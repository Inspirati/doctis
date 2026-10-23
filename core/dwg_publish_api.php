<?php
# Doctis - Document Issue Tracking System
#
# Document Publishing API — renders a project's Markdown documents into a
# static website (doc/publish/PUBLISHING.md).
#
# Publishing a document converts its on-record version (the registered
# git_sha) to HTML with pandoc and writes it into the project's publication
# target directory at its repository path, .md replaced by .html.  This is a
# port of the HCRQMS engine/scripts/build-html.sh build, one document at a
# time: the same banner, sidebar navigation and link rewriting, plus Doctis
# metadata (doctis-status, doctis-number, ...) for the template to show.
#
# The website describes itself.  Every page Doctis writes ends with a
# "doctis-publish" marker comment recording the document, commit and status
# it was published from.  The navigation in every page and the generated
# index.html are rebuilt from those markers after each change, so projects
# that share a target directory share one site.  Files without a marker are
# never overwritten or removed.
#
# Settings (target, url, template, logo) come from the nearest project in the
# hierarchy that sets dwg_publish_target, like repository inheritance.
# Targets must lie under $g_dwg_publish_roots (config_inc.php only).
# Templates and logos are read from the project repository's default branch;
# templates that use pandoc partials are refused, because a partial can read
# any .html file on the server.  Failures throw
# ClientException(ERROR_DWG_PUBLISH) with a readable message.

use Mantis\Exceptions\ClientException;

require_api( 'access_dwg_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'helper_api.php' );
require_api( 'history_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'project_hierarchy_api.php' );
require_api( 'repository_api.php' );
require_api( 'user_api.php' );

const DWG_PUBLISH_MARKER = 'doctis-publish';
const DWG_PUBLISH_NAV_BEGIN = '<!-- doctis-nav -->';
const DWG_PUBLISH_NAV_END = '<!-- /doctis-nav -->';
const DWG_PUBLISH_INDEX = 'index.html';
const DWG_PUBLISH_LOCK = '.doctis-publish.lock';

/**
 * Throw a publishing error.
 *
 * @param string $p_message Readable message.
 * @return never
 * @throws ClientException
 */
function dwg_publish_error( string $p_message ) {
	throw new ClientException( $p_message, ERROR_DWG_PUBLISH, array( $p_message ) );
}

# ── Settings ─────────────────────────────────────────────────────────────────

/**
 * Normalise an absolute directory path: collapse slashes, drop a trailing
 * slash.  Returns '' for a relative path or one with "." or ".." segments or
 * control characters.
 *
 * @param string $p_path
 * @return string
 */
function dwg_publish_normalize_dir( string $p_path ): string {
	$t_path = trim( $p_path );
	if( $t_path === '' || $t_path[0] !== '/' || preg_match( '/[\x00-\x1f\x7f]/', $t_path ) ) {
		return '';
	}
	$t_parts = array();
	foreach( explode( '/', $t_path ) as $t_part ) {
		if( $t_part === '' ) {
			continue;
		}
		if( $t_part === '.' || $t_part === '..' ) {
			return '';
		}
		$t_parts[] = $t_part;
	}
	return '/' . implode( '/', $t_parts );
}

/**
 * True when a path is a directory or lies below it (both normalised).
 *
 * @param string $p_path
 * @param string $p_dir
 * @return boolean
 */
function dwg_publish_path_within( string $p_path, string $p_dir ): bool {
	if( $p_dir === '/' ) {
		return true;
	}
	return $p_path === $p_dir || strpos( $p_path, $p_dir . '/' ) === 0;
}

/**
 * The publishing roots from $g_dwg_publish_roots, normalised.  The
 * filesystem root itself is never accepted.
 *
 * @return array
 */
function dwg_publish_roots(): array {
	$t_roots = array();
	foreach( (array)config_get_global( 'dwg_publish_roots' ) as $t_root ) {
		$t_root = dwg_publish_normalize_dir( (string)$t_root );
		if( $t_root !== '' && $t_root !== '/' ) {
			$t_roots[] = $t_root;
		}
	}
	return $t_roots;
}

/**
 * True when this server allows publishing at all.
 *
 * @return boolean
 */
function dwg_publish_enabled(): bool {
	return count( dwg_publish_roots() ) > 0;
}

/**
 * Explain why a directory cannot be a publication target.
 *
 * @param string $p_target Absolute directory path.
 * @return string '' when the directory can be used.
 */
function dwg_publish_target_problem( string $p_target ): string {
	$t_roots = dwg_publish_roots();
	if( !$t_roots ) {
		return 'publishing is not enabled on this server ($g_dwg_publish_roots is empty).';
	}
	$t_target = dwg_publish_normalize_dir( $p_target );
	if( $t_target === '' ) {
		return 'the target directory must be an absolute path without "." or ".." segments.';
	}

	$t_root = '';
	foreach( $t_roots as $t_candidate ) {
		if( dwg_publish_path_within( $t_target, $t_candidate ) ) {
			$t_root = $t_candidate;
			break;
		}
	}
	if( $t_root === '' ) {
		return 'the target directory must be at or below ' . implode( ' or ', $t_roots ) . '.';
	}
	$t_real_root = realpath( $t_root );
	if( $t_real_root === false || !is_dir( $t_real_root ) ) {
		return 'the publishing root ' . $t_root . ' does not exist.';
	}

	# Symbolic links must not lead the target outside its root.
	$t_existing = $t_target;
	while( !file_exists( $t_existing ) ) {
		$t_existing = dirname( $t_existing );
	}
	$t_real = realpath( $t_existing );
	if( $t_existing === $t_target && !is_dir( $t_target ) ) {
		return 'the target ' . $t_target . ' is not a directory.';
	}
	if( $t_real === false || !dwg_publish_path_within( $t_real, $t_real_root ) ) {
		return 'the target directory resolves outside ' . $t_root . '.';
	}

	# Never publish over the application or the document store.
	$t_reserved = array(
		config_get_global( 'absolute_path' ),
		config_get_global( 'git_storage_root' ),
		config_get_global( 'git_worktree_root' ),
		config_get_global( 'absolute_path_default_upload_folder' ),
	);
	foreach( $t_reserved as $t_dir ) {
		$t_dir = dwg_publish_normalize_dir( (string)$t_dir );
		if( $t_dir === '' ) {
			continue;
		}
		$t_real_dir = realpath( $t_dir ) ?: $t_dir;
		foreach( array( array( $t_target, $t_dir ), array( $t_real, $t_real_dir ) ) as $t_pair ) {
			if( dwg_publish_path_within( $t_pair[0], $t_pair[1] ) || dwg_publish_path_within( $t_pair[1], $t_pair[0] ) ) {
				return 'the target directory overlaps the Doctis installation or document store.';
			}
		}
	}
	return '';
}

/**
 * Explain why a website URL cannot be used: it must be a path on this
 * server ("/site/") or an http(s) URL.
 *
 * @param string $p_url
 * @return string '' when the URL can be used (or is blank).
 */
function dwg_publish_url_problem( string $p_url ): string {
	if( $p_url === '' || preg_match( '#^(/|https?://)[^\s<>"\\\\]*$#i', $p_url ) ) {
		return '';
	}
	return 'the website URL must start with "/", "http://" or "https://".';
}

/**
 * True when a project sets its own publication target in the database.
 *
 * @param integer $p_project_id
 * @return boolean
 */
function dwg_publish_project_sets_target( int $p_project_id ): bool {
	db_param_push();
	$t_result = db_query( 'SELECT COUNT(*) FROM {config} WHERE config_id=' . db_param()
		. ' AND project_id=' . db_param() . ' AND user_id=' . db_param(),
		array( 'dwg_publish_target', $p_project_id, ALL_USERS ) );
	return (int)db_result( $t_result ) > 0;
}

/**
 * Resolve a project's publishing settings.  They come from the project if
 * it sets dwg_publish_target, else from the nearest ancestor that does, else
 * from the global configuration.
 *
 * @param integer $p_project_id
 * @return array project_id (the project the settings come from, or
 *               ALL_PROJECTS), target ('' when the project does not
 *               publish), configured_target, problem, url, template, logo,
 *               pdf (true when a PDF is published with each page).
 */
function dwg_publish_site( int $p_project_id ): array {
	$t_source = ALL_PROJECTS;
	$t_id     = $p_project_id;
	$t_seen   = array();
	while( $t_id > 0 && !isset( $t_seen[$t_id] ) ) {
		$t_seen[$t_id] = true;
		if( dwg_publish_project_sets_target( $t_id ) ) {
			$t_source = $t_id;
			break;
		}
		$t_id = (int)project_hierarchy_get_parent( $t_id, /* show_disabled */ true );
	}

	$t_configured = trim( (string)config_get( 'dwg_publish_target', '', ALL_USERS, $t_source ) );
	$t_url        = trim( (string)config_get( 'dwg_publish_url', '', ALL_USERS, $t_source ) );
	$t_problem    = '';
	$t_target     = '';
	if( $t_configured !== '' && dwg_publish_enabled() ) {
		$t_problem = dwg_publish_target_problem( $t_configured );
		if( $t_problem === '' ) {
			$t_target = dwg_publish_normalize_dir( $t_configured );
		}
	}

	return array(
		'project_id'        => $t_source,
		'target'            => $t_target,
		'configured_target' => $t_configured,
		'problem'           => $t_problem,
		'url'               => dwg_publish_url_problem( $t_url ) === '' ? $t_url : '',
		'template'          => trim( (string)config_get( 'dwg_publish_template', '', ALL_USERS, $t_source ) ),
		'logo'              => trim( (string)config_get( 'dwg_publish_logo', '', ALL_USERS, $t_source ) ),
		'pdf'               => (int)config_get( 'dwg_publish_pdf', OFF, ALL_USERS, $t_source ) === ON,
	);
}

/**
 * Save a project's publishing settings.  A blank target removes all of the
 * project's own settings, so it inherits its parent's site again.
 *
 * @param integer $p_project_id
 * @param string  $p_target   Absolute directory, or ''.
 * @param string  $p_url      Website URL, or ''.
 * @param string  $p_template Repository path, or '' for the Doctis default.
 * @param string  $p_logo     Repository path, or '' for the project name.
 * @param boolean $p_pdf      Also publish a PDF of each document.
 * @return void
 */
function dwg_publish_settings_save( int $p_project_id, string $p_target, string $p_url, string $p_template, string $p_logo, bool $p_pdf = false ): void {
	static $s_options = array( 'dwg_publish_target', 'dwg_publish_url', 'dwg_publish_template', 'dwg_publish_logo', 'dwg_publish_pdf' );
	$t_values = array( trim( $p_target ), trim( $p_url ), trim( $p_template ), trim( $p_logo ), $p_pdf ? ON : '' );

	if( $t_values[0] === '' ) {
		foreach( $s_options as $t_option ) {
			config_delete( $t_option, ALL_USERS, $p_project_id );
		}
		return;
	}

	$t_problem = dwg_publish_target_problem( $t_values[0] );
	if( $t_problem === '' ) {
		$t_values[0] = dwg_publish_normalize_dir( $t_values[0] );
		$t_problem = dwg_publish_url_problem( $t_values[1] );
	}
	foreach( array( 2 => 'template', 3 => 'logo' ) as $t_index => $t_name ) {
		if( $t_problem !== '' || $t_values[$t_index] === '' ) {
			continue;
		}
		$t_path = file_dwg_git_path_sanitize( $t_values[$t_index] );
		$t_content = $t_path === false ? null : dwg_publish_repo_file( $p_project_id, $t_path );
		if( $t_content === null ) {
			$t_problem = 'the ' . $t_name . ' ' . $t_values[$t_index] . ' is not in the repository\'s default branch.';
		} else if( $t_name === 'template' ) {
			$t_problem = dwg_publish_template_problem( $t_content );
		} else if( !preg_match( '/\.(svg|png|jpe?g)$/i', $t_path ) ) {
			$t_problem = 'the logo must be an SVG, PNG or JPEG image.';
		}
		$t_values[$t_index] = (string)$t_path;
	}
	if( $t_problem === '' && $p_pdf ) {
		$t_problem = dwg_publish_pdf_problem();
	}
	if( $t_problem !== '' ) {
		dwg_publish_error( $t_problem );
	}

	foreach( $s_options as $t_index => $t_option ) {
		if( $t_values[$t_index] === '' ) {
			config_delete( $t_option, ALL_USERS, $p_project_id );
		} else {
			config_set( $t_option, $t_values[$t_index], ALL_USERS, $p_project_id );
		}
	}
}

/**
 * The URL of a published page, or '' when the site has no URL.
 *
 * @param array  $p_site As returned by dwg_publish_site().
 * @param string $p_page Site-relative page path.
 * @return string
 */
function dwg_publish_page_url( array $p_site, string $p_page ): string {
	if( $p_site['url'] === '' ) {
		return '';
	}
	return rtrim( $p_site['url'], '/' ) . '/' . dwg_publish_href( $p_page );
}

/**
 * Make a website URL absolute, as the document URL field requires: a URL
 * starting with "/" takes the scheme and host of Doctis's own address
 * ($g_path).
 *
 * @param string $p_url
 * @return string '' when no valid absolute http(s) URL results.
 */
function dwg_publish_absolute_url( string $p_url ): string {
	if( $p_url !== '' && $p_url[0] === '/' ) {
		$t_base = parse_url( (string)config_get_global( 'path' ) );
		if( empty( $t_base['scheme'] ) || empty( $t_base['host'] ) ) {
			return '';
		}
		$t_url = $t_base['scheme'] . ':';
		if( substr( $p_url, 0, 2 ) !== '//' ) {
			$t_url .= '//' . $t_base['host'] . ( isset( $t_base['port'] ) ? ':' . $t_base['port'] : '' );
		}
		$p_url = $t_url . $p_url;
	}
	return dwg_link_url_is_valid( $p_url ) ? $p_url : '';
}

/**
 * Set a document's URL field (link_url).  Both copies of the field ({dwg}
 * and {documents}) are updated and the change is logged in the history, as
 * DwgData::update() does, without its notification email.
 *
 * @param integer $p_dwg_id
 * @param string  $p_url     '' clears the field.
 * @param integer $p_user_id
 * @return void
 */
function dwg_publish_set_link_url( int $p_dwg_id, string $p_url, int $p_user_id ): void {
	$t_old = (string)dwg_get_field( $p_dwg_id, 'link_url' );
	if( $t_old === $p_url ) {
		return;
	}
	db_param_push();
	db_query( 'UPDATE {dwg} SET link_url=' . db_param() . ' WHERE id=' . db_param(), array( $p_url, $p_dwg_id ) );
	db_param_push();
	db_query( 'UPDATE {documents} SET link_url=' . db_param() . ' WHERE id=' . db_param(),
		array( $p_url, (int)dwg_get_field( $p_dwg_id, 'document_id' ) ) );
	dwg_update_date( $p_dwg_id );
	history_dwg_log_event_direct( $p_dwg_id, 'link_url', $t_old, $p_url, $p_user_id );
	dwg_clear_cache( $p_dwg_id );
}

# ── Pages ────────────────────────────────────────────────────────────────────

/**
 * True when a repository path names a Markdown file.
 *
 * @param string $p_path
 * @return boolean
 */
function dwg_publish_is_markdown( string $p_path ): bool {
	return (bool)preg_match( '/\.(md|markdown)$/i', $p_path );
}

/**
 * The site-relative page path for a repository path: .md becomes .html.
 *
 * @param string $p_git_path
 * @return string
 */
function dwg_publish_page_path( string $p_git_path ): string {
	return preg_replace( '/\.(md|markdown)$/i', '', $p_git_path ) . '.html';
}

/**
 * Percent-encode each segment of a site-relative path for an href.
 *
 * @param string $p_page
 * @return string
 */
function dwg_publish_href( string $p_page ): string {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $p_page ) ) );
}

/**
 * The relative prefix from a page back to the site root, e.g. "../../".
 *
 * @param string $p_page Site-relative page path.
 * @return string
 */
function dwg_publish_prefix( string $p_page ): string {
	return str_repeat( '../', substr_count( $p_page, '/' ) );
}

/**
 * The marker comment that ends every page Doctis writes.
 *
 * @param array $p_data
 * @return string
 */
function dwg_publish_marker_line( array $p_data ): string {
	$t_json = json_encode( $p_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP );
	# "--" may not appear inside an HTML comment.
	return '<!-- ' . DWG_PUBLISH_MARKER . ' ' . str_replace( '--', '-\\u002d', $t_json ) . " -->\n";
}

/**
 * Read the marker from the end of a page.
 *
 * @param string $p_file
 * @return array|null Null when the file has no Doctis marker.
 */
function dwg_publish_read_marker( string $p_file ): ?array {
	if( !is_file( $p_file ) || is_link( $p_file ) ) {
		return null;
	}
	$t_handle = @fopen( $p_file, 'rb' );
	if( $t_handle === false ) {
		return null;
	}
	$t_size = (int)filesize( $p_file );
	fseek( $t_handle, max( 0, $t_size - 16384 ) );
	$t_tail = (string)fread( $t_handle, 16384 );
	fclose( $t_handle );
	if( !preg_match( '/<!-- ' . DWG_PUBLISH_MARKER . ' (\{.*\}) -->\s*$/s', $t_tail, $t_match ) ) {
		return null;
	}
	$t_data = json_decode( $t_match[1], true );
	return is_array( $t_data ) ? $t_data : null;
}

/**
 * List the pages Doctis has published in a target directory, keyed and
 * sorted by site-relative path.  Hidden files and directories, symbolic
 * links, files without a marker and the generated index are skipped.
 *
 * @param string $p_target
 * @return array Page markers, each with 'page' added.
 */
function dwg_publish_site_pages( string $p_target ): array {
	$t_pages = array();
	if( $p_target === '' || !is_dir( $p_target ) ) {
		return $t_pages;
	}
	$t_iterator = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $p_target, FilesystemIterator::SKIP_DOTS ),
		function( $p_file ) {
			return $p_file->getFilename()[0] !== '.' && !$p_file->isLink();
		} ) );
	foreach( $t_iterator as $t_file ) {
		if( !$t_file->isFile() || strtolower( $t_file->getExtension() ) !== 'html' ) {
			continue;
		}
		$t_marker = dwg_publish_read_marker( $t_file->getPathname() );
		if( $t_marker === null || !isset( $t_marker['dwg_id'] ) ) {
			continue;
		}
		$t_page = substr( $t_file->getPathname(), strlen( $p_target ) + 1 );
		$t_marker['page'] = $t_page;
		$t_pages[$t_page] = $t_marker;
	}
	ksort( $t_pages, SORT_STRING );
	return $t_pages;
}

/**
 * Find the published page of a document in its project's site.  Looks at
 * the page path of the current registered file only.
 *
 * @param integer $p_dwg_id
 * @return array|null The page marker with page, file and url added, or null.
 */
function dwg_publish_get( int $p_dwg_id ): ?array {
	$t_row = file_dwg_primary_get( $p_dwg_id );
	if( !$t_row || !dwg_publish_is_markdown( $t_row['git_path'] ) ) {
		return null;
	}
	$t_site = dwg_publish_site( (int)dwg_get_field( $p_dwg_id, 'project_id' ) );
	if( $t_site['target'] === '' ) {
		return null;
	}
	$t_page   = dwg_publish_page_path( $t_row['git_path'] );
	$t_file   = $t_site['target'] . '/' . $t_page;
	$t_marker = dwg_publish_read_marker( $t_file );
	if( $t_marker === null || (int)( $t_marker['dwg_id'] ?? 0 ) !== $p_dwg_id ) {
		return null;
	}
	$t_marker['page'] = $t_page;
	$t_marker['file'] = $t_file;
	$t_marker['url']  = dwg_publish_page_url( $t_site, $t_page );
	$t_marker['pdf_url'] = isset( $t_marker['pdf'] ) ? dwg_publish_page_url( $t_site, (string)$t_marker['pdf'] ) : '';
	return $t_marker;
}

/**
 * True when a published page no longer matches the document: a newer
 * version is on record or the status has changed.
 *
 * @param array   $p_published As returned by dwg_publish_get().
 * @param integer $p_dwg_id
 * @return boolean
 */
function dwg_publish_is_stale( array $p_published, int $p_dwg_id ): bool {
	$t_row = file_dwg_primary_get( $p_dwg_id );
	return !$t_row
		|| ( $p_published['sha'] ?? '' ) !== $t_row['git_sha']
		|| (int)( $p_published['status'] ?? 0 ) !== (int)dwg_get_field( $p_dwg_id, 'status' );
}

# ── Navigation ───────────────────────────────────────────────────────────────

/**
 * Display name for a site directory: words split on hyphens, short words
 * and words without vowels upper-cased as acronyms (it, hr, qms, vcrm).
 *
 * @param string $p_dir
 * @return string
 */
function dwg_publish_dir_label( string $p_dir ): string {
	static $s_small = array( 'a', 'an', 'and', 'at', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to' );
	$t_words = preg_split( '/[-_\s]+/', $p_dir, -1, PREG_SPLIT_NO_EMPTY );
	foreach( $t_words as $t_index => $t_word ) {
		$t_lower = mb_strtolower( $t_word );
		if( $t_index > 0 && in_array( $t_lower, $s_small, true ) ) {
			$t_words[$t_index] = $t_lower;
		} else if( mb_strlen( $t_lower ) <= 2 || !preg_match( '/[aeiouy]/', $t_lower ) ) {
			$t_words[$t_index] = mb_strtoupper( $t_lower );
		} else {
			$t_words[$t_index] = mb_strtoupper( mb_substr( $t_lower, 0, 1 ) ) . mb_substr( $t_lower, 1 );
		}
	}
	return implode( ' ', $t_words );
}

/**
 * Build the navigation tree of a site's pages.
 *
 * @param array $p_pages As returned by dwg_publish_site_pages().
 * @return array Node: files => list of [title, page], dirs => name => node.
 */
function dwg_publish_nav_tree( array $p_pages ): array {
	$t_root = array( 'files' => array(), 'dirs' => array() );
	foreach( $p_pages as $t_page => $t_marker ) {
		$t_parts = explode( '/', $t_page );
		$t_file  = array_pop( $t_parts );
		$t_node  = &$t_root;
		foreach( $t_parts as $t_part ) {
			if( !isset( $t_node['dirs'][$t_part] ) ) {
				$t_node['dirs'][$t_part] = array( 'files' => array(), 'dirs' => array() );
			}
			$t_node = &$t_node['dirs'][$t_part];
		}
		$t_title = trim( (string)( $t_marker['title'] ?? '' ) );
		if( $t_title === '' ) {
			$t_title = preg_replace( '/\.html$/', '', $t_file );
		}
		$t_node['files'][] = array( $t_title, $t_page );
		unset( $t_node );
	}
	return $t_root;
}

/**
 * Render a navigation tree as the <li> items of the sidebar list, with
 * links relative to one page.  Same markup as HCRQMS generate-nav.py.
 *
 * @param array  $p_node   As returned by dwg_publish_nav_tree().
 * @param string $p_prefix Relative prefix from the page to the site root.
 * @param string $p_indent
 * @return string
 */
function dwg_publish_nav_html( array $p_node, string $p_prefix, string $p_indent = '' ): string {
	$t_html = '';
	foreach( $p_node['files'] as $t_file ) {
		$t_html .= $p_indent . '<li class="nav-file"><a href="'
			. htmlspecialchars( $p_prefix . dwg_publish_href( $t_file[1] ) ) . '">'
			. htmlspecialchars( $t_file[0] ) . "</a></li>\n";
	}
	$t_dirs = $p_node['dirs'];
	ksort( $t_dirs, SORT_STRING );
	foreach( $t_dirs as $t_name => $t_child ) {
		$t_html .= $p_indent . "<li class=\"nav-dir\">\n"
			. $p_indent . "  <details open>\n"
			. $p_indent . '    <summary>' . htmlspecialchars( dwg_publish_dir_label( (string)$t_name ) ) . "</summary>\n"
			. $p_indent . "    <ul>\n"
			. dwg_publish_nav_html( $t_child, $p_prefix, $p_indent . '    ' )
			. $p_indent . "    </ul>\n"
			. $p_indent . "  </details>\n"
			. $p_indent . "</li>\n";
	}
	return $t_html;
}

/**
 * The banner and sidebar inserted before each page body, as written by
 * HCRQMS build-html.sh.  The navigation sits between marker comments so it
 * can be replaced when pages are added or withdrawn.
 *
 * @param string $p_prefix    Relative prefix from the page to the site root.
 * @param string $p_nav       Navigation items for this page.
 * @param string $p_logo_html Banner logo or title.
 * @return string
 */
function dwg_publish_before_body( string $p_prefix, string $p_nav, string $p_logo_html ): string {
	return "<header class=\"site-banner\">\n"
		. "  <button class=\"nav-toggle\" type=\"button\" aria-controls=\"site-navigation\" aria-expanded=\"true\" aria-label=\"Hide navigation\">\n"
		. "    <span aria-hidden=\"true\"></span>\n"
		. "  </button>\n"
		. '  <a href="' . htmlspecialchars( $p_prefix ) . DWG_PUBLISH_INDEX . "\" class=\"banner-home\">\n"
		. '    ' . $p_logo_html . "\n"
		. "  </a>\n"
		. "</header>\n"
		. "<nav class=\"sidebar\" id=\"site-navigation\" aria-label=\"Documents\">\n"
		. "  <ul class=\"nav-tree\">\n"
		. DWG_PUBLISH_NAV_BEGIN . "\n"
		. $p_nav
		. DWG_PUBLISH_NAV_END . "\n"
		. "  </ul>\n"
		. "</nav>\n"
		. "<div class=\"sidebar-resizer\" role=\"separator\" aria-label=\"Resize navigation\" aria-orientation=\"vertical\" tabindex=\"0\"></div>\n";
}

/**
 * Replace the navigation of a page that Doctis wrote earlier.
 *
 * @param string $p_html
 * @param string $p_nav
 * @return string|null The new page, or null when it has no navigation markers.
 */
function dwg_publish_replace_nav( string $p_html, string $p_nav ): ?string {
	$t_begin = strpos( $p_html, DWG_PUBLISH_NAV_BEGIN );
	if( $t_begin === false ) {
		return null;
	}
	$t_begin += strlen( DWG_PUBLISH_NAV_BEGIN );
	$t_end = strpos( $p_html, DWG_PUBLISH_NAV_END, $t_begin );
	if( $t_end === false ) {
		return null;
	}
	return substr( $p_html, 0, $t_begin ) . "\n" . $p_nav . substr( $p_html, $t_end );
}

# ── Repository files and templates ───────────────────────────────────────────

/**
 * Read a file from the default branch of a project's repository.
 *
 * @param integer $p_project_id
 * @param string  $p_path Repository-relative path.
 * @return string|null Null when the file does not exist.
 */
function dwg_publish_repo_file( int $p_project_id, string $p_path ): ?string {
	$t_path = file_dwg_git_path_sanitize( $p_path );
	$t_repository_id = $p_project_id > 0 ? repository_id_for_project( $p_project_id ) : 0;
	if( $t_path === false || $t_repository_id === 0 ) {
		return null;
	}
	$t_row    = repository_get_row( $t_repository_id );
	$t_bare   = repository_bare_path( $t_repository_id );
	$t_branch = 'refs/heads/' . ( $t_row && $t_row['default_branch'] !== '' ? $t_row['default_branch'] : 'main' );
	if( !is_dir( $t_bare ) || file_dwg_git_object_sha( $t_bare, $t_branch, $t_path ) === null ) {
		return null;
	}
	$t_content = shell_exec( 'git --git-dir=' . escapeshellarg( $t_bare )
		. ' show ' . escapeshellarg( $t_branch . ':' . $t_path ) . ' 2>/dev/null' );
	return is_string( $t_content ) ? $t_content : null;
}

/**
 * Explain why a pandoc template cannot be used.
 *
 * @param string $p_template Template text.
 * @return string '' when the template can be used.
 */
function dwg_publish_template_problem( string $p_template ): string {
	# "$$" is a literal dollar sign, never the start of a template expression.
	$t_text = str_replace( '$$', '', $p_template );
	# A partial, ${ name() } or $name()$, may read any .html file on the server.
	if( preg_match( '/\$\{?\s*(?:[\w.-]+:)?[\w.\/~-]+\(\)/', $t_text ) ) {
		return 'the template uses pandoc partials, which publishing does not allow.';
	}
	if( !preg_match( '/\$\{?\s*body\b/', $t_text ) ) {
		return 'the template has no $body$ variable.';
	}
	return '';
}

/**
 * Load the site's pandoc template: the configured repository file, or the
 * Doctis default.
 *
 * @param array $p_site As returned by dwg_publish_site().
 * @return string
 */
function dwg_publish_template_text( array $p_site ): string {
	if( $p_site['template'] === '' ) {
		return (string)file_get_contents( __DIR__ . '/publish/dwg_publish_template.html' );
	}
	$t_template = dwg_publish_repo_file( (int)$p_site['project_id'], $p_site['template'] );
	if( $t_template === null ) {
		dwg_publish_error( 'the template ' . $p_site['template'] . ' is not in the repository\'s default branch.' );
	}
	$t_problem = dwg_publish_template_problem( $t_template );
	if( $t_problem !== '' ) {
		dwg_publish_error( $p_site['template'] . ': ' . $t_problem );
	}
	return $t_template;
}

/**
 * The banner logo: the configured repository image embedded as a data URI,
 * else the project name.
 *
 * @param array $p_site As returned by dwg_publish_site().
 * @return string HTML
 */
function dwg_publish_logo_html( array $p_site ): string {
	$t_project_id = (int)$p_site['project_id'];
	$t_name = $t_project_id > 0 ? project_get_name( $t_project_id ) : config_get( 'window_title' );
	static $s_types = array( 'svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg' );
	$t_type = $s_types[strtolower( pathinfo( $p_site['logo'], PATHINFO_EXTENSION ) )] ?? '';
	if( $t_type !== '' ) {
		$t_image = dwg_publish_repo_file( $t_project_id, $p_site['logo'] );
		if( $t_image !== null && $t_image !== '' ) {
			return '<img src="data:' . $t_type . ';base64,' . base64_encode( $t_image )
				. '" alt="' . htmlspecialchars( $t_name ) . '" class="banner-logo">';
		}
	}
	return '<span class="banner-logo-text">' . htmlspecialchars( $t_name ) . '</span>';
}

# ── Files ────────────────────────────────────────────────────────────────────

/**
 * Create a directory below the target, refusing symbolic links that lead
 * outside it.
 *
 * @param string $p_target
 * @param string $p_dir Absolute directory path at or below the target.
 * @return void
 */
function dwg_publish_make_dir( string $p_target, string $p_dir ): void {
	$t_real_target = realpath( $p_target );
	$t_existing    = $p_dir;
	while( !file_exists( $t_existing ) && dwg_publish_path_within( $t_existing, $p_target ) ) {
		$t_existing = dirname( $t_existing );
	}
	$t_real = realpath( $t_existing );
	if( $t_real_target !== false && ( $t_real === false || !dwg_publish_path_within( $t_real, $t_real_target ) ) ) {
		dwg_publish_error( 'the directory ' . $p_dir . ' leads outside the target directory.' );
	}
	if( !is_dir( $p_dir ) && !@mkdir( $p_dir, 0775, true ) && !is_dir( $p_dir ) ) {
		dwg_publish_error( 'could not create ' . $p_dir . '. Check that the web server can write to the target directory.' );
	}
	$t_real = realpath( $p_dir );
	$t_real_target = realpath( $p_target );
	if( $t_real === false || $t_real_target === false || !dwg_publish_path_within( $t_real, $t_real_target ) ) {
		dwg_publish_error( 'the directory ' . $p_dir . ' leads outside the target directory.' );
	}
}

/**
 * Write a page atomically.
 *
 * @param string $p_target
 * @param string $p_page    Site-relative page path.
 * @param string $p_content
 * @return void
 */
function dwg_publish_write( string $p_target, string $p_page, string $p_content ): void {
	$t_file = $p_target . '/' . $p_page;
	$t_dir  = dirname( $t_file );
	dwg_publish_make_dir( $p_target, $t_dir );
	if( is_link( $t_file ) ) {
		dwg_publish_error( $p_page . ' is a symbolic link; it was not replaced.' );
	}
	$t_temp = $t_dir . '/.' . basename( $t_file ) . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
	if( @file_put_contents( $t_temp, $p_content ) === false ) {
		dwg_publish_error( 'could not write ' . $p_page . '. Check that the web server can write to the target directory.' );
	}
	if( !@rename( $t_temp, $t_file ) ) {
		@unlink( $t_temp );
		dwg_publish_error( 'could not replace ' . $p_page . '.' );
	}
}

/**
 * Remove a page and any directories it leaves empty.
 *
 * @param string $p_target
 * @param string $p_page Site-relative page path.
 * @return void
 */
function dwg_publish_remove( string $p_target, string $p_page ): void {
	$t_file = $p_target . '/' . $p_page;
	if( is_file( $t_file ) && !is_link( $t_file ) && !@unlink( $t_file ) ) {
		dwg_publish_error( 'could not remove ' . $p_page . '.' );
	}
	$t_dir = dirname( $t_file );
	while( $t_dir !== $p_target && dwg_publish_path_within( $t_dir, $p_target ) && @rmdir( $t_dir ) ) {
		$t_dir = dirname( $t_dir );
	}
}

/**
 * Take the site's exclusive lock, creating the target directory if needed.
 *
 * @param string $p_target
 * @return resource
 */
function dwg_publish_lock( string $p_target ) {
	dwg_publish_make_dir( $p_target, $p_target );
	$t_handle = @fopen( $p_target . '/' . DWG_PUBLISH_LOCK, 'c' );
	if( $t_handle === false ) {
		dwg_publish_error( 'the web server cannot write to ' . $p_target . '.' );
	}
	flock( $t_handle, LOCK_EX );
	return $t_handle;
}

/**
 * Release the site lock.
 *
 * @param resource $p_handle
 * @return void
 */
function dwg_publish_unlock( $p_handle ): void {
	flock( $p_handle, LOCK_UN );
	fclose( $p_handle );
}

/**
 * Create a private working directory for one pandoc run.
 *
 * @return string
 */
function dwg_publish_work_dir(): string {
	$t_dir = rtrim( sys_get_temp_dir(), '/' ) . '/doctis-publish-' . bin2hex( random_bytes( 8 ) );
	if( !@mkdir( $t_dir, 0700 ) ) {
		dwg_publish_error( 'could not create a temporary directory.' );
	}
	return $t_dir;
}

/**
 * Delete a working directory created by dwg_publish_work_dir().
 *
 * @param string $p_dir
 * @return void
 */
function dwg_publish_work_dir_delete( string $p_dir ): void {
	foreach( (array)@scandir( $p_dir ) as $t_file ) {
		if( $t_file !== '.' && $t_file !== '..' ) {
			@unlink( $p_dir . '/' . $t_file );
		}
	}
	@rmdir( $p_dir );
}

/**
 * Run pandoc in a working directory.  Arguments are passed without a shell.
 *
 * @param array  $p_args
 * @param string $p_work_dir
 * @param array  $p_env      Extra environment variables.
 * @return void
 */
function dwg_publish_pandoc( array $p_args, string $p_work_dir, array $p_env = array() ): void {
	$t_pandoc = (string)config_get_global( 'dwg_publish_pandoc' );
	if( $t_pandoc === '' || !is_executable( $t_pandoc ) ) {
		dwg_publish_error( 'pandoc was not found at "' . $t_pandoc . '" ($g_dwg_publish_pandoc).' );
	}
	$t_errors  = $p_work_dir . '/pandoc.err';
	$t_process = proc_open(
		array_merge( array( $t_pandoc, '--sandbox' ), $p_args ),
		array(
			0 => array( 'file', '/dev/null', 'r' ),
			1 => array( 'file', '/dev/null', 'w' ),
			2 => array( 'file', $t_errors, 'w' ),
		),
		$t_pipes, $p_work_dir,
		array( 'HOME' => $p_work_dir, 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8' ) + $p_env );
	if( !is_resource( $t_process ) ) {
		dwg_publish_error( 'pandoc could not be started.' );
	}
	$t_status = proc_close( $t_process );
	if( $t_status !== 0 ) {
		$t_message = trim( (string)@file_get_contents( $t_errors ) );
		dwg_publish_error( 'pandoc failed: ' . ( $t_message !== '' ? mb_substr( $t_message, 0, 500 ) : 'exit status ' . $t_status ) );
	}
}

# ── PDF ──────────────────────────────────────────────────────────────────────

/**
 * The site-relative PDF path for a page: .html becomes .pdf.
 *
 * @param string $p_page
 * @return string
 */
function dwg_publish_pdf_path( string $p_page ): string {
	return preg_replace( '/\.html$/', '', $p_page ) . '.pdf';
}

/**
 * Explain why PDFs cannot be produced on this server.
 *
 * @return string '' when they can.
 */
function dwg_publish_pdf_problem(): string {
	$t_engine = (string)config_get_global( 'dwg_publish_pdf_engine' );
	if( $t_engine === '' || !is_executable( $t_engine ) ) {
		return 'the PDF engine was not found at "' . $t_engine . '" ($g_dwg_publish_pdf_engine); see doc/publish/PUBLISHING.md §9 for the packages.';
	}
	return '';
}

/**
 * Escape text for LaTeX.
 *
 * @param string $p_text
 * @return string
 */
function dwg_publish_latex_escape( string $p_text ): string {
	static $s_map = array(
		'\\' => '\\textbackslash{}', '{' => '\\{', '}' => '\\}', '$' => '\\$', '&' => '\\&',
		'#' => '\\#', '%' => '\\%', '_' => '\\_', '^' => '\\textasciicircum{}', '~' => '\\textasciitilde{}',
	);
	return strtr( str_replace( array( "\r", "\n" ), ' ', $p_text ), $s_map );
}

/**
 * The LaTeX header of a published PDF: the HCRQMS build-pdf.sh footer
 * (reference, revision and status; page X of Y; uncontrolled copy notice),
 * with the values from Doctis, escaped.
 *
 * @param array $p_values As returned by dwg_publish_template_values().
 * @return string
 */
function dwg_publish_pdf_header( array $p_values ): string {
	$t_meta = $p_values['metadata'];
	$t_reference = trim( ( $t_meta['doctis-number'] ?? '' ) !== '' ? $t_meta['doctis-number'] : ( $t_meta['doctis-title'] ?? '' ) );
	if( ( $t_meta['doctis-revision'] ?? '' ) !== '' ) {
		$t_reference .= ' Rev ' . $t_meta['doctis-revision'];
	}
	$t_left = dwg_publish_latex_escape( $t_reference ) . ' --- ' . dwg_publish_latex_escape( $t_meta['doctis-status'] ?? '' );
	$t_classification = dwg_publish_latex_escape( mb_strtoupper( trim( $t_meta['doctis-classification'] ?? '' ) ) );
	return "\\usepackage{fancyhdr}\n"
		. "\\usepackage{lastpage}\n"
		. "\\usepackage{xcolor}\n"
		# soul's \st, used for strikethrough, loses characters such as the em dash under xelatex.
		. "\\usepackage[normalem]{ulem}\n"
		. "\\providecommand{\\st}{}\\renewcommand{\\st}[1]{\\sout{#1}}\n"
		. "\\pagestyle{fancy}\n"
		. "\\fancyhf{}\n"
		. ( $t_classification !== '' ? "\\fancyhead[C]{\\small\\bfseries " . $t_classification . "}\n" : '' )
		. "\\renewcommand{\\headrulewidth}{0pt}\n"
		. "\\renewcommand{\\footrulewidth}{0.4pt}\n"
		. "\\fancyfoot[C]{%\n"
		. "  \\begin{minipage}{\\textwidth}\n"
		. "    \\small\\itshape\n"
		. "    \\makebox[0.4\\textwidth][l]{" . $t_left . "}%\n"
		. "    \\makebox[0.2\\textwidth][c]{\\upshape\\bfseries " . $t_classification . "}%\n"
		. "    \\makebox[0.4\\textwidth][r]{Page \\thepage\\ of \\pageref{LastPage}}\\\\[2pt]\n"
		. "    \\begin{center}\\color{red}UNCONTROLLED COPY WHEN PRINTED OR TRANSMITTED\\end{center}\n"
		. "  \\end{minipage}%\n"
		. "}\n"
		. "\\renewcommand{\\maketitle}{}\n";
}

/**
 * Build the PDF of a document in a working directory that holds source.md.
 * Raw LaTeX is turned off and TeX may not open files outside the working
 * directory: with raw LaTeX, a document could copy any file the web server
 * can read (such as config_inc.php) into the PDF.
 *
 * @param string $p_work_dir
 * @param array  $p_values   As returned by dwg_publish_template_values().
 * @return string The PDF.
 */
function dwg_publish_build_pdf( string $p_work_dir, array $p_values ): string {
	$t_problem = dwg_publish_pdf_problem();
	if( $t_problem !== '' ) {
		dwg_publish_error( $t_problem );
	}
	$t_papersize = (string)config_get( 'dwg_publish_pdf_papersize' );
	file_put_contents( $p_work_dir . '/header.tex', dwg_publish_pdf_header( $p_values ) );
	$t_args = array(
		'source.md', '--from=markdown-raw_tex-raw_attribute', '--standalone',
		'--pdf-engine=' . config_get_global( 'dwg_publish_pdf_engine' ),
		'--variable=mainfont:DejaVu Sans', '--variable=monofont:DejaVu Sans Mono',
		'--variable=geometry:margin=1in,footskip=0.6in',
		'--include-in-header=header.tex',
		'--lua-filter=' . __DIR__ . '/publish/control-block.lua',
		'--output=page.pdf',
	);
	# The Doctis values (title, control block) as metadata, escaped by pandoc.
	$t_args = array_merge( $t_args, dwg_publish_template_args( array( 'metadata' => $p_values['metadata'], 'variables' => array() ) ) );
	$t_args[] = '--metadata=title:' . str_replace( array( "\r", "\n" ), ' ', $p_values['metadata']['pagetitle'] );
	if( preg_match( '/^(a4|a5|letter|legal)$/', $t_papersize ) ) {
		$t_args[] = '--variable=papersize:' . $t_papersize;
	}
	dwg_publish_pandoc( $t_args, $p_work_dir, array( 'openin_any' => 'p' ) );
	return (string)file_get_contents( $p_work_dir . '/page.pdf' );
}

/**
 * Remove a page and its PDF, if the page marker records one.
 *
 * @param string $p_target
 * @param string $p_page
 * @param array  $p_marker
 * @return void
 */
function dwg_publish_remove_page( string $p_target, string $p_page, array $p_marker ): void {
	if( ( $p_marker['pdf'] ?? '' ) === dwg_publish_pdf_path( $p_page ) ) {
		dwg_publish_remove( $p_target, $p_marker['pdf'] );
	}
	dwg_publish_remove( $p_target, $p_page );
}

# ── Publishing ───────────────────────────────────────────────────────────────

/**
 * Explain why a document cannot be published.
 *
 * @param integer $p_dwg_id
 * @return string '' when it can.
 */
function dwg_publish_refusal( int $p_dwg_id ): string {
	$t_dwg  = dwg_get( $p_dwg_id );
	$t_site = dwg_publish_site( (int)$t_dwg->project_id );
	if( $t_site['target'] === '' ) {
		return $t_site['problem'] !== ''
			? 'the publication target directory cannot be used: ' . $t_site['problem']
			: 'the project has no publication target directory.';
	}
	$t_row = file_dwg_primary_get( $p_dwg_id );
	if( !$t_row ) {
		return 'the document has no registered file.';
	}
	if( !dwg_publish_is_markdown( $t_row['git_path'] ) ) {
		return 'only Markdown documents (.md) can be published.';
	}
	if( (int)$t_dwg->view_state === VS_PRIVATE ) {
		return 'private documents cannot be published.';
	}
	db_param_push();
	$t_result = db_query( 'SELECT COUNT(*) FROM {license_dwg_list} WHERE dwg_id=' . db_param(), array( $p_dwg_id ) );
	if( (int)db_result( $t_result ) > 0 ) {
		return 'documents restricted by a license cannot be published.';
	}
	return '';
}

/**
 * True when a user may publish a document and it can be published.
 *
 * @param integer      $p_dwg_id
 * @param integer|null $p_user_id
 * @return boolean
 */
function dwg_publish_can_publish( int $p_dwg_id, ?int $p_user_id = null ): bool {
	if( !dwg_publish_enabled() ) {
		return false;
	}
	$t_project_id = (int)dwg_get_field( $p_dwg_id, 'project_id' );
	return access_has_dwg_level( config_get( 'dwg_publish_threshold', null, null, $t_project_id ), $p_dwg_id, $p_user_id )
		&& dwg_publish_refusal( $p_dwg_id ) === '';
}

/**
 * True when a user may withdraw a document's published page.
 *
 * @param integer      $p_dwg_id
 * @param integer|null $p_user_id
 * @return boolean
 */
function dwg_publish_can_withdraw( int $p_dwg_id, ?int $p_user_id = null ): bool {
	$t_project_id = (int)dwg_get_field( $p_dwg_id, 'project_id' );
	return dwg_publish_enabled()
		&& access_has_dwg_level( config_get( 'dwg_publish_threshold', null, null, $t_project_id ), $p_dwg_id, $p_user_id );
}

/**
 * The template metadata for a document.  Values given with --metadata are
 * HTML-escaped by pandoc; --variable values are inserted as they are, so
 * only computed, validated values go there.
 *
 * @param DwgData $p_dwg
 * @param array   $p_row Primary file row.
 * @return array metadata => name => value, variables => name => value.
 */
function dwg_publish_template_values( DwgData $p_dwg, array $p_row ): array {
	$t_project_id = (int)$p_dwg->project_id;
	$t_status     = (int)$p_dwg->status;
	$t_label      = get_enum_element( 'dwg_status', $t_status, null, $t_project_id );
	$t_key        = MantisEnum::getLabel( config_get( 'dwg_status_enum_string', null, null, $t_project_id ), $t_status );
	$t_color      = get_dwg_status_color( $t_status, null, $t_project_id, '#d0d7de' );

	$t_date_format = config_get( 'short_date_format' );
	$t_record = substr( $p_row['git_sha'], 0, 8 );
	$t_commit = file_dwg_git_commit_metadata( dwg_project_bare_repo_path( $t_project_id ), $p_row['git_sha'] );
	if( $t_commit !== null ) {
		$t_record .= ', ' . date( $t_date_format, $t_commit['date'] );
	}
	$t_approval = dwg_publish_approval( $p_dwg );

	$t_metadata = array(
		'pagetitle'          => $p_dwg->title !== '' ? $p_dwg->title : basename( $p_row['git_path'] ),
		'doctis-id'          => (string)$p_dwg->id,
		'doctis-title'       => $p_dwg->title,
		'doctis-number'      => $p_dwg->number,
		'doctis-revision'    => $p_dwg->revision,
		'doctis-status'      => mb_strtoupper( $t_label ),
		'doctis-project'     => project_get_name( $t_project_id ),
		'doctis-commit'      => substr( $p_row['git_sha'], 0, 8 ),
		'doctis-published'   => date( config_get( 'normal_date_format' ) ),
		'doctis-url'         => config_get_global( 'path' ) . 'dwg_view.php?id=' . (int)$p_dwg->id,
		'doctis-classification' => $p_dwg->classification,
		# The Owner row is the person responsible for the document, not whoever
		# holds the current review step: a reader of a controlled document needs
		# the former.  control-block.lua drops rows with an empty value, so an
		# unowned document simply has no Owner row.  See doc/dwg/OWNERSHIP.md §8.
		'doctis-owner'       => (int)$p_dwg->owner_id > 0 ? user_get_name( (int)$p_dwg->owner_id ) : '',
		'doctis-approved'    => $t_approval !== null
			? sprintf( lang_get( 'dwg_publish_approved_value' ), date( $t_date_format, $t_approval['date'] ), user_get_name( $t_approval['user_id'] ) )
			: '',
		'doctis-effective'   => dwg_publish_date( $p_dwg->release_date, $t_date_format ),
		'doctis-review'      => dwg_publish_date( $p_dwg->due_date, $t_date_format ),
		'doctis-record'      => $t_record,
	);
	if( ON == config_get( 'dwg_publish_control_block', null, null, $t_project_id ) ) {
		$t_metadata['doctis-control'] = '1';
		$t_labels = array(
			'number' => 'dwg_number', 'title' => 'dwg_title', 'revision' => 'dwg_revision', 'status' => 'status',
			'classification' => 'dwg_classification', 'owner' => 'dwg_publish_owner', 'approved' => 'dwg_publish_approved',
			'effective' => 'dwg_publish_effective', 'review' => 'dwg_publish_review', 'record' => 'primary_document_approved',
			'published' => 'dwg_publish_published',
		);
		foreach( $t_labels as $t_key => $t_string ) {
			$t_metadata['doctis-label-' . $t_key] = lang_get( $t_string );
		}
	}
	$t_variables = array(
		'doctis-status-key'   => trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $t_key ) ), '-' ),
		'doctis-status-color' => preg_match( '/^#[0-9a-fA-F]{3,8}$/', $t_color ) ? $t_color : '#d0d7de',
	);
	return array( 'metadata' => array_filter( $t_metadata, 'strlen' ), 'variables' => $t_variables );
}

/**
 * The latest approval of a document still in an approved status
 * ($g_dwg_publish_approved_statuses): the history entry that set it.
 *
 * @param DwgData $p_dwg
 * @return array|null user_id and date, or null when not approved.
 */
function dwg_publish_approval( DwgData $p_dwg ): ?array {
	$t_statuses = array_map( 'intval', (array)config_get( 'dwg_publish_approved_statuses', null, null, (int)$p_dwg->project_id ) );
	if( !$t_statuses || !in_array( (int)$p_dwg->status, $t_statuses, true ) ) {
		return null;
	}
	db_param_push();
	$t_result = db_query( 'SELECT user_id, date_modified FROM {dwg_history} WHERE dwg_id=' . db_param()
		. " AND field_name='status' AND new_value IN (" . implode( ',', $t_statuses ) . ')'
		. ' ORDER BY date_modified DESC, id DESC', array( (int)$p_dwg->id ), 1 );
	$t_row = db_fetch_array( $t_result );
	return $t_row ? array( 'user_id' => (int)$t_row['user_id'], 'date' => (int)$t_row['date_modified'] ) : null;
}

/**
 * Format a document date field, or '' when it is not set.
 *
 * @param mixed  $p_value Timestamp.
 * @param string $p_format
 * @return string
 */
function dwg_publish_date( $p_value, string $p_format ): string {
	return is_numeric( $p_value ) && (int)$p_value > 1 && !date_is_null( (int)$p_value ) ? date( $p_format, (int)$p_value ) : '';
}

/**
 * Pandoc arguments for metadata and variables.
 *
 * @param array $p_values As returned by dwg_publish_template_values().
 * @return array
 */
function dwg_publish_template_args( array $p_values ): array {
	$t_args = array();
	foreach( $p_values['metadata'] as $t_name => $t_value ) {
		# Pandoc takes the value literally (only "true" and "false" become booleans).
		$t_args[] = '--metadata=' . $t_name . ':' . str_replace( array( "\r", "\n" ), ' ', $t_value );
	}
	foreach( $p_values['variables'] as $t_name => $t_value ) {
		$t_args[] = '--variable=' . $t_name . ':' . $t_value;
	}
	return $t_args;
}

/**
 * Regenerate the site navigation in every page except one, and the index.
 *
 * @param array  $p_site     As returned by dwg_publish_site().
 * @param array  $p_pages    The site's pages after the change.
 * @param string $p_skip     A page just written with current navigation.
 * @param string $p_template Template text for the index, or null to remove it.
 * @param string $p_logo     Banner logo HTML.
 * @return void
 */
function dwg_publish_refresh_site( array $p_site, array $p_pages, string $p_skip, ?string $p_template, string $p_logo ): void {
	$t_target = $p_site['target'];
	$t_tree   = dwg_publish_nav_tree( $p_pages );
	foreach( array_keys( $p_pages ) as $t_page ) {
		if( $t_page === $p_skip ) {
			continue;
		}
		$t_html = @file_get_contents( $t_target . '/' . $t_page );
		if( $t_html === false ) {
			continue;
		}
		$t_new = dwg_publish_replace_nav( $t_html, dwg_publish_nav_html( $t_tree, dwg_publish_prefix( $t_page ) ) );
		if( $t_new !== null && $t_new !== $t_html ) {
			dwg_publish_write( $t_target, $t_page, $t_new );
		}
	}
	dwg_publish_write_index( $p_site, $p_pages, $t_tree, $p_template, $p_logo );
}

/**
 * Write the generated index.html, a register of the site's published
 * documents.  A published document at index.html takes its place, an
 * index.html that Doctis did not write is left alone, and the index is
 * removed when the site has no pages (or no template is available).
 *
 * @param array       $p_site
 * @param array       $p_pages
 * @param array       $p_tree     As returned by dwg_publish_nav_tree().
 * @param string|null $p_template
 * @param string      $p_logo
 * @return void
 */
function dwg_publish_write_index( array $p_site, array $p_pages, array $p_tree, ?string $p_template, string $p_logo ): void {
	$t_target = $p_site['target'];
	$t_file   = $t_target . '/' . DWG_PUBLISH_INDEX;
	if( isset( $p_pages[DWG_PUBLISH_INDEX] ) ) {
		return;
	}
	if( file_exists( $t_file ) ) {
		$t_marker = dwg_publish_read_marker( $t_file );
		if( $t_marker === null || empty( $t_marker['index'] ) ) {
			return;
		}
	}
	if( !$p_pages || $p_template === null ) {
		dwg_publish_remove( $t_target, DWG_PUBLISH_INDEX );
		return;
	}

	$t_has_pdf = count( array_filter( array_column( $p_pages, 'pdf' ) ) ) > 0;
	$t_body = '<h1>' . htmlspecialchars( lang_get( 'dwg_publish_index_title' ) ) . "</h1>\n<table>\n<thead><tr>"
		. '<th>' . htmlspecialchars( lang_get( 'dwg_title' ) ) . '</th>'
		. '<th>' . htmlspecialchars( lang_get( 'dwg_number' ) ) . '</th>'
		. '<th>' . htmlspecialchars( lang_get( 'dwg_revision' ) ) . '</th>'
		. '<th>' . htmlspecialchars( lang_get( 'status' ) ) . '</th>'
		. '<th>' . htmlspecialchars( lang_get( 'dwg_publish_published' ) ) . '</th>'
		. ( $t_has_pdf ? '<th>PDF</th>' : '' ) . "</tr></thead>\n<tbody>\n";
	foreach( $p_pages as $t_page => $t_marker ) {
		$t_title = trim( (string)( $t_marker['title'] ?? '' ) );
		$t_body .= '<tr><td><a href="' . htmlspecialchars( dwg_publish_href( $t_page ) ) . '">'
			. htmlspecialchars( $t_title !== '' ? $t_title : $t_page ) . '</a></td>'
			. '<td>' . htmlspecialchars( (string)( $t_marker['number'] ?? '' ) ) . '</td>'
			. '<td>' . htmlspecialchars( (string)( $t_marker['revision'] ?? '' ) ) . '</td>'
			. '<td>' . htmlspecialchars( (string)( $t_marker['status_label'] ?? '' ) ) . '</td>'
			. '<td>' . htmlspecialchars( isset( $t_marker['published'] ) ? date( config_get( 'short_date_format' ), (int)$t_marker['published'] ) : '' ) . '</td>'
			. ( $t_has_pdf ? '<td>' . ( isset( $t_marker['pdf'] ) ? '<a href="' . htmlspecialchars( dwg_publish_href( (string)$t_marker['pdf'] ) ) . '">PDF</a>' : '' ) . '</td>' : '' )
			. "</tr>\n";
	}
	$t_body .= "</tbody>\n</table>\n";

	$t_work = dwg_publish_work_dir();
	try {
		file_put_contents( $t_work . '/template.html', $p_template );
		file_put_contents( $t_work . '/index-body.html', $t_body );
		file_put_contents( $t_work . '/before.html', dwg_publish_before_body( '', dwg_publish_nav_html( $p_tree, '' ), $p_logo ) );
		$t_values = array(
			'metadata'  => array( 'pagetitle' => lang_get( 'dwg_publish_index_title' ) ),
			'variables' => array(),
		);
		if( (int)$p_site['project_id'] > 0 ) {
			$t_values['metadata']['doctis-project'] = project_get_name( (int)$p_site['project_id'] );
		}
		dwg_publish_pandoc( array_merge( array(
			'index-body.html', '--from=html', '--to=html5',
			'--template=template.html', '--include-before-body=before.html',
			'--output=index.html',
		), dwg_publish_template_args( $t_values ) ), $t_work );
		$t_html = (string)file_get_contents( $t_work . '/index.html' );
	} finally {
		dwg_publish_work_dir_delete( $t_work );
	}
	dwg_publish_write( $t_target, DWG_PUBLISH_INDEX,
		$t_html . dwg_publish_marker_line( array( 'index' => 1, 'updated' => time() ) ) );
}

/**
 * Publish a document's on-record version to its project's website.
 *
 * @param integer $p_dwg_id
 * @param integer $p_user_id
 * @return array page (site-relative path), url ('' when the site has no URL),
 *               pdf (site-relative path, '' when no PDF) and pdf_url.
 */
function dwg_publish( int $p_dwg_id, int $p_user_id ): array {
	$t_problem = dwg_publish_refusal( $p_dwg_id );
	if( $t_problem !== '' ) {
		dwg_publish_error( $t_problem );
	}
	$t_dwg    = dwg_get( $p_dwg_id, true );
	$t_site   = dwg_publish_site( (int)$t_dwg->project_id );
	$t_target = $t_site['target'];
	$t_row    = file_dwg_primary_get( $p_dwg_id );
	$t_page   = dwg_publish_page_path( $t_row['git_path'] );

	$t_source = file_dwg_get_storage_backend()->retrieve( $t_row, (int)$t_dwg->project_id );
	if( $t_source === false ) {
		dwg_publish_error( 'the on-record version could not be read from the repository.' );
	}
	$t_template = dwg_publish_template_text( $t_site );
	$t_logo     = dwg_publish_logo_html( $t_site );
	$t_values   = dwg_publish_template_values( $t_dwg, $t_row );

	$t_lock = dwg_publish_lock( $t_target );
	try {
		$t_pages = dwg_publish_site_pages( $t_target );
		$t_file  = $t_target . '/' . $t_page;
		if( file_exists( $t_file ) ) {
			$t_existing = dwg_publish_read_marker( $t_file );
			$t_ours = $t_existing !== null && ( isset( $t_existing['dwg_id'] )
				? (int)$t_existing['dwg_id'] === $p_dwg_id
				: !empty( $t_existing['index'] ) );
			if( !$t_ours ) {
				dwg_publish_error( $t_page . ' already exists in the target directory and was not published from this document.' );
			}
		}
		$t_previous = $t_pages[$t_page] ?? null;

		$t_pdf = $t_site['pdf'] ? dwg_publish_pdf_path( $t_page ) : '';
		if( $t_pdf !== '' && file_exists( $t_target . '/' . $t_pdf ) && ( $t_previous['pdf'] ?? '' ) !== $t_pdf ) {
			dwg_publish_error( $t_pdf . ' already exists in the target directory and was not published from this document.' );
		}
		if( $t_pdf !== '' ) {
			$t_values['variables']['doctis-pdf'] = rawurlencode( basename( $t_pdf ) );
		}

		$t_marker = array(
			'dwg_id'       => $p_dwg_id,
			'project_id'   => (int)$t_dwg->project_id,
			'path'         => $t_row['git_path'],
			'sha'          => $t_row['git_sha'],
			'title'        => $t_dwg->title,
			'number'       => $t_dwg->number,
			'revision'     => $t_dwg->revision,
			'status'       => (int)$t_dwg->status,
			'status_label' => $t_values['metadata']['doctis-status'],
			'classification' => $t_dwg->classification,
			'published'    => time(),
			'user_id'      => $p_user_id,
		);
		if( $t_pdf !== '' ) {
			$t_marker['pdf'] = $t_pdf;
		}
		$t_pages[$t_page] = $t_marker + array( 'page' => $t_page );

		# A page of this document at an earlier path (the file was renamed) is replaced.
		$t_moved = array();
		foreach( $t_pages as $t_other => $t_other_marker ) {
			if( $t_other !== $t_page && (int)$t_other_marker['dwg_id'] === $p_dwg_id ) {
				$t_moved[$t_other] = $t_other_marker;
				unset( $t_pages[$t_other] );
			}
		}
		ksort( $t_pages, SORT_STRING );
		$t_tree   = dwg_publish_nav_tree( $t_pages );
		$t_prefix = dwg_publish_prefix( $t_page );

		$t_work = dwg_publish_work_dir();
		try {
			file_put_contents( $t_work . '/source.md', $t_source['content'] );
			file_put_contents( $t_work . '/template.html', $t_template );
			file_put_contents( $t_work . '/before.html', dwg_publish_before_body( $t_prefix, dwg_publish_nav_html( $t_tree, $t_prefix ), $t_logo ) );
			dwg_publish_pandoc( array_merge( array(
				# Without raw_tex, pandoc drops text such as "CzProject\GitPhp" as LaTeX.
				'source.md', '--from=markdown-raw_tex', '--to=html5',
				'--template=template.html',
				'--lua-filter=' . __DIR__ . '/publish/rewrite-links.lua',
				'--lua-filter=' . __DIR__ . '/publish/control-block.lua',
				'--include-before-body=before.html',
				'--output=page.html',
			), dwg_publish_template_args( $t_values ) ), $t_work );
			$t_html = (string)file_get_contents( $t_work . '/page.html' );
			$t_pdf_content = $t_pdf !== '' ? dwg_publish_build_pdf( $t_work, $t_values ) : '';
		} finally {
			dwg_publish_work_dir_delete( $t_work );
		}

		if( $t_pdf !== '' ) {
			dwg_publish_write( $t_target, $t_pdf, $t_pdf_content );
		} else if( $t_previous && isset( $t_previous['pdf'] ) && $t_previous['pdf'] === dwg_publish_pdf_path( $t_page ) ) {
			# PDFs were turned off since the last publish.
			dwg_publish_remove( $t_target, $t_previous['pdf'] );
		}
		dwg_publish_write( $t_target, $t_page, $t_html . dwg_publish_marker_line( $t_marker ) );
		foreach( $t_moved as $t_other => $t_other_marker ) {
			dwg_publish_remove_page( $t_target, $t_other, $t_other_marker );
		}
		dwg_publish_refresh_site( $t_site, $t_pages, $t_page, $t_template, $t_logo );
	} finally {
		dwg_publish_unlock( $t_lock );
	}

	history_dwg_log_event_direct( $p_dwg_id, 'dwg_published',
		$t_previous ? dwg_publish_history_value( $t_previous ) : '',
		dwg_publish_history_value( $t_marker + array( 'page' => $t_page ) ), $p_user_id );

	# The document URL field points at the published page.
	$t_link = dwg_publish_absolute_url( dwg_publish_page_url( $t_site, $t_page ) );
	if( $t_link !== '' && ON == config_get( 'dwg_publish_set_link_url', null, null, (int)$t_dwg->project_id ) ) {
		dwg_publish_set_link_url( $p_dwg_id, $t_link, $p_user_id );
	}

	return array(
		'page'    => $t_page,
		'url'     => dwg_publish_page_url( $t_site, $t_page ),
		'pdf'     => $t_pdf,
		'pdf_url' => $t_pdf !== '' ? dwg_publish_page_url( $t_site, $t_pdf ) : '',
	);
}

/**
 * Remove a document's published pages from its project's website.
 *
 * @param integer $p_dwg_id
 * @param integer $p_user_id
 * @param boolean $p_log     False when the document itself is being deleted.
 * @return integer The number of pages removed.
 */
function dwg_publish_withdraw( int $p_dwg_id, int $p_user_id, bool $p_log = true ): int {
	$t_site   = dwg_publish_site( (int)dwg_get_field( $p_dwg_id, 'project_id' ) );
	$t_target = $t_site['target'];
	if( $t_target === '' || !is_dir( $t_target ) ) {
		return 0;
	}

	$t_lock = dwg_publish_lock( $t_target );
	try {
		$t_pages   = dwg_publish_site_pages( $t_target );
		$t_removed = array();
		foreach( $t_pages as $t_page => $t_marker ) {
			if( (int)$t_marker['dwg_id'] === $p_dwg_id ) {
				dwg_publish_remove_page( $t_target, $t_page, $t_marker );
				$t_removed[] = $t_marker;
				unset( $t_pages[$t_page] );
			}
		}
		if( $t_removed ) {
			# Withdrawal must not depend on the template; without one the index
			# is removed rather than left listing the withdrawn page.
			try {
				$t_template = dwg_publish_template_text( $t_site );
			} catch( ClientException $e ) {
				$t_template = null;
			}
			dwg_publish_refresh_site( $t_site, $t_pages, '', $t_template, dwg_publish_logo_html( $t_site ) );
		}
	} finally {
		dwg_publish_unlock( $t_lock );
	}

	if( $p_log ) {
		foreach( $t_removed as $t_marker ) {
			history_dwg_log_event_direct( $p_dwg_id, 'dwg_published', dwg_publish_history_value( $t_marker ), '', $p_user_id );
			# Clear the document URL only if it still points at the removed page.
			$t_link = dwg_publish_absolute_url( dwg_publish_page_url( $t_site, (string)$t_marker['page'] ) );
			if( $t_link !== '' && (string)dwg_get_field( $p_dwg_id, 'link_url' ) === $t_link ) {
				dwg_publish_set_link_url( $p_dwg_id, '', $p_user_id );
			}
		}
	}
	return count( $t_removed );
}

/**
 * Withdraw a document's published pages while the document is deleted.
 * Failures are logged, never raised, so they cannot block the deletion.
 *
 * @param integer $p_dwg_id
 * @return void
 */
function dwg_publish_withdraw_on_delete( int $p_dwg_id ): void {
	if( !dwg_publish_enabled() ) {
		return;
	}
	try {
		dwg_publish_withdraw( $p_dwg_id, 0, false );
	} catch( Exception $e ) {
		error_log( 'Doctis: could not withdraw the published page of document ' . $p_dwg_id . ': ' . $e->getMessage() );
	}
}

/**
 * The history value of a published page: commit, status and page path.
 *
 * @param array $p_marker
 * @return string
 */
function dwg_publish_history_value( array $p_marker ): string {
	return substr( (string)( $p_marker['sha'] ?? '' ), 0, 8 ) . ' '
		. ( $p_marker['status_label'] ?? '' ) . ' ' . ( $p_marker['page'] ?? '' );
}
