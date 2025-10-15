<?php
# ----------------------------------------------------------------------
# MantisBT / Doctis Enum Consistency Checker
# ----------------------------------------------------------------------

//require_once( dirname( __FILE__ ) . '/../../core.php' );
//access_ensure_global_level( config_get( 'admin_site_threshold' ) );

if( !defined( 'CHECK_ENUM_CONSISTENCY_INC_ALLOW' ) ) {
	return;
}

# MantisBT Check API
require_once( 'check_api.php' );
require_api( 'config_api.php' );
require_api( 'plugin_api.php' );
require_api( 'constant_inc.php' );

check_print_section_header_row( 'Enums' );

echo '<h2>Enum–Constant Consistency Check</h2>';

# ----------------------------------------------------------------------
# Helper function
# ----------------------------------------------------------------------
function enum_check_table( $enum_name, $enum_value = null ) {
	if( $enum_value === null ) {
		$enum_value = config_get( $enum_name, '', ALL_USERS, ALL_PROJECTS );
	}

	if( empty( $enum_value ) ) {
		echo "<p><strong>$enum_name</strong>: <em>empty or undefined</em></p>";
		return;
	}

	$rows = [];
	$pairs = explode( ',', $enum_value );

	foreach( $pairs as $pair ) {
		list( $id, $label ) = array_map( 'trim', explode( ':', $pair, 2 ) );

		$const_name = strtoupper( str_replace( ' ', '_', $label ) );
		$const_defined = defined( $const_name );
		if ( !$const_defined ) {
			$const_defined = defined( $const_name . '_' );
			$const_value   = $const_defined ? constant( $const_name . '_' ) : '-';
		} else {
			$const_value   = $const_defined ? constant( $const_name ) : '-';
		}
		$match = $const_defined && (string)$const_value === (string)$id;

		$rows[] = [
			'id' => $id,
			'label' => $label,
			'const_name' => $const_name,
			'const_value' => $const_value,
			'match' => $match,
			'defined' => $const_defined,
		];
	}

	echo '<h3>' . string_display_line( $enum_name ) . '</h3>';
	echo '<table class="width100" cellspacing="1">';
	echo '<tr class="row-category">
			<td>Enum ID</td>
			<td>Label</td>
			<td>Constant</td>
			<td>Const Value</td>
			<td>Status</td>
		  </tr>';

	foreach( $rows as $r ) {
		$class = $r['match'] ? 'row-1' : 'row-2';
		$status = !$r['defined']
			? '<span style="color:red">Missing</span>'
			: ($r['match']
				? '<span style="color:green">OK</span>'
				: '<span style="color:orange">Mismatch</span>');

		echo "<tr class=\"$class\">";
		echo "<td>{$r['id']}</td>";
		echo "<td>{$r['label']}</td>";
		echo "<td>{$r['const_name']}</td>";
		echo "<td>{$r['const_value']}</td>";
		echo "<td>$status</td>";
		echo "</tr>";
	}

	echo '</table><br>';
}

# ----------------------------------------------------------------------
# Add your enum strings here
# ----------------------------------------------------------------------
$enum_sets = [
	'access_levels_enum_string',
#    'project_status_enum_string',
#    'project_view_state_enum_string',
#    'view_state_enum_string',
	'priority_enum_string',
	'severity_enum_string',
	'status_enum_string',
	'dwg_status_enum_string',
	'resolution_enum_string',
#    'projection_enum_string',
#    'change_class_enum_string',
#    'reproducibility_enum_string',
#    'custom_field_type_enum_string',
#    'sponsorship_enum_string',
#    'eta_enum_string',
];

foreach( $enum_sets as $enum_name ) {
	enum_check_table( $enum_name );
}
