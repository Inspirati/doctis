<?php
/**
 * Doctis git repository importer (WP7 — see doc/git/GIT_IMPORTER.md).
 *
 * Adopts an existing git repository as a Doctis project's document store and
 * registers its qualifying files as Doctis documents — by reference, without
 * creating any commits.  Run on the server as www-data:
 *
 *   sudo -u www-data php admin/import-git-repo.php --source <path> [options]
 *
 * Options (CLI overrides a committed .doctis manifest at the source HEAD):
 *   --source <path>         Path of the git repository to import (required)
 *   --user <name>           Doctis account performing the import (default: administrator)
 *   --name <name>           Parent project name (default: manifest, else source basename)
 *   --description <text>    Parent project description
 *   --directories <a,b>     Allowlisted root directories (recursive; default: whole tree)
 *   --patterns <a,b>        Document file globs, matched on basename (default: *.md)
 *   --subprojects <mode>    none | subdirs (default: none)
 *   --subdir-marker <file>  Marker that qualifies a subdirectory (default: .doctis;
 *                           when no subdirectory has it, every top-level dir qualifies)
 *   --frontmatter <yes|no>  Parse YAML frontmatter from matched files (default: yes)
 *   --filename-parse <y|n>  Parse number/revision/title from filenames (default: no)
 *   --category-from <mode>  directory | none (default: directory — D7 path-as-category)
 *   --update                Re-import into an existing project set: register new files,
 *                           skip registered paths, report paths missing at HEAD
 *   --dry-run               Print the full would-be result without writing anything
 *
 * .doctis manifest (INI, committed at the repo root):
 *   [project]  name, description
 *   [import]   directories, patterns, subprojects, subdir_marker
 *   [metadata] frontmatter, filename_parse, category_from
 * A per-subdirectory .doctis may set name, description, patterns, import=no.
 *
 * Metadata gleaning (GIT_IMPORTER.md §6): frontmatter (doc_id → number,
 * title, revision, status, classification, owner, effective_date,
 * review_period) outranks git history (first/last commit dates, author →
 * creator by email) outranks path/filename conventions.  Note: Doctis
 * manages documents.reference as the on-record SHA, so frontmatter doc_id
 * maps to documents.number.
 */

$t_options = getopt( '', array(
	'source:', 'user:', 'name:', 'description:', 'directories:', 'patterns:',
	'subprojects:', 'subdir-marker:', 'frontmatter:', 'filename-parse:',
	'category-from:', 'update', 'dry-run', 'help',
) );

if( isset( $t_options['help'] ) || !isset( $t_options['source'] ) ) {
	$t_lines = file( __FILE__ );
	foreach( array_slice( $t_lines, 1, 40 ) as $t_line ) {
		if( strpos( $t_line, '*/' ) !== false ) {
			break;
		}
		echo preg_replace( '/^\s*\*( |$)/', '', $t_line );
	}
	exit( isset( $t_options['help'] ) ? 0 : 1 );
}

$t_mantis_dir = dirname( __DIR__ ) . '/';
require_once( $t_mantis_dir . 'core.php' );
require_once( $t_mantis_dir . 'api/soap/mc_core.php' );   # mci_* helpers used by DwgAddCommand
require_api( 'authentication_api.php' );
require_api( 'category_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'project_hierarchy_api.php' );
require_api( 'repository_api.php' );
require_api( 'user_api.php' );

use Mantis\Exceptions\ClientException;

# ── CLI / manifest configuration ─────────────────────────────────────────────

$g_dry    = isset( $t_options['dry-run'] );
$g_update = isset( $t_options['update'] );

$t_source = rtrim( $t_options['source'], '/' );
$t_login  = $t_options['user'] ?? 'administrator';

auth_attempt_script_login( $t_login );
$g_import_user_id = auth_get_current_user_id();

function say( string $p_msg ): void {
	echo $p_msg . "\n";
}

function abort( string $p_msg ): void {
	fwrite( STDERR, "ERROR: $p_msg\n" );
	exit( 1 );
}

# All git reads use `git -C <dir>` — valid for both a source working tree and
# a bare repository directory, and immune to the CWD-based path/revision
# disambiguation that `--git-dir` + non-bare repos perform (which fails
# hard when the importer's CWD is unreadable to www-data).
function src_git( string $p_repo, string $p_args ): string {
	return trim( (string)shell_exec(
		'git -C ' . escapeshellarg( $p_repo ) . ' ' . $p_args . ' 2>/dev/null'
	) );
}

/** Parse a .doctis INI manifest string into [section][key] => value. */
function manifest_parse( string $p_content ): array {
	$t_ini = @parse_ini_string( $p_content, true, INI_SCANNER_RAW );
	return is_array( $t_ini ) ? array_change_key_case( $t_ini, CASE_LOWER ) : array();
}

function manifest_get( array $p_manifest, string $p_section, string $p_key, $p_default ) {
	return $p_manifest[$p_section][$p_key] ?? $p_default;
}

function csv_list( string $p_value ): array {
	return array_values( array_filter( array_map( 'trim', explode( ',', $p_value ) ) ) );
}

# Manifest committed at the source HEAD wins over nothing; CLI wins over manifest.
$t_manifest_raw = src_git( $t_source, 'show HEAD:.doctis' );
$t_manifest     = $t_manifest_raw !== '' ? manifest_parse( $t_manifest_raw ) : array();

$g_cfg = array(
	'name'           => $t_options['name']
		?? manifest_get( $t_manifest, 'project', 'name', basename( $t_source ) ),
	'description'    => $t_options['description']
		?? manifest_get( $t_manifest, 'project', 'description', 'Imported from git repository ' . basename( $t_source ) ),
	'directories'    => csv_list( $t_options['directories']
		?? manifest_get( $t_manifest, 'import', 'directories', '' ) ),
	'patterns'       => csv_list( $t_options['patterns']
		?? manifest_get( $t_manifest, 'import', 'patterns', '*.md' ) ),
	'subprojects'    => $t_options['subprojects']
		?? manifest_get( $t_manifest, 'import', 'subprojects', 'none' ),
	'subdir_marker'  => $t_options['subdir-marker']
		?? manifest_get( $t_manifest, 'import', 'subdir_marker', '.doctis' ),
	'frontmatter'    => ( $t_options['frontmatter']
		?? manifest_get( $t_manifest, 'metadata', 'frontmatter', 'yes' ) ) !== 'no',
	'filename_parse' => ( $t_options['filename-parse']
		?? manifest_get( $t_manifest, 'metadata', 'filename_parse', 'no' ) ) !== 'no',
	'category_from'  => $t_options['category-from']
		?? manifest_get( $t_manifest, 'metadata', 'category_from', 'directory' ),
);

# ── Phase A1: pre-flight ─────────────────────────────────────────────────────

say( '── Phase A: repository adoption ─────────────────────────────────────────' );
say( 'Source   : ' . $t_source );
say( 'Project  : ' . $g_cfg['name'] . ( $g_update ? ' (update mode)' : '' ) . ( $g_dry ? '  [DRY RUN]' : '' ) );

if( !is_dir( $t_source ) ) {
	abort( 'Source does not exist: ' . $t_source );
}
if( src_git( $t_source, 'rev-parse --git-dir' ) === '' ) {
	abort( 'Source is not a git repository: ' . $t_source );
}
$t_head = src_git( $t_source, 'rev-parse HEAD' );
if( !preg_match( '/^[0-9a-f]{40}$/', $t_head ) ) {
	abort( 'Source repository has no commits.' );
}
$t_default_branch = src_git( $t_source, 'symbolic-ref --short HEAD' );
if( $t_default_branch === '' ) {
	$t_default_branch = 'main';
}
$t_dirty = src_git( $t_source, 'status --porcelain' );
if( $t_dirty !== '' ) {
	say( 'WARNING  : source working tree is dirty (' . count( explode( "\n", $t_dirty ) )
		. ' entries) — only committed content at HEAD will be imported.' );
}
$t_gitattributes = src_git( $t_source, 'show HEAD:.gitattributes' );
if( strpos( $t_gitattributes, 'filter=lfs' ) !== false ) {
	abort( 'Source uses git-LFS; adoption of LFS repositories is not supported yet (see GIT_TODO.md §7 LFS).' );
}
$t_storage_root = config_get_global( 'git_storage_root' );
if( is_blank( $t_storage_root ) || !is_dir( $t_storage_root ) || !is_writable( $t_storage_root ) ) {
	abort( '$g_git_storage_root is not configured/writable: ' . $t_storage_root );
}
say( 'Branch   : ' . $t_default_branch . '   HEAD: ' . substr( $t_head, 0, 8 ) );

# ── Phase A2/A3: projects ────────────────────────────────────────────────────

/** Create (or in update/dry mode, resolve) a project by name; returns id (0 in dry-run when absent). */
function project_ensure( string $p_name, string $p_description, ?int $p_parent_id ): int {
	global $g_dry, $g_update;
	$t_existing = project_get_id_by_name( $p_name, /* default */ 0 );
	if( $t_existing > 0 ) {
		if( !$g_update ) {
			abort( "Project '$p_name' already exists — re-run with --update to import into it." );
		}
		return (int)$t_existing;
	}
	if( $g_dry ) {
		say( "  [dry-run] would create project '$p_name'" . ( $p_parent_id !== null ? ' (sub-project)' : '' ) );
		return 0;
	}
	$t_id = project_create( $p_name, $p_description, /* status: development */ 10 );
	if( $p_parent_id !== null && $p_parent_id > 0 ) {
		project_hierarchy_add( $t_id, $p_parent_id );
	}
	say( "  Created project '$p_name' (id $t_id)" . ( $p_parent_id !== null ? " under project $p_parent_id" : '' ) );
	return $t_id;
}

$t_parent_id = project_ensure( $g_cfg['name'], $g_cfg['description'], null );

# Sub-projects: one per qualifying top-level directory.  A directory qualifies
# when it contains the committed marker file; when NO directory has the
# marker, every non-hidden top-level directory qualifies (fallback).
# $g_roots maps each import root ('' = repo root) to its target project id.
$g_roots       = array();   # root path (no trailing slash) => project id
$g_subdir_cfg  = array();   # root path => per-subdir manifest overrides
if( $g_cfg['subprojects'] === 'subdirs' ) {
	$t_top_dirs = array();
	foreach( array_filter( explode( "\n", src_git( $t_source, 'ls-tree HEAD' ) ) ) as $t_line ) {
		# <mode> SP <type> SP <object> TAB <name>
		if( preg_match( '/^\d+ tree [0-9a-f]+\t(.+)$/', $t_line, $t_m ) && $t_m[1][0] !== '.' ) {
			$t_top_dirs[] = $t_m[1];
		}
	}
	$t_marked = array();
	foreach( $t_top_dirs as $t_dir ) {
		if( src_git( $t_source, 'cat-file -e HEAD:' . escapeshellarg( $t_dir . '/' . $g_cfg['subdir_marker'] ) . ' 2>/dev/null && echo yes' ) === 'yes' ) {
			$t_marked[] = $t_dir;
		}
	}
	$t_qualifying = !empty( $t_marked ) ? $t_marked : $t_top_dirs;
	if( empty( $t_marked ) ) {
		say( 'No committed ' . $g_cfg['subdir_marker'] . ' markers found — every top-level directory qualifies ('
			. count( $t_qualifying ) . ' sub-projects).' );
	}
	foreach( $t_qualifying as $t_dir ) {
		$t_sub_manifest = manifest_parse( src_git( $t_source,
			'show HEAD:' . escapeshellarg( $t_dir . '/' . $g_cfg['subdir_marker'] ) ) );
		if( manifest_get( $t_sub_manifest, 'import', 'import', 'yes' ) === 'no' ) {
			say( "  Skipping '$t_dir' — marked import=no" );
			continue;
		}
		$t_sub_name = manifest_get( $t_sub_manifest, 'project', 'name', $t_dir );
		$t_sub_desc = manifest_get( $t_sub_manifest, 'project', 'description',
			'Imported sub-project for ' . $t_dir . ' (repository ' . basename( $t_source ) . ')' );
		$g_roots[$t_dir]      = project_ensure( $t_sub_name, $t_sub_desc, $t_parent_id );
		$g_subdir_cfg[$t_dir] = $t_sub_manifest;
	}
} else {
	if( empty( $g_cfg['directories'] ) ) {
		$g_roots[''] = $t_parent_id;
	} else {
		foreach( $g_cfg['directories'] as $t_dir ) {
			$g_roots[rtrim( $t_dir, '/' )] = $t_parent_id;
		}
	}
}

# ── Phase A4/A5: repository entity + adoption ────────────────────────────────

# $t_repo_dir is the directory Phase B reads from with `git -C`: the source
# working tree in dry-run mode, the adopted bare repository otherwise.
$t_repo_dir = '';
if( $g_dry ) {
	$t_repo_dir = $t_source;
	say( '  [dry-run] would create {repository} row and adopt into '
		. $t_storage_root . '/' . repository_slug( $g_cfg['name'] ) . '-r<id>.git' );
} else {
	$t_repo_id = $t_parent_id > 0 ? repository_id_for_project( $t_parent_id ) : 0;
	if( $t_repo_id > 0 && is_dir( repository_bare_path( $t_repo_id ) ) ) {
		if( !$g_update ) {
			abort( 'Project already has a repository on disk — use --update.' );
		}
		say( '  Using existing repository r' . $t_repo_id . ' (' . repository_basename( $t_repo_id ) . ')' );
	} else {
		$t_repo_id = repository_create( $g_cfg['name'], $t_parent_id, $t_source, $t_default_branch );
		$t_paths   = repository_adopt( $t_repo_id, $t_source );
		say( '  Adopted ' . $t_source . ' → ' . $t_paths['bare'] );
	}
	$t_repo_dir = repository_bare_path( $t_repo_id );
}

# ── Phase B: discovery, metadata, registration ───────────────────────────────

say( '' );
say( '── Phase B: document discovery and registration ─────────────────────────' );

# B1 — discover committed files at HEAD, matching roots + patterns.
# NUL-delimited listing handles spaces and exotic characters in paths.
$t_ls = (string)shell_exec(
	'git -C ' . escapeshellarg( $t_repo_dir ) . ' ls-tree -r -z --name-only HEAD 2>/dev/null'
);
$t_all_files = array_filter( explode( "\x00", $t_ls ) );

function patterns_for_root( string $p_root ): array {
	global $g_cfg, $g_subdir_cfg;
	if( isset( $g_subdir_cfg[$p_root] ) ) {
		$t_override = manifest_get( $g_subdir_cfg[$p_root], 'import', 'patterns', '' );
		if( $t_override !== '' ) {
			return csv_list( $t_override );
		}
	}
	return $g_cfg['patterns'];
}

$t_candidates = array();   # path => root
foreach( $t_all_files as $t_path ) {
	foreach( $g_roots as $t_root => $t_project_id ) {
		if( $t_root !== '' && strpos( $t_path, $t_root . '/' ) !== 0 ) {
			continue;
		}
		foreach( patterns_for_root( $t_root ) as $t_pattern ) {
			if( fnmatch( strtolower( $t_pattern ), strtolower( basename( $t_path ) ) ) ) {
				$t_candidates[$t_path] = $t_root;
				continue 3;
			}
		}
	}
}
say( count( $t_candidates ) . ' candidate file(s) matched under ' . count( $g_roots ) . ' import root(s).' );

# ── Metadata helpers (GIT_IMPORTER.md §6) ────────────────────────────────────

/** Naive YAML frontmatter: leading --- block of "key: value" lines. */
function frontmatter_parse( string $p_content ): array {
	if( !preg_match( '/^---\R(.*?)\R---(\R|$)/s', $p_content, $t_m ) ) {
		return array();
	}
	$t_out = array();
	foreach( preg_split( '/\R/', $t_m[1] ) as $t_line ) {
		if( preg_match( '/^([A-Za-z0-9_\-]+):\s*(.*)$/', $t_line, $t_kv ) ) {
			$t_out[strtolower( $t_kv[1] )] = trim( $t_kv[2], " \t\"'" );
		}
	}
	return $t_out;
}

/**
 * Map a frontmatter/filename status word to [dwg status id, pin-on-record].
 * Draft documents are registered without an on-record pin (GIT_IMPORTER D6).
 */
function status_map( string $p_status ): array {
	$t_status = strtolower( trim( $p_status ) );
	# Qualified draft statuses ("Draft — requires CEO signature…") are drafts.
	if( strpos( $t_status, 'draft' ) === 0 ) {
		return array( 110, false );
	}
	switch( $t_status ) {
		case '':
			return array( 110, true );
		case 'in review':
		case 'review':
			return array( 160, true );
		case 'approved':
		case 'effective':
		case 'active':
			return array( 180, true );
		case 'released':
			return array( 190, true );
		case 'superseded':
		case 'obsolete':
		case 'withdrawn':
			return array( 195, true );
		default:
			return array( 110, true );   # unknown → pending; caller reports it
	}
}

/**
 * Parse hardware-style filenames:
 *   HCR-570C-Gimbal-IMU-Rev-D_(Uncontrolled-Draft-V2).pdf
 * → number HCR-570, edition C, revision D, title "Gimbal IMU", draft flag.
 */
function filename_parse( string $p_basename ): array {
	$t_out = array();
	if( preg_match( '/^([A-Z]{2,5}-\d+)([A-Z])?-(.+?)(?:-Rev-([A-Za-z0-9]+))?(?:_\((.+?)\))?\.[A-Za-z0-9]+$/', $p_basename, $t_m ) ) {
		$t_out['number']   = $t_m[1];
		$t_out['edition']  = $t_m[2] ?? '';
		$t_out['title']    = str_replace( '-', ' ', $t_m[3] );
		$t_out['revision'] = $t_m[4] ?? '';
		if( isset( $t_m[5] ) && stripos( $t_m[5], 'draft' ) !== false ) {
			$t_out['draft'] = true;
		}
	}
	return $t_out;
}

/** Resolve/create a category for a project (dry-run tolerant). */
function category_ensure( string $p_name, int $p_project_id ): ?int {
	global $g_dry;
	$t_name = substr( $p_name, 0, 128 );
	if( $p_project_id < 1 ) {
		return null;   # dry-run with not-yet-created project
	}
	$t_id = category_get_id_by_name( $t_name, $p_project_id, /* trigger_errors */ false );
	if( $t_id !== false ) {
		return (int)$t_id;
	}
	if( $g_dry ) {
		return null;
	}
	return category_add( $p_project_id, $t_name );
}

# ── Per-file pipeline ────────────────────────────────────────────────────────

$t_report   = array();
$t_created  = 0;
$t_skipped  = 0;
$t_failed   = 0;
$t_warnings = array();

foreach( $t_candidates as $t_path => $t_root ) {
	$t_project_id = $g_roots[$t_root];
	$t_basename   = basename( $t_path );
	try {
		# --update: skip paths already registered in any project sharing the repo.
		if( !$g_dry && $t_project_id > 0
		 && file_dwg_primary_path_in_use( 0, $t_project_id, $t_path ) !== 0 ) {
			$t_report[] = array( 'skip (registered)', $t_path, '', '' );
			$t_skipped++;
			continue;
		}

		# B2 — metadata.
		$t_meta = array(
			'title' => '', 'number' => '', 'edition' => '', 'revision' => '',
			'classification' => '', 'owner' => '', 'status_word' => '',
			'release_date' => null, 'due_date' => null,
		);

		if( $g_cfg['frontmatter'] && strtolower( pathinfo( $t_basename, PATHINFO_EXTENSION ) ) === 'md' ) {
			$t_fm = frontmatter_parse( (string)shell_exec(
				'git -C ' . escapeshellarg( $t_repo_dir ) .
				' show ' . escapeshellarg( 'HEAD:' . $t_path ) . ' 2>/dev/null'
			) );
			$t_meta['title']          = $t_fm['title'] ?? '';
			$t_meta['number']         = $t_fm['doc_id'] ?? '';
			$t_meta['revision']       = $t_fm['revision'] ?? '';
			$t_meta['classification'] = $t_fm['classification'] ?? '';
			$t_meta['owner']          = $t_fm['owner'] ?? '';
			$t_meta['status_word']    = $t_fm['status'] ?? '';
			if( !empty( $t_fm['effective_date'] ) && ( $t_ts = strtotime( $t_fm['effective_date'] ) ) !== false ) {
				$t_meta['release_date'] = $t_ts;
				if( !empty( $t_fm['review_period'] )
				 && preg_match( '/(\d+)\s*month/i', $t_fm['review_period'], $t_pm ) ) {
					$t_meta['due_date'] = strtotime( '+' . $t_pm[1] . ' months', $t_ts );
				}
			}
			if( $t_meta['status_word'] !== '' && status_map( $t_meta['status_word'] ) === array( 110, true )
			 && strtolower( $t_meta['status_word'] ) !== 'pending' ) {
				$t_warnings[] = "$t_path: unmapped frontmatter status '" . $t_meta['status_word'] . "' → pending";
			}
		}

		if( $g_cfg['filename_parse'] ) {
			$t_fp = filename_parse( $t_basename );
			foreach( array( 'number', 'edition', 'revision', 'title' ) as $t_key ) {
				if( $t_meta[$t_key] === '' && isset( $t_fp[$t_key] ) ) {
					$t_meta[$t_key] = $t_fp[$t_key];
				}
			}
			if( isset( $t_fp['draft'] ) && $t_meta['status_word'] === '' ) {
				$t_meta['status_word'] = 'draft';
			}
		}

		# Git history: dates and author.
		$t_first_ts = (int)trim( (string)shell_exec(
			'git -C ' . escapeshellarg( $t_repo_dir ) .
			' log --format=%at --reverse HEAD -- ' . escapeshellarg( $t_path ) . ' 2>/dev/null | head -1'
		) );
		$t_last = explode( "\x1f", trim( (string)shell_exec(
			'git -C ' . escapeshellarg( $t_repo_dir ) .
			' log -1 --format="%at%x1f%aN%x1f%aE" HEAD -- ' . escapeshellarg( $t_path ) . ' 2>/dev/null'
		) ) );
		$t_last_ts      = (int)( $t_last[0] ?? 0 );
		$t_git_author   = $t_last[1] ?? '';
		$t_git_email    = $t_last[2] ?? '';
		$t_creator_id   = $t_git_email !== '' ? (int)user_get_id_by_email( $t_git_email, false ) : 0;

		# Handler: only when the frontmatter owner matches a real account.
		$t_handler_id = 0;
		if( $t_meta['owner'] !== '' ) {
			$t_handler_id = (int)user_get_id_by_name( $t_meta['owner'], false );
			if( $t_handler_id === 0 ) {
				$t_warnings[] = "$t_path: owner '" . $t_meta['owner'] . "' matches no Doctis account";
			}
		}

		# Category from the directory path relative to the import root (D7).
		$t_category_name = 'General';
		if( $g_cfg['category_from'] === 'directory' ) {
			$t_rel_dir = dirname( $t_root === '' ? $t_path : substr( $t_path, strlen( $t_root ) + 1 ) );
			if( $t_rel_dir !== '.' && $t_rel_dir !== '' ) {
				$t_category_name = $t_rel_dir;
			}
		}

		list( $t_status_id, $t_pin ) = status_map( $t_meta['status_word'] );
		$t_title   = $t_meta['title'] !== '' ? $t_meta['title'] : pathinfo( $t_basename, PATHINFO_FILENAME );
		$t_summary = substr( $t_title, 0, 255 );

		if( $g_dry ) {
			$t_report[] = array(
				'would import', $t_path,
				( $t_meta['number'] !== '' ? $t_meta['number'] . ' ' : '' ) . $t_summary
					. ( $t_meta['revision'] !== '' ? ' [rev ' . $t_meta['revision'] . ']' : '' ),
				'status=' . $t_status_id . ( $t_pin ? ' pinned' : ' DRAFT' ) . ' cat=' . $t_category_name,
			);
			$t_created++;
			continue;
		}

		# B3 — create the document via the command layer (parity with UI/SOAP).
		category_ensure( $t_category_name, $t_project_id );
		$t_issue = array(
			'project'        => array( 'id' => $t_project_id ),
			'category'       => array( 'name' => $t_category_name ),
			'summary'        => $t_summary,
			'description'    => 'Imported from git repository \'' . basename( $t_source )
				. '\' (path: ' . $t_path . ')',
			'title'          => $t_title,
			'author'         => $t_meta['owner'] !== '' ? $t_meta['owner'] : $t_git_author,
			'number'         => $t_meta['number'],
			'edition'        => $t_meta['edition'],
			'revision'       => $t_meta['revision'],
			'classification' => $t_meta['classification'],
			'status'         => array( 'id' => $t_status_id ),
			'view_state'     => array( 'id' => VS_PUBLIC ),
			'revision_date'  => $t_last_ts > 0 ? $t_last_ts : null,
			'release_date'   => $t_meta['release_date'],
			'custom_fields'  => array(),
		);
		if( $t_meta['due_date'] !== null ) {
			$t_issue['due_date'] = $t_meta['due_date'];
		}
		if( $t_creator_id > 0 ) {
			$t_issue['creator'] = array( 'id' => $t_creator_id );
		}
		if( $t_handler_id > 0 ) {
			$t_issue['handler'] = array( 'id' => $t_handler_id );
		}

		$t_command = new DwgAddCommand( array( 'payload' => array( 'issue' => $t_issue ) ) );
		$t_result  = $t_command->execute();
		$t_dwg_id  = (int)$t_result['issue_id'];

		# Git history dates → submission/update timestamps (not settable via the command).
		if( $t_first_ts > 0 ) {
			db_param_push();
			db_query(
				'UPDATE {dwg} SET date_submitted=' . db_param() . ', last_updated=' . db_param()
					. ' WHERE id=' . db_param(),
				array( $t_first_ts, $t_last_ts > 0 ? $t_last_ts : $t_first_ts, $t_dwg_id )
			);
		}

		# B4 — register the file at HEAD by reference (no commit).
		file_dwg_primary_register(
			$t_dwg_id, $g_import_user_id, $t_path, /* sha: HEAD */ '',
			'Imported from ' . basename( $t_source ),
			$t_pin
		);

		$t_report[] = array(
			'imported (dwg ' . $t_dwg_id . ')', $t_path,
			( $t_meta['number'] !== '' ? $t_meta['number'] . ' ' : '' ) . $t_summary,
			'status=' . $t_status_id . ( $t_pin ? ' pinned' : ' DRAFT' ) . ' cat=' . $t_category_name,
		);
		$t_created++;
	} catch( Throwable $e ) {
		$t_report[] = array( 'FAILED', $t_path, get_class( $e ) . ': ' . $e->getMessage(), '' );
		$t_failed++;
	}
}

# --update: report registered paths that no longer exist at HEAD (never auto-delete).
if( $g_update && !$g_dry ) {
	db_param_push();
	$t_result = db_query(
		'SELECT f.dwg_id, f.git_path FROM {dwg_primary_file} f'
		. ' INNER JOIN {dwg} d ON d.id = f.dwg_id'
		. ' WHERE d.project_id IN (' . implode( ',', array_map( 'intval',
			repository_project_ids( repository_id_for_project( $t_parent_id ) ) ) ) . ')',
		array()
	);
	while( ( $t_row = db_fetch_array( $t_result ) ) !== false ) {
		if( !in_array( $t_row['git_path'], $t_all_files, true ) ) {
			$t_warnings[] = 'dwg ' . $t_row['dwg_id'] . ': registered path "'
				. $t_row['git_path'] . '" is missing at HEAD (renamed/deleted externally)';
		}
	}
}

# ── B5: report ───────────────────────────────────────────────────────────────

say( '' );
say( '── Report ───────────────────────────────────────────────────────────────' );
foreach( $t_report as $t_row ) {
	say( sprintf( '  %-22s %-55s %s', $t_row[0], $t_row[1],
		trim( $t_row[2] . ( $t_row[3] !== '' ? '  (' . $t_row[3] . ')' : '' ) ) ) );
}
foreach( $t_warnings as $t_warning ) {
	say( '  WARNING: ' . $t_warning );
}
say( '' );
say( sprintf( '%s: %d imported, %d skipped, %d failed, %d warning(s)',
	$g_dry ? 'DRY RUN' : 'Result', $t_created, $t_skipped, $t_failed, count( $t_warnings ) ) );

exit( $t_failed === 0 ? 0 : 1 );
