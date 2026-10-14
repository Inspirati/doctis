<?php
# Doctis — A frozen Documentation Topics coverage snapshot (TOP-FR-022).
# Rendered from the stored record; the reader's current permissions decide
# which documents are named.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_snapshot_api.php' );

auth_ensure_user_authenticated();

$f_snapshot_id = gpc_get_int( 'id' );
$t_snapshot = topic_snapshot_get( $f_snapshot_id );
$t_project_id = (int)$t_snapshot['project_id'];
if( !topic_can_view_project( $t_project_id ) ) {
	access_denied();
}
$t_content = is_array( $t_snapshot['content'] ) ? $t_snapshot['content'] : array( 'topics' => array() );
$t_topics = topic_snapshot_reader_topics( $t_content );
$t_restricted = in_array( true, array_column( $t_topics, 'restricted' ), true );
$t_date_format = config_get( 'normal_date_format' );
$t_profile = $t_content['profile'] ?? array( 'revision' => 0 );

layout_page_header( 'S' . $f_snapshot_id . ' ' . $t_snapshot['title'] . ' — ' . lang_get( 'topic_snapshot' ) );
layout_page_begin( 'topic_page.php' );
?>

<div class="col-md-12 col-xs-12">
	<div class="widget-box widget-color-orange">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-camera', 'ace-icon' ); ?>
			<?php echo string_display_line( lang_get( 'topic_snapshot' ) . ' S' . $f_snapshot_id . ': ' . $t_snapshot['title']
				. ' — ' . project_get_name( $t_project_id ) ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main">
		<div class="alert alert-warning"><?php echo lang_get( 'topic_snapshot_banner' ) ?></div>
		<?php if( !$t_snapshot['intact'] ) { ?>
		<div class="alert alert-danger"><?php echo lang_get( 'topic_snapshot_tampered' ) ?></div>
		<?php } ?>
		<?php if( $t_restricted ) { ?>
		<div class="alert alert-info"><?php echo lang_get( 'topic_snapshot_limited' ) ?></div>
		<?php } ?>
		<table class="table table-bordered table-condensed" style="max-width:900px;">
			<tr><th class="category width-25"><?php echo lang_get( 'topic_snapshot_taken' ) ?></th>
				<td><?php echo string_display_line( date( $t_date_format, (int)$t_snapshot['date_created'] ) . ' — ' . topic_user_name( (int)$t_snapshot['user_id'] ) ) ?></td></tr>
			<tr><th class="category"><?php echo lang_get( 'topic_catalogue' ) ?></th>
				<td><?php echo string_display_line( ( $t_content['catalogue']['label'] ?? $t_snapshot['catalogue_label'] ) . ' (' . ( $t_content['catalogue']['status'] ?? '' ) . ')' ) ?></td></tr>
			<tr><th class="category"><?php echo lang_get( 'topic_milestone' ) ?></th>
				<td><?php echo string_display_line( $t_snapshot['milestone_name'] !== '' ? $t_snapshot['milestone_name'] : '—' ) ?></td></tr>
			<tr><th class="category"><?php echo lang_get( 'topic_readiness' ) ?></th>
				<td><?php echo topic_exp_state_html( $t_snapshot['readiness'] ) ?></td></tr>
			<tr><th class="category"><?php echo lang_get( 'topic_profile' ) ?></th>
				<td><?php echo $t_profile['revision'] > 0
					? string_display_line( sprintf( lang_get( 'topic_profile_authorised' ), $t_profile['revision'], $t_profile['authorised_by'],
						date( $t_date_format, (int)$t_profile['authorised_at'] ) ) )
						. ( !empty( $t_profile['changed'] ) ? ' <span class="label label-warning">' . lang_get( 'topic_profile_changed' ) . '</span>' : '' )
					: lang_get( 'topic_profile_not_authorised' ) ?></td></tr>
			<tr><th class="category"><?php echo lang_get( 'topic_snapshot_integrity' ) ?></th>
				<td><?php echo lang_get( $t_snapshot['intact'] ? 'topic_snapshot_intact' : 'topic_snapshot_not_intact' ) ?>
					<code class="small"><?php echo string_display_line( substr( $t_snapshot['data_hash'], 0, 16 ) ) ?>…</code></td></tr>
		</table>
		<div class="noprint">
			<?php print_link_button( 'topic_export.php?format=print&snapshot_id=' . $f_snapshot_id, lang_get( 'topic_export_print' ), 'btn-xs', true ); ?>
			<?php print_link_button( 'topic_export.php?format=csv&snapshot_id=' . $f_snapshot_id, lang_get( 'topic_export_csv' ), 'btn-xs' ); ?>
		</div>
	</div>
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table class="table table-bordered table-condensed">
		<thead><tr>
			<th><?php echo lang_get( 'topic_code' ) ?></th>
			<th><?php echo lang_get( 'topic_topic' ) ?></th>
			<th><?php echo lang_get( 'topic_required' ) ?></th>
			<th><?php echo lang_get( 'topic_documents' ) ?></th>
			<th><?php echo lang_get( 'topic_coverage' ) ?></th>
			<th><?php echo lang_get( 'topic_expectations' ) ?></th>
		</tr></thead>
		<tbody>
		<?php foreach( $t_topics as $t_topic ) { ?>
			<tr>
				<td class="nowrap"><?php echo ( $t_topic['level'] ? '&nbsp;&nbsp;&raquo; ' : '' ) . string_display_line( $t_topic['code'] ) ?></td>
				<td><?php echo string_display_line( $t_topic['title'] ) ?></td>
				<td><?php echo string_display_line( $t_topic['applicability_label'] );
					if( $t_topic['rationale'] !== '' ) { echo '<br /><span class="grey small">' . string_display_line( $t_topic['rationale'] ) . '</span>'; } ?></td>
				<td><?php
					$t_lines = array();
					$t_hidden = false;
					foreach( $t_topic['tags'] as $t_tag ) {
						if( !$t_tag['visible'] ) {
							$t_hidden = true;
							continue;
						}
						$t_line = string_display_line( $t_tag['text'] ) . ' <span class="grey">(' . string_display_line( $t_tag['status_label'] ) . ')</span> &mdash; '
							. string_display_line( $t_tag['extent_label'] ) . ( $t_tag['locator'] !== '' ? ': ' . string_display_line( $t_tag['locator'] ) : '' );
						$t_lines[] = $t_tag['inactive'] ? '<del>' . $t_line . '</del>' : $t_line;
					}
					if( $t_hidden ) {
						$t_lines[] = '<span class="grey">' . lang_get( 'topic_restricted_documents' ) . '</span>';
					}
					echo $t_lines ? implode( '<br />', $t_lines ) : '&mdash;'; ?></td>
				<td class="nowrap"><?php echo topic_state_html( $t_topic['declared_state'] ) ?></td>
				<td class="nowrap"><?php echo topic_exp_state_html( $t_topic['expectation_state'] ) ?></td>
			</tr>
			<?php foreach( $t_topic['expectations'] as $t_expectation ) { ?>
			<tr class="small">
				<td></td>
				<td colspan="3">
					<strong><?php echo string_display_line( $t_expectation['ref'] ) ?></strong>
					<?php echo string_display_line( $t_expectation['statement'] ) ?>
					<span class="grey">(<?php echo string_display_line( $t_expectation['owner'] . '; ' . lang_get( 'topic_due_from' ) . ' '
						. $t_expectation['due'] . '; ' . $t_expectation['maturity_label'] . '; ' . sprintf( lang_get( 'topic_revision_short' ), $t_expectation['revision'] ) ) ?>)</span>
					<?php
					$t_items = array();
					$t_hidden = false;
					foreach( $t_expectation['evidence'] as $t_item ) {
						if( !$t_item['visible'] ) {
							$t_hidden = true;
							continue;
						}
						$t_items[] = string_display_line( $t_item['text'] ) . ' <code>' . string_display_line( $t_item['version_label'] ) . '</code>'
							. ( $t_item['locator'] !== '' ? ' ' . string_display_line( $t_item['locator'] ) : '' );
					}
					if( $t_hidden ) {
						$t_items[] = lang_get( 'topic_restricted_short' );
					}
					if( $t_items ) {
						echo '<br />' . lang_get( 'topic_evidence' ) . ': ' . implode( '; ', $t_items );
					}
					if( $t_expectation['assessment'] !== null ) {
						$t_a = $t_expectation['assessment'];
						echo '<br />' . string_display_line( lang_get( 'topic_latest_assessment' ) . ': ' . $t_a['finding_label'] . ', '
							. date( config_get( 'short_date_format' ), (int)$t_a['date'] ) . ', ' . $t_a['assessor'] . ' — ' . $t_a['rationale'] );
					} ?>
				</td>
				<td colspan="2"><?php echo topic_exp_state_with_reasons_html( $t_expectation['state'], $t_expectation['reasons'] ) ?></td>
			</tr>
			<?php } ?>
		<?php } ?>
		</tbody>
	</table>
	</div>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<?php print_link_button( 'topic_page.php?project_id=' . $t_project_id . '#snapshots', lang_get( 'topic_back_to_topics' ) ); ?>
</div>

<?php
layout_page_end();
