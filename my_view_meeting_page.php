<?php
# Doctis — My Meetings view.
#
# Lists the meetings the current user chairs, minutes, organised or is invited
# to (see core/meeting_api.php). Meetings are planned, and minutes written,
# in the AI Assistant Meeting tab.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'layout_api.php' );
require_api( 'meeting_api.php' );
require_api( 'string_api.php' );

auth_ensure_user_authenticated();
html_robots_noindex();

$t_user_id = auth_get_current_user_id();
$t_ai_enabled = !is_blank( config_get_global( 'anthropic_api_key' ) )
	&& access_has_global_level( config_get_global( 'ai_assist_threshold' ) );

# A meeting is upcoming until its scheduled end.
$t_now = time();
$t_upcoming = array();
$t_past = array();
foreach( meeting_get_for_user( $t_user_id ) as $t_meeting ) {
	if( (int)$t_meeting['date_start'] + 60 * (int)$t_meeting['duration'] >= $t_now ) {
		array_unshift( $t_upcoming, $t_meeting );   # soonest first
	} else {
		$t_past[] = $t_meeting;                     # most recent first
	}
}

/**
 * Comma-separated participant list: chair, minute taker, invitees, with
 * attendance once minutes exist.
 *
 * @param array $p_meeting Meeting row.
 * @return string HTML.
 */
function meeting_print_participants( array $p_meeting ) {
	$t_parts = array();
	$t_parts[] = string_html_specialchars( meeting_user_display_name( (int)$p_meeting['chair_id'] ) )
		. ' <span class="text-muted">(' . lang_get( 'meeting_role_chair' ) . ')</span>';
	if( (int)$p_meeting['minute_taker_id'] > 0 && user_exists( (int)$p_meeting['minute_taker_id'] ) ) {
		$t_parts[] = string_html_specialchars( meeting_user_display_name( (int)$p_meeting['minute_taker_id'] ) )
			. ' <span class="text-muted">(' . lang_get( 'meeting_role_minute_taker' ) . ')</span>';
	}
	foreach( meeting_invitees_get( (int)$p_meeting['id'] ) as $t_invitee ) {
		$t_uid = (int)$t_invitee['user_id'];
		if( $t_uid > 0 && ( $t_uid === (int)$p_meeting['chair_id'] || $t_uid === (int)$p_meeting['minute_taker_id'] ) ) {
			continue;
		}
		$t_note = '';
		if( (int)$t_invitee['attendance'] === MEETING_ATTENDED ) {
			$t_note = ' <span class="text-muted">(' . lang_get( 'meeting_attended' ) . ')</span>';
		} else if( (int)$t_invitee['attendance'] === MEETING_APOLOGIES ) {
			$t_note = ' <span class="text-muted">(' . lang_get( 'meeting_apologies' ) . ')</span>';
		}
		$t_parts[] = string_html_specialchars( $t_invitee['name'] ) . $t_note;
	}
	return implode( ', ', $t_parts );
}

/**
 * Print a table of meetings.
 *
 * @param array $p_meetings  Meeting rows with 'role'.
 * @param bool  $p_ai_enabled Whether the Meeting Assistant can be opened.
 * @return void
 */
function meeting_print_table( array $p_meetings, $p_ai_enabled ) {
	if( empty( $p_meetings ) ) {
		echo '<p class="text-muted">' . lang_get( 'meeting_none' ) . '</p>';
		return;
	}
	$t_user_id = auth_get_current_user_id();
?>
	<div class="table-responsive">
	<table class="table table-bordered table-condensed table-striped table-hover">
		<thead>
			<tr>
				<th><?php echo lang_get( 'meeting_when' ) ?></th>
				<th><?php echo lang_get( 'meeting_meeting' ) ?></th>
				<th><?php echo lang_get( 'meeting_department' ) ?></th>
				<th><?php echo lang_get( 'meeting_my_role' ) ?></th>
				<th><?php echo lang_get( 'meeting_participants' ) ?></th>
				<th><?php echo lang_get( 'status' ) ?></th>
				<th><?php echo lang_get( 'meeting_record' ) ?></th>
			</tr>
		</thead>
		<tbody>
<?php foreach( $p_meetings as $t_meeting ):
		$t_status = (int)$t_meeting['status'];
		$t_dwg_id = (int)$t_meeting['dwg_id'];
		$t_can_minute = $p_ai_enabled
			&& in_array( $t_status, array( MEETING_AGENDA, MEETING_MINUTES ), true )
			&& meeting_user_can_write_minutes( $t_meeting, $t_user_id );
?>
			<tr>
				<td class="nowrap">
					<?php echo date( config_get( 'normal_date_format' ), (int)$t_meeting['date_start'] ) ?>
					<br><span class="text-muted small"><?php echo (int)$t_meeting['duration'] ?> min</span>
				</td>
				<td>
					<strong><?php echo string_display_line( $t_meeting['title'] ) ?></strong>
					<br><span class="text-muted small">
						<?php echo string_display_line( $t_meeting['doc_ref'] ) ?>
						<?php if( !is_blank( $t_meeting['location'] ) ) echo '&middot; ' . string_display_line( $t_meeting['location'] ) ?>
					</span>
				</td>
				<td><?php echo string_display_line( $t_meeting['department'] ) ?></td>
				<td><?php echo lang_get( 'meeting_role_' . $t_meeting['role'] ) ?></td>
				<td class="small"><?php echo meeting_print_participants( $t_meeting ) ?></td>
				<td>
					<?php echo meeting_status_label( $t_status ) ?>
<?php	# TMPL-SYS-001: draft minutes are due within 2 business days of the meeting.
		$t_end = (int)$t_meeting['date_start'] + 60 * (int)$t_meeting['duration'];
		if( $t_status === MEETING_AGENDA && $t_end < time() ):
			$t_due = meeting_business_days_after( $t_end, 2 ); ?>
					<br><span class="small <?php echo $t_due < time() ? 'red' : 'text-muted' ?>">
						<?php echo sprintf( lang_get( 'meeting_minutes_due' ), date( config_get( 'short_date_format' ), $t_due ) ) ?>
					</span>
<?php	endif; ?>
				</td>
				<td class="nowrap">
<?php	if( $t_dwg_id > 0 && dwg_exists( $t_dwg_id ) && access_has_dwg_level( config_get( 'view_dwg_threshold' ), $t_dwg_id ) ): ?>
					<a href="<?php echo string_get_dwg_view_url( $t_dwg_id ) ?>"><?php echo lang_get( 'document' ) ?> #<?php echo $t_dwg_id ?></a>
<?php	else: ?>
					<span class="text-muted"><?php echo lang_get( 'meeting_no_record' ) ?></span>
<?php	endif; ?>
<?php	if( $t_can_minute ): ?>
					<br><a class="btn btn-minier btn-primary btn-white btn-round" style="margin-top:4px"
						href="ai_assist_page.php?meeting_id=<?php echo (int)$t_meeting['id'] ?>#tab-meeting">
						<?php print_icon( 'fa-pencil', 'ace-icon' ); ?>
						<?php echo lang_get( $t_status === MEETING_MINUTES ? 'meeting_revise_minutes' : 'meeting_write_minutes' ) ?>
					</a>
<?php	endif; ?>
<?php	if( meeting_user_can_approve_minutes( $t_meeting, $t_user_id ) ): ?>
					<form method="post" action="meeting_minutes_approve.php" style="display:inline">
						<?php echo form_security_field( 'meeting_minutes_approve' ) ?>
						<input type="hidden" name="meeting_id" value="<?php echo (int)$t_meeting['id'] ?>" />
						<button type="submit" class="btn btn-minier btn-success btn-white btn-round" style="margin-top:4px">
							<?php print_icon( 'fa-check', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_approve_minutes' ) ?>
						</button>
					</form>
<?php	endif; ?>
				</td>
			</tr>
<?php endforeach; ?>
		</tbody>
	</table>
	</div>
<?php
}

layout_page_header( lang_get( 'my_view_meeting_link' ) );
layout_page_begin( 'my_view_page.php', true );
print_my_view_menu( 'my_view_meeting_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-calendar', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_upcoming' ) ?></h4>
<?php if( $t_ai_enabled ): ?>
			<div class="widget-toolbar">
				<a class="btn btn-minier btn-primary btn-white btn-round" href="ai_assist_page.php#tab-meeting">
					<?php print_icon( 'fa-plus', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_plan_button' ) ?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="widget-body">
			<div class="widget-main">
				<?php meeting_print_table( $t_upcoming, $t_ai_enabled ); ?>
			</div>
		</div>
	</div>

	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter"><?php print_icon( 'fa-history', 'ace-icon' ); ?> <?php echo lang_get( 'meeting_past' ) ?></h4>
		</div>
		<div class="widget-body">
			<div class="widget-main">
				<?php meeting_print_table( $t_past, $t_ai_enabled ); ?>
			</div>
		</div>
	</div>
</div>
<?php layout_page_end(); ?>
