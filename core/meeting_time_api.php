<?php
# Meeting local times: retain named zones, store occurrences as Unix timestamps.

require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'lang_api.php' );
require_api( 'user_pref_api.php' );

use Mantis\Exceptions\ClientException;

/** Validate a named timezone, including its daylight-saving rules. */
function meeting_timezone_validate( string $p_timezone ): string {
	if( !in_array( $p_timezone, timezone_identifiers_list(), true ) ) {
		throw new ClientException( 'Invalid meeting timezone', ERROR_INVALID_FIELD_VALUE, array( 'timezone' ) );
	}
	return $p_timezone;
}

/** Legacy meetings have no recorded zone: use the application default, never guess. */
function meeting_timezone( array $p_meeting ): string {
	return meeting_timezone_validate( (string)( !empty( $p_meeting['timezone'] )
		? $p_meeting['timezone'] : config_get_global( 'default_timezone' ) ) );
}

/** Format an occurrence in its recorded zone, independently of the reader. */
function meeting_datetime( array $p_meeting ): DateTimeImmutable {
	return ( new DateTimeImmutable( '@' . (int)$p_meeting['date_start'] ) )
		->setTimezone( new DateTimeZone( meeting_timezone( $p_meeting ) ) );
}

/** All instants matching a local clock time: zero in a gap, two in an overlap. */
function meeting_local_time_candidates( string $p_date, string $p_time, string $p_timezone ): array {
	$t_zone = new DateTimeZone( meeting_timezone_validate( $p_timezone ) );
	$t_text = $p_date . ' ' . $p_time;
	$t_naive = DateTimeImmutable::createFromFormat( '!Y-m-d H:i', $t_text, new DateTimeZone( 'UTC' ) );
	if( !$t_naive || $t_naive->format( 'Y-m-d H:i' ) !== $t_text ) {
		throw new ClientException( 'Invalid meeting date/time', ERROR_INVALID_FIELD_VALUE, array( 'date/time' ) );
	}
	$t_wall = $t_naive->getTimestamp();
	$t_candidates = array();
	foreach( $t_zone->getTransitions( $t_wall - 172800, $t_wall + 172800 ) as $t_transition ) {
		$t_ts = $t_wall - $t_transition['offset'];
		$t_local = ( new DateTimeImmutable( '@' . $t_ts ) )->setTimezone( $t_zone );
		if( $t_local->format( 'Y-m-d H:i' ) === $t_text ) {
			$t_candidates[$t_ts] = $t_local;
		}
	}
	ksort( $t_candidates, SORT_NUMERIC );
	return array_values( $t_candidates );
}

/** Reject skipped times; require an explicit earlier/later choice for repeated times. */
function meeting_local_datetime( string $p_date, string $p_time, string $p_timezone, string $p_choice = '' ): DateTimeImmutable {
	if( !in_array( $p_choice, array( '', 'earlier', 'later' ), true ) ) {
		throw new ClientException( 'Invalid clock-change choice', ERROR_INVALID_FIELD_VALUE, array( 'time_choice' ) );
	}
	$t_candidates = meeting_local_time_candidates( $p_date, $p_time, $p_timezone );
	if( !$t_candidates ) {
		$t_message = sprintf( lang_get( 'meeting_time_gap' ), $p_date . ' ' . $p_time, $p_timezone );
		throw new ClientException( $t_message, ERROR_INVALID_FIELD_VALUE, array( $t_message ) );
	}
	if( count( $t_candidates ) > 1 && $p_choice === '' ) {
		$t_message = sprintf( lang_get( 'meeting_time_overlap' ), $p_date . ' ' . $p_time, $p_timezone );
		throw new ClientException( $t_message, ERROR_INVALID_FIELD_VALUE, array( $t_message ) );
	}
	return $p_choice === 'later' ? end( $t_candidates ) : $t_candidates[0];
}

/** Preserve an already resolved repeated time when editing other meeting details. */
function meeting_time_choice( array $p_meeting ): string {
	$t_local = meeting_datetime( $p_meeting );
	$t_candidates = meeting_local_time_candidates( $t_local->format( 'Y-m-d' ), $t_local->format( 'H:i' ), meeting_timezone( $p_meeting ) );
	return count( $t_candidates ) > 1
		? ( $t_candidates[0]->getTimestamp() === (int)$p_meeting['date_start'] ? 'earlier' : 'later' ) : '';
}
