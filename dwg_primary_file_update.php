<?php
# dwg_primary_file_update.php
# POST handler — upload or replace the primary document file for a dwg record.
# Called from the primary document section on dwg_view_inc.php.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'dwg_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );

auth_ensure_user_authenticated();
form_security_validate( 'dwg_primary_file_update' );

$f_dwg_id     = gpc_get_int( 'dwg_id' );
$f_description = gpc_get_string( 'primary_document_description', '' );

$t_dwg        = dwg_get( $f_dwg_id );
$t_project_id = $t_dwg->project_id;

# Access check — same threshold as updating the dwg record
access_ensure_dwg_level( config_get( 'update_dwg_threshold' ), $f_dwg_id );

# Validate file upload
if( !isset( $_FILES['primary_document_file'] ) || $_FILES['primary_document_file']['error'] === UPLOAD_ERR_NO_FILE ) {
	error_parameters( lang_get( 'primary_document_file' ) );
	trigger_error( ERROR_EMPTY_FIELD, ERROR );
}

$t_file = $_FILES['primary_document_file'];

if( $t_file['error'] !== UPLOAD_ERR_OK ) {
	trigger_error( ERROR_FILE_UPLOAD_FAILURE, ERROR );
}

$t_max_file_size = file_dwg_get_max_file_size();
if( $t_file['size'] > $t_max_file_size ) {
	trigger_error( ERROR_FILE_TOO_BIG, ERROR );
}

file_dwg_primary_add(
	$f_dwg_id,
	auth_get_current_user_id(),
	$t_file['tmp_name'],
	$t_file['name'],
	$t_file['size'],
	$t_file['type'],
	$f_description
);

form_security_purge( 'dwg_primary_file_update' );

print_header_redirect( 'dwg_view.php?id=' . $f_dwg_id );
