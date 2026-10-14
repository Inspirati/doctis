<?php
# Doctis — Tag a document with a Documentation Topic.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'topic_api.php' );

form_security_validate( 'dwg_topic_add' );

$f_dwg_id = gpc_get_int( 'dwg_id' );
# Project whose profile the tag belongs to: another one shares the document.
$f_project_id = gpc_get_int( 'project_id', 0 );
dwg_ensure_exists( $f_dwg_id );
$t_project_id = $f_project_id > 0 ? $f_project_id : (int)dwg_get_field( $f_dwg_id, 'project_id' );
if( !topic_can_tag_dwg_in_project( $f_dwg_id, $t_project_id ) ) {
	access_denied();
}

topic_dwg_add( $f_dwg_id, gpc_get_int( 'topic_id' ), gpc_get_int( 'extent', TOPIC_EXTENT_PART ),
	gpc_get_string( 'locator', '' ), $t_project_id );

form_security_purge( 'dwg_topic_add' );
dwg_topic_redirect( $f_dwg_id, $t_project_id );
