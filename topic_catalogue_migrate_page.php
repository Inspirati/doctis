<?php
# Doctis — Preview moving a project's Documentation Topics to another
# revision of its catalogue (TOP-FR-007), and confirm it.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id' );
$f_catalogue_id = gpc_get_int( 'catalogue_id' );
project_ensure_exists( $f_project_id );
if( !topic_can_manage_project( $f_project_id ) ) {
	access_denied();
}
$t_preview = topic_catalogue_migration_preview( $f_project_id, $f_catalogue_id );
$t_labels = array(
	'same' => 'topic_change_same', 'changed' => 'topic_change_changed', 'added' => 'topic_change_added',
	'retired' => 'topic_change_retired', 'removed' => 'topic_change_removed',
);

layout_page_header( lang_get( 'topic_catalogue_migration' ) . ' — ' . project_get_name( $f_project_id ) );
layout_page_begin( 'topic_page.php' );
?>
<div class="col-md-12 col-xs-12">
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-exchange', 'ace-icon' ); ?>
			<?php echo string_display_line( lang_get( 'topic_catalogue_migration' ) . ': ' . topic_catalogue_label( $t_preview['current'] )
				. ' → ' . topic_catalogue_label( $t_preview['target'] ) . ' (' . topic_catalogue_status_label( (int)$t_preview['target']['status'] ) . ')' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p><?php echo lang_get( 'topic_migration_intro' ) ?></p>
	</div>
	<div class="widget-main no-padding">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th><?php echo lang_get( 'topic_code' ) ?></th>
			<th><?php echo lang_get( 'topic_change' ) ?></th>
			<th><?php echo string_display_line( topic_catalogue_label( $t_preview['current'] ) ) ?></th>
			<th><?php echo string_display_line( topic_catalogue_label( $t_preview['target'] ) ) ?></th>
			<th><?php echo lang_get( 'topic_project_records' ) ?></th>
		</tr></thead>
		<tbody>
		<?php foreach( $t_preview['rows'] as $t_code => $t_row ) {
			$t_blocked = in_array( (string)$t_code, $t_preview['blockers'], true ); ?>
			<tr<?php echo $t_blocked ? ' class="danger"' : '' ?>>
				<td><?php echo string_display_line( (string)$t_code ) ?></td>
				<td><?php echo lang_get( $t_labels[$t_row['change']] ) ?></td>
				<td><?php echo $t_row['old'] === null ? '&mdash;' : string_display_line( $t_row['old']['title'] ) ?></td>
				<td><?php echo $t_row['new'] === null ? '&mdash;' : string_display_line( $t_row['new']['title'] )
					. ( $t_row['change'] == 'changed' && $t_row['old']['description'] !== $t_row['new']['description']
						? '<br /><span class="small grey">' . lang_get( 'topic_description_changed' ) . '</span>' : '' ) ?></td>
				<td class="center"><?php echo (int)$t_row['records'] ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	<div class="widget-main">
		<?php if( $t_preview['blockers'] ) { ?>
		<div class="alert alert-danger"><?php echo string_display_line( sprintf( lang_get( 'topic_migration_blocked' ), implode( ', ', $t_preview['blockers'] ) ) ) ?></div>
		<?php } else { ?>
		<p><?php echo lang_get( 'topic_migration_consequences' ) ?></p>
		<form method="post" action="topic_catalogue_migrate.php">
			<?php echo form_security_field( 'topic_catalogue_migrate' ) ?>
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<input type="hidden" name="catalogue_id" value="<?php echo (int)$f_catalogue_id ?>" />
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo string_display_line( sprintf( lang_get( 'topic_migration_confirm' ),
				topic_catalogue_label( $t_preview['target'] ) ) ) ?></button>
		</form>
		<?php } ?>
		<div class="space-10"></div>
		<?php print_link_button( 'topic_page.php?project_id=' . (int)$f_project_id, lang_get( 'topic_back_to_topics' ) ); ?>
	</div>
	</div>
	</div>
</div>
<?php
layout_page_end();
