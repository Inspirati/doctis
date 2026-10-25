<?php
# Doctis - Document Issue Tracking System
#
# Record-kind filter for the document list
# (doc/git/SOURCE_REPOSITORIES.md).
#
# Registered by filter_dwg_get_plugin_filters() in every project, because
# every document has a kind.  The four values are the combinations of
# {dwg}.kind and {dwg}.frozen that mean something:
#
#   document          a file, still following the repository
#   document_frozen   a file whose issued version is final
#   source_line       a repository or subtree, following its branch
#   source_release    a repository or subtree, pinned at its commit
#
# Values are words rather than numbers because a bare "0" would compare equal
# to META_FILTER_ANY.

class DwgKindFilter extends MantisFilter {
	public $field = 'dwg_kind';
	public $type = FILTER_TYPE_MULTI_STRING;

	/**
	 * Filter value => array( kind, frozen ).
	 *
	 * @return array
	 */
	public static function states() {
		return array(
			'document'        => array( DWG_KIND_DOCUMENT, 0 ),
			'document_frozen' => array( DWG_KIND_DOCUMENT, 1 ),
			'source_line'     => array( DWG_KIND_SOURCE,   0 ),
			'source_release'  => array( DWG_KIND_SOURCE,   1 ),
		);
	}

	public function __construct() {
		$this->title = lang_get( 'dwg_kind' );
	}

	/**
	 * @param mixed $p_filter_input Filter field input.
	 * @return boolean
	 */
	public function validate( $p_filter_input ) {
		$t_states = self::states();
		foreach( (array)$p_filter_input as $t_value ) {
			$t_value = (string)$t_value;
			if( $t_value === (string)META_FILTER_ANY ) {
				continue;
			}
			if( !isset( $t_states[$t_value] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param mixed $p_filter_input Filter field input.
	 * @return array Query parts: where, params.
	 */
	public function query( $p_filter_input ) {
		$t_states = self::states();
		$t_clauses = array();
		$t_params  = array();
		foreach( (array)$p_filter_input as $t_value ) {
			$t_value = (string)$t_value;
			if( !isset( $t_states[$t_value] ) ) {
				continue;
			}
			list( $t_kind, $t_frozen ) = $t_states[$t_value];
			$t_clauses[] = '( {dwg}.kind=? AND {dwg}.frozen=? )';
			$t_params[]  = $t_kind;
			$t_params[]  = $t_frozen;
		}
		if( !$t_clauses ) {
			return array();
		}
		return array(
			'where'  => '( ' . implode( ' OR ', $t_clauses ) . ' )',
			'params' => $t_params,
		);
	}

	/**
	 * @param mixed $p_filter_value One filter value.
	 * @return string
	 */
	public function display( $p_filter_value ) {
		$t_options = $this->options();
		return $t_options[(string)$p_filter_value] ?? (string)$p_filter_value;
	}

	/**
	 * @return array value => text
	 */
	public function options() {
		$t_options = array();
		foreach( array_keys( self::states() ) as $t_value ) {
			$t_options[$t_value] = lang_get( 'dwg_kind_' . $t_value );
		}
		return $t_options;
	}
}
