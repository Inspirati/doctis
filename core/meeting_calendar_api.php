<?php
# Doctis — meeting calendar invitations (iCalendar, RFC 5545)
#
# Each meeting has a stable UID; {meeting}.sequence is the SEQUENCE, bumped on
# reschedule, invitee change and cancellation, so calendar clients update or
# remove the event they already hold. Times are written in UTC.
#
#   REQUEST — agenda issued or updated (emailed to invitees)
#   CANCEL  — meeting cancelled, or invitee removed
#   PUBLISH — downloaded from the meeting page (meeting_ics.php)
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );
require_api( 'meeting_api.php' );
require_api( 'user_api.php' );

/**
 * Build the iCalendar text for a meeting.
 *
 * @param array  $p_meeting Meeting row.
 * @param string $p_method  REQUEST, CANCEL or PUBLISH.
 * @return string CRLF-terminated, folded iCalendar text.
 */
function meeting_ics( array $p_meeting, string $p_method = 'REQUEST' ): string {
	$t_method = in_array( $p_method, array( 'REQUEST', 'CANCEL', 'PUBLISH' ), true ) ? $p_method : 'REQUEST';
	$t_path = config_get_global( 'path' );
	$t_host = parse_url( $t_path, PHP_URL_HOST ) ?: 'doctis';
	$t_url = $t_path . 'meeting_view_page.php?id=' . (int)$p_meeting['id'];
	$t_start = (int)$p_meeting['date_start'];
	$t_end = $t_start + 60 * (int)$p_meeting['duration'];
	$t_cancelled = $t_method === 'CANCEL' || (int)$p_meeting['status'] === MEETING_CANCELLED;

	$t_lines = array(
		'BEGIN:VCALENDAR',
		'PRODID:-//Doctis//Meeting Assistant//EN',
		'VERSION:2.0',
		'CALSCALE:GREGORIAN',
		'METHOD:' . $t_method,
		'BEGIN:VEVENT',
		'UID:doctis-meeting-' . (int)$p_meeting['id'] . '@' . $t_host,
		'SEQUENCE:' . (int)$p_meeting['sequence'],
		'DTSTAMP:' . meeting_ics_utc( time() ),
		'DTSTART:' . meeting_ics_utc( $t_start ),
		'DTEND:' . meeting_ics_utc( $t_end ),
		'SUMMARY:' . meeting_ics_text( $p_meeting['title'] . ' (' . $p_meeting['doc_ref'] . ')' ),
		'LOCATION:' . meeting_ics_text( $p_meeting['location'] ),
		'DESCRIPTION:' . meeting_ics_text( 'Doctis meeting record ' . $p_meeting['doc_ref'] . ".\n" . $t_url ),
		'URL:' . $t_url,
		'STATUS:' . ( $t_cancelled ? 'CANCELLED' : 'CONFIRMED' ),
	);

	$t_chair = (int)$p_meeting['chair_id'];
	if( $t_chair > 0 && user_exists( $t_chair ) ) {
		$t_email = meeting_user_notify_email( $t_chair );
		if( !is_blank( $t_email ) ) {
			$t_lines[] = 'ORGANIZER;CN=' . meeting_ics_param( meeting_user_display_name( $t_chair ) ) . ':mailto:' . $t_email;
		}
	}
	if( $t_method !== 'PUBLISH' ) {
		foreach( meeting_invitees_get( (int)$p_meeting['id'] ) as $t_invitee ) {
			$t_uid = (int)$t_invitee['user_id'];
			if( $t_uid <= 0 || !user_exists( $t_uid ) ) {
				continue;
			}
			$t_email = meeting_user_notify_email( $t_uid );
			if( !is_blank( $t_email ) ) {
				$t_lines[] = 'ATTENDEE;CN=' . meeting_ics_param( $t_invitee['name'] )
					. ';ROLE=REQ-PARTICIPANT;PARTSTAT=NEEDS-ACTION;RSVP=FALSE:mailto:' . $t_email;
			}
		}
	}
	$t_lines[] = 'END:VEVENT';
	$t_lines[] = 'END:VCALENDAR';

	return implode( "\r\n", array_map( 'meeting_ics_fold', $t_lines ) ) . "\r\n";
}

/**
 * UTC date-time in iCalendar form.
 *
 * @param int $p_ts
 * @return string e.g. 20261016T100000Z
 */
function meeting_ics_utc( int $p_ts ): string {
	return gmdate( 'Ymd\THis\Z', $p_ts );
}

/**
 * Escape a TEXT value (RFC 5545 §3.3.11).
 *
 * @param string $p_text
 * @return string
 */
function meeting_ics_text( string $p_text ): string {
	return str_replace(
		array( '\\', ';', ',', "\r\n", "\n", "\r" ),
		array( '\\\\', '\;', '\,', '\n', '\n', '\n' ),
		$p_text
	);
}

/**
 * Quote a parameter value (e.g. CN); DQUOTE and control characters are not allowed.
 *
 * @param string $p_value
 * @return string
 */
function meeting_ics_param( string $p_value ): string {
	return '"' . preg_replace( '/["\x00-\x1f\x7f]/', '', $p_value ) . '"';
}

/**
 * Fold a content line at 75 octets without splitting UTF-8 characters.
 *
 * @param string $p_line
 * @return string
 */
function meeting_ics_fold( string $p_line ): string {
	$t_out = array();
	$t_limit = 75;
	while( strlen( $p_line ) > $t_limit ) {
		$t_chunk = mb_strcut( $p_line, 0, $t_limit, 'UTF-8' );
		$t_out[] = $t_chunk;
		$p_line = substr( $p_line, strlen( $t_chunk ) );
		$t_limit = 74;   # continuation lines begin with a space
	}
	$t_out[] = $p_line;
	return implode( "\r\n ", $t_out );
}
