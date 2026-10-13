<?php
# Doctis - Document Issue Tracking System
#
# Document Topics API (doc/topics/TOPICS_PLAN.md, Phase 1).
#
# A Topic catalogue (e.g. STD-ENG-004 Rev A) lists numbered Topics of project
# engineering information. A project enables Topics by adopting a catalogue,
# decides whether each Topic applies, and tags its documents with Topics: a
# document is either part of a Topic's documentation or the whole of it, and
# a locator names the relevant sections.
#
# Tags are declarations, not adequacy assessments. A Topic's coverage comes
# from its own tags only: parent and child Topics never affect each other.
#
# Tables: {topic_catalogue}, {topic}, {topic_project}, {topic_applicability},
# {dwg_topic}. Validation failures throw ClientException(ERROR_TOPIC) with a
# readable message.

use Mantis\Exceptions\ClientException;

require_api( 'access_api.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'dwg_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'history_dwg_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );

/**
 * Coverage states of a Topic in a project (Phase 1, declared).
 */
const TOPIC_STATE_NOT_APPLICABLE = 'not_applicable';
const TOPIC_STATE_NONE = 'none';
const TOPIC_STATE_PARTIAL = 'partial';
const TOPIC_STATE_COMPLETE = 'complete';

/**
 * Longest locator, applicability rationale and Topic code accepted.
 */
const TOPIC_LOCATOR_MAX = 255;
const TOPIC_RATIONALE_MAX = 1024;

/**
 * Directory of bundled catalogue files that Manage > Topics can load.
 */
const TOPIC_CATALOGUE_SEED_DIR = __DIR__ . '/topic_catalogues';

/**
 * Throw a Topics validation error.
 *
 * @param string $p_message Readable message.
 * @return never
 * @throws ClientException
 */
function topic_error( string $p_message ) {
	throw new ClientException( $p_message, ERROR_TOPIC, array( $p_message ) );
}

# ── Access ───────────────────────────────────────────────────────────────────

/**
 * Whether the Topics feature is switched on ($g_topics_enabled).
 *
 * @return boolean
 */
function topic_feature_enabled(): bool {
	return ON == config_get( 'topics_enabled' );
}

/**
 * Whether the user may manage Topic catalogues (Manage > Topics).
 *
 * @param integer|null $p_user_id User, default current.
 * @return boolean
 */
function topic_can_manage_catalogues( ?int $p_user_id = null ): bool {
	return topic_feature_enabled()
		&& access_has_global_level( config_get( 'manage_topic_threshold' ), $p_user_id );
}

/**
 * Whether the user may enable Topics on a project and decide applicability.
 *
 * @param integer      $p_project_id Project.
 * @param integer|null $p_user_id    User, default current.
 * @return boolean
 */
function topic_can_manage_project( int $p_project_id, ?int $p_user_id = null ): bool {
	return topic_feature_enabled() && $p_project_id != ALL_PROJECTS
		&& access_has_project_level( config_get( 'topic_profile_threshold', null, $p_user_id, $p_project_id ), $p_project_id, $p_user_id );
}

/**
 * Whether the user may view a project's Topics.
 *
 * @param integer      $p_project_id Project.
 * @param integer|null $p_user_id    User, default current.
 * @return boolean
 */
function topic_can_view_project( int $p_project_id, ?int $p_user_id = null ): bool {
	return topic_feature_enabled() && $p_project_id != ALL_PROJECTS
		&& access_has_project_level( config_get( 'topic_view_threshold', null, $p_user_id, $p_project_id ), $p_project_id, $p_user_id );
}

/**
 * Whether the user may change a document's Topic tags.
 *
 * @param integer      $p_dwg_id  Document.
 * @param integer|null $p_user_id User, default current.
 * @return boolean
 */
function topic_can_tag_dwg( int $p_dwg_id, ?int $p_user_id = null ): bool {
	if( !topic_feature_enabled() ) {
		return false;
	}
	$t_project_id = (int)dwg_get_field( $p_dwg_id, 'project_id' );
	return topic_project_is_enabled( $t_project_id )
		&& access_has_dwg_level( config_get( 'topic_tag_threshold', null, $p_user_id, $t_project_id ), $p_dwg_id, $p_user_id );
}

/**
 * Whether the user may see a document (the document list's rules).
 *
 * @param integer      $p_dwg_id  Document.
 * @param integer|null $p_user_id User, default current.
 * @return boolean
 */
function topic_can_see_dwg( int $p_dwg_id, ?int $p_user_id = null ): bool {
	return access_has_dwg_level( config_get( 'view_dwg_threshold' ), $p_dwg_id, $p_user_id );
}

# ── Catalogues ───────────────────────────────────────────────────────────────

/**
 * All catalogues, by name and revision.
 *
 * @return array Rows of {topic_catalogue}.
 */
function topic_catalogue_get_all(): array {
	$t_result = db_query( 'SELECT * FROM {topic_catalogue} ORDER BY name, revision, id' );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * One catalogue.
 *
 * @param integer $p_catalogue_id Catalogue.
 * @return array Row of {topic_catalogue}.
 * @throws ClientException when it does not exist.
 */
function topic_catalogue_get( int $p_catalogue_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_catalogue} WHERE id=' . db_param(), array( $p_catalogue_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		topic_error( 'catalogue ' . $p_catalogue_id . ' does not exist.' );
	}
	return $t_row;
}

/**
 * Display name of a catalogue, e.g. "STD-ENG-004 Rev A".
 *
 * @param array $p_catalogue Row of {topic_catalogue}.
 * @return string
 */
function topic_catalogue_label( array $p_catalogue ): string {
	return trim( $p_catalogue['name'] . ( $p_catalogue['revision'] !== '' ? ' Rev ' . $p_catalogue['revision'] : '' ) );
}

/**
 * Localised name of a catalogue status.
 *
 * @param integer $p_status TOPIC_CATALOGUE_* value.
 * @return string
 */
function topic_catalogue_status_label( int $p_status ): string {
	switch( $p_status ) {
		case TOPIC_CATALOGUE_DRAFT:
			return lang_get( 'topic_catalogue_draft' );
		case TOPIC_CATALOGUE_PUBLISHED:
			return lang_get( 'topic_catalogue_published' );
		case TOPIC_CATALOGUE_RETIRED:
			return lang_get( 'topic_catalogue_retired' );
	}
	return (string)$p_status;
}

/**
 * Validate catalogue name, revision and source document.
 *
 * @param string  $p_name          Name.
 * @param string  $p_revision      Revision.
 * @param integer $p_source_dwg_id Source document, 0 = none.
 * @param integer $p_catalogue_id  Catalogue being updated, 0 = new.
 * @return void
 * @throws ClientException
 */
function topic_catalogue_validate( string $p_name, string $p_revision, int $p_source_dwg_id, int $p_catalogue_id ): void {
	if( $p_name === '' ) {
		topic_error( 'a catalogue needs a name.' );
	}
	if( mb_strlen( $p_name ) > 128 || mb_strlen( $p_revision ) > 32 ) {
		topic_error( 'catalogue name (128) or revision (32 characters) too long.' );
	}
	if( $p_source_dwg_id != 0 && !dwg_exists( $p_source_dwg_id ) ) {
		topic_error( 'source document ' . $p_source_dwg_id . ' does not exist.' );
	}
	db_param_push();
	$t_result = db_query( 'SELECT id FROM {topic_catalogue} WHERE name=' . db_param()
		. ' AND revision=' . db_param() . ' AND id<>' . db_param(),
		array( $p_name, $p_revision, $p_catalogue_id ) );
	if( db_num_rows( $t_result ) > 0 ) {
		topic_error( 'a catalogue named "' . $p_name . '" with revision "' . $p_revision . '" already exists.' );
	}
}

/**
 * Create a Draft catalogue.
 *
 * @param string  $p_name          Name, e.g. "STD-ENG-004".
 * @param string  $p_revision      Revision, e.g. "A".
 * @param integer $p_source_dwg_id Registered source document, 0 = none.
 * @return integer New catalogue id.
 * @throws ClientException
 */
function topic_catalogue_create( string $p_name, string $p_revision, int $p_source_dwg_id = 0 ): int {
	$t_name = trim( $p_name );
	$t_revision = trim( $p_revision );
	topic_catalogue_validate( $t_name, $t_revision, $p_source_dwg_id, 0 );

	db_param_push();
	db_query( 'INSERT INTO {topic_catalogue} ( name, revision, source_dwg_id, status, user_id, date_created, date_updated )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $t_name, $t_revision, $p_source_dwg_id, TOPIC_CATALOGUE_DRAFT,
			auth_get_current_user_id(), db_now(), db_now() ) );
	return (int)db_insert_id( db_get_table( 'topic_catalogue' ) );
}

/**
 * Change a catalogue's name, revision and source document. Draft only.
 *
 * @param integer $p_catalogue_id  Catalogue.
 * @param string  $p_name          Name.
 * @param string  $p_revision      Revision.
 * @param integer $p_source_dwg_id Source document, 0 = none.
 * @return void
 * @throws ClientException
 */
function topic_catalogue_update( int $p_catalogue_id, string $p_name, string $p_revision, int $p_source_dwg_id ): void {
	topic_catalogue_ensure_draft( $p_catalogue_id );
	$t_name = trim( $p_name );
	$t_revision = trim( $p_revision );
	topic_catalogue_validate( $t_name, $t_revision, $p_source_dwg_id, $p_catalogue_id );

	db_param_push();
	db_query( 'UPDATE {topic_catalogue} SET name=' . db_param() . ', revision=' . db_param()
		. ', source_dwg_id=' . db_param() . ', date_updated=' . db_param() . ' WHERE id=' . db_param(),
		array( $t_name, $t_revision, $p_source_dwg_id, db_now(), $p_catalogue_id ) );
}

/**
 * Stop unless the catalogue is Draft (fully editable).
 *
 * @param integer $p_catalogue_id Catalogue.
 * @return array The catalogue row.
 * @throws ClientException
 */
function topic_catalogue_ensure_draft( int $p_catalogue_id ): array {
	$t_catalogue = topic_catalogue_get( $p_catalogue_id );
	if( (int)$t_catalogue['status'] != TOPIC_CATALOGUE_DRAFT ) {
		topic_error( topic_catalogue_label( $t_catalogue ) . ' is '
			. strtolower( topic_catalogue_status_label( (int)$t_catalogue['status'] ) )
			. '; only a Draft catalogue can be edited.' );
	}
	return $t_catalogue;
}

/**
 * Why a catalogue cannot be published, or '' when it can.
 *
 * Publishing needs at least one Topic and a source document whose status is
 * one of $g_topic_catalogue_publish_status.
 *
 * @param array $p_catalogue Row of {topic_catalogue}.
 * @return string
 */
function topic_catalogue_publish_blocker( array $p_catalogue ): string {
	if( !topic_get_all( (int)$p_catalogue['id'], true ) ) {
		return 'it has no Topics.';
	}
	$t_source = (int)$p_catalogue['source_dwg_id'];
	if( $t_source == 0 || !dwg_exists( $t_source ) ) {
		return 'it has no source document. Set the registered standard it comes from.';
	}
	$t_status = (int)dwg_get_field( $t_source, 'status' );
	if( !in_array( $t_status, (array)config_get( 'topic_catalogue_publish_status' ) ) ) {
		return 'its source document ' . dwg_format_id( $t_source ) . ' is '
			. get_enum_element( 'dwg_status', $t_status ) . ', not approved.';
	}
	return '';
}

/**
 * Change a catalogue's status.
 *
 * Allowed: Draft → Published (see topic_catalogue_publish_blocker()),
 * Published → Retired, Retired → Published.
 *
 * @param integer $p_catalogue_id Catalogue.
 * @param integer $p_status       TOPIC_CATALOGUE_PUBLISHED or _RETIRED.
 * @return void
 * @throws ClientException
 */
function topic_catalogue_set_status( int $p_catalogue_id, int $p_status ): void {
	$t_catalogue = topic_catalogue_get( $p_catalogue_id );
	$t_current = (int)$t_catalogue['status'];
	if( $t_current == $p_status ) {
		return;
	}
	if( $p_status == TOPIC_CATALOGUE_PUBLISHED ) {
		if( $t_current == TOPIC_CATALOGUE_DRAFT ) {
			$t_blocker = topic_catalogue_publish_blocker( $t_catalogue );
			if( $t_blocker !== '' ) {
				topic_error( topic_catalogue_label( $t_catalogue ) . ' cannot be published: ' . $t_blocker );
			}
		}
	} else if( $p_status == TOPIC_CATALOGUE_RETIRED ) {
		if( $t_current != TOPIC_CATALOGUE_PUBLISHED ) {
			topic_error( 'only a Published catalogue can be retired; delete a Draft instead.' );
		}
	} else {
		topic_error( 'a catalogue cannot return to Draft.' );
	}

	db_param_push();
	db_query( 'UPDATE {topic_catalogue} SET status=' . db_param() . ', date_updated=' . db_param()
		. ' WHERE id=' . db_param(), array( $p_status, db_now(), $p_catalogue_id ) );
}

/**
 * Number of projects that have adopted a catalogue (enabled or not).
 *
 * @param integer $p_catalogue_id Catalogue.
 * @return integer
 */
function topic_catalogue_project_count( int $p_catalogue_id ): int {
	db_param_push();
	$t_result = db_query( 'SELECT COUNT(*) FROM {topic_project} WHERE catalogue_id=' . db_param(), array( $p_catalogue_id ) );
	return (int)db_result( $t_result );
}

/**
 * Delete a Draft catalogue that no project has adopted, with its Topics.
 *
 * @param integer $p_catalogue_id Catalogue.
 * @return void
 * @throws ClientException
 */
function topic_catalogue_delete( int $p_catalogue_id ): void {
	$t_catalogue = topic_catalogue_ensure_draft( $p_catalogue_id );
	if( topic_catalogue_project_count( $p_catalogue_id ) > 0 ) {
		topic_error( topic_catalogue_label( $t_catalogue ) . ' is used by a project and cannot be deleted.' );
	}
	db_param_push();
	db_query( 'DELETE FROM {topic} WHERE catalogue_id=' . db_param(), array( $p_catalogue_id ) );
	db_param_push();
	db_query( 'DELETE FROM {topic_catalogue} WHERE id=' . db_param(), array( $p_catalogue_id ) );
}

/**
 * Bundled catalogue files that can be loaded.
 *
 * @return array Per file: file (basename), name, revision, loaded (bool).
 */
function topic_catalogue_seeds(): array {
	$t_seeds = array();
	foreach( glob( TOPIC_CATALOGUE_SEED_DIR . '/*.json' ) ?: array() as $t_path ) {
		$t_data = json_decode( (string)file_get_contents( $t_path ), true );
		if( !is_array( $t_data ) || !isset( $t_data['name'] ) ) {
			continue;
		}
		db_param_push();
		$t_result = db_query( 'SELECT id FROM {topic_catalogue} WHERE name=' . db_param() . ' AND revision=' . db_param(),
			array( (string)$t_data['name'], (string)( $t_data['revision'] ?? '' ) ) );
		$t_seeds[] = array(
			'file' => basename( $t_path ),
			'name' => (string)$t_data['name'],
			'revision' => (string)( $t_data['revision'] ?? '' ),
			'loaded' => db_num_rows( $t_result ) > 0,
		);
	}
	return $t_seeds;
}

/**
 * Load a bundled catalogue file as a new Draft catalogue.
 *
 * File format: {"name", "revision", "topics": [{"code", "parent" (code or
 * absent), "title", "description"}]}, principal Topics before their children.
 *
 * @param string $p_file Basename of a file in TOPIC_CATALOGUE_SEED_DIR.
 * @return integer New catalogue id.
 * @throws ClientException
 */
function topic_catalogue_load_seed( string $p_file ): int {
	$t_path = TOPIC_CATALOGUE_SEED_DIR . '/' . basename( $p_file );
	if( !preg_match( '/\.json$/', $t_path ) || !is_file( $t_path ) ) {
		topic_error( 'catalogue file "' . $p_file . '" not found.' );
	}
	$t_data = json_decode( (string)file_get_contents( $t_path ), true );
	if( !is_array( $t_data ) || !isset( $t_data['name'], $t_data['topics'] ) || !is_array( $t_data['topics'] ) ) {
		topic_error( 'catalogue file "' . $p_file . '" is not valid.' );
	}

	$t_catalogue_id = topic_catalogue_create( (string)$t_data['name'], (string)( $t_data['revision'] ?? '' ) );
	$t_ids = array();
	$t_order = 0;
	try {
		foreach( $t_data['topics'] as $t_topic ) {
			$t_parent_code = (string)( $t_topic['parent'] ?? '' );
			if( $t_parent_code !== '' && !isset( $t_ids[$t_parent_code] ) ) {
				topic_error( 'catalogue file "' . $p_file . '": parent ' . $t_parent_code . ' must come before its children.' );
			}
			$t_order += 10;
			$t_code = (string)( $t_topic['code'] ?? '' );
			$t_ids[$t_code] = topic_create( $t_catalogue_id, $t_code,
				$t_parent_code === '' ? 0 : $t_ids[$t_parent_code],
				(string)( $t_topic['title'] ?? '' ), (string)( $t_topic['description'] ?? '' ), $t_order );
		}
	} catch( ClientException $e ) {
		# Leave no half-loaded catalogue behind.
		topic_catalogue_delete( $t_catalogue_id );
		throw $e;
	}
	return $t_catalogue_id;
}

# ── Topics ───────────────────────────────────────────────────────────────────

/**
 * A catalogue's Topics in display order: each principal Topic followed by
 * its children, each level ordered by sort_order then code. Each row gains
 * 'level' (0 principal, 1 child).
 *
 * @param integer $p_catalogue_id Catalogue.
 * @param boolean $p_enabled_only Leave out retired Topics.
 * @return array Rows of {topic}, keyed by id.
 */
function topic_get_all( int $p_catalogue_id, bool $p_enabled_only = false ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic} WHERE catalogue_id=' . db_param()
		. ( $p_enabled_only ? ' AND enabled=1' : '' ) . ' ORDER BY sort_order, code', array( $p_catalogue_id ) );
	$t_principal = array();
	$t_children = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		if( (int)$t_row['parent_id'] == 0 ) {
			$t_principal[] = $t_row;
		} else {
			$t_children[(int)$t_row['parent_id']][] = $t_row;
		}
	}
	$t_topics = array();
	foreach( $t_principal as $t_row ) {
		$t_row['level'] = 0;
		$t_topics[(int)$t_row['id']] = $t_row;
		foreach( $t_children[(int)$t_row['id']] ?? array() as $t_child ) {
			$t_child['level'] = 1;
			$t_topics[(int)$t_child['id']] = $t_child;
		}
		unset( $t_children[(int)$t_row['id']] );
	}
	# Children of a retired parent left out by $p_enabled_only.
	foreach( $t_children as $t_rows ) {
		foreach( $t_rows as $t_child ) {
			$t_child['level'] = 1;
			$t_topics[(int)$t_child['id']] = $t_child;
		}
	}
	return $t_topics;
}

/**
 * One Topic.
 *
 * @param integer $p_topic_id Topic.
 * @return array Row of {topic}.
 * @throws ClientException when it does not exist.
 */
function topic_get( int $p_topic_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic} WHERE id=' . db_param(), array( $p_topic_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		topic_error( 'Topic ' . $p_topic_id . ' does not exist.' );
	}
	return $t_row;
}

/**
 * Display name of a Topic, e.g. "10 System Concept and Requirements".
 *
 * @param array $p_topic Row of {topic}.
 * @return string
 */
function topic_label( array $p_topic ): string {
	return $p_topic['code'] . ' ' . $p_topic['title'];
}

/**
 * Whether a Topic code is acceptable: 1–8 letters, digits, '.', '_' or '-',
 * starting with a letter or digit.
 *
 * @param string $p_code Code.
 * @return boolean
 */
function topic_code_is_valid( string $p_code ): bool {
	return (bool)preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]{0,7}$/', $p_code );
}

/**
 * Validate a Topic's code, parent and title within its catalogue.
 *
 * @param integer $p_catalogue_id Catalogue.
 * @param integer $p_topic_id     Topic being updated, 0 = new.
 * @param string  $p_code         Code.
 * @param integer $p_parent_id    Parent Topic, 0 = principal.
 * @param string  $p_title        Title.
 * @return void
 * @throws ClientException
 */
function topic_validate( int $p_catalogue_id, int $p_topic_id, string $p_code, int $p_parent_id, string $p_title ): void {
	if( !topic_code_is_valid( $p_code ) ) {
		topic_error( 'Topic code "' . $p_code . '" is not valid: use 1 to 8 letters or digits (also . _ -).' );
	}
	if( $p_title === '' || mb_strlen( $p_title ) > 128 ) {
		topic_error( 'a Topic needs a title of at most 128 characters.' );
	}
	db_param_push();
	$t_result = db_query( 'SELECT id FROM {topic} WHERE catalogue_id=' . db_param() . ' AND code=' . db_param()
		. ' AND id<>' . db_param(), array( $p_catalogue_id, $p_code, $p_topic_id ) );
	if( db_num_rows( $t_result ) > 0 ) {
		topic_error( 'the catalogue already has a Topic ' . $p_code . '.' );
	}
	if( $p_parent_id != 0 ) {
		if( $p_parent_id == $p_topic_id ) {
			topic_error( 'a Topic cannot be its own parent.' );
		}
		$t_parent = topic_get( $p_parent_id );
		if( (int)$t_parent['catalogue_id'] != $p_catalogue_id ) {
			topic_error( 'the parent Topic belongs to another catalogue.' );
		}
		if( (int)$t_parent['parent_id'] != 0 ) {
			topic_error( 'Topic ' . $t_parent['code'] . ' is itself a child; Topics nest one level only.' );
		}
		if( $p_topic_id != 0 ) {
			db_param_push();
			$t_result = db_query( 'SELECT COUNT(*) FROM {topic} WHERE parent_id=' . db_param(), array( $p_topic_id ) );
			if( (int)db_result( $t_result ) > 0 ) {
				topic_error( 'a Topic with children cannot have a parent; Topics nest one level only.' );
			}
		}
	}
}

/**
 * Add a Topic to a Draft catalogue.
 *
 * @param integer $p_catalogue_id Catalogue.
 * @param string  $p_code         Code, e.g. "00".
 * @param integer $p_parent_id    Parent Topic, 0 = principal.
 * @param string  $p_title        Title.
 * @param string  $p_description  Expected information.
 * @param integer $p_sort_order   Display order among siblings.
 * @return integer New Topic id.
 * @throws ClientException
 */
function topic_create( int $p_catalogue_id, string $p_code, int $p_parent_id, string $p_title, string $p_description, int $p_sort_order = 0 ): int {
	topic_catalogue_ensure_draft( $p_catalogue_id );
	$t_code = trim( $p_code );
	$t_title = trim( $p_title );
	topic_validate( $p_catalogue_id, 0, $t_code, $p_parent_id, $t_title );

	db_param_push();
	db_query( 'INSERT INTO {topic} ( catalogue_id, code, parent_id, title, description, sort_order, enabled, date_created, date_updated )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', 1, ' . db_param() . ', ' . db_param() . ' )',
		array( $p_catalogue_id, $t_code, $p_parent_id, $t_title, trim( $p_description ), $p_sort_order, db_now(), db_now() ) );
	return (int)db_insert_id( db_get_table( 'topic' ) );
}

/**
 * Change a Topic of a Draft catalogue.
 *
 * @param integer $p_topic_id    Topic.
 * @param string  $p_code        Code.
 * @param integer $p_parent_id   Parent Topic, 0 = principal.
 * @param string  $p_title       Title.
 * @param string  $p_description Expected information.
 * @param integer $p_sort_order  Display order among siblings.
 * @return void
 * @throws ClientException
 */
function topic_update( int $p_topic_id, string $p_code, int $p_parent_id, string $p_title, string $p_description, int $p_sort_order ): void {
	$t_topic = topic_get( $p_topic_id );
	topic_catalogue_ensure_draft( (int)$t_topic['catalogue_id'] );
	$t_code = trim( $p_code );
	$t_title = trim( $p_title );
	topic_validate( (int)$t_topic['catalogue_id'], $p_topic_id, $t_code, $p_parent_id, $t_title );

	db_param_push();
	db_query( 'UPDATE {topic} SET code=' . db_param() . ', parent_id=' . db_param() . ', title=' . db_param()
		. ', description=' . db_param() . ', sort_order=' . db_param() . ', date_updated=' . db_param()
		. ' WHERE id=' . db_param(),
		array( $t_code, $p_parent_id, $t_title, trim( $p_description ), $p_sort_order, db_now(), $p_topic_id ) );
}

/**
 * Retire or restore a Topic. Allowed in Draft and Published catalogues;
 * retired Topics are offered for no new tags.
 *
 * @param integer $p_topic_id Topic.
 * @param boolean $p_enabled  False to retire, true to restore.
 * @return void
 * @throws ClientException
 */
function topic_set_enabled( int $p_topic_id, bool $p_enabled ): void {
	$t_topic = topic_get( $p_topic_id );
	$t_catalogue = topic_catalogue_get( (int)$t_topic['catalogue_id'] );
	if( (int)$t_catalogue['status'] == TOPIC_CATALOGUE_RETIRED ) {
		topic_error( topic_catalogue_label( $t_catalogue ) . ' is retired.' );
	}
	db_param_push();
	db_query( 'UPDATE {topic} SET enabled=' . db_param() . ', date_updated=' . db_param() . ' WHERE id=' . db_param(),
		array( $p_enabled ? 1 : 0, db_now(), $p_topic_id ) );
}

/**
 * Whether any tag or applicability decision refers to a Topic.
 *
 * @param integer $p_topic_id Topic.
 * @return boolean
 */
function topic_is_used( int $p_topic_id ): bool {
	foreach( array( '{dwg_topic}', '{topic_applicability}', '{topic_expectation}' ) as $t_table ) {
		db_param_push();
		$t_result = db_query( 'SELECT COUNT(*) FROM ' . $t_table . ' WHERE topic_id=' . db_param(), array( $p_topic_id ) );
		if( (int)db_result( $t_result ) > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Delete an unused Topic, without children, from a Draft catalogue.
 *
 * @param integer $p_topic_id Topic.
 * @return void
 * @throws ClientException
 */
function topic_delete( int $p_topic_id ): void {
	$t_topic = topic_get( $p_topic_id );
	topic_catalogue_ensure_draft( (int)$t_topic['catalogue_id'] );
	db_param_push();
	$t_result = db_query( 'SELECT COUNT(*) FROM {topic} WHERE parent_id=' . db_param(), array( $p_topic_id ) );
	if( (int)db_result( $t_result ) > 0 ) {
		topic_error( 'Topic ' . $t_topic['code'] . ' has children; delete or move them first.' );
	}
	if( topic_is_used( $p_topic_id ) ) {
		topic_error( 'Topic ' . $t_topic['code'] . ' is in use by a project; retire it instead.' );
	}
	db_param_push();
	db_query( 'DELETE FROM {topic} WHERE id=' . db_param(), array( $p_topic_id ) );
}

# ── Project enablement ───────────────────────────────────────────────────────

/**
 * A project's Topics row.
 *
 * @param integer $p_project_id Project.
 * @return array|null Row of {topic_project}, null when never enabled.
 */
function topic_project_get( int $p_project_id ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_project} WHERE project_id=' . db_param(), array( $p_project_id ) );
	$t_row = db_fetch_array( $t_result );
	return $t_row ?: null;
}

/**
 * Whether Topics are switched on globally and enabled for the project.
 *
 * @param integer $p_project_id Project.
 * @return boolean
 */
function topic_project_is_enabled( int $p_project_id ): bool {
	if( !topic_feature_enabled() || $p_project_id == ALL_PROJECTS ) {
		return false;
	}
	$t_row = topic_project_get( $p_project_id );
	return $t_row !== null && (int)$t_row['enabled'] == 1;
}

/**
 * Whether a project has any Topic tags or applicability decisions.
 *
 * @param integer $p_project_id Project.
 * @return boolean
 */
function topic_project_has_records( int $p_project_id ): bool {
	foreach( array( '{dwg_topic}', '{topic_applicability}', '{topic_expectation}' ) as $t_table ) {
		db_param_push();
		$t_result = db_query( 'SELECT COUNT(*) FROM ' . $t_table . ' WHERE project_id=' . db_param(), array( $p_project_id ) );
		if( (int)db_result( $t_result ) > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Enable Topics on a project with a catalogue.
 *
 * Re-enabling keeps the adopted catalogue. Changing catalogue is refused
 * once the project has tags or decisions (migration is a later phase).
 *
 * @param integer $p_project_id   Project.
 * @param integer $p_catalogue_id Catalogue to adopt.
 * @return void
 * @throws ClientException
 */
function topic_project_enable( int $p_project_id, int $p_catalogue_id ): void {
	if( $p_project_id == ALL_PROJECTS || !project_exists( $p_project_id ) ) {
		topic_error( 'select a project first.' );
	}
	$t_catalogue = topic_catalogue_get( $p_catalogue_id );
	$t_row = topic_project_get( $p_project_id );
	if( $t_row !== null && (int)$t_row['catalogue_id'] != $p_catalogue_id ) {
		if( topic_project_has_records( $p_project_id ) ) {
			topic_error( 'this project already uses another catalogue and has Topic records; changing catalogue is not supported yet.' );
		}
	}
	if( (int)$t_catalogue['status'] == TOPIC_CATALOGUE_RETIRED
		&& ( $t_row === null || (int)$t_row['catalogue_id'] != $p_catalogue_id ) ) {
		topic_error( topic_catalogue_label( $t_catalogue ) . ' is retired and cannot be adopted.' );
	}

	db_param_push();
	if( $t_row === null ) {
		db_query( 'INSERT INTO {topic_project} ( project_id, catalogue_id, enabled, user_id, date_updated )
			VALUES ( ' . db_param() . ', ' . db_param() . ', 1, ' . db_param() . ', ' . db_param() . ' )',
			array( $p_project_id, $p_catalogue_id, auth_get_current_user_id(), db_now() ) );
	} else {
		db_query( 'UPDATE {topic_project} SET catalogue_id=' . db_param() . ', enabled=1, user_id=' . db_param()
			. ', date_updated=' . db_param() . ' WHERE project_id=' . db_param(),
			array( $p_catalogue_id, auth_get_current_user_id(), db_now(), $p_project_id ) );
	}
	topic_event_log( $p_project_id, 'enable', topic_catalogue_label( $t_catalogue ) );
}

/**
 * Disable Topics on a project. All Topic records are kept.
 *
 * @param integer $p_project_id Project.
 * @return void
 */
function topic_project_disable( int $p_project_id ): void {
	db_param_push();
	db_query( 'UPDATE {topic_project} SET enabled=0, user_id=' . db_param() . ', date_updated=' . db_param()
		. ' WHERE project_id=' . db_param(), array( auth_get_current_user_id(), db_now(), $p_project_id ) );
	topic_event_log( $p_project_id, 'disable' );
}

/**
 * Delete every Topic record of a project (project deletion).
 *
 * @param integer $p_project_id Project.
 * @return void
 */
function topic_project_delete( int $p_project_id ): void {
	# Phase 2 records hang off expectations.
	db_param_push();
	$t_result = db_query( 'SELECT id FROM {topic_expectation} WHERE project_id=' . db_param(), array( $p_project_id ) );
	while( $t_row = db_fetch_array( $t_result ) ) {
		foreach( array( '{topic_evidence}', '{topic_assessment}', '{topic_expectation_revision}' ) as $t_table ) {
			db_param_push();
			db_query( 'DELETE FROM ' . $t_table . ' WHERE expectation_id=' . db_param(), array( (int)$t_row['id'] ) );
		}
	}
	foreach( array( '{dwg_topic}', '{topic_applicability}', '{topic_expectation}', '{topic_milestone}', '{topic_profile}',
		'{topic_snapshot}', '{topic_event}', '{topic_project}' ) as $t_table ) {
		db_param_push();
		db_query( 'DELETE FROM ' . $t_table . ' WHERE project_id=' . db_param(), array( $p_project_id ) );
	}
}

/**
 * The enabled project's adopted catalogue, checking a Topic belongs to it.
 *
 * @param integer $p_project_id Project.
 * @param integer $p_topic_id   Topic, 0 to skip the check.
 * @return array Row of {topic_catalogue}.
 * @throws ClientException
 */
function topic_project_catalogue( int $p_project_id, int $p_topic_id = 0 ): array {
	if( !topic_project_is_enabled( $p_project_id ) ) {
		topic_error( 'Topics are not enabled for this project.' );
	}
	$t_catalogue = topic_catalogue_get( (int)topic_project_get( $p_project_id )['catalogue_id'] );
	if( $p_topic_id != 0 && (int)topic_get( $p_topic_id )['catalogue_id'] != (int)$t_catalogue['id'] ) {
		topic_error( 'that Topic is not in the project\'s catalogue.' );
	}
	return $t_catalogue;
}

# ── Applicability ────────────────────────────────────────────────────────────

/**
 * Localised name of an applicability value.
 *
 * @param integer $p_applicability TOPIC_UNDECIDED, _APPLICABLE or _NOT_APPLICABLE.
 * @return string
 */
function topic_applicability_label( int $p_applicability ): string {
	switch( $p_applicability ) {
		case TOPIC_APPLICABLE:
			return lang_get( 'topic_applicable' );
		case TOPIC_NOT_APPLICABLE:
			return lang_get( 'topic_not_applicable' );
	}
	return lang_get( 'topic_undecided' );
}

/**
 * Current applicability decisions of a project.
 *
 * @param integer $p_project_id Project.
 * @return array topic_id => newest row of {topic_applicability}.
 */
function topic_applicability_current( int $p_project_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_applicability} WHERE project_id=' . db_param() . ' ORDER BY id',
		array( $p_project_id ) );
	$t_current = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_current[(int)$t_row['topic_id']] = $t_row;
	}
	return $t_current;
}

/**
 * Applicability decisions for one Topic of a project, newest first.
 *
 * @param integer $p_project_id Project.
 * @param integer $p_topic_id   Topic.
 * @return array Rows of {topic_applicability}.
 */
function topic_applicability_history( int $p_project_id, int $p_topic_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_applicability} WHERE project_id=' . db_param()
		. ' AND topic_id=' . db_param() . ' ORDER BY id DESC', array( $p_project_id, $p_topic_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Record an applicability decision.
 *
 * Not applicable needs a rationale. A decision identical to the current one
 * is not recorded again.
 *
 * @param integer $p_project_id    Project.
 * @param integer $p_topic_id      Topic of the project's catalogue.
 * @param integer $p_applicability TOPIC_UNDECIDED, _APPLICABLE or _NOT_APPLICABLE.
 * @param string  $p_rationale     Reason; required for Not applicable.
 * @param integer|null $p_expected_decision_id Id of the current decision the
 *                 form was based on (0 = none); a different one is a conflict.
 *                 Null skips the check.
 * @return boolean True when a new decision was recorded.
 * @throws ClientException
 */
function topic_applicability_set( int $p_project_id, int $p_topic_id, int $p_applicability, string $p_rationale, ?int $p_expected_decision_id = null ): bool {
	topic_project_catalogue( $p_project_id, $p_topic_id );
	if( !in_array( $p_applicability, array( TOPIC_UNDECIDED, TOPIC_APPLICABLE, TOPIC_NOT_APPLICABLE ), true ) ) {
		topic_error( 'unknown applicability value ' . $p_applicability . '.' );
	}
	$t_rationale = trim( $p_rationale );
	if( $p_applicability == TOPIC_NOT_APPLICABLE && $t_rationale === '' ) {
		topic_error( 'Not applicable needs a rationale.' );
	}
	if( mb_strlen( $t_rationale ) > TOPIC_RATIONALE_MAX ) {
		topic_error( 'the rationale is longer than ' . TOPIC_RATIONALE_MAX . ' characters.' );
	}

	$t_current = topic_applicability_current( $p_project_id )[$p_topic_id] ?? null;
	if( $p_expected_decision_id !== null && $p_expected_decision_id != ( $t_current === null ? 0 : (int)$t_current['id'] ) ) {
		topic_conflict( 'The applicability decision' );
	}
	$t_current_value = $t_current === null ? TOPIC_UNDECIDED : (int)$t_current['applicability'];
	$t_current_rationale = $t_current === null ? '' : $t_current['rationale'];
	if( $t_current_value == $p_applicability && $t_current_rationale === $t_rationale ) {
		return false;
	}

	db_param_push();
	db_query( 'INSERT INTO {topic_applicability} ( project_id, topic_id, applicability, rationale, user_id, date_decided )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_project_id, $p_topic_id, $p_applicability, $t_rationale, auth_get_current_user_id(), db_now() ) );
	topic_event_log( $p_project_id, 'applicability', topic_get( $p_topic_id )['code'] . ': '
		. topic_applicability_label( $p_applicability ) . ( $t_rationale !== '' ? ' — ' . $t_rationale : '' ) );
	return true;
}

# ── Document tags ────────────────────────────────────────────────────────────

/**
 * Localised name of a tag extent.
 *
 * @param integer $p_extent TOPIC_EXTENT_PART or TOPIC_EXTENT_WHOLE.
 * @return string
 */
function topic_extent_label( int $p_extent ): string {
	return lang_get( $p_extent == TOPIC_EXTENT_WHOLE ? 'topic_extent_whole' : 'topic_extent_part' );
}

/**
 * Validate and normalise a tag's extent and locator.
 *
 * @param integer $p_extent  Extent.
 * @param string  $p_locator Locator.
 * @return string Trimmed locator.
 * @throws ClientException
 */
function topic_dwg_validate( int $p_extent, string $p_locator ): string {
	if( $p_extent != TOPIC_EXTENT_PART && $p_extent != TOPIC_EXTENT_WHOLE ) {
		topic_error( 'unknown extent value ' . $p_extent . '.' );
	}
	$t_locator = trim( $p_locator );
	if( mb_strlen( $t_locator ) > TOPIC_LOCATOR_MAX ) {
		topic_error( 'the sections text is longer than ' . TOPIC_LOCATOR_MAX . ' characters.' );
	}
	return $t_locator;
}

/**
 * History value of a tag: "code|extent|locator".
 *
 * @param string  $p_code    Topic code.
 * @param integer $p_extent  Extent.
 * @param string  $p_locator Locator.
 * @return string
 */
function topic_dwg_history_value( string $p_code, int $p_extent, string $p_locator ): string {
	return $p_code . '|' . $p_extent . '|' . $p_locator;
}

/**
 * Readable form of a tag history value, e.g. "10, whole: §3–5".
 *
 * @param string $p_value Value from topic_dwg_history_value().
 * @return string
 */
function topic_dwg_history_describe( string $p_value ): string {
	$t_parts = explode( '|', $p_value, 3 );
	if( count( $t_parts ) < 2 ) {
		return $p_value;
	}
	$t_text = $t_parts[0] . ', ' . topic_extent_label( (int)$t_parts[1] );
	if( ( $t_parts[2] ?? '' ) !== '' ) {
		$t_text .= ': ' . $t_parts[2];
	}
	return $t_text;
}

/**
 * One tag, with its Topic's code and title.
 *
 * @param integer $p_tag_id Tag.
 * @return array Row of {dwg_topic} plus code, title.
 * @throws ClientException when it does not exist.
 */
function topic_dwg_get( int $p_tag_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT dt.*, t.code, t.title FROM {dwg_topic} dt JOIN {topic} t ON t.id=dt.topic_id
		WHERE dt.id=' . db_param(), array( $p_tag_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		topic_error( 'Topic tag ' . $p_tag_id . ' does not exist.' );
	}
	return $t_row;
}

/**
 * A document's tags in its own project, in Topic display order.
 *
 * @param integer $p_dwg_id Document.
 * @return array Rows of {dwg_topic} plus code, title, parent_id, enabled (Topic).
 */
function topic_dwg_get_for_dwg( int $p_dwg_id ): array {
	$t_project_id = (int)dwg_get_field( $p_dwg_id, 'project_id' );
	db_param_push();
	$t_result = db_query( 'SELECT dt.*, t.code, t.title, t.parent_id, t.enabled AS topic_enabled
		FROM {dwg_topic} dt JOIN {topic} t ON t.id=dt.topic_id
		WHERE dt.dwg_id=' . db_param() . ' AND dt.project_id=' . db_param(), array( $p_dwg_id, $t_project_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[(int)$t_row['topic_id']] = $t_row;
	}
	# Display order of the catalogue.
	$t_ordered = array();
	$t_catalogue = topic_project_get( $t_project_id );
	if( $t_catalogue !== null ) {
		foreach( topic_get_all( (int)$t_catalogue['catalogue_id'] ) as $t_topic_id => $t_topic ) {
			if( isset( $t_rows[$t_topic_id] ) ) {
				$t_ordered[] = $t_rows[$t_topic_id];
				unset( $t_rows[$t_topic_id] );
			}
		}
	}
	return array_merge( $t_ordered, array_values( $t_rows ) );
}

/**
 * Tag a document with a Topic of its project's catalogue.
 *
 * @param integer $p_dwg_id   Document.
 * @param integer $p_topic_id Enabled Topic of the project's catalogue.
 * @param integer $p_extent   TOPIC_EXTENT_PART or TOPIC_EXTENT_WHOLE.
 * @param string  $p_locator  Relevant sections, may be empty.
 * @return integer New tag id.
 * @throws ClientException
 */
function topic_dwg_add( int $p_dwg_id, int $p_topic_id, int $p_extent, string $p_locator = '' ): int {
	if( !dwg_exists( $p_dwg_id ) ) {
		topic_error( 'document ' . $p_dwg_id . ' does not exist.' );
	}
	$t_project_id = (int)dwg_get_field( $p_dwg_id, 'project_id' );
	topic_project_catalogue( $t_project_id, $p_topic_id );
	$t_topic = topic_get( $p_topic_id );
	if( (int)$t_topic['enabled'] != 1 ) {
		topic_error( 'Topic ' . $t_topic['code'] . ' is retired.' );
	}
	$t_locator = topic_dwg_validate( $p_extent, $p_locator );

	db_param_push();
	$t_result = db_query( 'SELECT id FROM {dwg_topic} WHERE project_id=' . db_param() . ' AND dwg_id=' . db_param()
		. ' AND topic_id=' . db_param(), array( $t_project_id, $p_dwg_id, $p_topic_id ) );
	if( db_num_rows( $t_result ) > 0 ) {
		topic_error( 'the document already has Topic ' . $t_topic['code'] . '; change that tag instead.' );
	}

	db_param_push();
	db_query( 'INSERT INTO {dwg_topic} ( project_id, dwg_id, topic_id, extent, locator, user_id, date_added )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ' )',
		array( $t_project_id, $p_dwg_id, $p_topic_id, $p_extent, $t_locator, auth_get_current_user_id(), db_now() ) );
	$t_tag_id = (int)db_insert_id( db_get_table( 'dwg_topic' ) );

	history_dwg_log_event_special( $p_dwg_id, DWG_TOPIC_ATTACHED, '',
		topic_dwg_history_value( $t_topic['code'], $p_extent, $t_locator ) );
	topic_event_log( $t_project_id, 'tag_add', topic_event_document( $p_dwg_id ) . ': '
		. topic_dwg_history_describe( topic_dwg_history_value( $t_topic['code'], $p_extent, $t_locator ) ) );
	return $t_tag_id;
}

/**
 * Change a tag's extent and locator.
 *
 * @param integer $p_tag_id  Tag.
 * @param integer $p_extent  TOPIC_EXTENT_PART or TOPIC_EXTENT_WHOLE.
 * @param string  $p_locator Relevant sections, may be empty.
 * @return boolean True when something changed.
 * @throws ClientException
 */
function topic_dwg_update( int $p_tag_id, int $p_extent, string $p_locator ): bool {
	$t_tag = topic_dwg_get( $p_tag_id );
	$t_locator = topic_dwg_validate( $p_extent, $p_locator );
	if( (int)$t_tag['extent'] == $p_extent && $t_tag['locator'] === $t_locator ) {
		return false;
	}
	db_param_push();
	db_query( 'UPDATE {dwg_topic} SET extent=' . db_param() . ', locator=' . db_param() . ' WHERE id=' . db_param(),
		array( $p_extent, $t_locator, $p_tag_id ) );
	history_dwg_log_event_special( (int)$t_tag['dwg_id'], DWG_TOPIC_UPDATED,
		topic_dwg_history_value( $t_tag['code'], (int)$t_tag['extent'], $t_tag['locator'] ),
		topic_dwg_history_value( $t_tag['code'], $p_extent, $t_locator ) );
	topic_event_log( (int)$t_tag['project_id'], 'tag_change', topic_event_document( (int)$t_tag['dwg_id'] ) . ': '
		. topic_dwg_history_describe( topic_dwg_history_value( $t_tag['code'], $p_extent, $t_locator ) ) );
	return true;
}

/**
 * Remove a tag.
 *
 * @param integer $p_tag_id Tag.
 * @return void
 * @throws ClientException
 */
function topic_dwg_remove( int $p_tag_id ): void {
	$t_tag = topic_dwg_get( $p_tag_id );
	# Evidence needs the tag: drop the document's links to this Topic's expectations.
	require_api( 'topic_expectation_api.php' );
	topic_evidence_remove_for_dwg( (int)$t_tag['dwg_id'], (int)$t_tag['project_id'], (int)$t_tag['topic_id'] );
	db_param_push();
	db_query( 'DELETE FROM {dwg_topic} WHERE id=' . db_param(), array( $p_tag_id ) );
	history_dwg_log_event_special( (int)$t_tag['dwg_id'], DWG_TOPIC_DETACHED,
		topic_dwg_history_value( $t_tag['code'], (int)$t_tag['extent'], $t_tag['locator'] ), '' );
	topic_event_log( (int)$t_tag['project_id'], 'tag_remove', topic_event_document( (int)$t_tag['dwg_id'] ) . ': ' . $t_tag['code'] );
}

/**
 * Delete all tags of a document without history (document deletion).
 *
 * @param integer $p_dwg_id Document.
 * @return void
 */
function topic_dwg_delete_all( int $p_dwg_id ): void {
	require_api( 'topic_expectation_api.php' );
	topic_evidence_remove_for_dwg( $p_dwg_id, 0, 0 );
	db_param_push();
	db_query( 'DELETE FROM {dwg_topic} WHERE dwg_id=' . db_param(), array( $p_dwg_id ) );
}

/**
 * Follow a document moved to another project.
 *
 * Tags of the old project move with the document when the new project has
 * Topics enabled with the same catalogue; otherwise they are removed. Both
 * are recorded in the document's history.
 *
 * @param integer $p_dwg_id         Document.
 * @param integer $p_old_project_id Project it left.
 * @param integer $p_new_project_id Project it joined.
 * @return void
 */
function topic_dwg_move( int $p_dwg_id, int $p_old_project_id, int $p_new_project_id ): void {
	if( $p_old_project_id == $p_new_project_id ) {
		return;
	}
	$t_old = topic_project_get( $p_old_project_id );
	$t_new = topic_project_get( $p_new_project_id );
	$t_keep = $t_old !== null && $t_new !== null && (int)$t_new['enabled'] == 1
		&& (int)$t_old['catalogue_id'] == (int)$t_new['catalogue_id'];

	db_param_push();
	$t_result = db_query( 'SELECT dt.*, t.code FROM {dwg_topic} dt JOIN {topic} t ON t.id=dt.topic_id
		WHERE dt.dwg_id=' . db_param() . ' AND dt.project_id=' . db_param(), array( $p_dwg_id, $p_old_project_id ) );
	require_api( 'topic_expectation_api.php' );
	while( $t_tag = db_fetch_array( $t_result ) ) {
		# Expectations belong to the old project: the document leaves their evidence.
		topic_evidence_remove_for_dwg( $p_dwg_id, $p_old_project_id, (int)$t_tag['topic_id'] );
		$t_value = topic_dwg_history_value( $t_tag['code'], (int)$t_tag['extent'], $t_tag['locator'] );
		if( $t_keep ) {
			db_param_push();
			db_query( 'UPDATE {dwg_topic} SET project_id=' . db_param() . ' WHERE id=' . db_param(),
				array( $p_new_project_id, (int)$t_tag['id'] ) );
			topic_event_log( $p_old_project_id, 'tag_moved_out', topic_event_document( $p_dwg_id ) . ': ' . $t_tag['code'] );
			topic_event_log( $p_new_project_id, 'tag_moved_in', topic_event_document( $p_dwg_id ) . ': ' . topic_dwg_history_describe( $t_value ) );
		} else {
			db_param_push();
			db_query( 'DELETE FROM {dwg_topic} WHERE id=' . db_param(), array( (int)$t_tag['id'] ) );
			history_dwg_log_event_special( $p_dwg_id, DWG_TOPIC_DETACHED, $t_value, '' );
			topic_event_log( $p_old_project_id, 'tag_moved_out', topic_event_document( $p_dwg_id ) . ': ' . $t_tag['code'] );
		}
	}
}

# ── Coverage ─────────────────────────────────────────────────────────────────

/**
 * Localised name of a coverage state.
 *
 * @param string $p_state TOPIC_STATE_* value.
 * @return string
 */
function topic_state_label( string $p_state ): string {
	return lang_get( 'topic_state_' . $p_state );
}

/**
 * Coverage state of a Topic from its own counted tags.
 *
 * @param integer $p_applicability Current applicability.
 * @param array   $p_extents       Extents of the counted tags.
 * @return string TOPIC_STATE_* value.
 */
function topic_coverage_state( int $p_applicability, array $p_extents ): string {
	if( $p_applicability == TOPIC_NOT_APPLICABLE ) {
		return TOPIC_STATE_NOT_APPLICABLE;
	}
	if( !$p_extents ) {
		return TOPIC_STATE_NONE;
	}
	return in_array( TOPIC_EXTENT_WHOLE, array_map( 'intval', $p_extents ), true )
		? TOPIC_STATE_COMPLETE : TOPIC_STATE_PARTIAL;
}

/**
 * A project's documents with what the Topic views show of them.
 *
 * @param integer $p_project_id Project.
 * @return array dwg_id => id, number, title, status.
 */
function topic_project_documents( int $p_project_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT d.id, d.status, doc.number, doc.title FROM {dwg} d
		JOIN {documents} doc ON doc.id=d.document_id
		WHERE d.project_id=' . db_param() . ' ORDER BY doc.number, doc.title, d.id', array( $p_project_id ) );
	$t_documents = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_documents[(int)$t_row['id']] = $t_row;
	}
	return $t_documents;
}

/**
 * Everything the project Topics page shows, for one user.
 *
 * Tagged documents the user cannot see are left out of 'tags'; the Topic's
 * 'restricted' flag says some exist. Coverage counts every current tag
 * (including restricted ones) on a document whose status is not in
 * $g_topic_inactive_status, so the state is the same for every reader.
 *
 * @param integer      $p_project_id Project with Topics enabled.
 * @param integer|null $p_user_id    Reader, default current.
 * @return array catalogue, topics (id => topic row + applicability,
 *               decision, tags, restricted, state), counts (state => n),
 *               applicability_counts, untagged (visible documents), restricted.
 * @throws ClientException
 */
function topic_project_view( int $p_project_id, ?int $p_user_id = null ): array {
	$t_catalogue = topic_project_catalogue( $p_project_id );
	$t_topics = topic_get_all( (int)$t_catalogue['id'] );
	$t_decisions = topic_applicability_current( $p_project_id );
	$t_documents = topic_project_documents( $p_project_id );
	$t_inactive = array_map( 'intval', (array)config_get( 'topic_inactive_status', null, $p_user_id, $p_project_id ) );

	if( $t_documents ) {
		dwg_cache_array_rows( array_keys( $t_documents ) );
	}
	$t_visible = array();
	foreach( $t_documents as $t_dwg_id => $t_document ) {
		$t_visible[$t_dwg_id] = topic_can_see_dwg( $t_dwg_id, $p_user_id );
	}

	db_param_push();
	$t_result = db_query( 'SELECT * FROM {dwg_topic} WHERE project_id=' . db_param() . ' ORDER BY id', array( $p_project_id ) );
	$t_tagged = array();
	foreach( $t_topics as $t_topic_id => $t_topic ) {
		$t_topics[$t_topic_id]['tags'] = array();
		$t_topics[$t_topic_id]['extents'] = array();
		$t_topics[$t_topic_id]['restricted'] = false;
	}
	while( $t_tag = db_fetch_array( $t_result ) ) {
		$t_topic_id = (int)$t_tag['topic_id'];
		$t_dwg_id = (int)$t_tag['dwg_id'];
		if( !isset( $t_topics[$t_topic_id] ) || !isset( $t_documents[$t_dwg_id] ) ) {
			continue;
		}
		$t_tagged[$t_dwg_id] = true;
		$t_tag['inactive'] = in_array( (int)$t_documents[$t_dwg_id]['status'], $t_inactive, true );
		if( !$t_tag['inactive'] ) {
			$t_topics[$t_topic_id]['extents'][] = (int)$t_tag['extent'];
		}
		if( $t_visible[$t_dwg_id] ) {
			$t_tag['document'] = $t_documents[$t_dwg_id];
			$t_topics[$t_topic_id]['tags'][] = $t_tag;
		} else {
			$t_topics[$t_topic_id]['restricted'] = true;
		}
	}

	$t_counts = array_fill_keys( array( TOPIC_STATE_COMPLETE, TOPIC_STATE_PARTIAL, TOPIC_STATE_NONE, TOPIC_STATE_NOT_APPLICABLE ), 0 );
	$t_applicability_counts = array_fill_keys( array( TOPIC_APPLICABLE, TOPIC_NOT_APPLICABLE, TOPIC_UNDECIDED ), 0 );
	$t_restricted = false;
	foreach( $t_topics as $t_topic_id => $t_topic ) {
		$t_decision = $t_decisions[$t_topic_id] ?? null;
		$t_applicability = $t_decision === null ? TOPIC_UNDECIDED : (int)$t_decision['applicability'];
		$t_topics[$t_topic_id]['applicability'] = $t_applicability;
		$t_topics[$t_topic_id]['decision'] = $t_decision;
		$t_topics[$t_topic_id]['state'] = topic_coverage_state( $t_applicability, $t_topic['extents'] );
		# Retired Topics without tags or a decision are history only.
		if( (int)$t_topic['enabled'] != 1 && !$t_topic['extents'] && !$t_topic['tags'] && !$t_topic['restricted'] && $t_decision === null ) {
			unset( $t_topics[$t_topic_id] );
			continue;
		}
		$t_counts[$t_topics[$t_topic_id]['state']]++;
		$t_applicability_counts[$t_applicability]++;
		$t_restricted = $t_restricted || $t_topic['restricted'];
	}

	$t_untagged = array();
	foreach( $t_documents as $t_dwg_id => $t_document ) {
		if( !isset( $t_tagged[$t_dwg_id] ) && $t_visible[$t_dwg_id] ) {
			$t_untagged[$t_dwg_id] = $t_document;
		}
	}

	return array(
		'catalogue' => $t_catalogue,
		'topics' => $t_topics,
		'counts' => $t_counts,
		'applicability_counts' => $t_applicability_counts,
		'untagged' => $t_untagged,
		'restricted' => $t_restricted,
	);
}

/**
 * Display text for a document in Topic views: "<number> <title>", falling
 * back to the formatted document id.
 *
 * @param array $p_document Row from topic_project_documents().
 * @return string Plain text.
 */
function topic_document_label( array $p_document ): string {
	$t_label = trim( $p_document['number'] . ' ' . $p_document['title'] );
	return $t_label !== '' ? $t_label : dwg_format_id( (int)$p_document['id'] );
}

# ── Display helpers ──────────────────────────────────────────────────────────

/**
 * Print <option>s for the enabled Topics of a catalogue, children indented.
 *
 * @param integer $p_catalogue_id Catalogue.
 * @param array   $p_exclude_ids  Topic ids to leave out (e.g. already tagged).
 * @return integer Number of options printed.
 */
function print_topic_option_list( int $p_catalogue_id, array $p_exclude_ids = array() ): int {
	$t_count = 0;
	foreach( topic_get_all( $p_catalogue_id, true ) as $t_topic_id => $t_topic ) {
		if( in_array( $t_topic_id, $p_exclude_ids ) ) {
			continue;
		}
		echo '<option value="' . (int)$t_topic_id . '" title="' . string_attribute( $t_topic['description'] ) . '">'
			. ( $t_topic['level'] ? '&nbsp;&nbsp;&nbsp;' : '' ) . string_display_line( topic_label( $t_topic ) ) . '</option>';
		$t_count++;
	}
	return $t_count;
}

/**
 * Print a <select> of tag extents.
 *
 * @param string  $p_name     Field name.
 * @param integer $p_selected Selected extent.
 * @param string  $p_id       Element id, '' for none.
 * @return void
 */
function print_topic_extent_select( string $p_name, int $p_selected = TOPIC_EXTENT_PART, string $p_id = '' ): void {
	echo '<select name="' . string_attribute( $p_name ) . '" class="input-sm"'
		. ( $p_id !== '' ? ' id="' . string_attribute( $p_id ) . '"' : '' ) . '>';
	foreach( array( TOPIC_EXTENT_PART, TOPIC_EXTENT_WHOLE ) as $t_extent ) {
		echo '<option value="' . $t_extent . '"' . ( $t_extent == $p_selected ? ' selected="selected"' : '' ) . '>'
			. topic_extent_label( $t_extent ) . '</option>';
	}
	echo '</select>';
}

/**
 * HTML label for a coverage state: text plus colour.
 *
 * @param string $p_state TOPIC_STATE_* value.
 * @return string HTML.
 */
function topic_state_html( string $p_state ): string {
	static $s_class = array(
		TOPIC_STATE_COMPLETE => 'label-success',
		TOPIC_STATE_PARTIAL => 'label-warning',
		TOPIC_STATE_NONE => 'label-danger',
		TOPIC_STATE_NOT_APPLICABLE => 'label-grey',
	);
	return '<span class="label ' . ( $s_class[$p_state] ?? 'label-default' ) . '">'
		. string_display_line( topic_state_label( $p_state ) ) . '</span>';
}

/**
 * HTML for one tagged document in Topic views: link, status, extent, sections.
 * Tags on inactive documents (e.g. archived) are struck through.
 *
 * @param array $p_tag Tag row with 'document' and 'inactive' (topic_project_view()).
 * @return string HTML.
 */
function topic_tag_html( array $p_tag ): string {
	$t_document = $p_tag['document'];
	$t_html = string_get_dwg_view_title_link( (int)$t_document['id'],
		string_display_line( topic_document_label( $t_document ) ), false )
		. ' <span class="grey">(' . string_display_line( get_enum_element( 'dwg_status', (int)$t_document['status'] ) ) . ')</span>'
		. ' &mdash; ' . topic_extent_label( (int)$p_tag['extent'] )
		. ( $p_tag['locator'] !== '' ? ': ' . string_display_line( $p_tag['locator'] ) : '' );
	return $p_tag['inactive'] ? '<del title="' . string_attribute( lang_get( 'topic_inactive_document' ) ) . '">' . $t_html . '</del>' : $t_html;
}

/**
 * Redirect after a tag action, to where the form was: the project Topics page
 * ('topics'), a Topic's detail page ('detail', with topic_id) or the document
 * (default).
 *
 * @param integer $p_dwg_id Document acted on.
 * @return void Does not return.
 */
function dwg_topic_redirect( int $p_dwg_id ): void {
	$t_project_id = (int)dwg_get_field( $p_dwg_id, 'project_id' );
	switch( gpc_get_string( 'return', 'view' ) ) {
		case 'topics':
			print_header_redirect( 'topic_page.php?project_id=' . $t_project_id );
			break;
		case 'detail':
			print_header_redirect( 'topic_detail_page.php?project_id=' . $t_project_id
				. '&topic_id=' . gpc_get_int( 'topic_id' ) );
			break;
		default:
			print_header_redirect( string_get_dwg_view_url( $p_dwg_id ) . '#topics' );
	}
}

/**
 * Whether the sidebar shows "Topics" for the current project: always for
 * All Projects (the page asks for a project), otherwise when the project has
 * Topics enabled and the user may view them, or the user may enable them.
 *
 * @param integer $p_project_id Current project.
 * @return boolean
 */
function topic_sidebar_visible( int $p_project_id ): bool {
	if( !topic_feature_enabled() ) {
		return false;
	}
	if( $p_project_id == ALL_PROJECTS ) {
		return true;
	}
	return ( topic_project_is_enabled( $p_project_id ) && topic_can_view_project( $p_project_id ) )
		|| topic_can_manage_project( $p_project_id );
}

# ── Document list ────────────────────────────────────────────────────────────

/**
 * Load the tags of many documents (in their own projects) in one query, for
 * the document list's Topics column.
 *
 * @param array $p_dwg_ids Document ids.
 * @return void
 */
function topic_dwg_cache_tags( array $p_dwg_ids ): void {
	global $g_cache_topic_dwg_tags;
	$t_ids = array();
	foreach( $p_dwg_ids as $t_id ) {
		$t_id = (int)$t_id;
		if( $t_id > 0 && !isset( $g_cache_topic_dwg_tags[$t_id] ) ) {
			$t_ids[] = $t_id;
			$g_cache_topic_dwg_tags[$t_id] = array();
		}
	}
	if( !$t_ids ) {
		return;
	}
	$t_result = db_query( 'SELECT dt.dwg_id, dt.extent, dt.locator, t.code, t.title, t.sort_order, t.parent_id
		FROM {dwg_topic} dt
		JOIN {dwg} d ON d.id=dt.dwg_id AND d.project_id=dt.project_id
		JOIN {topic} t ON t.id=dt.topic_id
		WHERE dt.dwg_id IN (' . implode( ',', $t_ids ) . ')
		ORDER BY t.sort_order, t.code' );
	while( $t_row = db_fetch_array( $t_result ) ) {
		$g_cache_topic_dwg_tags[(int)$t_row['dwg_id']][] = $t_row;
	}
}

/**
 * HTML of a document's Topic codes for the document list: one code per tag,
 * whole-Topic tags in bold, the full description as a tooltip. Empty when
 * the project does not use Topics or the user may not see them.
 *
 * @param integer $p_dwg_id     Document.
 * @param integer $p_project_id Its project.
 * @return string HTML.
 */
function topic_dwg_codes_html( int $p_dwg_id, int $p_project_id ): string {
	global $g_cache_topic_dwg_tags;
	if( !topic_project_is_enabled( $p_project_id ) || !topic_can_view_project( $p_project_id ) ) {
		return '';
	}
	if( !isset( $g_cache_topic_dwg_tags[$p_dwg_id] ) ) {
		topic_dwg_cache_tags( array( $p_dwg_id ) );
	}
	$t_codes = array();
	foreach( $g_cache_topic_dwg_tags[$p_dwg_id] as $t_tag ) {
		$t_whole = (int)$t_tag['extent'] == TOPIC_EXTENT_WHOLE;
		$t_tip = $t_tag['code'] . ' ' . $t_tag['title'] . ' — ' . topic_extent_label( (int)$t_tag['extent'] )
			. ( $t_tag['locator'] !== '' ? ': ' . $t_tag['locator'] : '' );
		$t_code = string_display_line( $t_tag['code'] );
		$t_codes[] = '<span title="' . string_attribute( $t_tip ) . '">' . ( $t_whole ? '<strong>' . $t_code . '</strong>' : $t_code ) . '</span>';
	}
	return implode( ' ', $t_codes );
}

/**
 * Topic codes on offer where a code (not an id) is chosen, e.g. the document
 * list filter and bulk tagging, which may span projects: the current
 * project's catalogue, or with All Projects every catalogue in use. The first
 * catalogue wins a code's title.
 *
 * @param integer $p_project_id Current project.
 * @return array code => array( 'title' => string, 'children' => codes[] ), in catalogue order.
 */
function topic_code_options( int $p_project_id ): array {
	if( $p_project_id != ALL_PROJECTS && topic_project_is_enabled( $p_project_id ) ) {
		$t_catalogue_ids = array( (int)topic_project_get( $p_project_id )['catalogue_id'] );
	} else {
		$t_catalogue_ids = array();
		$t_result = db_query( 'SELECT DISTINCT catalogue_id FROM {topic_project} WHERE enabled=1 ORDER BY catalogue_id' );
		while( $t_row = db_fetch_array( $t_result ) ) {
			$t_catalogue_ids[] = (int)$t_row['catalogue_id'];
		}
	}

	$t_options = array();
	foreach( $t_catalogue_ids as $t_catalogue_id ) {
		$t_topics = topic_get_all( $t_catalogue_id, true );
		foreach( $t_topics as $t_topic ) {
			$t_code = $t_topic['code'];
			if( !isset( $t_options[$t_code] ) ) {
				$t_options[$t_code] = array( 'title' => $t_topic['title'], 'children' => array() );
			}
		}
		foreach( $t_topics as $t_topic ) {
			$t_parent_id = (int)$t_topic['parent_id'];
			if( $t_parent_id != 0 && isset( $t_topics[$t_parent_id] ) ) {
				$t_parent_code = $t_topics[$t_parent_id]['code'];
				if( !in_array( $t_topic['code'], $t_options[$t_parent_code]['children'], true ) ) {
					$t_options[$t_parent_code]['children'][] = $t_topic['code'];
				}
			}
		}
	}
	return $t_options;
}

/**
 * The enabled Topic with a given code in a project's catalogue.
 *
 * @param integer $p_project_id Project with Topics enabled.
 * @param string  $p_code       Topic code.
 * @return array|null Row of {topic}, null when the catalogue has no such enabled Topic.
 */
function topic_get_by_code( int $p_project_id, string $p_code ): ?array {
	$t_row = topic_project_get( $p_project_id );
	if( $t_row === null ) {
		return null;
	}
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic} WHERE catalogue_id=' . db_param() . ' AND code=' . db_param() . ' AND enabled=1',
		array( (int)$t_row['catalogue_id'], $p_code ) );
	$t_topic = db_fetch_array( $t_result );
	return $t_topic ?: null;
}

# ── Document create form ─────────────────────────────────────────────────────

/**
 * Whether the create form offers Topics for a project: Topics enabled there
 * and the user may tag its documents.
 *
 * @param integer $p_project_id Project of the new document.
 * @return boolean
 */
function topic_create_form_enabled( int $p_project_id ): bool {
	return topic_project_is_enabled( $p_project_id )
		&& access_has_project_level( config_get( 'topic_tag_threshold', null, null, $p_project_id ), $p_project_id );
}

/**
 * The Topics chosen on the document create form, validated before the
 * document is created so a bad choice cannot leave a half-tagged document.
 *
 * Form fields: topic_ids[] (ticked Topic ids), topic_extent[<id>],
 * topic_locator[<id>].
 *
 * @param integer $p_project_id Project of the new document.
 * @return array Per tag: topic_id, extent, locator. Empty when none chosen.
 * @throws ClientException
 */
function topic_create_form_tags( int $p_project_id ): array {
	$t_ids = array_unique( gpc_get_int_array( 'topic_ids', array() ) );
	if( !$t_ids ) {
		return array();
	}
	if( !topic_create_form_enabled( $p_project_id ) ) {
		topic_error( 'you cannot tag documents of this project.' );
	}
	$t_extents = gpc_get_int_array( 'topic_extent', array() );
	$t_locators = gpc_get_string_array( 'topic_locator', array() );
	$t_tags = array();
	foreach( $t_ids as $t_topic_id ) {
		topic_project_catalogue( $p_project_id, $t_topic_id );
		$t_topic = topic_get( $t_topic_id );
		if( (int)$t_topic['enabled'] != 1 ) {
			topic_error( 'Topic ' . $t_topic['code'] . ' is retired.' );
		}
		$t_extent = (int)( $t_extents[$t_topic_id] ?? TOPIC_EXTENT_PART );
		$t_tags[] = array(
			'topic_id' => $t_topic_id,
			'extent' => $t_extent,
			'locator' => topic_dwg_validate( $t_extent, (string)( $t_locators[$t_topic_id] ?? '' ) ),
		);
	}
	return $t_tags;
}

# ── Export ───────────────────────────────────────────────────────────────────

/**
 * Rows of a project's Topics for export (CSV and print), from the same view
 * and permissions as the Topics page: one row per tagged document visible to
 * the reader, one "restricted" row per Topic with hidden documents, and one
 * row for a Topic without documents.
 *
 * @param array $p_view Result of topic_project_view().
 * @return array Rows: code, topic, parent, required, rationale, coverage,
 *               document, title, status, extent, sections, inactive.
 */
function topic_export_rows( array $p_view ): array {
	$t_codes = array();
	foreach( $p_view['topics'] as $t_topic_id => $t_topic ) {
		$t_codes[$t_topic_id] = $t_topic['code'];
	}
	$t_rows = array();
	foreach( $p_view['topics'] as $t_topic ) {
		$t_base = array(
			'code' => $t_topic['code'],
			'topic' => $t_topic['title'] . ( (int)$t_topic['enabled'] != 1 ? ' (' . lang_get( 'topic_retired' ) . ')' : '' ),
			'parent' => $t_codes[(int)$t_topic['parent_id']] ?? '',
			'required' => topic_applicability_label( (int)$t_topic['applicability'] ),
			'rationale' => $t_topic['decision']['rationale'] ?? '',
			'coverage' => topic_state_label( $t_topic['state'] ),
			'document' => '', 'title' => '', 'status' => '', 'extent' => '', 'sections' => '', 'inactive' => false,
		);
		foreach( $t_topic['tags'] as $t_tag ) {
			$t_document = $t_tag['document'];
			$t_rows[] = array_merge( $t_base, array(
				'document' => $t_document['number'] !== '' ? $t_document['number'] : dwg_format_id( (int)$t_document['id'] ),
				'title' => $t_document['title'],
				'status' => get_enum_element( 'dwg_status', (int)$t_document['status'] ),
				'extent' => topic_extent_label( (int)$t_tag['extent'] ),
				'sections' => $t_tag['locator'],
				'inactive' => $t_tag['inactive'],
			) );
		}
		if( $t_topic['restricted'] ) {
			$t_rows[] = array_merge( $t_base, array( 'document' => lang_get( 'topic_restricted_short' ) ) );
		}
		if( !$t_topic['tags'] && !$t_topic['restricted'] ) {
			$t_rows[] = $t_base;
		}
	}
	return $t_rows;
}

# ── Activity log ─────────────────────────────────────────────────────────────

/**
 * Record a project-level Topics action ({topic_event}).
 *
 * @param integer $p_project_id Project.
 * @param string  $p_action     Action key (topic_event_<action> is its label).
 * @param string  $p_detail     What it concerned: codes, references, text.
 * @return void
 */
function topic_event_log( int $p_project_id, string $p_action, string $p_detail = '' ): void {
	db_param_push();
	db_query( 'INSERT INTO {topic_event} ( project_id, action, detail, user_id, date_event )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_project_id, $p_action, mb_substr( $p_detail, 0, 1024 ), auth_get_current_user_id(), db_now() ) );
}

/**
 * A project's Topics activity, newest first.
 *
 * @param integer $p_project_id Project.
 * @param integer $p_limit      Rows.
 * @param integer $p_offset     Rows to skip.
 * @return array Rows of {topic_event}.
 */
function topic_event_get( int $p_project_id, int $p_limit = 100, int $p_offset = 0 ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_event} WHERE project_id=' . db_param() . ' ORDER BY id DESC',
		array( $p_project_id ), $p_limit, $p_offset );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Number of a project's Topics activity rows.
 *
 * @param integer $p_project_id Project.
 * @return integer
 */
function topic_event_count( int $p_project_id ): int {
	db_param_push();
	return (int)db_result( db_query( 'SELECT COUNT(*) FROM {topic_event} WHERE project_id=' . db_param(), array( $p_project_id ) ) );
}

/**
 * Localised name of an activity action.
 *
 * @param string $p_action Action key.
 * @return string
 */
function topic_event_label( string $p_action ): string {
	return lang_get_defaulted( 'topic_event_' . $p_action, $p_action );
}

/**
 * Short text identifying a tagged document in the activity log.
 *
 * @param integer $p_dwg_id Document.
 * @return string
 */
function topic_event_document( int $p_dwg_id ): string {
	$t_number = (string)dwg_get_field( $p_dwg_id, 'number' );
	return ( $t_number !== '' ? $t_number : dwg_format_id( $p_dwg_id ) ) . ' (#' . $p_dwg_id . ')';
}

/**
 * Stop with a conflict error: someone changed the record after the page was
 * opened (TOP-FR-028).
 *
 * @param string $p_what What changed.
 * @return never
 * @throws ClientException
 */
function topic_conflict( string $p_what ) {
	topic_error( $p_what . ' was changed by someone else after you opened this page. Reload the page, check the change and try again.' );
}
