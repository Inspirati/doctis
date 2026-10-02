<?php
# Doctis — recurring meeting scheduler (cron).
#
# For each repeating series ({meeting_series}) whose next occurrence is due
# ($g_meeting_schedule_lead_days ahead), create that meeting as the chair of
# the series' latest meeting: the Meeting Assistant writes the agenda (with
# approval of the previous minutes and the open actions carried forward);
# date, time, department, duration, location, minute taker and invitees are
# fixed from the previous meeting, not left to the model. The new meeting is
# stored, invitees receive the agenda with a calendar invitation, and the
# chair is told. The outcome is recorded on the series (last_run, last_error).
#
# Usage (as the web server user, e.g. from /etc/cron.d/doctis):
#   php scripts/meeting_schedule.php [--dry-run] [--series=N] [--now="YYYY-MM-DD HH:MM"]
#
# --dry-run  list what is due without creating anything (no API call)
# --series   only this series (root meeting id)
# --now      evaluate as if at this time (testing)

global $g_bypass_headers;
$g_bypass_headers = 1;

require_once( dirname( __DIR__ ) . '/core.php' );
require_once( dirname( __DIR__ ) . '/ai_assist_anthropic_api.php' );
require_once( dirname( __DIR__ ) . '/ai_assist_meeting_api.php' );

require_api( 'authentication_api.php' );
require_api( 'meeting_api.php' );

if( php_sapi_name() != 'cli' ) {
	echo "meeting_schedule.php is not allowed to run through the webserver.\n";
	exit( 1 );
}

$t_options = getopt( '', array( 'dry-run', 'series:', 'now:' ) );
$t_dry_run = isset( $t_options['dry-run'] );
$t_only = isset( $t_options['series'] ) ? (int)$t_options['series'] : 0;
$t_now = isset( $t_options['now'] ) ? strtotime( $t_options['now'] ) : time();
if( $t_now === false ) {
	echo "Invalid --now value.\n";
	exit( 1 );
}

# One run at a time: overlapping runs could create an occurrence twice.
$t_lock = fopen( sys_get_temp_dir() . '/doctis-meeting-schedule.lock', 'c' );
if( !$t_lock || !flock( $t_lock, LOCK_EX | LOCK_NB ) ) {
	echo "Another scheduler run is in progress.\n";
	exit( 0 );
}

$t_failed = 0;
foreach( meeting_schedule_due( $t_now ) as $t_due ) {
	$t_series_id = (int)$t_due['series']['series_id'];
	if( $t_only > 0 && $t_series_id !== $t_only ) {
		continue;
	}
	$t_rule = $t_due['series']['recurrence'];
	$t_start = (int)$t_due['next_start'];
	echo 'Series ' . $t_series_id . ' (' . $t_rule . '): next ' . date( 'Y-m-d H:i', $t_start ) . ' — ';
	if( $t_dry_run ) {
		echo "due (dry run)\n";
		continue;
	}
	$t_error = meeting_schedule_create( $t_series_id, $t_rule, $t_start );
	meeting_recurrence_record_run( $t_series_id, $t_error );
	if( $t_error === '' ) {
		echo "created\n";
	} else {
		echo 'FAILED: ' . $t_error . "\n";
		$t_failed++;
	}
}

flock( $t_lock, LOCK_UN );
exit( $t_failed > 0 ? 1 : 0 );


/**
 * Create the next occurrence of a series.
 *
 * @param int    $p_series_id Series root.
 * @param string $p_rule
 * @param int    $p_start     Start of the occurrence.
 * @return string '' on success, else the error.
 */
function meeting_schedule_create( int $p_series_id, string $p_rule, int $p_start ): string {
	# The latest meeting that took place (or will) is the model for the next one.
	$t_base = null;
	foreach( array_reverse( meeting_series_get( array( 'id' => $p_series_id, 'series_id' => 0 ) ) ) as $t_m ) {
		if( (int)$t_m['status'] !== MEETING_CANCELLED ) {
			$t_base = $t_m;
			break;
		}
	}
	if( $t_base === null ) {
		return 'every meeting of the series is cancelled';
	}
	$t_chair = (int)$t_base['chair_id'];
	if( !user_exists( $t_chair ) || !user_is_enabled( $t_chair )
		|| !auth_attempt_script_login( user_get_field( $t_chair, 'username' ) ) ) {
		return 'the chair (user ' . $t_chair . ') cannot act';
	}

	$t_ids = array();
	$t_guests = array();
	foreach( meeting_invitees_get( (int)$t_base['id'] ) as $t_invitee ) {
		if( (int)$t_invitee['user_id'] > 0 ) {
			$t_ids[] = (int)$t_invitee['user_id'];
		} else {
			$t_guests[] = $t_invitee['name'];
		}
	}

	$t_when = date( 'l j F Y', $p_start ) . ' at ' . date( 'H:i', $p_start ) . ' (' . date_default_timezone_get() . ')';
	$t_answer = ai_assist_anthropic_request(
		ai_assist_meeting_system_prompt( $t_chair, null, $t_base ),
		array( array( 'role' => 'user', 'content' =>
			'This meeting series repeats ' . $p_rule . '. Doctis is scheduling its next meeting automatically for '
			. $t_when . '. Do not ask any questions: generate the agenda document now, with the same invitees, '
			. 'minute taker, department, duration and location as the previous meeting, approval of the previous '
			. 'minutes, and every open action. Leave recurrence empty.' ) ),
		8192
	);
	if( $t_answer['error'] !== null ) {
		return $t_answer['error'];
	}

	$t_result = ai_assist_process_meeting_document( $t_answer['reply'], $t_chair, array(
		'type'            => 'agenda',
		'date'            => date( 'Y-m-d', $p_start ),
		'time'            => date( 'H:i', $p_start ),
		'dept'            => $t_base['department'],
		'duration'        => (string)$t_base['duration'],
		'location'        => $t_base['location'],
		'minute_taker_id' => (string)$t_base['minute_taker_id'],
		'invitee_ids'     => implode( ',', $t_ids ),
		'guests'          => implode( ';', $t_guests ),
		'series_of'       => (string)$t_base['id'],
		'recurrence'      => '',
	) );
	if( $t_result === null ) {
		return 'the assistant did not produce an agenda';
	}
	if( empty( $t_result['meeting_id'] ) ) {
		return $t_result['error'] ?? 'the meeting was not recorded';
	}

	$t_meeting = meeting_get( (int)$t_result['meeting_id'] );
	meeting_email_send( $t_meeting, array( $t_chair ),
		'Next meeting scheduled: ' . $t_meeting['doc_ref'] . ' — ' . $t_meeting['title'],
		'Doctis has scheduled the next meeting of this ' . $p_rule . ' series for ' . $t_when
			. '. The invitees have been sent the agenda and a calendar invitation. '
			. 'Review, change or cancel it from the meeting page.',
		meeting_record_content( $t_meeting ) );

	return (string)( $t_result['error'] ?? '' );
}
