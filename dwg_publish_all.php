<?php
# dwg_publish_all.php
# POST handler — publish every eligible document of a project's website.
#
# Publishing is otherwise one document at a time, which is the wrong shape for
# a site being built from empty: somebody would press Publish once per
# document, and each press would rewrite the navigation in every page already
# there.  dwg_publish_all() renders them with that refresh deferred and
# rebuilds the navigation once.
#
# It runs in budgeted steps rather than one long request.  Rendering a site
# takes minutes and a web request does not get minutes -- nginx gives a
# FastCGI response 60 seconds by default -- so an unbounded pass returns 504
# while PHP carries on invisibly, losing the report and inviting a second
# pass over the same site.  Each step publishes for a few seconds, then this
# page submits itself again with the resume point and the running totals.
#
# Reached from Manage Project -> Publishing.  See doc/publish/PUBLISHING.md.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'dwg_publish_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );

# Seconds of publishing per request.  Comfortably inside a 60-second proxy
# timeout even with the final navigation refresh on top of it.
define( 'DWG_PUBLISH_ALL_BUDGET', 20.0 );

# A bound on the state carried between steps, so a site where everything
# fails cannot grow the form without limit.
define( 'DWG_PUBLISH_ALL_MAX_FAILED', 50 );

auth_ensure_user_authenticated();
form_security_validate( 'dwg_publish_all' );

$f_project_id = gpc_get_int( 'project_id' );
$f_after_id   = gpc_get_int( 'after_id', 0 );
$f_published  = gpc_get_int( 'published', 0 );
$f_skipped    = json_decode( gpc_get_string( 'skipped', '' ), true );
$f_failed     = json_decode( gpc_get_string( 'failed', '' ), true );
$t_skipped    = is_array( $f_skipped ) ? $f_skipped : array();
$t_failed     = is_array( $f_failed ) ? $f_failed : array();

project_ensure_exists( $f_project_id );

# Publishing the whole site is a project-wide act, so it is gated on the
# project rather than on any one document; the threshold is the same one.
access_ensure_project_level(
	config_get( 'dwg_publish_threshold', null, null, $f_project_id ), $f_project_id );

# Only the first step asks: the continuation steps are this page resubmitting
# itself, and confirming each one would be absurd.
if( $f_after_id === 0 ) {
	helper_ensure_confirmed(
		sprintf( lang_get( 'dwg_publish_all_confirm' ),
			string_display_line( project_get_name( $f_project_id ) ) ),
		lang_get( 'dwg_publish_all_button' )
	);
}

set_time_limit( 0 );

$t_step = dwg_publish_all( $f_project_id, auth_get_current_user_id(),
	$f_after_id, DWG_PUBLISH_ALL_BUDGET );

$t_published = $f_published + (int)$t_step['published'];
foreach( $t_step['skipped'] as $t_reason => $t_count ) {
	$t_skipped[$t_reason] = ( $t_skipped[$t_reason] ?? 0 ) + $t_count;
}
foreach( $t_step['failed'] as $t_id => $t_message ) {
	if( count( $t_failed ) < DWG_PUBLISH_ALL_MAX_FAILED ) {
		$t_failed[$t_id] = $t_message;
	}
}

form_security_purge( 'dwg_publish_all' );

$t_done  = (bool)$t_step['done'];
$t_title = lang_get( 'dwg_publish_all' );

layout_page_header( $t_title );
layout_page_begin( 'manage_overview_page.php' );
?>
<div class="col-md-10 col-md-offset-1 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( $t_done ? 'fa-upload' : 'fa-spinner', 'ace-icon' ); ?>
				<?php echo $t_title ?>
				&mdash; <?php echo string_display_line( project_get_name( $f_project_id ) ) ?>
			</h4>
		</div>
		<div class="widget-body"><div class="widget-main">

<?php if( !$t_done ): ?>
			<div class="alert alert-info">
				<?php echo sprintf( lang_get( 'dwg_publish_all_progress' ),
					(int)$t_published, (int)$t_step['remaining'] ) ?>
			</div>
			<form id="publish-all-continue" method="post" action="dwg_publish_all.php">
				<?php echo form_security_field( 'dwg_publish_all' ) ?>
				<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
				<input type="hidden" name="after_id" value="<?php echo (int)$t_step['after_id'] ?>" />
				<input type="hidden" name="published" value="<?php echo (int)$t_published ?>" />
				<input type="hidden" name="skipped" value="<?php echo string_attribute( json_encode( $t_skipped ) ) ?>" />
				<input type="hidden" name="failed" value="<?php echo string_attribute( json_encode( $t_failed ) ) ?>" />
				<button class="btn btn-sm btn-primary btn-white btn-round">
					<?php echo lang_get( 'dwg_publish_all_continue' ) ?>
				</button>
				<span class="small">&nbsp;<?php echo lang_get( 'dwg_publish_all_continue_help' ) ?></span>
			</form>
<?php else: ?>
			<div class="alert alert-<?php echo $t_failed ? 'warning' : 'success' ?>">
				<?php echo sprintf( lang_get( 'dwg_publish_all_result' ),
					(int)$t_published, count( $t_failed ) ) ?>
			</div>
<?php endif; ?>

<?php if( $t_skipped ): ?>
			<h5><?php echo lang_get( 'dwg_publish_all_skipped' ) ?></h5>
			<ul>
<?php	foreach( $t_skipped as $t_reason => $t_count ): ?>
				<li><strong><?php echo (int)$t_count ?></strong>
					&mdash; <?php echo string_display_line( $t_reason ) ?></li>
<?php	endforeach; ?>
			</ul>
<?php endif; ?>

<?php if( $t_failed ): ?>
			<h5><?php echo lang_get( 'dwg_publish_all_failed' ) ?></h5>
			<ul>
<?php	foreach( $t_failed as $t_id => $t_message ): ?>
				<li><a href="dwg_view.php?id=<?php echo (int)$t_id ?>"><?php echo (int)$t_id ?></a>
					&mdash; <?php echo string_display_line( $t_message ) ?></li>
<?php	endforeach; ?>
			</ul>
<?php endif; ?>

<?php if( $t_done ): ?>
			<div class="space-10"></div>
			<a href="manage_proj_edit_page.php?project_id=<?php echo (int)$f_project_id ?>#publishing"
				class="btn btn-sm btn-default btn-white btn-round">
				<?php echo lang_get( 'proceed' ) ?>
			</a>
<?php endif; ?>
		</div></div>
	</div>
</div>
<?php
if( !$t_done ) {
	# Carries on by itself; the button above is the fallback without scripting.
	html_javascript_link( 'dwg_publish_all.js' );
}
layout_page_end();
