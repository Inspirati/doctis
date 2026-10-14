<?php
# Doctis — "Documentation Topics" block of the document view page
# (doc/topics/TOPICS_PLAN.md §4.4). Included from dwg_view_inc.php.
#
# Expects: $f_dwg_id, $t_force_readonly.

require_api( 'topic_api.php' );

$t_topics_project_id = (int)dwg_get_field( $f_dwg_id, 'project_id' );
$t_topics_own = topic_project_is_enabled( $t_topics_project_id ) && topic_can_view_project( $t_topics_project_id );

# Other projects using this document as shared evidence (TOP-FR-009), shown
# even when its own project does not use Topics.
$t_shared_lines = array();
if( topic_feature_enabled() ) {
	foreach( topic_dwg_get_shared_uses( $f_dwg_id ) as $t_use_project_id => $t_use_tags ) {
		if( !topic_can_view_project( $t_use_project_id ) ) {
			continue;
		}
		$t_codes = array();
		foreach( $t_use_tags as $t_use ) {
			$t_codes[] = $t_use['code'] . ' (' . topic_extent_label( (int)$t_use['extent'] ) . ')';
		}
		$t_shared_lines[] = '<a href="topic_page.php?project_id=' . (int)$t_use_project_id . '">'
			. string_display_line( project_get_name( $t_use_project_id ) ) . '</a>: ' . string_display_line( implode( ', ', $t_codes ) );
	}
}

if( $t_topics_own || $t_shared_lines ) {
	$t_topics_tags = $t_topics_own ? topic_dwg_get_for_dwg( $f_dwg_id ) : array();
	$t_topics_can_tag = $t_topics_own && !$t_force_readonly && topic_can_tag_dwg( $f_dwg_id );
	$t_topics_catalogue_id = $t_topics_own ? (int)topic_project_get( $t_topics_project_id )['catalogue_id'] : 0;
	$t_return = array( 'return' => 'view' );

	$t_collapse_block = is_collapsed( 'topics', false );
	$t_block_css = $t_collapse_block ? 'collapsed' : '';
	$t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';
?>
	<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>

	<div id="topics" class="widget-box widget-color-blue2 <?php echo $t_block_css ?>">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-tags', 'ace-icon' ); ?>
				<?php echo lang_get( 'topic_page_title' ) ?>
			</h4>
			<div class="widget-toolbar">
				<a data-action="collapse" href="#">
					<?php print_icon( $t_block_icon, '1 ace-icon bigger-125' ); ?>
				</a>
			</div>
		</div>

		<div class="widget-body">
			<div class="widget-main no-padding">
				<div class="table-responsive">
					<table class="table table-bordered table-condensed table-striped">
					<?php if( $t_topics_own && !$t_topics_tags ) { ?>
						<tr><td colspan="4"><?php echo lang_get( 'topic_document_untagged' ) ?></td></tr>
					<?php }
					foreach( $t_topics_tags as $t_tag ) { ?>
						<tr>
							<td class="width-35">
								<a href="topic_detail_page.php?project_id=<?php echo $t_topics_project_id ?>&amp;topic_id=<?php echo (int)$t_tag['topic_id'] ?>"><?php
									echo string_display_line( $t_tag['code'] . ' ' . $t_tag['title'] ) ?></a>
								<?php if( (int)$t_tag['topic_enabled'] != 1 ) { echo ' <span class="grey">(' . lang_get( 'topic_retired' ) . ')</span>'; } ?>
							</td>
							<?php if( $t_topics_can_tag ) { ?>
							<td class="nowrap" colspan="3"><?php include( __DIR__ . '/dwg_topic_edit_inc.php' ); ?></td>
							<?php } else { ?>
							<td class="width-15"><?php echo topic_extent_label( (int)$t_tag['extent'] ) ?></td>
							<td colspan="2"><?php echo string_display_line( $t_tag['locator'] ) ?></td>
							<?php } ?>
						</tr>
					<?php }

					if( $t_topics_can_tag ) {
						$t_tagged_ids = array();
						foreach( $t_topics_tags as $t_tag ) {
							$t_tagged_ids[] = (int)$t_tag['topic_id'];
						}
						ob_start();
						$t_option_count = print_topic_option_list( $t_topics_catalogue_id, $t_tagged_ids );
						$t_options = ob_get_clean();
						if( $t_option_count > 0 ) { ?>
						<tr class="noprint">
							<td colspan="4">
								<form method="post" action="dwg_topic_add.php" class="form-inline">
									<?php echo form_security_field( 'dwg_topic_add' ) ?>
									<input type="hidden" name="dwg_id" value="<?php echo (int)$f_dwg_id ?>" />
									<input type="hidden" name="return" value="view" />
									<label for="dwg-topic-add"><?php echo lang_get( 'topic_add_tag' ) ?></label>
									<select id="dwg-topic-add" name="topic_id" class="input-sm"><?php echo $t_options ?></select>
									<?php print_topic_extent_select( 'extent' ); ?>
									<input type="text" name="locator" class="input-sm" size="20" maxlength="255"
										placeholder="<?php echo lang_get( 'topic_sections' ) ?>" aria-label="<?php echo lang_get( 'topic_sections' ) ?>" />
									<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'topic_tag' ) ?></button>
								</form>
							</td>
						</tr>
					<?php }
					} ?>
					<?php if( $t_shared_lines ) { ?>
						<tr>
							<td colspan="4"><?php echo lang_get( 'topic_shared_uses' ) ?> <?php echo implode( '; ', $t_shared_lines ) ?></td>
						</tr>
					<?php } ?>
						<tr>
							<td colspan="4" class="grey small">
								<?php echo lang_get( 'topic_extent_hint' ) ?>
								<?php if( $t_topics_own ) { ?>
								<a href="topic_page.php?project_id=<?php echo $t_topics_project_id ?>"><?php echo lang_get( 'topic_all_project_topics' ) ?></a>
								<?php } ?>
							</td>
						</tr>
					</table>
				</div>
			</div>
		</div>
	</div>
	</div>
<?php
}
