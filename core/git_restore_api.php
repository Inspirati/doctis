<?php
# Administrator-only Git/LFS recovery, independent of shell/console access.
# Uploads and archive validation are private; installed storage is changed
# only after a separate, explicit restore request. No database rows are edited.

require_api( 'repository_api.php' );

const GIT_RESTORE_CHUNK_BYTES = 524288;

function git_restore_remove( string $p_path ): void {
	if( is_dir( $p_path ) && !is_link( $p_path ) ) {
		foreach( scandir( $p_path ) as $t_name ) {
			if( $t_name !== '.' && $t_name !== '..' ) {
				git_restore_remove( $p_path . '/' . $t_name );
			}
		}
		if( !rmdir( $p_path ) ) { throw new RuntimeException( 'Could not remove a staging directory.' ); }
	} else if( ( file_exists( $p_path ) || is_link( $p_path ) ) && !unlink( $p_path ) ) {
		throw new RuntimeException( 'Could not remove a staged file.' );
	}
}

function git_restore_mkdir( string $p_path ): void {
	if( !is_dir( $p_path ) && !mkdir( $p_path, 0700, true ) ) {
		throw new RuntimeException( 'Could not create a private staging directory.' );
	}
}

function git_restore_temp_root(): string {
	$t_root = sys_get_temp_dir() . '/doctis-restore-' . hash( 'sha256', dirname( __DIR__ ) );
	if( is_link( $t_root ) ) { throw new RuntimeException( 'Invalid restore staging directory.' ); }
	git_restore_mkdir( $t_root );
	return $t_root;
}

function git_restore_ticket_path( string $p_ticket ): string {
	if( !preg_match( '/^[a-f0-9]{32}$/D', $p_ticket ) ) {
		throw new RuntimeException( 'Invalid restore upload identifier.' );
	}
	return git_restore_temp_root() . '/' . $p_ticket;
}

function git_restore_state( string $p_dir ): array {
	$t_state = json_decode( (string)@file_get_contents( $p_dir . '/state.json' ), true );
	if( !is_array( $t_state ) ) { throw new RuntimeException( 'Upload not found; select the backup again.' ); }
	return $t_state;
}

function git_restore_save( string $p_dir, array $p_state ): void {
	$t_json = json_encode( $p_state, JSON_THROW_ON_ERROR );
	if( file_put_contents( $p_dir . '/state.new', $t_json ) === false
		|| !rename( $p_dir . '/state.new', $p_dir . '/state.json' ) ) {
		throw new RuntimeException( 'Could not save restore progress.' );
	}
}

function git_restore_start( string $p_name, int $p_size, string $p_hash, int $p_user ): array {
	if( $p_size < 1 || $p_size > config_get_global( 'git_restore_max_archive_size' )
		|| ( $p_hash !== '' && !preg_match( '/^[a-f0-9]{64}$/D', $p_hash ) ) ) {
		throw new RuntimeException( 'Invalid archive size or SHA-256 checksum.' );
	}
	$t_ticket = bin2hex( random_bytes( 16 ) );
	$t_dir = git_restore_ticket_path( $t_ticket );
	if( disk_free_space( git_restore_temp_root() ) < $p_size * 2 ) {
		throw new RuntimeException( 'Insufficient temporary disk space for this upload.' );
	}
	git_restore_mkdir( $t_dir );
	$t_state = array( 'ticket' => $t_ticket, 'owner' => $p_user, 'name' => basename( $p_name ),
		'size' => $p_size, 'expected_sha256' => $p_hash, 'phase' => 'uploading', 'updated' => time() );
	git_restore_save( $t_dir, $t_state );
	touch( $t_dir . '/archive.tar.gz' );
	return $t_state;
}

function git_restore_append( string $p_dir, array $p_state, int $p_offset, string $p_file ): int {
	clearstatcache( true, $p_dir . '/archive.tar.gz' );
	$t_offset = filesize( $p_dir . '/archive.tar.gz' );
	$t_size = filesize( $p_file );
	if( $p_state['phase'] !== 'uploading' || $t_size < 1 || $t_size > GIT_RESTORE_CHUNK_BYTES
		|| $p_offset !== $t_offset || $t_offset + $t_size > $p_state['size'] ) {
		throw new RuntimeException( 'Upload offset or chunk size does not match. Resume from server progress.' );
	}
	$t_in = fopen( $p_file, 'rb' );
	$t_out = fopen( $p_dir . '/archive.tar.gz', 'ab' );
	try {
		if( stream_copy_to_stream( $t_in, $t_out ) !== $t_size ) {
			ftruncate( $t_out, $t_offset );
			throw new RuntimeException( 'Could not save the complete upload chunk.' );
		}
	} finally {
		fclose( $t_in );
		fclose( $t_out );
	}
	return $t_offset + $t_size;
}

/** Read and bound a gzip stream without loading a large file into memory. */
function git_restore_read( $p_stream, int $p_size, int &$p_total ): string {
	$t_result = '';
	while( strlen( $t_result ) < $p_size ) {
		$t_chunk = @gzread( $p_stream, $p_size - strlen( $t_result ) );
		if( $t_chunk === false || $t_chunk === '' ) { throw new RuntimeException( 'Truncated or corrupt gzip archive.' ); }
		$t_result .= $t_chunk;
	}
	$p_total += $p_size;
	if( $p_total > config_get_global( 'git_restore_max_unpacked_size' ) ) {
		throw new RuntimeException( 'The unpacked archive exceeds the configured restore limit.' );
	}
	return $t_result;
}

function git_restore_octal( string $p_value ): int {
	$t_value = trim( $p_value, " \0" );
	if( $t_value !== '' && !preg_match( '/^[0-7]+$/D', $t_value ) ) {
		throw new RuntimeException( 'Unsupported tar numeric field.' );
	}
	return (int)octdec( $t_value );
}

/** Only Backup Git Store layouts are accepted. Links/devices are never extracted. */
function git_restore_extract( string $p_archive, string $p_dir ): array {
	git_restore_mkdir( $p_dir );
	$t_stream = @gzopen( $p_archive, 'rb' );
	if( $t_stream === false ) { throw new RuntimeException( 'Cannot open the gzip backup.' ); }
	$t_total = 0;
	$t_count = 0;
	$t_long = '';
	$t_seen = array();
	$t_layout = null;
	try {
		while( true ) {
			$t_header = git_restore_read( $t_stream, 512, $t_total );
			if( $t_header === str_repeat( "\0", 512 ) ) {
				if( git_restore_read( $t_stream, 512, $t_total ) !== $t_header || $t_long !== '' ) {
					throw new RuntimeException( 'Invalid tar end marker.' );
				}
				while( !gzeof( $t_stream ) ) {
					$t_tail = @gzread( $t_stream, 65536 );
					if( $t_tail === false || trim( $t_tail, "\0" ) !== '' ) {
						throw new RuntimeException( 'Invalid gzip trailer or concatenated archive.' );
					}
					$t_total += strlen( $t_tail );
					if( $t_total > config_get_global( 'git_restore_max_unpacked_size' ) ) {
						throw new RuntimeException( 'Archive padding exceeds the restore limit.' );
					}
				}
				break;
			}
			$t_checksum = git_restore_octal( substr( $t_header, 148, 8 ) );
			if( array_sum( unpack( 'C*', substr_replace( $t_header, str_repeat( ' ', 8 ), 148, 8 ) ) ) !== $t_checksum ) {
				throw new RuntimeException( 'Tar header checksum mismatch.' );
			}
			if( ++$t_count > 200000 ) { throw new RuntimeException( 'Too many archive entries.' ); }
			$t_size = git_restore_octal( substr( $t_header, 124, 12 ) );
			$t_type = $t_header[156];
			$t_name = rtrim( substr( $t_header, 0, 100 ), "\0" );
			# GNU long-name records are used by the server's tar for long repository paths.
			if( $t_type === 'L' ) {
				if( $t_size < 1 || $t_size > 1048576 || $t_long !== '' ) {
					throw new RuntimeException( 'Invalid tar long-name record.' );
				}
				$t_long = rtrim( git_restore_read( $t_stream, $t_size, $t_total ), "\0" );
				if( $t_size % 512 ) { git_restore_read( $t_stream, 512 - $t_size % 512, $t_total ); }
				continue;
			}
			if( $t_long !== '' ) {
				$t_name = $t_long;
				$t_long = '';
			} else if( substr( $t_header, 257, 6 ) === "ustar\0" ) {
				$t_prefix = rtrim( substr( $t_header, 345, 155 ), "\0" );
				if( $t_prefix !== '' ) { $t_name = $t_prefix . '/' . $t_name; }
			}
			if( !in_array( $t_type, array( '0', "\0", '5' ), true ) ) {
				throw new RuntimeException( 'Only regular files/directories in GNU/ustar backups are supported; links and special entries are refused.' );
			}
			while( str_starts_with( $t_name, './' ) ) { $t_name = substr( $t_name, 2 ); }
			$t_name = rtrim( $t_name, '/' );
			if( $t_name === '.' || $t_name === '' ) {
				if( $t_type !== '5' || $t_size !== 0 ) { throw new RuntimeException( 'Invalid archive root.' ); }
				continue;
			}
			$t_parts = explode( '/', $t_name );
			if( $t_name[0] === '/' || preg_match( '/[\x00-\x1f\x7f]/', $t_name )
				|| array_intersect( $t_parts, array( '..', '.', '' ) ) || isset( $t_seen[$t_name] ) ) {
				throw new RuntimeException( 'Unsafe or duplicate archive path.' );
			}
			$t_seen[$t_name] = true;
			$t_new_layout = in_array( $t_parts[0], array( 'repositories', 'lfs' ), true );
			if( $t_parts[0] !== '.doctis-lock' ) {
				if( $t_layout !== null && $t_layout !== $t_new_layout ) { throw new RuntimeException( 'Mixed backup layouts.' ); }
				$t_layout = $t_new_layout;
			}
			if( !$t_new_layout ) { array_unshift( $t_parts, 'repositories' ); }
			$t_skip = $t_parts === array( 'repositories', '.doctis-lock' );
			if( count( $t_parts ) > 1 && !$t_skip ) {
				$t_pattern = $t_parts[0] === 'repositories' ? '/^[a-z0-9][a-z0-9-]*-r[1-9][0-9]*\.git$/D'
					: '/^[a-z0-9][a-z0-9-]*-r[1-9][0-9]*$/D';
				if( !preg_match( $t_pattern, $t_parts[1] ) ) { throw new RuntimeException( 'Invalid repository directory in backup.' ); }
			}
			$t_relative = implode( '/', $t_parts );
			$t_target = $p_dir . '/' . $t_relative;
			if( $t_type === '5' ) {
				if( $t_size !== 0 ) { throw new RuntimeException( 'Directory contains unexpected data.' ); }
				git_restore_mkdir( $t_target );
			} else {
				if( count( $t_parts ) < 3 && !$t_skip ) { throw new RuntimeException( 'Unexpected file at the archive root.' ); }
				if( $t_size > config_get_global( 'git_restore_max_unpacked_size' )
					|| disk_free_space( $p_dir ) < $t_size + 1048576 ) { throw new RuntimeException( 'Insufficient unpacking space or oversized file.' ); }
				git_restore_mkdir( dirname( $t_target ) );
				$t_out = $t_skip ? null : @fopen( $t_target, 'xb' );
				if( !$t_skip && $t_out === false ) { throw new RuntimeException( 'Duplicate or conflicting archive file.' ); }
				try {
					for( $t_left = $t_size; $t_left > 0; $t_left -= strlen( $t_data ) ) {
						$t_data = git_restore_read( $t_stream, min( 65536, $t_left ), $t_total );
						if( !$t_skip && fwrite( $t_out, $t_data ) !== strlen( $t_data ) ) { throw new RuntimeException( 'Could not unpack the file.' ); }
					}
				} finally {
					if( is_resource( $t_out ) ) { fclose( $t_out ); chmod( $t_target, 0600 ); }
				}
				if( $t_size % 512 ) { git_restore_read( $t_stream, 512 - $t_size % 512, $t_total ); }
			}
		}
	} finally { gzclose( $t_stream ); }
	git_restore_mkdir( $p_dir . '/repositories' );
	git_restore_mkdir( $p_dir . '/lfs' );
	return array( 'entries' => $t_count, 'unpacked_bytes' => $t_total, 'includes_lfs' => $t_layout === true );
}

/** Argument-array commands avoid interpreting repository paths as shell code. */
function git_restore_git( string $p_repo, array $p_args, string $p_input = '', bool $p_optional = false ): string {
	$t_out = tmpfile();
	$t_err = tmpfile();
	$t_in = tmpfile();
	fwrite( $t_in, $p_input );
	rewind( $t_in );
	$t_process = proc_open( array_merge( array( 'git', '--git-dir=' . $p_repo ), $p_args ),
		array( 0 => $t_in, 1 => $t_out, 2 => $t_err ), $t_pipes );
	$t_rc = is_resource( $t_process ) ? proc_close( $t_process ) : -1;
	rewind( $t_out ); rewind( $t_err );
	$t_result = stream_get_contents( $t_out );
	$t_error = stream_get_contents( $t_err, 1000 );
	fclose( $t_in ); fclose( $t_out ); fclose( $t_err );
	if( $t_rc !== 0 && !$p_optional ) { throw new RuntimeException( 'Git validation failed: ' . $t_error ); }
	return $t_result;
}

function git_restore_database_inventory(): array {
	$t_result = db_query( 'SELECT id FROM {repository} ORDER BY id' );
	$t_names = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_names[(int)$t_row['id']] = repository_basename( (int)$t_row['id'] ) . '.git';
	}
	return $t_names;
}

/** Validate every stored LFS object and all Git history in private staging. */
function git_restore_validate_store( string $p_dir, array $p_expected ): array {
	$t_store = $p_dir . '/repositories';
	$t_lfs = $p_dir . '/lfs';
	$t_expected = $p_expected;
	$t_repos = array();
	foreach( array_diff( scandir( $t_store ), array( '.', '..' ) ) as $t_name ) {
		if( !is_dir( $t_store . '/' . $t_name ) ) { throw new RuntimeException( 'Unexpected file in repository root.' ); }
		$t_repos[] = $t_name;
	}
	if( array_diff( $t_expected, $t_repos ) ) { throw new RuntimeException( 'The backup lacks repositories referenced by this database. Restore the matching database first.' ); }
	$t_lfs_count = 0;
	$t_lfs_bytes = 0;
	foreach( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $t_lfs, FilesystemIterator::SKIP_DOTS ) ) as $t_file ) {
		$t_relative = substr( $t_file->getPathname(), strlen( $t_lfs ) + 1 );
		if( !preg_match( '#^([a-z0-9][a-z0-9-]*-r[1-9][0-9]*)/([a-f0-9]{2})/([a-f0-9]{2})/([a-f0-9]{64})$#D', $t_relative, $t_match )
			|| substr( $t_match[4], 0, 4 ) !== $t_match[2] . $t_match[3]
			|| !in_array( $t_match[1] . '.git', $t_repos, true )
			|| hash_file( 'sha256', $t_file->getPathname() ) !== $t_match[4] ) {
			throw new RuntimeException( 'Invalid, orphaned or corrupt LFS object in backup.' );
		}
		$t_lfs_count++;
		$t_lfs_bytes += $t_file->getSize();
	}
	$t_policies = array();
	$t_pointers = 0;
	foreach( $t_repos as $t_name ) {
		$t_repo = $t_store . '/' . $t_name;
		foreach( array( 'objects/info/alternates', 'objects/info/http-alternates', 'commondir', 'gitdir', 'worktrees' ) as $t_external ) {
			if( file_exists( $t_repo . '/' . $t_external ) ) { throw new RuntimeException( 'The backup depends on external Git objects or worktrees.' ); }
		}
		$t_policy = trim( git_restore_git( $t_repo, array( 'config', '--file', $t_repo . '/config', '--get', 'doctis.pushpolicy' ), '', true ) );
		$t_policies[$t_name] = $t_policy === DWG_PUSH_POLICY_SOURCE ? $t_policy : DWG_PUSH_POLICY_DOCUMENT;
		# Restore content and policy, not source-host executable configuration.
		git_restore_remove( $t_repo . '/hooks' );
		git_restore_mkdir( $t_repo . '/hooks' );
		file_put_contents( $t_repo . '/config', "[core]\nrepositoryformatversion = 0\nbare = true\nfilemode = true\n[doctis]\npushpolicy = " . $t_policies[$t_name] . "\n" );
		repository_configure_bare( $t_repo );
		git_restore_git( $t_repo, array( 'fsck', '--full' ) );
		$t_objects = git_restore_git( $t_repo, array( 'cat-file', '--batch-all-objects', '--batch-check' ) );
		$t_small = array();
		foreach( explode( "\n", trim( $t_objects ) ) as $t_line ) {
			if( preg_match( '/^([a-f0-9]{40}) blob ([0-9]+)$/D', $t_line, $t_match ) && (int)$t_match[2] <= 1024 ) { $t_small[] = $t_match[1]; }
		}
		if( $t_small ) {
			$t_blobs = git_restore_git( $t_repo, array( 'cat-file', '--batch' ), implode( "\n", $t_small ) . "\n" );
			$t_offset = 0;
			foreach( $t_small as $t_oid ) {
				$t_end = strpos( $t_blobs, "\n", $t_offset );
				$t_header = substr( $t_blobs, $t_offset, $t_end - $t_offset );
				if( !preg_match( '/^[a-f0-9]{40} blob ([0-9]+)$/D', $t_header, $t_match ) ) { throw new RuntimeException( 'Invalid Git blob response.' ); }
				$t_blob = substr( $t_blobs, $t_end + 1, (int)$t_match[1] );
				$t_offset = $t_end + 2 + (int)$t_match[1];
				if( !str_starts_with( $t_blob, "version https://git-lfs.github.com/spec/v1\n" ) ) { continue; }
				if( !preg_match( '/\noid sha256:([a-f0-9]{64})\nsize ([0-9]+)(?:\n|$)/', $t_blob, $t_match ) ) { throw new RuntimeException( 'Invalid LFS pointer.' ); }
				$t_object = $t_lfs . '/' . substr( $t_name, 0, -4 ) . '/' . substr( $t_match[1], 0, 2 ) . '/' . substr( $t_match[1], 2, 2 ) . '/' . $t_match[1];
				if( !is_file( $t_object ) || filesize( $t_object ) !== (int)$t_match[2] ) { throw new RuntimeException( 'A Git LFS pointer has no matching object in this backup.' ); }
				$t_pointers++;
			}
		}
	}
	return array( 'repositories' => $t_repos, 'database_repositories' => $t_expected, 'push_policies' => $t_policies,
		'lfs_objects' => $t_lfs_count, 'lfs_bytes' => $t_lfs_bytes, 'lfs_pointers' => $t_pointers );
}

function git_restore_validate( string $p_dir ): array {
	$t_expected = git_restore_database_inventory();
	$t_report = git_restore_validate_store( $p_dir, $t_expected );
	$t_store = $p_dir . '/repositories';
	$t_specs = array();
	foreach( array( 'dwg_primary_file', 'dwg_primary_draft' ) as $t_table ) {
		$t_result = db_query( 'SELECT f.git_sha, f.git_path, w.project_id FROM {' . $t_table . '} f JOIN {dwg} w ON w.id=f.dwg_id' );
		while( $t_row = db_fetch_array( $t_result ) ) {
			if( $t_row['git_sha'] === '' ) { continue; }
			$t_id = repository_id_for_project( (int)$t_row['project_id'] );
			if( !isset( $t_expected[$t_id] ) || !preg_match( '/^[a-f0-9]{40}$/D', $t_row['git_sha'] ) || preg_match( '/[\r\n\x00]/', $t_row['git_path'] ) ) { throw new RuntimeException( 'Invalid database file reference.' ); }
			$t_specs[$t_expected[$t_id]][$t_row['git_sha'] . '^{commit}'] = true;
			if( $t_row['git_path'] !== '' ) { $t_specs[$t_expected[$t_id]][$t_row['git_sha'] . ':' . $t_row['git_path']] = true; }
		}
	}
	foreach( $t_specs as $t_name => $t_checks ) {
		$t_result = git_restore_git( $t_store . '/' . $t_name, array( 'cat-file', '--batch-check' ), implode( "\n", array_keys( $t_checks ) ) . "\n" );
		if( preg_match( '/ (missing|ambiguous)$/m', $t_result ) ) { throw new RuntimeException( 'The database references a missing Git commit or file.' ); }
	}
	$t_report['database_fingerprint'] = git_restore_database_fingerprint();
	return $t_report;
}

function git_restore_database_fingerprint(): string {
	$t_data = array();
	foreach( array( 'repository', 'project_repository', 'project_hierarchy', 'dwg_primary_file', 'dwg_primary_draft' ) as $t_table ) {
		$t_result = db_query( 'SELECT * FROM {' . $t_table . '}' );
		$t_rows = array();
		while( $t_row = db_fetch_array( $t_result ) ) { $t_rows[] = json_encode( $t_row ); }
		sort( $t_rows );
		$t_data[$t_table] = $t_rows;
	}
	return hash( 'sha256', json_encode( $t_data ) );
}

function git_restore_inspect( string $p_dir, array $p_state ): array {
	if( !in_array( $p_state['phase'], array( 'uploading', 'ready' ), true ) ) { throw new RuntimeException( 'This restore is not available for validation.' ); }
	if( filesize( $p_dir . '/archive.tar.gz' ) !== $p_state['size'] ) { throw new RuntimeException( 'Upload is incomplete.' ); }
	$t_hash = hash_file( 'sha256', $p_dir . '/archive.tar.gz' );
	if( $p_state['expected_sha256'] !== '' && !hash_equals( $p_state['expected_sha256'], $t_hash ) ) { throw new RuntimeException( 'Uploaded archive SHA-256 does not match.' ); }
	git_restore_remove( $p_dir . '/extracted' );
	$t_archive = git_restore_extract( $p_dir . '/archive.tar.gz', $p_dir . '/extracted' );
	$t_report = git_restore_validate( $p_dir . '/extracted' );
	$p_state['report'] = array_merge( $t_archive, $t_report, array( 'sha256' => $t_hash ) );
	$p_state['phase'] = 'ready';
	git_restore_save( $p_dir, $p_state );
	return $p_state;
}

function git_restore_roots( bool $p_need_lfs = false ): array {
	$t_roots = array();
	foreach( array( 'repositories' => 'git_storage_root', 'lfs' => 'git_lfs_root', 'worktrees' => 'git_worktree_root' ) as $t_kind => $t_option ) {
		$t_config = rtrim( (string)config_get_global( $t_option ), '/' );
		if( $t_kind === 'lfs' && $t_config === '' && !$p_need_lfs ) { continue; }
		$t_root = $t_config !== '' ? realpath( $t_config ) : false;
		if( $t_root === false || $t_root === '/' || is_link( $t_config ) || !is_writable( $t_root ) ) { throw new RuntimeException( 'Restore requires three existing, writable, separate storage roots.' ); }
		foreach( $t_roots as $t_other ) {
			if( $t_root === $t_other || str_starts_with( $t_root . '/', $t_other . '/' ) || str_starts_with( $t_other . '/', $t_root . '/' ) ) { throw new RuntimeException( 'Storage roots must not overlap.' ); }
		}
		$t_roots[$t_kind] = $t_root;
	}
	return $t_roots;
}

function git_restore_copy( string $p_from, string $p_to ): void {
	if( is_link( $p_from ) ) { throw new RuntimeException( 'Unexpected staging link.' ); }
	if( is_dir( $p_from ) ) {
		git_restore_mkdir( $p_to );
		chmod( $p_to, 0770 );
		foreach( array_diff( scandir( $p_from ), array( '.', '..' ) ) as $t_name ) { git_restore_copy( $p_from . '/' . $t_name, $p_to . '/' . $t_name ); }
	} else {
		if( !copy( $p_from, $p_to ) ) { throw new RuntimeException( 'Could not prepare the replacement on the destination filesystem.' ); }
		chmod( $p_to, ( fileperms( $p_from ) & 0111 ) ? 0770 : 0660 );
	}
}

function git_restore_exclusive(): void {
	global $g_git_restore_request_lock;
	if( !is_resource( $g_git_restore_request_lock ) || !flock( $g_git_restore_request_lock, LOCK_EX | LOCK_NB ) ) {
		throw new RuntimeException( 'Other application requests are active. Retry the restore when the server is idle.' );
	}
}

/** Roll back from on-disk rename evidence, including an interrupted PHP request. */
function git_restore_rollback( string $p_dir, array $p_state ): void {
	foreach( $p_state['installation'] as $t_kind => $t_install ) {
		$t_root = $t_install['root'];
		$t_old = $t_root . '/.doctis-previous-' . $p_state['ticket'];
		foreach( $t_install['new'] as $t_name ) {
			if( !in_array( $t_name, $t_install['old'], true ) ) { git_restore_remove( $t_root . '/' . $t_name ); }
		}
		if( is_dir( $t_old ) ) {
			foreach( array_diff( scandir( $t_old ), array( '.', '..' ) ) as $t_name ) {
				git_restore_remove( $t_root . '/' . $t_name );
				if( !rename( $t_old . '/' . $t_name, $t_root . '/' . $t_name ) ) { throw new RuntimeException( 'Rollback needs attention; keep this restore upload and retry recovery.' ); }
			}
		}
		git_restore_remove( $t_old );
		git_restore_remove( $t_root . '/.doctis-incoming-' . $p_state['ticket'] );
	}
	$p_state['phase'] = 'ready';
	git_restore_save( $p_dir, $p_state );
	@unlink( git_restore_gate_path() . '.restore' );
}

function git_restore_apply( string $p_dir, array $p_state ): array {
	if( $p_state['phase'] !== 'ready' ) { throw new RuntimeException( 'Validate the complete upload before restoring.' ); }
	git_restore_exclusive();
	if( is_file( git_restore_gate_path() . '.restore' ) ) { throw new RuntimeException( 'Recover the interrupted restore first.' ); }
	if( !hash_equals( $p_state['report']['database_fingerprint'], git_restore_database_fingerprint() ) ) { throw new RuntimeException( 'The database changed after validation. Validate the backup again.' ); }
	$t_roots = git_restore_roots( $p_state['report']['lfs_objects'] > 0 );
	$p_state['installation'] = array();
	$t_prepared = array();
	try {
		foreach( $t_roots as $t_kind => $t_root ) {
			$t_incoming = $t_root . '/.doctis-incoming-' . $p_state['ticket'];
			$t_old = $t_root . '/.doctis-previous-' . $p_state['ticket'];
			if( file_exists( $t_incoming ) || file_exists( $t_old ) ) { throw new RuntimeException( 'Previous restore staging exists; recover it first.' ); }
			$t_old_names = array_values( array_diff( scandir( $t_root ), array( '.', '..', '.doctis-lock' ) ) );
			$p_state['installation'][$t_kind] = array( 'root' => $t_root, 'old' => $t_old_names, 'new' => array() );
			$p_state['phase'] = 'preparing';
			git_restore_save( $p_dir, $p_state );
			$t_prepared[] = $t_root;
			git_restore_mkdir( $t_incoming );
			git_restore_mkdir( $t_old );
			if( $t_kind !== 'worktrees' ) { git_restore_copy( $p_dir . '/extracted/' . $t_kind, $t_incoming ); }
			$p_state['installation'][$t_kind]['new'] = array_values( array_diff( scandir( $t_incoming ), array( '.', '..' ) ) );
			git_restore_save( $p_dir, $p_state );
		}
		$p_state['phase'] = 'applying';
		git_restore_save( $p_dir, $p_state );
		if( file_put_contents( git_restore_gate_path() . '.restore', $p_state['ticket'] ) === false ) { throw new RuntimeException( 'Cannot enter restore maintenance mode.' ); }
		foreach( $p_state['installation'] as $t_kind => $t_install ) {
			$t_root = $t_install['root'];
			foreach( $t_install['old'] as $t_name ) {
				if( !rename( $t_root . '/' . $t_name, $t_root . '/.doctis-previous-' . $p_state['ticket'] . '/' . $t_name ) ) { throw new RuntimeException( 'Could not preserve existing storage for rollback.' ); }
			}
			foreach( $t_install['new'] as $t_name ) {
				if( !rename( $t_root . '/.doctis-incoming-' . $p_state['ticket'] . '/' . $t_name, $t_root . '/' . $t_name ) ) { throw new RuntimeException( 'Could not install replacement storage.' ); }
			}
		}
	} catch( Throwable $t_error ) {
		if( $p_state['phase'] === 'applying' ) {
			git_restore_rollback( $p_dir, $p_state );
		} else {
			foreach( $t_prepared as $t_root ) {
				git_restore_remove( $t_root . '/.doctis-incoming-' . $p_state['ticket'] );
				git_restore_remove( $t_root . '/.doctis-previous-' . $p_state['ticket'] );
			}
			$p_state['phase'] = 'ready';
			git_restore_save( $p_dir, $p_state );
		}
		throw $t_error;
	}
	# The commit point: after this, recovery must finish cleanup, not roll back.
	$p_state['phase'] = 'complete';
	git_restore_save( $p_dir, $p_state );
	git_restore_finish( $p_dir, $p_state );
	return $p_state['report'];
}

function git_restore_finish( string $p_dir, array $p_state ): void {
	foreach( $p_state['installation'] as $t_install ) {
		git_restore_remove( $t_install['root'] . '/.doctis-previous-' . $p_state['ticket'] );
		git_restore_remove( $t_install['root'] . '/.doctis-incoming-' . $p_state['ticket'] );
	}
	@unlink( git_restore_gate_path() . '.restore' );
	git_restore_remove( $p_dir . '/extracted' );
	git_restore_remove( $p_dir . '/archive.tar.gz' );
}
