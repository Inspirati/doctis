<?php
require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'git_restore_api.php' );

auth_reauthenticate();
access_ensure_global_level( ADMINISTRATOR );
form_security_validate( 'manage_git_restore' );

header( 'Content-Type: application/json; charset=utf-8' );
header( 'Cache-Control: no-store' );

$t_lock = null;
try {
	if( $_SERVER['REQUEST_METHOD'] !== 'POST' ) { throw new RuntimeException( 'POST is required.' ); }
	$f_action = gpc_get_string( 'action' );
	$t_user = auth_get_current_user_id();
	if( $f_action === 'start' ) {
		if( is_file( git_restore_gate_path() . '.restore' ) ) { throw new RuntimeException( 'Recover the interrupted restore first.' ); }
		$t_state = git_restore_start( gpc_get_string( 'name' ), gpc_get_int( 'size' ),
			strtolower( trim( gpc_get_string( 'sha256', '' ) ) ), $t_user );
		$t_dir = git_restore_ticket_path( $t_state['ticket'] );
		$t_state['modified'] = gpc_get_int( 'modified', 0 );
		git_restore_save( $t_dir, $t_state );
	} else {
		$t_dir = git_restore_ticket_path( gpc_get_string( 'ticket' ) );
		$t_lock = @fopen( $t_dir . '/lock', 'c' );
		if( $t_lock === false || !flock( $t_lock, LOCK_EX | LOCK_NB ) ) { throw new RuntimeException( 'This upload is busy. Retry shortly.' ); }
		$t_state = git_restore_state( $t_dir );
		# Any administrator can recover the one interrupted installation.
		$t_marker = trim( (string)@file_get_contents( git_restore_gate_path() . '.restore' ) );
		if( $t_state['owner'] !== $t_user && !( in_array( $f_action, array( 'status', 'recover' ), true ) && $t_marker === $t_state['ticket'] ) ) {
			throw new RuntimeException( 'This upload belongs to another administrator.' );
		}
		switch( $f_action ) {
			case 'status':
				break;
			case 'upload':
				$t_file = gpc_get_file( 'chunk', null );
				if( !is_array( $t_file ) || $t_file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file( $t_file['tmp_name'] ) ) { throw new RuntimeException( 'No complete upload chunk was received.' ); }
				git_restore_append( $t_dir, $t_state, gpc_get_int( 'offset' ), $t_file['tmp_name'] );
				break;
			case 'inspect':
				set_time_limit( 0 );
				$t_state = git_restore_inspect( $t_dir, $t_state );
				break;
			case 'apply':
				if( gpc_get_string( 'confirm', '' ) !== 'RESTORE' ) { throw new RuntimeException( 'Type RESTORE to confirm replacing this server\'s Git/LFS store.' ); }
				ignore_user_abort( true );
				set_time_limit( 0 );
				$t_report = git_restore_apply( $t_dir, $t_state );
				$t_state = git_restore_state( $t_dir );
				error_log( 'ADMIN: ' . current_user_get_field( 'username' ) . ' restored Git/LFS store; archive SHA-256 '
					. $t_report['sha256'] . ', repositories ' . count( $t_report['repositories'] ) . ', LFS objects ' . $t_report['lfs_objects'] );
				break;
			case 'recover':
				if( gpc_get_string( 'confirm', '' ) !== 'RECOVER' ) { throw new RuntimeException( 'Type RECOVER to confirm recovery.' ); }
				git_restore_exclusive();
				ignore_user_abort( true );
				set_time_limit( 0 );
				if( $t_state['phase'] === 'complete' ) {
					git_restore_finish( $t_dir, $t_state );
				} else if( in_array( $t_state['phase'], array( 'applying', 'preparing' ), true ) ) {
					git_restore_rollback( $t_dir, $t_state );
				} else { throw new RuntimeException( 'There is no interrupted installation to recover.' ); }
				$t_state = git_restore_state( $t_dir );
				break;
			case 'cancel':
				if( in_array( $t_state['phase'], array( 'applying', 'preparing' ), true ) || $t_marker === $t_state['ticket'] ) { throw new RuntimeException( 'Recover the interrupted installation before discarding it.' ); }
				git_restore_remove( $t_dir );
				$t_state['phase'] = 'cancelled';
				break;
			default:
				throw new RuntimeException( 'Unknown restore operation.' );
		}
	}
	clearstatcache();
	$t_state['uploaded'] = is_file( $t_dir . '/archive.tar.gz' ) ? filesize( $t_dir . '/archive.tar.gz' ) : 0;
	$t_state['chunk_bytes'] = GIT_RESTORE_CHUNK_BYTES;
	unset( $t_state['installation'], $t_state['expected_sha256'], $t_state['owner'] );
	echo json_encode( array( 'ok' => true, 'state' => $t_state ), JSON_THROW_ON_ERROR );
} catch( Throwable $t_error ) {
	http_response_code( 400 );
	echo json_encode( array( 'ok' => false, 'error' => $t_error->getMessage() ), JSON_INVALID_UTF8_SUBSTITUTE );
} finally {
	if( is_resource( $t_lock ) ) { flock( $t_lock, LOCK_UN ); fclose( $t_lock ); }
}
