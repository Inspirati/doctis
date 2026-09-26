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
