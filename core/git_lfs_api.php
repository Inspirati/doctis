<?php
# Doctis - Document Issue Tracking System
#
# Git LFS server — the batch API and the "basic" transfer.
#
# Git LFS keeps a large file's bytes out of the repository: the commit holds a
# short text *pointer* naming the content's sha256 ("oid") and its size, and
# the bytes live in a separate content-addressed store that the client fetches
# on demand.  That is what makes a repository of CAD files or firmware images
# clonable: history stays small and a clone downloads only the versions it
# checks out.
#
# The client derives its LFS endpoint from the git remote URL by appending
# "/info/lfs", so for a Doctis remote
#
#     http://host/git/<slug>-r<id>.git
#
# every LFS request already arrives inside the /git/ route and is handled by
# git_http.php.  Nothing in the web-server configuration needs to know about
# LFS, and authentication is the gateway's own: a Doctis API token as the HTTP
# Basic password.  Authorisation is the gateway's too — the read threshold to
# download an object, the write threshold to upload one — because an LFS object
# is part of the repository's content and nothing finer-grained could be
# honoured over git anyway.
#
# Only the "basic" transfer adapter is offered, and file locking is refused:
# both are optional in the specification and neither is needed to store or
# fetch content.
#
# Design, staging and decisions: doc/git/GIT_LFS.md.

require_api( 'access_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'repository_api.php' );

# The media type the specification requires on the batch and verify calls.
# git-lfs checks it on the response and treats anything else as a server that
# does not speak LFS, so it must be exact.
define( 'GIT_LFS_MIME_TYPE', 'application/vnd.git-lfs+json' );

# The first line of every pointer file.  Part of the format, not a URL to
# fetch: it identifies the pointer version.
define( 'GIT_LFS_POINTER_VERSION', 'https://git-lfs.github.com/spec/v1' );

/**
 * Whether the LFS endpoints are available on this install.
 *
 * Requires remote git access, the feature switch, and a configured object
 * store — there is nowhere to put an object without the last.
 *
 * @return bool
 */
function git_lfs_enabled(): bool {
	return ON == config_get_global( 'git_http_enabled', OFF )
		&& ON == config_get_global( 'git_lfs_enabled', OFF )
		&& (string)config_get_global( 'git_lfs_root', '' ) !== '';
}

/**
 * Whether a string is a valid LFS object id.
 *
 * An oid is a lowercase hex sha256.  This is the only validation standing
 * between a request path and a filesystem path, so it is deliberately strict:
 * no uppercase, no prefixes, nothing that could contain a separator or a dot.
 *
 * @param string $p_oid
 * @return bool
 */
function git_lfs_oid_valid( string $p_oid ): bool {
	return (bool)preg_match( '/^[0-9a-f]{64}$/', $p_oid );
}

/**
 * The object-store directory for one repository.
 *
 * Per repository rather than a shared pool: an object must only ever be
 * reachable through the access check of the repository that holds it, and
 * deleting a repository must be able to take its objects with it.  The
 * immutable "-r<id>" suffix is authoritative, as everywhere else.
 *
 * @param int $p_repository_id
 * @return string  Absolute path, no trailing slash, or '' if unconfigured.
 */
function git_lfs_repo_root( int $p_repository_id ): string {
	$t_root = rtrim( (string)config_get_global( 'git_lfs_root', '' ), '/' );
	if( $t_root === '' ) {
		return '';
	}
	return $t_root . '/' . repository_basename( $p_repository_id );
}

/**
 * Where one object's bytes live.
 *
 * The two-level fan-out mirrors git-lfs's own layout, so the directory is
 * recognisable to anyone who opens it and can be moved between a Doctis store
 * and a client's .git/lfs/objects without rearranging.
 *
 * @param int    $p_repository_id
 * @param string $p_oid  Must already have passed git_lfs_oid_valid().
 * @return string
 */
function git_lfs_object_path( int $p_repository_id, string $p_oid ): string {
	$t_root = git_lfs_repo_root( $p_repository_id );
	if( $t_root === '' ) {
		return '';
	}
	return $t_root . '/' . substr( $p_oid, 0, 2 ) . '/' . substr( $p_oid, 2, 2 ) . '/' . $p_oid;
}

/**
 * Whether an object is present, and its size.
 *
 * @param int    $p_repository_id
 * @param string $p_oid
 * @return int|false  Size in bytes, or false if absent.
 */
function git_lfs_object_size( int $p_repository_id, string $p_oid ) {
	$t_path = git_lfs_object_path( $p_repository_id, $p_oid );
	if( $t_path === '' || !is_file( $t_path ) ) {
		return false;
	}
	return (int)filesize( $t_path );
}

/**
 * Read an LFS pointer, if that is what this content is.
 *
 * The format is two or three short lines in a fixed order, and a file is a
 * pointer only if it matches exactly — a real document that happens to begin
 * with the word "version" must never be mistaken for one, because the
 * consequence would be serving the wrong bytes.  The size guard comes first
 * so a large file is never scanned.
 *
 * @param string $p_content
 * @return array|null  array( 'oid' => <sha256>, 'size' => <int> ), or null.
 */
function git_lfs_parse_pointer( string $p_content ): ?array {
	# A pointer is always small; the specification caps it well under this.
	if( $p_content === '' || strlen( $p_content ) > 1024 ) {
		return null;
	}
	if( strncmp( $p_content, 'version ' . GIT_LFS_POINTER_VERSION, 8 + strlen( GIT_LFS_POINTER_VERSION ) ) !== 0 ) {
		return null;
	}
	if( !preg_match( '/^oid sha256:([0-9a-f]{64})$/m', $p_content, $t_oid )
		|| !preg_match( '/^size (\d{1,19})$/m', $p_content, $t_size ) ) {
		return null;
	}
	return array( 'oid' => $t_oid[1], 'size' => (int)$t_size[1] );
}

/**
 * Compose a pointer file's content for an object.
 *
 * @param string $p_oid
 * @param int    $p_size
 * @return string
 */
function git_lfs_pointer( string $p_oid, int $p_size ): string {
	return 'version ' . GIT_LFS_POINTER_VERSION . "\n"
		. 'oid sha256:' . $p_oid . "\n"
		. 'size ' . $p_size . "\n";
}

/**
 * Emit an LFS JSON response and terminate.
 *
 * @param int   $p_code  HTTP status.
 * @param array $p_body  Decoded body.
 * @return void
 */
function git_lfs_json( int $p_code, array $p_body ): void {
	while( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	http_response_code( $p_code );
	header( 'Content-Type: ' . GIT_LFS_MIME_TYPE );
	echo json_encode( $p_body );
	exit;
}

/**
 * Emit an LFS error response and terminate.
 *
 * The client shows the message to the user verbatim, so it is written for
 * whoever typed the git command, not for a log.
 *
 * @param int    $p_code
 * @param string $p_message
 * @return void
 */
function git_lfs_fail( int $p_code, string $p_message ): void {
	git_lfs_json( $p_code, array( 'message' => $p_message ) );
}

/**
 * Handle one LFS request end to end.  Terminates the request.
 *
 * Called from the gateway once the caller is authenticated and the repository
 * resolved; this function does the LFS-specific authorisation, because whether
 * the request is a read or a write depends on the batch body rather than on
 * the URL.
 *
 * @param string $p_lfs_path       Path below "info/lfs/", e.g. "objects/batch".
 * @param int    $p_repository_id
 * @param int    $p_project_id     The repository's owner project.
 * @param int    $p_user_id
 * @return void
 */
function git_lfs_handle_request( string $p_lfs_path, int $p_repository_id,
		int $p_project_id, int $p_user_id ): void {

	if( !git_lfs_enabled() ) {
		# 501 rather than 404: the repository is there and the endpoint is a
		# real one, the server just does not offer it.  git-lfs reports this
		# clearly, where a 404 reads as a missing repository.
		git_lfs_fail( 501, 'Git LFS is not enabled on this Doctis server.' );
	}

	$t_method = strtoupper( $_SERVER['REQUEST_METHOD'] ?? 'GET' );

	# File locking is optional in the specification and is about exclusive
	# checkout of unmergeable binaries, not about storing or fetching them.
	if( strncmp( $p_lfs_path, 'locks', 5 ) === 0 ) {
		git_lfs_fail( 501, 'Git LFS file locking is not supported by Doctis.' );
	}

	if( $p_lfs_path === 'objects/batch' ) {
		if( $t_method !== 'POST' ) {
			git_lfs_fail( 405, 'The LFS batch endpoint accepts POST only.' );
		}
		git_lfs_handle_batch( $p_repository_id, $p_project_id, $p_user_id );
	}

	# The transfer endpoints: the hrefs handed back by the batch call.
	if( preg_match( '#^objects/([0-9a-f]{64})(/verify)?$#', $p_lfs_path, $t_m ) ) {
		$t_oid    = $t_m[1];
		$t_verify = isset( $t_m[2] );

		if( $t_verify ) {
			git_lfs_require_access( $p_project_id, $p_user_id, false );
			git_lfs_handle_verify( $p_repository_id, $t_oid );
		}
		if( $t_method === 'PUT' ) {
			git_lfs_require_access( $p_project_id, $p_user_id, true );
			git_lfs_handle_upload( $p_repository_id, $t_oid );
		}
		if( $t_method === 'GET' ) {
			git_lfs_require_access( $p_project_id, $p_user_id, false );
			git_lfs_handle_download( $p_repository_id, $t_oid );
		}
		git_lfs_fail( 405, 'Unsupported method for an LFS object.' );
	}

	git_lfs_fail( 404, 'Unsupported Git LFS request.' );
}

/**
 * Authorise an LFS operation, or terminate.
 *
 * The same thresholds as git itself: an LFS object is repository content, and
 * a caller who may clone the repository may read its objects.
 *
 * @param int  $p_project_id
 * @param int  $p_user_id
 * @param bool $p_write
 * @return void
 */
function git_lfs_require_access( int $p_project_id, int $p_user_id, bool $p_write ): void {
	$t_key = $p_write ? 'git_http_write_threshold' : 'git_http_read_threshold';
	$t_threshold = config_get( $t_key, null, $p_user_id, $p_project_id );
	if( !access_has_project_level( $t_threshold, $p_project_id, $p_user_id ) ) {
		git_lfs_fail( 403, $p_write
			? 'You do not have push access to this project repository, so you cannot upload LFS objects.'
			: 'You do not have access to this project repository.' );
	}
}

/**
 * The batch endpoint: tell the client, per object, where to put or get it.
 *
 * This is the only call a client must make.  The transfer itself goes to the
 * hrefs returned here; because they are on the same host as the endpoint, the
 * client reuses the credentials it has just authenticated with, so no token is
 * echoed back into a response body.
 *
 * @param int $p_repository_id
 * @param int $p_project_id
 * @param int $p_user_id
 * @return void
 */
function git_lfs_handle_batch( int $p_repository_id, int $p_project_id, int $p_user_id ): void {
	$t_raw = (string)file_get_contents( 'php://input' );
	$t_req = json_decode( $t_raw, true );
	if( !is_array( $t_req ) ) {
		git_lfs_fail( 400, 'Malformed LFS batch request.' );
	}

	$t_operation = (string)( $t_req['operation'] ?? '' );
	if( $t_operation !== 'download' && $t_operation !== 'upload' ) {
		git_lfs_fail( 400, 'Unsupported LFS operation: ' . $t_operation );
	}
	$t_upload = ( $t_operation === 'upload' );

	# Authorise once for the whole batch, by what it intends to do.
	git_lfs_require_access( $p_project_id, $p_user_id, $t_upload );

	# Only the basic transfer is offered.  A client that omits "transfers"
	# means basic; one that asks for something else and not basic cannot be
	# served.
	$t_transfers = $t_req['transfers'] ?? array( 'basic' );
	if( is_array( $t_transfers ) && $t_transfers && !in_array( 'basic', $t_transfers, true ) ) {
		git_lfs_fail( 422, 'Doctis offers the "basic" LFS transfer only.' );
	}

	$t_objects = $t_req['objects'] ?? null;
	if( !is_array( $t_objects ) ) {
		git_lfs_fail( 422, 'The LFS batch request named no objects.' );
	}

	$t_base = repository_clone_url( repository_basename( $p_repository_id ) )
		. '/info/lfs/objects/';

	$t_out = array();
	foreach( $t_objects as $t_object ) {
		$t_oid  = is_array( $t_object ) ? (string)( $t_object['oid'] ?? '' ) : '';
		$t_size = is_array( $t_object ) ? (int)( $t_object['size'] ?? 0 ) : 0;

		if( !git_lfs_oid_valid( $t_oid ) ) {
			$t_out[] = array(
				'oid'   => $t_oid,
				'size'  => $t_size,
				'error' => array( 'code' => 422, 'message' => 'Not a sha256 object id.' ),
			);
			continue;
		}

		$t_have = git_lfs_object_size( $p_repository_id, $t_oid );
		$t_entry = array(
			'oid'           => $t_oid,
			'size'          => $t_size,
			'authenticated' => true,
		);

		if( $t_upload ) {
			# An object already held at the right size needs no transfer, and
			# saying so is how a push of an unchanged file stays cheap.  An
			# entry with no actions means exactly that.
			if( $t_have === false || $t_have !== $t_size ) {
				$t_entry['actions'] = array(
					'upload' => array( 'href' => $t_base . $t_oid ),
					'verify' => array( 'href' => $t_base . $t_oid . '/verify' ),
				);
			}
		} else {
			if( $t_have === false ) {
				$t_entry['error'] = array(
					'code'    => 404,
					'message' => 'Object is not stored on this server.',
				);
			} else {
				# Answer with the size on disk: if the pointer and the stored
				# object disagree, the client should be told what it is
				# actually about to receive.
				$t_entry['size']    = $t_have;
				$t_entry['actions'] = array(
					'download' => array( 'href' => $t_base . $t_oid ),
				);
			}
		}

		$t_out[] = $t_entry;
	}

	git_lfs_json( 200, array(
		'transfer'  => 'basic',
		'objects'   => $t_out,
		'hash_algo' => 'sha256',
	) );
}

/**
 * Receive one object's bytes (PUT).
 *
 * The body is streamed to a temporary file beside its final home and hashed as
 * it arrives, so an object of any size is handled without holding it in
 * memory, and content that does not hash to the oid it was sent as is never
 * stored.  The move into place is a rename within one directory, which is
 * atomic: a reader never sees a partial object.
 *
 * @param int    $p_repository_id
 * @param string $p_oid
 * @return void
 */
function git_lfs_handle_upload( int $p_repository_id, string $p_oid ): void {
	$t_path = git_lfs_object_path( $p_repository_id, $p_oid );
	if( $t_path === '' ) {
		git_lfs_fail( 500, 'The Git LFS object store is not configured.' );
	}

	if( is_file( $t_path ) ) {
		# Content-addressed: the bytes cannot differ, so this is already done.
		http_response_code( 200 );
		exit;
	}

	$t_dir = dirname( $t_path );
	if( !is_dir( $t_dir ) && !@mkdir( $t_dir, 0770, true ) && !is_dir( $t_dir ) ) {
		git_lfs_fail( 500, 'Unable to create the Git LFS object directory.' );
	}

	$t_temp = $t_dir . '/.' . $p_oid . '.' . getmypid() . '.part';
	$t_in   = fopen( 'php://input', 'rb' );
	$t_out  = fopen( $t_temp, 'wb' );
	if( !$t_in || !$t_out ) {
		if( $t_in ) {
			fclose( $t_in );
		}
		if( $t_out ) {
			fclose( $t_out );
		}
		@unlink( $t_temp );
		git_lfs_fail( 500, 'Unable to receive the Git LFS object.' );
	}

	$t_hash = hash_init( 'sha256' );
	$t_size = 0;
	while( !feof( $t_in ) ) {
		$t_chunk = fread( $t_in, 1048576 );
		if( $t_chunk === false || $t_chunk === '' ) {
			break;
		}
		hash_update( $t_hash, $t_chunk );
		$t_size += strlen( $t_chunk );
		if( fwrite( $t_out, $t_chunk ) === false ) {
			fclose( $t_in );
			fclose( $t_out );
			@unlink( $t_temp );
			git_lfs_fail( 500, 'Unable to write the Git LFS object to the store.' );
		}
	}
	fclose( $t_in );
	fclose( $t_out );

	if( hash_final( $t_hash ) !== $p_oid ) {
		@unlink( $t_temp );
		git_lfs_fail( 422, 'The uploaded content does not match the object id it was sent as.' );
	}

	if( !@rename( $t_temp, $t_path ) ) {
		@unlink( $t_temp );
		git_lfs_fail( 500, 'Unable to store the Git LFS object.' );
	}
	@chmod( $t_path, 0660 );

	http_response_code( 200 );
	exit;
}

/**
 * Confirm an object landed, at the size the client says it should be.
 *
 * @param int    $p_repository_id
 * @param string $p_oid
 * @return void
 */
function git_lfs_handle_verify( int $p_repository_id, string $p_oid ): void {
	$t_have = git_lfs_object_size( $p_repository_id, $p_oid );
	if( $t_have === false ) {
		git_lfs_fail( 404, 'Object is not stored on this server.' );
	}

	$t_req  = json_decode( (string)file_get_contents( 'php://input' ), true );
	$t_want = is_array( $t_req ) && isset( $t_req['size'] ) ? (int)$t_req['size'] : $t_have;
	if( $t_want !== $t_have ) {
		git_lfs_fail( 422, 'Object is stored at ' . $t_have . ' bytes, not ' . $t_want . '.' );
	}

	git_lfs_json( 200, array( 'oid' => $p_oid, 'size' => $t_have ) );
}

/**
 * Send one object's bytes (GET).
 *
 * Streamed, not read into memory: these are the files that were too big to
 * keep in the repository in the first place.
 *
 * @param int    $p_repository_id
 * @param string $p_oid
 * @return void
 */
function git_lfs_handle_download( int $p_repository_id, string $p_oid ): void {
	$t_path = git_lfs_object_path( $p_repository_id, $p_oid );
	if( $t_path === '' || !is_file( $t_path ) ) {
		git_lfs_fail( 404, 'Object is not stored on this server.' );
	}

	while( ob_get_level() > 0 ) {
		ob_end_clean();
	}
	http_response_code( 200 );
	header( 'Content-Type: application/octet-stream' );
	header( 'Content-Length: ' . filesize( $t_path ) );
	header( 'X-Content-Type-Options: nosniff' );
	readfile( $t_path );
	exit;
}
