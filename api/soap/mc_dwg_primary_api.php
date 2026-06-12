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
 * SOAP primary document file endpoints — Doctis extension.
 *
 * The {dwg_primary_file} table holds exactly one canonical file per document
 * record (or no row if no file has been uploaded yet).  Files are always
 * stored via the GIT backend.
 *
 * PrimaryFileData structure returned by mc_dwg_primary_get():
 *   filename     — original filename
 *   filesize     — size in bytes
 *   file_type    — MIME type
 *   date_added   — ISO-8601 timestamp
 *   description  — revision note (may be blank)
 *   download_url — URL to retrieve the file (authenticated)
 *
 * @package MantisBT
 */

require_once( __DIR__ . '/mc_core.php' );

use Mantis\Exceptions\ClientException;

/**
 * Return metadata about the primary document file for a given document.
 *
 * Returns an empty array (no row) if no primary file has been uploaded yet.
 *
 * @param string  $p_username Authenticating username.
 * @param string  $p_password Authenticating password.
 * @param integer $p_dwg_id   Document id.
 * @return array|SoapFault    PrimaryFileData array, empty array if absent, or a fault.
 */
function mc_dwg_primary_get( $p_username, $p_password, $p_dwg_id ) {
	$t_user_id = mci_check_login( $p_username, $p_password );
	if( $t_user_id === false ) {
		return mci_fault_login_failed();
	}

	if( !dwg_exists( $p_dwg_id ) ) {
		return ApiObjectFactory::faultNotFound( 'Document \'' . $p_dwg_id . '\' does not exist.' );
	}

	$t_project_id = dwg_get_field( $p_dwg_id, 'project_id' );
	if( !mci_has_readonly_access( $t_user_id, $t_project_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	if( !access_has_dwg_level( config_get( 'view_dwg_threshold' ), $p_dwg_id, $t_user_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	$t_row = file_dwg_primary_get( $p_dwg_id );
	if( $t_row === null ) {
		return array();
	}

	$t_download_url = config_get_global( 'path' )
		. 'file_download.php?type=dwg_primary&id=' . (int)$p_dwg_id;

	log_event( LOG_WEBSERVICE, 'getting primary file metadata for document \'' . $p_dwg_id . '\'' );

	return array(
		'filename'     => $t_row['filename'],
		'filesize'     => (int)$t_row['filesize'],
		'file_type'    => $t_row['file_type'],
		'date_added'   => date( 'c', $t_row['date_added'] ),
		'description'  => (string)$t_row['description'],
		'download_url' => $t_download_url,
	);
}

/**
 * Upload or replace the primary document file for a document.
 *
 * If a primary file already exists it is replaced (old file is soft-deleted
 * from the GIT repository and the {dwg_primary_file} row is overwritten).
 *
 * Content must be base64-encoded.
 *
 * @param string  $p_username    Authenticating username.
 * @param string  $p_password    Authenticating password.
 * @param integer $p_dwg_id      Document id.
 * @param string  $p_name        Original filename (e.g. "spec-rev-b.pdf").
 * @param string  $p_file_type   MIME type supplied by the caller.
 * @param string  $p_content     Base64-encoded file bytes.
 * @param string  $p_description Revision note (optional).
 * @return boolean|SoapFault     true on success, or a fault.
 */
function mc_dwg_primary_upload( $p_username, $p_password, $p_dwg_id, $p_name, $p_file_type, $p_content, $p_description = '' ) {
	$t_user_id = mci_check_login( $p_username, $p_password );
	if( $t_user_id === false ) {
		return mci_fault_login_failed();
	}

	if( !dwg_exists( $p_dwg_id ) ) {
		return ApiObjectFactory::faultNotFound( 'Document \'' . $p_dwg_id . '\' does not exist.' );
	}

	$t_project_id = dwg_get_field( $p_dwg_id, 'project_id' );
	if( !mci_has_readwrite_access( $t_user_id, $t_project_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	if( !access_has_dwg_level( config_get( 'update_dwg_threshold' ), $p_dwg_id, $t_user_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	$t_decoded = base64_decode( $p_content, true );
	if( $t_decoded === false ) {
		return ApiObjectFactory::faultBadRequest( 'Content is not valid base64.' );
	}

	$t_file_size = strlen( $t_decoded );
	if( $t_file_size === 0 ) {
		return ApiObjectFactory::faultBadRequest( 'File content is empty.' );
	}

	$t_max_file_size = file_dwg_get_max_file_size();
	if( $t_file_size > $t_max_file_size ) {
		return ApiObjectFactory::faultBadRequest(
			'File is too big. Maximum size is ' . $t_max_file_size . ' bytes.'
		);
	}

	$t_tmp = tempnam( sys_get_temp_dir(), 'mci_dwgp_' );
	file_put_contents( $t_tmp, $t_decoded );

	try {
		file_dwg_primary_add(
			$p_dwg_id,
			$t_user_id,
			$t_tmp,
			$p_name,
			$t_file_size,
			$p_file_type,
			$p_description
		);
		log_event( LOG_WEBSERVICE, 'uploaded primary file \'' . $p_name . '\' for document \'' . $p_dwg_id . '\'' );
		return true;
	} catch( ClientException $e ) {
		return ApiObjectFactory::faultBadRequest( $e->getMessage() );
	} finally {
		if( file_exists( $t_tmp ) ) {
			unlink( $t_tmp );
		}
	}
}

/**
 * Delete the primary document file for a document.
 *
 * Performs a soft-delete in the GIT repository (the file disappears from
 * HEAD but the full commit history is retained).  The {dwg_primary_file}
 * row is also removed.  No-op if no primary file exists.
 *
 * @param string  $p_username Authenticating username.
 * @param string  $p_password Authenticating password.
 * @param integer $p_dwg_id   Document id.
 * @return boolean|SoapFault  true on success, or a fault.
 */
function mc_dwg_primary_delete( $p_username, $p_password, $p_dwg_id ) {
	$t_user_id = mci_check_login( $p_username, $p_password );
	if( $t_user_id === false ) {
		return mci_fault_login_failed();
	}

	if( !dwg_exists( $p_dwg_id ) ) {
		return ApiObjectFactory::faultNotFound( 'Document \'' . $p_dwg_id . '\' does not exist.' );
	}

	$t_project_id = dwg_get_field( $p_dwg_id, 'project_id' );
	if( !mci_has_readwrite_access( $t_user_id, $t_project_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	if( !access_has_dwg_level( config_get( 'update_dwg_threshold' ), $p_dwg_id, $t_user_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	log_event( LOG_WEBSERVICE, 'deleting primary file for document \'' . $p_dwg_id . '\'' );
	file_dwg_primary_delete( $p_dwg_id );

	return true;
}
