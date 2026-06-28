<?php
# Doctis — REST endpoints for the document (dwg) domain
#
# Routes registered here:
#   GET    /api/rest/v1/documents[/{id}]  — list or fetch
#   POST   /api/rest/v1/documents          — create
#   PATCH  /api/rest/v1/documents/{id}     — update (partial)
#   DELETE /api/rest/v1/documents/{id}     — delete

require_api( 'filter_dwg_api.php' );

use Mantis\Exceptions\ClientException;

/**
 * @var \Slim\App $g_app
 */
$g_app->group( '/documents', function() use ( $g_app ) {
	$g_app->get( '',        'rest_document_get' );
	$g_app->get( '/',       'rest_document_get' );
	$g_app->get( '/{id}',   'rest_document_get' );
	$g_app->get( '/{id}/',  'rest_document_get' );
	$g_app->post( '',        'rest_document_add' );
	$g_app->post( '/',       'rest_document_add' );
	$g_app->patch( '/{id}',  'rest_document_update' );
	$g_app->patch( '/{id}/', 'rest_document_update' );
	$g_app->delete( '/{id}',  'rest_document_delete' );
	$g_app->delete( '/{id}/', 'rest_document_delete' );
} );

/**
 * GET /documents/{id}  — fetch a single document by id.
 * GET /documents        — list documents (project_id, page, page_size query params).
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 */
function rest_document_get( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_id = isset( $p_args['id'] ) ? $p_args['id'] : $p_request->getParam( 'id' );

	if( !is_blank( $t_id ) ) {
		$t_document = mc_dwg_get( /* username */ '', /* password */ '', $t_id );
		ApiObjectFactory::throwIfFault( $t_document );

		return $p_response->withStatus( HTTP_STATUS_SUCCESS )
			->withJson( array( 'documents' => array( $t_document ) ) );
	}

	# List mode
	$t_project_id  = (int)$p_request->getParam( 'project_id', ALL_PROJECTS );
	$t_page_number = (int)$p_request->getParam( 'page', 1 );
	$t_page_size   = (int)$p_request->getParam( 'page_size', 50 );

	if( $t_project_id != ALL_PROJECTS ) {
		$t_message = "Project '$t_project_id' not found";
		if( !project_exists( $t_project_id ) ) {
			return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, $t_message );
		}
		$t_user_id = auth_get_current_user_id();
		if( !access_has_project_level( VIEWER, $t_project_id, $t_user_id ) ) {
			return $p_response->withStatus( HTTP_STATUS_NOT_FOUND, $t_message );
		}
	}

	helper_set_current_project( $t_project_id );

	$t_page_count = 0;
	$t_dwg_count  = 0;
	$t_rows = filter_dwg_get_dwg_rows(
		$t_page_number, $t_page_size, $t_page_count, $t_dwg_count,
		/* custom_filter */ null, $t_project_id
	);

	$t_user_id   = auth_get_current_user_id();
	$t_lang      = mci_get_user_lang( $t_user_id );
	$t_documents = array();
	foreach( $t_rows as $t_bug ) {
		$t_documents[] = mci_dwg_data_as_array( $t_bug, $t_user_id, $t_lang );
	}

	return $p_response->withStatus( HTTP_STATUS_SUCCESS )->withJson( array(
		'documents'   => $t_documents,
		'total_count' => $t_dwg_count,
		'page'        => $t_page_number,
		'page_size'   => $t_page_size,
	) );
}

/**
 * POST /documents — create a new document.
 *
 * Request body mirrors the issue payload accepted by POST /issues:
 * project, title, author, publisher, number, edition, revision, reference,
 * classification, description, summary, category, handler, priority, view_state, etc.
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 *
 * @noinspection PhpUnusedParameterInspection
 */
function rest_document_add( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_payload = $p_request->getParsedBody();
	if( !$t_payload ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, 'Invalid request body or format' );
	}

	$t_data    = array( 'payload' => array( 'issue' => $t_payload ) );
	$t_command = new DwgAddCommand( $t_data );
	$t_result  = $t_command->execute();
	$t_id      = (int)$t_result['issue_id'];

	$t_document = mc_dwg_get( /* username */ '', /* password */ '', $t_id );

	return $p_response->withStatus( HTTP_STATUS_CREATED, "Document Created with id $t_id" )
		->withJson( array( 'document' => $t_document ) );
}

/**
 * PATCH /documents/{id} — partially update a document.
 *
 * Only fields present in the request body are updated; all other fields
 * retain their current values.
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 *
 * @throws \Mantis\Exceptions\LegacyApiFaultException
 */
function rest_document_update( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_id = isset( $p_args['id'] ) ? $p_args['id'] : $p_request->getParam( 'id' );
	if( is_blank( $t_id ) ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, "Mandatory field 'id' is missing." );
	}

	$t_document = mc_dwg_get( /* username */ '', /* password */ '', $t_id );
	ApiObjectFactory::throwIfFault( $t_document );

	$t_patch = $p_request->getParsedBody();
	if( !$t_patch ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, 'Invalid request body or format' );
	}
	if( isset( $t_patch['id'] ) && (int)$t_patch['id'] != (int)$t_id ) {
		return $p_response->withStatus( HTTP_STATUS_BAD_REQUEST, 'Document id mismatch' );
	}

	$t_merged = (object)array_merge( (array)$t_document, $t_patch );
	# dwg.summary is never stored by DwgData::create(); mc_dwg_update() still requires
	# it non-blank, so fall back to the document title when the field is empty.
	if( is_blank( $t_merged->summary ?? '' ) && !is_blank( $t_merged->title ?? '' ) ) {
		$t_merged->summary = $t_merged->title;
	}
	# mc_dwg_update() also requires non-blank description; ensure a default.
	if( !isset( $t_merged->description ) ) {
		$t_merged->description = '';
	}
	$t_result = mc_dwg_update( /* username */ '', /* password */ '', $t_id, $t_merged );
	ApiObjectFactory::throwIfFault( $t_result );

	$t_updated = mc_dwg_get( /* username */ '', /* password */ '', $t_id );

	return $p_response->withStatus( HTTP_STATUS_SUCCESS, "Document $t_id Updated" )
		->withJson( array( 'documents' => array( $t_updated ) ) );
}

/**
 * DELETE /documents/{id} — delete a document.
 *
 * @param \Slim\Http\Request  $p_request
 * @param \Slim\Http\Response $p_response
 * @param array               $p_args
 * @return \Slim\Http\Response
 *
 * @noinspection PhpUnusedParameterInspection
 */
function rest_document_delete( \Slim\Http\Request $p_request, \Slim\Http\Response $p_response, array $p_args ) {
	$t_id = isset( $p_args['id'] ) ? $p_args['id'] : $p_request->getParam( 'id' );

	$t_data    = array( 'query' => array( 'id' => $t_id ) );
	$t_command = new DwgDeleteCommand( $t_data );
	$t_command->execute();

	return $p_response->withStatus( HTTP_STATUS_NO_CONTENT );
}
