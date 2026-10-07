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
 * The active recurrence row for a meeting's series, or null.
 *
 * @param array $p_meeting Any meeting of the series.
 * @return array|null {meeting_series} row.
 */
function meeting_recurrence_get( array $p_meeting ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {meeting_series} WHERE series_id=' . db_param() . ' AND active=1',
		array( meeting_series_root( $p_meeting ) ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row === false ? null : $t_row;
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
function meeting_recurrence_set( array $p_meeting, string $p_rule, int $p_user_id ): void {
	if( $p_rule !== '' && !in_array( $p_rule, MEETING_RECURRENCES, true ) ) {
		throw new Mantis\Exceptions\ClientException( 'Unknown recurrence "' . $p_rule . '"',
			ERROR_INVALID_FIELD_VALUE, array( 'recurrence' ) );
	}
	$t_root = meeting_series_root( $p_meeting );
	db_param_push();
	db_query( 'DELETE FROM {meeting_series} WHERE series_id=' . db_param(), array( $t_root ) );
	if( $p_rule === '' ) {
		return;
	}
	db_param_push();
	db_query(
		'INSERT INTO {meeting_series} ( series_id, recurrence, active, updated_by, date_updated, last_run, last_error )'
		. ' VALUES ( ' . implode( ', ', array_fill( 0, 7, db_param() ) ) . ' )',
		array( $t_root, $p_rule, 1, $p_user_id, db_now(), 0, '' )
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
 * @return int
 */
function meeting_recurrence_next_start( int $p_start, string $p_rule ): int {
	$t_time = date( 'H:i', $p_start );
	switch( $p_rule ) {
		case 'weekly':
			return strtotime( date( 'Y-m-d', $p_start ) . ' ' . $t_time . ' +7 days' );
		case 'fortnightly':
			return strtotime( date( 'Y-m-d', $p_start ) . ' ' . $t_time . ' +14 days' );
		case 'monthly':
			$t_nth = (int)ceil( (int)date( 'j', $p_start ) / 7 );
			$t_weekday = date( 'l', $p_start );
			$t_month = date( 'F Y', strtotime( date( 'Y-m-01', $p_start ) . ' +1 month' ) );
			$t_ordinals = array( 1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth', 5 => 'last' );
			return strtotime( $t_ordinals[$t_nth] . ' ' . $t_weekday . ' of ' . $t_month . ' ' . $t_time );
	}
	return 0;
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
 * @return array Each ['series' => {meeting_series} row, 'latest' => meeting row, 'next_start' => int].
 */
function meeting_schedule_due( ?int $p_now = null ): array {
	$t_now = $p_now ?? time();
	$t_lead = 86400 * max( 0, (int)config_get_global( 'meeting_schedule_lead_days' ) );
	$t_due = array();
	$t_result = db_query( 'SELECT * FROM {meeting_series} WHERE active=1 ORDER BY series_id' );
	while( ( $t_series = db_fetch_array( $t_result ) ) !== false ) {
		$t_latest = meeting_series_latest( (int)$t_series['series_id'] );
		if( $t_latest === null ) {
			continue;
		}
		$t_next = meeting_recurrence_next_start( (int)$t_latest['date_start'], $t_series['recurrence'] );
		# A lapsed series resumes with the next occurrence still to come.
		for( $i = 0; $t_next > 0 && $t_next < $t_now && $i < 520; $i++ ) {
			$t_next = meeting_recurrence_next_start( $t_next, $t_series['recurrence'] );
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
