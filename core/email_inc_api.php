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
## CN-start
require_api( 'template_api.php' );
## CN-end

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as phpmailerException;
use Mantis\Exceptions\ClientException;
use VBoctor\Email\DisposableEmailChecker;

/** @global PHPMailer $g_phpMailer Reusable PHPMailer object */
$g_phpMailer = null;

/**
 * Generates md5 used in "In-Reply-To" header for emails.
 *
 * @param int $p_id
 * @param int $p_date_submitted
 *
 * @return string
 */
function email_generate_md5( $p_id, $p_date_submitted ) {
	return md5( $p_id . $p_date_submitted );
}

/**
 * Indicates how generated emails will be processed by the shutdown function
 * at the end of the current request's execution; this is a binary flag:
 * - EMAIL_SHUTDOWN_SKIP       Initial state: do nothing (no generated emails)
 * - EMAIL_SHUTDOWN_GENERATED  Emails will be sent, unless $g_email_send_using_cronjob is ON
 * - EMAIL_SHUTDOWN_FORCE      All queued emails will be sent regardless of cronjob settings
 * @see email_shutdown_function()
 * @global $g_email_shutdown_processing
 */
$g_email_shutdown_processing = EMAIL_SHUTDOWN_SKIP;

/**
 * Regex for valid email addresses.
 *
 * @see string_insert_hrefs()
 * This pattern is consistent with email addresses validation logic
 * @see $g_validate_email
 * Uses the standard HTML5 pattern defined in
 * {@link https://html.spec.whatwg.org/multipage/input.html#valid-e-mail-address}
 * Note: the original regex from the spec has been modified to
 * - escape the '/' in the first character class definition
 * - remove the '^' and '$' anchors to allow matching anywhere in a string
 * - add a limit of 64 chars on local part to avoid timeouts on very long texts with false matches.
 *
 * @return string
 */
function email_regex_simple() {
	return "/[a-zA-Z0-9.!#$%&'*+\/=?^_`{|}~-]{1,64}@[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?(?:\.[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?)*/";
}

/**
 * Check to see that the format is valid and that the mx record exists.
 *
 * @param string $p_email An email address.
 *
 * @return boolean
 */
function email_is_valid( $p_email ) {
	$t_validate_email = config_get_global( 'validate_email' );

	# if we don't validate then just accept
	# If blank email is allowed or current user is admin, then accept blank emails which are useful for
	# accounts that should never receive email notifications (e.g. anonymous account)
	if( OFF == $t_validate_email ||
		ON == config_get_global( 'use_ldap_email' ) ||
		( is_blank( $p_email ) && ( ON == config_get( 'allow_blank_email' ) || current_user_is_administrator() ) )
	) {
		return true;
	}

	# E-mail validation method
	# Note: PHPMailer offers alternative validation methods.
	# It was decided in PR 172 (https://github.com/mantisbt/mantisbt/pull/172)
	# to just default to HTML5 without over-complicating things for end users
	# by offering a potentially confusing choice between the different methods.
	# Refer to PHPMailer documentation for ValidateAddress method for details.
	# @link https://github.com/PHPMailer/PHPMailer/blob/v5.2.9/class.phpmailer.php#L863
	$t_method = 'html5';

	# check email address is a valid format
	log_event( LOG_EMAIL_VERBOSE, "Validating address '$p_email' with method '$t_method'" );
	if( PHPMailer::validateAddress( $p_email, $t_method ) ) {
		$t_domain = substr( $p_email, strpos( $p_email, '@' ) + 1 );

		# see if we're limited to a set of known domains
		$t_limit_email_domains = config_get( 'limit_email_domains' );
		if( !empty( $t_limit_email_domains ) ) {
			foreach( $t_limit_email_domains as $t_email_domain ) {
				if( 0 == strcasecmp( $t_email_domain, $t_domain ) ) {
					return true; # no need to check mx record details (below) if we've explicitly allowed the domain
				}
			}
			log_event( LOG_EMAIL, "failed - not in limited domains list '$t_limit_email_domains'" );
			return false;
		}

		if( ON == config_get( 'check_mx_record' ) ) {
			$t_mx = array();

			# Check for valid mx records
			if( getmxrr( $t_domain, $t_mx ) ) {
				return true;
			} else {
				$t_host = $t_domain . '.';

				# for no mx record... try dns check
				if( checkdnsrr( $t_host, 'ANY' ) ) {
					return true;
				}
				log_event( LOG_EMAIL, "failed - mx/dns record check" );
			}
		} else {
			# Email format was valid but didn't check for valid mx records
			return true;
		}
	} else {
		log_event( LOG_EMAIL, "failed - invalid address" );
	}

	# Everything failed.  The email is invalid
	return false;
}

/**
 * Check if the email address is valid trigger an ERROR if it isn't.
 *
 * @param string $p_email An email address.

 * @return void
 * @throws ClientException
 */
function email_ensure_valid( $p_email ) {
	if( !email_is_valid( $p_email ) ) {
		throw new ClientException(
			sprintf( "Email '%s' is invalid.", $p_email ),
			ERROR_EMAIL_INVALID );
	}
}

/**
 * Check if the email address is disposable.
 *
 * @param string $p_email An email address.
 *
 * @return boolean
 */
function email_is_disposable( $p_email ) {
	return DisposableEmailChecker::is_disposable_email( $p_email );
}

/**
 * Check if the email address is disposable, triggers an ERROR if it is not.
 *
 * @param string $p_email An email address.
 *
 * @return void
 * @throws ClientException
 */
function email_ensure_not_disposable( $p_email ) {
	if( email_is_disposable( $p_email ) ) {
		throw new ClientException(
			sprintf( "Email '%s' is disposable.", $p_email ),
			ERROR_EMAIL_DISPOSABLE
		);
	}
}

/**
 * Get the value associated with the specific action and flag.
 *
 * For example, you can get the value associated with notifying "admin"
 * on action "new", i.e. notify administrators on new bugs which can be
 * ON or OFF.
 *
 * @param string $p_action Action.
 * @param string $p_flag   Flag.
 *
 * @return integer 1 - enabled, 0 - disabled.
 */
function email_notify_flag( $p_action, $p_flag ) {
	# If flag is specified for the specific event, use that.
	$t_notify_flags = config_get( 'notify_flags' );
	if( isset( $t_notify_flags[$p_action][$p_flag] ) ) {
		return $t_notify_flags[$p_action][$p_flag];
	}

	# If not, then use the default if specified in database or global.
	# Note that web UI may not support or specify all flags (e.g. explicit),
	# hence, if config is retrieved from database it may not have the flag.
	$t_default_notify_flags = config_get( 'default_notify_flags' );
	if( isset( $t_default_notify_flags[$p_flag] ) ) {
		return $t_default_notify_flags[$p_flag];
	}

	# If the flag is not specified so far, then force using global config which
	# should have all flags specified.
	$t_global_default_notify_flags = config_get_global( 'default_notify_flags' );
	if( isset( $t_global_default_notify_flags[$p_flag] ) ) {
		return $t_global_default_notify_flags[$p_flag];
	}

	return OFF;
}

/**
 * Send an email notification to a user when their information is changed by another user.
 *
 * @param int   $p_user_id  The user id of the user whose information was changed.
 * @param array $p_old_user The user's information before the change.
 * @param array $p_new_user The user's information after the change.
 *
 * @return void
 */
function email_user_changed( $p_user_id, $p_old_user, $p_new_user ) {
	if( config_get( 'enable_email_notification' ) == OFF ) {
		return;
	}

	lang_push( user_pref_get_language( $p_user_id ) );
	$t_changes = '';

	if( strcmp( $p_new_user['username'], $p_old_user['username'] ) ) {
		$t_changes .= lang_get( 'username_label' ) . ' ' . $p_old_user['username'] . ' => ' . $p_new_user['username'] . "\n";
	}

	if( strcmp( $p_old_user['realname'], $p_new_user['realname'] ) ) {
		$t_changes .= lang_get( 'realname_label' ) . ' ' . $p_old_user['realname'] . ' => ' . $p_new_user['realname'] . "\n";
	}

	if( strcmp( $p_old_user['email'], $p_new_user['email'] ) ) {
		$t_changes .= lang_get( 'email_label' ) . ' ' . $p_old_user['email'] . ' => ' . $p_new_user['email'] . "\n";
	}

	if( $p_old_user['access_level'] !== $p_new_user['access_level'] ) {
		$t_old_access_string = get_enum_element( 'access_levels', $p_old_user['access_level'] );
		$t_new_access_string = get_enum_element( 'access_levels', $p_new_user['access_level'] );
		$t_changes .= lang_get( 'access_level_label' ) . ' ' . $t_old_access_string . ' => ' . $t_new_access_string . "\n\n";
	}

	if( !empty( $t_changes ) ) {
		$t_subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'email_user_updated_subject' );
		$t_updated_msg = lang_get( 'email_user_updated_msg' );
		$t_message = $t_updated_msg . "\n\n" . config_get_global( 'path' ) . 'account_page.php' . "\n\n" . $t_changes;

		if( null === email_store( $p_new_user['email'], $t_subject, $t_message ) ) {
			log_event( LOG_EMAIL, 'Notification was NOT sent to ' . $p_new_user['username'] );
		} else {
			log_event( LOG_EMAIL, 'Account update notification sent to ' . $p_new_user['username'] . ' (' . $p_new_user['email'] . ')' );
			if( config_get( 'email_send_using_cronjob' ) == OFF ) {
				email_send_all();
			}
		}
	}

	lang_pop();
}

/**
 * Send password to user.
 *
 * @param int    $p_user_id      A valid user identifier.
 * @param string $p_confirm_hash Confirmation hash.
 * @param string $p_admin_name   Administrator name.
 *
 * @return void
 * @throws ClientException
 */
function email_signup( $p_user_id, $p_confirm_hash, $p_admin_name = '' ) {
	if( ( OFF == config_get( 'send_reset_password' ) ) || ( OFF == config_get( 'enable_email_notification' ) ) ) {
		return;
	}

	#	@@@ thraxisp - removed to address #6084 - user won't have any settings yet,
	#  use same language as display for the email
	#  lang_push( user_pref_get_language( $p_user_id ) );
	# retrieve the username and email
	$t_username = user_get_username( $p_user_id );
	$t_email = user_get_email( $p_user_id );

	# Build Welcome Message
	$t_subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'new_account_subject' );

	if( !empty( $p_admin_name ) ) {
		$t_intro_text = sprintf( lang_get( 'new_account_greeting_admincreated' ), $p_admin_name, $t_username );
	} else {
		$t_intro_text = sprintf( lang_get( 'new_account_greeting' ), $t_username );
	}

	$t_message = $t_intro_text . "\n\n" . string_get_confirm_hash_url( $p_user_id, $p_confirm_hash ) . "\n\n" . lang_get( 'new_account_message' ) . "\n\n" . lang_get( 'new_account_do_not_reply' );

	# Send signup email regardless of mail notification pref
	# or else users won't be able to sign up
	if( !is_blank( $t_email ) ) {
		email_store( $t_email, $t_subject, $t_message, null, true );
		log_event( LOG_EMAIL, 'Signup Email = %s, Hash = %s, User = @U%d', $t_email, $p_confirm_hash, $p_user_id );
	}

	# lang_pop(); # see above
}

/**
 * Send confirm_hash URL to let user reset their password.
 *
 * @param int    $p_user_id        A valid user identifier.
 * @param string $p_confirm_hash   Confirmation hash.
 * @param bool   $p_reset_by_admin True if password was reset by admin,
 *                                 False (default) for user request (lost password)
 *
 * @return void
 * @throws ClientException
 */
function email_send_confirm_hash_url( $p_user_id, $p_confirm_hash, $p_reset_by_admin = false ) {
	if( OFF == config_get( 'send_reset_password' ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'Password reset email notifications disabled.' );
		return;
	}
	if( OFF == config_get( 'enable_email_notification' ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'email notifications disabled.' );
		return;
	}
	if( !user_is_enabled( $p_user_id ) ) {
		log_event( LOG_EMAIL, 'Password reset for user @U%d not sent, user is disabled', $p_user_id );
		return;
	}
	lang_push( user_pref_get_language( $p_user_id ) );

	# retrieve the username and email
	$t_username = user_get_username( $p_user_id );
	$t_email = user_get_email( $p_user_id );

	$t_subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'lost_password_subject' );

	if( $p_reset_by_admin ) {
		$t_message = lang_get( 'reset_request_admin_msg' );
	} else {
		$t_message = lang_get( 'reset_request_msg' );
	}
	$t_message .= "\n\n"
		. string_get_confirm_hash_url( $p_user_id, $p_confirm_hash ) . "\n\n"
		. lang_get( 'new_account_username' ) . ' ' . $t_username . "\n"
		. lang_get( 'new_account_IP' ) . ' ' . $_SERVER['REMOTE_ADDR'] . "\n\n"
		. lang_get( 'new_account_do_not_reply' );

	# Send password reset regardless of mail notification preferences
	# or else users won't be able to receive their reset passwords
	if( !is_blank( $t_email ) ) {
		email_store( $t_email, $t_subject, $t_message, null, true );
		log_event( LOG_EMAIL, 'Password reset for user @U%d sent to %s', $p_user_id, $t_email );
	} else {
		log_event( LOG_EMAIL, 'Password reset for user @U%d not sent, email is empty', $p_user_id );
	}

	lang_pop();
}

/**
 * Send confirm_hash URL to let user validate a new email address.
 *
 * @param int    $p_user_id        A valid user identifier.
 * @param string $p_confirm_hash   Confirmation hash.
 * @param string $p_new_email      The new email address
 *
 * @return void
 * @throws ClientException
 */
function email_send_email_verification_url( $p_user_id, $p_confirm_hash, $p_new_email ) {
	# TODO is this needed ?
	if( OFF == config_get( 'send_reset_password' ) ) {
		log_event( LOG_EMAIL_VERBOSE, 'Password reset email notifications disabled.' );
		return;
	}

	lang_push( user_pref_get_language( $p_user_id ) );

	# retrieve the username and email
	$t_username = user_get_username( $p_user_id );
	$t_old_email = user_get_email( $p_user_id );

	$t_subject = '[' . config_get( 'window_title' ) . '] '
		. lang_get( 'verify_email_title' );

	$t_message = lang_get( 'verify_email_msg' )
		. "\n\n"
		. string_get_confirm_hash_url( $p_user_id, $p_confirm_hash, 'verify_email.php' )
		. "\n\n"
		. lang_get( 'new_account_username' ) . ' ' . $t_username . "\n"
		. lang_get( 'new_value' ) . ': ' . $p_new_email . "\n"
		. lang_get( 'old_value' ) . ': ' . $t_old_email . "\n"
		. lang_get( 'new_account_IP' ) . ' ' . $_SERVER['REMOTE_ADDR']
		. "\n\n"
		. lang_get( 'new_account_do_not_reply' );

	# Send regardless of mail notification preferences
	email_store( $p_new_email, $t_subject, $t_message, [], true, [$t_old_email] );
	log_event( LOG_EMAIL, 'Email verification message for user @U%d sent to %s', $p_user_id, $p_new_email );

	lang_pop();
}

/**
 * Notify the selected group a new user has signup.
 *
 * @param string $p_username Username of new user.
 * @param string $p_email    Email address of new user.
 *
 * @return void
 * @throws ClientException
 */
function email_notify_new_account( $p_username, $p_email ) {
	log_event( LOG_EMAIL, 'New account for user %s', $p_username );

	$t_threshold_min = config_get( 'notify_new_user_created_threshold_min' );
	$t_threshold_users = project_get_all_user_rows( ALL_PROJECTS, $t_threshold_min );
	$t_user_ids = array_keys( $t_threshold_users );
	user_cache_array_rows( $t_user_ids );
	user_pref_cache_array_rows( $t_user_ids );

	foreach( $t_threshold_users as $t_user ) {
		lang_push( user_pref_get_language( $t_user['id'] ) );

		$t_recipient_email = user_get_email( $t_user['id'] );
		$t_subject = '[' . config_get( 'window_title' ) . '] ' . lang_get( 'new_account_subject' );

		$t_message = lang_get( 'new_account_signup_msg' ) . "\n\n" . lang_get( 'new_account_username' ) . ' ' . $p_username . "\n" . lang_get( 'new_account_email' ) . ' ' . $p_email . "\n" . lang_get( 'new_account_IP' ) . ' ' . $_SERVER['REMOTE_ADDR'] . "\n" . config_get_global( 'path' ) . "\n\n" . lang_get( 'new_account_do_not_reply' );

		if( !is_blank( $t_recipient_email ) ) {
			email_store( $t_recipient_email, $t_subject, $t_message );
			log_event( LOG_EMAIL, 'New Account Notify for email = \'%s\'', $t_recipient_email );
		}

		lang_pop();
	}
}

/**
 * Store email in queue for sending.
 *
 * @param string $p_recipient Email recipient address.
 * @param string $p_subject   Subject of email message.
 * @param string $p_message   Body text of email message.
 * @param array  $p_headers   Array of additional headers to send with the email.
 * @param bool   $p_force     True to force sending of emails in shutdown function,
 *                            even when using cronjob
 * @param array  $p_cc        Array of cc recipients.
 * @param array  $p_bcc       Array of bcc recipients.
 *
 * @return integer|null
 */
function email_store( string $p_recipient, string $p_subject, string $p_message, array $p_headers = null, $p_force = false, $p_cc = [], $p_bcc = [] ) {
	global $g_email_shutdown_processing;

	$t_recipient = trim( $p_recipient );
	$t_subject = string_email( trim( $p_subject ) );
	$t_message = string_email_links( trim( $p_message ) );

	# short-circuit if no recipient is defined, or email disabled
	# note that this may cause signup messages not to be sent
	if( is_blank( $p_recipient ) || ( OFF == config_get( 'enable_email_notification' ) ) ) {
		return null;
	}

	$t_email_data = new EmailData;

	$t_email_data->email = $t_recipient;
	$t_email_data->subject = $t_subject;
	$t_email_data->body = $t_message;
	$t_email_data->metadata = array();
	$t_email_data->metadata['headers'] = $p_headers === null ? array() : $p_headers;
	$t_email_data->metadata['cc'] = $p_cc;
	$t_email_data->metadata['bcc'] = $p_bcc;

	# Urgent = 1, Not Urgent = 5, Disable = 0
	$t_email_data->metadata['charset'] = 'utf-8';

	$t_hostname = '';
	if( isset( $_SERVER['SERVER_NAME'] ) ) {
		$t_hostname = $_SERVER['SERVER_NAME'];
	} else {
		$t_address = explode( '@', config_get( 'from_email' ) );
		if( isset( $t_address[1] ) ) {
			$t_hostname = $t_address[1];
		}
	}
	$t_email_data->metadata['hostname'] = $t_hostname;

	$t_email_id = email_queue_add( $t_email_data );

	# Set the email processing flag for the shutdown function
	$g_email_shutdown_processing |= EMAIL_SHUTDOWN_GENERATED;
	if( $p_force ) {
		$g_email_shutdown_processing |= EMAIL_SHUTDOWN_FORCE;
	}

	return $t_email_id;
}

/**
 * This function sends all the emails that are stored in the queue.
 *
 * It will be called
 * - immediately after queueing messages in case of synchronous emails
 * - from a cronjob in case of asynchronous emails
 * If a failure occurs, then the function exits.
 *
 * @param bool $p_delete_on_failure Indicates whether to remove email from queue on failure (default false).
 *
 * @return void
 *
 * @todo In case of synchronous email sending, we may get a race condition where two requests send the same email.
 */
function email_send_all( $p_delete_on_failure = false ) : void {
	$t_ids = email_queue_get_ids();

	log_event( LOG_EMAIL_VERBOSE, 'Processing e-mail queue (' . count( $t_ids ) . ' messages)' );

	foreach( $t_ids as $t_id ) {
		$t_email_data = email_queue_get( $t_id );
		$t_start = microtime( true );

		# check if email was not found.  This can happen if another request picks up the email first and sends it.
		if( $t_email_data === false ) {
			$t_email_sent = true;
			log_event( LOG_EMAIL_VERBOSE, 'Message $t_id has already been sent' );
		} else {
			log_event( LOG_EMAIL_VERBOSE, 'Sending message ' . $t_id );
			$t_email_sent = email_send( $t_email_data );
		}

		if( !$t_email_sent ) {
			# Delete emails that were submitted more than N days ago
			$t_submitted = (int)$t_email_data->submitted;
			$t_delete_after_in_days = (int)config_get_global( 'email_retry_in_days' );
			$t_retry_cutoff = time() - ( $t_delete_after_in_days * 24 * 60 * 60 );
			if( $p_delete_on_failure || $t_submitted < $t_retry_cutoff ) {
				$t_reason = $p_delete_on_failure ? 'delete on failure' : 'retry expired';
				email_queue_delete( $t_email_data->email_id, $t_reason );
			}

			# If unable to place the email in the email server queue and more
			# than 5 seconds have elapsed, then we assume that the server
			# connection is down, hence no point to continue trying with the
			# rest of the emails.
			if( microtime( true ) - $t_start > 5 ) {
				log_event( LOG_EMAIL, 'Server not responding for 5 seconds, aborting' );
				break;
			}
		}
	}
}

/**
 * This function sends an email message based on the supplied email data.
 *
 * @param EmailData $p_email_data Email Data object representing the email to send.
 *
 * @return boolean
 */
function email_send( EmailData $p_email_data ) : bool {
	global $g_phpMailer;

	$t_email_data = $p_email_data;

	$t_recipient = trim( $t_email_data->email );
	$t_subject = string_email( trim( $t_email_data->subject ) );
	$t_message = string_email_links( trim( $t_email_data->body ) );

	$t_debug_email = config_get_global( 'debug_email' );

	$t_log_msg = 'ERROR: Message could not be sent - ';

	if( is_null( $g_phpMailer ) ) {
		if( PHPMAILER_METHOD_SMTP == config_get( 'phpMailer_method' ) ) {
			register_shutdown_function( 'email_smtp_close' );
		}
		$g_phpMailer = new PHPMailer( true );

		// Set e-mail addresses validation pattern. The 'html5' setting is
		// consistent with the regex defined in email_regex_simple().
		PHPMailer::$validator  = 'html5';
	}
	$t_mail = $g_phpMailer;

	if( isset( $t_email_data->metadata['hostname'] ) ) {
		$t_mail->Hostname = $t_email_data->metadata['hostname'];
	}

	# @@@ should this be the current language (for the recipient) or the default one (for the user running the command) (thraxisp)
	$t_lang = config_get_global( 'default_language' );
	if( 'auto' == $t_lang ) {
		$t_lang = config_get_global( 'fallback_language' );
	}
	$t_mail->setLanguage( lang_get( 'phpmailer_language', $t_lang ) );

	# Select the method to send mail
	switch( config_get( 'phpMailer_method' ) ) {
		case PHPMAILER_METHOD_MAIL:
			$t_mail->isMail();
			break;

		case PHPMAILER_METHOD_SENDMAIL:
			$t_mail->isSendmail();
			break;

		case PHPMAILER_METHOD_SMTP:
			$t_mail->isSMTP();

			# SMTP collection is always kept alive
			$t_mail->SMTPKeepAlive = true;

			if( !is_blank( config_get( 'smtp_username' ) ) ) {
				# Use SMTP Authentication
				$t_mail->SMTPAuth = true;
				$t_mail->Username = config_get( 'smtp_username' );
				$t_mail->Password = config_get( 'smtp_password' );
			}

			if( is_blank( config_get( 'smtp_connection_mode' ) ) ) {
				$t_mail->SMTPAutoTLS = false;
			}
			else {
				$t_mail->SMTPSecure = config_get( 'smtp_connection_mode' );
			}

			$t_mail->Port = config_get( 'smtp_port' );

			break;
	}

	# S/MIME signature
	if( ON == config_get_global( 'email_smime_enable' ) ) {
		$t_mail->sign(
			config_get_global( 'email_smime_cert_file' ),
			config_get_global( 'email_smime_key_file' ),
			config_get_global( 'email_smime_key_password' ),
			config_get_global( 'email_smime_extracerts_file' )
		);
	}

	#apply DKIM settings
	if( config_get_global( 'email_dkim_enable' ) ) {
		$t_mail->DKIM_domain = config_get_global( 'email_dkim_domain' );
		$t_mail->DKIM_private = config_get_global( 'email_dkim_private_key_file_path' );
		$t_mail->DKIM_private_string = config_get_global( 'email_dkim_private_key_string' );
		$t_mail->DKIM_selector = config_get_global( 'email_dkim_selector' );
		$t_mail->DKIM_passphrase = config_get_global( 'email_dkim_passphrase' );
		$t_mail->DKIM_identity = config_get_global( 'email_dkim_identity' );
	}

	$t_mail->isHTML( false );              # set email format to plain text
## CN
	if ( ON == config_get( 'use_mailtemplate' ) )  {
		$t_mail->isHTML( true );
	} 
## CN
	$t_mail->WordWrap = 80;              # set word wrap to 80 characters
	$t_mail->CharSet = $t_email_data->metadata['charset'];
	$t_mail->Host = config_get( 'smtp_host' );
	$t_mail->From = config_get( 'from_email' );
	$t_mail->Sender = config_get( 'return_path_email' );
	$t_mail->FromName = config_get( 'from_name' );
	$t_mail->AddCustomHeader( 'Auto-Submitted:auto-generated' );
	$t_mail->AddCustomHeader( 'X-Auto-Response-Suppress: All' );

	if( isset( $t_email_data->metadata['cc'] ) && $t_email_data->metadata['cc'] ) {
		foreach( $t_email_data->metadata['cc'] as $cc ) {
			$t_mail->addCC( trim( $cc ) );
		}
	}
	if( isset( $t_email_data->metadata['bcc'] ) && $t_email_data->metadata['bcc'] ) {
		foreach( $t_email_data->metadata['bcc'] as $bcc ) {
			$t_mail->addBCC( trim( $bcc ) );
		}
	}

	$t_mail->Encoding   = 'quoted-printable';

	if( isset( $t_email_data->metadata['priority'] ) ) {
		$t_mail->Priority = $t_email_data->metadata['priority'];  # Urgent = 1, Not Urgent = 5, Disable = 0
	}

	if( !empty( $t_debug_email ) ) {
		$t_message = 'To: ' . $t_recipient . "\n\n" . $t_message;
		$t_recipient = $t_debug_email;
		log_event(LOG_EMAIL_VERBOSE, "Using debug email '$t_debug_email'");
	}

	try {
		$t_mail->addAddress( $t_recipient );
	}
	catch ( phpmailerException $e ) {
		log_event( LOG_EMAIL, $t_log_msg . $t_mail->ErrorInfo );
		$t_mail->clearAllRecipients();
		$t_mail->clearAttachments();
		$t_mail->clearReplyTos();
		$t_mail->clearCustomHeaders();
		return false;
	}

	$t_mail->Subject = $t_subject;
	$t_mail->Body = make_lf_crlf( $t_message );

	if( isset( $t_email_data->metadata['headers'] ) && is_array( $t_email_data->metadata['headers'] ) ) {
		foreach( $t_email_data->metadata['headers'] as $t_key => $t_value ) {
			switch( strtolower( $t_key ) ) {
				case 'message-id':
					# Note: hostname can never be blank here as we set metadata['hostname']
					# in email_store() where mail gets queued.
					if( !strchr( $t_value, '@' ) && !is_blank( $t_mail->Hostname ) ) {
						$t_value = $t_value . '@' . $t_mail->Hostname;
					}
					$t_mail->set( 'MessageID', '<' . $t_value . '>' );
					break;
				/** @noinspection PhpMissingBreakStatementInspection */
				case 'in-reply-to':
					if( !preg_match( '/<.+@.+>/m', $t_value ) ) {
						$t_value = '<' . $t_value . '@' . $t_mail->Hostname . '>';
					}
					# Fall-through
				default:
					$t_mail->addCustomHeader( $t_key . ': ' . $t_value );
					break;
			}
		}
	}

	try {
		$t_success = $t_mail->send();
		if( $t_success ) {
			if( $t_email_data->email_id > 0 ) {
				email_queue_delete( $t_email_data->email_id );
			}
		} else {
			# We should never get here, as an exception is thrown after failures
			log_event( LOG_EMAIL, $t_log_msg . $t_mail->ErrorInfo );
		}
	}
	catch ( phpmailerException $e ) {
		log_event( LOG_EMAIL, $t_log_msg . $t_mail->ErrorInfo );
		$t_success = false;
	}

	$t_mail->clearAllRecipients();
	$t_mail->clearAttachments();
	$t_mail->clearReplyTos();
	$t_mail->clearCustomHeaders();

	return $t_success;
}

/**
 * closes opened kept alive SMTP connection (if it was opened)
 *
 * @return void
 */
function email_smtp_close() {
	global $g_phpMailer;

	if( !is_null( $g_phpMailer ) ) {
		$t_smtp = $g_phpMailer->getSMTPInstance();
		if( $t_smtp->connected() ) {
			$t_smtp->quit();
			$t_smtp->close();
		}
		$g_phpMailer = null;
	}
}

/**
 * Clean up LF to CRLF.
 *
 * @param string $p_string String to convert line feeds on.
 *
 * @return string
 */
function make_lf_crlf( $p_string ) {
	$t_string = str_replace( "\n", "\r\n", $p_string );
	return str_replace( "\r\r\n", "\r\n", $t_string );
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
function email_format_attribute( array $p_visible_data, $p_attribute_id ) {
	if( array_key_exists( $p_attribute_id, $p_visible_data ) ) {
		return utf8_str_pad( lang_get( $p_attribute_id ) . ': ', config_get( 'email_padding_length' ) )
			. $p_visible_data[$p_attribute_id] . "\n";
	}
	return '';
}

/**
 * The email sending shutdown function.
 *
 * Will send any queued emails, except when $g_email_send_using_cronjob = ON.
 * If $g_email_shutdown_processing EMAIL_SHUTDOWN_FORCE flag is set, emails
 * will be sent regardless of cronjob setting.
 *
 * @return void
 */
function email_shutdown_function() {
	global $g_email_shutdown_processing;

	# Nothing to do if
	# - no emails have been generated in the current request
	# - system is configured to use cron job (unless processing is forced)
	if(    $g_email_shutdown_processing == EMAIL_SHUTDOWN_SKIP
		|| (   !( $g_email_shutdown_processing & EMAIL_SHUTDOWN_FORCE )
			&& config_get( 'email_send_using_cronjob' )
		   )
	) {
		return;
	}

	$t_msg ='Shutdown function called for ' . $_SERVER['SCRIPT_NAME'];
	if( $g_email_shutdown_processing & EMAIL_SHUTDOWN_FORCE ) {
		$t_msg .= ' (email processing forced)';
	}

	log_event( LOG_EMAIL_VERBOSE, $t_msg );

	if( $g_email_shutdown_processing ) {
		email_send_all();
	}
}

/**
 * Get the list of supported email actions.
 *
 * @return array List of actions
 */
function email_get_actions() {
	$t_actions = array( 'updated', 'owner', 'reopened', 'deleted', 'bugnote', 'relation', 'monitor' );

	if( config_get( 'enable_sponsorship' ) == ON ) {
		$t_actions[] = 'sponsor';
	}

	$t_statuses = MantisEnum::getAssocArrayIndexedByValues( config_get( 'status_enum_string' ) );
	ksort( $t_statuses );

	foreach( $t_statuses as $t_label ) {
		$t_actions[] = $t_label;
	}

	return $t_actions;
}
