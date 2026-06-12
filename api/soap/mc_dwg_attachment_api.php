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
 * SOAP document note-attachment endpoints — Doctis extension.
 *
 * Mirrors mc_issue_attachment_api.php for the {dwg_file} table.
 * Uses the dwg file storage backend (DISK / DATABASE / GIT).
 *
 * @package MantisBT
 */

require_once( __DIR__ . '/mc_core.php' );

use Mantis\Exceptions\ClientException;

/**
 * Return the base64-encoded content of a document note attachment.
 *
 * @param string  $p_username      Authenticating username.
 * @param string  $p_password      Authenticating password.
 * @param integer $p_attachment_id {dwg_file}.id of the attachment.
 * @return string|SoapFault Base64-encoded file content, or a fault.
 */
function mc_dwg_attachment_get( $p_username, $p_password, $p_attachment_id ) {
	$t_user_id = mci_check_login( $p_username, $p_password );
	if( $t_user_id === false ) {
		return mci_fault_login_failed();
	}

	db_param_push();
	$t_result = db_query(
		'SELECT * FROM {dwg_file} WHERE id=' . db_param(),
		array( (int)$p_attachment_id )
	);
	if( $t_result->EOF ) {
		return ApiObjectFactory::faultNotFound( 'No document attachment with id ' . $p_attachment_id . '.' );
	}
	$t_row    = db_fetch_array( $t_result );
	$t_dwg_id = (int)$t_row['dwg_id'];

	if( !dwg_exists( $t_dwg_id ) ) {
		return ApiObjectFactory::faultNotFound( 'No document with id ' . $t_dwg_id . '.' );
	}

	if( !access_has_dwg_level( config_get( 'view_dwg_threshold' ), $t_dwg_id, $t_user_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	log_event( LOG_WEBSERVICE, 'getting content for dwg attachment id \'' . $p_attachment_id . '\'' );

	$t_file_content = file_dwg_get_content( $p_attachment_id, 'dwg' );
	if( $t_file_content === false ) {
		return ApiObjectFactory::faultNotFound( 'Unable to retrieve content for attachment ' . $p_attachment_id . '.' );
	}

	return base64_encode( $t_file_content['content'] );
}

/**
 * Add a note attachment to an existing document.
 *
 * Content is base64-encoded by the caller.  The attachment is stored via
 * the configured dwg file storage backend.
 *
 * @param string  $p_username  Authenticating username.
 * @param string  $p_password  Authenticating password.
 * @param integer $p_dwg_id    Document id to attach the file to.
 * @param string  $p_name      Original filename (e.g. "report.pdf").
 * @param string  $p_file_type MIME type supplied by the caller.
 * @param string  $p_content   Base64-encoded file bytes.
 * @return integer|SoapFault   {dwg_file}.id of the new attachment, or a fault.
 */
function mc_dwg_attachment_add( $p_username, $p_password, $p_dwg_id, $p_name, $p_file_type, $p_content ) {
	$t_user_id = mci_check_login( $p_username, $p_password );
	if( $t_user_id === false ) {
		return mci_fault_login_failed();
	}

	if( !dwg_exists( $p_dwg_id ) ) {
		return ApiObjectFactory::faultNotFound( 'Document \'' . $p_dwg_id . '\' does not exist.' );
	}

	if( !access_has_dwg_level( config_get( 'upload_dwg_file_threshold' ), $p_dwg_id, $t_user_id ) ) {
		return mci_fault_access_denied( $t_user_id );
	}

	$t_decoded = base64_decode( $p_content, true );
	if( $t_decoded === false ) {
		return ApiObjectFactory::faultBadRequest( 'Content is not valid base64.' );
	}

	$t_file_size = strlen( $t_decoded );
	$t_max_file_size = file_dwg_get_max_file_size();
	if( $t_file_size > $t_max_file_size ) {
		return ApiObjectFactory::faultBadRequest(
			'File is too big. Maximum size is ' . $t_max_file_size . ' bytes.'
		);
	}

	$t_tmp = tempnam( sys_get_temp_dir(), 'mci_dwg_' );
	file_put_contents( $t_tmp, $t_decoded );

	$t_file = array(
		'name'           => $p_name,
		'type'           => $p_file_type,
		'tmp_name'       => $t_tmp,
		'error'          => UPLOAD_ERR_OK,
		'size'           => $t_file_size,
		'browser_upload' => false,
	);

	try {
		$t_result = file_dwg_add( $p_dwg_id, $t_file, 'dwg', '', '', $t_user_id );
		log_event( LOG_WEBSERVICE, 'added attachment id \'' . $t_result['id'] . '\' to document \'' . $p_dwg_id . '\'' );
		return (int)$t_result['id'];
	} catch( ClientException $e ) {
		return ApiObjectFactory::faultBadRequest( $e->getMessage() );
	} finally {
		if( file_exists( $t_tmp ) ) {
			unlink( $t_tmp );
		}
	}
}

/**
 * Delete a document note attachment.
 *
 * The caller must own the attachment, or hold at least delete_attachments_threshold
 * on the document (subject to allow_delete_own_attachments config).
 *
 * @param string  $p_username      Authenticating username.
 * @param string  $p_password      Authenticating password.
 * @param integer $p_attachment_id {dwg_file}.id of the attachment to delete.
 * @return boolean|SoapFault       true on success, or a fault.
 */
function mc_dwg_attachment_delete( $p_username, $p_password, $p_attachment_id ) {
	$t_user_id = mci_check_login( $p_username, $p_password );
	if( $t_user_id === false ) {
		return mci_fault_login_failed();
	}

	$t_dwg_id         = (int)file_dwg_get_field( $p_attachment_id, 'dwg_id' );
	$t_attachment_owner = (int)file_dwg_get_field( $p_attachment_id, 'user_id' );
	$t_is_owner       = $t_attachment_owner === (int)$t_user_id;

	if( !$t_is_owner || ( $t_is_owner && !config_get( 'allow_delete_own_attachments' ) ) ) {
		if( !access_has_dwg_level( config_get( 'delete_attachments_threshold' ), $t_dwg_id, $t_user_id ) ) {
			return mci_fault_access_denied( $t_user_id );
		}
	}

	log_event( LOG_WEBSERVICE, 'deleting dwg attachment id \'' . $p_attachment_id . '\'' );
	return file_dwg_delete( $p_attachment_id, 'dwg' );
}
