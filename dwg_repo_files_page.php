<?php
# dwg_repo_files_page.php
# Files in a project's repository that are not yet Doctis documents.
#
# A developer who adds a file in an external clone and pushes it needs to
# see it from Doctis and turn it into a document.  Manage -> Git Store can
# do that, but it is an administrator's whole-server tool: it lists every
# repository on the server, shows server paths and worktrees, and changes
# push policies.  Opening it to developers would mean retrofitting access
# checks into every listing, view, action and download, where one miss
# would leak another project's files.  This page is the developer's view
# instead: one project's repository, its unregistered files, and Register.
#
# Access mirrors git itself.  Anyone who may clone the repository can
# already read every file in it, so seeing the list needs the same right
# (repository_user_can_clone()).  Registering creates a document, so it
# needs create_dwg_threshold on this project -- the create form checks it
# again.
#
# GET shows the list; POST action=register_frontmatter registers every file
# that needs no typing.  See doc/DOCTIS-GIT-USER-MANUAL.md section 8.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'git_store_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
if( $f_project_id === ALL_PROJECTS ) {
	# One repository per page; "All Projects" has none.
	print_header_redirect( 'login_select_proj_page.php?ref=dwg_repo_files_page.php' );
}
project_ensure_exists( $f_project_id );

if( !repository_user_can_clone( $f_project_id ) ) {
	access_denied();
}

$t_repo_id = repository_id_for_project( $f_project_id );
$t_repo    = git_store_repositories()[repository_basename( $t_repo_id )] ?? null;
if( $t_repo === null || !$t_repo['bare_exists'] ) {
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}

$t_can_register = access_has_project_level(
	config_get( 'create_dwg_threshold', null, null, $f_project_id ), $f_project_id );
$t_source_repo  = repository_push_policy( $t_repo_id ) === DWG_PUSH_POLICY_SOURCE;

if( $_SERVER['REQUEST_METHOD'] === 'POST' && gpc_get_string( 'action', '' ) === 'register_frontmatter' ) {
	form_security_validate( 'dwg_repo_files_register' );
	access_ensure_project_level( config_get( 'create_dwg_threshold', null, null, $f_project_id ), $f_project_id );
	set_time_limit( 0 );
	$t_bulk = git_store_register_frontmatter_all( $t_repo, $f_project_id, auth_get_current_user_id() );
	form_security_purge( 'dwg_repo_files_register' );
	print_header_redirect( 'dwg_repo_files_page.php?project_id=' . $f_project_id
		. '&registered=' . (int)$t_bulk['registered']
		. ( $t_bulk['failed'] ? '&failed=' . rawurlencode( implode( '; ', array_keys( $t_bulk['failed'] ) ) ) : '' ) );
}

$f_show_covered = gpc_get_bool( 'covered', false );

$t_tree         = git_store_tree( $t_repo );
$t_docs         = git_store_documents( $t_repo, $t_tree );
$t_unregistered = array_values( array_diff( array_keys( $t_tree ), array_keys( $t_docs ) ) );
$t_covered      = git_store_covered_paths( $t_repo, $t_tree, $t_unregistered );
$t_gaps         = array_values( array_diff( $t_unregistered, array_keys( $t_covered ) ) );
$t_ready        = git_store_frontmatter_ready( $t_repo, $t_gaps );
$t_last         = git_store_last_changes( $t_repo );
$t_shown        = $f_show_covered ? $t_unregistered : $t_gaps;

$f_registered = gpc_get_int( 'registered', -1 );
$f_failed     = trim( gpc_get_string( 'failed', '' ) );

layout_page_header( lang_get( 'dwg_repo_files_title' ) );
layout_page_begin();
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-code-fork', 'ace-icon' ); ?>
				<?php echo lang_get( 'dwg_repo_files_title' ) ?>
				&mdash; <?php echo string_display_line( project_get_name( $f_project_id ) ) ?>
			</h4>
		</div>
		<div class="widget-body"><div class="widget-main">

<?php if( $f_registered >= 0 ): ?>
			<div class="alert alert-<?php echo $f_failed !== '' ? 'warning' : 'success' ?>">
				<?php echo sprintf( lang_get( 'dwg_repo_files_registered' ), $f_registered ) ?>
<?php	if( $f_failed !== '' ): ?>
				<br /><?php echo lang_get( 'dwg_repo_files_failed' ) ?>
				<code><?php echo string_display_line( $f_failed ) ?></code>
<?php	endif; ?>
			</div>
<?php endif; ?>

			<p>
				<?php echo sprintf( lang_get( 'dwg_repo_files_intro' ),
					'<code>' . string_display_line( $t_repo['key'] ) . '</code>' ) ?>
				<span class="label label-<?php echo $t_source_repo ? 'primary' : 'default' ?>"><?php
					echo lang_get( $t_source_repo ? 'push_policy_source' : 'push_policy_document' ) ?></span>
			</p>
			<p>
				<span class="label label-default"><?php echo sprintf( lang_get( 'dwg_repo_files_gap_count' ), count( $t_gaps ) ) ?></span>
<?php if( $t_covered ): ?>
				<a href="dwg_repo_files_page.php?project_id=<?php echo (int)$f_project_id ?>&amp;covered=<?php echo $f_show_covered ? 0 : 1 ?>">
					<span class="label label-info"><?php echo sprintf( lang_get( 'dwg_repo_files_covered_count' ), count( $t_covered ) ) ?></span></a>
				<span class="small grey">&nbsp;<?php echo lang_get( $f_show_covered
					? 'dwg_repo_files_covered_hide' : 'dwg_repo_files_covered_show' ) ?></span>
<?php endif; ?>
			</p>

<?php if( $t_source_repo && $t_can_register && $t_shown ): ?>
			<?php print_dwg_source_repository_notice( 'register' ); ?>
<?php endif; ?>

<?php if( !$t_shown ): ?>
			<p class="grey"><?php echo lang_get( 'dwg_repo_files_none' ) ?></p>
<?php else: ?>
			<div class="table-responsive">
			<table class="table table-condensed table-striped table-hover">
				<thead><tr>
					<th><?php echo lang_get( 'dwg_repo_files_path' ) ?></th>
					<th class="nowrap"><?php echo lang_get( 'dwg_repo_files_size' ) ?></th>
					<th><?php echo lang_get( 'dwg_repo_files_last_change' ) ?></th>
					<th class="nowrap"><?php echo lang_get( 'dwg_repo_files_metadata' ) ?></th>
					<th></th>
				</tr></thead>
				<tbody>
<?php	foreach( $t_shown as $t_path ):
			$t_commit = $t_last[$t_path] ?? null; ?>
				<tr>
					<td><code><?php echo string_display_line( $t_path ) ?></code></td>
					<td class="nowrap"><?php echo git_store_format_size( $t_tree[$t_path]['size'] ) ?></td>
					<td class="small"><?php if( $t_commit ) {
						echo string_display_line( date( config_get( 'short_date_format' ), (int)$t_commit['time'] ) )
							. ' &middot; ' . string_display_line( $t_commit['author'] )
							. ' &middot; <code>' . htmlspecialchars( substr( $t_commit['sha'], 0, 8 ) ) . '</code>';
					} ?></td>
					<td class="nowrap"><?php
						if( isset( $t_covered[$t_path] ) ) {
							echo '<span class="label label-info" title="' . string_attribute( lang_get( 'dwg_covered_by_help' ) ) . '">'
								. sprintf( lang_get( 'dwg_covered_by' ), '#' . (int)$t_covered[$t_path]['dwg_id'] ) . '</span> ';
						}
						if( isset( $t_ready[$t_path] ) ) {
							echo '<span class="label label-success">' . lang_get( 'dwg_repo_files_frontmatter' ) . '</span>';
						}
					?></td>
					<td class="nowrap"><?php if( $t_can_register ) { ?>
						<a class="btn btn-xs btn-primary btn-white btn-round" href="<?php
							echo htmlspecialchars( 'dwg_create_page.php?project_id=' . (int)$f_project_id
								. '&git_path=' . rawurlencode( $t_path ) ) ?>"><?php
							echo lang_get( 'dwg_register_button' ) ?></a>
					<?php } ?></td>
				</tr>
<?php	endforeach; ?>
				</tbody>
			</table>
			</div>
<?php endif; ?>

<?php if( $t_can_register && $t_ready ): ?>
			<form method="post" action="dwg_repo_files_page.php">
				<?php echo form_security_field( 'dwg_repo_files_register' ) ?>
				<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
				<input type="hidden" name="action" value="register_frontmatter" />
				<button class="btn btn-sm btn-primary btn-white btn-round"><?php
					echo sprintf( lang_get( 'dwg_register_all_button' ), count( $t_ready ) ) ?></button>
				<span class="small">&nbsp;<?php echo lang_get( 'dwg_register_all_help' ) ?></span>
			</form>
<?php endif; ?>

		</div></div>
	</div>
</div>
<?php
layout_page_end();
