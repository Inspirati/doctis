<?php
# Doctis — Documentation Topics of the current project
# (doc/topics/TOPICS_PLAN.md §4.3).
#
# Lists every Topic of the project's catalogue with its applicability, the
# documents tagged with it, its declared coverage and (phase 2) the state of
# its expectations at the selected milestone; the project's documentation
# readiness and profile authorisation; and the untagged documents. Project
# managers enable Topics and authorise the profile here.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_api.php' );
require_api( 'topic_expectation_api.php' );
require_api( 'topic_snapshot_api.php' );

auth_ensure_user_authenticated();
if( !topic_feature_enabled() ) {
	access_denied();
}

$f_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
$f_milestone_id = gpc_get_int( 'milestone_id', 0 );
if( $f_project_id != ALL_PROJECTS ) {
	project_ensure_exists( $f_project_id );
}
$t_can_manage = topic_can_manage_project( $f_project_id );
$t_enabled = topic_project_is_enabled( $f_project_id );
# Only people who may see this project's Topics, or enable them, learn
# anything about the project here.
if( $f_project_id != ALL_PROJECTS && !$t_can_manage && !topic_can_view_project( $f_project_id ) ) {
	access_denied();
}

layout_page_header( lang_get( 'topics_link' ) );
layout_page_begin( 'topic_page.php' );

$t_title = lang_get( 'topic_page_title' )
	. ( $f_project_id != ALL_PROJECTS ? ' — ' . project_get_name( $f_project_id ) : '' );
?>

<div class="col-md-12 col-xs-12">
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-tags', 'ace-icon' ); ?>
			<?php echo string_display_line( $t_title ) ?>
		</h4>
		<?php if( $t_enabled ) {
			$t_view = topic_coverage_view( $f_project_id, $f_milestone_id );
			$t_catalogue = $t_view['catalogue'];
			$t_milestone_id = $t_view['milestone_id'];
			$t_milestone_param = $t_milestone_id > 0 ? '&milestone_id=' . $t_milestone_id : ''; ?>
		<div class="widget-toolbar">
			<span class="white"><?php echo lang_get( 'topic_catalogue' ) ?>:
				<?php echo string_display_line( topic_catalogue_label( $t_catalogue ) ) ?>
				(<?php echo topic_catalogue_status_label( (int)$t_catalogue['status'] ) ?>)</span>
		</div>
		<?php } ?>
	</div>
	<div class="widget-body">
	<div class="widget-main">
<?php
if( $f_project_id == ALL_PROJECTS ) {
	echo '<p>' . lang_get( 'topic_select_project' ) . '</p>';
} else if( !$t_enabled ) {
	# ── Not enabled: offer enablement to project managers ──
	$t_row = topic_project_get( $f_project_id );
	if( !$t_can_manage ) {
		echo '<p>' . lang_get( 'topic_not_enabled' ) . '</p>';
	} else {
		$t_adoptable = array_filter( topic_catalogue_get_all(), function( $p_catalogue ) use ( $t_row ) {
			return (int)$p_catalogue['status'] != TOPIC_CATALOGUE_RETIRED
				|| ( $t_row !== null && (int)$t_row['catalogue_id'] == (int)$p_catalogue['id'] );
		} );
		echo '<p>' . lang_get( 'topic_enable_intro' ) . '</p>';
		if( $t_row !== null ) {
			echo '<p class="grey">' . lang_get( 'topic_previously_enabled' ) . '</p>';
		}
		if( !$t_adoptable ) {
			echo '<p>' . lang_get( 'topic_no_catalogue_to_adopt' );
			if( topic_can_manage_catalogues() ) {
				echo ' <a href="manage_topic_page.php">' . lang_get( 'manage_topics_link' ) . '</a>';
			}
			echo '</p>';
		} else { ?>
		<form method="post" action="topic_project_action.php" class="form-inline">
			<?php echo form_security_field( 'topic_project_action' ) ?>
			<input type="hidden" name="action" value="enable" />
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<label for="topic-catalogue"><?php echo lang_get( 'topic_catalogue' ) ?></label>
			<select id="topic-catalogue" name="catalogue_id" class="input-sm">
			<?php foreach( $t_adoptable as $t_catalogue ) { ?>
				<option value="<?php echo (int)$t_catalogue['id'] ?>"<?php
					echo $t_row !== null && (int)$t_row['catalogue_id'] == (int)$t_catalogue['id'] ? ' selected="selected"' : '' ?>><?php
					echo string_display_line( topic_catalogue_label( $t_catalogue ) )
						. ' (' . topic_catalogue_status_label( (int)$t_catalogue['status'] ) . ')' ?></option>
			<?php } ?>
			</select>
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'topic_enable' ) ?></button>
		</form>
<?php
		}
	}
} else {
	# ── Enabled: the Topics table ──
	if( (int)$t_catalogue['status'] == TOPIC_CATALOGUE_DRAFT ) {
		echo '<div class="alert alert-warning">' . lang_get( 'topic_catalogue_is_draft' ) . '</div>';
	}
	if( $t_can_manage ) {
		foreach( topic_catalogue_other_revisions( (int)$t_catalogue['id'] ) as $t_other ) {
			echo '<div class="alert alert-info">' . string_display_line( sprintf( lang_get( 'topic_other_revision' ),
				topic_catalogue_label( $t_other ), topic_catalogue_status_label( (int)$t_other['status'] ) ) )
				. ' <a href="topic_catalogue_migrate_page.php?project_id=' . (int)$f_project_id . '&amp;catalogue_id=' . (int)$t_other['id'] . '">'
				. lang_get( 'topic_review_change' ) . '</a></div>';
		}
	}
	echo '<p>' . lang_get( 'topic_declared_notice' ) . '</p>';

	$t_ac = $t_view['applicability_counts'];
	$t_sc = $t_view['counts'];
	echo '<p><strong>' . lang_get( 'topic_required' ) . ':</strong> '
		. topic_applicability_label( TOPIC_APPLICABLE ) . ' ' . $t_ac[TOPIC_APPLICABLE] . ' &middot; '
		. topic_applicability_label( TOPIC_NOT_APPLICABLE ) . ' ' . $t_ac[TOPIC_NOT_APPLICABLE] . ' &middot; '
		. topic_applicability_label( TOPIC_UNDECIDED ) . ' ' . $t_ac[TOPIC_UNDECIDED]
		. ' &nbsp; <strong>' . lang_get( 'topic_coverage' ) . ':</strong> '
		. topic_state_label( TOPIC_STATE_COMPLETE ) . ' ' . $t_sc[TOPIC_STATE_COMPLETE] . ' &middot; '
		. topic_state_label( TOPIC_STATE_PARTIAL ) . ' ' . $t_sc[TOPIC_STATE_PARTIAL] . ' &middot; '
		. topic_state_label( TOPIC_STATE_NONE ) . ' ' . $t_sc[TOPIC_STATE_NONE] . ' &middot; '
		. topic_state_label( TOPIC_STATE_NOT_APPLICABLE ) . ' ' . $t_sc[TOPIC_STATE_NOT_APPLICABLE]
		. ' &nbsp; <strong>' . lang_get( 'topic_untagged_documents' ) . ':</strong> ' . count( $t_view['untagged'] )
		. '</p>';
	if( $t_view['restricted'] ) {
		echo '<div class="alert alert-info">' . lang_get( 'topic_limited_view' ) . '</div>';
	}

	# Phase 2: milestone, readiness and expectation counts.
	$t_profile = $t_view['profile'];
	echo '<div class="well well-sm">';
	if( $t_view['milestones'] ) {
		print_topic_milestone_selector( 'topic_page.php', array( 'project_id' => $f_project_id ), $t_view['milestones'], $t_milestone_id );
		echo ' &nbsp; <strong>' . lang_get( 'topic_readiness' ) . ':</strong> ' . topic_exp_state_html( $t_view['readiness'] );
		$t_ec = $t_view['expectation_counts'];
		echo '<br /><strong>' . lang_get( 'topic_expectations' ) . ':</strong> ';
		$t_parts = array();
		foreach( $t_ec as $t_state => $t_count ) {
			$t_parts[] = topic_exp_state_label( $t_state ) . ' ' . $t_count;
		}
		echo implode( ' &middot; ', $t_parts );
	} else {
		echo lang_get( 'topic_no_milestones' );
	}
	echo '<br /><strong>' . lang_get( 'topic_profile' ) . ':</strong> ';
	if( $t_profile['current'] === null ) {
		echo lang_get( 'topic_profile_not_authorised' );
	} else {
		echo string_display_line( sprintf( lang_get( 'topic_profile_authorised' ), (int)$t_profile['current']['revision'],
			topic_user_name( (int)$t_profile['current']['user_id'] ),
			date( config_get( 'normal_date_format' ), (int)$t_profile['current']['date_authorised'] ) ) );
		if( $t_profile['changed'] ) {
			echo ' <span class="label label-warning">' . lang_get( 'topic_profile_changed' ) . '</span>';
		}
	}
	echo ' <a class="small" href="#profile">' . lang_get( 'topic_profile_details' ) . '</a>';
	if( $t_can_manage || $t_view['milestones'] ) {
		echo ' &middot; <a class="small" href="topic_milestone_page.php?project_id=' . (int)$f_project_id . '">' . lang_get( 'topic_milestones' ) . '</a>';
	}
	echo ' &middot; <a class="small" href="topic_activity_page.php?project_id=' . (int)$f_project_id . '">' . lang_get( 'topic_activity' ) . '</a>';
	echo '<br /><span class="small grey">' . lang_get( 'topic_readiness_notice' ) . '</span>';
	echo '</div>';

	echo '<div class="noprint">';
	print_link_button( 'topic_export.php?format=print&project_id=' . (int)$f_project_id . $t_milestone_param, lang_get( 'topic_export_print' ), 'btn-xs', true );
	echo ' ';
	print_link_button( 'topic_export.php?format=csv&project_id=' . (int)$f_project_id . $t_milestone_param, lang_get( 'topic_export_csv' ), 'btn-xs' );
	echo '</div>';
?>
	</div>
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed">
		<thead>
			<tr>
				<th><?php echo lang_get( 'topic_code' ) ?></th>
				<th><?php echo lang_get( 'topic_topic' ) ?></th>
				<th><?php echo lang_get( 'topic_required' ) ?></th>
				<th><?php echo lang_get( 'topic_documents' ) ?></th>
				<th><?php echo lang_get( 'topic_coverage' ) ?></th>
				<th><?php echo lang_get( 'topic_expectations' ) ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach( $t_view['topics'] as $t_topic_id => $t_topic ) {
			$t_detail = 'topic_detail_page.php?project_id=' . (int)$f_project_id . '&topic_id=' . (int)$t_topic_id . $t_milestone_param;
			$t_decision = $t_topic['decision']; ?>
			<tr>
				<td class="nowrap"><?php echo ( $t_topic['level'] ? '&nbsp;&nbsp;&raquo; ' : '' ) . string_display_line( $t_topic['code'] ) ?></td>
				<td>
					<a href="<?php echo string_attribute( $t_detail ) ?>" title="<?php echo string_attribute( $t_topic['description'] ) ?>"><?php
						echo string_display_line( $t_topic['title'] ) ?></a>
					<?php if( (int)$t_topic['enabled'] != 1 ) { echo ' <span class="grey">(' . lang_get( 'topic_retired' ) . ')</span>'; } ?>
				</td>
				<td>
					<?php echo topic_applicability_label( (int)$t_topic['applicability'] );
					if( $t_decision !== null && $t_decision['rationale'] !== '' ) {
						echo '<br /><span class="grey small">' . string_display_line( $t_decision['rationale'] ) . '</span>';
					}
					if( $t_can_manage ) {
						echo ' <a class="small" href="' . string_attribute( $t_detail ) . '#applicability">' . lang_get( 'topic_decide' ) . '</a>';
					} ?>
				</td>
				<td>
					<?php
					$t_lines = array();
					foreach( $t_topic['tags'] as $t_tag ) {
						$t_lines[] = topic_tag_html( $t_tag );
					}
					if( $t_topic['restricted'] ) {
						$t_lines[] = '<span class="grey">' . lang_get( 'topic_restricted_documents' ) . '</span>';
					}
					echo $t_lines ? implode( '<br />', $t_lines ) : '&mdash;';
					?>
				</td>
				<td class="nowrap"><?php echo topic_state_html( $t_topic['state'] ) ?></td>
				<td class="nowrap">
					<?php
					echo topic_exp_state_html( $t_topic['expectation_state'] );
					if( $t_topic['expectations'] ) {
						$t_satisfied = 0;
						$t_due = 0;
						foreach( $t_topic['expectations'] as $t_expectation ) {
							if( $t_expectation['state'] != TOPIC_EXP_NOT_DUE ) {
								$t_due++;
								$t_satisfied += $t_expectation['state'] == TOPIC_EXP_SATISFIED ? 1 : 0;
							}
						}
						echo '<br /><span class="small grey">' . string_display_line( sprintf( lang_get( 'topic_expectations_due_count' ),
							$t_satisfied, $t_due, count( $t_topic['expectations'] ) ) ) . '</span>';
					}
					?>
				</td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
<?php
}
?>
	</div>
	</div>
</div>

<?php
if( $t_enabled ) {
	# ── Untagged documents ──
	$t_catalogue_id = (int)$t_catalogue['id'];
?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-file-o', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_untagged_documents' ) ?> (<?php echo count( $t_view['untagged'] ) ?>)
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed table-striped">
		<?php if( !$t_view['untagged'] ) { ?>
		<tr><td><?php echo lang_get( 'topic_all_documents_tagged' ) ?></td></tr>
		<?php }
		foreach( $t_view['untagged'] as $t_dwg_id => $t_document ) { ?>
		<tr>
			<td>
				<?php echo string_get_dwg_view_title_link( $t_dwg_id, string_display_line( topic_document_label( $t_document ) ), false ) ?>
				<span class="grey">(<?php echo string_display_line( get_enum_element( 'dwg_status', (int)$t_document['status'] ) ) ?>)</span>
			</td>
			<td class="nowrap">
			<?php if( topic_can_tag_dwg( $t_dwg_id ) ) { ?>
				<form method="post" action="dwg_topic_add.php" class="form-inline">
					<?php echo form_security_field( 'dwg_topic_add' ) ?>
					<input type="hidden" name="dwg_id" value="<?php echo (int)$t_dwg_id ?>" />
					<input type="hidden" name="return" value="topics" />
					<select name="topic_id" class="input-sm" aria-label="<?php echo lang_get( 'topic_topic' ) ?>">
						<?php print_topic_option_list( $t_catalogue_id ); ?>
					</select>
					<?php print_topic_extent_select( 'extent' ); ?>
					<input type="text" name="locator" class="input-sm" size="16" maxlength="255"
						placeholder="<?php echo lang_get( 'topic_sections' ) ?>" aria-label="<?php echo lang_get( 'topic_sections' ) ?>" />
					<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'topic_tag' ) ?></button>
				</form>
			<?php } ?>
			</td>
		</tr>
		<?php } ?>
	</table>
	</div>
	</div>
	</div>
	</div>
</div>

<?php if( access_has_project_level( config_get( 'topic_tag_threshold', null, null, $f_project_id ), $f_project_id ) ) { ?>
<div class="col-md-12 col-xs-12" id="shared">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2 collapsed">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-share-alt', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_share_title' ) ?>
		</h4>
		<div class="widget-toolbar"><a data-action="collapse" href="#"><?php print_icon( 'fa-chevron-down', '1 ace-icon bigger-125' ); ?></a></div>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p class="small grey"><?php echo lang_get( 'topic_share_intro' ) ?></p>
		<form method="post" action="dwg_topic_add.php" class="form-inline">
			<?php echo form_security_field( 'dwg_topic_add' ) ?>
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<input type="hidden" name="return" value="topics" />
			<label for="topic-share-dwg"><?php echo lang_get( 'topic_share_document_id' ) ?></label>
			<input type="number" id="topic-share-dwg" name="dwg_id" class="input-sm" min="1" required style="width:8em" />
			<select name="topic_id" class="input-sm" aria-label="<?php echo lang_get( 'topic_topic' ) ?>">
				<?php print_topic_option_list( (int)$t_catalogue['id'] ); ?>
			</select>
			<?php print_topic_extent_select( 'extent' ); ?>
			<input type="text" name="locator" class="input-sm" size="16" maxlength="255"
				placeholder="<?php echo lang_get( 'topic_sections' ) ?>" aria-label="<?php echo lang_get( 'topic_sections' ) ?>" />
			<button type="submit" class="btn btn-primary btn-xs btn-white btn-round"><?php echo lang_get( 'topic_tag' ) ?></button>
		</form>
	</div>
	</div>
	</div>
</div>
<?php } ?>

<div class="col-md-12 col-xs-12" id="snapshots">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-camera', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_snapshots' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p class="small grey"><?php echo lang_get( 'topic_snapshots_intro' ) ?></p>
		<?php if( topic_can_snapshot( $f_project_id ) ) { ?>
		<form method="post" action="topic_snapshot_action.php" class="form-inline">
			<?php echo form_security_field( 'topic_snapshot_action' ) ?>
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<input type="hidden" name="milestone_id" value="<?php echo (int)$t_milestone_id ?>" />
			<label for="topic-snapshot-title"><?php echo lang_get( 'topic_snapshot_title' ) ?></label>
			<input type="text" id="topic-snapshot-title" name="title" class="input-sm" size="40" maxlength="128" required
				placeholder="<?php echo lang_get( 'topic_snapshot_title_placeholder' ) ?>" />
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo string_display_line( sprintf( lang_get( 'topic_snapshot_take' ),
				$t_view['milestones'][$t_milestone_id]['name'] ?? '—' ) ) ?></button>
		</form>
		<?php } ?>
	</div>
	<?php $t_snapshots = topic_snapshot_get_all( $f_project_id );
	if( $t_snapshots ) { ?>
	<div class="widget-main no-padding">
	<table class="table table-bordered table-condensed table-striped">
		<thead><tr>
			<th>#</th>
			<th><?php echo lang_get( 'topic_snapshot_title' ) ?></th>
			<th><?php echo lang_get( 'topic_milestone' ) ?></th>
			<th><?php echo lang_get( 'topic_readiness' ) ?></th>
			<th><?php echo lang_get( 'topic_profile' ) ?></th>
			<th><?php echo lang_get( 'topic_snapshot_taken' ) ?></th>
		</tr></thead>
		<tbody>
		<?php foreach( $t_snapshots as $t_row ) { ?>
			<tr>
				<td><a href="topic_snapshot_page.php?id=<?php echo (int)$t_row['id'] ?>">S<?php echo (int)$t_row['id'] ?></a></td>
				<td><a href="topic_snapshot_page.php?id=<?php echo (int)$t_row['id'] ?>"><?php echo string_display_line( $t_row['title'] ) ?></a></td>
				<td><?php echo string_display_line( $t_row['milestone_name'] ) ?></td>
				<td><?php echo topic_exp_state_html( $t_row['readiness'] ) ?></td>
				<td><?php echo (int)$t_row['profile_revision'] > 0 ? string_display_line( sprintf( lang_get( 'topic_revision_short' ), (int)$t_row['profile_revision'] ) ) : '&mdash;' ?></td>
				<td class="nowrap"><?php echo string_display_line( date( config_get( 'normal_date_format' ), (int)$t_row['date_created'] ) . ' — ' . topic_user_name( (int)$t_row['user_id'] ) ) ?></td>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	</div>
	<?php } ?>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12" id="profile">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-check-circle', 'ace-icon' ); ?>
			<?php echo lang_get( 'topic_profile' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<p><?php echo lang_get( 'topic_profile_intro' ) ?></p>
		<?php
		$t_profiles = topic_profile_history( $f_project_id );
		if( $t_profiles ) { ?>
		<table class="table table-bordered table-condensed table-striped" style="max-width:900px;">
			<thead><tr>
				<th><?php echo lang_get( 'topic_revision' ) ?></th>
				<th><?php echo lang_get( 'date_modified' ) ?></th>
				<th><?php echo lang_get( 'username' ) ?></th>
				<th><?php echo lang_get( 'topic_note' ) ?></th>
			</tr></thead>
			<tbody>
			<?php foreach( $t_profiles as $t_row ) { ?>
				<tr>
					<td><?php echo (int)$t_row['revision'] ?></td>
					<td class="nowrap"><?php echo date( config_get( 'normal_date_format' ), (int)$t_row['date_authorised'] ) ?></td>
					<td><?php echo string_display_line( topic_user_name( (int)$t_row['user_id'] ) ) ?></td>
					<td><?php echo string_display_line( $t_row['note'] ) ?></td>
				</tr>
			<?php } ?>
			</tbody>
		</table>
		<?php }
		if( $t_can_manage ) {
			$t_blockers = topic_profile_blockers( $f_project_id );
			if( $t_blockers ) {
				echo '<p><strong>' . lang_get( 'topic_profile_blocked' ) . '</strong></p><ul>';
				foreach( $t_blockers as $t_blocker ) {
					echo '<li>' . string_display_line( $t_blocker ) . '</li>';
				}
				echo '</ul>';
			} else if( $t_profile['changed'] ) { ?>
		<form method="post" action="topic_profile_authorise.php" class="form-inline">
			<?php echo form_security_field( 'topic_profile_authorise' ) ?>
			<input type="hidden" name="project_id" value="<?php echo (int)$f_project_id ?>" />
			<input type="hidden" name="fingerprint" value="<?php echo string_attribute( topic_profile_fingerprint( $f_project_id ) ) ?>" />
			<label for="topic-profile-note"><?php echo lang_get( 'topic_note' ) ?></label>
			<input type="text" id="topic-profile-note" name="note" class="input-sm" size="50" maxlength="255" />
			<button type="submit" class="btn btn-primary btn-sm btn-white btn-round"><?php echo lang_get( 'topic_profile_authorise' ) ?></button>
		</form>
		<?php } else {
				echo '<p class="grey">' . lang_get( 'topic_profile_up_to_date' ) . '</p>';
			}
		} ?>
	</div>
	</div>
	</div>
</div>

<?php if( $t_can_manage ) { ?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<?php print_form_button( 'topic_project_action.php', lang_get( 'topic_disable' ),
		array( 'action' => 'disable', 'project_id' => $f_project_id ), null, 'btn btn-default btn-sm btn-white btn-round' ); ?>
	<span class="grey"><?php echo lang_get( 'topic_disable_hint' ) ?></span>
</div>
<?php }
}

layout_page_end();
