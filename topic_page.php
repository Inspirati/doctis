<?php
# Doctis — Documentation Topics of the current project
# (doc/topics/TOPICS_PLAN.md §4.3).
#
# Lists every Topic of the project's catalogue with its applicability, the
# documents tagged with it and its declared coverage, and the project's
# untagged documents. Project managers enable Topics here.

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

auth_ensure_user_authenticated();
if( !topic_feature_enabled() ) {
	access_denied();
}

$f_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
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
			$t_view = topic_project_view( $f_project_id );
			$t_catalogue = $t_view['catalogue']; ?>
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
			</tr>
		</thead>
		<tbody>
		<?php foreach( $t_view['topics'] as $t_topic_id => $t_topic ) {
			$t_detail = 'topic_detail_page.php?project_id=' . (int)$f_project_id . '&topic_id=' . (int)$t_topic_id;
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
