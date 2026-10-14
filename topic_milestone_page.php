<?php
# Doctis — A project's Documentation Topics milestones: the ordered lifecycle
# points expectations fall due at (STD-ENG-004 §6). Project managers edit
# them; other readers see the list.

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
require_api( 'topic_expectation_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
project_ensure_exists( $f_project_id );
if( !topic_can_view_project( $f_project_id ) ) {
	access_denied();
}
topic_project_catalogue( $f_project_id );
$t_can_manage = topic_can_manage_project( $f_project_id );
$t_milestones = topic_milestone_get_all( $f_project_id );
$t_current = topic_milestone_current( $t_milestones );
$t_action = 'topic_milestone_action.php';

layout_page_header( lang_get( 'topic_milestones' ) . ' — ' . project_get_name( $f_project_id ) );
layout_page_begin( 'topic_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-flag', 'ace-icon' ); ?>
			<?php echo string_display_line( lang_get( 'topic_milestones' ) . ' — ' . project_get_name( $f_project_id ) ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p><?php echo lang_get( 'topic_milestones_intro' ) ?></p>
		<?php if( !$t_milestones && $t_can_manage ) {
			print_form_button( $t_action, lang_get( 'topic_milestones_load_defaults' ),
				array( 'action' => 'load_defaults', 'project_id' => $f_project_id ), null, 'btn btn-primary btn-sm btn-white btn-round' );
		} ?>
	</div>
	<?php if( $t_milestones ) { ?>
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th><?php echo lang_get( 'topic_sort_order' ) ?></th>
			<th><?php echo lang_get( 'topic_milestone' ) ?></th>
			<th><?php echo lang_get( 'description' ) ?></th>
			<th><?php echo lang_get( 'topic_milestone_achieved' ) ?></th>
			<?php if( $t_can_manage ) { ?><th><?php echo lang_get( 'actions' ) ?></th><?php } ?>
		</tr></thead>
		<tbody>
		<?php foreach( $t_milestones as $t_id => $t_milestone ) {
			$t_achieved = (int)$t_milestone['date_achieved']; ?>
		<tr>
			<?php if( $t_can_manage ) { ?>
			<td colspan="3">
				<form method="post" action="<?php echo $t_action ?>" class="form-inline">
					<?php echo form_security_field( 'topic_milestone_action' ) ?>
					<input type="hidden" name="action" value="update" />
					<input type="hidden" name="id" value="<?php echo (int)$t_id ?>" />
					<input type="number" name="sort_order" class="input-sm" style="width:5em" value="<?php echo (int)$t_milestone['sort_order'] ?>"
						aria-label="<?php echo lang_get( 'topic_sort_order' ) ?>" />
					<input type="text" name="name" class="input-sm" size="24" maxlength="64" required
						value="<?php echo string_attribute( $t_milestone['name'] ) ?>" aria-label="<?php echo lang_get( 'topic_milestone' ) ?>" />
					<input type="text" name="description" class="input-sm" size="60" maxlength="255"
						value="<?php echo string_attribute( $t_milestone['description'] ) ?>" aria-label="<?php echo lang_get( 'description' ) ?>" />
					<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'update' ) ?></button>
				</form>
			</td>
			<?php } else { ?>
			<td><?php echo (int)$t_milestone['sort_order'] ?></td>
			<td><?php echo string_display_line( $t_milestone['name'] ) ?></td>
			<td><?php echo string_display_line( $t_milestone['description'] ) ?></td>
			<?php } ?>
			<td class="nowrap"><?php
				echo $t_achieved > 0 ? date( config_get( 'short_date_format' ), $t_achieved ) : '&mdash;';
				if( $t_id == $t_current ) {
					echo ' <span class="label label-info">' . lang_get( 'topic_milestone_current' ) . '</span>';
				} ?></td>
			<?php if( $t_can_manage ) { ?>
			<td class="nowrap"><?php
				print_form_button( $t_action, lang_get( $t_achieved ? 'topic_milestone_reopen' : 'topic_milestone_achieve' ),
					array( 'action' => $t_achieved ? 'reopen' : 'achieve', 'id' => $t_id ) );
				echo ' ';
				print_form_button( $t_action, lang_get( 'delete' ),
					array( 'action' => 'delete', 'id' => $t_id ), null, 'btn btn-danger btn-xs btn-white btn-round' ); ?></td>
			<?php } ?>
		</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	<?php } ?>
	<?php if( $t_can_manage ) { ?>
	<div class="widget-main">
		<form method="post" action="<?php echo $t_action ?>" class="form-inline">
			<?php echo form_security_field( 'topic_milestone_action' ) ?>
			<input type="hidden" name="action" value="create" />
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<label for="milestone-name"><?php echo lang_get( 'topic_add_milestone' ) ?></label>
			<input type="text" id="milestone-name" name="name" class="input-sm" size="24" maxlength="64" required placeholder="<?php echo lang_get( 'topic_milestone' ) ?>" />
			<input type="text" name="description" class="input-sm" size="60" maxlength="255" placeholder="<?php echo lang_get( 'description' ) ?>" aria-label="<?php echo lang_get( 'description' ) ?>" />
			<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'topic_add_milestone' ) ?></button>
		</form>
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
