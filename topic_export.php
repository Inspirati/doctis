<?php
# Doctis — Export a project's Documentation Topics as CSV (format=csv) or as
# a printable page (format=print): the live view (project_id, milestone_id),
# labelled as live, or a frozen snapshot (snapshot_id), labelled as such.
# Same data and permissions as the pages (STD-IT-001 TOP-FR-023).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'csv_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'topic_api.php' );
require_api( 'topic_expectation_api.php' );
require_api( 'user_api.php' );

auth_ensure_user_authenticated();

$f_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
$f_format = gpc_get_string( 'format', 'print' ) == 'csv' ? 'csv' : 'print';
$f_milestone_id = gpc_get_int( 'milestone_id', 0 );
$f_snapshot_id = gpc_get_int( 'snapshot_id', 0 );
$t_date_format = config_get( 'normal_date_format' );

if( $f_snapshot_id > 0 ) {
	# A frozen snapshot, shown with the reader's current permissions.
	require_api( 'topic_snapshot_api.php' );
	$t_snapshot = topic_snapshot_get( $f_snapshot_id );
	$f_project_id = (int)$t_snapshot['project_id'];
	if( !topic_can_view_project( $f_project_id ) ) {
		access_denied();
	}
	$t_content = is_array( $t_snapshot['content'] ) ? $t_snapshot['content'] : array( 'topics' => array() );
	$t_snapshot_rows = topic_snapshot_export_rows( $t_content );
	$t_rows = $t_snapshot_rows['topics'];
	$t_expectation_rows = $t_snapshot_rows['expectations'];
	$t_milestone_text = $t_snapshot['milestone_name'] !== ''
		? $t_snapshot['milestone_name'] . ' — ' . lang_get( 'topic_readiness' ) . ': ' . topic_exp_state_label( $t_snapshot['readiness'] ) : '';
	$t_profile = $t_content['profile'] ?? array( 'revision' => 0 );
	$t_profile_text = $t_profile['revision'] > 0
		? sprintf( lang_get( 'topic_profile_authorised' ), $t_profile['revision'], $t_profile['authorised_by'], date( $t_date_format, (int)$t_profile['authorised_at'] ) )
			. ( !empty( $t_profile['changed'] ) ? ' ' . lang_get( 'topic_profile_changed' ) . '.' : '' )
		: lang_get( 'topic_profile_not_authorised' );
	$t_catalogue_text = ( $t_content['catalogue']['label'] ?? $t_snapshot['catalogue_label'] ) . ' (' . ( $t_content['catalogue']['status'] ?? '' ) . ')';
	$t_restricted = in_array( lang_get( 'topic_restricted_short' ), array_column( $t_rows, 'document' ), true )
		|| strpos( implode( ' ', array_column( $t_expectation_rows, 'evidence' ) ), lang_get( 'topic_restricted_short' ) ) !== false;
	$t_view_text = sprintf( lang_get( 'topic_export_snapshot' ), $f_snapshot_id, $t_snapshot['title'],
		date( $t_date_format, (int)$t_snapshot['date_created'] ), topic_user_name( (int)$t_snapshot['user_id'] ) )
		. ( $t_snapshot['intact'] ? '' : ' ' . lang_get( 'topic_snapshot_not_intact' ) );
	$t_file_suffix = '-snapshot-S' . $f_snapshot_id;
} else {
	project_ensure_exists( $f_project_id );
	if( !topic_can_view_project( $f_project_id ) ) {
		access_denied();
	}
	$t_view = topic_coverage_view( $f_project_id, $f_milestone_id );
	$t_rows = topic_export_rows( $t_view );
	$t_expectation_rows = topic_export_expectation_rows( $t_view );
	$t_milestone_text = $t_view['milestones']
		? ( $t_view['milestones'][$t_view['milestone_id']]['name'] ?? '' ) . ' — ' . lang_get( 'topic_readiness' ) . ': ' . topic_exp_state_label( $t_view['readiness'] )
		: '';
	$t_profile = $t_view['profile'];
	$t_profile_text = $t_profile['current'] === null ? lang_get( 'topic_profile_not_authorised' )
		: sprintf( lang_get( 'topic_profile_authorised' ), (int)$t_profile['current']['revision'],
			topic_user_name( (int)$t_profile['current']['user_id'] ),
			date( $t_date_format, (int)$t_profile['current']['date_authorised'] ) )
			. ( $t_profile['changed'] ? ' ' . lang_get( 'topic_profile_changed' ) . '.' : '' );
	$t_catalogue = $t_view['catalogue'];
	$t_catalogue_text = topic_catalogue_label( $t_catalogue ) . ' (' . topic_catalogue_status_label( (int)$t_catalogue['status'] ) . ')';
	$t_restricted = $t_view['restricted'];
	$t_view_text = lang_get( 'topic_export_live' );
	$t_file_suffix = '-topics-' . date( 'Ymd' );
}
if( $t_restricted ) {
	$t_view_text .= ' ' . lang_get( 'topic_export_limited' );
}
$t_expectation_headings = array(
	'code' => lang_get( 'topic_code' ), 'ref' => '#', 'expectation' => lang_get( 'topic_expectation' ),
	'owner' => lang_get( 'topic_owner' ), 'due' => lang_get( 'topic_due_from' ),
	'maturity' => lang_get( 'topic_required_maturity' ), 'state' => lang_get( 'topic_state' ),
	'reasons' => lang_get( 'topic_reasons' ), 'evidence' => lang_get( 'topic_evidence' ),
	'assessment' => lang_get( 'topic_latest_assessment' ),
);
$t_project_name = project_get_name( $f_project_id );
$t_generated = date( $t_date_format ) . ' — ' . user_get_name( auth_get_current_user_id() );
$t_headings = array(
	'code' => lang_get( 'topic_code' ), 'topic' => lang_get( 'topic_topic' ), 'parent' => lang_get( 'topic_parent' ),
	'required' => lang_get( 'topic_required' ), 'rationale' => lang_get( 'topic_rationale' ),
	'coverage' => lang_get( 'topic_coverage' ), 'document' => lang_get( 'topic_document' ),
	'title' => lang_get( 'topic_title' ), 'status' => lang_get( 'status' ),
	'extent' => lang_get( 'topic_extent' ), 'sections' => lang_get( 'topic_sections' ),
);

if( $f_format == 'csv' ) {
	$t_sep = csv_get_separator();
	$t_nl = csv_get_newline();
	$t_line = function( array $p_values ) use ( $t_sep, $t_nl ) {
		echo implode( $t_sep, array_map( 'csv_escape_string', $p_values ) ) . $t_nl;
	};
	csv_start( $t_project_name . $t_file_suffix . '.csv' );
	$t_line( array( lang_get( 'topic_page_title' ), $t_project_name ) );
	$t_line( array( lang_get( 'topic_catalogue' ), $t_catalogue_text ) );
	$t_line( array( lang_get( 'topic_export_generated' ), $t_generated ) );
	if( $t_milestone_text !== '' ) {
		$t_line( array( lang_get( 'topic_milestone' ), $t_milestone_text ) );
	}
	$t_line( array( lang_get( 'topic_profile' ), $t_profile_text ) );
	$t_line( array( lang_get( 'topic_export_view' ), $t_view_text ) );
	$t_line( array( lang_get( 'topic_declared_notice' ) ) );
	echo $t_nl;
	$t_line( array_values( $t_headings ) );
	foreach( $t_rows as $t_row ) {
		$t_values = array();
		foreach( array_keys( $t_headings ) as $t_key ) {
			$t_values[] = (string)$t_row[$t_key];
		}
		if( $t_row['inactive'] ) {
			$t_values[array_search( 'extent', array_keys( $t_headings ) )] .= ' (' . lang_get( 'topic_not_counted' ) . ')';
		}
		$t_line( $t_values );
	}
	if( $t_expectation_rows ) {
		echo $t_nl;
		$t_line( array_values( $t_expectation_headings ) );
		foreach( $t_expectation_rows as $t_row ) {
			$t_line( array_map( 'strval', array_values( array_intersect_key( array_merge( $t_expectation_headings, $t_row ), $t_expectation_headings ) ) ) );
		}
	}
	exit;
}

# Printable page: no sidebar or forms.
layout_page_header( lang_get( 'topic_page_title' ) . ' — ' . $t_project_name );
?>
<div class="container-fluid">
	<h3><?php echo string_display_line( lang_get( 'topic_page_title' ) . ' — ' . $t_project_name ) ?></h3>
	<table class="table table-bordered table-condensed" style="max-width:800px;">
		<tr><th class="category"><?php echo lang_get( 'topic_catalogue' ) ?></th><td><?php echo string_display_line( $t_catalogue_text ) ?></td></tr>
		<tr><th class="category"><?php echo lang_get( 'topic_export_generated' ) ?></th><td><?php echo string_display_line( $t_generated ) ?></td></tr>
		<?php if( $t_milestone_text !== '' ) { ?>
		<tr><th class="category"><?php echo lang_get( 'topic_milestone' ) ?></th><td><?php echo string_display_line( $t_milestone_text ) ?></td></tr>
		<?php } ?>
		<tr><th class="category"><?php echo lang_get( 'topic_profile' ) ?></th><td><?php echo string_display_line( $t_profile_text ) ?></td></tr>
		<tr><th class="category"><?php echo lang_get( 'topic_export_view' ) ?></th><td><?php
			echo string_display_line( $t_view_text ) ?></td></tr>
	</table>
	<p><?php echo lang_get( 'topic_declared_notice' ) ?></p>
	<table class="table table-bordered table-condensed">
		<thead><tr>
		<?php foreach( $t_headings as $t_heading ) { echo '<th>' . $t_heading . '</th>'; } ?>
		</tr></thead>
		<tbody>
		<?php foreach( $t_rows as $t_row ) { ?>
			<tr>
			<?php foreach( array_keys( $t_headings ) as $t_key ) {
				$t_cell = string_display_line( (string)$t_row[$t_key] );
				if( $t_row['inactive'] && in_array( $t_key, array( 'document', 'title', 'status', 'extent', 'sections' ) ) ) {
					$t_cell = '<del>' . $t_cell . '</del>';
				}
				echo '<td>' . $t_cell . '</td>';
			} ?>
			</tr>
		<?php } ?>
		</tbody>
	</table>
	<?php if( $t_expectation_rows ) { ?>
	<h4><?php echo lang_get( 'topic_expectations' ) ?></h4>
	<table class="table table-bordered table-condensed">
		<thead><tr>
		<?php foreach( $t_expectation_headings as $t_heading ) { echo '<th>' . $t_heading . '</th>'; } ?>
		</tr></thead>
		<tbody>
		<?php foreach( $t_expectation_rows as $t_row ) {
			echo '<tr>';
			foreach( array_keys( $t_expectation_headings ) as $t_key ) {
				echo '<td>' . string_display_line( (string)$t_row[$t_key] ) . '</td>';
			}
			echo '</tr>';
		} ?>
		</tbody>
	</table>
	<?php } ?>
	<p class="noprint"><a href="<?php echo $f_snapshot_id > 0 ? 'topic_snapshot_page.php?id=' . $f_snapshot_id : 'topic_page.php?project_id=' . (int)$f_project_id ?>"><?php echo lang_get( 'topic_back_to_topics' ) ?></a></p>
</div>
<?php
layout_body_javascript();
html_body_end();
html_end();
