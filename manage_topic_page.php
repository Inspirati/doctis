<?php
# Doctis — Manage > Topics: the Topic catalogues (doc/topics/TOPICS_PLAN.md).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'form_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_api.php' );

auth_reauthenticate();
if( !topic_can_manage_catalogues() ) {
	access_denied();
}

$t_catalogues = topic_catalogue_get_all();
$t_seeds = topic_catalogue_seeds();

layout_page_header( lang_get( 'manage_topics_link' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_topic_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-tags', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_catalogues' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p><?php echo lang_get( 'topic_catalogues_intro' ) ?></p>
	</div>
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-striped table-bordered table-condensed table-hover">
		<thead>
			<tr>
				<th><?php echo lang_get( 'topic_catalogue' ) ?></th>
				<th><?php echo lang_get( 'status' ) ?></th>
				<th class="center"><?php echo lang_get( 'topic_topics' ) ?></th>
				<th class="center"><?php echo lang_get( 'topic_projects' ) ?></th>
				<th><?php echo lang_get( 'topic_source_document' ) ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if( !$t_catalogues ) { ?>
			<tr><td colspan="5"><?php echo lang_get( 'topic_no_catalogues' ) ?></td></tr>
		<?php }
		foreach( $t_catalogues as $t_catalogue ) {
			$t_id = (int)$t_catalogue['id'];
			$t_source = (int)$t_catalogue['source_dwg_id']; ?>
			<tr>
				<td><a href="manage_topic_catalogue_page.php?id=<?php echo $t_id ?>"><?php
					echo string_display_line( topic_catalogue_label( $t_catalogue ) ) ?></a></td>
				<td><?php echo topic_catalogue_status_label( (int)$t_catalogue['status'] ) ?></td>
				<td class="center"><?php echo count( topic_get_all( $t_id, true ) ) ?></td>
				<td class="center"><?php echo topic_catalogue_project_count( $t_id ) ?></td>
				<td><?php echo $t_source > 0 ? string_get_dwg_view_link( $t_source ) : '' ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	</div>
	</div>
</div>

<?php
$t_unloaded = array_filter( $t_seeds, function( $p_seed ) { return !$p_seed['loaded']; } );
if( $t_unloaded ) { ?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-download', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_bundled_catalogues' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p><?php echo lang_get( 'topic_bundled_catalogues_intro' ) ?></p>
		<?php foreach( $t_unloaded as $t_seed ) {
			print_form_button(
				'manage_topic_catalogue_action.php',
				string_html_specialchars( sprintf( lang_get( 'topic_load_catalogue' ),
					topic_catalogue_label( $t_seed ) ) ),
				array( 'action' => 'load_seed', 'file' => $t_seed['file'] ),
				null,
				'btn btn-primary btn-sm btn-white btn-round'
			);
			echo ' ';
		} ?>
	</div>
	</div>
	</div>
</div>
<?php } ?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-plus', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_create_catalogue' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<form method="post" action="manage_topic_catalogue_action.php" class="form-inline">
			<?php echo form_security_field( 'manage_topic_catalogue_action' ) ?>
			<input type="hidden" name="action" value="create" />
			<label for="catalogue-name"><?php echo lang_get( 'name' ) ?></label>
			<input type="text" id="catalogue-name" name="name" class="input-sm" size="24" maxlength="128" required />
			<label for="catalogue-revision"><?php echo lang_get( 'topic_revision' ) ?></label>
			<input type="text" id="catalogue-revision" name="revision" class="input-sm" size="6" maxlength="32" />
			<label for="catalogue-source"><?php echo lang_get( 'topic_source_document_id' ) ?></label>
			<input type="number" id="catalogue-source" name="source_dwg_id" class="input-sm" min="0" style="width:8em" />
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'topic_create_catalogue' ) ?></button>
		</form>
	</div>
	</div>
	</div>
</div>

<?php
layout_page_end();
