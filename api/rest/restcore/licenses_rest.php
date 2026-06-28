<?php
# Doctis — REST endpoints for the license domain
#
# A Doctis license is a skill, security clearance, or professional qualification
# registered against a user account. It is unrelated to software licensing.
#
# Routes registered here:
#   GET    /api/rest/v1/licenses[/{id}]  — list (brief) or fetch (detailed)
#   POST   /api/rest/v1/licenses          — create
#   PATCH  /api/rest/v1/licenses/{id}     — update (partial)
#   DELETE /api/rest/v1/licenses/{id}     — delete

use Mantis\Exceptions\ClientException;

/**
 * @var \Slim\App $g_app
 */
$g_app->group( '/licenses', function() use ( $g_app ) {
	$g_app->get( '',         'rest_license_get' );
	$g_app->get( '/',        'rest_license_get' );
	$g_app->get( '/{id}',    'rest_license_get' );
	$g_app->get( '/{id}/',   'rest_license_get' );
	$g_app->post( '',         'rest_license_add' );
	$g_app->post( '/',        'rest_license_add' );
	$g_app->patch( '/{id}',   'rest_license_update' );
	$g_app->patch( '/{id}/',  'rest_license_update' );
	$g_app->delete( '/{id}',  'rest_license_delete' );
	$g_app->delete( '/{id}/', 'rest_license_delete' );
} );

/**
 * GET /licenses/{id}  — fetch a single license with full detail.
 * GET /licenses        — list all licenses (brief: id + name only).
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 *
 * @noinspection PhpUnusedParameterInspection
 */
function rest_license_get( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_user_id = auth_get_current_user_id();
	$t_lang    = mci_get_user_lang( $t_user_id );

	$t_id = isset( $p_args['id'] ) ? $p_args['id'] : $p_request->getParam( 'id' );

	if( !is_blank( $t_id ) ) {
		$t_id = (int)$t_id;
		if( !license_exists( $t_id ) ) {
			return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, "License '$t_id' not found" );
		}

		$t_license = mci_license_get( $t_id, $t_lang, /* detail */ true );
		return $p_response->withStatus( HTTP_STATUS_SUCCESS )
			->withJson( array( 'licenses' => array( $t_license ) ) );
	}

	# List all licenses (brief — id and name only)
	$t_rows     = license_get_all_rows();
	$t_licenses = array();
	foreach( $t_rows as $t_row ) {
		$t_licenses[] = mci_license_get( (int)$t_row['id'], $t_lang, /* detail */ false );
	}

	return $p_response->withStatus( HTTP_STATUS_SUCCESS )
		->withJson( array( 'licenses' => $t_licenses ) );
}

/**
 * POST /licenses — create a new license.
 *
 * Request body fields:
 *   name        (string, required)
 *   description (string, optional)
 *   status      (object {id, name} or int, optional — defaults to 10/development)
 *   view_state  (object {id, name} or int, optional — defaults to public)
 *   enabled     (bool, optional — defaults to true)
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 *
 * @noinspection PhpUnusedParameterInspection
 */
function rest_license_add( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_payload = $p_request->getParsedBody();
	if( !$t_payload ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, 'Invalid request body or format' );
	}

	$t_data    = array( 'payload' => $t_payload );
	$t_command = new LicenseCreateCommand( $t_data );
	$t_result  = $t_command->execute();
	$t_id      = (int)$t_result['id'];

	$t_user_id = auth_get_current_user_id();
	$t_lang    = mci_get_user_lang( $t_user_id );
	$t_license = mci_license_get( $t_id, $t_lang, /* detail */ true );

	return $p_response->withStatus( HTTP_STATUS_CREATED, "License Created with id $t_id" )
		->withJson( array( 'license' => $t_license ) );
}

/**
 * PATCH /licenses/{id} — partially update a license.
 *
 * Accepts a subset of the same fields as POST /licenses.
 * Fields omitted from the body retain their current values.
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 */
function rest_license_update( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_id = isset( $p_args['id'] ) ? (int)$p_args['id'] : (int)$p_request->getParam( 'id' );
	if( $t_id < 1 ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, "Mandatory field 'id' is missing or invalid." );
	}

	$t_payload = $p_request->getParsedBody();
	if( !$t_payload ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, 'Invalid request body or format' );
	}

	$t_data = array(
		'query'   => array( 'id' => $t_id ),
		'payload' => $t_payload,
	);
	$t_command = new LicenseUpdateCommand( $t_data );
	$t_command->execute();

	$t_user_id = auth_get_current_user_id();
	$t_lang    = mci_get_user_lang( $t_user_id );
	$t_license = mci_license_get( $t_id, $t_lang, /* detail */ true );

	return $p_response->withStatus( HTTP_STATUS_SUCCESS, "License $t_id Updated" )
		->withJson( array( 'license' => $t_license ) );
}

/**
 * DELETE /licenses/{id} — delete a license.
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 *
 * @noinspection PhpUnusedParameterInspection
 */
function rest_license_delete( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_id = isset( $p_args['id'] ) ? (int)$p_args['id'] : (int)$p_request->getParam( 'id' );
	if( $t_id < 1 ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, "Mandatory field 'id' is missing or invalid." );
	}

	$t_data    = array( 'query' => array( 'id' => $t_id ) );
	$t_command = new LicenseDeleteCommand( $t_data );
	$t_command->execute();

	return $p_response->withStatus( HTTP_STATUS_NO_CONTENT );
}
