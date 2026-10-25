<?php
/**
 * Doctis git mapping-layer integration test (C1 model).
 *
 * Bootstraps the full MantisBT/Doctis core and exercises the repository
 * entity, project→repository resolution, path-as-data storage, the
 * register-by-reference primitive, collision enforcement, dangling-path
 * detection, repository adoption, and rename relocation.
 *
 * Complements admin/test-git-php.php (pure git mechanics, no core).
 * Creates its own projects/documents/repositories and tears everything
 * down.  Run on the server (the native development VM) as www-data:
 *
 *   sudo -u www-data php /var/www/html/doctis/admin/test-git-doctis.php
 */

$t_mantis_dir = dirname( __DIR__ ) . '/';
require_once( $t_mantis_dir . 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'dwg_api.php' );
require_api( 'dwg_publish_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'project_hierarchy_api.php' );
require_api( 'repository_api.php' );

use Mantis\Exceptions\ClientException;

auth_attempt_script_login( 'administrator' );

$g_pass = 0;
$g_fail = 0;

function ok( string $p_label ): void {
	global $g_pass;
	$g_pass++;
	echo "  $p_label ... OK\n";
}

function fail( string $p_label, string $p_detail = '' ): void {
	global $g_fail;
	$g_fail++;
	echo "  $p_label ... FAILED" . ( $p_detail !== '' ? ": $p_detail" : '' ) . "\n";
}

function check( bool $p_cond, string $p_label, string $p_detail = '' ): void {
	$p_cond ? ok( $p_label ) : fail( $p_label, $p_detail );
}

function bare_git( string $p_bare, string $p_args ): string {
	return trim( (string)shell_exec(
		'git --git-dir=' . escapeshellarg( $p_bare ) . ' ' . $p_args . ' 2>/dev/null'
	) );
}

/** Insert a minimal {documents} + {dwg} pair and return the dwg id. */
function make_dwg( int $p_project_id, int $p_category_id, string $p_summary ): int {
	db_param_push();
	db_query( 'INSERT INTO {documents} ( title ) VALUES ( ' . db_param() . ' )', array( $p_summary ) );
	$t_document_id = db_insert_id( db_get_table( 'documents' ) );
	db_param_push();
	db_query(
		'INSERT INTO {dwg_text} ( description, steps_to_reproduce, additional_information )'
		. ' VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( '', '', '' )
	);
	$t_text_id = db_insert_id( db_get_table( 'dwg_text' ) );
	db_param_push();
	db_query(
		'INSERT INTO {dwg} ( project_id, creator_id, category_id, document_id, dwg_text_id, summary )
		 VALUES ( ' . db_param() . ', 1, ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_project_id, $p_category_id, $t_document_id, $t_text_id, $p_summary )
	);
	return db_insert_id( db_get_table( 'dwg' ) );
}

function write_tmp( string $p_content ): string {
	$t_tmp = tempnam( sys_get_temp_dir(), 'dwgtest' );
	file_put_contents( $t_tmp, $p_content );
	return $t_tmp;
}

# State recorded for teardown.
$t_cleanup = array(
	'projects'     => array(),
	'dwg_ids'      => array(),
	'repo_ids'     => array(),
	'disk'         => array(),
	'tmp'          => array(),
	'category_ids' => array(),
);

$t_storage_root  = config_get_global( 'git_storage_root' );
$t_worktree_root = config_get_global( 'git_worktree_root' );
if( is_blank( $t_storage_root ) || !is_dir( $t_storage_root ) ) {
	echo "SKIP: git_storage_root is not configured/present.\n";
	exit( 1 );
}

echo "── Doctis git mapping-layer test ────────────────────────────────────────\n";

try {

# ── Step 1: path sanitizer ──────────────────────────────────────────────────
echo "Step 1: path sanitizer\n";
check( file_dwg_git_path_sanitize( 'a/b/c.md' ) === 'a/b/c.md', 'valid path accepted' );
check( file_dwg_git_path_sanitize( '/lead/slash.md' ) === 'lead/slash.md', 'leading slash stripped' );
check( file_dwg_git_path_sanitize( './dot/rel.md' ) === 'dot/rel.md', 'leading ./ stripped' );
check( file_dwg_git_path_sanitize( 'a//b.md' ) === 'a/b.md', 'duplicate slashes collapsed' );
check( file_dwg_git_path_sanitize( 'a/../b.md' ) === false, 'traversal rejected' );
check( file_dwg_git_path_sanitize( '' ) === false, 'empty rejected' );
check( file_dwg_git_path_sanitize( 'dir/' ) === false, 'trailing slash rejected' );
check( file_dwg_git_path_sanitize( "bad\x01.md" ) === false, 'control chars rejected' );
check( file_dwg_git_path_sanitize( 'back\\slash.md' ) === 'back/slash.md', 'backslash normalised' );

# ── Step 2: projects and resolution ─────────────────────────────────────────
echo "Step 2: repository entity and project resolution\n";
$t_parent = project_create( 'GitTest Parent ' . getmypid(), 'test', 10 );
$t_child  = project_create( 'GitTest Child ' . getmypid(), 'test', 10 );
$t_cleanup['projects'][] = $t_parent;
$t_cleanup['projects'][] = $t_child;
project_hierarchy_add( $t_child, $t_parent );

check( repository_id_for_project( $t_parent ) === 0, 'no repository before first need' );

# Creation resolves at the top of the tree even when triggered from the child.
$t_repo_id = repository_id_for_project_or_create( $t_child );
$t_cleanup['repo_ids'][] = $t_repo_id;
check( $t_repo_id > 0, 'repository created on demand' );
$t_repo_row = repository_get_row( $t_repo_id );
check( $t_repo_row !== false && (int)$t_repo_row['owner_project_id'] === $t_parent,
	'repository owned by top-level project', 'owner=' . ( $t_repo_row['owner_project_id'] ?? '?' ) );
check( preg_match( '/^[a-z0-9\-]+-r' . $t_repo_id . '$/', repository_basename( $t_repo_id ) ) === 1,
	'basename is <slug>-r<id>', repository_basename( $t_repo_id ) );
check( repository_id_for_project( $t_parent ) === $t_repo_id, 'parent resolves to repository' );
check( repository_id_for_project( $t_child ) === $t_repo_id, 'child inherits repository' );

# ── Step 3: on-disk materialisation ─────────────────────────────────────────
echo "Step 3: on-disk materialisation\n";
$t_paths = repository_ensure_on_disk( $t_repo_id );
$t_bare  = $t_paths['bare'];
$t_cleanup['disk'][] = $t_paths['bare'];
$t_cleanup['disk'][] = $t_paths['worktree'];
check( is_dir( $t_bare ), 'bare repository exists', $t_bare );
check( is_dir( $t_paths['worktree'] ), 'server worktree exists' );
check( is_file( $t_bare . '/hooks/pre-receive' ), 'pre-receive hook installed' );
check( bare_git( $t_bare, 'config http.receivepack' ) === 'true', 'http.receivepack enabled' );

# Sub-project with its own explicit repository wins over inheritance.
$t_own_repo = repository_create( 'GitTest Own ' . getmypid(), $t_child );
$t_cleanup['repo_ids'][] = $t_own_repo;
check( repository_id_for_project( $t_child ) === $t_own_repo, 'explicit link overrides inheritance' );
db_param_push();
db_query( 'DELETE FROM {project_repository} WHERE project_id=' . db_param(), array( $t_child ) );
check( repository_id_for_project( $t_child ) === $t_repo_id, 'unlinking falls back to inheritance' );

# ── Step 4: upload → register (default template) ────────────────────────────
echo "Step 4: primary upload with default {dwg_id}/{filename} template\n";
$t_dwg1 = make_dwg( $t_parent, 1, 'GitTest doc 1' );
$t_dwg2 = make_dwg( $t_child, 1, 'GitTest doc 2 (child project)' );
$t_cleanup['dwg_ids'][] = $t_dwg1;
$t_cleanup['dwg_ids'][] = $t_dwg2;

# Regression: dwg_move() must use the document attachment API. Calling the
# issue attachment API raises ERROR_BUG_NOT_FOUND when no issue shares the ID.
dwg_move( $t_dwg2, $t_parent );
check( (int)dwg_get_field( $t_dwg2, 'project_id' ) === $t_parent,
	'document moves between projects without issue lookup' );

$t_tmp = write_tmp( "content v1\n" );
file_dwg_primary_add( $t_dwg1, 1, $t_tmp, 'spec.md', 11, 'text/markdown', 'initial' );
$t_row = file_dwg_primary_get( $t_dwg1 );
check( $t_row !== null, 'primary row created' );
check( $t_row['git_path'] === $t_dwg1 . '/spec.md', 'git_path from default template', $t_row['git_path'] );
check( preg_match( '/^[0-9a-f]{40}$/', $t_row['git_sha'] ) === 1, 'git_sha recorded' );
check( bare_git( $t_bare, 'cat-file -e HEAD:' . escapeshellarg( $t_row['git_path'] ) . '; echo $?' ) === '0'
	|| bare_git( $t_bare, 'ls-tree --name-only HEAD ' . escapeshellarg( $t_row['git_path'] ) ) === $t_row['git_path'],
	'file present at HEAD' );
check( bare_git( $t_bare, 'for-each-ref refs/doctis/approved/' . $t_dwg1 ) !== '', 'approved ref pinned' );

$t_content = file_dwg_primary_get_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "content v1\n", 'retrieve round-trip' );
$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && !$t_head['has_draft'], 'initial registration has no Draft' );

# ── Step 5: replace with SAME filename (regression: file must stay at HEAD) ─
echo "Step 5: replace with same filename\n";
$t_tmp = write_tmp( "content v2\n" );
file_dwg_primary_add( $t_dwg1, 1, $t_tmp, 'spec.md', 11, 'text/markdown', 'revised' );
$t_row = file_dwg_primary_get( $t_dwg1 );
$t_draft = file_dwg_primary_draft_get( $t_dwg1 );
check( bare_git( $t_bare, 'ls-tree --name-only HEAD ' . escapeshellarg( $t_row['git_path'] ) ) === $t_row['git_path'],
	'file still present at HEAD after same-name replace' );
$t_content = file_dwg_primary_get_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "content v1\n", 'On Record content remains original' );
$t_content = file_dwg_primary_get_head_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "content v2\n", 'Draft serves new content' );
check( $t_draft !== null && $t_draft['filename'] === 'spec.md' && $t_draft['git_sha'] !== $t_row['git_sha'],
	'Draft metadata differs from On Record' );
$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && $t_head['has_draft'] && $t_head['sha'] === $t_draft['git_sha'],
	'panel revision is staged commit, not repository HEAD' );
db_param_push();
$t_revision_count = (int)db_result( db_query(
	'SELECT COUNT(*) FROM {dwg_history} WHERE dwg_id=' . db_param() . ' AND field_name=\'primary_document_draft\'',
	array( $t_dwg1 )
) );
check( $t_revision_count === 1, 'same-name replacement appears as Draft in document history' );

# ── Step 6: replace with NEW filename (sticky directory) ────────────────────
echo "Step 6: replace with new filename\n";
$t_tmp = write_tmp( "content v3\n" );
file_dwg_primary_add( $t_dwg1, 1, $t_tmp, 'spec-rev-b.md', 11, 'text/markdown', 'renamed' );
$t_row = file_dwg_primary_get( $t_dwg1 );
$t_draft = file_dwg_primary_draft_get( $t_dwg1 );
check( $t_row['git_path'] === $t_dwg1 . '/spec.md' && $t_row['filename'] === 'spec.md',
	'On Record keeps original path and filename' );
check( $t_draft['git_path'] === $t_dwg1 . '/spec-rev-b.md', 'Draft adopts new basename', $t_draft['git_path'] );
check( bare_git( $t_bare, 'ls-tree --name-only HEAD ' . escapeshellarg( $t_dwg1 . '/spec.md' ) ) === '',
	'old path removed from HEAD' );
check( bare_git( $t_bare, 'ls-tree --name-only HEAD ' . escapeshellarg( $t_draft['git_path'] ) ) === $t_draft['git_path'],
	'new path present at HEAD' );

check( $t_draft['git_sha'] === bare_git( $t_bare, 'rev-parse HEAD' ),
	'renamed replacement records final HEAD commit' );
$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && $t_head['has_draft'] && !$t_head['stale'] && $t_head['filename'] === 'spec-rev-b.md',
	'renamed Draft is document-specific and current' );
$t_content = file_dwg_primary_get_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "content v1\n", 'On Record still serves original content' );
$t_content = file_dwg_primary_get_head_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "content v3\n", 'renamed Draft serves new content' );
check( file_dwg_primary_get_head_content( $t_dwg1, str_repeat( '0', 40 ) ) === false,
	'Draft download rejects an outdated requested SHA' );
db_param_push();
$t_revision_count = (int)db_result( db_query(
	'SELECT COUNT(*) FROM {dwg_history} WHERE dwg_id=' . db_param() . ' AND field_name=\'primary_document_draft\'',
	array( $t_dwg1 )
) );
check( $t_revision_count === 2, 'renamed replacement appears as Draft in document history' );

# ── Step 7: register-by-reference ────────────────────────────────────────────
echo "Step 7: register-by-reference (no upload)\n";
# Commit a file into the shared repo outside the Doctis upload path.
$t_worktree = $t_paths['worktree'];
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' pull --quiet origin 2>&1' );
mkdir( $t_worktree . '/manual', 0775, true );
file_put_contents( $t_worktree . '/manual/adopted.md', "manually committed\n" );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' add manual/adopted.md 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' -c user.name=tester -c user.email=t@t commit -q -m "manual" 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' push -q origin HEAD 2>&1' );

$t_reg = file_dwg_primary_register( $t_dwg2, 1, 'manual/adopted.md', '', '', false );
check( preg_match( '/^[0-9a-f]{40}$/', $t_reg['git_sha'] ) === 1, 'HEAD sha registered' );
check( $t_reg['filename'] === 'adopted.md', 'filename derived from path' );
$t_row2 = file_dwg_primary_get( $t_dwg2 );
check( $t_row2 !== null && $t_row2['git_path'] === 'manual/adopted.md', 'row registered with native path' );
$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && $t_head['sha'] === $t_draft['git_sha'] && !$t_head['stale'],
	'unrelated Git commit does not change staged Draft' );
check( (int)$t_row2['filesize'] === strlen( "manually committed\n" ), 'filesize from git object', (string)$t_row2['filesize'] );
$t_content = file_dwg_primary_get_content( $t_dwg2 );
check( $t_content !== false && $t_content['content'] === "manually committed\n", 'registered content retrievable' );
db_param_push();
$t_reference = db_result( db_query(
	'SELECT d.reference FROM {documents} d INNER JOIN {dwg} w ON w.document_id=d.id'
		. ' WHERE w.id=' . db_param(), array( $t_dwg2 )
) );
check( $t_reference === $t_reg['git_sha'], 'registration records SHA without approval' );
check( bare_git( $t_bare, 'for-each-ref refs/doctis/approved/' . $t_dwg2 ) === '',
	'unapproved registration has no approved ref' );
file_dwg_primary_register( $t_dwg2, 1, 'manual/adopted.md' );
check( bare_git( $t_bare, 'for-each-ref refs/doctis/approved/' . $t_dwg2 ) !== '', 'registration pinned approved ref' );

try {
	file_dwg_primary_register( $t_dwg2, 1, 'does/not/exist.md' );
	fail( 'nonexistent path rejected' );
} catch( ClientException $e ) {
	ok( 'nonexistent path rejected' );
}

# ── Step 8: collision enforcement across projects sharing the repo ──────────
echo "Step 8: per-repository path collision\n";
try {
	file_dwg_primary_register( $t_dwg1, 1, 'manual/adopted.md' );
	fail( 'collision rejected (register)' );
} catch( ClientException $e ) {
	ok( 'collision rejected (register)' );
}
# dwg1's row must be intact after the rejected registration attempt.
$t_row = file_dwg_primary_get( $t_dwg1 );
check( $t_row !== null && $t_row['git_path'] === $t_dwg1 . '/spec.md', 'holder row untouched by rejected attempt' );

# ── Step 9: head info and dangling-path detection ────────────────────────────
echo "Step 9: HEAD info and dangling path\n";
$t_head = file_dwg_git_head_info( $t_dwg2 );
check( $t_head !== null && $t_head['filename'] === 'adopted.md', 'head_info sees registered path' );

# Externally remove dwg2's file (simulates a rename/delete pushed from outside).
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' rm -q manual/adopted.md 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' -c user.name=tester -c user.email=t@t commit -q -m "external rm" 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' push -q origin HEAD 2>&1' );
$t_head = file_dwg_git_head_info( $t_dwg2 );
check( $t_head !== null && $t_head['filename'] === null, 'dangling path detected (filename null)' );
check( $t_head !== null && $t_head['missing'] && !$t_head['has_draft'],
	'deleted path is not a promotable Draft' );
# The pinned on-record version must remain retrievable.
$t_content = file_dwg_primary_get_content( $t_dwg2 );
check( $t_content !== false && $t_content['content'] === "manually committed\n",
	'on-record version still retrievable after external delete' );

# ── Step 10: staged and external Draft promotion ────────────────────────────
echo "Step 10: document-specific Draft promotion\n";
# An external edit after an upload makes that staged Draft stale.
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' pull -q origin 2>&1' );
file_put_contents( $t_worktree . '/' . $t_dwg1 . '/spec-rev-b.md', "draft v4\n" );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' add ' . escapeshellarg( $t_dwg1 . '/spec-rev-b.md' ) . ' 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' -c user.name=tester -c user.email=t@t commit -q -m "draft" 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' push -q origin HEAD 2>&1' );

$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && $t_head['has_draft'] && $t_head['stale']
	&& $t_head['sha'] === $t_draft['git_sha'], 'later Git edit does not silently replace staged Draft' );
try {
	file_dwg_primary_sync_head( $t_dwg1, 1 );
	fail( 'stale Draft promotion rejected' );
} catch( ClientException $e ) {
	ok( 'stale Draft promotion rejected' );
}
$t_content = file_dwg_primary_get_head_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "content v3\n", 'staged Draft still downloads exact uploaded content' );

$t_tmp = write_tmp( "draft v5\n" );
file_dwg_primary_add( $t_dwg1, 1, $t_tmp, 'spec-rev-b.md', 9, 'text/markdown', 'restaged' );
$t_staged = file_dwg_primary_draft_get( $t_dwg1 );
$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && $t_head['has_draft'] && !$t_head['stale']
	&& $t_head['sha'] === $t_staged['git_sha'], 're-upload refreshes staged Draft' );
try {
	file_dwg_primary_sync_head( $t_dwg1, 1, str_repeat( '0', 40 ) );
	fail( 'outdated approval SHA rejected' );
} catch( ClientException $e ) {
	ok( 'outdated approval SHA rejected' );
}

$t_before = file_dwg_primary_get( $t_dwg1 );
file_dwg_primary_sync_head( $t_dwg1, 1, $t_staged['git_sha'] );
$t_after = file_dwg_primary_get( $t_dwg1 );
check( $t_after['git_sha'] === $t_staged['git_sha'] && $t_after['git_sha'] !== $t_before['git_sha'],
	'promotion records exact staged SHA' );
check( $t_after['git_path'] === $t_dwg1 . '/spec-rev-b.md' && $t_after['filename'] === 'spec-rev-b.md',
	'promotion adopts Draft path and filename' );
check( file_dwg_primary_draft_get( $t_dwg1 ) === null, 'Draft cleared after promotion' );
$t_content = file_dwg_primary_get_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "draft v5\n", 'promoted staged content served' );

# A direct Git edit at the registered path also creates a Draft for this
# document; promote the last commit affecting that path.
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' pull -q origin 2>&1' );
file_put_contents( $t_worktree . '/' . $t_dwg1 . '/spec-rev-b.md', "external v6\n" );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' add ' . escapeshellarg( $t_dwg1 . '/spec-rev-b.md' ) . ' 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' -c user.name=tester -c user.email=t@t commit -q -m "external edit" 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_worktree ) . ' push -q origin HEAD 2>&1' );
$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && $t_head['has_draft'] && !$t_head['stale']
	&& $t_head['sha'] === bare_git( $t_bare, 'rev-parse HEAD' ), 'direct Git edit detected for this path' );
file_dwg_primary_sync_head( $t_dwg1, 1, $t_head['sha'] );
$t_content = file_dwg_primary_get_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "external v6\n", 'promoted direct Git edit served' );

# ── Step 11: category path template ──────────────────────────────────────────
echo "Step 11: {category}/{filename} template\n";
$t_category_id = category_add( $t_parent, 'guidance' );
$t_cleanup['category_ids'][] = $t_category_id;
config_set( 'dwg_repo_path_template', '{category}/{filename}', ALL_USERS, $t_parent );
$t_dwg3 = make_dwg( $t_parent, $t_category_id, 'GitTest doc 3' );
$t_cleanup['dwg_ids'][] = $t_dwg3;
$t_tmp = write_tmp( "categorised\n" );
file_dwg_primary_add( $t_dwg3, 1, $t_tmp, 'policy.md', 12, 'text/markdown', '' );
$t_row3 = file_dwg_primary_get( $t_dwg3 );
check( $t_row3 !== null && $t_row3['git_path'] === 'guidance/policy.md', 'template path applied', $t_row3['git_path'] ?? 'null' );
$t_head = file_dwg_git_head_info( $t_dwg1 );
check( $t_head !== null && !$t_head['has_draft'] && $t_head['sha'] === file_dwg_primary_get( $t_dwg1 )['git_sha'],
	'unrelated document commit does not create Draft' );
try {
	file_dwg_primary_sync_head( $t_dwg1, 1 );
	fail( 'unchanged document promotion rejected' );
} catch( ClientException $e ) {
	ok( 'unchanged document promotion rejected' );
}

# A URL is metadata on the document, not a replacement for its Git reference.
check( dwg_link_url_is_valid( 'https://intranet.example/documents/3' ), 'HTTP document URL accepted' );
check( !dwg_link_url_is_valid( 'javascript:alert(1)' ), 'unsafe document URL rejected' );
$t_link_dwg = dwg_get( $t_dwg3, true );
$t_link_dwg->link_url = 'https://intranet.example/documents/3';
$t_link_dwg->update( false, true );
$t_link_dwg = dwg_get( $t_dwg3 );
db_param_push();
$t_doc_link = db_result( db_query( 'SELECT link_url FROM {documents} WHERE id=' . db_param(), array( $t_link_dwg->document_id ) ) );
check( $t_link_dwg->link_url === $t_doc_link && $t_doc_link === 'https://intranet.example/documents/3',
	'document URL saved in both metadata rows' );
$t_link_dwg->link_url = '';
$t_link_dwg->update( false, true );
db_param_push();
$t_doc_link = db_result( db_query( 'SELECT link_url FROM {documents} WHERE id=' . db_param(), array( $t_link_dwg->document_id ) ) );
check( dwg_get( $t_dwg3 )->link_url === '' && $t_doc_link === '', 'document URL can be cleared' );

# Same template, same category, same filename → collision at upload time.
$t_dwg4 = make_dwg( $t_parent, $t_category_id, 'GitTest doc 4' );
$t_cleanup['dwg_ids'][] = $t_dwg4;
$t_tmp = write_tmp( "collide\n" );
try {
	file_dwg_primary_add( $t_dwg4, 1, $t_tmp, 'policy.md', 8, 'text/markdown', '' );
	fail( 'upload collision rejected' );
} catch( ClientException $e ) {
	ok( 'upload collision rejected' );
	@unlink( $t_tmp );
}
config_delete( 'dwg_repo_path_template', ALL_USERS, $t_parent );

# ── Step 12: repository adoption ─────────────────────────────────────────────
echo "Step 12: repository adoption\n";
$t_src = sys_get_temp_dir() . '/gittest-src-' . getmypid();
$t_cleanup['tmp'][] = $t_src;
exec( 'git init -q -b main ' . escapeshellarg( $t_src ) . ' 2>&1' );
file_put_contents( $t_src . '/README.md', "adopted repo\n" );
mkdir( $t_src . '/docs' );
file_put_contents( $t_src . '/docs/spec.md', "adopted spec\n" );
exec( 'git -C ' . escapeshellarg( $t_src ) . ' add -A 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_src ) . ' -c user.name=src -c user.email=s@s commit -q -m "first" 2>&1' );
file_put_contents( $t_src . '/docs/spec.md', "adopted spec v2\n" );
exec( 'git -C ' . escapeshellarg( $t_src ) . ' -c user.name=src -c user.email=s@s commit -q -am "second" 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_src ) . ' remote add origin https://example.invalid/old.git 2>&1' );

$t_adopt_project = project_create( 'GitTest Adopt ' . getmypid(), 'test', 10 );
$t_cleanup['projects'][] = $t_adopt_project;
$t_adopt_repo = repository_create( 'GitTest Adopt ' . getmypid(), $t_adopt_project, $t_src );
$t_cleanup['repo_ids'][] = $t_adopt_repo;
$t_adopt_paths = repository_adopt( $t_adopt_repo, $t_src );
$t_cleanup['disk'][] = $t_adopt_paths['bare'];
$t_cleanup['disk'][] = $t_adopt_paths['worktree'];

check( is_dir( $t_adopt_paths['bare'] ), 'adopted bare repo exists' );
check( bare_git( $t_adopt_paths['bare'], 'rev-list --count HEAD' ) === '2', 'full history adopted' );
check( bare_git( $t_adopt_paths['bare'], 'remote' ) === '', 'inherited remotes stripped' );
check( is_file( $t_adopt_paths['bare'] . '/hooks/pre-receive' ), 'hook installed on adopted repo' );
$t_adopt_row = repository_get_row( $t_adopt_repo );
check( $t_adopt_row['default_branch'] === 'main', 'default branch recorded' );

# Register a native-path file from the adopted history.
$t_dwg5 = make_dwg( $t_adopt_project, 1, 'GitTest adopted doc' );
$t_cleanup['dwg_ids'][] = $t_dwg5;
$t_reg5 = file_dwg_primary_register( $t_dwg5, 1, 'docs/spec.md' );
$t_content = file_dwg_primary_get_content( $t_dwg5 );
check( $t_content !== false && $t_content['content'] === "adopted spec v2\n", 'adopted content registered and retrievable' );

# Import-like case: two documents share one registration commit. Editing one
# file must not create a Draft for the other.
$t_dwg6 = make_dwg( $t_adopt_project, 1, 'GitTest adopted README' );
$t_cleanup['dwg_ids'][] = $t_dwg6;
$t_reg6 = file_dwg_primary_register( $t_dwg6, 1, 'README.md', $t_reg5['git_sha'], '', false );
check( $t_reg5['git_sha'] === $t_reg6['git_sha'], 'two documents share import commit' );
$t_adopt_worktree = $t_adopt_paths['worktree'];
file_put_contents( $t_adopt_worktree . '/docs/spec.md', "adopted spec v3\n" );
exec( 'git -C ' . escapeshellarg( $t_adopt_worktree ) . ' add docs/spec.md 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_adopt_worktree ) . ' -c user.name=src -c user.email=s@s commit -q -m "edit one imported document" 2>&1' );
exec( 'git -C ' . escapeshellarg( $t_adopt_worktree ) . ' push -q origin HEAD 2>&1' );
$t_changed = file_dwg_git_head_info( $t_dwg5 );
$t_unchanged = file_dwg_git_head_info( $t_dwg6 );
check( $t_changed !== null && $t_changed['has_draft'], 'edited imported document has Draft' );
check( $t_unchanged !== null && !$t_unchanged['has_draft']
	&& $t_unchanged['sha'] === $t_reg6['git_sha'], 'untouched document with same import SHA has no Draft' );

# ── Step 12b: move to a project with another repository ─────────────────────
echo "Step 12b: document move carries the primary file\n";
$t_dwg7 = make_dwg( $t_parent, 1, 'GitTest moved document' );
$t_cleanup['dwg_ids'][] = $t_dwg7;
$t_tmp = write_tmp( "moved content\n" );
file_dwg_primary_add( $t_dwg7, 1, $t_tmp, 'moved.md', 14, 'text/markdown', 'before move' );
$t_before = file_dwg_primary_get( $t_dwg7 );
dwg_move( $t_dwg7, $t_adopt_project );
$t_after = file_dwg_primary_get( $t_dwg7 );
$t_adopt_bare = $t_adopt_paths['bare'];
check( (int)dwg_get_field( $t_dwg7, 'project_id' ) === $t_adopt_project, 'document moved to the other project' );
check( $t_after['git_path'] === $t_before['git_path'], 'path kept when free in the target', $t_after['git_path'] );
check( $t_after['git_sha'] !== $t_before['git_sha']
	&& bare_git( $t_adopt_bare, 'cat-file -t ' . escapeshellarg( $t_after['git_sha'] ) ) === 'commit',
	'On Record commit is in the target repository' );
check( bare_git( $t_adopt_bare, 'cat-file -e ' . escapeshellarg( 'HEAD:' . $t_after['git_path'] ) . ' 2>/dev/null; echo $?' ) === '0',
	'file present at target HEAD' );
check( bare_git( $t_bare, 'cat-file -e ' . escapeshellarg( 'HEAD:' . $t_before['git_path'] ) . ' 2>/dev/null; echo $?' ) !== '0',
	'file removed from source HEAD' );
check( bare_git( $t_bare, 'cat-file -e ' . escapeshellarg( $t_before['git_sha'] . ':' . $t_before['git_path'] ) . ' 2>/dev/null; echo $?' ) === '0',
	'source history keeps the file' );
$t_content = file_dwg_primary_get_content( $t_dwg7 );
check( $t_content !== false && $t_content['content'] === "moved content\n", 'moved file retrievable' );
check( dwg_get_field( $t_dwg7, 'reference' ) === $t_after['git_sha'], 'Reference follows the new commit' );

# A document with a Draft is refused and stays where it is.
try {
	dwg_move( $t_dwg5, $t_parent );
	fail( 'document with a Draft refused' );
} catch( Mantis\Exceptions\ClientException $e ) {
	check( (int)dwg_get_field( $t_dwg5, 'project_id' ) === $t_adopt_project, 'document with a Draft refused and not moved' );
}

# ── Step 13: rename relocation ───────────────────────────────────────────────
echo "Step 13: rename relocation\n";
$t_old_bare = repository_bare_path( $t_repo_id );
db_param_push();
db_query( 'UPDATE {project} SET name=' . db_param() . ' WHERE id=' . db_param(),
	array( 'GitTest Renamed ' . getmypid(), $t_parent ) );
project_clear_cache( $t_parent );
repository_rename_for_owner_project( $t_parent );
$t_new_bare = repository_bare_path( $t_repo_id );
$t_cleanup['disk'][] = $t_new_bare;
$t_cleanup['disk'][] = repository_worktree_path( $t_repo_id );
check( $t_new_bare !== $t_old_bare, 'bare path changed', $t_new_bare );
check( is_dir( $t_new_bare ) && !is_dir( $t_old_bare ), 'bare repo relocated on disk' );
check( strpos( basename( $t_new_bare ), 'gittest-renamed-' ) === 0, 'slug follows new name', basename( $t_new_bare ) );
# Content still retrievable through the relocated repo (worktree is lazily re-cloned).
$t_content = file_dwg_primary_get_content( $t_dwg1 );
check( $t_content !== false && $t_content['content'] === "external v6\n", 'retrieve works after relocation' );

# ── Step 14: source release records ─────────────────────────────────────────
# A document whose git_path names a TREE records a release of a repository
# rather than a single file.  See doc/git/SOURCE_REPOSITORIES.md.
echo "Step 14: source release records\n";
check( file_dwg_git_path_sanitize( '', true ) === '', 'repository root accepted with allow_root' );
check( file_dwg_git_path_sanitize( '', false ) === false, 'repository root still refused by default' );
check( file_dwg_git_path_sanitize( 'a/../b.md', true ) === false, 'allow_root does not relax traversal' );
check( file_dwg_git_tree_ish( str_repeat( 'a', 40 ), '' ) === str_repeat( 'a', 40 ) . ':',
	'tree-ish for the repository root is "<sha>:"' );

$t_bare14 = repository_bare_path( $t_repo_id );
$t_head14 = bare_git( $t_bare14, 'rev-parse HEAD' );
check( file_dwg_git_object_type( $t_bare14, $t_head14, '' ) === 'tree', 'root resolves to a tree' );
check( file_dwg_git_object_type( $t_bare14, $t_head14, 'no/such/path' ) === '', 'absent path has no type' );

$t_dwg_src = make_dwg( $t_parent, 1, 'GitTest source release' );
$t_cleanup['dwg_ids'][] = $t_dwg_src;

# A tree is refused unless the caller asks for a source release.
try {
	file_dwg_primary_register( $t_dwg_src, 1, '', $t_head14 );
	fail( 'tree refused without allow_tree' );
} catch( ClientException $e ) {
	ok( 'tree refused without allow_tree' );
}

$t_rel = file_dwg_primary_register( $t_dwg_src, 1, '', $t_head14, 'release 1', true, '',
	array( 'allow_tree' => true ) );
$t_src_row = file_dwg_primary_get( $t_dwg_src );
check( $t_src_row !== null && $t_src_row['git_path'] === '', 'registered at the repository root' );
check( file_dwg_primary_is_tree( $t_src_row ), 'row marked as a tree' );
check( (int)$t_src_row['filesize'] === 0, 'filesize is 0, not the tree object size',
	(string)$t_src_row['filesize'] );
check( $t_src_row['git_sha'] === $t_head14, 'on record at the release commit' );
check( bare_git( $t_bare14, 'for-each-ref refs/doctis/approved/' . $t_dwg_src ) !== '',
	'approved ref pinned for the release' );

# Download is an archive of the tree, not `git show` of a directory listing.
check( file_dwg_get_storage_backend()->retrieve( $t_src_row, $t_parent ) === false,
	'backend refuses to retrieve a tree as content' );
$t_zip = (int)trim( (string)shell_exec( 'git --git-dir=' . escapeshellarg( $t_bare14 )
	. ' archive --format=zip ' . escapeshellarg( $t_head14 . ':' ) . ' 2>/dev/null | wc -c' ) );
check( $t_zip > 100, 'git archive produces a zip of the tree', $t_zip . ' bytes' );

# An upload cannot overwrite a release: its content arrives by push.
try {
	$t_tmp14 = write_tmp( "stray\n" );
	file_dwg_primary_add( $t_dwg_src, 1, $t_tmp14, 'stray.md', 6, 'text/markdown', '' );
	fail( 'upload onto a source release refused' );
} catch( ClientException $e ) {
	ok( 'upload onto a source release refused' );
}

# A commit to the repository raises a Draft; promoting it cuts the next release.
$t_paths14 = repository_ensure_on_disk( $t_repo_id );
$t_wt14 = $t_paths14['worktree'];
dwg_git_worktree_sync( $t_wt14 );
file_put_contents( $t_wt14 . '/RELEASE-NOTES.txt', "r2\n" );
exec( 'cd ' . escapeshellarg( $t_wt14 ) . ' && git add -A && git -c user.name=T -c user.email=t@e.invalid'
	. ' commit -q -m "next release" && git push -q origin HEAD 2>&1' );
$t_head14b = bare_git( $t_bare14, 'rev-parse HEAD' );
check( $t_head14b !== $t_head14, 'repository advanced', substr( $t_head14b, 0, 8 ) );

$t_info14 = file_dwg_git_head_info( $t_dwg_src );
check( $t_info14 !== null && $t_info14['has_draft'], 'push raises a Draft on the release record' );
check( $t_info14 !== null && $t_info14['sha'] === $t_head14b, 'Draft is the new commit' );
check( file_dwg_primary_get( $t_dwg_src )['git_sha'] === $t_head14,
	'On Record stays pinned until promoted' );

file_dwg_primary_sync_head( $t_dwg_src, 1, $t_head14b );
$t_src_row2 = file_dwg_primary_get( $t_dwg_src );
check( $t_src_row2['git_sha'] === $t_head14b, 'promotion cuts the next release' );
check( file_dwg_primary_is_tree( $t_src_row2 ), 'promoted row is still a tree' );
check( $t_src_row2['git_path'] === '', 'promoted row is still rooted at the repository' );
check( count( explode( "\n", bare_git( $t_bare14, 'for-each-ref refs/doctis/approved/' . $t_dwg_src ) ) ) === 2,
	'both releases are pinned' );

# A release is not a page, and is refused by publishing for that stated reason.
check( strpos( dwg_publish_refusal( $t_dwg_src ), 'source release' ) !== false,
	'publishing refuses a source release by name', dwg_publish_refusal( $t_dwg_src ) );

# It is also excluded from the move path, which is a file operation.
check( file_dwg_primary_move_prepare( $t_dwg_src, $t_child, 1 ) === null,
	'source release excluded from the move path' );

# ── Step 15: per-repository push policy ─────────────────────────────────────
echo "Step 15: per-repository push policy\n";
check( is_file( $t_bare14 . '/hooks/post-receive' ), 'post-receive hook installed' );
check( repository_get_push_policy( $t_bare14 ) === DWG_PUSH_POLICY_DOCUMENT,
	'repositories default to the document policy' );
repository_set_push_policy( $t_bare14, DWG_PUSH_POLICY_SOURCE );
check( repository_get_push_policy( $t_bare14 ) === DWG_PUSH_POLICY_SOURCE, 'policy set to source' );
repository_configure_bare( $t_bare14 );
check( repository_get_push_policy( $t_bare14 ) === DWG_PUSH_POLICY_SOURCE,
	'refreshing the configuration does not re-impose document control' );
try {
	repository_set_push_policy( $t_bare14, 'nonsense' );
	fail( 'unknown policy refused' );
} catch( ClientException $e ) {
	ok( 'unknown policy refused' );
}
repository_set_push_policy( $t_bare14, DWG_PUSH_POLICY_DOCUMENT );

# The hook must enforce whichever policy is in force, for a real push.
$t_hookwt = sys_get_temp_dir() . '/doctis-hooktest-' . getmypid();
$t_cleanup['tmp'][] = $t_hookwt;
exec( 'git clone -q ' . escapeshellarg( $t_bare14 ) . ' ' . escapeshellarg( $t_hookwt ) . ' 2>&1' );
$t_force = 'cd ' . escapeshellarg( $t_hookwt ) . ' && git push --force origin HEAD~1:refs/heads/policytest 2>&1';
exec( 'cd ' . escapeshellarg( $t_hookwt ) . ' && git push -q origin HEAD:refs/heads/policytest 2>&1' );
exec( $t_force, $t_out15, $t_rc15 );
check( $t_rc15 !== 0 && strpos( implode( ' ', $t_out15 ), 'non-fast-forward' ) !== false,
	'document policy rejects a force-push', implode( ' ', $t_out15 ) );

repository_set_push_policy( $t_bare14, DWG_PUSH_POLICY_SOURCE );
unset( $t_out15 );
exec( $t_force, $t_out15, $t_rc15 );
check( $t_rc15 === 0, 'source policy permits a force-push', implode( ' ', (array)$t_out15 ) );
unset( $t_out15 );
exec( 'cd ' . escapeshellarg( $t_hookwt ) . ' && git push origin --delete policytest 2>&1', $t_out15, $t_rc15 );
check( $t_rc15 === 0, 'source policy permits a branch deletion', implode( ' ', (array)$t_out15 ) );
unset( $t_out15 );
exec( 'cd ' . escapeshellarg( $t_hookwt ) . ' && git push origin HEAD:refs/doctis/approved/1/1 2>&1', $t_out15, $t_rc15 );
check( $t_rc15 !== 0 && strpos( implode( ' ', $t_out15 ), 'server-managed' ) !== false,
	'refs/doctis/* stay unpushable under the source policy', implode( ' ', (array)$t_out15 ) );

# The approved pin is what makes that safe: the released commit survives a
# force-push past it, and a prune.
exec( 'cd ' . escapeshellarg( $t_hookwt ) . ' && git push -q --force origin '
	. escapeshellarg( $t_head14 ) . ':' . bare_git( $t_bare14, 'symbolic-ref HEAD' ) . ' 2>&1' );
exec( 'git --git-dir=' . escapeshellarg( $t_bare14 ) . ' gc --prune=now --quiet 2>&1' );
check( bare_git( $t_bare14, 'cat-file -t ' . escapeshellarg( $t_head14b ) ) === 'commit',
	'an approved release survives a force-push past it, and a prune' );

} catch( Throwable $e ) {
	fail( 'unexpected exception', get_class( $e ) . ': ' . $e->getMessage()
		. ' @ ' . $e->getFile() . ':' . $e->getLine() );
}

# ── Teardown ─────────────────────────────────────────────────────────────────
echo "── Teardown ─────────────────────────────────────────────────────────────\n";
foreach( $t_cleanup['dwg_ids'] as $t_id ) {
	db_param_push();
	$t_text_id = db_result( db_query( 'SELECT dwg_text_id FROM {dwg} WHERE id=' . db_param(), array( $t_id ) ) );
	db_param_push();
	db_query( 'DELETE FROM {dwg_history} WHERE dwg_id=' . db_param(), array( $t_id ) );
	db_param_push();
	db_query( 'DELETE d, doc FROM {dwg} d LEFT JOIN {documents} doc ON doc.id=d.document_id WHERE d.id=' . db_param(), array( $t_id ) );
	db_param_push();
	db_query( 'DELETE FROM {dwg_primary_file} WHERE dwg_id=' . db_param(), array( $t_id ) );
	db_param_push();
	db_query( 'DELETE FROM {dwg_primary_draft} WHERE dwg_id=' . db_param(), array( $t_id ) );
	if( $t_text_id > 0 ) {
		db_param_push();
		db_query( 'DELETE FROM {dwg_text} WHERE id=' . db_param(), array( $t_text_id ) );
	}
}
foreach( $t_cleanup['repo_ids'] as $t_id ) {
	db_param_push();
	db_query( 'DELETE FROM {repository} WHERE id=' . db_param(), array( $t_id ) );
	db_param_push();
	db_query( 'DELETE FROM {project_repository} WHERE repository_id=' . db_param(), array( $t_id ) );
}
foreach( $t_cleanup['projects'] as $t_id ) {
	project_delete( $t_id );
}
foreach( array_unique( $t_cleanup['disk'] ) as $t_path ) {
	if( $t_path !== '' && is_dir( $t_path )
	 && ( strpos( $t_path, $t_storage_root ) === 0 || strpos( $t_path, (string)$t_worktree_root ) === 0 ) ) {
		exec( 'rm -rf ' . escapeshellarg( $t_path ) );
	}
}
foreach( $t_cleanup['tmp'] as $t_path ) {
	if( is_dir( $t_path ) && strpos( $t_path, sys_get_temp_dir() ) === 0 ) {
		exec( 'rm -rf ' . escapeshellarg( $t_path ) );
	}
}
echo "Removed test projects, documents, repositories, and disk state.\n";

echo "\n── Result: {$g_pass} passed, {$g_fail} failed ──────────────────────────\n";
exit( $g_fail === 0 ? 0 : 1 );
