<?php
# Doctis — Change a Documentation Topic tag's extent and sections.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'topic_api.php' );

form_security_validate( 'dwg_topic_update' );

$t_tag = topic_dwg_get( gpc_get_int( 'tag_id' ) );
$t_dwg_id = (int)$t_tag['dwg_id'];
if( !topic_can_tag_dwg_in_project( $t_dwg_id, (int)$t_tag['project_id'] ) ) {
	access_denied();
}

topic_dwg_update( (int)$t_tag['id'], gpc_get_int( 'extent', TOPIC_EXTENT_PART ), gpc_get_string( 'locator', '' ) );

form_security_purge( 'dwg_topic_update' );
dwg_topic_redirect( $t_dwg_id, (int)$t_tag['project_id'] );
