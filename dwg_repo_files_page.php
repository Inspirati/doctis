<?php
# Project-scoped repository browser. Reading needs clone access; registration
# separately needs create_dwg_threshold and always uses the default branch.
require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'print_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_browser_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();
$f_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
if( $f_project_id === ALL_PROJECTS ) {
	print_header_redirect( 'login_select_proj_page.php?ref=dwg_repo_files_page.php' );
}
project_ensure_exists( $f_project_id );
if( !repository_user_can_clone( $f_project_id ) ) {
	access_denied();
}
$t_repo_id = repository_id_for_project( $f_project_id );
$t_repo = repository_browser_repo( $t_repo_id );
$t_can_register = access_has_project_level(
	config_get( 'create_dwg_threshold', null, null, $f_project_id ), $f_project_id );
$t_source_repo = repository_push_policy( $t_repo_id ) === DWG_PUSH_POLICY_SOURCE;

if( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
	form_security_validate( 'dwg_repo_files_register' );
	access_ensure_project_level( config_get( 'create_dwg_threshold', null, null, $f_project_id ), $f_project_id );
	if( gpc_get_string( 'action', '' ) !== 'register_frontmatter' ) {
		access_denied();
	}
	set_time_limit( 0 );
	$t_bulk = git_store_register_frontmatter_all( $t_repo, $f_project_id, auth_get_current_user_id() );
	form_security_purge( 'dwg_repo_files_register' );
	print_header_redirect( 'dwg_repo_files_page.php?project_id=' . $f_project_id
		. '&registered=' . (int)$t_bulk['registered']
		. ( $t_bulk['failed'] ? '&failed=' . rawurlencode( implode( '; ', array_keys( $t_bulk['failed'] ) ) ) : '' ) );
}

$f_view = gpc_get_string( 'view', gpc_isset( 'covered' ) ? 'gaps' : 'files' );
if( !in_array( $f_view, array( 'files', 'gaps', 'history' ), true ) ) {
	$f_view = 'files';
}
$f_directory = gpc_get_string( 'dir', '' );
$f_path = gpc_get_string( 'path', '' );
$f_revision = gpc_get_string( 'rev', 'HEAD' );
$f_skip = max( 0, gpc_get_int( 'skip', 0 ) );
if( strpos( $f_directory . $f_path, "\0" ) !== false ) {
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}
$t_head = repository_browser_commit( $t_repo );
$t_commit = $f_revision === 'HEAD' ? $t_head : repository_browser_commit( $t_repo, $f_revision );
if( $f_revision !== 'HEAD' && $t_commit === '' ) {
	trigger_error( ERROR_FILE_NOT_FOUND, ERROR );
}
$t_tree = $t_commit === '' ? array() : git_store_tree( $t_repo, $t_commit );
$t_docs = git_store_documents( $t_repo, $t_tree );
$t_unregistered = array_values( array_diff( array_keys( $t_tree ), array_keys( $t_docs ) ) );
$t_covered = git_store_covered_paths( $t_repo, $t_tree, $t_unregistered );
$t_gaps = array_values( array_diff( $t_unregistered, array_keys( $t_covered ) ) );
$t_historical = $f_revision !== 'HEAD';
$t_ready = !$t_historical && $t_can_register && $f_view === 'gaps'
	? git_store_frontmatter_ready( $t_repo, $t_gaps ) : array();
$t_shown_tree = $f_view === 'gaps'
	? array_intersect_key( $t_tree, array_flip( gpc_get_bool( 'covered', false ) ? $t_unregistered : $t_gaps ) )
	: $t_tree;
$t_entries = repository_browser_entries( $t_shown_tree, $f_directory );
$t_history = $f_view === 'history' ? repository_browser_history( $t_repo, $t_commit, $f_path, $f_skip ) : array();
$t_more = count( $t_history ) > 50;
$t_history = array_slice( $t_history, 0, 50 );

# Keep every navigation target in the authorised project, with URL-encoded paths.
$t_url = static function( array $p_params = array() ) use ( $f_project_id, $f_revision ) {
	return string_attribute( 'dwg_repo_files_page.php?' . http_build_query(
		array_merge( array( 'project_id' => $f_project_id, 'rev' => $f_revision ), $p_params ), '', '&', PHP_QUERY_RFC3986 ) );
};
$t_text = static function( $p_text ) {
	return string_html_specialchars( (string)$p_text );
};
$f_registered = gpc_get_int( 'registered', -1 );
$f_failed = gpc_get_string( 'failed', '' );
layout_page_header( lang_get( 'dwg_repo_files_title' ) );
layout_page_begin();
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-code-fork', 'ace-icon' ); ?>
				<?php echo lang_get( 'dwg_repo_files_title' ), ' &mdash; ', $t_text( project_get_name( $f_project_id ) ) ?></h4>
		</div>
		<div class="widget-body"><div class="widget-main">
			<?php print_dwg_repository_navigation( $f_project_id ); ?>
			<p><?php echo sprintf( lang_get( 'dwg_repo_browser_intro' ), '<code>' . $t_text( $t_repo['key'] ) . '</code>' ) ?>
				<span class="label label-default"><?php echo lang_get( $t_source_repo ? 'push_policy_source' : 'push_policy_document' ) ?></span>
			</p>
<?php if( $f_registered >= 0 ): ?>
			<div class="alert alert-<?php echo $f_failed !== '' ? 'warning' : 'success' ?>">
				<?php echo sprintf( lang_get( 'dwg_repo_files_registered' ), $f_registered ); ?>
<?php if( $f_failed !== '' ) { echo '<br />', lang_get( 'dwg_repo_files_failed' ), ' <code>', $t_text( $f_failed ), '</code>'; } ?>
			</div>
<?php endif; ?>
			<ul class="nav nav-tabs">
<?php foreach( array( 'files' => 'dwg_repo_browser_all', 'gaps' => 'dwg_repo_browser_gaps', 'history' => 'dwg_repo_browser_history' ) as $t_view => $t_label ): ?>
				<li<?php echo $f_view === $t_view ? ' class="active"' : '' ?>><a href="<?php echo $t_url( array( 'view' => $t_view ) ) ?>"><?php echo lang_get( $t_label ) ?></a></li>
<?php endforeach; ?>
			</ul>
			<div class="space-10"></div>
<?php if( $t_historical ): ?>
			<div class="alert alert-info"><?php echo sprintf( lang_get( 'dwg_repo_browser_revision' ), '<code>' . $t_text( $t_commit ) . '</code>' ) ?>
				<a href="<?php echo $t_url( array( 'rev' => 'HEAD' ) ) ?>"><?php echo lang_get( 'dwg_repo_browser_head' ) ?></a></div>
<?php endif; ?>
<?php if( $f_view === 'history' ): ?>
			<p><?php echo lang_get( 'dwg_repo_browser_history_help' ) ?> <code><?php echo $t_text( $f_path ) ?></code></p>
			<div class="table-responsive"><table class="table table-striped table-condensed">
				<thead><tr><th><?php echo lang_get( 'dwg_repo_browser_commit' ) ?></th><th><?php echo lang_get( 'date' ) ?></th>
					<th><?php echo lang_get( 'dwg_repo_browser_author' ) ?></th><th><?php echo lang_get( 'summary' ) ?></th></tr></thead><tbody>
<?php foreach( $t_history as $t_change ): ?>
				<tr><td><a href="<?php echo $t_url( array( 'rev' => $t_change['sha'], 'dir' => $f_directory ) ) ?>"><code><?php echo $t_text( substr( $t_change['sha'], 0, 12 ) ) ?></code></a></td>
					<td class="nowrap"><?php echo $t_text( date( config_get( 'normal_date_format' ), $t_change['time'] ) ) ?></td>
					<td><?php echo $t_text( $t_change['author'] ) ?></td><td><?php echo $t_text( $t_change['subject'] ) ?></td></tr>
<?php endforeach; ?>
			</tbody></table></div>
<?php if( !$t_history ): ?><p class="grey"><?php echo lang_get( 'dwg_repo_browser_empty' ) ?></p><?php endif; ?>
<?php foreach( array( -50 => 'prev', 50 => 'next' ) as $t_delta => $t_label ):
	if( $t_delta < 0 ? $f_skip === 0 : !$t_more ) { continue; } ?>
			<a class="btn btn-sm btn-default" href="<?php echo $t_url( array( 'view' => 'history', 'rev' => $t_commit,
				'path' => $f_path, 'dir' => $f_directory, 'skip' => max( 0, $f_skip + $t_delta ) ) ) ?>"><?php echo lang_get( $t_label ) ?></a>
<?php endforeach; ?>
<?php else: ?>
			<p><a href="<?php echo $t_url( array( 'view' => $f_view ) ) ?>"><?php echo lang_get( 'dwg_repo_browser_root' ) ?></a>
<?php
$t_parent = '';
foreach( $f_directory === '' ? array() : explode( '/', $f_directory ) as $t_component ) {
	$t_parent .= ( $t_parent === '' ? '' : '/' ) . $t_component;
	echo ' / <a href="', $t_url( array( 'view' => $f_view, 'dir' => $t_parent ) ), '">', $t_text( $t_component ), '</a>';
}
?>
				&middot; <a href="<?php echo $t_url( array( 'view' => 'history', 'path' => $f_directory, 'dir' => $f_directory ) ) ?>"><?php echo lang_get( 'dwg_repo_browser_history' ) ?></a></p>
<?php if( $f_view === 'gaps' ): ?>
			<p><?php echo sprintf( lang_get( 'dwg_repo_files_gap_count' ), count( $t_gaps ) ) ?>
<?php if( $t_covered ): ?>
				&middot; <a href="<?php echo $t_url( array( 'view' => 'gaps', 'dir' => $f_directory, 'covered' => !gpc_get_bool( 'covered', false ) ) ) ?>"><?php echo sprintf( lang_get( 'dwg_repo_files_covered_count' ), count( $t_covered ) ), ' &mdash; ',
					lang_get( gpc_get_bool( 'covered', false ) ? 'dwg_repo_files_covered_hide' : 'dwg_repo_files_covered_show' ) ?></a>
<?php endif; ?>
			</p>
<?php endif; ?>
<?php if( $t_source_repo && $t_can_register && !$t_historical ): print_dwg_source_repository_notice( 'register' ); endif; ?>
<?php if( !$t_entries ): ?><p class="grey"><?php echo lang_get( 'dwg_repo_browser_empty' ) ?></p><?php else: ?>
			<div class="table-responsive"><table class="table table-condensed table-striped table-hover">
				<thead><tr><th><?php echo lang_get( 'dwg_repo_files_path' ) ?></th><th><?php echo lang_get( 'dwg_repo_files_size' ) ?></th>
					<th><?php echo lang_get( 'dwg_repo_files_last_change' ) ?></th><th><?php echo lang_get( 'dwg_repo_files_metadata' ) ?></th><th></th></tr></thead><tbody>
<?php foreach( $t_entries as $t_path => $t_entry ):
	$t_folder = $t_entry['type'] === 'tree';
	$t_change = repository_browser_history( $t_repo, $t_commit, $t_path, 0, 1 )[0] ?? null;
	$t_doc = $t_docs[$t_path] ?? array( 'record' => null, 'draft' => null );
	$t_download = 'dwg_repo_file_download.php?project_id=' . $f_project_id . '&rev=' . $t_commit . '&path=' . rawurlencode( $t_path ); ?>
				<tr><td>
<?php if( $t_folder || $t_entry['type'] === 'blob' ): ?>
					<a href="<?php echo $t_folder ? $t_url( array( 'view' => $f_view, 'dir' => $t_path,
						'covered' => gpc_get_bool( 'covered', false ) ) ) : string_attribute( $t_download ) ?>"><code><?php echo $t_text( basename( $t_path ) ), $t_folder ? '/' : '' ?></code></a>
<?php else: ?>
					<code><?php echo $t_text( basename( $t_path ) ) ?></code> (<?php echo lang_get( 'dwg_repo_browser_submodule' ) ?>)
<?php endif; ?>
				</td><td class="nowrap"><?php echo $t_folder ? '' : git_store_format_size( $t_entry['size'] ) ?></td>
				<td class="small"><?php if( $t_change ) { echo $t_text( date( config_get( 'short_date_format' ), $t_change['time'] ) ),
					' &middot; ', $t_text( $t_change['author'] ), ' &middot; <code>', $t_text( substr( $t_change['sha'], 0, 8 ) ), '</code>'; } ?></td>
				<td><?php foreach( array( 'record', 'draft' ) as $t_kind ) {
					if( $t_doc[$t_kind] ) { echo '<a href="dwg_view.php?id=', (int)$t_doc[$t_kind]['dwg_id'], '">#',
						(int)$t_doc[$t_kind]['dwg_id'], ' ', lang_get( 'dwg_repo_browser_' . $t_kind ), '</a> '; }
				}
				if( isset( $t_covered[$t_path] ) ) { echo '<a href="dwg_view.php?id=', (int)$t_covered[$t_path]['dwg_id'], '">',
					sprintf( lang_get( 'dwg_covered_by' ), '#' . (int)$t_covered[$t_path]['dwg_id'] ), '</a> '; }
				if( isset( $t_ready[$t_path] ) ) { echo '<span class="label label-success">', lang_get( 'dwg_repo_files_frontmatter' ), '</span>'; }
				?></td>
				<td class="nowrap"><a class="btn btn-xs btn-default" href="<?php echo $t_url( array( 'view' => 'history', 'path' => $t_path, 'dir' => $f_directory ) ) ?>"><?php echo lang_get( 'dwg_repo_browser_history' ) ?></a>
<?php if( !$t_historical && $t_can_register && $t_entry['type'] === 'blob' && !$t_doc['record'] && !$t_doc['draft'] ): ?>
					<a class="btn btn-xs btn-primary btn-white" href="<?php echo string_attribute( 'dwg_create_page.php?project_id=' . $f_project_id
						. '&git_path=' . rawurlencode( $t_path ) ) ?>"><?php echo lang_get( 'dwg_register_button' ) ?></a>
<?php endif; ?>
				</td></tr>
<?php endforeach; ?>
			</tbody></table></div>
<?php endif; ?>
<?php if( $t_ready ): ?>
			<form method="post" action="dwg_repo_files_page.php">
				<?php echo form_security_field( 'dwg_repo_files_register' ) ?>
				<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
				<input type="hidden" name="action" value="register_frontmatter" />
				<button class="btn btn-sm btn-primary btn-white btn-round"><?php echo sprintf( lang_get( 'dwg_register_all_button' ), count( $t_ready ) ) ?></button>
				<span class="small"><?php echo lang_get( 'dwg_register_all_help' ) ?></span>
			</form>
<?php endif; ?>
<?php if( $t_docs ): ?>
			<details><summary><?php echo lang_get( 'dwg_repo_browser_records' ) ?></summary>
				<p class="small"><?php echo lang_get( 'dwg_repo_browser_records_help' ) ?></p><ul>
<?php foreach( $t_docs as $t_path => $t_doc ):
	foreach( array( 'record', 'draft' ) as $t_kind ):
		if( !$t_doc[$t_kind] ) { continue; } ?>
				<li><a href="dwg_view.php?id=<?php echo (int)$t_doc[$t_kind]['dwg_id'] ?>">#<?php echo (int)$t_doc[$t_kind]['dwg_id'], ' ', $t_text( $t_doc[$t_kind]['title'] ?? $t_doc[$t_kind]['summary'] ) ?></a>
					&mdash; <?php echo lang_get( 'dwg_repo_browser_' . $t_kind ) ?> &mdash; <code><?php echo $t_text( $t_path ) ?></code></li>
<?php endforeach; endforeach; ?>
				</ul></details>
<?php endif; ?>
<?php endif; ?>
		</div></div>
	</div>
</div>
<?php
layout_page_end();
