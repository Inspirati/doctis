<?php
# Doctis - Document Issue Tracking System
#
# Document frontmatter: the metadata block a Markdown document carries about
# itself, and the mapping from its status word to a Doctis status.
#
# This began in admin/import-git-repo.php, which was the only thing that read
# it.  Registering a file that arrived through an external clone needs the
# same parse -- the Register form is pre-filled from the document's own
# frontmatter -- and that runs in a web request, which cannot include a CLI
# script that parses getopt at load time.  So it lives here, and both callers
# use one parser rather than two that drift.
#
# See doc/git/GIT_IMPORTER.md section 6.

require_api( 'constant_inc.php' );

/**
 * Naive YAML frontmatter: a leading --- block of "key: value" lines.
 *
 * Deliberately not a YAML parser.  The documents this reads are written by
 * hand to a house pattern, and a real parser would accept structures that
 * the rest of the pipeline has no meaning for.
 *
 * @param string $p_content File content.
 * @return array Lower-cased key => value, empty when there is no block.
 */
function dwg_frontmatter_parse( string $p_content ): array {
	if( !preg_match( '/^---\R(.*?)\R---(\R|$)/s', $p_content, $t_m ) ) {
		return array();
	}
	$t_out = array();
	foreach( preg_split( '/\R/', $t_m[1] ) as $t_line ) {
		if( preg_match( '/^([A-Za-z0-9_\-]+):\s*(.*)$/', $t_line, $t_kv ) ) {
			$t_out[strtolower( $t_kv[1] )] = trim( $t_kv[2], " \t\"'" );
		}
	}
	return $t_out;
}

/**
 * Map a frontmatter status word to a Doctis document status.
 *
 * Importing or registering never approves a document: approval is a separate
 * Doctis action with its own threshold and history.  An unknown word becomes
 * pending rather than guessing.
 *
 * @param string $p_status
 * @return integer
 */
function dwg_frontmatter_status( string $p_status ): int {
	$t_status = strtolower( trim( $p_status ) );
	# Qualified draft statuses ("Draft — requires CEO signature…") are drafts:
	# status 165, labelled "draft" (owner decision 2026-10-13).
	if( strpos( $t_status, 'draft' ) === 0 ) {
		return 165;
	}
	switch( $t_status ) {
		case '':
			return 110;
		case 'in review':
		case 'review':
			return 160;
		case 'approved':
		case 'effective':
		case 'active':
			return 180;
		case 'released':
			return 190;
		case 'superseded':
		case 'obsolete':
		case 'withdrawn':
			return 195;
		default:
			return 110;   # unknown → pending; the caller reports it
	}
}

/**
 * The document fields a frontmatter block supplies, as the create form names
 * them.  Only keys the block actually carried are returned, so a caller can
 * tell "absent" from "empty" and leave its own defaults alone.
 *
 * @param array $p_front dwg_frontmatter_parse() output.
 * @return array Form field name => value.
 */
function dwg_frontmatter_fields( array $p_front ): array {
	# doc_id maps to the document NUMBER, not the reference: Doctis manages
	# {documents}.reference as the on-record commit SHA, which
	# file_dwg_primary_register() overwrites on every registration.  The
	# importer has always done it this way (import-git-repo.php header);
	# mapping it to reference would silently lose the identifier.
	static $s_map = array(
		'doc_id'         => 'dwg_number',
		'document_id'    => 'dwg_number',
		'number'         => 'dwg_number',
		'title'          => 'dwg_title',
		'revision'       => 'dwg_revision',
		'edition'        => 'dwg_edition',
		'author'         => 'dwg_author',
		'publisher'      => 'dwg_publisher',
		'discipline'     => 'dwg_discipline',
		'classification' => 'dwg_classification',
	);
	$t_fields = array();
	foreach( $s_map as $t_key => $t_field ) {
		if( isset( $p_front[$t_key] ) && $p_front[$t_key] !== '' && !isset( $t_fields[$t_field] ) ) {
			$t_fields[$t_field] = $p_front[$t_key];
		}
	}
	return $t_fields;
}

/**
 * Whether a frontmatter block carries enough to register a document without
 * anyone typing anything.
 *
 * A title is the one field with no sensible fallback: everything else can be
 * left blank or defaulted, but a document with no name is the defect this
 * codebase already had once.  Bulk registration uses this; single
 * registration does not, because there a person is filling the form in.
 *
 * @param array $p_front
 * @return boolean
 */
function dwg_frontmatter_sufficient( array $p_front ): bool {
	$t_fields = dwg_frontmatter_fields( $p_front );
	return isset( $t_fields['dwg_title'] ) && trim( $t_fields['dwg_title'] ) !== '';
}
