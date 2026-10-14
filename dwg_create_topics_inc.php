<?php
# Doctis — "Documentation Topics" block of the document create form
# (doc/topics/TOPICS_PLAN.md §5). Included from dwg_create_page.php.
#
# Expects: $t_project_id (project of the new document).

require_api( 'topic_api.php' );

if( topic_create_form_enabled( (int)$t_project_id ) ) {
	$t_topics_catalogue_id = (int)topic_project_get( (int)$t_project_id )['catalogue_id'];
?>
<div class="space-10"></div>
<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-tags', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_page_title' ) ?>
		</h4>
	</div>
	<div class="widget-body">
		<div class="widget-main no-padding">
			<div class="table-responsive">
				<table class="table table-bordered table-condensed table-striped">
				<tr>
					<td colspan="3" class="grey"><?php echo lang_get( 'topic_create_hint' ) ?></td>
				</tr>
				<?php foreach( topic_get_all( $t_topics_catalogue_id, true ) as $t_topic_id => $t_topic ) {
					$t_field_id = 'create-topic-' . (int)$t_topic_id; ?>
				<tr>
					<td class="width-40">
						<label for="<?php echo $t_field_id ?>" title="<?php echo string_attribute( $t_topic['description'] ) ?>">
							<input type="checkbox" class="ace" id="<?php echo $t_field_id ?>" name="topic_ids[]" value="<?php echo (int)$t_topic_id ?>" />
							<span class="lbl"> <?php echo ( $t_topic['level'] ? '&nbsp;&nbsp;&nbsp;' : '' ) . string_display_line( topic_label( $t_topic ) ) ?></span>
						</label>
					</td>
					<td class="width-15"><?php print_topic_extent_select( 'topic_extent[' . (int)$t_topic_id . ']' ); ?></td>
					<td><input type="text" name="topic_locator[<?php echo (int)$t_topic_id ?>]" class="input-sm" size="30" maxlength="255"
						placeholder="<?php echo lang_get( 'topic_sections' ) ?>" aria-label="<?php echo string_attribute( $t_topic['code'] . ' ' . lang_get( 'topic_sections' ) ) ?>" /></td>
				</tr>
				<?php } ?>
				</table>
			</div>
		</div>
	</div>
</div>
<?php
}
