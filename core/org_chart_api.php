<?php
# Doctis organisational chart model helpers.

/**
 * Build a cycle-safe organisational chart model from user rows.
 *
 * @param array $p_users User rows, keyed or unkeyed.
 * @return array Nodes, child relationships, roots, external-manager groups.
 */
function org_chart_build( array $p_users ) {
	$t_nodes = array();
	foreach( $p_users as $t_user ) {
		$t_id = (int)$t_user['id'];
		$t_user['id'] = $t_id;
		$t_user['reports_to'] = (int)( $t_user['reports_to'] ?? 0 );
		$t_user['alternative'] = trim( (string)( $t_user['alternative'] ?? '' ) );
		$t_nodes[$t_id] = $t_user;
	}

	$t_children = array();
	$t_roots = array();
	$t_external = array();
	foreach( $t_nodes as $t_id => $t_user ) {
		$t_manager_id = $t_user['reports_to'];
		if( $t_manager_id !== 0 && $t_manager_id !== $t_id && isset( $t_nodes[$t_manager_id] ) ) {
			$t_children[$t_manager_id][] = $t_id;
		} elseif( $t_user['alternative'] !== '' ) {
			$t_external[$t_user['alternative']][] = $t_id;
		} else {
			$t_roots[] = $t_id;
		}
	}

	$t_sort_ids = function( &$p_ids ) use ( $t_nodes ) {
		usort( $p_ids, function( $p_left, $p_right ) use ( $t_nodes ) {
			$t_left = $t_nodes[$p_left]['realname'] ?: $t_nodes[$p_left]['username'];
			$t_right = $t_nodes[$p_right]['realname'] ?: $t_nodes[$p_right]['username'];
			return strcasecmp( $t_left, $t_right );
		} );
	};
	$t_sort_ids( $t_roots );
	foreach( $t_children as &$t_child_ids ) {
		$t_sort_ids( $t_child_ids );
	}
	unset( $t_child_ids );
	ksort( $t_external, SORT_NATURAL | SORT_FLAG_CASE );

	return array(
		'nodes' => $t_nodes,
		'children' => $t_children,
		'roots' => $t_roots,
		'external' => $t_external,
	);
}

/**
 * Build a vector chart for PDF export using the same model as the HTML view.
 *
 * The page grows to fit the whole diagram, rather than clipping a wide tree.
 * Graphviz also handles disconnected components and legacy reporting cycles.
 *
 * @param array $p_chart Organisation chart model.
 * @return Digraph Export graph.
 */
function org_chart_create_graph( array $p_chart ) {
	require_api( 'graphviz_api.php' );

	$t_graph = new Digraph( 'organisation', array(
		'rankdir' => 'TB',
		'splines' => 'ortho',
		'ordering' => 'out',
		'nodesep' => 0.25,
		'ranksep' => 0.5,
		'pad' => 0.25,
		'bgcolor' => 'white',
		'fontname' => 'Arial',
		'fontsize' => 18,
		'label' => lang_get( 'org_chart_title' ),
		'labelloc' => 't',
	) );
	$t_graph->set_default_node_attr( array(
		'shape' => 'box',
		'style' => 'rounded,filled',
		'fillcolor' => 'white',
		'color' => '#9eb6ce',
		'fontname' => 'Arial',
		'fontsize' => 12,
		'margin' => '0.15,0.1',
	) );
	$t_graph->set_default_edge_attr( array(
		'color' => '#9eb6ce',
		'arrowhead' => 'none',
	) );

	foreach( $p_chart['nodes'] as $t_id => $t_user ) {
		$t_lines = array( $t_user['realname'] ?: $t_user['username'] );
		if( !is_blank( $t_user['position_title'] ?? '' ) ) {
			$t_lines[] = $t_user['position_title'];
		}
		$t_meta = implode( ' · ', array_filter( array( $t_user['department'] ?? '', $t_user['company'] ?? '' ) ) );
		if( !is_blank( $t_meta ) ) {
			$t_lines[] = $t_meta;
		}
		$t_graph->add_node( 'user_' . $t_id, array( 'label' => org_chart_graph_label( $t_lines ) ) );
	}
	foreach( $p_chart['children'] as $t_manager_id => $t_child_ids ) {
		foreach( $t_child_ids as $t_child_id ) {
			$t_graph->add_edge( 'user_' . $t_manager_id, 'user_' . $t_child_id );
		}
	}
	$t_external_id = 0;
	foreach( $p_chart['external'] as $t_name => $t_user_ids ) {
		$t_node = 'external_' . $t_external_id++;
		$t_graph->add_node( $t_node, array(
			'label' => org_chart_graph_label( array( $t_name, lang_get( 'org_chart_external' ) ) ),
			'style' => 'rounded,filled,dashed',
			'fillcolor' => '#f6f6f6',
		) );
		foreach( $t_user_ids as $t_user_id ) {
			$t_graph->add_edge( $t_node, 'user_' . $t_user_id );
		}
	}
	if( empty( $p_chart['nodes'] ) ) {
		$t_graph->add_node( 'empty', array( 'label' => org_chart_graph_label( array( lang_get( 'org_chart_empty' ) ) ) ) );
	}
	return $t_graph;
}

/**
 * Escape staff-supplied text for Graphviz's HTML-like labels.
 *
 * @param array $p_lines Name followed by optional position and department.
 * @return string Label with a bold name and one row per detail.
 */
function org_chart_graph_label( array $p_lines ) {
	$t_label = '<TABLE BORDER="0" CELLBORDER="0" CELLSPACING="0">';
	foreach( array_values( $p_lines ) as $t_index => $t_line ) {
		$t_text = htmlspecialchars( $t_line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
		$t_label .= '<TR><TD>' . ( $t_index === 0 ? '<B>' . $t_text . '</B>' : $t_text ) . '</TD></TR>';
	}
	return $t_label . '</TABLE>';
}
