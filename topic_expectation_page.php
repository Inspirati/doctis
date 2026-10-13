<?php
# Doctis — One Documentation Topics expectation (doc/topics/TOPICS_PLAN.md §7):
# its state at the selected milestone with reasons, evidence, adequacy
# assessments and revision history. Project managers edit it, document
# contributors link evidence, authorised assessors record assessments.

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
require_api( 'topic_expectation_api.php' );

auth_ensure_user_authenticated();

$f_expectation_id = gpc_get_int( 'id' );
$f_milestone_id = gpc_get_int( 'milestone_id', 0 );

$t_expectation = topic_expectation_get( $f_expectation_id );
$t_project_id = (int)$t_expectation['project_id'];
if( !topic_can_view_project( $t_project_id ) ) {
	access_denied();
}
topic_project_catalogue( $t_project_id );
$t_topic = topic_get( (int)$t_expectation['topic_id'] );
$t_milestones = topic_milestone_get_all( $t_project_id );
if( !isset( $t_milestones[$f_milestone_id] ) ) {
	$f_milestone_id = topic_milestone_current( $t_milestones );
}
$t_evidence = topic_evidence_get_all( $f_expectation_id );
$t_assessments = topic_assessment_history( $f_expectation_id );
$t_latest = $t_assessments[0] ?? null;
$t_state = topic_expectation_state( $t_expectation, $t_milestones, $f_milestone_id, $t_evidence, $t_latest );
$t_enabled = (int)$t_expectation['enabled'] == 1;
$t_can_manage = topic_can_manage_project( $t_project_id );
$t_can_assess = $t_enabled && topic_can_assess( $t_project_id, $t_topic['code'] );
$t_ref = topic_expectation_ref( $t_expectation );
$t_self = 'topic_expectation_page.php?id=' . $f_expectation_id . '&milestone_id=' . $f_milestone_id;
$t_hidden = 0;
foreach( $t_evidence as $t_dwg_id => $t_item ) {
	if( !topic_can_see_dwg( $t_dwg_id ) ) {
		$t_hidden++;
	}
}
$t_date_format = config_get( 'normal_date_format' );

layout_page_header( sprintf( lang_get( 'topic_expectation_ref' ), $t_ref ) . ' — ' . lang_get( 'topics_link' ) );
layout_page_begin( 'topic_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-list-ol', 'ace-icon' ); ?>
			<?php echo string_display_line( sprintf( lang_get( 'topic_expectation_ref' ), $t_ref ) . ' — '
				. topic_label( $t_topic ) . ' — ' . project_get_name( $t_project_id ) ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<?php print_topic_milestone_selector( 'topic_expectation_page.php', array( 'id' => $f_expectation_id ), $t_milestones, $f_milestone_id ); ?>
		<div class="space-6"></div>
		<table class="table table-bordered table-condensed">
			<tr>
				<th class="category width-20"><?php echo lang_get( 'topic_expectation' ) ?></th>
				<td><?php echo string_display_links( $t_expectation['statement'] ) ?>
					<?php if( !$t_enabled ) { echo ' <span class="label label-grey">' . lang_get( 'topic_retired' ) . '</span>'; } ?></td>
			</tr>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_state' ) ?></th>
				<td><?php echo topic_exp_state_with_reasons_html( $t_state['state'], $t_state['reasons'] ) ?>
					<span class="grey small"><?php echo string_display_line( sprintf( lang_get( 'topic_state_at' ),
						$t_milestones[$f_milestone_id]['name'] ?? '' ) ) ?></span></td>
			</tr>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_owner' ) ?></th>
				<td><?php echo string_display_line( topic_user_name( (int)$t_expectation['owner_id'] ) ) ?></td>
			</tr>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_due_from' ) ?></th>
				<td><?php echo string_display_line( $t_milestones[(int)$t_expectation['milestone_id']]['name'] ?? '' ) ?></td>
			</tr>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_required_maturity' ) ?></th>
				<td><?php echo topic_maturity_label( (int)$t_expectation['maturity'] ) ?></td>
			</tr>
			<tr>
				<th class="category"><?php echo lang_get( 'topic_revision' ) ?></th>
				<td><?php echo (int)$t_expectation['revision'] ?></td>
			</tr>
		</table>

		<?php if( $t_can_manage && $t_milestones ) { ?>
		<details>
			<summary class="noprint"><?php echo lang_get( 'topic_edit_expectation' ) ?></summary>
			<form method="post" action="topic_expectation_action.php">
				<?php echo form_security_field( 'topic_expectation_action' ) ?>
				<input type="hidden" name="action" value="update" />
				<input type="hidden" name="id" value="<?php echo $f_expectation_id ?>" />
				<input type="hidden" name="expected_revision" value="<?php echo (int)$t_expectation['revision'] ?>" />
				<input type="hidden" name="milestone_view" value="<?php echo (int)$f_milestone_id ?>" />
				<div class="form-group">
					<textarea name="statement" class="form-control" rows="2" maxlength="1024" required
						aria-label="<?php echo lang_get( 'topic_expectation' ) ?>"><?php echo string_textarea( $t_expectation['statement'] ) ?></textarea>
				</div>
				<div class="form-inline">
					<label for="expectation-owner"><?php echo lang_get( 'topic_owner' ) ?></label>
					<select id="expectation-owner" name="owner_id" class="input-sm">
						<option value="0">&mdash;</option>
						<?php print_user_option_list( (int)$t_expectation['owner_id'], $t_project_id, REPORTER ); ?>
					</select>
					<label for="expectation-milestone"><?php echo lang_get( 'topic_due_from' ) ?></label>
					<select id="expectation-milestone" name="milestone_id" class="input-sm">
						<?php print_topic_milestone_option_list( $t_milestones, (int)$t_expectation['milestone_id'] ); ?>
					</select>
					<label for="expectation-maturity"><?php echo lang_get( 'topic_required_maturity' ) ?></label>
					<?php print_topic_maturity_select( 'maturity', (int)$t_expectation['maturity'], 'expectation-maturity' ); ?>
					<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'update' ) ?></button>
				</div>
				<p class="small grey"><?php echo lang_get( 'topic_edit_expectation_hint' ) ?></p>
			</form>
			<?php print_form_button( 'topic_expectation_action.php', lang_get( $t_enabled ? 'topic_retire' : 'topic_restore' ),
				array( 'action' => $t_enabled ? 'retire' : 'restore', 'id' => $f_expectation_id, 'milestone_view' => $f_milestone_id,
					'expected_revision' => (int)$t_expectation['revision'] ) ); ?>
		</details>
		<?php } ?>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12" id="evidence">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-files-o', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_evidence' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th><?php echo lang_get( 'topic_document' ) ?></th>
			<th><?php echo lang_get( 'status' ) ?></th>
			<th><?php echo lang_get( 'topic_maturity' ) ?></th>
			<th><?php echo lang_get( 'topic_version' ) ?></th>
			<th><?php echo lang_get( 'topic_sections' ) ?></th>
			<th></th>
		</tr></thead>
		<tbody>
		<?php if( !$t_evidence ) { ?>
			<tr><td colspan="6"><?php echo lang_get( 'topic_reason_no_evidence' ) ?></td></tr>
		<?php }
		foreach( $t_evidence as $t_dwg_id => $t_item ) {
			if( !topic_can_see_dwg( $t_dwg_id ) ) {
				continue;
			}
			$t_status = (int)$t_item['status'];
			$t_maturity = topic_status_maturity( $t_status, $t_project_id ); ?>
			<tr>
				<td><?php echo string_get_dwg_view_title_link( $t_dwg_id, string_display_line( topic_document_label( $t_item ) ), false ) ?></td>
				<td><?php echo string_display_line( get_enum_element( 'dwg_status', $t_status ) ) ?></td>
				<td><?php echo $t_maturity === null ? '&mdash;' : topic_maturity_label( $t_maturity ) ?></td>
				<td><code><?php echo string_display_line( topic_evidence_version_label( $t_item['version'] ) ) ?></code></td>
				<td><?php echo string_display_line( $t_item['locator'] ) ?></td>
				<td class="nowrap"><?php
					if( topic_can_tag_dwg( $t_dwg_id ) ) {
						print_form_button( 'topic_evidence_action.php', lang_get( 'topic_remove' ),
							array( 'action' => 'remove', 'evidence_id' => (int)$t_item['evidence_id'], 'milestone_view' => $f_milestone_id ),
							null, 'btn btn-danger btn-xs btn-white btn-round' );
					} ?></td>
			</tr>
		<?php }
		if( $t_hidden > 0 ) { ?>
			<tr><td colspan="6" class="grey"><?php echo lang_get( 'topic_restricted_evidence' ) ?></td></tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	<?php
	# Documents of the project tagged with this Topic that may become evidence.
	$t_candidates = array();
	if( $t_enabled ) {
		$t_view = topic_project_view( $t_project_id );
		foreach( $t_view['topics'][(int)$t_topic['id']]['tags'] ?? array() as $t_tag ) {
			$t_dwg_id = (int)$t_tag['dwg_id'];
			if( !isset( $t_evidence[$t_dwg_id] ) && topic_can_tag_dwg( $t_dwg_id ) ) {
				$t_candidates[$t_dwg_id] = $t_tag['document'];
			}
		}
	}
	if( $t_candidates ) { ?>
	<div class="widget-main">
		<form method="post" action="topic_evidence_action.php" class="form-inline">
			<?php echo form_security_field( 'topic_evidence_action' ) ?>
			<input type="hidden" name="action" value="add" />
			<input type="hidden" name="expectation_id" value="<?php echo $f_expectation_id ?>" />
			<input type="hidden" name="milestone_view" value="<?php echo (int)$f_milestone_id ?>" />
			<label for="evidence-document"><?php echo lang_get( 'topic_add_evidence' ) ?></label>
			<select id="evidence-document" name="dwg_id" class="input-sm">
			<?php foreach( $t_candidates as $t_dwg_id => $t_document ) { ?>
				<option value="<?php echo (int)$t_dwg_id ?>"><?php echo string_display_line( topic_document_label( $t_document ) ) ?></option>
			<?php } ?>
			</select>
			<input type="text" name="locator" class="input-sm" size="24" maxlength="255"
				placeholder="<?php echo lang_get( 'topic_sections' ) ?>" aria-label="<?php echo lang_get( 'topic_sections' ) ?>" />
			<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'topic_add_evidence' ) ?></button>
		</form>
		<p class="small grey"><?php echo string_display_line( sprintf( lang_get( 'topic_evidence_hint' ), $t_topic['code'] ) ) ?></p>
	</div>
	<?php } ?>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12" id="assessments">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-gavel', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_assessments' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p class="small grey"><?php echo lang_get( 'topic_assessment_notice' ) ?></p>
		<?php if( $t_can_assess && $t_evidence ) { ?>
		<form method="post" action="topic_assessment_add.php">
			<?php echo form_security_field( 'topic_assessment_add' ) ?>
			<input type="hidden" name="expectation_id" value="<?php echo $f_expectation_id ?>" />
			<input type="hidden" name="basis" value="<?php echo string_attribute( topic_assessment_basis( $f_expectation_id ) ) ?>" />
			<div class="form-inline">
				<?php foreach( array( TOPIC_FINDING_ADEQUATE, TOPIC_FINDING_GAP ) as $t_finding ) { ?>
				<label class="inline" style="margin-right:16px;">
					<input type="radio" name="finding" value="<?php echo $t_finding ?>" required /> <?php echo topic_finding_label( $t_finding ) ?>
				</label>
				<?php } ?>
				<label for="assessment-milestone"><?php echo lang_get( 'topic_milestone' ) ?></label>
				<select id="assessment-milestone" name="milestone_id" class="input-sm">
					<?php print_topic_milestone_option_list( $t_milestones, $f_milestone_id ); ?>
				</select>
			</div>
			<div class="form-group">
				<label for="assessment-rationale"><?php echo lang_get( 'topic_rationale' ) ?></label>
				<textarea id="assessment-rationale" name="rationale" class="form-control" rows="3" maxlength="4000" required></textarea>
				<span class="small grey"><?php echo lang_get( 'topic_assessment_hint' ) ?></span>
			</div>
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'topic_record_assessment' ) ?></button>
		</form>
		<?php } else if( $t_enabled && !$t_can_assess ) { ?>
		<p class="grey"><?php echo lang_get( 'topic_cannot_assess' ) ?></p>
		<?php } ?>
	</div>
	<div class="widget-main no-padding">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th><?php echo lang_get( 'date_modified' ) ?></th>
			<th><?php echo lang_get( 'topic_assessor' ) ?></th>
			<th><?php echo lang_get( 'topic_milestone' ) ?></th>
			<th><?php echo lang_get( 'topic_finding' ) ?></th>
			<th><?php echo lang_get( 'topic_rationale' ) ?></th>
			<th><?php echo lang_get( 'topic_evidence_assessed' ) ?></th>
		</tr></thead>
		<tbody>
		<?php if( !$t_assessments ) { ?>
			<tr><td colspan="6"><?php echo lang_get( 'topic_no_assessments' ) ?></td></tr>
		<?php }
		foreach( $t_assessments as $t_assessment ) {
			$t_items = array();
			foreach( $t_assessment['evidence'] as $t_dwg_id => $t_item ) {
				if( !dwg_exists( (int)$t_dwg_id ) || !topic_can_see_dwg( (int)$t_dwg_id ) ) {
					$t_items[] = lang_get( 'topic_restricted_short' );
					continue;
				}
				$t_items[] = string_display_line( topic_document_label( array( 'id' => $t_dwg_id,
					'number' => (string)dwg_get_field( $t_dwg_id, 'number' ), 'title' => (string)dwg_get_field( $t_dwg_id, 'title' ) ) ) )
					. ' <code>' . string_display_line( topic_evidence_version_label( $t_item['version'] ) ) . '</code>'
					. ( $t_item['locator'] !== '' ? ' ' . string_display_line( $t_item['locator'] ) : '' );
			} ?>
			<tr>
				<td class="nowrap"><?php echo date( $t_date_format, (int)$t_assessment['date_assessed'] ) ?></td>
				<td><?php echo string_display_line( topic_user_name( (int)$t_assessment['user_id'] ) ) ?></td>
				<td><?php echo string_display_line( $t_milestones[(int)$t_assessment['milestone_id']]['name'] ?? '' ) ?></td>
				<td><?php echo topic_finding_label( (int)$t_assessment['finding'] )
					. ' <span class="grey small">(' . string_display_line( sprintf( lang_get( 'topic_revision_short' ), (int)$t_assessment['expectation_revision'] ) ) . ')</span>' ?></td>
				<td><?php echo string_display_links( $t_assessment['rationale'] ) ?></td>
				<td><?php echo implode( '<br />', $t_items ) ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2 collapsed" id="expectation-revisions">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-history', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_expectation_revisions' ) ?>
		</h4>
		<div class="widget-toolbar"><a data-action="collapse" href="#"><?php print_icon( 'fa-chevron-down', '1 ace-icon bigger-125' ); ?></a></div>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th><?php echo lang_get( 'topic_revision' ) ?></th>
			<th><?php echo lang_get( 'date_modified' ) ?></th>
			<th><?php echo lang_get( 'username' ) ?></th>
			<th><?php echo lang_get( 'topic_expectation' ) ?></th>
			<th><?php echo lang_get( 'topic_owner' ) ?></th>
			<th><?php echo lang_get( 'topic_due_from' ) ?></th>
			<th><?php echo lang_get( 'topic_required_maturity' ) ?></th>
		</tr></thead>
		<tbody>
		<?php foreach( topic_expectation_revisions( $f_expectation_id ) as $t_row ) { ?>
			<tr>
				<td><?php echo (int)$t_row['revision'] . ( (int)$t_row['enabled'] == 1 ? '' : ' (' . lang_get( 'topic_retired' ) . ')' ) ?></td>
				<td class="nowrap"><?php echo date( $t_date_format, (int)$t_row['date_changed'] ) ?></td>
				<td><?php echo string_display_line( topic_user_name( (int)$t_row['user_id'] ) ) ?></td>
				<td><?php echo string_display_line( $t_row['statement'] ) ?></td>
				<td><?php echo string_display_line( topic_user_name( (int)$t_row['owner_id'] ) ) ?></td>
				<td><?php echo string_display_line( $t_milestones[(int)$t_row['milestone_id']]['name'] ?? '' ) ?></td>
				<td><?php echo topic_maturity_label( (int)$t_row['maturity'] ) ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<?php print_link_button( 'topic_detail_page.php?project_id=' . $t_project_id . '&topic_id=' . (int)$t_topic['id']
		. '&milestone_id=' . (int)$f_milestone_id . '#expectations', lang_get( 'topic_back_to_topic' ) ); ?>
</div>

<?php
layout_page_end();
