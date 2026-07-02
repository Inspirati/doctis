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

use Mantis\Exceptions\ClientException;

require_api( 'authentication_api.php' );
require_api( 'constant_inc.php' );
require_api( 'config_api.php' );
require_api( 'helper_api.php' );
require_api( 'license_api.php' );
require_api( 'user_api.php' );

$t_soap_dir = dirname( __DIR__, 2 ) . '/api/soap/';
require_once( $t_soap_dir . 'mc_api.php' );

/**
 * A command that removes a user's access to a license. If user id 0
 * (ALL_USERS) is specified, then all users will be removed from the license.
 *
 * Sample:
 * {
 *   "payload": {
 *     "project": { "id": 1 },                  // license's project scope; may be ALL_PROJECTS
 *     "license": { "id": 3 },                  // can also be { "name": "SC Clearance" }
 *     "user": { "name": "administrator" }      // can also be { "id": 1 }, or 0 for ALL_USERS
 *   }
 * }
 */
class LicenseUsersDeleteCommand extends Command {
	/**
	 * @var integer The project id (license scope; may be ALL_PROJECTS)
	 */
	private $project_id;

	/**
	 * @var integer The license id
	 */
	private $license_id;

	/**
	 * @var integer The user id (ALL_USERS to remove every user)
	 */
	private $user_id;

	/**
	 * @var integer The logged in user id.
	 */
	private $actor_id;

	/**
	 * Constructor
	 *
	 * @param array $p_data The command data.
	 */
	function __construct( array $p_data ) {
		parent::__construct( $p_data );
	}

	/**
	 * Validate the data.
	 *
	 * @return void
	 * @throws ClientException
	 */
	function validate() {
		$t_project = $this->payload( 'project' );
		if( is_null( $t_project ) ) {
			throw new ClientException( 'Project not specified', ERROR_EMPTY_FIELD, array( 'project' ) );
		}

		# A license's project_id may legitimately be ALL_PROJECTS (a global license).
		$this->project_id = mci_get_project_id( $t_project );

		$t_license = $this->payload( 'license' );
		if( is_null( $t_license ) ) {
			throw new ClientException( 'License not specified', ERROR_EMPTY_FIELD, array( 'license' ) );
		}

		$this->license_id = mci_get_license_id( $t_license );
		if( $this->license_id < 1 ) {
			throw new ClientException( 'Invalid License', ERROR_INVALID_FIELD_VALUE, array( 'license' ) );
		}

		$t_user = $this->payload( 'user' );
		if( is_null( $t_user ) ) {
			throw new ClientException( 'User not specified', ERROR_EMPTY_FIELD, array( 'user' ) );
		}

		$this->user_id = mci_get_user_id( $t_user, /* default */ null, /* allow all users */ true );
		if( is_null( $this->user_id ) ) {
			throw new ClientException( 'Invalid User', ERROR_INVALID_FIELD_VALUE, array( 'user' ) );
		}

		# ALL_USERS is a valid case for removing every user from the license
		if( $this->user_id != ALL_USERS ) {
			user_ensure_exists( $this->user_id );
		}

		if( ALL_PROJECTS != $this->project_id ) {
			project_ensure_exists( $this->project_id );
		}
		license_ensure_exists( $this->license_id );

		$this->actor_id = auth_get_current_user_id();

		# We should check both since we are in the license section and an
		# admin might raise the first threshold and not realize they need
		# to raise the second
		$t_access_check = access_has_license_level(
			config_get( 'manage_license_threshold', /* default */ null, $this->actor_id, $this->project_id ),
			$this->license_id );

		$t_access_check = $t_access_check &&
			access_has_license_level(
				config_get( 'license_user_threshold', /* default */ null, $this->actor_id, $this->project_id ),
				$this->license_id );

		if( !$t_access_check ) {
			throw new ClientException( "Access Denied", ERROR_ACCESS_DENIED );
		}
	}

	/**
	 * Process the command.
	 *
	 * @return array Command response
	 */
	protected function process() {
		if( $this->user_id === ALL_USERS ) {
			license_remove_all_users( $this->license_id );
		} else {
			license_remove_user( $this->license_id, $this->user_id );
		}

		return array();
	}
}
