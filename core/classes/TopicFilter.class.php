<?php
# Doctis - Document Issue Tracking System
#
# Documentation Topics filter for the document list
# (doc/topics/TOPICS_PLAN.md §5, Phase 1b).
#
# Registered by filter_dwg_get_plugin_filters() when $g_topics_enabled is ON.
# Values are Topic codes prefixed with "T" (a bare "00" would compare equal
# to META_FILTER_ANY), or META_FILTER_NONE for documents with no Topic in a
# project that uses Topics. Codes rather than ids, so the filter works across
# projects that adopted different revisions of a catalogue. A principal Topic
# also matches its children's tags (TOP-FR-013); the list's Topics column
# shows each document's specific codes.

class TopicFilter extends MantisFilter {
	public $field = 'topic';
	public $type = FILTER_TYPE_MULTI_STRING;

	/**
	 * Option values: code => option text, cached per request.
	 * @var array|null
	 */
	private $options = null;

	public function __construct() {
		$this->title = lang_get( 'topics' );
	}

	/**
	 * Encode a Topic code as a filter value.
	 * @param string $p_code Topic code.
	 * @return string
	 */
	public static function value( $p_code ) {
		return 'T' . $p_code;
	}

	/**
	 * @param mixed $p_filter_input Filter field input.
	 * @return boolean
	 */
	public function validate( $p_filter_input ) {
		foreach( (array)$p_filter_input as $t_value ) {
			$t_value = (string)$t_value;
			if( $t_value === (string)META_FILTER_ANY || $t_value === (string)META_FILTER_NONE ) {
				continue;
			}
			if( !preg_match( '/^T[A-Za-z0-9][A-Za-z0-9._-]{0,7}$/', $t_value ) ) {
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
		$t_codes = array();
		$t_none = false;
		foreach( (array)$p_filter_input as $t_value ) {
			$t_value = (string)$t_value;
			if( $t_value === (string)META_FILTER_NONE ) {
				$t_none = true;
			} else if( strncmp( $t_value, 'T', 1 ) === 0 ) {
				$t_codes[] = substr( $t_value, 1 );
			}
		}

		$t_clauses = array();
		$t_params = array();
		if( $t_codes ) {
			$t_marks = implode( ',', array_fill( 0, count( $t_codes ), '?' ) );
			$t_clauses[] = 'EXISTS ( SELECT 1 FROM {dwg_topic} tf_dt
				JOIN {topic} tf_t ON tf_t.id=tf_dt.topic_id
				LEFT JOIN {topic} tf_p ON tf_p.id=tf_t.parent_id
				WHERE tf_dt.dwg_id={dwg}.id AND tf_dt.project_id={dwg}.project_id
				AND ( tf_t.code IN (' . $t_marks . ') OR tf_p.code IN (' . $t_marks . ') ) )';
			$t_params = array_merge( $t_codes, $t_codes );
		}
		if( $t_none ) {
			$t_clauses[] = '( {dwg}.project_id IN ( SELECT tf_tp.project_id FROM {topic_project} tf_tp WHERE tf_tp.enabled=1 )
				AND NOT EXISTS ( SELECT 1 FROM {dwg_topic} tf_dt2
					WHERE tf_dt2.dwg_id={dwg}.id AND tf_dt2.project_id={dwg}.project_id ) )';
		}
		if( !$t_clauses ) {
			return array();
		}
		return array(
			'where' => '( ' . implode( ' OR ', $t_clauses ) . ' )',
			'params' => $t_params,
		);
	}

	/**
	 * @param mixed $p_filter_value One filter value.
	 * @return string
	 */
	public function display( $p_filter_value ) {
		$t_options = $this->options();
		return $t_options[(string)$p_filter_value] ?? substr( (string)$p_filter_value, 1 );
	}

	/**
	 * The Topics of the current project's catalogue, or with All Projects the
	 * Topics of every catalogue in use (by code), plus [none].
	 * @return array value => text
	 */
	public function options() {
		if( $this->options !== null ) {
			return $this->options;
		}
		require_api( 'topic_api.php' );

		$this->options = array( (string)META_FILTER_NONE => '[' . lang_get( 'topic_filter_none' ) . ']' );
		foreach( topic_code_options( helper_get_current_project() ) as $t_code => $t_option ) {
			$t_text = $t_code . ' ' . $t_option['title'];
			if( $t_option['children'] ) {
				$t_text .= ' (+ ' . implode( ', ', $t_option['children'] ) . ')';
			}
			$this->options[self::value( $t_code )] = $t_text;
		}
		return $this->options;
	}
}
