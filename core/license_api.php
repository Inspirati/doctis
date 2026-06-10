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
 * License API
 *
 * @package CoreAPI
 * @subpackage LicenseAPI
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses bug_api.php
 * @uses category_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses custom_field_api.php
 * @uses database_api.php
 * @uses error_api.php
 * @uses file_api.php
 * @uses lang_api.php
 * @uses news_api.php
 * @uses license_hierarchy_api.php
 * @uses user_api.php
 * @uses user_pref_api.php
 * @uses utility_api.php
 * @uses version_api.php
 */

require_api( 'bug_api.php' );
require_api( 'dwg_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'custom_field_api.php' );
require_api( 'database_api.php' );
require_api( 'error_api.php' );
require_api( 'file_api.php' );
require_api( 'lang_api.php' );
require_api( 'news_api.php' );
// require_api( 'license_hierarchy_api.php' );
require_api( 'user_api.php' );
require_api( 'user_pref_api.php' );
require_api( 'utility_api.php' );
require_api( 'version_api.php' );

$g_cache_license = array();
$g_cache_license_missing = array();
$g_cache_license_all = false;

use Mantis\Exceptions\ClientException;

/**
 * Checks if there are no licenses defined.
 * @return boolean true if there are no licenses defined, false otherwise.
 * @access public
 */
function license_table_empty() {
	global $g_cache_license;

	# If licenses already cached, use the cache.
	if( isset( $g_cache_license ) && count( $g_cache_license ) > 0 ) {
		return false;
	}

	# Otherwise, check if the licenses table contains at least one license.
	$t_query = new DbQuery();
	$t_query->sql( 'SELECT * FROM {license}' );
	$t_query->set_limit( 1 );
	$t_result = $t_query->execute();

	return db_num_rows( $t_result ) == 0;
}

/**
 * Cache a license row if necessary and return the cached copy
 *  If the second parameter is true (default), trigger an error
 *  if the license can't be found.  If the second parameter is
 *  false, return false if the license can't be found.
 * @param integer $p_license_id     A license identifier.
 * @param boolean $p_trigger_errors Whether to trigger errors.
 * @return array|boolean
 * @throws ClientException
 */
function license_cache_row( $p_license_id, $p_trigger_errors = true ) {
	global $g_cache_license, $g_cache_license_missing;
	$c_license_id = (int)$p_license_id;

	if( $c_license_id == ALL_LICENSES ) {
		return false;
	}


	if( isset( $g_cache_license[$c_license_id] ) ) {
		return $g_cache_license[$c_license_id];
	} else if( isset( $g_cache_license_missing[$c_license_id] ) ) {
		return false;
	}

	$t_query = new DbQuery();
	$t_query->sql( 'SELECT * FROM {license} WHERE id=' . $t_query->param( $p_license_id ) );
	$t_row = $t_query->fetch();

	if( $t_row === false ) {
		$g_cache_license_missing[$c_license_id] = true;

		if( $p_trigger_errors ) {
			throw new ClientException( "License #$p_license_id not found", ERROR_LICENSE_NOT_FOUND, array( $p_license_id ) );
		}

		return false;
	}

	$g_cache_license[$c_license_id] = $t_row;
	return $t_row;
}

/**
 * Cache license data for array of license ids
 * @param array $p_license_id_array An array of license identifiers.
 * @return void
 */
function license_cache_array_rows( array $p_license_id_array ) {
	global $g_cache_license, $g_cache_license_missing;

	$c_license_id_array = array();
	foreach( $p_license_id_array as $t_license_id ) {
		$c_id = (int)$t_license_id;
		if( !isset( $g_cache_license[$c_id] ) && !isset( $g_cache_license_missing[$c_id] ) ) {
			$c_license_id_array[] = $c_id;
		}
	}

	if( empty( $c_license_id_array ) ) {
		return;
	}

	$t_query = new DbQuery();
	$t_query->sql(
		'SELECT * FROM {license} WHERE '
		. $t_query->sql_in( 'id', $c_license_id_array )
	);
	$t_query->execute();

	$t_licenses_found = array();
	while( $t_row = $t_query->fetch() ) {
		$t_id = (int)$t_row['id'];
		$g_cache_license[$t_id] = $t_row;
		$t_licenses_found[$t_id] = true;
	}

	foreach ( $c_license_id_array as $c_license_id ) {
		if( !isset( $t_licenses_found[$c_license_id] ) ) {
			$g_cache_license_missing[$c_license_id] = true;
		}
	}
}

/**
 * Cache all license rows and return an array of them
 * @return array
 */
function license_cache_all() {
	global $g_cache_license, $g_cache_license_all;

	if( !$g_cache_license_all ) {
		$t_query = new DbQuery( 'SELECT * FROM {license}' );
		$t_query->execute();

		while( $t_row = $t_query->fetch() ) {
			$g_cache_license[(int)$t_row['id']] = $t_row;
		}

		$g_cache_license_all = true;
	}

	return $g_cache_license;
}

/**
 * Clear the license cache (or just the given id if specified)
 * @param integer $p_license_id A license identifier.
 * @return void
 */
function license_clear_cache( $p_license_id = null ) {
	global $g_cache_license, $g_cache_license_missing, $g_cache_license_all;

	if( null === $p_license_id ) {
		$g_cache_license = array();
		$g_cache_license_missing = array();
		$g_cache_license_all = false;
	} else {
		unset( $g_cache_license[(int)$p_license_id] );
		unset( $g_cache_license_missing[(int)$p_license_id] );
		$g_cache_license_all = false;
	}
}

/**
 * Check if license is enabled.
 * @param integer $p_license_id The license id.
 * @return boolean
 */
function license_enabled( $p_license_id ) {
	return license_get_field( $p_license_id, 'enabled' ) ? true : false;
}

/**
 * check to see if license exists by id
 * return true if it does, false otherwise
 * @param integer $p_license_id A license identifier.
 * @return boolean
 */
function license_exists( $p_license_id ) {
	# we're making use of the caching function here.  If we succeed in caching the license then it exists and is
	# now cached for use by later function calls.  If we can't cache it we return false.
	if( false == license_cache_row( $p_license_id, false ) ) {
		return false;
	} else {
		return true;
	}
}

/**
 * Check to see if license exists by id.
 *
 * Errors if it does not exist, otherwise let execution continue undisturbed.
 *
 * @param integer $p_license_id A license identifier.
 *
 * @return void
 * @throws ClientException if license does not exist
 */
function license_ensure_exists( $p_license_id ) {
	if( !license_exists( $p_license_id ) ) {
		throw new ClientException(
			"License $p_license_id not found",
			ERROR_LICENSE_NOT_FOUND,
			array( $p_license_id ) );
	}
}

/**
 * check to see if license exists by name
 * @param string  $p_name       The license name.
 * @param integer $p_exclude_id Optional license id to exclude from the check,
 *                              to allow uniqueness check when updating.
 * @return boolean
 */
function license_is_name_unique( $p_name, $p_exclude_id = null ) {
	$t_match_str = preg_replace('/[^A-Z0-9]/', '', strtoupper( $p_name ) );
	$t_query = new DbQuery();
	// $t_query->sql( 'SELECT COUNT(*) FROM {license} WHERE name=' . $t_query->param( $p_name ) );
	$t_query->sql( 'SELECT COUNT(*) FROM {license} WHERE name=' . $t_query->param( $p_name ) . ' OR match_str=' . $t_query->param( $t_match_str ) );
	if( $p_exclude_id ) {
		$t_query->append_sql( ' AND id <> ' . $t_query->param( (int)$p_exclude_id ) );
	}
	$t_query->execute();

	return $t_query->value() == 0;
}

/**
 * check to see if license exists by id
 * if it doesn't exist then error
 * otherwise let execution continue undisturbed
 * @param string  $p_name       The license name.
 * @param integer $p_exclude_id Optional license id to exclude from the check,
 *                              to allow uniqueness check when updating.
 * @return void
 */
function license_ensure_name_unique( $p_name, $p_exclude_id = null ) {
	if( !license_is_name_unique( $p_name, $p_exclude_id ) ) {
		trigger_error( ERROR_LICENSE_NAME_NOT_UNIQUE, ERROR );
	}
}

/**
 * check to see if the user/license combo already exists
 * returns true is duplicate is found, otherwise false
 * @param integer $p_license_id A license identifier.
 * @param integer $p_user_id    A user id identifier.
 * @return boolean
 */
function license_includes_user( $p_license_id, $p_user_id ) {
	$t_query = new DbQuery();
	$t_query->sql( 'SELECT COUNT(*) FROM {license_user_list}
		WHERE license_id=' . $t_query->param( $p_license_id ) . '
		AND user_id=' . $t_query->param( $p_user_id )
	);
	$t_query->execute();

	return $t_query->value() != 0;
}

/**
 * Make sure that the license file path is valid: add trailing slash and
 * set it to blank if equal to default path
 * @param string $p_file_path A file path.
 * @return string
 * @access public
 */
// function validate_license_file_path( $p_file_path ) {
// 	if( !is_blank( $p_file_path ) ) {
// 		# Make sure file path has trailing slash
// 		$p_file_path = terminate_directory_path( $p_file_path );

// 		# If the provided path is the same as the default, make the path blank.
// 		# This means that if the default upload path is changed, you don't have
// 		# to update the upload path for every single license.
// 		if( !strcmp( $p_file_path, config_get_global( 'absolute_path_default_upload_folder' ) ) ) {
// 			$p_file_path = '';
// 		} else {
// 			file_ensure_valid_upload_path( $p_file_path );
// 		}
// 	}

// 	return $p_file_path;
// }



/**
 * Create a new license
 * @param string  $p_name           The name of the license being created.
 * @param string  $p_description    A description for the license.
 * @param integer $p_status         The status of the license.
 * @param integer $p_view_state     The view state of the license - public or private.
 * @param string  $p_file_path      The attachment file path for the license, if not storing in the database.
 * @param boolean $p_enabled        Whether the license is enabled.
 * @param boolean $p_inherit_global Whether the license inherits global categories.
 * @return integer
 */
// function license_create( $p_name, $p_description, $p_status, $p_view_state = VS_PUBLIC, $p_file_path = '', $p_enabled = true, $p_inherit_global = true ) {
function license_create( $p_name, $p_description, $p_status, $p_view_state = VS_PUBLIC, $p_enabled = true ) {
	$c_enabled = (bool)$p_enabled;

	if( is_blank( $p_name ) ) {
		trigger_error( ERROR_LICENSE_NAME_INVALID, ERROR );
	}

	$t_name = trim( $p_name );

	license_ensure_name_unique( $t_name );

	# License does not exist yet, so we get global config
	// if( DATABASE !== config_get( 'file_upload_method', null, null, ALL_LICENSES ) ) {
	// 	$p_file_path = validate_license_file_path( $p_file_path );
	// }

	// $t_match_str = preg_replace('/[^a-zA-Z0-9]/', '', $t_name );
	$t_match_str = preg_replace('/[^A-Z0-9]/', '', strtoupper( $t_name ) );

	$t_param = array(
		'name' => $t_name,
		'match_str' => $t_match_str,
		'status' => (int)$p_status,
		'enabled' => $c_enabled,
		'view_state' => (int)$p_view_state,
		// 'file_path' => $p_file_path,
		'description' => $p_description,
		// 'inherit_global' => $p_inherit_global,
	);

	$t_query = new DbQuery( 'INSERT INTO {license}
		( ' . implode( ', ', array_keys( $t_param ) ) . ' )
		VALUES :param'
	);

	$t_query->bind( 'param', $t_param );
	$t_query->execute();

	# return the id of the new license
	return db_insert_id( db_get_table( 'license' ) );
}

/**
 * Delete a license
 * @param integer $p_license_id A license identifier.
 * @return void
 */
function license_delete( $p_license_id ) {
	$t_email_notifications = config_get( 'enable_email_notification' );

	# temporarily disable all notifications
	config_set_cache( 'enable_email_notification', OFF, CONFIG_TYPE_INT );

	# Delete the bugs
	// bug_delete_all( $p_license_id );

	# Delete the documents
	// dwg_delete_all( $p_license_id );

	# Delete associations with custom field definitions.
	// custom_field_unlink_all( $p_license_id );

	# Delete the license categories
	// category_remove_all( $p_license_id );

	# Delete the license versions
	// version_remove_all( $p_license_id );

	# Delete relations to other licenses
	// license_hierarchy_remove_all( $p_license_id );

	# Delete the license files
	// license_delete_all_files( $p_license_id );

	# Set default to ALL_LICENSES for all users who had the license as default
	// user_pref_clear_license_default( $p_license_id );

	# Delete the records assigning users to this license
	license_remove_all_users( $p_license_id );

	# Delete the records assigning dwgs to this license
	license_remove_all_dwgs( $p_license_id );

	# Delete all news entries associated with the license being deleted
	// news_delete_all( $p_license_id );

	# Delete license specific configurations
	// config_delete_license( $p_license_id );

	# Delete any user prefs that are license specific
	// user_pref_db_delete_license( $p_license_id );

	# Delete the license entry
	$t_query = new DbQuery( 'DELETE FROM {license} WHERE id=:license_id' );
	$t_query->bind( 'license_id', $p_license_id );
	$t_query->execute();

	config_set_cache( 'enable_email_notification', $t_email_notifications, CONFIG_TYPE_INT );

	license_clear_cache( $p_license_id );
}

/**
 * Update a license
 * @param integer $p_license_id     The license identifier being updated.
 * @param string  $p_name           The license name.
 * @param string  $p_description    A description of the license.
 * @param integer $p_status         The current status of the license.
 * @param integer $p_view_state     The view state of the license - public or private.
 * @param string  $p_file_path      The attachment file path for the license, if not storing in the database.
 * @param boolean $p_enabled        Whether the license is enabled.
 * @param boolean $p_inherit_global Whether the license inherits global categories.
 * @return void
 */
// function license_update( $p_license_id, $p_name, $p_description, $p_status, $p_view_state, $p_file_path, $p_enabled, $p_inherit_global ) {
function license_update( $p_license_id, $p_name, $p_description, $p_status, $p_view_state, $p_enabled ) {
	$p_license_id = (int)$p_license_id;
	$c_enabled = (bool)$p_enabled;
	// $c_inherit_global = (bool)$p_inherit_global;

	if( is_blank( $p_name ) ) {
		trigger_error( ERROR_LICENSE_NAME_INVALID, ERROR );
	}

	$t_new_name = trim( $p_name );
	$t_old_name = license_get_field( $p_license_id, 'name' );

	# If license is becoming private, save current user's access level
	# so we can add them to the license afterwards so they don't lock
	# themselves out
	$t_old_view_state = license_get_field( $p_license_id, 'view_state' );
	$t_is_becoming_private = VS_PRIVATE == $p_view_state && VS_PRIVATE != $t_old_view_state;
	if( $t_is_becoming_private ) {
		$t_user_id = auth_get_current_user_id();
		$t_access_level = user_get_access_level( $t_user_id, $p_license_id );
		$t_manage_license_threshold = config_get( 'manage_license_threshold' );
	}

	if( strcasecmp( $t_new_name, $t_old_name ) != 0 ) {
		license_ensure_name_unique( $p_name, $p_license_id );
	}

	// if( DATABASE !== config_get( 'file_upload_method', null, null, $p_license_id ) ) {
	// 	$p_file_path = validate_license_file_path( $p_file_path );
	// }

	$t_param = array(
		'name' => $t_new_name,
		'status' => (int)$p_status,
		'enabled' => $c_enabled,
		'view_state' => (int)$p_view_state,
		// 'file_path' => $p_file_path,
		'description' => $p_description,
		// 'inherit_global' => $c_inherit_global,
	);

	$t_columns = '';
	foreach( array_keys( $t_param ) as $t_col ) {
		$t_columns .= "\n\t\t$t_col = :$t_col,";
	}

	$t_param['license_id'] = $p_license_id;
	$t_query = new DbQuery( 'UPDATE {license} SET'
		. rtrim( $t_columns, ',' ) . '
		WHERE id = :license_id'
	);

	$t_query->execute( $t_param );

	license_clear_cache( $p_license_id );

	# User just locked themselves out of the license by making it private,
	# so we add them to the license with their previous access level
	// if( $t_is_becoming_private && !access_has_license_level( $t_manage_license_threshold, $p_license_id ) ) {
	// 	license_add_user( $p_license_id, $t_user_id, $t_access_level );
	// }

	// if( $t_is_becoming_private ) {
	// 	user_pref_clear_invalid_license_default( $p_license_id );
	// }
}

/**
 * Copy custom fields
 * @param integer $p_destination_id The destination license identifier.
 * @param integer $p_source_id      The source license identifier.
 * @return void
 */
// function license_copy_custom_fields( $p_destination_id, $p_source_id ) {
// 	$t_custom_field_ids = custom_field_get_linked_ids( $p_source_id );
// 	foreach( $t_custom_field_ids as $t_custom_field_id ) {
// 		if( !custom_field_is_linked( $t_custom_field_id, $p_destination_id ) ) {
// 			custom_field_link( $t_custom_field_id, $p_destination_id );
// 			$t_sequence = custom_field_get_sequence( $t_custom_field_id, $p_source_id );
// 			custom_field_set_sequence( $t_custom_field_id, $p_destination_id, $t_sequence );
// 		}
// 	}
// }

/**
 * Get the id of the license with the specified name
 * @param string $p_license_name License name to retrieve.
 * @param integer|boolean $p_default The default value or false if the default should not be applied.
 * @return null|integer
 */
function license_get_id_by_name( $p_license_name, $p_default = ALL_LICENSES ) {
	$t_match_str = preg_replace('/[^A-Z0-9]/', '', strtoupper( $p_license_name ) );
	$t_query = new DbQuery();
	$t_query->sql(
		'SELECT id FROM {license} WHERE name = '
		. $t_query->param( $p_license_name ) .
		' OR match_str = '
		. $t_query->param( $t_match_str )
	);
	$t_id = $t_query->value();
	if( $t_id ) {
		return $t_id;
	} elseif( $p_default === false ) {
		return null;
	} else {
		return $p_default;
	}
}

/**
 * Return the row describing the given license
 * @param integer $p_license_id     A license identifier.
 * @param boolean $p_trigger_errors Whether to trigger errors.
 * @return array
 */
function license_get_row( $p_license_id, $p_trigger_errors = true ) {
	return license_cache_row( $p_license_id, $p_trigger_errors );
}

/**
 * Return all rows describing all licenses
 * @return array
 */
function license_get_all_rows() {
	return license_cache_all();
}

/**
 * Return the specified field of the specified license
 * @param integer $p_license_id     A license identifier.
 * @param string  $p_field_name     The field name to retrieve.
 * @param boolean $p_trigger_errors Whether to trigger errors.
 * @return string
 */
function license_get_field( $p_license_id, $p_field_name, $p_trigger_errors = true ) {
	$t_row = license_get_row( $p_license_id, $p_trigger_errors );

	if( isset( $t_row[$p_field_name] ) ) {
		return $t_row[$p_field_name];
	} else if( $p_trigger_errors ) {
		error_parameters( $p_field_name );
		trigger_error( ERROR_DB_FIELD_NOT_FOUND, WARNING );
	}

	return '';
}

/**
 * Return the name of the license
 * Handles ALL_LICENSES by returning the internationalized string for All Licenses
 * @param integer $p_license_id     A license identifier.
 * @param boolean $p_trigger_errors Whether to trigger errors.
 * @return string
 */
function license_get_name( $p_license_id, $p_trigger_errors = true ) {
	if( ALL_LICENSES == $p_license_id ) {
		return lang_get( 'all_licenses' );
	} else {
		return license_get_field( $p_license_id, 'name', $p_trigger_errors );
	}
}

/**
 * Return the user's local (overridden) access level on the license or false
 *  if the user is not listed on the license
 * @param integer $p_license_id A license identifier.
 * @param integer $p_user_id    A user identifier.
 * @return integer
 * @deprecated     access_get_local_level() should be used in preference to this function
 *                 This function has been deprecated in version 2.6
 */
function license_get_local_user_access_level( $p_license_id, $p_user_id ) {
	error_parameters( __FUNCTION__ . '()', 'access_get_local_level()' );
	trigger_error( ERROR_DEPRECATED_SUPERSEDED, DEPRECATED );
	return access_get_local_level( $p_user_id, $p_license_id );
}

/**
 * return the descriptor holding all the info from the license user list
 * for the specified license
 * @param integer $p_license_id A license identifier.
 * @return array
 */
function license_get_local_user_rows( $p_license_id ) {
	$t_query = new DbQuery();
	$t_query->sql(
		'SELECT * FROM {license_user_list} WHERE license_id='
		. $t_query->param( (int)$p_license_id )
	);
	return $t_query->fetch_all();
}

/**
 * Return an array of info about users who have access to the the given license
 * For each user we have 'id', 'username', and 'access_level' (overall access level)
 * If the second parameter is given, return only users with an access level
 * higher than the given value.
 * if the first parameter is given as 'ALL_LICENSES', return the global access level (without
 * any reference to the specific license
 * @param integer $p_license_id           A license identifier.
 * @param integer $p_access_level         Access level.
 * @param boolean $p_include_global_users Whether to include global users.
 * @return array List of users, array key is user ID
 */
function license_get_all_dwg_rows( $p_license_id = ALL_LICENSES, $p_access_level = ANYBODY, $p_include_global_users = true ) {
	$c_license_id = (int)$p_license_id;

	$t_users = array();
	return $t_users;
}

function license_get_all_user_rows( $p_license_id = ALL_LICENSES, $p_access_level = ANYBODY, $p_include_global_users = true ) {
	$c_license_id = (int)$p_license_id;

	# Optimization when access_level is NOBODY
	if( NOBODY == $p_access_level ) {
		return array();
	}

	$t_on = ON;
	$t_users = array();

	$t_global_access_level = $p_access_level;
	if( $c_license_id != ALL_LICENSES && $p_include_global_users ) {

		# looking for specific license
		if( VS_PRIVATE == license_get_field( $p_license_id, 'view_state' ) ) {
			# @todo (thraxisp) this is probably more complex than it needs to be
			# When a new license is created, those who meet 'private_license_threshold' are added
			# automatically, but don't have an entry in license_user_list table.
			#  if they did, you would not have to add global levels.
			$t_private_license_threshold = config_get( 'private_license_threshold' );
			if( is_array( $t_private_license_threshold ) ) {
				if( is_array( $p_access_level ) ) {
					# both private threshold and request are arrays, use intersection
					$t_global_access_level = array_intersect( $p_access_level, $t_private_license_threshold );
				} else {
					# private threshold is an array, but request is a number, use values in threshold higher than request
					$t_global_access_level = array();
					foreach( $t_private_license_threshold as $t_threshold ) {
						if( $p_access_level <= $t_threshold ) {
							$t_global_access_level[] = $t_threshold;
						}
					}
				}
			} else {
				if( is_array( $p_access_level ) ) {
					# private threshold is a number, but request is an array, use values in request higher than threshold
					$t_global_access_level = array();
					foreach( $p_access_level as $t_threshold ) {
						if( $t_threshold >= $t_private_license_threshold ) {
							$t_global_access_level[] = $t_threshold;
						}
					}
				} else {
					# both private threshold and request are numbers, use maximum
					$t_global_access_level = max( $p_access_level, $t_private_license_threshold );
				}
			}
		}
	}

	if( $p_include_global_users ) {
		$t_query = new DbQuery();
		$t_query->sql( 'SELECT id, username, realname, access_level
			FROM {user}
			WHERE enabled = ' . $t_query->param( $t_on ) . ' 
				AND '
		);
		if( is_array( $t_global_access_level ) ) {
			if( empty( $t_global_access_level ) ) {
				$t_query->append_sql( 'access_level >= ' . $t_query->param( NOBODY ) );
			} else {
				$t_query->append_sql( $t_query->sql_in( 'access_level', $t_global_access_level ) );
			}
		} else {
			$t_query->append_sql( 'access_level >= ' . $t_query->param( $t_global_access_level ) );
		}
		$t_query->execute();

		while( $t_row = $t_query->fetch() ) {
			$t_users[(int)$t_row['id']] = $t_row;
		}
	}

	if( $c_license_id != ALL_LICENSES ) {
		# Get the license overrides
		$t_query = new DbQuery();
		$t_query->sql( 'SELECT u.id, u.username, u.realname, l.access_level
			FROM {license_user_list} l, {user} u
			WHERE l.user_id = u.id
			AND u.enabled = ' . $t_query->param( $t_on ) . '
			AND l.license_id = ' . $t_query->param( $c_license_id )
		);
		$t_query->execute();

		while( $t_row = $t_query->fetch() ) {
			if( is_array( $p_access_level ) ) {
				$t_keep = in_array( $t_row['access_level'], $p_access_level );
			} else {
				$t_keep = $t_row['access_level'] >= $p_access_level;
			}

			if( $t_keep ) {
				$t_users[(int)$t_row['id']] = $t_row;
			} else {
				# If user's overridden level is lower than required, so remove
				#  them from the list if they were previously there
				unset( $t_users[(int)$t_row['id']] );
			}
		}
	}

	return $t_users;
}

/**
 * Returns the upload path for the specified license, empty string if
 * file_upload_method is DATABASE
 * @param integer $p_license_id A license identifier.
 * @return string upload path
 */
// function license_get_upload_path( $p_license_id ) {
// 	if( DATABASE == config_get( 'file_upload_method', null, ALL_USERS, $p_license_id ) ) {
// 		return '';
// 	}

// 	if( $p_license_id == ALL_LICENSES ) {
// 		$t_path = config_get_global( 'absolute_path_default_upload_folder', '' );
// 	} else {
// 		$t_path = license_get_field( $p_license_id, 'file_path' );
// 		if( is_blank( $t_path ) ) {
// 			$t_path = config_get_global( 'absolute_path_default_upload_folder', '' );
// 		}
// 	}

// 	return $t_path;
// }

/**
 * Add user with the specified access level to a license.
 * @param integer $p_license_id   A license identifier.
 * @param integer $p_user_id      A valid user id identifier.
 * @param integer $p_access_level The access level to add the user with.
 * @return void
 */
function license_add_user( $p_license_id, $p_user_id, $p_access_level ) {
	license_add_users( $p_license_id, array( $p_user_id => $p_access_level ) );
}

function license_add_dwg( $p_license_id, $p_dwg_id, $p_access_level ) {
	license_add_dwgs( $p_license_id, array( $p_dwg_id => $p_access_level ) );
}

/**
 * Update user with the specified access level to a license.
 * @param integer $p_license_id   A license identifier.
 * @param integer $p_user_id      A user identifier.
 * @param integer $p_access_level Access level to set.
 * @return void
 */
function license_update_user_access( $p_license_id, $p_user_id, $p_access_level ) {
	license_add_users( $p_license_id, array( $p_user_id => $p_access_level ) );
}

/**
 * Update or add user with the specified access level to a license.
 * This function involves one more database query than license_update_user_acces() or license_add_user().
 * @param integer $p_license_id   A license identifier.
 * @param integer $p_user_id      A user identifier.
 * @param integer $p_access_level License Access level to grant the user.
 * @return void
 */
function license_set_user_access( $p_license_id, $p_user_id, $p_access_level ) {
	license_add_users( $p_license_id, array( $p_user_id => $p_access_level ) );
}

/**
 * Add or modify multiple users associated to a license with a specific access level.
 * $p_changes is an array of access levels indexed by user_id, such as:
 *   array ( user1 => access_level, user2 => access_level, ... )
 * This function will manage inserts and updates as needed.
 *
 * @param integer $p_license_id   A license identifier.
 * @param array $p_changes        An array of modifications.
 * @return void
 */
function license_add_users( $p_license_id, array $p_changes ) {
	# normalize input
	$t_changes = array();
	foreach( $p_changes as $t_id => $t_value ) {
		if( DEFAULT_ACCESS_LEVEL == $t_value ) {
			$t_changes[(int)$t_id] = user_get_access_level( $t_id );
		} else {
			$t_changes[(int)$t_id] = (int)$t_value;
		}
	}

	$t_user_ids = array_keys( $t_changes );
	if( empty( $t_user_ids ) ) {
		return;
	}

	$t_license_id = (int)$p_license_id;
	$t_query = new DbQuery();
	$t_sql = 'SELECT user_id FROM {license_user_list} 
		WHERE license_id = ' . $t_query->param( $t_license_id ) . ' 
		AND ' . $t_query->sql_in( 'user_id', $t_user_ids );
	$t_query->sql( $t_sql );
	$t_updating = array_column( $t_query->fetch_all(), 'user_id' );

	if( !empty( $t_updating ) ) {
		$t_update = new DbQuery( 'UPDATE {license_user_list} 
			SET status = :new_value 
			WHERE user_id = :user_id AND license_id = :license_id'
		);
		foreach( $t_updating as $t_id ) {
			$t_params = array(
				'license_id' => $t_license_id,
				'user_id' => (int)$t_id,
				'new_value' => $t_changes[$t_id]
			);
			$t_update->execute( $t_params );
			unset( $t_changes[$t_id] );

			# Trigger event for user access modification on license
			event_signal('EVENT_MANAGE_LICENSE_USER_UPDATE', array('user_id' => $t_id, 'license_id' => $p_license_id));
		}
	}
	# remaining items are for insert
	if( !empty( $t_changes ) ) {
		// $t_insert = new DbQuery( 'INSERT INTO {license_user_list} 
		// 	( license_id, user_id, access_level ) 
		// 	VALUES :params'
		// );
		$t_date_added = db_now();
		$t_insert = new DbQuery( 'INSERT INTO {license_user_list} 
			( license_id, user_id, date_added ) 
			VALUES :params'
		);
		foreach( $t_changes as $t_id => $t_value ) {
			// $t_insert->bind( 'params', array( $t_license_id, $t_id, $t_value ) );
			$t_insert->bind( 'params', array( $t_license_id, $t_id, $t_date_added ) );
			$t_insert->execute();

			# Trigger event for user added on license
			event_signal('EVENT_MANAGE_LICENSE_USER_CREATE', array('user_id' => $t_id, 'license_id' => $p_license_id));
		}
	}
}

function license_add_dwgs( $p_license_id, array $p_changes ) {
	# normalize input
	$t_changes = array();
	foreach( $p_changes as $t_id => $t_value ) {
		if( DEFAULT_ACCESS_LEVEL == $t_value ) {
			$t_changes[(int)$t_id] = user_get_access_level( $t_id );
		} else {
			$t_changes[(int)$t_id] = (int)$t_value;
		}
	}

	$t_dwg_ids = array_keys( $t_changes );
	if( empty( $t_dwg_ids ) ) {
		return;
	}

	$t_license_id = (int)$p_license_id;
	// $t_query = new DbQuery();
	// $t_sql = 'SELECT dwg_id FROM {license_dwg_list} 
	// 	WHERE license_id = ' . $t_query->param( $t_license_id ) . ' 
	// 	AND ' . $t_query->sql_in( 'dwg_id', $t_dwg_ids );
	// $t_query->sql( $t_sql );
	// $t_updating = array_column( $t_query->fetch_all(), 'dwg_id' );

	// if( !empty( $t_updating ) ) {
	// 	$t_update = new DbQuery( 'UPDATE {license_dwg_list} 
	// 		SET access_level = :new_value 
	// 		WHERE dwg_id = :dwg_id AND license_id = :license_id'
	// 	);
	// 	foreach( $t_updating as $t_id ) {
	// 		$t_params = array(
	// 			'license_id' => $t_license_id,
	// 			'dwg_id' => (int)$t_id,
	// 			'new_value' => $t_changes[$t_id]
	// 		);
	// 		$t_update->execute( $t_params );
	// 		unset( $t_changes[$t_id] );

	// 		# Trigger event for user access modification on license
	// 		event_signal('EVENT_MANAGE_LICENSE_DWG_UPDATE', array('user_id' => $t_id, 'license_id' => $p_license_id));
	// 	}
	// }
	# remaining items are for insert
	if( !empty( $t_changes ) ) {
		$t_insert = new DbQuery( 'INSERT INTO {license_dwg_list} 
			( license_id, dwg_id ) 
			VALUES :params'
		);
		foreach( $t_changes as $t_id => $t_value ) {
			// $t_insert->bind( 'params', array( $t_license_id, $t_id, $t_value ) );
			$t_insert->bind( 'params', array( $t_license_id, $t_id ) );
			$t_insert->execute();

			# Trigger event for user added on license
			event_signal('EVENT_MANAGE_LICENSE_DWG_CREATE', array('dwg_id' => $t_id, 'license_id' => $p_license_id));
		}
	}
}

/**
 * Remove user from license.
 * @param integer $p_license_id A license identifier.
 * @param integer $p_user_id    A user identifier.
 * @return void
 */
function license_remove_user( $p_license_id, $p_user_id ) {
	license_remove_users( $p_license_id, array( $p_user_id ) );
}

function license_remove_dwg( $p_license_id, $p_dwg_id ) {
	error_log("****************************************");
	error_log("license_id = " . print_r($p_license_id, true));
	error_log("p_dwg_id = " . print_r($p_dwg_id, true));
	license_remove_dwgs( $p_license_id, array( $p_dwg_id ) );
}

/**
 * Remove multiple users from license.
 *
 * The user's default_license preference will be set to ALL_LICENSES if they
 * no longer have access to the license.

 * @param integer $p_license_id  A license identifier.
 * @param array $p_user_ids      Array of user identifiers.
 * @return void
 */
function license_remove_users( $p_license_id, array $p_user_ids ) {
	# normalize input
	$t_user_ids = array();
	foreach( $p_user_ids as $t_id ) {
		$t_user_ids[] = (int)$t_id;
	}
	if( empty( $t_user_ids ) ) {
		return;
	}

	# Trigger event for each user deleted from license
	foreach( $p_user_ids as $t_id ) {
		event_signal('EVENT_MANAGE_LICENSE_USER_DELETE', array('user_id' => $t_id, 'license_id' => $p_license_id));
	}

	# Remove users from the license
	$t_query = new DbQuery();
	$t_sql = 'DELETE FROM {license_user_list}'
		. ' WHERE license_id = ' . $t_query->param( (int)$p_license_id )
		. ' AND ' . $t_query->sql_in( 'user_id', $t_user_ids );
	$t_query->sql( $t_sql );
	$t_query->execute();

	// user_pref_clear_invalid_license_default( $p_license_id );
}

function license_remove_dwgs( $p_license_id, array $p_dwg_ids ) {
	# normalize input
	$t_dwg_ids = array();
	foreach( $p_dwg_ids as $t_id ) {
		$t_dwg_ids[] = (int)$t_id;
	}
	if( empty( $t_dwg_ids ) ) {
		return;
	}

	# Trigger event for each dwg deleted from license
	foreach( $p_dwg_ids as $t_id ) {
		event_signal('EVENT_MANAGE_LICENSE_DWG_DELETE', array('dwg_id' => $t_id, 'license_id' => $p_license_id));
	}

	error_log("****************************************");
	error_log("license_id = " . print_r($p_license_id, true));
	error_log("p_dwg_ids = " . print_r($p_dwg_ids, true));
	error_log("t_dwg_ids = " . print_r($t_dwg_ids, true));

	# Remove dwgs from the license
	$t_query = new DbQuery();
	$t_sql = 'DELETE FROM {license_dwg_list}'
		. ' WHERE license_id = ' . $t_query->param( (int)$p_license_id )
		. ' AND ' . $t_query->sql_in( 'dwg_id', $t_dwg_ids );

	error_log("SEQUEL: " . clean_sql($t_sql));
	error_log("****************************************");

	$t_query->sql( $t_sql );
	$t_query->execute();

	// user_pref_clear_invalid_license_default( $p_license_id );
}

/**
 * Delete all users from the license user list for a given license.
 *
 * This is useful when deleting or closing a license. The $p_access_level_limit
 * parameter can be used to only remove users from a license if their access
 * level is below or equal to the limit.
 *
 * The user's default_license preference will be set to ALL_LICENSES if they
 * no longer have access to the license.
 *
 * @param integer $p_license_id         A license identifier.
 * @param integer $p_access_level_limit Access level limit (null = no limit).
 * @return void
 */
function license_remove_all_users( $p_license_id, $p_access_level_limit = null ) {
	$t_query = new DbQuery();
	$t_sql = 'DELETE FROM {license_user_list} '
		. 'WHERE license_id = ' . $t_query->param( (int)$p_license_id );
	// if( $p_access_level_limit !== null ) {
	// 	$t_sql .= ' AND access_level <= ' . $t_query->param( (int)$p_access_level_limit );
	// }
	$t_query->sql( $t_sql );
	$t_query->execute();
}

function license_remove_all_dwgs( $p_license_id, $p_access_level_limit = null ) {
	$t_query = new DbQuery();
	$t_sql = 'DELETE FROM {license_dwg_list} '
		. 'WHERE license_id = ' . $t_query->param( (int)$p_license_id );
	// if( $p_access_level_limit !== null ) {
	// 	$t_sql .= ' AND access_level <= ' . $t_query->param( (int)$p_access_level_limit );
	// }
	$t_query->sql( $t_sql );
	$t_query->execute();
}

/**
 * Copy all users and their permissions from the source license to the
 * destination license. The $p_access_level_limit parameter can be used to
 * limit the access level for users as they're copied to the destination
 * license (the highest access level they'll receive in the destination
 * license will be equal to $p_access_level_limit).
 * @param integer $p_destination_id     The destination license identifier.
 * @param integer $p_source_id          The source license identifier.
 * @param integer $p_access_level_limit Access level limit (null = no limit).
 * @return void
 */
function license_copy_users( $p_destination_id, $p_source_id, $p_access_level_limit = null ) {
	# Copy all users from current license over to another license
	$t_rows = license_get_local_user_rows( $p_source_id );

	$t_count = count( $t_rows );
	for( $i = 0; $i < $t_count; $i++ ) {
		$t_row = $t_rows[$i];

		if( $p_access_level_limit !== null &&
			$t_row['access_level'] > $p_access_level_limit ) {
			$t_destination_access_level = $p_access_level_limit;
		} else {
			$t_destination_access_level = $t_row['access_level'];
		}

		# if there is no duplicate then add a new entry
		# otherwise just update the access level for the existing entry
		if( license_includes_user( $p_destination_id, $t_row['user_id'] ) ) {
			license_update_user_access( $p_destination_id, $t_row['user_id'], $t_destination_access_level );
		} else {
			license_add_user( $p_destination_id, $t_row['user_id'], $t_destination_access_level );
		}
	}
}

/**
 * Delete all files associated with a license
 * @param integer $p_license_id A license identifier.
 * @return void
 */
// function license_delete_all_files( $p_license_id ) {
// 	file_delete_license_files( $p_license_id );
// }

/**
 * Returns the license name as a link formatted for display in menus and buttons.
 *
 * The link is formatted as a link to set_license.php, which can be used to
 * display license selection menus:
 * - licenses list in navbar {@see layout_navbar_licenses_menu()}
 * - license menu bar {@see print_license_menu_bar()}
 *
 * @param integer $p_license_id License Id to display
 * @param bool    $p_active     True if it's the currently active license
 * @param string  $p_class      CSS classes to apply
 * @param array   $p_parents    Array of parent licenses (empty if top-level)
 * @param string  $p_indent     String to use to indent the sublicenses
 *
 * @return string Fully formatted HTML link to the license
 */
function license_link_for_menu( $p_license_id, $p_active = false, $p_class = '', array $p_parents = array(), $p_indent = '' ) {
	if( $p_parents ) {
		$t_full_id = implode( ";", $p_parents ) . ';' . $p_license_id;
		$t_indent = str_repeat( $p_indent, count( $p_parents ) ) . '&nbsp;';
	} else {
		$t_full_id = $p_license_id;
		$t_indent = '';
	}

	$t_url = helper_mantis_url( 'set_license.php?license_id=' . $t_full_id );
	$t_label = $t_indent . string_html_specialchars( license_get_name( $p_license_id ) );

	if( $p_active ) {
		$p_class .= ' active';
	}

	return sprintf('<a class="%s" href="%s">%s</a>', $p_class, $t_url, $t_label );
}

/**
 * Returns the number of dwgs associated with the given License.
 *
 * @param int $p_license_id A license identifier.
 *
 * @return int
 */
function license_get_dwg_count( $p_license_id ) {
	$t_query = new DbQuery();
	$t_query->sql( 'SELECT COUNT(*) FROM {license_dwg_list} WHERE license_id='
		. $t_query->param( $p_license_id )
	);
	$t_query->execute();
	return $t_query->value();
}

/**
 * Returns the number of user associated with the given License.
 *
 * @param int $p_license_id A license identifier.
 *
 * @return int
 */
function license_get_user_count( $p_license_id ) {
	$t_query = new DbQuery();
	$t_query->sql( 'SELECT COUNT(*) FROM {license_user_list} WHERE license_id='
		. $t_query->param( $p_license_id )
	);
	$t_query->execute();
	return $t_query->value();
}

function license_user_has_access( $p_license_id ) {
	$t_user_id = auth_get_current_user_id();
	$t_query = new DbQuery();
	$t_query->sql( 'SELECT COUNT(*) FROM {license_user_list} WHERE license_id='
		. $t_query->param( $p_license_id )
		. ' AND user_id='
		. $t_query->param( $t_user_id )
	);
	$t_query->execute();
	return $t_query->value();
}

function license_user_has_applied( $p_license_id, $p_status = 0 ) {
	$t_user_id = auth_get_current_user_id();
	$t_query = new DbQuery();
	$t_query->sql( 'SELECT COUNT(*) FROM {license_user_list} WHERE license_id='
		. $t_query->param( $p_license_id )
		. ' AND user_id='
		. $t_query->param( $t_user_id )
	);
	if( $p_status ) {
		$t_query->append_sql( 
			' AND status='
			. $t_query->param( (int)$p_status ));
	}
	$t_query->execute();
	return $t_query->value();
}

/**
 * Gets the tags that are not associated with the specified bug.
 *
 * @param int $p_dwg_id The bug id, if 0 returns all available tags.
 *
 * @return array List of tag rows, each with id, name, and description.
 */
function license_get_candidates_for_dwg( $p_dwg_id ) {
	db_param_push();
	$t_query = 'SELECT id, name, description FROM {license}';
	$t_params = array();

	if( 0 != $p_dwg_id ) {
		$t_assoc_query = 'SELECT dwg_id FROM {license_dwg_list} WHERE dwg_id = ' . db_param();
		$t_params[] = $p_dwg_id;

		# Define specific where clause to exclude tags already attached to the bug
		# Special handling for odbc_mssql which does not support bound subqueries (#14774)
		if( config_get_global( 'db_type' ) == 'odbc_mssql' ) {
			db_param_push();
			$t_result = db_query( $t_assoc_query, $t_params );

			$t_subquery_results = array();
			while( $t_row = db_fetch_array( $t_result ) ) {
				$t_subquery_results[] = (int)$t_row['dwg_id'];
			}
			if( $t_subquery_results ) {
				$t_where = ' WHERE id NOT IN (' . implode( ', ', $t_subquery_results ) . ')';
			} else {
				$t_where = '';
			}
			$t_params = null;
		} else {
			$t_where = " WHERE id NOT IN ($t_assoc_query)";
		}
		$t_query .= $t_where;
	}

	$t_query .= ' ORDER BY name ASC ';
	$t_result = db_query( $t_query, $t_params );

	$t_results_to_return = array();

	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_results_to_return[] = $t_row;
	}

	return $t_results_to_return;
}

