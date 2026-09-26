<?php
# Doctis — update user-maintained profile and organisation fields.

require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'event_api.php' );
require_api( 'helper_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

use Mantis\Exceptions\ClientException;

/**
 * Update the profile of the current user.
 */
class UserProfileUpdateCommand extends Command {
	private $user_id;
	private $fields;

	/**
	 * Validate and normalize profile data.
	 *
	 * @return void
	 * @throws ClientException
	 */
	protected function validate() {
		$this->user_id = helper_parse_id( $this->query( 'user_id' ), 'user_id' );
		if( $this->user_id !== auth_get_current_user_id() ) {
			throw new ClientException( 'Users may only update their own profile', ERROR_ACCESS_DENIED );
		}
		if( !user_exists( $this->user_id ) ) {
			throw new ClientException( 'User does not exist', ERROR_USER_NOT_FOUND );
		}
		user_ensure_unprotected( $this->user_id );

		$t_profile = $this->payload( 'profile' );
		if( !is_array( $t_profile ) ) {
			throw new ClientException( 'Missing profile data', ERROR_EMPTY_FIELD, array( 'profile' ) );
		}

		$t_reports_to = (int)( $t_profile['reports_to'] ?? 0 );
		if( $t_reports_to === $this->user_id ) {
			throw new ClientException( 'A user cannot report to themselves', ERROR_INVALID_FIELD_VALUE, array( 'reports_to' ) );
		}
		if( $t_reports_to !== 0 && ( !user_exists( $t_reports_to ) || !user_is_enabled( $t_reports_to ) ) ) {
			throw new ClientException( 'Reports To user is invalid', ERROR_INVALID_FIELD_VALUE, array( 'reports_to' ) );
		}

		# Reject reporting cycles by walking the proposed manager chain.
		$t_manager_id = $t_reports_to;
		$t_seen = array();
		while( $t_manager_id !== 0 && !isset( $t_seen[$t_manager_id] ) ) {
			if( $t_manager_id === $this->user_id ) {
				throw new ClientException( 'Reports To would create a cycle', ERROR_INVALID_FIELD_VALUE, array( 'reports_to' ) );
			}
			$t_seen[$t_manager_id] = true;
			$t_manager = user_cache_row( $t_manager_id, false );
			$t_manager_id = $t_manager === false ? 0 : (int)( $t_manager['reports_to'] ?? 0 );
		}

		$t_meeting_invite = (int)( $t_profile['meeting_invite'] ?? 0 );
		if( !in_array( $t_meeting_invite, array( 0, 1, 2 ), true ) ) {
			$t_meeting_invite = 0;
		}

		$t_email_secondary = trim( (string)( $t_profile['email_secondary'] ?? '' ) );
		if( !is_blank( $t_email_secondary ) && !filter_var( $t_email_secondary, FILTER_VALIDATE_EMAIL ) ) {
			throw new ClientException( 'Notification email is invalid', ERROR_INVALID_FIELD_VALUE, array( 'email_secondary' ) );
		}

		$this->fields = array(
			'realname' => mb_substr( string_normalize( (string)( $t_profile['realname'] ?? '' ) ), 0, DB_FIELD_SIZE_REALNAME ),
			'position_title' => mb_substr( trim( (string)( $t_profile['position_title'] ?? '' ) ), 0, DB_FIELD_SIZE_POSITION_TITLE ),
			'company' => mb_substr( trim( (string)( $t_profile['company'] ?? '' ) ), 0, DB_FIELD_SIZE_COMPANY ),
			'phone' => mb_substr( trim( (string)( $t_profile['phone'] ?? '' ) ), 0, DB_FIELD_SIZE_PHONE ),
			'department' => mb_substr( trim( (string)( $t_profile['department'] ?? '' ) ), 0, DB_FIELD_SIZE_DEPARTMENT ),
			'reports_to' => $t_reports_to,
			'alternative' => mb_substr( trim( (string)( $t_profile['alternative'] ?? '' ) ), 0, DB_FIELD_SIZE_ALTERNATIVE ),
			'meeting_invite' => $t_meeting_invite,
			'email_secondary' => mb_substr( $t_email_secondary, 0, 191 ),
		);
	}

	/**
	 * Persist the validated profile fields.
	 *
	 * @return array Command response.
	 */
	protected function process() {
		user_set_fields( $this->user_id, $this->fields );
		event_signal( 'EVENT_MANAGE_USER_UPDATE', array( $this->user_id ) );

		return array( 'user' => user_get_row( $this->user_id ) );
	}
}
