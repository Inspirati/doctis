<?php
# Doctis — A project's Documentation Topics activity log ({topic_event}),
# newest first, 100 per page.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_expectation_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
$f_page = max( 1, gpc_get_int( 'page', 1 ) );
project_ensure_exists( $f_project_id );
if( !topic_can_view_project( $f_project_id ) ) {
	access_denied();
}
$t_per_page = 100;
$t_total = topic_event_count( $f_project_id );
$t_pages = max( 1, (int)ceil( $t_total / $t_per_page ) );
$t_events = topic_event_get( $f_project_id, $t_per_page, ( $f_page - 1 ) * $t_per_page );
$t_date_format = config_get( 'normal_date_format' );

layout_page_header( lang_get( 'topic_activity' ) . ' — ' . project_get_name( $f_project_id ) );
layout_page_begin( 'topic_page.php' );
?>
<div class="col-md-12 col-xs-12">
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-history', 'ace-icon' ); ?>
			<?php echo string_display_line( lang_get( 'topic_activity' ) . ' — ' . project_get_name( $f_project_id ) ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p class="small grey"><?php echo lang_get( 'topic_activity_intro' ) ?></p>
	</div>
	<div class="widget-main no-padding">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th><?php echo lang_get( 'date_modified' ) ?></th>
			<th><?php echo lang_get( 'username' ) ?></th>
			<th><?php echo lang_get( 'topic_action' ) ?></th>
			<th><?php echo lang_get( 'topic_detail' ) ?></th>
		</tr></thead>
		<tbody>
		<?php if( !$t_events ) { ?>
			<tr><td colspan="4"><?php echo lang_get( 'topic_no_activity' ) ?></td></tr>
		<?php }
		foreach( $t_events as $t_event ) { ?>
			<tr>
				<td class="nowrap"><?php echo date( $t_date_format, (int)$t_event['date_event'] ) ?></td>
				<td><?php echo string_display_line( topic_user_name( (int)$t_event['user_id'] ) ) ?></td>
				<td class="nowrap"><?php echo string_display_line( topic_event_label( $t_event['action'] ) ) ?></td>
				<td><?php echo string_display_line( $t_event['detail'] ) ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	<?php if( $t_pages > 1 ) { ?>
	<div class="widget-main">
		<?php for( $i = 1; $i <= $t_pages; $i++ ) {
			echo $i == $f_page ? '<strong>' . $i . '</strong> ' : '<a href="topic_activity_page.php?project_id=' . $f_project_id . '&amp;page=' . $i . '">' . $i . '</a> ';
		} ?>
	</div>
	<?php } ?>
	</div>
	</div>
</div>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<?php print_link_button( 'topic_page.php?project_id=' . (int)$f_project_id, lang_get( 'topic_back_to_topics' ) ); ?>
</div>
<?php
layout_page_end();
