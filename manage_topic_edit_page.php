<?php
# Doctis — Manage > Topics: edit one Topic of a Draft catalogue.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_api.php' );

auth_reauthenticate();
if( !topic_can_manage_catalogues() ) {
	access_denied();
}

$f_topic_id = gpc_get_int( 'id' );
$t_form_topic = topic_get( $f_topic_id );
$t_form_catalogue_id = (int)$t_form_topic['catalogue_id'];
$t_catalogue = topic_catalogue_ensure_draft( $t_form_catalogue_id );
$t_form_parents = topic_get_all( $t_form_catalogue_id );

layout_page_header( topic_label( $t_form_topic ) . ' — ' . lang_get( 'manage_topics_link' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_topic_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-pencil', 'ace-icon' ); ?>
			<?php echo string_display_line( topic_catalogue_label( $t_catalogue ) . ': ' . topic_label( $t_form_topic ) ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php include( __DIR__ . '/manage_topic_form_inc.php' ); ?>
		<div class="space-10"></div>
		<?php print_link_button( 'manage_topic_catalogue_page.php?id=' . $t_form_catalogue_id, lang_get( 'topic_back_to_catalogue' ) ); ?>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
