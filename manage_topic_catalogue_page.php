<?php
# Doctis — Manage > Topics: one Topic catalogue and its Topics.

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

$f_catalogue_id = gpc_get_int( 'id' );
$t_catalogue = topic_catalogue_get( $f_catalogue_id );
$t_status = (int)$t_catalogue['status'];
$t_is_draft = $t_status == TOPIC_CATALOGUE_DRAFT;
$t_topics = topic_get_all( $f_catalogue_id );
$t_project_count = topic_catalogue_project_count( $f_catalogue_id );
$t_source = (int)$t_catalogue['source_dwg_id'];
$t_action = 'manage_topic_catalogue_action.php';
$t_button = 'btn btn-primary btn-sm btn-white btn-round';

layout_page_header( topic_catalogue_label( $t_catalogue ) . ' — ' . lang_get( 'manage_topics_link' ) );
layout_page_begin( 'manage_overview_page.php' );
print_manage_menu( 'manage_topic_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-tags', 'ace-icon' ); ?>
			<?php echo string_display_line( topic_catalogue_label( $t_catalogue ) ) ?>
			&mdash; <?php echo topic_catalogue_status_label( $t_status ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p><?php echo lang_get( $t_is_draft ? 'topic_catalogue_draft_help'
			: ( $t_status == TOPIC_CATALOGUE_PUBLISHED ? 'topic_catalogue_published_help' : 'topic_catalogue_retired_help' ) ) ?></p>

		<?php if( $t_is_draft ) { ?>
		<form method="post" action="<?php echo $t_action ?>" class="form-inline">
			<?php echo form_security_field( 'manage_topic_catalogue_action' ) ?>
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="id" value="<?php echo $f_catalogue_id ?>" />
			<label for="catalogue-name"><?php echo lang_get( 'name' ) ?></label>
			<input type="text" id="catalogue-name" name="name" class="input-sm" size="24" maxlength="128" required
				value="<?php echo string_attribute( $t_catalogue['name'] ) ?>" />
			<label for="catalogue-revision"><?php echo lang_get( 'topic_revision' ) ?></label>
			<input type="text" id="catalogue-revision" name="revision" class="input-sm" size="6" maxlength="32"
				value="<?php echo string_attribute( $t_catalogue['revision'] ) ?>" />
			<label for="catalogue-source"><?php echo lang_get( 'topic_source_document_id' ) ?></label>
			<input type="number" id="catalogue-source" name="source_dwg_id" class="input-sm" min="0" style="width:8em"
				value="<?php echo $t_source > 0 ? $t_source : '' ?>" />
			<button type="submit" class="<?php echo $t_button ?>"><?php echo lang_get( 'update' ) ?></button>
		</form>
		<div class="space-10"></div>
		<?php } ?>

		<table class="table table-bordered table-condensed" style="max-width:700px;">
			<tr>
				<th class="category"><?php echo lang_get( 'topic_source_document' ) ?></th>
				<td><?php echo $t_source > 0 && dwg_exists( $t_source )
					? string_get_dwg_view_link( $t_source ) . ' ' . string_display_line( (string)dwg_get_field( $t_source, 'title' ) )
						. ' (' . get_enum_element( 'dwg_status', (int)dwg_get_field( $t_source, 'status' ) ) . ')'
					: lang_get( 'topic_none' ) ?></td>
			</tr>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_projects' ) ?></th>
				<td><?php echo $t_project_count ?></td>
			</tr>
		</table>

		<?php
		if( $t_is_draft ) {
			$t_blocker = topic_catalogue_publish_blocker( $t_catalogue );
			if( $t_blocker === '' ) {
				print_form_button( $t_action, lang_get( 'topic_publish' ),
					array( 'action' => 'publish', 'id' => $f_catalogue_id ), null, $t_button );
			} else {
				echo '<p class="grey">' . lang_get( 'topic_cannot_publish' ) . ' ' . string_display_line( $t_blocker ) . '</p>';
			}
			if( $t_project_count == 0 ) {
				echo ' ';
				print_form_button( $t_action, lang_get( 'topic_delete_catalogue' ),
					array( 'action' => 'delete', 'id' => $f_catalogue_id ), null, 'btn btn-danger btn-sm btn-white btn-round' );
			}
		} else if( $t_status == TOPIC_CATALOGUE_PUBLISHED ) {
			print_form_button( $t_action, lang_get( 'topic_retire_catalogue' ),
				array( 'action' => 'retire', 'id' => $f_catalogue_id ), null, $t_button );
		} else {
			print_form_button( $t_action, lang_get( 'topic_republish' ),
				array( 'action' => 'publish', 'id' => $f_catalogue_id ), null, $t_button );
		}
		if( !$t_is_draft ) { ?>
		<div class="space-10"></div>
		<form method="post" action="<?php echo $t_action ?>" class="form-inline">
			<?php echo form_security_field( 'manage_topic_catalogue_action' ) ?>
			<input type="hidden" name="action" value="new_revision" />
			<input type="hidden" name="id" value="<?php echo $f_catalogue_id ?>" />
			<label for="catalogue-new-revision"><?php echo lang_get( 'topic_new_revision' ) ?></label>
			<input type="text" id="catalogue-new-revision" name="revision" class="input-sm" size="6" maxlength="32" required />
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'topic_create_revision' ) ?></button>
			<span class="grey small"><?php echo lang_get( 'topic_new_revision_hint' ) ?></span>
		</form>
		<?php } ?>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-list', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_topics' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-striped table-bordered table-condensed">
		<thead>
			<tr>
				<th><?php echo lang_get( 'topic_code' ) ?></th>
				<th><?php echo lang_get( 'topic_title' ) ?></th>
				<th><?php echo lang_get( 'topic_expected_information' ) ?></th>
				<th><?php echo lang_get( 'status' ) ?></th>
				<th><?php echo lang_get( 'actions' ) ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if( !$t_topics ) { ?>
			<tr><td colspan="5"><?php echo lang_get( 'topic_no_topics' ) ?></td></tr>
		<?php }
		foreach( $t_topics as $t_topic_id => $t_topic ) {
			$t_enabled = (int)$t_topic['enabled'] == 1; ?>
			<tr<?php echo $t_enabled ? '' : ' class="grey"' ?>>
				<td class="nowrap"><?php echo ( $t_topic['level'] ? '&nbsp;&nbsp;&raquo; ' : '' ) . string_display_line( $t_topic['code'] ) ?></td>
				<td><?php echo string_display_line( $t_topic['title'] ) ?></td>
				<td><?php echo string_display_links( $t_topic['description'] ) ?></td>
				<td><?php echo lang_get( $t_enabled ? 'topic_active' : 'topic_retired' ) ?></td>
				<td class="nowrap">
				<?php
				if( $t_is_draft ) {
					print_link_button( 'manage_topic_edit_page.php?id=' . $t_topic_id, lang_get( 'edit' ), 'btn-xs' );
					echo ' ';
				}
				if( $t_status != TOPIC_CATALOGUE_RETIRED ) {
					print_form_button( 'manage_topic_action.php', lang_get( $t_enabled ? 'topic_retire' : 'topic_restore' ),
						array( 'action' => $t_enabled ? 'retire' : 'restore', 'id' => $t_topic_id ) );
				}
				if( $t_is_draft && !topic_is_used( $t_topic_id ) ) {
					echo ' ';
					print_form_button( 'manage_topic_action.php', lang_get( 'delete' ),
						array( 'action' => 'delete', 'id' => $t_topic_id ), null, 'btn btn-danger btn-xs btn-white btn-round' );
				}
				?>
				</td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	</div>
	</div>
</div>

<?php if( $t_is_draft ) { ?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-plus', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_add_topic' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php
		$t_form_topic = array( 'id' => 0, 'code' => '', 'parent_id' => 0, 'title' => '', 'description' => '', 'sort_order' => ( count( $t_topics ) + 1 ) * 10 );
		$t_form_catalogue_id = $f_catalogue_id;
		$t_form_parents = $t_topics;
		include( __DIR__ . '/manage_topic_form_inc.php' );
		?>
	</div>
	</div>
	</div>
</div>
<?php } ?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<?php print_link_button( 'manage_topic_page.php', lang_get( 'topic_back_to_catalogues' ) ); ?>
</div>

<?php
layout_page_end();
