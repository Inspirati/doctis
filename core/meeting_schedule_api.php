<?php
# Doctis — recurring meetings
#
# A series (the meetings linked by {meeting}.series_id; its root is the first
# meeting) may repeat. {meeting_series} holds the rule; the scheduler
# (scripts/meeting_schedule.php, run from cron) creates each next occurrence
# $g_meeting_schedule_lead_days before it, as the chair of the latest
# meeting, through the Meeting Assistant.
#
#   weekly      — same weekday and time, every 7 days
#   fortnightly — every 14 days
#   monthly     — same weekday of the month (e.g. 2nd Tuesday; 5th → last)
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );
require_api( 'database_api.php' );
require_api( 'lang_api.php' );
require_api( 'meeting_api.php' );

/** Recurrence rules accepted. */
const MEETING_RECURRENCES = array( 'weekly', 'fortnightly', 'monthly' );

/**
 * The stored recurrence row (including a paused rule) for a meeting's series, or null.
 *
 * @param array $p_meeting Any meeting of the series.
 * @return array|null {meeting_series} row.
 */
function meeting_recurrence_get( array $p_meeting ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {meeting_series} WHERE series_id=' . db_param(),
		array( meeting_series_root( $p_meeting ) ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
}

/** The series' saved scheduling zone, independent of the occurrence's display zone. */
function meeting_series_timezone( array $p_meeting ): string {
	$t_rule = meeting_recurrence_get( $p_meeting );
	return !empty( $t_rule['timezone'] ) ? meeting_timezone_validate( $t_rule['timezone'] ) : meeting_timezone( $p_meeting );
}

/**
 * Whether the user may set a series' recurrence: the chair or organiser of
 * the given meeting, which must not be cancelled.
 *
 * @param array $p_meeting
 * @param int   $p_user_id
 * @return bool
 */
function meeting_user_can_set_recurrence( array $p_meeting, int $p_user_id ): bool {
	return (int)$p_meeting['status'] !== MEETING_CANCELLED
		&& in_array( meeting_user_role( $p_meeting, $p_user_id ), array( 'chair', 'organiser' ), true );
}

/**
 * Set or stop a series' recurrence ('' stops it).
 *
 * @param array  $p_meeting Any meeting of the series.
 * @param string $p_rule    weekly, fortnightly, monthly or ''.
 * @param int    $p_user_id
 * @return void
 * @throws Mantis\Exceptions\ClientException for an unknown rule.
 */
function meeting_recurrence_set( array $p_meeting, string $p_rule, int $p_user_id, ?string $p_timezone = null ): void {
	if( $p_rule !== '' && !in_array( $p_rule, MEETING_RECURRENCES, true ) ) {
		throw new Mantis\Exceptions\ClientException( 'Unknown recurrence "' . $p_rule . '"',
			ERROR_INVALID_FIELD_VALUE, array( 'recurrence' ) );
	}
	$t_existing = meeting_recurrence_get( $p_meeting );
	$t_timezone = $p_rule === '' ? '' : meeting_timezone_validate(
		$p_timezone ?? ( !empty( $t_existing['timezone'] ) ? $t_existing['timezone'] : meeting_timezone( $p_meeting ) ) );
	$t_root = meeting_series_root( $p_meeting );
	db_param_push();
	db_query( 'DELETE FROM {meeting_series} WHERE series_id=' . db_param(), array( $t_root ) );
	if( $p_rule === '' ) {
		return;
	}
	db_param_push();
	db_query(
		'INSERT INTO {meeting_series} ( series_id, recurrence, timezone, active, updated_by, date_updated, last_run, last_error )'
		. ' VALUES ( ' . implode( ', ', array_fill( 0, 8, db_param() ) ) . ' )',
		array( $t_root, $p_rule, $t_timezone, 1, $p_user_id, db_now(), 0, '' )
	);
}

/**
 * Record the outcome of a scheduler run for a series.
 *
 * @param int    $p_series_id
 * @param string $p_error '' on success.
 * @return void
 */
function meeting_recurrence_record_run( int $p_series_id, string $p_error ): void {
	db_param_push();
	db_query( 'UPDATE {meeting_series} SET last_run=' . db_param() . ', last_error=' . db_param()
		. ' WHERE series_id=' . db_param(), array( db_now(), mb_substr( $p_error, 0, 255 ), $p_series_id ) );
}

/**
 * Start of the occurrence after $p_start under a rule (same local time).
 *
 * @param int    $p_start Unix timestamp of an occurrence.
 * @param string $p_rule
 * @param string|null $p_timezone Saved series zone; null retains legacy caller behaviour.
 * @return int
 */
function meeting_recurrence_next_start( int $p_start, string $p_rule, ?string $p_timezone = null ): int {
	$t_timezone = meeting_timezone_validate( $p_timezone ?? date_default_timezone_get() );
	$t_local = ( new DateTimeImmutable( '@' . $p_start ) )->setTimezone( new DateTimeZone( $t_timezone ) );
	# Calendar arithmetic on civil dates; convert back to an instant only afterwards.
	$t_date = new DateTimeImmutable( $t_local->format( 'Y-m-d' ) . ' 12:00', new DateTimeZone( 'UTC' ) );
	switch( $p_rule ) {
		case 'weekly':
		case 'fortnightly':
			$t_next = $t_date->modify( $p_rule === 'weekly' ? '+7 days' : '+14 days' );
			break;
		case 'monthly':
			$t_nth = (int)ceil( (int)$t_local->format( 'j' ) / 7 );
			$t_month = $t_date->modify( 'first day of next month' )->format( 'F Y' );
			$t_ordinals = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'last' );
			$t_next = new DateTimeImmutable( $t_ordinals[$t_nth] . ' ' . $t_local->format( 'l' ) . ' of ' . $t_month,
				new DateTimeZone( 'UTC' ) );
			break;
		default:
			return 0;
	}
	return meeting_local_datetime( $t_next->format( 'Y-m-d' ), $t_local->format( 'H:i' ), $t_timezone )->getTimestamp();
}

/**
 * The latest meeting of a series (by start, cancelled ones included: a
 * cancelled occurrence still uses its slot).
 *
 * @param int $p_series_id Series root.
 * @return array|null
 */
function meeting_series_latest( int $p_series_id ): ?array {
	db_param_push();
	$t_result = db_query(
		'SELECT * FROM {meeting} WHERE id=' . db_param() . ' OR series_id=' . db_param()
		. ' ORDER BY date_start DESC, id DESC',
		array( $p_series_id, $p_series_id ), 1
	);
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
}

/**
 * Series whose next occurrence is due to be created now.
 *
 * @param int|null $p_now Defaults to time().
 * @param bool $p_pause_invalid False for a read-only dry run.
 * @param int $p_only_series Restrict evaluation to one series; 0 means all.
 * @return array Each ['series' => {meeting_series} row, 'latest' => meeting row, 'next_start' => int].
 */
function meeting_schedule_due( ?int $p_now = null, bool $p_pause_invalid = true, int $p_only_series = 0 ): array {
	$t_now = $p_now ?? time();
	$t_lead = 86400 * max( 0, (int)config_get_global( 'meeting_schedule_lead_days' ) );
	$t_due = array();
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {meeting_series} WHERE active=1'
		. ( $p_only_series > 0 ? ' AND series_id=' . db_param() : '' ) . ' ORDER BY series_id',
		$p_only_series > 0 ? array( $p_only_series ) : array() );
	while( ( $t_series = db_fetch_array( $t_result ) ) !== false ) {
		$t_latest = meeting_series_latest( (int)$t_series['series_id'] );
		if( $t_latest === null ) {
			continue;
		}
		try {
			$t_next = meeting_recurrence_next_start( (int)$t_latest['date_start'], $t_series['recurrence'], $t_series['timezone'] );
			# A lapsed series resumes with the next occurrence still to come.
			for( $i = 0; $t_next > 0 && $t_next < $t_now && $i < 520; $i++ ) {
				$t_next = meeting_recurrence_next_start( $t_next, $t_series['recurrence'], $t_series['timezone'] );
			}
		} catch( \Mantis\Exceptions\ClientException $e ) {
			# Never guess a legacy zone or silently shift a clock-change occurrence.
			if( !$p_pause_invalid ) {
				continue;
			}
			meeting_recurrence_record_run( (int)$t_series['series_id'],
				lang_get( 'meeting_timezone_review' ) . ' ' . $e->getMessage() );
			db_param_push();
			db_query( 'UPDATE {meeting_series} SET active=0 WHERE series_id=' . db_param(), array( (int)$t_series['series_id'] ) );
			continue;
		}
		if( $t_next > 0 && $t_next - $t_lead <= $t_now ) {
			$t_due[] = array( 'series' => $t_series, 'latest' => $t_latest, 'next_start' => $t_next );
		}
	}
	return $t_due;
}

/**
 * Localised label for a recurrence rule.
 *
 * @param string $p_rule
 * @return string
 */
function meeting_recurrence_label( string $p_rule ): string {
	return $p_rule === '' ? lang_get( 'meeting_repeat_none' ) : lang_get( 'meeting_repeat_' . $p_rule );
}
