<?php
# Doctis - Document Issue Tracking System
#
# Document metadata cut-over: remove lifecycle metadata from Markdown
# document content (doc/publish/METADATA_CUTOVER.md).
#
# The git store is the source of document content and Doctis the source of
# document status.  Lifecycle metadata (status, revision, owner,
# classification, approver, effective date, review period) written into the
# documents themselves goes stale and contradicts Doctis, so it is removed:
#   - YAML frontmatter keys (title, status, revision, ...).  Other keys stay,
#     doc_id among them; a block left empty is removed.
#   - "**Label:** value" lines under the first heading.  Only the header
#     region is touched: the lines directly under the H1, up to the first line
#     that is not a label line, a blank line or an HTML comment.  Several
#     labels on one line, separated by " | ", are removed one by one.  A
#     horizontal rule that only separated the removed lines from the body
#     goes with them.
# The functions are pure: they take and return document text.

/** Frontmatter keys removed by default. */
const DWG_METADATA_KEYS = array(
	'title', 'status', 'revision', 'owner', 'approver', 'effective_date', 'review_period', 'classification',
);

/** Header labels removed by default (compared case-insensitively). */
const DWG_METADATA_LABELS = array(
	'Document ID', 'Revision', 'Status', 'Owner', 'Classification', 'Approver', 'Effective Date', 'Review Period',
);

/**
 * Remove keys from a leading YAML frontmatter block.  A key's indented or
 * list continuation lines go with it.  The block is removed when only blank
 * lines and comments remain.
 *
 * @param string $p_content
 * @param array  $p_keys    Keys to remove.
 * @param array  $p_removed Receives the removed keys.
 * @return string
 */
function dwg_metadata_strip_frontmatter( string $p_content, array $p_keys, array &$p_removed = array() ): string {
	if( !preg_match( '/\A---\r?\n(.*?)\r?\n(?:---|\.\.\.)[ \t]*(?:\r?\n|\z)/s', $p_content, $t_match ) ) {
		return $p_content;
	}
	$t_keys  = array_map( 'strtolower', $p_keys );
	$t_kept  = array();
	$t_skip  = false;
	$t_value = false;
	foreach( preg_split( '/\r?\n/', $t_match[1] ) as $t_line ) {
		if( preg_match( '/^([A-Za-z0-9_-]+)\s*:/', $t_line, $t_key ) ) {
			$t_skip = in_array( strtolower( $t_key[1] ), $t_keys, true );
			if( $t_skip ) {
				$p_removed[] = $t_key[1];
				continue;
			}
			$t_value = true;
		} else if( $t_skip && preg_match( '/^(\s+\S|-\s)/', $t_line ) ) {
			continue;   # continuation of a removed key
		} else {
			$t_skip = false;
		}
		$t_kept[] = $t_line;
	}
	if( !$p_removed ) {
		return $p_content;
	}

	$t_rest = substr( $p_content, strlen( $t_match[0] ) );
	if( !$t_value ) {
		# Nothing but blank lines and comments left: drop the block.
		return preg_replace( '/\A(\r?\n)+/', '', $t_rest );
	}
	return "---\n" . implode( "\n", $t_kept ) . "\n---\n" . $t_rest;
}

/**
 * Remove "**Label:** value" lifecycle lines from the header region under the
 * document's first heading.
 *
 * @param string $p_content
 * @param array  $p_labels  Labels to remove.
 * @param array  $p_removed Receives the removed labels.
 * @return string
 */
function dwg_metadata_strip_header( string $p_content, array $p_labels, array &$p_removed = array() ): string {
	$t_labels = array_map( 'mb_strtolower', $p_labels );
	$t_eol    = strpos( $p_content, "\r\n" ) !== false ? "\r\n" : "\n";
	$t_lines  = explode( "\n", str_replace( "\r\n", "\n", $p_content ) );

	# Skip the frontmatter, then find the first H1 among the leading lines.
	$t_start = 0;
	if( isset( $t_lines[0] ) && $t_lines[0] === '---' ) {
		for( $i = 1; $i < count( $t_lines ); $i++ ) {
			if( $t_lines[$i] === '---' || $t_lines[$i] === '...' ) {
				$t_start = $i + 1;
				break;
			}
		}
	}
	for( $i = $t_start; $i < count( $t_lines ) && $i < $t_start + 15; $i++ ) {
		if( preg_match( '/^# /', $t_lines[$i] ) ) {
			$t_start = $i + 1;
			break;
		}
	}

	$t_label_line = '/^\*\*[^*\n]+:\*\*/';
	$t_out    = array_slice( $t_lines, 0, $t_start );
	$t_region = array();
	$t_end    = count( $t_lines );
	for( $i = $t_start; $i < count( $t_lines ); $i++ ) {
		$t_line = $t_lines[$i];
		if( trim( $t_line ) === '' || preg_match( '/^\s*<!--.*-->\s*$/', $t_line ) ) {
			$t_region[] = $t_line;
			continue;
		}
		if( !preg_match( $t_label_line, $t_line ) ) {
			$t_end = $i;
			break;
		}
		# Several "**Label:** value" segments may share a line, separated by " | ".
		$t_segments = preg_split( '/\s+\|\s+(?=\*\*[^*\n]+:\*\*)/', rtrim( $t_line ) );
		$t_keep = array();
		foreach( $t_segments as $t_segment ) {
			if( preg_match( '/^\*\*([^*\n]+):\*\*/', $t_segment, $t_label )
			 && in_array( mb_strtolower( trim( $t_label[1] ) ), $t_labels, true ) ) {
				$p_removed[] = trim( $t_label[1] );
				continue;
			}
			$t_keep[] = $t_segment;
		}
		if( $t_keep ) {
			$t_region[] = count( $t_keep ) === count( $t_segments ) ? $t_line : implode( ' | ', $t_keep );
		}
	}
	if( !$p_removed ) {
		return $p_content;
	}

	$t_rest = array_slice( $t_lines, $t_end );
	$t_left = array_filter( $t_region, function( $p_line ) use ( $t_label_line ) {
		return (bool)preg_match( $t_label_line, $p_line );
	} );
	# A rule that only separated the removed header from the body goes too.
	if( !$t_left && isset( $t_rest[0] ) && preg_match( '/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $t_rest[0] ) ) {
		array_shift( $t_rest );
	}

	# Trim the region's blank lines and collapse runs of them, so one blank
	# line separates the heading, any remaining header paragraphs and the body.
	$t_compact = array();
	foreach( $t_region as $t_line ) {
		if( trim( $t_line ) === '' && ( !$t_compact || trim( end( $t_compact ) ) === '' ) ) {
			continue;
		}
		$t_compact[] = $t_line;
	}
	while( $t_compact && trim( end( $t_compact ) ) === '' ) {
		array_pop( $t_compact );
	}
	$t_region = $t_compact;
	while( $t_rest && trim( $t_rest[0] ) === '' ) {
		array_shift( $t_rest );
	}
	$t_result = $t_out;
	if( $t_region ) {
		$t_result[] = '';
		$t_result = array_merge( $t_result, $t_region );
	}
	if( $t_rest ) {
		$t_result[] = '';
		$t_result = array_merge( $t_result, $t_rest );
	}
	return implode( $t_eol, $t_result );
}

/**
 * Remove lifecycle metadata from a Markdown document: frontmatter keys and
 * header lines.
 *
 * @param string $p_content
 * @param array  $p_removed Receives 'keys' and 'labels' removed.
 * @param array  $p_keys    Frontmatter keys (default DWG_METADATA_KEYS).
 * @param array  $p_labels  Header labels (default DWG_METADATA_LABELS).
 * @return string
 */
function dwg_metadata_strip( string $p_content, array &$p_removed = array(), array $p_keys = DWG_METADATA_KEYS, array $p_labels = DWG_METADATA_LABELS ): string {
	$t_keys   = array();
	$t_labels = array();
	$t_content = dwg_metadata_strip_frontmatter( $p_content, $p_keys, $t_keys );
	$t_content = dwg_metadata_strip_header( $t_content, $p_labels, $t_labels );
	$p_removed = array( 'keys' => $t_keys, 'labels' => $t_labels );
	return $t_content;
}
