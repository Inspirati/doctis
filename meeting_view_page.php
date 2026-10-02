<?php
# Doctis — meeting details.
#
# One meeting: details, participants and attendance, actions and their
# issues, the series it belongs to, and the actions open to the viewer
# (write/revise/approve minutes, change or cancel, plan the next meeting,
# calendar download). Visible to participants and to users who can view the
# meeting document.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'layout_api.php' );
require_api( 'meeting_api.php' );
require_api( 'print_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();
html_robots_noindex();

$f_meeting_id = gpc_get_int( 'id' );
$t_user_id = auth_get_current_user_id();
$t_meeting = meeting_get( $f_meeting_id );
if( $t_meeting === null || !meeting_user_can_view( $t_meeting, $t_user_id ) ) {
	access_denied();
}

$t_status = (int)$t_meeting['status'];
$t_dwg_id = (int)$t_meeting['dwg_id'];
$t_start = (int)$t_meeting['date_start'];
$t_end = $t_start + 60 * (int)$t_meeting['duration'];
$t_ai_enabled = !is_blank( config_get_global( 'anthropic_api_key' ) )
	&& access_has_global_level( config_get_global( 'ai_assist_threshold' ) );
$t_can_minute = $t_ai_enabled && in_array( $t_status, array( MEETING_AGENDA, MEETING_MINUTES ), true )
	&& meeting_user_can_write_minutes( $t_meeting, $t_user_id );
$t_can_approve = meeting_user_can_approve_minutes( $t_meeting, $t_user_id );
$t_can_manage = meeting_user_can_manage( $t_meeting, $t_user_id );
$t_can_plan_next = $t_ai_enabled && $t_status !== MEETING_CANCELLED
	&& meeting_user_can_write_minutes( $t_meeting, $t_user_id );
$t_series = meeting_series_get( $t_meeting );
$t_actions = meeting_actions_get( (int)$t_meeting['id'] );
$t_return = 'meeting_view_page.php?id=' . (int)$t_meeting['id'];

layout_page_header( $t_meeting['doc_ref'] . ' — ' . $t_meeting['title'] );
layout_page_begin( 'my_view_page.php', true );
print_my_view_menu( 'my_view_meeting_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-calendar', 'ace-icon' ); ?>
				<?php echo string_display_line( $t_meeting['doc_ref'] ) ?> &mdash; <?php echo string_display_line( $t_meeting['title'] ) ?>
			</h4>
		</div>
		<div class="widget-body">
			<div class="widget-toolbox padding-8 clearfix">
<?php if( $t_can_minute ): ?>
				<a class="btn btn-sm btn-primary btn-white btn-round" href="ai_assist_page.php?meeting_id=<?php echo (int)$t_meeting['id'] ?>#tab-meeting">
					<?php print_icon( 'fa-pencil', 'ace-icon' ); ?>
					<?php echo lang_get( $t_status === MEETING_MINUTES ? 'meeting_revise_minutes' : 'meeting_write_minutes' ) ?>
				</a>
<?php endif; ?>
<?php if( $t_can_approve ): ?>
				<form method="post" action="meeting_minutes_approve.php" style="display:inline">
					<?php echo form_security_field( 'meeting_minutes_approve' ) ?>
					<input type="hidden" name="meeting_id" value="<?php echo (int)$t_meeting['id'] ?>" />
					<input type="hidden" name="return" value="<?php echo string_attribute( $t_return ) ?>" />
					<button type="submit" class="btn btn-sm btn-success btn-white btn-round">
						<?php print_icon( 'fa-check', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_approve_minutes' ) ?>
					</button>
				</form>
<?php endif; ?>
<?php if( $t_can_manage ): ?>
				<a class="btn btn-sm btn-default btn-white btn-round" href="meeting_edit_page.php?id=<?php echo (int)$t_meeting['id'] ?>">
					<?php print_icon( 'fa-edit', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_edit_button' ) ?>
				</a>
<?php endif; ?>
<?php if( $t_can_plan_next ): ?>
				<a class="btn btn-sm btn-default btn-white btn-round" href="ai_assist_page.php?series_of=<?php echo (int)$t_meeting['id'] ?>#tab-meeting">
					<?php print_icon( 'fa-forward', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_plan_next' ) ?>
				</a>
<?php endif; ?>
				<a class="btn btn-sm btn-default btn-white btn-round" href="meeting_ics.php?id=<?php echo (int)$t_meeting['id'] ?>">
					<?php print_icon( 'fa-calendar-plus-o', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_calendar' ) ?>
				</a>
<?php if( $t_can_manage ): ?>
				<form method="post" action="meeting_cancel.php" class="form-inline pull-right">
					<?php echo form_security_field( 'meeting_cancel' ) ?>
					<input type="hidden" name="meeting_id" value="<?php echo (int)$t_meeting['id'] ?>" />
					<input type="text" name="reason" class="input-sm" size="28" maxlength="250"
						placeholder="<?php echo lang_get( 'meeting_cancel_reason' ) ?>" />
					<button type="submit" class="btn btn-sm btn-danger btn-white btn-round">
						<?php print_icon( 'fa-times', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_cancel_button' ) ?>
					</button>
				</form>
<?php endif; ?>
			</div>
			<div class="widget-main no-padding">
				<div class="table-responsive">
				<table class="table table-bordered table-condensed">
					<tr>
						<th class="category width-20"><?php echo lang_get( 'meeting_when' ) ?></th>
						<td><?php echo date( 'l j F Y, H:i', $t_start ) . ' – ' . date( 'H:i', $t_end ) ?>
							<span class="text-muted">(<?php echo (int)$t_meeting['duration'] ?> min, <?php echo string_display_line( date_default_timezone_get() ) ?>)</span></td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'meeting_location' ) ?></th>
						<td><?php echo string_display_line( $t_meeting['location'] ) ?></td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'meeting_department_full' ) ?></th>
						<td><?php
							$t_departments = config_get_global( 'ai_meeting_departments' );
							echo string_display_line( $t_meeting['department']
								. ( isset( $t_departments[$t_meeting['department']] ) ? ' — ' . $t_departments[$t_meeting['department']]['name'] : '' ) ) ?></td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'status' ) ?></th>
						<td><?php echo meeting_status_label( $t_status ) ?></td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'meeting_role_chair' ) ?></th>
						<td><?php echo string_display_line( meeting_user_display_name( (int)$t_meeting['chair_id'] ) ) ?></td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'meeting_role_minute_taker' ) ?></th>
						<td><?php echo (int)$t_meeting['minute_taker_id'] > 0
							? string_display_line( meeting_user_display_name( (int)$t_meeting['minute_taker_id'] ) ) : '—' ?></td>
					</tr>
					<tr>
						<th class="category"><?php echo lang_get( 'meeting_record' ) ?></th>
						<td>
<?php if( $t_dwg_id > 0 && dwg_exists( $t_dwg_id ) && access_has_dwg_level( config_get( 'view_dwg_threshold' ), $t_dwg_id ) ): ?>
							<a href="<?php echo string_get_dwg_view_url( $t_dwg_id ) ?>"><?php echo lang_get( 'document' ) ?> #<?php echo $t_dwg_id ?></a>
							<span class="text-muted small"><?php echo string_display_line( $t_meeting['git_path'] ) ?></span>
<?php elseif( $t_dwg_id > 0 ): ?>
							<span class="text-muted"><?php echo lang_get( 'meeting_record_by_email' ) ?></span>
<?php else: ?>
							<span class="text-muted"><?php echo lang_get( 'meeting_no_record' ) ?></span>
<?php endif; ?>
						</td>
					</tr>
				</table>
				</div>
			</div>
		</div>
	</div>

	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-users', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_participants' ) ?></h4>
		</div>
		<div class="widget-body"><div class="widget-main no-padding">
			<table class="table table-bordered table-condensed table-striped">
				<tr>
					<th><?php echo lang_get( 'name' ) ?></th>
					<th><?php echo lang_get( 'meeting_attendance' ) ?></th>
				</tr>
<?php foreach( meeting_invitees_get( (int)$t_meeting['id'] ) as $t_invitee ):
		$t_attendance = (int)$t_invitee['attendance']; ?>
				<tr>
					<td><?php echo string_display_line( $t_invitee['name'] ) ?>
						<?php if( (int)$t_invitee['user_id'] === (int)$t_meeting['minute_taker_id'] && (int)$t_invitee['user_id'] > 0 ): ?>
						<span class="text-muted">(<?php echo lang_get( 'meeting_role_minute_taker' ) ?>)</span>
						<?php elseif( (int)$t_invitee['user_id'] === 0 ): ?>
						<span class="text-muted">(<?php echo lang_get( 'meeting_guest' ) ?>)</span>
						<?php endif; ?></td>
					<td><?php echo lang_get( $t_attendance === MEETING_ATTENDED ? 'meeting_attended'
						: ( $t_attendance === MEETING_APOLOGIES ? 'meeting_apologies' : 'meeting_invited' ) ) ?></td>
				</tr>
<?php endforeach; ?>
			</table>
		</div></div>
	</div>

	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-tasks', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_actions' ) ?></h4>
		</div>
		<div class="widget-body"><div class="widget-main no-padding">
<?php if( empty( $t_actions ) ): ?>
			<p class="text-muted" style="padding:8px"><?php echo lang_get( 'meeting_no_actions' ) ?></p>
<?php else: ?>
			<table class="table table-bordered table-condensed table-striped">
				<tr>
					<th class="width-10">#</th>
					<th><?php echo lang_get( 'meeting_actions' ) ?></th>
					<th><?php echo lang_get( 'meeting_action_owner' ) ?></th>
					<th><?php echo lang_get( 'meeting_action_due' ) ?></th>
					<th><?php echo lang_get( 'meeting_action_issue' ) ?></th>
				</tr>
<?php	foreach( $t_actions as $t_action ):
		$t_bug_id = (int)$t_action['bug_id']; ?>
				<tr>
					<td><?php echo string_display_line( $t_action['ref'] ) ?></td>
					<td><?php echo string_display_line( $t_action['description'] ) ?></td>
					<td><?php echo is_blank( $t_action['owner_name'] ) ? '—' : string_display_line( $t_action['owner_name'] ) ?></td>
					<td class="nowrap"><?php echo (int)$t_action['due_date'] > 0
						? date( config_get( 'short_date_format' ), (int)$t_action['due_date'] ) : '—' ?></td>
					<td class="nowrap">
<?php		if( $t_bug_id > 0 && bug_exists( $t_bug_id ) && access_has_bug_level( config_get( 'view_bug_threshold' ), $t_bug_id ) ): ?>
						<a href="<?php echo string_get_bug_view_url( $t_bug_id ) ?>"><?php echo bug_format_id( $t_bug_id ) ?></a>
						<?php echo get_enum_element( 'status', (int)bug_get_field( $t_bug_id, 'status' ) ) ?>
<?php		elseif( $t_bug_id > 0 ): ?>
						<?php echo bug_format_id( $t_bug_id ) ?>
<?php		else: ?>
						<span class="text-muted small"><?php echo lang_get( $t_status === MEETING_APPROVED ? 'meeting_action_no_issue' : 'meeting_action_pending' ) ?></span>
<?php		endif; ?>
					</td>
				</tr>
<?php	endforeach; ?>
			</table>
<?php endif; ?>
		</div></div>
	</div>

<?php if( count( $t_series ) > 1 ): ?>
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-link', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_series' ) ?></h4>
		</div>
		<div class="widget-body"><div class="widget-main no-padding">
			<table class="table table-bordered table-condensed table-striped">
<?php	foreach( $t_series as $t_m ): ?>
				<tr<?php echo (int)$t_m['id'] === (int)$t_meeting['id'] ? ' class="info"' : '' ?>>
					<td class="nowrap"><?php echo date( config_get( 'normal_date_format' ), (int)$t_m['date_start'] ) ?></td>
					<td>
<?php		if( meeting_user_can_view( $t_m, $t_user_id ) ): ?>
						<a href="meeting_view_page.php?id=<?php echo (int)$t_m['id'] ?>"><?php echo string_display_line( $t_m['doc_ref'] ) ?></a>
<?php		else: ?>
						<?php echo string_display_line( $t_m['doc_ref'] ) ?>
<?php		endif; ?>
						&mdash; <?php echo string_display_line( $t_m['title'] ) ?>
					</td>
					<td><?php echo meeting_status_label( (int)$t_m['status'] ) ?></td>
				</tr>
<?php	endforeach; ?>
			</table>
		</div></div>
	</div>
<?php endif; ?>
</div>
<?php layout_page_end(); ?>
