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
require_api( 'user_pref_api.php' );
require_api( 'license_api.php' );
require_api( 'dwg_api.php' );

$t_soap_dir = dirname( __DIR__, 2 ) . '/api/soap/';
require_once( $t_soap_dir . 'mc_api.php' );

/**
 * Sample:
 * {
 *   "payload": {
 *     "project": { "name": "My Project" },    // can also be { "id : 1 }
 *     "user": { "name": "administrator" },    // can also be { "id": 1 }
 *     "access_level": { "name": "developer" } // can also be { "id": 25 }
 *   }
 * }
 */

/**
 * A command to add a user to a project or update their access to a project.
 */
class LicenseDwgAddCommand extends Command {
	/**
	 * @var integer The project id
	 */
	private $project_id;

	/**
	 * @var integer The license id
	 */
	private $license_id;

	/**
	 * @var integer The document id
	 */
	private $document_id;

	/**
	 * The minimum access level, users with access level greater or equal to this access level
	 * will be returned.
	 *
	 * @var integer
	 */
	private $access_level;

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

		# A license's project_id may legitimately be ALL_PROJECTS (a global
		# license), so unlike a real project id, 0 is not invalid here.
		# Actual non-existent projects are still caught by
		# project_ensure_exists() below.
		$this->project_id = mci_get_project_id( $t_project );

		$t_license = $this->payload( 'license' );
		if( is_null( $t_license ) ) {
			throw new ClientException( 'License not specified', ERROR_EMPTY_FIELD, array( 'license' ) );
		}

		$this->license_id = mci_get_license_id( $t_license );
		if( $this->license_id < 1 ) {
			$t_match_str = preg_replace('/[^A-Z0-9]/', '', strtoupper( $t_license['name'] ) );
			$this->license_id = license_get_id_by_name( $t_match_str, 0 );

		// $t_license_id = license_get_id_by_name( $p_license['name'], $p_default );

			if( $this->license_id < 1 ) {
				throw new ClientException( 'Invalid License', ERROR_INVALID_FIELD_VALUE, array( 'license' ) );
			}
		}

		$t_dwg = $this->payload( 'document' );
		if( is_null( $t_dwg ) ) {
			throw new ClientException( 'Document not specified', ERROR_EMPTY_FIELD, array( 'document' ) );
		}

		$this->document_id = mci_get_document_id( $t_dwg, $this->project_id );
		if( $this->document_id < 1 ) {
			throw new ClientException( 'Invalid Document', ERROR_INVALID_FIELD_VALUE, array( 'document' ) );
		}

		// $this->access_level = access_parse_array( $t_access_level );
		$this->access_level = 0;

		dwg_ensure_exists( $this->document_id );
		if( 0 !=  $this->project_id ) {
			project_ensure_exists( $this->project_id );
		}
		license_ensure_exists( $this->license_id );

		$t_actor_id = auth_get_current_user_id();

		# We should check both since we are in the project section and an
		# admin might raise the first threshold and not realize they need
		# to raise the second
		$t_access_check = access_has_license_level(
			config_get( 'manage_license_threshold', /* default */ null, $t_actor_id, $this->project_id ),
			$this->license_id );

		$t_access_check = $t_access_check &&
			access_has_license_level(
				config_get( 'license_user_threshold', /* default */ null, $t_actor_id, $this->project_id ),
				$this->license_id );

		if( !$t_access_check ) {
			throw new ClientException( "Access Denied", ERROR_ACCESS_DENIED );
		}
	}

	/**
	 * Process the command.
	 *
	 * @return void
	 */
	protected function process() {
		# This is an upsert, it will work for adding a user or modifying their access level.
		license_add_dwg( $this->license_id, $this->document_id, $this->access_level );
	}
}
