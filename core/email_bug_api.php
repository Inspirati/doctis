<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Email API
 *
 * @package CoreAPI
 * @subpackage EmailAPI
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses bug_api.php
 * @uses bugnote_api.php
 * @uses category_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses custom_field_api.php
 * @uses database_api.php
 * @uses email_queue_api.php
 * @uses event_api.php
 * @uses helper_api.php
 * @uses history_api.php
 * @uses lang_api.php
 * @uses logging_api.php
 * @uses project_api.php
 * @uses relationship_api.php
 * @uses sponsorship_api.php
 * @uses string_api.php
 * @uses user_api.php
 * @uses user_pref_api.php
 * @uses utility_api.php
 *
 * @uses PHPMailerAutoload.php PHPMailer library
 *
 * @noinspection PhpMissingReturnTypeInspection, PhpMissingParamTypeInspection
 */

require_once( 'email_api.php' );

require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'bugnote_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'custom_field_api.php' );
require_api( 'database_api.php' );
require_api( 'email_queue_api.php' );
require_api( 'event_api.php' );
require_api( 'helper_api.php' );
require_api( 'history_api.php' );
require_api( 'lang_api.php' );
require_api( 'logging_api.php' );
require_api( 'project_api.php' );
require_api( 'relationship_api.php' );
require_api( 'sponsorship_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );
require_api( 'user_pref_api.php' );
require_api( 'utility_api.php' );

require_once( __DIR__ . '/classes/EmailMessage.class.php' );
require_once( __DIR__ . '/classes/EmailSender.class.php' );

# PHPMailer is needed for email address validation independent of the provider used
# to send the emails.
use PHPMailer\PHPMailer\PHPMailer;

use Mantis\Exceptions\ClientException;
use VBoctor\Email\DisposableEmailChecker;

/**
 * Collect valid email recipients for email notification.
 *
 * @todo yarick123: email_collect_recipients(...) will be completely rewritten to provide additional information such as language, user access,..
 * @todo yarick123:sort recipients list by language to reduce switches between different languages
 *
 * @param int    $p_bug_id                  A bug identifier.
 * @param string $p_notify_type             Notification type.
 * @param array  $p_extra_user_ids_to_email Array of additional email addresses to notify.
 * @param int    $p_bugnote_id              The bugnote id in case of bugnote, otherwise null.
 *
 * @return array
 * @throws ClientException
 */
function email_collect_recipients( $p_bug_id, $p_notify_type, array $p_extra_user_ids_to_email = array(), $p_bugnote_id = null ) {
	$t_recipients = array();

	# add explicitly specified users
	$t_explicit_enabled = ( ON == email_notify_flag( $p_notify_type, 'explicit' ) );
	foreach ( $p_extra_user_ids_to_email as $t_user_id ) {
		if ( $t_explicit_enabled ) {
			$t_recipients[$t_user_id] = true;
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, add @U%d (explicitly specified)', $p_bug_id, $t_user_id );
		} else {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, skip @U%d (explicit disabled)', $p_bug_id, $t_user_id );
		}
	}

	# add Reporter
	$t_reporter_id = bug_get_field( $p_bug_id, 'reporter_id' );
	if( ON == email_notify_flag( $p_notify_type, 'reporter' ) ) {
		$t_recipients[$t_reporter_id] = true;
		log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, add @U%d (reporter)', $p_bug_id, $t_reporter_id );
	} else {
		log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, skip @U%d (reporter disabled)', $p_bug_id, $t_reporter_id );
	}

	# add Handler
	$t_handler_id = bug_get_field( $p_bug_id, 'handler_id' );
	if( $t_handler_id > 0 ) {
		if( ON == email_notify_flag( $p_notify_type, 'handler' ) ) {
			$t_recipients[$t_handler_id] = true;
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, add @U%d (handler)', $p_bug_id, $t_handler_id );
		} else {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, skip @U%d (handler disabled)', $p_bug_id, $t_handler_id );
		}
	}

	$t_project_id = bug_get_field( $p_bug_id, 'project_id' );

	# add users monitoring the bug
	$t_monitoring_enabled = ON == email_notify_flag( $p_notify_type, 'monitor' );
	db_param_push();
	$t_query = 'SELECT DISTINCT user_id FROM {bug_monitor} WHERE bug_id=' . db_param();
	$t_result = db_query( $t_query, array( $p_bug_id ) );

	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_user_id = $t_row['user_id'];
		if ( $t_monitoring_enabled ) {
			$t_recipients[$t_user_id] = true;
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, add @U%d (monitoring)', $p_bug_id, $t_user_id );
		} else {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, skip @U%d (monitoring disabled)', $p_bug_id, $t_user_id );
		}
	}

	# add Category Owner
	if( ON == email_notify_flag( $p_notify_type, 'category' ) ) {
		$t_category_id = bug_get_field( $p_bug_id, 'category_id' );

		if( $t_category_id > 0 ) {
			$t_category_assigned_to = category_get_field( $t_category_id, 'user_id' );

			if( $t_category_assigned_to > 0 ) {
				$t_recipients[$t_category_assigned_to] = true;
				log_event( LOG_EMAIL_RECIPIENT, sprintf( 'Issue = #%d, add Category Owner = @U%d', $p_bug_id, $t_category_assigned_to ) );
			}
		}
	}

	# add users who contributed bugnotes
	$t_notes_enabled = ( ON == email_notify_flag( $p_notify_type, 'bugnotes' ) );
	db_param_push();
	$t_query = 'SELECT DISTINCT reporter_id FROM {bugnote} WHERE bug_id = ' . db_param();
	$t_result = db_query( $t_query, array( $p_bug_id ) );
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_user_id = $t_row['reporter_id'];
		if ( $t_notes_enabled ) {
			$t_recipients[$t_user_id] = true;
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, add @U%d (note author)', $p_bug_id, $t_user_id );
		} else {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, skip @U%d (note author disabled)', $p_bug_id, $t_user_id );
		}
	}

	# add project users who meet the thresholds
	$t_bug_is_private = bug_get_field( $p_bug_id, 'view_state' ) == VS_PRIVATE;
	$t_threshold_min = email_notify_flag( $p_notify_type, 'threshold_min' );
	$t_threshold_max = email_notify_flag( $p_notify_type, 'threshold_max' );
	$t_threshold_users = project_get_all_user_rows( $t_project_id, $t_threshold_min );
	foreach( $t_threshold_users as $t_user ) {
		if( $t_user['access_level'] <= $t_threshold_max ) {
			if( !$t_bug_is_private || access_compare_level( $t_user['access_level'], config_get( 'private_bug_threshold' ) ) ) {
				$t_recipients[$t_user['id']] = true;
				log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, add @U%d (based on access level)', $p_bug_id, $t_user['id'] );
			}
		}
	}

	# add users as specified by plugins
	$t_recipients_include_data = event_signal( 'EVENT_NOTIFY_USER_INCLUDE', array( $p_bug_id, $p_notify_type ) );
	foreach( $t_recipients_include_data as $t_plugin => $t_recipients_include_data2 ) {
		foreach( $t_recipients_include_data2 as $t_recipients_included ) {
			# only handle if we get an array from the callback
			if( is_array( $t_recipients_included ) ) {
				foreach( $t_recipients_included as $t_user_id ) {
					$t_recipients[$t_user_id] = true;
					log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, add @U%d (by %s plugin)', $p_bug_id, $t_user_id, $t_plugin );
				}
			}
		}
	}

	# FIXME: the value of $p_notify_type could at this stage be either a status
	# or a built-in actions such as 'owner and 'sponsor'. We have absolutely no
	# idea whether 'new' is indicating a new bug has been filed, or if the
	# status of an existing bug has been changed to 'new'. Therefore it is best
	# to just assume built-in actions have precedence over status changes.
	switch( $p_notify_type ) {
		case 'new':
		case 'feedback': # This isn't really a built-in action (delete me!)
		case 'reopened':
		case 'resolved':
		case 'closed':
		case 'bugnote':
			$t_pref_field = 'email_on_' . $p_notify_type;
			if( !$p_bugnote_id ) {
				$p_bugnote_id = bugnote_get_latest_id( $p_bug_id );
			}
			break;
		case 'owner':
			# The email_on_assigned notification type is now effectively
			# email_on_change_of_handler.
			$t_pref_field = 'email_on_assigned';
			break;
		case 'deleted':
		case 'updated':
		case 'sponsor':
		case 'relation':
		case 'monitor':
		case 'priority': # This is never used, but exists in the database!
			# Issue #19459 these notification actions are not actually implemented
			# in the database and therefore aren't adjustable on a per-user
			# basis! The exception is 'monitor' that makes no sense being a
			# customisable per-user preference.
		default:
			# Anything not built-in is probably going to be a status
			$t_pref_field = 'email_on_status';
			break;
	}

	# @TODO we could optimize by modifying user_cache() to take an array
	#  of user ids so we could pull them all in.  We'll see if it's necessary
	$t_final_recipients = array();

	$t_bug = bug_get( $p_bug_id );
	$t_user_ids = array_keys( $t_recipients );
	user_cache_array_rows( $t_user_ids );
	user_pref_cache_array_rows( $t_user_ids );
	user_pref_cache_array_rows( $t_user_ids, $t_bug->project_id );

	# Check whether users should receive the emails
	# and put email address to $t_recipients[user_id]
	foreach( $t_recipients as $t_id => $t_ignore ) {
		# Possibly eliminate the current user
		if( ( auth_get_current_user_id() == $t_id ) && ( OFF == config_get( 'email_receive_own' ) ) ) {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, drop @U%d (own action)', $p_bug_id, $t_id );
			continue;
		}

		# Eliminate users who don't exist anymore or who are disabled
		if( !user_exists( $t_id ) || !user_is_enabled( $t_id ) ) {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, drop @U%d (user disabled)', $p_bug_id, $t_id );
			continue;
		}

		# Exclude users who have this notification type turned off
		if( $t_pref_field ) {
			$t_notify = user_pref_get_pref( $t_id, $t_pref_field );
			if( OFF == $t_notify ) {
				log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, drop @U%d (pref %s off)', $p_bug_id, $t_id, $t_pref_field );
				continue;
			} else {
				# Users can define the severity of an issue before they are emailed for
				# each type of notification
				$t_min_sev_pref_field = $t_pref_field . '_min_severity';
				$t_min_sev_notify = user_pref_get_pref( $t_id, $t_min_sev_pref_field );
				$t_bug_severity = bug_get_field( $p_bug_id, 'severity' );

				if( $t_bug_severity < $t_min_sev_notify ) {
					log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, drop @U%d (pref threshold)', $p_bug_id, $t_id );
					continue;
				}
			}
		}

		# exclude users who don't have at least viewer access to the bug,
		# or who can't see bugnotes if the last update included a bugnote
		$t_view_bug_threshold = config_get( 'view_bug_threshold', null, $t_id, $t_bug->project_id );
		if(   !access_has_bug_level( $t_view_bug_threshold, $p_bug_id, $t_id )
		   || (   $p_bugnote_id
			   && !access_has_bugnote_level( $t_view_bug_threshold, $p_bugnote_id, $t_id )
			  )
		) {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, drop @U%d (access level)', $p_bug_id, $t_id );
			continue;
		}

		# check to exclude users as specified by plugins
		$t_recipient_exclude_data = event_signal( 'EVENT_NOTIFY_USER_EXCLUDE', array( $p_bug_id, $p_notify_type, $t_id ) );
		$t_exclude = false;
		foreach( $t_recipient_exclude_data as $t_plugin => $t_recipient_exclude_data2 ) {
			foreach( $t_recipient_exclude_data2 as $t_recipient_excluded ) {
				# exclude if any plugin returns true (excludes the user)
				if( $t_recipient_excluded ) {
					$t_exclude = true;
					log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, drop @U%d (by %s plugin)', $p_bug_id, $t_id, $t_plugin );
				}
			}
		}

		# user was excluded by a plugin
		if( $t_exclude ) {
			continue;
		}

		# Finally, let's get their emails, if they've set one
		$t_email = user_get_email( $t_id );
		if( is_blank( $t_email ) ) {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, drop @U%d (no email address)', $p_bug_id, $t_id );
		} else {
			# @TODO we could check the emails for validity again but I think it would be too slow
			$t_final_recipients[$t_id] = $t_email;
		}
	}

	return $t_final_recipients;
}

/**
 * Send a generic email.
 *
 * @param int    $p_bug_id                   A bug identifier.
 * @param string $p_notify_type              Notification type, used to check who
 *                                           should get notified of such event.
 * @param int    $p_message_id               Message identifier to be translated
 *                                           and included at the top of the email message.
 * @param array  $p_header_optional_params   Optional Parameters for $p_message_id
 *                                           (default none).
 * @param array   $p_extra_user_ids_to_email Array of additional users to email.
 *
 * @return void
 * @throws ClientException
 */
function email_generic( $p_bug_id, $p_notify_type, $p_message_id = null, array $p_header_optional_params = [], array $p_extra_user_ids_to_email = array() ) {
	# @todo yarick123: email_collect_recipients(...) will be completely rewritten to provide additional information such as language, user access,..
	# @todo yarick123:sort recipients list by language to reduce switches between different languages
	$t_recipients = email_collect_recipients( $p_bug_id, $p_notify_type, $p_extra_user_ids_to_email );
	email_generic_to_recipients( $p_bug_id, $p_notify_type, $t_recipients, $p_message_id, $p_header_optional_params );
}

/**
 * Sends a generic email to the specific set of recipients.
 *
 * @param int     $p_bug_id                 A bug identifier
 * @param string  $p_notify_type            Notification type
 * @param array   $p_recipients             Array of recipients (key: user id, value: email address)
 * @param int     $p_message_id             Message identifier
 * @param array   $p_header_optional_params Optional Parameters (default none)
 *
 * @return void
 * @throws ClientException
 */
function email_generic_to_recipients( int $p_bug_id, string $p_notify_type, array $p_recipients, $p_message_id = null, array $p_header_optional_params = [] ) {
	if( empty( $p_recipients ) ) {
		return;
	}

	if( OFF == config_get( 'enable_email_notification' ) ) {
		return;
	}

	ignore_user_abort( true );

	bugnote_get_all_bugnotes( $p_bug_id );

	$t_project_id = bug_get_field( $p_bug_id, 'project_id' );

	# send email to every recipient
	foreach( $p_recipients as $t_user_id => $t_user_email ) {
		log_event( LOG_EMAIL_VERBOSE, 'Issue = #%d, Type = %s, Msg = \'%s\', User = @U%d, Email = \'%s\'.', $p_bug_id, $p_notify_type, $p_message_id, $t_user_id, $t_user_email );

		# load (push) user language here as build_visible_bug_data assumes current language
		lang_push( user_pref_get_language( $t_user_id, $t_project_id ) );

		$t_visible_bug_data = email_build_visible_bug_data( $t_user_id, $p_bug_id, $p_message_id );
		email_bug_info_to_one_user( $t_visible_bug_data, $p_message_id, $t_user_id, $p_header_optional_params );

		lang_pop();
	}
}

/**
 * Send notices that a user is now monitoring the bug.
 *
 * Typically, this will only be sent when the added user is not the logged-in
 * user.  This is assuming that receive own notifications is OFF (default).
 *
 * @param int $p_bug_id  A valid bug identifier.
 * @param int $p_user_id A valid user identifier.
 *
 * @return void
 * @throws ClientException
 */
function email_monitor_added( $p_bug_id, $p_user_id ) {
	log_event( LOG_EMAIL, 'Issue #%d monitored by user @U%d', $p_bug_id, $p_user_id );

	$t_opt = array();
	$t_opt[] = bug_format_id( $p_bug_id );
	$t_opt[] = user_get_name( $p_user_id );

	email_generic( $p_bug_id, 'monitor', 'email_notification_title_for_action_monitor', $t_opt, array( $p_user_id ) );
}

/**
 * Send notices when a relationship is ADDED.
 *
 * @param int  $p_bug_id           A bug identifier.
 * @param int  $p_related_bug_id   Related bug identifier.
 * @param int  $p_rel_type         Relationship type.
 * @param bool $p_email_for_source Should an email be triggered for source issue?
 *
 * @return void
 * @throws ClientException
 */
function email_relationship_added( $p_bug_id, $p_related_bug_id, $p_rel_type, $p_email_for_source ) {
	global $g_relationships;

	if( !isset( $g_relationships[$p_rel_type] ) ) {
		trigger_error( ERROR_RELATIONSHIP_NOT_FOUND, ERROR );
	}

	$t_rev_rel_type = relationship_get_complementary_type( $p_rel_type );
	if( !isset( $g_relationships[$t_rev_rel_type] ) ) {
		trigger_error( ERROR_RELATIONSHIP_NOT_FOUND, ERROR );
	}

	log_event(
		LOG_EMAIL,
		'Issue #%d relationship added to issue #%d (relationship type %s)',
		$p_bug_id,
		$p_related_bug_id,
		$g_relationships[$p_rel_type]['#description'] );

	# Source issue email notification. Should use the specified relationship message
	if( $p_email_for_source ) {
		$t_message_id = $g_relationships[$p_rel_type]['#notify_added'];
		email_relationship_send( $p_bug_id, $p_related_bug_id, $t_message_id );
	}

	# Destination issue email notification. Should use the relationship reverse message
	$t_message_id = $g_relationships[$t_rev_rel_type]['#notify_added'];
	email_relationship_send( $p_related_bug_id, $p_bug_id, $t_message_id );
}

/**
 * Filter recipients to remove ones that don't have access to the specified bug.
 *
 * @param int   $p_bug_id     The bug id
 * @param array $p_recipients The recipients array (key: id, value: email)
 *
 * @return array The filtered list of recipients in same format
 *
 * @access private
 */
function email_filter_recipients_for_bug( $p_bug_id, array $p_recipients ) {
	$t_view_bug_threshold = config_get( 'view_bug_threshold' );

	return array_filter( $p_recipients,
		function( $t_recipient_id ) use ( $t_view_bug_threshold, $p_bug_id ) {
			return access_has_bug_level( $t_view_bug_threshold, $p_bug_id, $t_recipient_id );
		},
		ARRAY_FILTER_USE_KEY
	);
}

/**
 * Helper function to collect recipients and send relationship notifications.
 *
 * @param int    $p_bug_id
 * @param int    $p_related_bug_id
 * @param string $p_message_id
 *
 * @access private
 * @throws ClientException
 */
function email_relationship_send( int $p_bug_id, int $p_related_bug_id, $p_message_id ) {
	$t_recipients = email_collect_recipients( $p_bug_id, 'relation' );

	# Recipient has to have access to both bugs to get the notification.
	$t_recipients = email_filter_recipients_for_bug( $p_bug_id, $t_recipients );
	$t_recipients = email_filter_recipients_for_bug( $p_related_bug_id, $t_recipients );

	$t_opt = [ bug_format_id( $p_related_bug_id ) ];

	email_generic_to_recipients( $p_bug_id, 'relation', $t_recipients, $p_message_id, $t_opt );
}

/**
 * Send notices when a relationship is DELETED.
 *
 * @param int $p_bug_id                  A bug identifier.
 * @param int $p_related_bug_id          Related bug identifier.
 * @param int $p_rel_type                Relationship type.
 * @param int $p_skip_email_for_issue_id Skip email for specified issue, otherwise 0.
 *
 * @return void
 * @throws ClientException
 */
function email_relationship_deleted( $p_bug_id, $p_related_bug_id, $p_rel_type, $p_skip_email_for_issue_id = 0 ) {
	global $g_relationships;
	if( !isset( $g_relationships[$p_rel_type] ) ) {
		trigger_error( ERROR_RELATIONSHIP_NOT_FOUND, ERROR );
	}

	$t_rev_rel_type = relationship_get_complementary_type( $p_rel_type );
	if( !isset( $g_relationships[$t_rev_rel_type] ) ) {
		trigger_error( ERROR_RELATIONSHIP_NOT_FOUND, ERROR );
	}

	log_event(
		LOG_EMAIL,
		'Issue #%d relationship to issue #%d (relationship type %s) deleted.',
		$p_bug_id,
		$p_related_bug_id,
		$g_relationships[$p_rel_type]['#description'] );

	# Source issue email notification. Should use the specified relationship message
	if( $p_bug_id != $p_skip_email_for_issue_id ) {
		$t_message_id = $g_relationships[$p_rel_type]['#notify_deleted'];
		email_relationship_send( $p_bug_id, $p_related_bug_id, $t_message_id );
	}

	# Destination issue email notification. Should use the relationship reverse message
	if( $p_bug_id != $p_related_bug_id && bug_exists( $p_related_bug_id) ) {
		$t_message_id = $g_relationships[$t_rev_rel_type]['#notify_deleted'];
		email_relationship_send( $p_related_bug_id, $p_bug_id, $t_message_id );
	}
}

/**
 * Email related issues when a bug is deleted.
 *
 * This should be called before the bug is deleted.
 *
 * @param int $p_bug_id The id of the bug to be deleted.
 *
 * @return void
 * @throws ClientException
 */
function email_relationship_bug_deleted( $p_bug_id ) {
	$t_ignore = false;
	$t_relationships = relationship_get_all( $p_bug_id, $t_ignore );
	if( empty( $t_relationships ) ) {
		return;
	}

	log_event( LOG_EMAIL, sprintf( 'Issue #%d has been deleted, sending notifications to related issues', $p_bug_id ) );

	foreach( $t_relationships as $t_relationship ) {
		$t_related_bug_id = $p_bug_id == $t_relationship->src_bug_id ?
			$t_relationship->dest_bug_id : $t_relationship->src_bug_id;

		$t_opt = array();
		$t_opt[] = bug_format_id( $p_bug_id );
		email_generic( $t_related_bug_id, 'handler', 'email_notification_title_for_action_related_issue_deleted', $t_opt );
	}
}

/**
 * Send notices to all the handlers of the parent bugs when a child bug is RESOLVED.
 *
 * @param int $p_bug_id A bug identifier.
 *
 * @return void
 * @throws ClientException
 */
function email_relationship_child_resolved( $p_bug_id ) {
	email_relationship_child_resolved_closed( $p_bug_id, 'email_notification_title_for_action_relationship_child_resolved' );
}

/**
 * Send notices to all the handlers of the parent bugs when a child bug is CLOSED.
 *
 * @param int $p_bug_id A bug identifier.
 *
 * @return void
 * @throws ClientException
 */
function email_relationship_child_closed( $p_bug_id ) {
	email_relationship_child_resolved_closed( $p_bug_id, 'email_notification_title_for_action_relationship_child_closed' );
}

/**
 * Send notices to all the handlers of the parent bugs still open when a child bug is resolved/closed.
 *
 * @param int $p_bug_id     A bug identifier.
 * @param int $p_message_id A message identifier.
 *
 * @return void
 * @throws ClientException
 */
function email_relationship_child_resolved_closed( $p_bug_id, $p_message_id ) {
	# retrieve all the relationships in which the bug is the destination bug
	$t_relationship = relationship_get_all_dest( $p_bug_id );
	$t_relationship_count = count( $t_relationship );
	if( $t_relationship_count == 0 ) {
		# no parent bug found
		return;
	}

	if( $p_message_id == 'email_notification_title_for_action_relationship_child_closed' ) {
		log_event( LOG_EMAIL, sprintf( 'Issue #%d child issue closed', $p_bug_id ) );
	} else {
		log_event( LOG_EMAIL, sprintf( 'Issue #%d child issue resolved', $p_bug_id ) );
	}

	for( $i = 0;$i < $t_relationship_count;$i++ ) {
		if( $t_relationship[$i]->type == BUG_DEPENDANT ) {
			$t_src_bug_id = $t_relationship[$i]->src_bug_id;
			$t_status = bug_get_field( $t_src_bug_id, 'status' );
			if( $t_status < config_get( 'bug_resolved_status_threshold' ) ) {

				# sent the notification just for parent bugs not resolved/closed
				$t_opt = array();
				$t_opt[] = bug_format_id( $p_bug_id );
				email_generic( $t_src_bug_id, 'handler', $p_message_id, $t_opt );
			}
		}
	}
}

/**
 * Send notices when a bug is sponsored.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_sponsorship_added( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d sponsorship added', $p_bug_id ) );
	email_generic( $p_bug_id, 'sponsor', 'email_notification_title_for_action_sponsorship_added' );
}

/**
 * Send notices when a sponsorship is modified.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_sponsorship_updated( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d sponsorship updated', $p_bug_id ) );
	email_generic( $p_bug_id, 'sponsor', 'email_notification_title_for_action_sponsorship_updated' );
}

/**
 * Send notices when a sponsorship is deleted.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_sponsorship_deleted( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d sponsorship removed', $p_bug_id ) );
	email_generic( $p_bug_id, 'sponsor', 'email_notification_title_for_action_sponsorship_deleted' );
}

/**
 * Send notices when a new bug is added.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_bug_added( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d reported', $p_bug_id ) );
	email_generic( $p_bug_id, 'new', 'email_notification_title_for_action_bug_submitted' );
}

/**
 * Send notifications for bug update.
 *
 * @param int $p_bug_id The bug id.
 *
 * @return void
 * @throws ClientException
 */
function email_bug_updated( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d updated', $p_bug_id ) );
	email_generic( $p_bug_id, 'updated', 'email_notification_title_for_action_bug_updated' );
}

/**
 * Generates md5 used in "In-Reply-To" header for emails.
 *
 * @param int $p_bug_id
 * @param int $p_date_submitted
 *
 * @return string
 */
// function email_generate_bug_md5( $p_bug_id, $p_date_submitted ) {
// 	return md5( $p_bug_id . $p_date_submitted );
// }

/**
 * Send notices when a new bugnote.
 *
 * @param int   $p_bugnote_id       The bugnote id.
 * @param array $p_files            The array of file information (keys: name, size)
 * @param array $p_exclude_user_ids The id of users to exclude.
 *
 * @return void
 * @throws ClientException
 */
function email_bugnote_add( $p_bugnote_id, $p_files = array(), $p_exclude_user_ids = array() ) {
	if( OFF == config_get( 'enable_email_notification' ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'email notifications disabled.' );
		return;
	}

	ignore_user_abort( true );

	$t_bugnote = bugnote_get( $p_bugnote_id );

	log_event( LOG_EMAIL, sprintf( 'Note ~%d added to issue #%d', $p_bugnote_id, $t_bugnote->bug_id ) );

	$t_project_id = bug_get_field( $t_bugnote->bug_id, 'project_id' );
	$t_date_submitted = bug_get_field( $t_bugnote->bug_id, 'date_submitted' );
	$t_separator = config_get( 'email_separator2' );
	$t_time_tracking_access_threshold = config_get( 'time_tracking_view_threshold' );
	$t_view_attachments_threshold = config_get( 'view_attachments_threshold' );
	$t_message_id = 'email_notification_title_for_action_bugnote_submitted';

	$t_subject = email_build_subject( $t_bugnote->bug_id );

	$t_recipients = email_collect_recipients( $t_bugnote->bug_id, 'bugnote', /* extra_user_ids */ array(), $p_bugnote_id );
	$t_recipients_verbose = array();

	# send email to every recipient
	foreach( $t_recipients as $t_user_id => $t_user_email ) {
		if( in_array( $t_user_id, $p_exclude_user_ids ) ) {
			log_event( LOG_EMAIL_RECIPIENT, 'Issue = #%d, Note = ~%d, Type = %s, Msg = \'%s\', User = @U%d excluded, Email = \'%s\'.',
				$t_bugnote->bug_id, $p_bugnote_id, 'bugnote', 'email_notification_title_for_action_bugnote_submitted', $t_user_id, $t_user_email );
			continue;
		}

		# Load this here per user to allow overriding this per user, or even per user per project
		if( config_get( 'email_notifications_verbose', /* default */ null, $t_user_id, $t_project_id ) == ON ) {
			$t_recipients_verbose[$t_user_id] = $t_user_email;
			continue;
		}

		log_event( LOG_EMAIL_VERBOSE, 'Issue = #%d, Note = ~%d, Type = %s, Msg = \'%s\', User = @U%d, Email = \'%s\'.',
			$t_bugnote->bug_id, $p_bugnote_id, 'bugnote', $t_message_id, $t_user_id, $t_user_email );

		# load (push) user language
		lang_push( user_pref_get_language( $t_user_id, $t_project_id ) );

		$t_message = lang_get( 'email_notification_title_for_action_bugnote_submitted' ) . "\n\n";

		$t_show_time_tracking = access_has_bug_level( $t_time_tracking_access_threshold, $t_bugnote->bug_id, $t_user_id );
		$t_formatted_note = email_format_bugnote( $t_bugnote, $t_project_id, $t_show_time_tracking, $t_separator );
		$t_message .= trim( $t_formatted_note ) . "\n";
		$t_message .= $t_separator . "\n";

		# Files attached
		if( count( $p_files ) > 0 &&
			access_has_bug_level( $t_view_attachments_threshold, $t_bugnote->bug_id, $t_user_id ) ) {
			$t_message .= lang_get( 'bugnote_attached_files' ) . "\n";

			foreach( $p_files as $t_file ) {
				$t_message .= '- ' . $t_file['name'] . ' (' . number_format( $t_file['size'] ) .
					' ' . lang_get( 'bytes' ) . ")\n";
			}

			$t_message .= $t_separator . "\n";
		}

		$t_contents = $t_message . "\n";

		$t_mail_headers = [
			'In-Reply-To' => email_generate_bug_md5( $t_bugnote->bug_id, $t_date_submitted )
		];

		email_store( $t_user_email, $t_subject, $t_contents, $t_mail_headers );

		log_event( LOG_EMAIL_VERBOSE, 'queued bugnote email for note ~' . $p_bugnote_id .
			' issue #' . $t_bugnote->bug_id . ' by U' . $t_user_id );

		lang_pop();
	}

	# Send emails out for users that select verbose notifications
	email_generic_to_recipients(
		$t_bugnote->bug_id,
		'bugnote',
		$t_recipients_verbose,
		$t_message_id
	);
}

/**
 * Send notices when a bug is RESOLVED.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_resolved( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d resolved', $p_bug_id ) );
	email_generic( $p_bug_id, 'resolved', 'email_notification_title_for_status_bug_resolved' );
}

/**
 * Send notices when a bug is CLOSED.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_close( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d closed', $p_bug_id ) );
	email_generic( $p_bug_id, 'closed', 'email_notification_title_for_status_bug_closed' );
}

/**
 * Send notices when a bug is REOPENED.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_bug_reopened( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d reopened', $p_bug_id ) );
	email_generic( $p_bug_id, 'reopened', 'email_notification_title_for_action_bug_reopened' );
}

/**
 * Send notices when a bug handler is changed.
 *
 * @param int $p_bug_id
 * @param int $p_prev_handler_id
 * @param int $p_new_handler_id
 *
 * @return void
 * @throws ClientException
 */
function email_owner_changed($p_bug_id, $p_prev_handler_id, $p_new_handler_id ) {
	if ( $p_prev_handler_id == 0 && $p_new_handler_id != 0 ) {
		log_event( LOG_EMAIL, sprintf( 'Issue #%d assigned to user @U%d.', $p_bug_id, $p_new_handler_id ) );
	} else if ( $p_prev_handler_id != 0 && $p_new_handler_id == 0 ) {
		log_event( LOG_EMAIL, sprintf( 'Issue #%d is no longer assigned to @U%d.', $p_bug_id, $p_prev_handler_id ) );
	} else {
		log_event(
			LOG_EMAIL,
			sprintf(
				'Issue #%d is assigned to @U%d instead of @U%d.',
				$p_bug_id,
				$p_new_handler_id,
				$p_prev_handler_id )
		);
	}

	$t_message_id = $p_new_handler_id == NO_USER ?
			'email_notification_title_for_action_bug_unassigned' :
			'email_notification_title_for_action_bug_assigned';

	$t_extra_user_ids_to_email = array();
	if ( $p_prev_handler_id !== NO_USER && $p_prev_handler_id != $p_new_handler_id ) {
		if ( email_notify_flag( 'owner', 'handler' ) == ON ) {
			$t_extra_user_ids_to_email[] = $p_prev_handler_id;
		}
	}

	email_generic( $p_bug_id, 'owner', $t_message_id, /* headers */ [], $t_extra_user_ids_to_email );
}

/**
 * Send notifications when bug status is changed.
 *
 * @param int    $p_bug_id           The bug id
 * @param string $p_new_status_label The new status label.
 *
 * @return void
 * @throws ClientException
 */
function email_bug_status_changed( $p_bug_id, $p_new_status_label ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d status changed', $p_bug_id ) );
	email_generic( $p_bug_id, $p_new_status_label, 'email_notification_title_for_status_bug_' . $p_new_status_label );
}

/**
 * Send notices when a bug is DELETED.
 *
 * @param int $p_bug_id
 *
 * @return void
 * @throws ClientException
 */
function email_bug_deleted( $p_bug_id ) {
	log_event( LOG_EMAIL, sprintf( 'Issue #%d deleted', $p_bug_id ) );
	email_generic( $p_bug_id, 'deleted', 'email_notification_title_for_action_bug_deleted' );
}

/**
 * Formats the subject correctly.
 *
 * We include the project name, bug id, and summary.
 *
 * @param int $p_bug_id A bug identifier.
 *
 * @return string
 * @throws ClientException
 */
function email_build_subject( $p_bug_id ) {
	# grab the project name
	$p_project_name = project_get_field( bug_get_field( $p_bug_id, 'project_id' ), 'name' );

	# grab the subject (summary)
	$p_subject = bug_get_field( $p_bug_id, 'summary' );

	# pad the bug id with zeros
	$t_bug_id = bug_format_id( $p_bug_id );

	# build standard subject string
	$t_email_subject = '[' . $p_project_name . ' ' . $t_bug_id . ']: ' . $p_subject;

	# update subject as defined by plugins
	return event_signal( 'EVENT_DISPLAY_EMAIL_BUILD_SUBJECT', $t_email_subject, array( $p_bug_id ) );
}

/**
 * Send a bug reminder to the given user(s).
 *
 * @param int|array $p_recipients User id or list of user ids array to send reminder to.
 * @param int       $p_bug_id     Issue for which the reminder is sent.
 * @param string    $p_message    Optional message to add to the e-mail.
 *
 * @return array List of users ids to whom the reminder e-mail was actually sent
 * @throws ClientException
 */
function email_bug_reminder( $p_recipients, $p_bug_id, $p_message ) {
	if( OFF == config_get( 'enable_email_notification' ) ) {
		return array();
	}

	if( !is_array( $p_recipients ) ) {
		$p_recipients = array(
			$p_recipients,
		);
	}

	$t_project_id = bug_get_field( $p_bug_id, 'project_id' );
	$t_sender_id = auth_get_current_user_id();
	$t_sender = user_get_name( $t_sender_id );

	$t_subject = email_build_subject( $p_bug_id );
	$t_date = date( config_get( 'normal_date_format' ) );

	$t_result = array();
	foreach( $p_recipients as $t_recipient ) {
		lang_push( user_pref_get_language( $t_recipient, $t_project_id ) );

		$t_email = user_get_email( $t_recipient );

		if( access_has_project_level( config_get( 'show_user_email_threshold' ), $t_project_id, $t_recipient ) ) {
			$t_sender_email = ' <' . user_get_email( $t_sender_id ) . '>';
		} else {
			$t_sender_email = '';
		}
		$t_header = "\n" . lang_get( 'on_date' ) . ' ' . $t_date . ', ' . $t_sender . ' ' . $t_sender_email . lang_get( 'sent_you_this_reminder_about' ) . ': ' . "\n\n";
		$t_contents = $t_header . string_get_bug_view_url_with_fqdn( $p_bug_id ) . " \n\n" . $p_message;

		$t_id = email_store( $t_email, $t_subject, $t_contents );
		if( $t_id !== null ) {
			$t_result[] = $t_recipient;
		}
		log_event( LOG_EMAIL_VERBOSE, 'queued reminder email ' . $t_id . ' for U' . $t_recipient );

		lang_pop();
	}

	return $t_result;
}

/**
 * Send a notification to users that were mentioned in an issue.
 *
 * @param int    $p_bug_id                   Issue for which the reminder is sent.
 * @param array  $p_mention_user_ids         User id or list of user ids array.
 * @param string $p_message                  Optional message to add to the e-mail.
 * @param array  $p_removed_mention_user_ids The users that were removed due to lack of access.
 *
 * @return array        List of users ids to whom the mentioned e-mail were actually sent
 * @throws ClientException
 */
function email_user_mention( $p_bug_id, $p_mention_user_ids, $p_message, $p_removed_mention_user_ids = array() ) {
	if( OFF == config_get( 'enable_email_notification' ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'email notifications disabled.' );
		return array();
	}

	$t_project_id = bug_get_field( $p_bug_id, 'project_id' );
	$t_sender_id = auth_get_current_user_id();
	$t_sender = user_get_name( $t_sender_id );

	$t_subject = email_build_subject( $p_bug_id );
	$t_date = date( config_get( 'normal_date_format' ) );
	$t_user_id = auth_get_current_user_id();
	$t_users_processed = array();

	foreach( $p_removed_mention_user_ids as $t_removed_mention_user_id ) {
		log_event( LOG_EMAIL_VERBOSE, 'skipped mention email for U' . $t_removed_mention_user_id . ' (no access to issue or note).' );
	}

	$t_result = array();
	foreach( $p_mention_user_ids as $t_mention_user_id ) {
		# Don't trigger mention emails for self mentions
		if( $t_mention_user_id == $t_user_id ) {
			log_event( LOG_EMAIL_VERBOSE, 'skipped mention email for U' . $t_mention_user_id . ' (self-mention).' );
			continue;
		}

		# Don't process a user more than once
		if( isset( $t_users_processed[$t_mention_user_id] ) ) {
			continue;
		}

		$t_users_processed[$t_mention_user_id] = true;

		# Don't email mention notifications to disabled users.
		if( !user_is_enabled( $t_mention_user_id ) ) {
			continue;
		}

		lang_push( user_pref_get_language( $t_mention_user_id, $t_project_id ) );

		$t_email = user_get_email( $t_mention_user_id );

		if( access_has_project_level( config_get( 'show_user_email_threshold' ), $t_project_id, $t_mention_user_id ) ) {
			$t_sender_email = ' <' . user_get_email( $t_sender_id ) . '> ';
		} else {
			$t_sender_email = '';
		}

		$t_complete_subject = sprintf( lang_get( 'mentioned_in' ), $t_subject );
		$t_header = "\n" . lang_get( 'on_date' ) . ' ' . $t_date . ', ' . $t_sender . ' ' . $t_sender_email . lang_get( 'mentioned_you' ) . "\n\n";
		$t_contents = $t_header . string_get_bug_view_url_with_fqdn( $p_bug_id ) . " \n\n" . $p_message;

		$t_id = email_store( $t_email, $t_complete_subject, $t_contents );
		if( $t_id !== null ) {
			$t_result[] = $t_mention_user_id;
		}

		log_event( LOG_EMAIL_VERBOSE, 'queued mention email ' . $t_id . ' for U' . $t_mention_user_id );

		lang_pop();
	}

	return $t_result;
}

/**
 * Send bug info to given user.
 *
 * @param array      $p_visible_bug_data       Array of bug data information.
 * @param string     $p_message_id             A message identifier.
 * @param int        $p_user_id                A valid user identifier.
 * @param array|null $p_header_optional_params Array of additional email headers.
 *
 * @return void
 * @throws ClientException
 */
function email_bug_info_to_one_user( array $p_visible_bug_data, string $p_message_id, int $p_user_id, array $p_header_optional_params = [] ) {
	$t_user_email = user_get_email( $p_user_id );

	# check whether email should be sent
	# @@@ can be email field empty? if yes - then it should be handled here
	if( ON !== config_get( 'enable_email_notification' ) || is_blank( $t_user_email ) ) {
		return;
	}

	# build subject
	$t_subject = email_build_subject( $p_visible_bug_data['email_bug'] );

	# build message
	$t_message = lang_get_defaulted( $p_message_id );

	if( $p_header_optional_params ) {
		$t_message = vsprintf( $t_message, $p_header_optional_params );
	}

	if( ( $t_message !== null ) && ( !is_blank( $t_message ) ) ) {
		$t_message .= " \n";
	}

	$t_message .= email_format_bug_message( $p_visible_bug_data );

	# build headers
	$t_bug_id = $p_visible_bug_data['email_bug'];
	$t_message_md5 = email_generate_bug_md5( $t_bug_id, $p_visible_bug_data['email_date_submitted'] );
	$t_mail_headers = array(
		'keywords' => $p_visible_bug_data['set_category'],
	);
	if( $p_message_id == 'email_notification_title_for_action_bug_submitted' ) {
		$t_mail_headers['Message-ID'] = $t_message_md5;
	} else {
		$t_mail_headers['In-Reply-To'] = $t_message_md5;
	}

	# send mail
	email_store( $t_user_email, $t_subject, $t_message, $t_mail_headers );
}

/**
 * Generates a formatted note to be used in email notifications.
 *
 * @param BugnoteData $p_bugnote              The bugnote object.
 * @param int         $p_project_id           The project id
 * @param bool        $p_show_time_tracking   True to show time tracking, false otherwise.
 * @param string      $p_horizontal_separator The horizontal line separator to use.
 * @param string      $p_date_format          The date format to use.
 *
 * @return string The formatted note.
 */
function email_format_bugnote( $p_bugnote, $p_project_id, $p_show_time_tracking, $p_horizontal_separator, $p_date_format = null ) {
	$t_date_format = ( $p_date_format === null ) ? config_get( 'normal_date_format' ) : $p_date_format;

	$t_last_modified = date( $t_date_format, $p_bugnote->last_modified );

	$t_formatted_bugnote_id = bugnote_format_id( $p_bugnote->id );
	$t_bugnote_link = string_process_bugnote_link( config_get( 'bugnote_link_tag' ) . $p_bugnote->id, false, false, true );

	if( $p_show_time_tracking && $p_bugnote->time_tracking > 0 ) {
		$t_time_tracking = ' ' . lang_get( 'time_tracking' ) . ' ' . db_minutes_to_hhmm( $p_bugnote->time_tracking ) . "\n";
	} else {
		$t_time_tracking = '';
	}

	if( user_exists( $p_bugnote->reporter_id ) ) {
		$t_access_level = access_get_project_level( $p_project_id, $p_bugnote->reporter_id );
		$t_access_level_string = ' (' . access_level_get_string( $t_access_level ) . ')';
	} else {
		$t_access_level_string = '';
	}

	$t_private = ( $p_bugnote->view_state == VS_PUBLIC ) ? '' : ' (' . lang_get( 'private' ) . ')';

	$t_string = ' (' . $t_formatted_bugnote_id . ') ' . user_get_name( $p_bugnote->reporter_id ) .
		$t_access_level_string . ' - ' . $t_last_modified . $t_private . "\n" .
		$t_time_tracking . ' ' . $t_bugnote_link;

	$t_message  = $p_horizontal_separator . " \n";
	$t_message .= $t_string . " \n";
	$t_message .= $p_horizontal_separator . " \n";
	$t_message .= $p_bugnote->note . " \n";

	return $t_message;
}

/**
 * Build the bug info part of the message.
 *
 * @param array $p_visible_bug_data Bug data array to format.
 *
 * @return string
 */
function email_format_bug_message( array $p_visible_bug_data ) {
	$t_normal_date_format = config_get( 'normal_date_format' );
	$t_complete_date_format = config_get( 'complete_date_format' );

	$t_email_separator1 = config_get( 'email_separator1' );
	$t_email_separator2 = config_get( 'email_separator2' );
	$t_email_padding_length = config_get( 'email_padding_length' );

	$p_visible_bug_data['email_date_submitted'] = date( $t_complete_date_format, $p_visible_bug_data['email_date_submitted'] );
	$p_visible_bug_data['email_last_modified'] = date( $t_complete_date_format, $p_visible_bug_data['email_last_modified'] );

	$t_message = $t_email_separator1 . " \n";

	if( isset( $p_visible_bug_data['email_bug_view_url'] ) ) {
		$t_message .= $p_visible_bug_data['email_bug_view_url'] . " \n";
		$t_message .= $t_email_separator1 . " \n";
	}

	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_reporter' );
	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_handler' );
	$t_message .= $t_email_separator1 . " \n";
	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_project' );
	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_bug' );
	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_category' );

	if( isset( $p_visible_bug_data['email_tag'] ) ) {
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_tag' );
	}

	if ( isset( $p_visible_bug_data[ 'email_reproducibility' ] ) ) {
		$p_visible_bug_data['email_reproducibility'] = get_enum_element( 'reproducibility', $p_visible_bug_data['email_reproducibility'] );
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_reproducibility' );
	}
		
	if ( isset( $p_visible_bug_data[ 'email_severity' ] ) ) {
		$p_visible_bug_data['email_severity'] = get_enum_element( 'severity', $p_visible_bug_data['email_severity'] );
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_severity' );
	}

	if ( isset( $p_visible_bug_data[ 'email_priority' ] ) ) {
		$p_visible_bug_data['email_priority'] = get_enum_element( 'priority', $p_visible_bug_data['email_priority'] );
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_priority' );
	}

	if ( isset( $p_visible_bug_data[ 'email_status' ] ) ) {
		$t_status = $p_visible_bug_data['email_status'];
		$p_visible_bug_data['email_status'] = get_enum_element( 'status', $t_status );	
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_status' );
	}

	if ( isset( $p_visible_bug_data[ 'email_target_version' ] ) ) {	
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_target_version' );
	}

	# custom fields formatting
	foreach( $p_visible_bug_data['custom_fields'] as $t_custom_field_name => $t_custom_field_data ) {
		$t_message .= utf8_str_pad( lang_get_defaulted( $t_custom_field_name ) . ': ', $t_email_padding_length );
		$t_message .= string_custom_field_value_for_email( $t_custom_field_data['value'], $t_custom_field_data['type'] );
		$t_message .= " \n";
	}

	# end foreach custom field

	if( isset( $t_status ) && config_get( 'bug_resolved_status_threshold' ) <= $t_status ) {
		
		if ( isset( $p_visible_bug_data[ 'email_resolution' ] ) ) {
			$p_visible_bug_data['email_resolution'] = get_enum_element( 'resolution', $p_visible_bug_data['email_resolution'] );
			$t_message .= email_format_attribute( $p_visible_bug_data, 'email_resolution' );
		}
			
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_fixed_in_version' );
	}
	$t_message .= $t_email_separator1 . " \n";

	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_date_submitted' );
	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_last_modified' );

	if( isset( $p_visible_bug_data['email_due_date'] ) ) {
		$t_message .= email_format_attribute( $p_visible_bug_data, 'email_due_date' );
	}

	$t_message .= $t_email_separator1 . " \n";

	$t_message .= email_format_attribute( $p_visible_bug_data, 'email_summary' );

	$t_message .= lang_get( 'email_description' ) . ": \n" . $p_visible_bug_data['email_description'] . "\n";

	if( isset( $p_visible_bug_data[ 'email_steps_to_reproduce' ] ) && !is_blank( $p_visible_bug_data['email_steps_to_reproduce'] ) ) {
		$t_message .= "\n" . lang_get( 'email_steps_to_reproduce' ) . ": \n" . $p_visible_bug_data['email_steps_to_reproduce'] . "\n";
	}

	if( isset( $p_visible_bug_data[ 'email_additional_information' ] ) && !is_blank( $p_visible_bug_data['email_additional_information'] ) ) {
		$t_message .= "\n" . lang_get( 'email_additional_information' ) . ": \n" . $p_visible_bug_data['email_additional_information'] . "\n";
	}

	if( isset( $p_visible_bug_data['relations'] ) ) {
		if( $p_visible_bug_data['relations'] != '' ) {
			$t_message .= $t_email_separator1 . "\n" . utf8_str_pad( lang_get( 'bug_relationships' ), 20 ) . utf8_str_pad( lang_get( 'id' ), 8 ) . lang_get( 'summary' ) . "\n" . $t_email_separator2 . "\n" . $p_visible_bug_data['relations'];
		}
	}

	# Sponsorship
	if( isset( $p_visible_bug_data['sponsorship_total'] ) && ( $p_visible_bug_data['sponsorship_total'] > 0 ) ) {
		$t_message .= $t_email_separator1 . " \n";
		$t_message .= sprintf( lang_get( 'total_sponsorship_amount' ), sponsorship_format_amount( $p_visible_bug_data['sponsorship_total'] ) ) . "\n\n";

		if( isset( $p_visible_bug_data['sponsorships'] ) ) {
			foreach( $p_visible_bug_data['sponsorships'] as $t_sponsorship ) {
				$t_date_added = date( config_get( 'normal_date_format' ), $t_sponsorship->date_submitted );

				$t_message .= $t_date_added . ': ';
				$t_message .= user_get_name( $t_sponsorship->user_id );
				$t_message .= ' (' . sponsorship_format_amount( $t_sponsorship->amount ) . ')' . " \n";
			}
		}
	}

	$t_message .= $t_email_separator1 . " \n\n";

	# format bugnotes
	foreach( $p_visible_bug_data['bugnotes'] as $t_bugnote ) {
		# Show time tracking is always true, since data has already been filtered out when creating the bug visible data.
		$t_message .= email_format_bugnote( $t_bugnote, $p_visible_bug_data['email_project_id'],
				/* show_time_tracking */ true,  $t_email_separator2, $t_normal_date_format ) . "\n";
	}

	# format history
	if( array_key_exists( 'history', $p_visible_bug_data ) ) {
		$t_message .= lang_get( 'bug_history' ) . " \n";
		$t_message .= utf8_str_pad( lang_get( 'date_modified' ), 17 ) . utf8_str_pad( lang_get( 'username' ), 15 ) . utf8_str_pad( lang_get( 'field' ), 25 ) . utf8_str_pad( lang_get( 'change' ), 20 ) . " \n";

		$t_message .= $t_email_separator1 . " \n";

		foreach( $p_visible_bug_data['history'] as $t_raw_history_item ) {
			$t_localized_item = history_localize_item(
				$t_raw_history_item['bug_id'],
				$t_raw_history_item['field'],
				$t_raw_history_item['type'],
				$t_raw_history_item['old_value'],
				$t_raw_history_item['new_value'],
				false
			);

			$t_message .= utf8_str_pad( date( $t_normal_date_format, $t_raw_history_item['date'] ), 17 ) . utf8_str_pad( $t_raw_history_item['username'], 15 ) . utf8_str_pad( $t_localized_item['note'], 25 ) . utf8_str_pad( $t_localized_item['change'], 20 ) . "\n";
		}
		$t_message .= $t_email_separator1 . " \n\n";
	}

	return $t_message;
}

/**
 * Format email attribute for display.
 *
 * If $p_visible_bug_data contains specified attribute the function
 * returns concatenated translated attribute name and original
 * attribute value. Else return empty string.
 *
 * @param array  $p_visible_bug_data Visible Bug Data array.
 * @param string $p_attribute_id     Attribute ID.
 *
 * @return string
 */
function email_format_attribute( array $p_visible_bug_data, $p_attribute_id ) {
	if( array_key_exists( $p_attribute_id, $p_visible_bug_data ) ) {
		return utf8_str_pad( lang_get( $p_attribute_id ) . ': ', config_get( 'email_padding_length' ) )
			. $p_visible_bug_data[$p_attribute_id] . "\n";
	}
	return '';
}

/**
 * Build the bug raw data visible for specified user to be translated and sent by email to the user
 *
 * Filter the bug data according to user access level.
 * @see email_format_bug_message()
 *
 * @param int    $p_user_id    A user identifier.
 * @param int    $p_bug_id     A bug identifier.
 * @param string $p_message_id A message identifier.
 *
 * @return array Bug data
 * @throws ClientException
 */
function email_build_visible_bug_data( $p_user_id, $p_bug_id, $p_message_id ) {
	# Override current user with user to construct bug data for.
	# This is to make sure that APIs that check against current user (e.g. relationship) work correctly.
	$t_current_user_id = current_user_set( $p_user_id );

	$t_project_id = bug_get_field( $p_bug_id, 'project_id' );
	$t_user_access_level = user_get_access_level( $p_user_id, $t_project_id );
	$t_user_bugnote_order = user_pref_get_pref( $p_user_id, 'bugnote_order' );
	$t_user_bugnote_limit = user_pref_get_pref( $p_user_id, 'email_bugnote_limit' );

	$t_row = bug_get_extended_row( $p_bug_id );
	$t_bug_data = array();

	$t_bug_view_fields = config_get( 'bug_view_page_fields', null, $p_user_id, $t_row['project_id'] );

	$t_bug_data['email_bug'] = $p_bug_id;

	if( $p_message_id !== 'email_notification_title_for_action_bug_deleted' ) {
		$t_bug_data['email_bug_view_url'] = string_get_bug_view_url_with_fqdn( $p_bug_id );
	}

	if( access_compare_level( $t_user_access_level, config_get( 'view_handler_threshold' ) ) ) {
		if( 0 != $t_row['handler_id'] ) {
			$t_bug_data['email_handler'] = user_get_name( $t_row['handler_id'] );
		} else {
			$t_bug_data['email_handler'] = '';
		}
	}

	$t_bug_data['email_reporter'] = user_get_name( $t_row['reporter_id'] );
	$t_bug_data['email_project_id'] = $t_row['project_id'];
	$t_bug_data['email_project'] = project_get_field( $t_row['project_id'], 'name' );

	$t_category_name = category_full_name( $t_row['category_id'], false );
	$t_bug_data['email_category'] = $t_category_name;

	$t_tag_rows = tag_bug_get_attached( $p_bug_id );
	if( in_array( 'tags', $t_bug_view_fields ) && !empty( $t_tag_rows ) && access_compare_level( $t_user_access_level, config_get( 'tag_view_threshold' ) ) ) {
		$t_bug_data['email_tag'] = '';

		foreach( $t_tag_rows as $t_tag ) {
			$t_bug_data['email_tag'] .= $t_tag['name'] . ', ';
		}

		$t_bug_data['email_tag'] = trim( $t_bug_data['email_tag'], ', ' );
	}

	$t_bug_data['email_date_submitted'] = $t_row['date_submitted'];
	$t_bug_data['email_last_modified'] = $t_row['last_updated'];

	if( !date_is_null( $t_row['due_date'] ) && access_compare_level( $t_user_access_level, config_get( 'due_date_view_threshold' ) ) ) {
		$t_bug_data['email_due_date'] = date( config_get( 'short_date_format' ), $t_row['due_date'] );
	}

	if ( in_array( 'status', $t_bug_view_fields ) ) {	
		$t_bug_data['email_status'] = $t_row['status'];
	}
	
	if ( in_array( 'severity', $t_bug_view_fields ) ) {	
		$t_bug_data['email_severity'] = $t_row['severity'];
	}
	
	if ( in_array( 'priority', $t_bug_view_fields ) ) {	
		$t_bug_data['email_priority'] = $t_row['priority'];
	}

	if ( in_array( 'reproducibility', $t_bug_view_fields ) ) {
		$t_bug_data['email_reproducibility'] = $t_row['reproducibility'];
	}
	
	if ( in_array( 'resolution', $t_bug_view_fields ) ) {	
		$t_bug_data['email_resolution'] = $t_row['resolution'];
	}
		
	$t_bug_data['email_fixed_in_version'] = $t_row['fixed_in_version'];

	if( in_array( 'target_version', $t_bug_view_fields ) && !is_blank( $t_row['target_version'] ) && access_compare_level( $t_user_access_level, config_get( 'roadmap_view_threshold' ) ) ) {
		$t_bug_data['email_target_version'] = $t_row['target_version'];
	}

	$t_bug_data['email_summary'] = $t_row['summary'];
	$t_bug_data['email_description'] = $t_row['description'];

	if( in_array( 'additional_info', $t_bug_view_fields ) ) {
		$t_bug_data['email_additional_information'] = $t_row['additional_information'];
	}
	
	if ( in_array( 'steps_to_reproduce', $t_bug_view_fields ) ) {
		$t_bug_data['email_steps_to_reproduce'] = $t_row['steps_to_reproduce'];
	}

	$t_bug_data['set_category'] = '[' . $t_bug_data['email_project'] . '] ' . $t_category_name;

	$t_bug_data['custom_fields'] = custom_field_get_linked_fields( $p_bug_id, $t_user_access_level );
	$t_bug_data['bugnotes'] = bugnote_get_all_visible_bugnotes( $p_bug_id, $t_user_bugnote_order, $t_user_bugnote_limit, $p_user_id );

	# put history data
	if( ( ON == config_get( 'history_default_visible' ) ) && access_compare_level( $t_user_access_level, config_get( 'view_history_threshold' ) ) ) {
		$t_bug_data['history'] = history_get_raw_events_array( $p_bug_id, $p_user_id );
	}

	# Sponsorship Information
	if( ( config_get( 'enable_sponsorship' ) == ON ) && ( access_has_bug_level( config_get( 'view_sponsorship_total_threshold' ), $p_bug_id, $p_user_id ) ) ) {
		$t_sponsorship_ids = sponsorship_get_all_ids( $p_bug_id );
		$t_bug_data['sponsorship_total'] = sponsorship_get_amount( $t_sponsorship_ids );

		if( access_has_bug_level( config_get( 'view_sponsorship_details_threshold' ), $p_bug_id, $p_user_id ) ) {
			$t_bug_data['sponsorships'] = array();
			foreach( $t_sponsorship_ids as $t_id ) {
				$t_bug_data['sponsorships'][] = sponsorship_get( $t_id );
			}
		}
	}

	$t_bug_data['relations'] = email_relationship_get_summary_text( $p_bug_id );

	current_user_set( $t_current_user_id );

	return $t_bug_data;
}

/**
 * Return formatted string with all the details on the requested relationship.
 *
 * @param int                 $p_bug_id       A bug identifier.
 * @param BugRelationshipData $p_relationship A bug relationship object.
 *
 * @return string
 * @throws ClientException
 */
function email_relationship_get_details( $p_bug_id, BugRelationshipData $p_relationship ) {
	$t_summary_wrap_at = mb_strlen( config_get( 'email_separator2' ) ) - 28;

	if( $p_bug_id == $p_relationship->src_bug_id ) {
		# root bug is in the source side, related bug in the destination side
		$t_related_project_id = $p_relationship->dest_bug_id;
		$t_related_bug_id = $p_relationship->dest_bug_id;
		$t_relationship_descr = relationship_get_description_src_side( $p_relationship->type );
	} else {
		# root bug is in the dest side, related bug in the source side
		$t_related_project_id = $p_relationship->src_bug_id;
		$t_related_bug_id = $p_relationship->src_bug_id;
		$t_relationship_descr = relationship_get_description_dest_side( $p_relationship->type );
	}

	# related bug not existing...
	if( !bug_exists( $t_related_bug_id ) ) {
		return '';
	}

	# user can access to the related bug at least as a viewer
	if( !access_has_bug_level( config_get( 'view_bug_threshold', null, null, $t_related_project_id ), $t_related_bug_id ) ) {
		return '';
	}

	# get the information from the related bug and prepare the link
	$t_bug = bug_get( $t_related_bug_id );

	$t_relationship_info_text = utf8_str_pad( $t_relationship_descr, 20 );
	$t_relationship_info_text .= utf8_str_pad( bug_format_id( $t_related_bug_id ), 8 );

	# add summary
	if( mb_strlen( $t_bug->summary ) <= $t_summary_wrap_at ) {
		$t_relationship_info_text .= string_email_links( $t_bug->summary );
	} else {
		$t_relationship_info_text .= mb_substr( string_email_links( $t_bug->summary ), 0, $t_summary_wrap_at - 3 ) . '...';
	}

	$t_relationship_info_text .= "\n";

	return $t_relationship_info_text;
}

/**
 * Get ALL the RELATIONSHIPS OF A SPECIFIC BUG in text format.
 *
 * @param int $p_bug_id A bug identifier.
 *
 * @return string
 * @throws ClientException
 */
function email_relationship_get_summary_text( $p_bug_id ) {
	# A variable that will be set by the following call to indicate if relationships belong
	# to multiple projects.
	$t_show_project = false;

	$t_relationship_all = relationship_get_all( $p_bug_id, $t_show_project );
	$t_relationship_all_count = count( $t_relationship_all );

	# prepare the relationships table
	$t_summary = '';
	for( $i = 0; $i < $t_relationship_all_count; $i++ ) {
		$t_summary .= email_relationship_get_details( $p_bug_id, $t_relationship_all[$i] );
	}

	return $t_summary;
}
