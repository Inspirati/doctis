<?php
# Doctis — One Documentation Topic of a project: expected information,
# applicability decision and its history, and the documents tagged with it.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_api.php' );
require_api( 'user_api.php' );
require_api( 'topic_expectation_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id' );
$f_topic_id = gpc_get_int( 'topic_id' );
$f_milestone_id = gpc_get_int( 'milestone_id', 0 );

project_ensure_exists( $f_project_id );
if( !topic_can_view_project( $f_project_id ) ) {
	access_denied();
}
$t_catalogue = topic_project_catalogue( $f_project_id, $f_topic_id );
$t_view = topic_coverage_view( $f_project_id, $f_milestone_id );
$t_milestone_id = $t_view['milestone_id'];
$t_milestones = $t_view['milestones'];
if( !isset( $t_view['topics'][$f_topic_id] ) ) {
	topic_error( 'that Topic is retired and has no records in this project.' );
}
$t_topic = $t_view['topics'][$f_topic_id];
$t_can_manage = topic_can_manage_project( $f_project_id );
$t_history = topic_applicability_history( $f_project_id, $f_topic_id );
$t_return = array( 'return' => 'detail', 'topic_id' => $f_topic_id );
$t_parent = (int)$t_topic['parent_id'] != 0 ? topic_get( (int)$t_topic['parent_id'] ) : null;

layout_page_header( topic_label( $t_topic ) . ' — ' . lang_get( 'topics_link' ) );
layout_page_begin( 'topic_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-tag', 'ace-icon' ); ?>
			<?php echo string_display_line( topic_label( $t_topic ) . ' — ' . project_get_name( $f_project_id ) ) ?>
		</h4>
		<div class="widget-toolbar"><?php echo topic_state_html( $t_topic['state'] ) ?></div>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<table class="table table-bordered table-condensed">
			<tr>
				<th class="category width-20"><?php echo lang_get( 'topic_catalogue' ) ?></th>
				<td><?php echo string_display_line( topic_catalogue_label( $t_catalogue ) )
					. ' (' . topic_catalogue_status_label( (int)$t_catalogue['status'] ) . ')' ?></td>
			</tr>
			<?php if( $t_parent !== null ) { ?>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_parent' ) ?></th>
				<td><?php echo string_display_line( topic_label( $t_parent ) ) ?>
					<span class="grey"><?php echo lang_get( 'topic_grouping_only' ) ?></span></td>
			</tr>
			<?php } ?>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_expected_information' ) ?></th>
				<td><?php echo string_display_links( $t_topic['description'] ) ?></td>
			</tr>
		</table>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12" id="applicability">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-check-square-o', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_required' ) ?>: <?php echo topic_applicability_label( (int)$t_topic['applicability'] ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php if( $t_can_manage ) {
			$t_current = (int)$t_topic['applicability']; ?>
		<form method="post" action="topic_applicability_update.php">
			<?php echo form_security_field( 'topic_applicability_update' ) ?>
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<input type="hidden" name="topic_id" value="<?php echo (int)$f_topic_id ?>" />
			<input type="hidden" name="expected_decision_id" value="<?php echo $t_topic['decision'] === null ? 0 : (int)$t_topic['decision']['id'] ?>" />
			<div class="form-group">
			<?php foreach( array( TOPIC_APPLICABLE, TOPIC_NOT_APPLICABLE, TOPIC_UNDECIDED ) as $t_value ) { ?>
				<label class="inline" style="margin-right:16px;">
					<input type="radio" name="applicability" value="<?php echo $t_value ?>"<?php
						echo $t_value == $t_current ? ' checked="checked"' : '' ?> />
					<?php echo topic_applicability_label( $t_value ) ?>
				</label>
			<?php } ?>
			</div>
			<div class="form-group">
				<label for="topic-rationale"><?php echo lang_get( 'topic_rationale' ) ?></label>
				<textarea id="topic-rationale" name="rationale" class="form-control" rows="2" maxlength="1024"><?php
					echo string_textarea( $t_topic['decision']['rationale'] ?? '' ) ?></textarea>
				<span class="grey small"><?php echo lang_get( 'topic_rationale_hint' ) ?></span>
			</div>
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'topic_record_decision' ) ?></button>
		</form>
		<div class="space-10"></div>
		<?php } ?>

		<table class="table table-bordered table-condensed table-striped">
			<thead>
				<tr>
					<th><?php echo lang_get( 'date_modified' ) ?></th>
					<th><?php echo lang_get( 'username' ) ?></th>
					<th><?php echo lang_get( 'topic_required' ) ?></th>
					<th><?php echo lang_get( 'topic_rationale' ) ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if( !$t_history ) { ?>
				<tr><td colspan="4"><?php echo lang_get( 'topic_no_decisions' ) ?></td></tr>
			<?php }
			foreach( $t_history as $t_row ) { ?>
				<tr>
					<td class="nowrap"><?php echo date( config_get( 'normal_date_format' ), (int)$t_row['date_decided'] ) ?></td>
					<td><?php echo string_display_line( user_get_name( (int)$t_row['user_id'] ) ) ?></td>
					<td><?php echo topic_applicability_label( (int)$t_row['applicability'] ) ?></td>
					<td><?php echo string_display_line( $t_row['rationale'] ) ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12" id="expectations">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-list-ol', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_expectations' ) ?>
		</h4>
		<div class="widget-toolbar"><?php echo topic_exp_state_html( $t_topic['expectation_state'] ) ?></div>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php print_topic_milestone_selector( 'topic_detail_page.php',
			array( 'project_id' => $f_project_id, 'topic_id' => $f_topic_id ), $t_milestones, $t_milestone_id ); ?>
		<p class="small grey"><?php echo lang_get( 'topic_expectations_intro' ) ?></p>
	</div>
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th><?php echo lang_get( 'topic_expectation' ) ?></th>
			<th><?php echo lang_get( 'topic_owner' ) ?></th>
			<th><?php echo lang_get( 'topic_due_from' ) ?></th>
			<th><?php echo lang_get( 'topic_required_maturity' ) ?></th>
			<th><?php echo lang_get( 'topic_evidence' ) ?></th>
			<th><?php echo lang_get( 'topic_state' ) ?></th>
		</tr></thead>
		<tbody>
		<?php if( !$t_topic['expectations'] ) { ?>
			<tr><td colspan="6"><?php echo lang_get( 'topic_no_expectations' ) ?></td></tr>
		<?php }
		foreach( $t_topic['expectations'] as $t_expectation_id => $t_expectation ) {
			$t_link = 'topic_expectation_page.php?id=' . (int)$t_expectation_id . '&milestone_id=' . (int)$t_milestone_id; ?>
			<tr>
				<td><a href="<?php echo string_attribute( $t_link ) ?>"><?php echo string_display_line( topic_expectation_ref( $t_expectation ) ) ?></a>
					<?php echo string_display_line( $t_expectation['statement'] ) ?></td>
				<td><?php echo string_display_line( topic_user_name( (int)$t_expectation['owner_id'] ) ) ?></td>
				<td><?php echo string_display_line( $t_milestones[(int)$t_expectation['milestone_id']]['name'] ?? '' ) ?></td>
				<td><?php echo topic_maturity_label( (int)$t_expectation['maturity'] ) ?></td>
				<td class="center"><?php echo count( $t_expectation['evidence_visible'] ) ?></td>
				<td><?php echo topic_exp_state_with_reasons_html( $t_expectation['state'], $t_expectation['reasons'] ) ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	<?php if( $t_can_manage && $t_milestones && (int)$t_topic['enabled'] == 1 ) { ?>
	<div class="widget-main">
		<form method="post" action="topic_expectation_action.php">
			<?php echo form_security_field( 'topic_expectation_action' ) ?>
			<input type="hidden" name="action" value="create" />
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<input type="hidden" name="topic_id" value="<?php echo (int)$f_topic_id ?>" />
			<input type="hidden" name="milestone_view" value="<?php echo (int)$t_milestone_id ?>" />
			<div class="form-group">
				<label for="expectation-statement"><?php echo lang_get( 'topic_add_expectation' ) ?></label>
				<textarea id="expectation-statement" name="statement" class="form-control" rows="2" maxlength="1024" required
					placeholder="<?php echo lang_get( 'topic_expectation_placeholder' ) ?>"></textarea>
			</div>
			<div class="form-inline">
				<label for="expectation-owner"><?php echo lang_get( 'topic_owner' ) ?></label>
				<select id="expectation-owner" name="owner_id" class="input-sm">
					<option value="0">&mdash;</option>
					<?php print_user_option_list( 0, $f_project_id, REPORTER ); ?>
				</select>
				<label for="expectation-milestone"><?php echo lang_get( 'topic_due_from' ) ?></label>
				<select id="expectation-milestone" name="milestone_id" class="input-sm">
					<?php print_topic_milestone_option_list( $t_milestones, $t_milestone_id ); ?>
				</select>
				<label for="expectation-maturity"><?php echo lang_get( 'topic_required_maturity' ) ?></label>
				<?php print_topic_maturity_select( 'maturity', TOPIC_MATURITY_DRAFT, 'expectation-maturity' ); ?>
				<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'topic_add_expectation' ) ?></button>
			</div>
		</form>
	</div>
	<?php } else if( $t_can_manage && !$t_milestones ) { ?>
	<div class="widget-main"><a href="topic_milestone_page.php?project_id=<?php echo (int)$f_project_id ?>"><?php echo lang_get( 'topic_no_milestones' ) ?></a></div>
	<?php } ?>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-files-o', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_documents' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p><?php echo lang_get( 'topic_declared_notice' ) ?></p>
		<table class="table table-bordered table-condensed table-striped">
			<?php if( !$t_topic['tags'] && !$t_topic['restricted'] ) { ?>
			<tr><td><?php echo lang_get( 'topic_no_documents' ) ?></td></tr>
			<?php }
			foreach( $t_topic['tags'] as $t_tag ) {
				$t_dwg_id = (int)$t_tag['dwg_id']; ?>
			<tr>
				<td><?php echo topic_tag_html( $t_tag ) ?></td>
				<td class="nowrap">
				<?php if( topic_can_tag_dwg( $t_dwg_id ) ) {
					$t_tag_id = (int)$t_tag['id'];
					include( __DIR__ . '/dwg_topic_edit_inc.php' );
				} ?>
				</td>
			</tr>
			<?php }
			if( $t_topic['restricted'] ) { ?>
			<tr><td colspan="2" class="grey"><?php echo lang_get( 'topic_restricted_documents' ) ?></td></tr>
			<?php } ?>
		</table>

		<?php
		# Add a document of the project that is not yet tagged with this Topic.
		$t_tagged = array();
		foreach( $t_topic['tags'] as $t_tag ) {
			$t_tagged[(int)$t_tag['dwg_id']] = true;
		}
		$t_candidates = array();
		if( (int)$t_topic['enabled'] == 1 ) {
			foreach( topic_project_documents( $f_project_id ) as $t_dwg_id => $t_document ) {
				if( !isset( $t_tagged[$t_dwg_id] ) && topic_can_see_dwg( $t_dwg_id ) && topic_can_tag_dwg( $t_dwg_id ) ) {
					$t_candidates[$t_dwg_id] = $t_document;
				}
			}
		}
		if( $t_candidates ) { ?>
		<form method="post" action="dwg_topic_add.php" class="form-inline">
			<?php echo form_security_field( 'dwg_topic_add' ) ?>
			<input type="hidden" name="topic_id" value="<?php echo (int)$f_topic_id ?>" />
			<input type="hidden" name="return" value="detail" />
			<label for="topic-add-document"><?php echo lang_get( 'topic_add_document' ) ?></label>
			<select id="topic-add-document" name="dwg_id" class="input-sm">
			<?php foreach( $t_candidates as $t_dwg_id => $t_document ) { ?>
				<option value="<?php echo (int)$t_dwg_id ?>"><?php echo string_display_line( topic_document_label( $t_document ) ) ?></option>
			<?php } ?>
			</select>
			<?php print_topic_extent_select( 'extent' ); ?>
			<input type="text" name="locator" class="input-sm" size="16" maxlength="255"
				placeholder="<?php echo lang_get( 'topic_sections' ) ?>" aria-label="<?php echo lang_get( 'topic_sections' ) ?>" />
			<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'topic_tag' ) ?></button>
		</form>
		<?php } ?>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<?php print_link_button( 'topic_page.php?project_id=' . (int)$f_project_id . '&milestone_id=' . (int)$t_milestone_id, lang_get( 'topic_back_to_topics' ) ); ?>
</div>

<?php
layout_page_end();
