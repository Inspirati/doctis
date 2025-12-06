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

require_api( 'license_api.php' );

$t_soap_dir = dirname( __DIR__, 2 ) . '/api/soap/';
require_once( $t_soap_dir . 'mc_api.php' );
require_once( $t_soap_dir . 'mc_enum_api.php' );

use Mantis\Exceptions\ClientException;

/**
 * A command that updates a license.
 */
class LicenseUpdateCommand extends Command {
	/**
	 * License Id
	 * @var integer
	 */
	private $id;

	/**
	 * License Name
	 * @var string
	 */
	private $name;

	/**
	 * License Description
	 * 
	 * @var string
	 */
	private $description;

	/**
	 * License View State
	 * 
	 * @var int
	 */
	private $view_state;

	/*
	 * License Inherit Global Categories
	 * 
	 * @var int
	 */
	// private $inherit_global;

	/**
	 * License Status
	 * 
	 * @var int
	 */
	private $status;

	/**
	 * License Enabled
	 * 
	 * @var int
	 */
	private $enabled;

	/**
	 * Constructor
	 *
	 * $p_data['query'] is expected to contain:
	 * - id (integer)
	 *
	 * $p_data['payload'] is expected to a subset of the following fields:
	 * - id (integer)
	 * - name (string)
	 * - description (string)
	 * - view_state (int)
	 * - status (int)
	 * - enabled (int)
	 *
	 * @param array $p_data The command data.
	 */
	function __construct( array $p_data ) {
		parent::__construct( $p_data );
	}

	/**
	 * Validate the inputs and access level.
	 *
	 * @throws ClientException
	 */
	protected function validate() {
		$this->id = (int)$this->query( 'id' );
		if( $this->id == ALL_LICENSES || $this->id < 1 ) {
			throw new ClientException(
				'License id is invalid',
				ERROR_INVALID_FIELD_VALUE,
				array( 'id' ) );
		}

		$t_license = $this->data['payload'];
		if( isset( $t_license['id'] ) && (int)$t_license['id'] != $this->id ) {
			throw new ClientException(
				'License id in payload does not match id in query',
				ERROR_INVALID_FIELD_VALUE,
				array( 'id' ) );
		}

		if( !license_exists( $this->id ) ) {
			throw new ClientException(
				'License not found',
				ERROR_LICENSE_NOT_FOUND,
				array( $this->id ) );
		}

		$t_user_id = auth_get_current_user_id();
		if( !access_has_license_level( config_get( 'manage_license_threshold', null, $t_user_id, $this->id ) ) ) {
			throw new ClientException(
				'Access denied to update license',
				ERROR_ACCESS_DENIED );
		}

		$this->name = $this->payload( 'name', license_get_field( $this->id, 'name' ) );
		if( is_blank( $this->name ) ) {
			throw new ClientException(
				'License name cannot be blank',
				ERROR_EMPTY_FIELD,
				array( 'name' ) );
		}

		$this->description = $this->payload( 'description', license_get_field( $this->id, 'description' ) );
		$this->enabled = (int)$this->payload( 'enabled', license_get_field( $this->id, 'enabled' ) );

		$t_view_state_ref = $this->payload( 'view_state', array( 'id' => license_get_field( $this->id, 'view_state' ) ) );
		$this->view_state = mci_get_license_view_state_id( $t_view_state_ref );

		$t_status_ref = $this->payload( 'status', array( 'id' => license_get_field( $this->id, 'status' ) ) );
		$this->status = mci_get_license_status_id( $t_status_ref );

		# check to make sure a modified license doesn't already exist
		if( $this->name != license_get_name( $this->id ) ) {
			if( !license_is_name_unique( $this->name ) ) {
				throw new ClientException(
					'License name already exists',
					ERROR_LICENSE_NAME_NOT_UNIQUE,
					array( $this->name ) );
			}
		}
	}

	/**
	 * Process the command.
	 *
	 * @return array
	 */
	protected function process() {
		license_update(
			$this->id,
			$this->name,
			$this->description,
			$this->status,
			$this->view_state,
			$this->enabled,
		);

		license_clear_cache( $this->id );

		event_signal( 'EVENT_MANAGE_LICENSE_UPDATE', array( $this->id ) );

		$t_result = array();
		if( $this->option('return_license', false ) ) {
			$t_user_id = auth_get_current_user_id();
			$t_lang = mci_get_user_lang( $t_user_id );
			$t_result['license'] = mci_license_get( $this->id, $t_lang, /* detail */ true );
		} else {
			$t_result['id'] = $this->id;
		}

		return $t_result;
	}
}
