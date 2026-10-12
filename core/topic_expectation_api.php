<?php
# Doctis - Document Issue Tracking System
#
# Documentation Topics, phase 2 (doc/topics/TOPICS_PLAN.md §7): milestones,
# expectations, evidence, adequacy assessments, coverage states and profile
# authorisation.
#
# An expectation is information a project requires for a Topic, due from a
# milestone at a required maturity. Documents tagged with the Topic are linked
# to it as evidence; an authorised assessor judges the evidence set Adequate
# or Gap. The assessment freezes each evidence document's version (On Record
# commit, or edition/revision without a primary file), so a later change to
# the evidence or the expectation makes it stale: "Reassessment required".
#
# States per expectation at a selected milestone (STD-IT-001 §6.1), in order:
# Not due, Reassessment required, Missing, Gap, Pending, Satisfied. A Topic is
# Satisfied when all its due expectations are; parent and child Topics never
# affect each other. Nothing here approves documents or releases products.

use Mantis\Exceptions\ClientException;

require_api( 'file_dwg_api.php' );
require_api( 'topic_api.php' );
require_api( 'user_api.php' );

/**
 * Expectation states.
 */
const TOPIC_EXP_NOT_DUE = 'not_due';
const TOPIC_EXP_REASSESS = 'reassess';
const TOPIC_EXP_MISSING = 'missing';
const TOPIC_EXP_GAP = 'gap';
const TOPIC_EXP_PENDING = 'pending';
const TOPIC_EXP_SATISFIED = 'satisfied';

/**
 * Topic states from expectations (besides TOPIC_EXP_NOT_DUE/SATISFIED and
 * TOPIC_STATE_NOT_APPLICABLE).
 */
const TOPIC_EXP_INCOMPLETE = 'incomplete';
const TOPIC_EXP_NONE = 'no_expectations';

/**
 * Project documentation readiness at a milestone.
 */
const TOPIC_READY = 'ready';
const TOPIC_NOT_READY = 'not_ready';
const TOPIC_NOTHING_DUE = 'nothing_due';
const TOPIC_PROFILE_UNRESOLVED = 'profile_unresolved';

/**
 * Longest expectation statement and assessment rationale.
 */
const TOPIC_STATEMENT_MAX = 1024;
const TOPIC_ASSESSMENT_RATIONALE_MAX = 4000;

# ── Milestones ───────────────────────────────────────────────────────────────

/**
 * Default milestones (STD-ENG-004 §6).
 *
 * @return array Each: name, description.
 */
function topic_milestone_defaults(): array {
	return array(
		array( 'Planning / ECP', 'Established Topic profile; concept, requirements, architecture, risks, V&V strategy and planned deliverables sufficient for the commitment' ),
		array( 'SRR / PDR', 'Requirements and architecture evidence for the functional/allocated baseline; safety, interface and support requirements identified' ),
		array( 'CDR', 'Detailed design and interfaces for the design baseline; risk controls and test/production/support plans developed' ),
		array( 'TRR', 'Approved procedures and acceptance criteria, configuration and equipment readiness, traceability and trial safety basis' ),
		array( 'Release / Incorporation Approval', 'Approved design, V&V and validation evidence; deviations resolved or authorised; operations, support, surveillance and retirement planning' ),
		array( 'In-service review', 'Monitoring and service records, configuration changes and resulting evidence updates' ),
		array( 'Retirement / closure', 'Retirement instructions, execution/closure records, data disposition and archival references' ),
	);
}

/**
 * A project's milestones in order, each with 'position' (0 = first).
 *
 * @param integer $p_project_id Project.
 * @return array id => row of {topic_milestone}.
 */
function topic_milestone_get_all( int $p_project_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_milestone} WHERE project_id=' . db_param() . ' ORDER BY sort_order, id',
		array( $p_project_id ) );
	$t_rows = array();
	$t_position = 0;
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_row['position'] = $t_position++;
		$t_rows[(int)$t_row['id']] = $t_row;
	}
	return $t_rows;
}

/**
 * One milestone.
 *
 * @param integer $p_milestone_id Milestone.
 * @return array Row of {topic_milestone}.
 * @throws ClientException
 */
function topic_milestone_get( int $p_milestone_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_milestone} WHERE id=' . db_param(), array( $p_milestone_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		topic_error( 'milestone ' . $p_milestone_id . ' does not exist.' );
	}
	return $t_row;
}

/**
 * Validate a milestone name and description.
 *
 * @param string $p_name        Name.
 * @param string $p_description Description.
 * @return void
 * @throws ClientException
 */
function topic_milestone_validate( string $p_name, string $p_description ): void {
	if( $p_name === '' || mb_strlen( $p_name ) > 64 ) {
		topic_error( 'a milestone needs a name of at most 64 characters.' );
	}
	if( mb_strlen( $p_description ) > 255 ) {
		topic_error( 'the milestone description is longer than 255 characters.' );
	}
}

/**
 * Add a milestone to a project that uses Topics.
 *
 * @param integer      $p_project_id  Project.
 * @param string       $p_name        Name, e.g. "CDR".
 * @param string       $p_description What documentation it needs.
 * @param integer|null $p_sort_order  Order; null puts it last.
 * @return integer New milestone id.
 * @throws ClientException
 */
function topic_milestone_create( int $p_project_id, string $p_name, string $p_description = '', ?int $p_sort_order = null ): int {
	topic_project_catalogue( $p_project_id );
	$t_name = trim( $p_name );
	$t_description = trim( $p_description );
	topic_milestone_validate( $t_name, $t_description );
	if( $p_sort_order === null ) {
		$t_milestones = topic_milestone_get_all( $p_project_id );
		$t_last = end( $t_milestones );
		$p_sort_order = $t_last ? (int)$t_last['sort_order'] + 10 : 10;
	}
	db_param_push();
	db_query( 'INSERT INTO {topic_milestone} ( project_id, name, description, sort_order, date_achieved, user_id, date_updated )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', 0, ' . db_param() . ', ' . db_param() . ' )',
		array( $p_project_id, $t_name, $t_description, $p_sort_order, auth_get_current_user_id(), db_now() ) );
	return (int)db_insert_id( db_get_table( 'topic_milestone' ) );
}

/**
 * Load the default milestones into a project that has none.
 *
 * @param integer $p_project_id Project.
 * @return void
 * @throws ClientException
 */
function topic_milestone_load_defaults( int $p_project_id ): void {
	if( topic_milestone_get_all( $p_project_id ) ) {
		topic_error( 'the project already has milestones.' );
	}
	$t_order = 0;
	foreach( topic_milestone_defaults() as $t_default ) {
		$t_order += 10;
		topic_milestone_create( $p_project_id, $t_default[0], $t_default[1], $t_order );
	}
}

/**
 * Change a milestone's name, description and order.
 *
 * @param integer $p_milestone_id Milestone.
 * @param string  $p_name         Name.
 * @param string  $p_description  Description.
 * @param integer $p_sort_order   Order.
 * @return void
 * @throws ClientException
 */
function topic_milestone_update( int $p_milestone_id, string $p_name, string $p_description, int $p_sort_order ): void {
	topic_milestone_get( $p_milestone_id );
	$t_name = trim( $p_name );
	$t_description = trim( $p_description );
	topic_milestone_validate( $t_name, $t_description );
	db_param_push();
	db_query( 'UPDATE {topic_milestone} SET name=' . db_param() . ', description=' . db_param() . ', sort_order=' . db_param()
		. ', user_id=' . db_param() . ', date_updated=' . db_param() . ' WHERE id=' . db_param(),
		array( $t_name, $t_description, $p_sort_order, auth_get_current_user_id(), db_now(), $p_milestone_id ) );
}

/**
 * Mark a milestone achieved (today) or not achieved.
 *
 * @param integer $p_milestone_id Milestone.
 * @param boolean $p_achieved     Achieved.
 * @return void
 * @throws ClientException
 */
function topic_milestone_set_achieved( int $p_milestone_id, bool $p_achieved ): void {
	topic_milestone_get( $p_milestone_id );
	db_param_push();
	db_query( 'UPDATE {topic_milestone} SET date_achieved=' . db_param() . ', user_id=' . db_param()
		. ', date_updated=' . db_param() . ' WHERE id=' . db_param(),
		array( $p_achieved ? db_now() : 0, auth_get_current_user_id(), db_now(), $p_milestone_id ) );
}

/**
 * Delete a milestone no expectation or assessment refers to.
 *
 * @param integer $p_milestone_id Milestone.
 * @return void
 * @throws ClientException
 */
function topic_milestone_delete( int $p_milestone_id ): void {
	$t_milestone = topic_milestone_get( $p_milestone_id );
	foreach( array( '{topic_expectation}', '{topic_expectation_revision}', '{topic_assessment}' ) as $t_table ) {
		db_param_push();
		$t_result = db_query( 'SELECT COUNT(*) FROM ' . $t_table . ' WHERE milestone_id=' . db_param(), array( $p_milestone_id ) );
		if( (int)db_result( $t_result ) > 0 ) {
			topic_error( 'milestone "' . $t_milestone['name'] . '" is used by expectations or assessments.' );
		}
	}
	db_param_push();
	db_query( 'DELETE FROM {topic_milestone} WHERE id=' . db_param(), array( $p_milestone_id ) );
}

/**
 * The milestone a project is working towards: the first not achieved, or the
 * last when all are achieved.
 *
 * @param array $p_milestones Result of topic_milestone_get_all().
 * @return integer Milestone id, 0 when there are none.
 */
function topic_milestone_current( array $p_milestones ): int {
	$t_last = 0;
	foreach( $p_milestones as $t_id => $t_milestone ) {
		if( (int)$t_milestone['date_achieved'] == 0 ) {
			return (int)$t_id;
		}
		$t_last = (int)$t_id;
	}
	return $t_last;
}

# ── Expectations ─────────────────────────────────────────────────────────────

/**
 * Localised name of a maturity level.
 *
 * @param integer $p_maturity TOPIC_MATURITY_* value.
 * @return string
 */
function topic_maturity_label( int $p_maturity ): string {
	switch( $p_maturity ) {
		case TOPIC_MATURITY_APPROVED:
			return lang_get( 'topic_maturity_approved' );
		case TOPIC_MATURITY_UNDER_REVIEW:
			return lang_get( 'topic_maturity_under_review' );
	}
	return lang_get( 'topic_maturity_draft' );
}

/**
 * Maturity of a document status ($g_topic_maturity_map).
 *
 * @param integer $p_status     Document status.
 * @param integer $p_project_id Project, for configuration.
 * @return integer|null TOPIC_MATURITY_* value, null when not mapped.
 */
function topic_status_maturity( int $p_status, int $p_project_id ): ?int {
	$t_map = (array)config_get( 'topic_maturity_map', null, null, $p_project_id );
	return isset( $t_map[$p_status] ) ? (int)$t_map[$p_status] : null;
}

/**
 * One expectation.
 *
 * @param integer $p_expectation_id Expectation.
 * @return array Row of {topic_expectation}.
 * @throws ClientException
 */
function topic_expectation_get( int $p_expectation_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_expectation} WHERE id=' . db_param(), array( $p_expectation_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		topic_error( 'expectation ' . $p_expectation_id . ' does not exist.' );
	}
	return $t_row;
}

/**
 * A project's expectations, optionally for one Topic.
 *
 * @param integer $p_project_id   Project.
 * @param integer $p_topic_id     Topic, 0 for all.
 * @param boolean $p_enabled_only Leave out retired expectations.
 * @return array id => row of {topic_expectation}, in creation order.
 */
function topic_expectation_get_all( int $p_project_id, int $p_topic_id = 0, bool $p_enabled_only = true ): array {
	$t_params = array( $p_project_id );
	db_param_push();
	$t_sql = 'SELECT * FROM {topic_expectation} WHERE project_id=' . db_param();
	if( $p_topic_id != 0 ) {
		$t_sql .= ' AND topic_id=' . db_param();
		$t_params[] = $p_topic_id;
	}
	if( $p_enabled_only ) {
		$t_sql .= ' AND enabled=1';
	}
	$t_result = db_query( $t_sql . ' ORDER BY id', $t_params );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[(int)$t_row['id']] = $t_row;
	}
	return $t_rows;
}

/**
 * Validate an expectation's fields for a project.
 *
 * @param integer $p_project_id   Project.
 * @param string  $p_statement    Required information.
 * @param integer $p_owner_id     Owner, 0 = none yet.
 * @param integer $p_milestone_id First milestone it is due at.
 * @param integer $p_maturity     Required maturity.
 * @return void
 * @throws ClientException
 */
function topic_expectation_validate( int $p_project_id, string $p_statement, int $p_owner_id, int $p_milestone_id, int $p_maturity ): void {
	if( $p_statement === '' || mb_strlen( $p_statement ) > TOPIC_STATEMENT_MAX ) {
		topic_error( 'an expectation needs a statement of at most ' . TOPIC_STATEMENT_MAX . ' characters.' );
	}
	if( !in_array( $p_maturity, array( TOPIC_MATURITY_DRAFT, TOPIC_MATURITY_UNDER_REVIEW, TOPIC_MATURITY_APPROVED ), true ) ) {
		topic_error( 'unknown maturity value ' . $p_maturity . '.' );
	}
	if( (int)topic_milestone_get( $p_milestone_id )['project_id'] != $p_project_id ) {
		topic_error( 'that milestone belongs to another project.' );
	}
	if( $p_owner_id != 0 && !user_exists( $p_owner_id ) ) {
		topic_error( 'owner ' . $p_owner_id . ' does not exist.' );
	}
}

/**
 * Append an expectation's current state to its revision history.
 *
 * @param integer $p_expectation_id Expectation.
 * @return void
 */
function topic_expectation_record_revision( int $p_expectation_id ): void {
	$t_row = topic_expectation_get( $p_expectation_id );
	db_param_push();
	db_query( 'INSERT INTO {topic_expectation_revision} ( expectation_id, revision, statement, owner_id, milestone_id, maturity, enabled, user_id, date_changed )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_expectation_id, (int)$t_row['revision'], $t_row['statement'], (int)$t_row['owner_id'], (int)$t_row['milestone_id'],
			(int)$t_row['maturity'], (int)$t_row['enabled'], auth_get_current_user_id(), db_now() ) );
}

/**
 * Add an expectation for a Topic of a project.
 *
 * @param integer $p_project_id   Project with Topics enabled.
 * @param integer $p_topic_id     Topic of the project's catalogue.
 * @param string  $p_statement    Required information.
 * @param integer $p_owner_id     Owner, 0 = none yet.
 * @param integer $p_milestone_id First milestone it is due at.
 * @param integer $p_maturity     Required maturity.
 * @return integer New expectation id.
 * @throws ClientException
 */
function topic_expectation_create( int $p_project_id, int $p_topic_id, string $p_statement, int $p_owner_id, int $p_milestone_id, int $p_maturity ): int {
	topic_project_catalogue( $p_project_id, $p_topic_id );
	$t_statement = trim( $p_statement );
	topic_expectation_validate( $p_project_id, $t_statement, $p_owner_id, $p_milestone_id, $p_maturity );

	db_param_push();
	db_query( 'INSERT INTO {topic_expectation} ( project_id, topic_id, statement, owner_id, milestone_id, maturity, revision, enabled, user_id, date_created, date_updated )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', 1, 1, ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_project_id, $p_topic_id, $t_statement, $p_owner_id, $p_milestone_id, $p_maturity,
			auth_get_current_user_id(), db_now(), db_now() ) );
	$t_id = (int)db_insert_id( db_get_table( 'topic_expectation' ) );
	topic_expectation_record_revision( $t_id );
	return $t_id;
}

/**
 * Change an expectation. Any change makes a new revision, which makes earlier
 * assessments stale.
 *
 * @param integer $p_expectation_id Expectation.
 * @param string  $p_statement      Required information.
 * @param integer $p_owner_id       Owner, 0 = none yet.
 * @param integer $p_milestone_id   First milestone it is due at.
 * @param integer $p_maturity       Required maturity.
 * @return boolean True when something changed.
 * @throws ClientException
 */
function topic_expectation_update( int $p_expectation_id, string $p_statement, int $p_owner_id, int $p_milestone_id, int $p_maturity ): bool {
	$t_row = topic_expectation_get( $p_expectation_id );
	$t_statement = trim( $p_statement );
	topic_expectation_validate( (int)$t_row['project_id'], $t_statement, $p_owner_id, $p_milestone_id, $p_maturity );
	if( $t_row['statement'] === $t_statement && (int)$t_row['owner_id'] == $p_owner_id
		&& (int)$t_row['milestone_id'] == $p_milestone_id && (int)$t_row['maturity'] == $p_maturity ) {
		return false;
	}
	db_param_push();
	db_query( 'UPDATE {topic_expectation} SET statement=' . db_param() . ', owner_id=' . db_param() . ', milestone_id=' . db_param()
		. ', maturity=' . db_param() . ', revision=revision+1, user_id=' . db_param() . ', date_updated=' . db_param()
		. ' WHERE id=' . db_param(),
		array( $t_statement, $p_owner_id, $p_milestone_id, $p_maturity, auth_get_current_user_id(), db_now(), $p_expectation_id ) );
	topic_expectation_record_revision( $p_expectation_id );
	return true;
}

/**
 * Retire or restore an expectation (a new revision either way).
 *
 * @param integer $p_expectation_id Expectation.
 * @param boolean $p_enabled        False to retire, true to restore.
 * @return void
 * @throws ClientException
 */
function topic_expectation_set_enabled( int $p_expectation_id, bool $p_enabled ): void {
	$t_row = topic_expectation_get( $p_expectation_id );
	if( (int)$t_row['enabled'] == ( $p_enabled ? 1 : 0 ) ) {
		return;
	}
	db_param_push();
	db_query( 'UPDATE {topic_expectation} SET enabled=' . db_param() . ', revision=revision+1, user_id=' . db_param()
		. ', date_updated=' . db_param() . ' WHERE id=' . db_param(),
		array( $p_enabled ? 1 : 0, auth_get_current_user_id(), db_now(), $p_expectation_id ) );
	topic_expectation_record_revision( $p_expectation_id );
}

/**
 * An expectation's revisions, newest first.
 *
 * @param integer $p_expectation_id Expectation.
 * @return array Rows of {topic_expectation_revision}.
 */
function topic_expectation_revisions( int $p_expectation_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_expectation_revision} WHERE expectation_id=' . db_param() . ' ORDER BY id DESC',
		array( $p_expectation_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Short reference of an expectation for history and lists: "E<id>".
 *
 * @param array $p_expectation Row of {topic_expectation}.
 * @return string
 */
function topic_expectation_ref( array $p_expectation ): string {
	return 'E' . (int)$p_expectation['id'];
}

# ── Evidence ─────────────────────────────────────────────────────────────────

/**
 * Version token of a document as evidence: its On Record commit, or without
 * a primary file its edition/revision.
 *
 * @param integer $p_dwg_id Document.
 * @return string "sha:<commit>" or "rev:<edition>/<revision>".
 */
function topic_evidence_version( int $p_dwg_id ): string {
	$t_primary = file_dwg_primary_get( $p_dwg_id );
	if( $t_primary !== null && (string)$t_primary['git_sha'] !== '' ) {
		return 'sha:' . $t_primary['git_sha'];
	}
	return 'rev:' . dwg_get_field( $p_dwg_id, 'edition' ) . '/' . dwg_get_field( $p_dwg_id, 'revision' );
}

/**
 * Short form of a version token for display: 10-character commit or the
 * edition/revision.
 *
 * @param string $p_version Version token.
 * @return string
 */
function topic_evidence_version_label( string $p_version ): string {
	if( strncmp( $p_version, 'sha:', 4 ) === 0 ) {
		return substr( $p_version, 4, 10 );
	}
	return trim( substr( $p_version, 4 ), '/' );
}

/**
 * An expectation's evidence, with each document's current status and version.
 *
 * @param integer $p_expectation_id Expectation.
 * @return array dwg_id => row of {topic_evidence} with id = the document id,
 *               evidence_id = the link id, plus number, title, status, version.
 */
function topic_evidence_get_all( int $p_expectation_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT e.*, d.status, doc.number, doc.title FROM {topic_evidence} e
		JOIN {dwg} d ON d.id=e.dwg_id JOIN {documents} doc ON doc.id=d.document_id
		WHERE e.expectation_id=' . db_param() . ' ORDER BY e.dwg_id', array( $p_expectation_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_row['version'] = topic_evidence_version( (int)$t_row['dwg_id'] );
		$t_row['evidence_id'] = (int)$t_row['id'];
		$t_row['id'] = (int)$t_row['dwg_id'];
		$t_rows[(int)$t_row['dwg_id']] = $t_row;
	}
	return $t_rows;
}

/**
 * One evidence link.
 *
 * @param integer $p_evidence_id Evidence link.
 * @return array Row of {topic_evidence}.
 * @throws ClientException
 */
function topic_evidence_get( int $p_evidence_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_evidence} WHERE id=' . db_param(), array( $p_evidence_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		topic_error( 'evidence ' . $p_evidence_id . ' does not exist.' );
	}
	return $t_row;
}

/**
 * History value of an evidence link: "<Topic code>|E<expectation id>|<locator>".
 *
 * @param array  $p_expectation Row of {topic_expectation}.
 * @param string $p_locator     Sections.
 * @return string
 */
function topic_evidence_history_value( array $p_expectation, string $p_locator ): string {
	return topic_get( (int)$p_expectation['topic_id'] )['code'] . '|' . topic_expectation_ref( $p_expectation ) . '|' . $p_locator;
}

/**
 * Readable form of an evidence history value.
 *
 * @param string $p_value Value from topic_evidence_history_value().
 * @return string
 */
function topic_evidence_history_describe( string $p_value ): string {
	$t_parts = explode( '|', $p_value, 3 );
	if( count( $t_parts ) < 3 ) {
		return $p_value;
	}
	return sprintf( lang_get( 'topic_expectation_of_topic' ), $t_parts[1], $t_parts[0] )
		. ( $t_parts[2] !== '' ? ': ' . $t_parts[2] : '' );
}

/**
 * Link a document to an expectation as evidence. The document must belong to
 * the expectation's project and be tagged with its Topic.
 *
 * @param integer $p_expectation_id Expectation (enabled).
 * @param integer $p_dwg_id         Document.
 * @param string  $p_locator        Relevant sections, may be empty.
 * @return integer New evidence id.
 * @throws ClientException
 */
function topic_evidence_add( int $p_expectation_id, int $p_dwg_id, string $p_locator = '' ): int {
	$t_expectation = topic_expectation_get( $p_expectation_id );
	if( (int)$t_expectation['enabled'] != 1 ) {
		topic_error( 'the expectation is retired.' );
	}
	if( !dwg_exists( $p_dwg_id ) || (int)dwg_get_field( $p_dwg_id, 'project_id' ) != (int)$t_expectation['project_id'] ) {
		topic_error( 'the document is not in the expectation\'s project.' );
	}
	$t_tagged = false;
	foreach( topic_dwg_get_for_dwg( $p_dwg_id ) as $t_tag ) {
		$t_tagged = $t_tagged || (int)$t_tag['topic_id'] == (int)$t_expectation['topic_id'];
	}
	if( !$t_tagged ) {
		topic_error( 'tag the document with Topic ' . topic_get( (int)$t_expectation['topic_id'] )['code'] . ' first.' );
	}
	$t_locator = topic_dwg_validate( TOPIC_EXTENT_PART, $p_locator );
	db_param_push();
	$t_result = db_query( 'SELECT id FROM {topic_evidence} WHERE expectation_id=' . db_param() . ' AND dwg_id=' . db_param(),
		array( $p_expectation_id, $p_dwg_id ) );
	if( db_num_rows( $t_result ) > 0 ) {
		topic_error( 'the document is already evidence for this expectation.' );
	}
	db_param_push();
	db_query( 'INSERT INTO {topic_evidence} ( expectation_id, dwg_id, locator, user_id, date_added )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_expectation_id, $p_dwg_id, $t_locator, auth_get_current_user_id(), db_now() ) );
	$t_id = (int)db_insert_id( db_get_table( 'topic_evidence' ) );
	history_dwg_log_event_special( $p_dwg_id, DWG_TOPIC_EVIDENCE_ADDED, '', topic_evidence_history_value( $t_expectation, $t_locator ) );
	return $t_id;
}

/**
 * Remove an evidence link.
 *
 * @param integer $p_evidence_id Evidence link.
 * @return void
 * @throws ClientException
 */
function topic_evidence_remove( int $p_evidence_id ): void {
	$t_evidence = topic_evidence_get( $p_evidence_id );
	$t_expectation = topic_expectation_get( (int)$t_evidence['expectation_id'] );
	db_param_push();
	db_query( 'DELETE FROM {topic_evidence} WHERE id=' . db_param(), array( $p_evidence_id ) );
	history_dwg_log_event_special( (int)$t_evidence['dwg_id'], DWG_TOPIC_EVIDENCE_REMOVED,
		topic_evidence_history_value( $t_expectation, $t_evidence['locator'] ), '' );
}

/**
 * Remove a document's evidence links for the expectations of one Topic in a
 * project (its tag was removed) or for all (document deleted: $p_topic_id 0,
 * without history).
 *
 * @param integer $p_dwg_id     Document.
 * @param integer $p_project_id Project of the expectations.
 * @param integer $p_topic_id   Topic, 0 for every expectation.
 * @return void
 */
function topic_evidence_remove_for_dwg( int $p_dwg_id, int $p_project_id, int $p_topic_id ): void {
	if( $p_topic_id == 0 ) {
		db_param_push();
		db_query( 'DELETE FROM {topic_evidence} WHERE dwg_id=' . db_param(), array( $p_dwg_id ) );
		return;
	}
	db_param_push();
	$t_result = db_query( 'SELECT e.id FROM {topic_evidence} e JOIN {topic_expectation} x ON x.id=e.expectation_id
		WHERE e.dwg_id=' . db_param() . ' AND x.project_id=' . db_param() . ' AND x.topic_id=' . db_param(),
		array( $p_dwg_id, $p_project_id, $p_topic_id ) );
	while( $t_row = db_fetch_array( $t_result ) ) {
		topic_evidence_remove( (int)$t_row['id'] );
	}
}

# ── Assessments ──────────────────────────────────────────────────────────────

/**
 * Localised name of an assessment finding.
 *
 * @param integer $p_finding TOPIC_FINDING_* value.
 * @return string
 */
function topic_finding_label( int $p_finding ): string {
	return lang_get( $p_finding == TOPIC_FINDING_GAP ? 'topic_finding_gap' : 'topic_finding_adequate' );
}

/**
 * Whether a user may assess expectations of a Topic in a project: the
 * project threshold ($g_topic_assess_threshold) and, when configured, a
 * granted assessor license ($g_topic_assessor_licenses).
 *
 * @param integer      $p_project_id Project.
 * @param string       $p_topic_code Topic code.
 * @param integer|null $p_user_id    User, default current.
 * @return boolean
 */
function topic_can_assess( int $p_project_id, string $p_topic_code, ?int $p_user_id = null ): bool {
	if( !topic_project_is_enabled( $p_project_id )
		|| !access_has_project_level( config_get( 'topic_assess_threshold', null, $p_user_id, $p_project_id ), $p_project_id, $p_user_id ) ) {
		return false;
	}
	$t_licenses = (array)config_get( 'topic_assessor_licenses', null, $p_user_id, $p_project_id );
	$t_license = $t_licenses[$p_topic_code] ?? ( $t_licenses['*'] ?? '' );
	if( $t_license === '' ) {
		return true;
	}
	return topic_user_holds_license( $p_user_id ?? auth_get_current_user_id(), $t_license );
}

/**
 * Whether a user holds a granted, enabled license of the given name.
 *
 * @param integer $p_user_id      User.
 * @param string  $p_license_name License name.
 * @return boolean
 */
function topic_user_holds_license( int $p_user_id, string $p_license_name ): bool {
	db_param_push();
	$t_result = db_query( 'SELECT COUNT(*) FROM {license_user_list} lul JOIN {license} l ON l.id=lul.license_id
		WHERE lul.user_id=' . db_param() . ' AND lul.status=20 AND l.enabled=1 AND l.name=' . db_param(),
		array( $p_user_id, $p_license_name ) );
	return (int)db_result( $t_result ) > 0;
}

/**
 * The evidence set of an expectation as an assessment records it.
 *
 * @param integer $p_expectation_id Expectation.
 * @return array dwg_id => array( dwg_id, version, status, locator ).
 */
function topic_assessment_evidence_set( int $p_expectation_id ): array {
	$t_set = array();
	foreach( topic_evidence_get_all( $p_expectation_id ) as $t_dwg_id => $t_evidence ) {
		$t_set[$t_dwg_id] = array(
			'dwg_id' => (int)$t_dwg_id,
			'version' => $t_evidence['version'],
			'status' => (int)$t_evidence['status'],
			'locator' => $t_evidence['locator'],
		);
	}
	return $t_set;
}

/**
 * Record an adequacy assessment of an expectation's current evidence set.
 *
 * @param integer $p_expectation_id Expectation (enabled, with evidence).
 * @param integer $p_finding        TOPIC_FINDING_ADEQUATE or _GAP.
 * @param string  $p_rationale      Reason; required.
 * @param integer $p_milestone_id   Milestone the assessment is for, 0 = the project's current one.
 * @return integer New assessment id.
 * @throws ClientException
 */
function topic_assessment_record( int $p_expectation_id, int $p_finding, string $p_rationale, int $p_milestone_id = 0 ): int {
	$t_expectation = topic_expectation_get( $p_expectation_id );
	if( (int)$t_expectation['enabled'] != 1 ) {
		topic_error( 'the expectation is retired.' );
	}
	if( $p_finding != TOPIC_FINDING_ADEQUATE && $p_finding != TOPIC_FINDING_GAP ) {
		topic_error( 'unknown finding value ' . $p_finding . '.' );
	}
	$t_rationale = trim( $p_rationale );
	if( $t_rationale === '' || mb_strlen( $t_rationale ) > TOPIC_ASSESSMENT_RATIONALE_MAX ) {
		topic_error( 'an assessment needs a rationale of at most ' . TOPIC_ASSESSMENT_RATIONALE_MAX . ' characters.' );
	}
	$t_set = topic_assessment_evidence_set( $p_expectation_id );
	if( !$t_set ) {
		topic_error( 'the expectation has no evidence to assess.' );
	}
	$t_project_id = (int)$t_expectation['project_id'];
	if( $p_milestone_id == 0 ) {
		$p_milestone_id = topic_milestone_current( topic_milestone_get_all( $t_project_id ) );
	} else if( (int)topic_milestone_get( $p_milestone_id )['project_id'] != $t_project_id ) {
		topic_error( 'that milestone belongs to another project.' );
	}

	db_param_push();
	db_query( 'INSERT INTO {topic_assessment} ( expectation_id, expectation_revision, milestone_id, finding, rationale, evidence, user_id, date_assessed )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', '
		. db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_expectation_id, (int)$t_expectation['revision'], $p_milestone_id, $p_finding, $t_rationale,
			json_encode( array_values( $t_set ) ), auth_get_current_user_id(), db_now() ) );
	return (int)db_insert_id( db_get_table( 'topic_assessment' ) );
}

/**
 * An expectation's assessments, newest first, each with 'evidence' decoded.
 *
 * @param integer $p_expectation_id Expectation.
 * @return array Rows of {topic_assessment}.
 */
function topic_assessment_history( int $p_expectation_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_assessment} WHERE expectation_id=' . db_param() . ' ORDER BY id DESC',
		array( $p_expectation_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_items = json_decode( (string)$t_row['evidence'], true );
		$t_row['evidence'] = array();
		foreach( is_array( $t_items ) ? $t_items : array() as $t_item ) {
			$t_row['evidence'][(int)$t_item['dwg_id']] = $t_item;
		}
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

# ── States ───────────────────────────────────────────────────────────────────

/**
 * Localised name of an expectation, Topic or readiness state.
 *
 * @param string $p_state State constant.
 * @return string
 */
function topic_exp_state_label( string $p_state ): string {
	return lang_get( 'topic_exp_' . $p_state );
}

/**
 * HTML label for an expectation or Topic state: text plus colour.
 *
 * @param string $p_state State constant.
 * @return string HTML.
 */
function topic_exp_state_html( string $p_state ): string {
	static $s_class = array(
		TOPIC_EXP_SATISFIED => 'label-success',
		TOPIC_EXP_PENDING => 'label-warning',
		TOPIC_EXP_INCOMPLETE => 'label-warning',
		TOPIC_EXP_REASSESS => 'label-warning',
		TOPIC_EXP_GAP => 'label-danger',
		TOPIC_EXP_MISSING => 'label-danger',
		TOPIC_EXP_NONE => 'label-danger',
		TOPIC_EXP_NOT_DUE => 'label-grey',
		TOPIC_STATE_NOT_APPLICABLE => 'label-grey',
		TOPIC_READY => 'label-success',
		TOPIC_NOT_READY => 'label-warning',
		TOPIC_NOTHING_DUE => 'label-grey',
		TOPIC_PROFILE_UNRESOLVED => 'label-danger',
	);
	return '<span class="label ' . ( $s_class[$p_state] ?? 'label-default' ) . '">'
		. string_display_line( topic_exp_state_label( $p_state ) ) . '</span>';
}

/**
 * State of one expectation at a milestone (STD-IT-001 §6.1).
 *
 * @param array $p_expectation Row of {topic_expectation}.
 * @param array $p_milestones  topic_milestone_get_all() of its project.
 * @param int   $p_milestone_id Selected milestone.
 * @param array $p_evidence    topic_evidence_get_all() of the expectation.
 * @param array|null $p_assessment Latest assessment (topic_assessment_history()[0]) or null.
 * @return array state (TOPIC_EXP_*), reasons (strings).
 */
function topic_expectation_state( array $p_expectation, array $p_milestones, int $p_milestone_id, array $p_evidence, ?array $p_assessment ): array {
	$t_project_id = (int)$p_expectation['project_id'];
	$t_due_position = $p_milestones[(int)$p_expectation['milestone_id']]['position'] ?? 0;
	$t_selected_position = $p_milestones[$p_milestone_id]['position'] ?? PHP_INT_MAX;

	# 1. Not yet due.
	if( $t_selected_position < $t_due_position ) {
		return array( 'state' => TOPIC_EXP_NOT_DUE, 'reasons' => array( sprintf( lang_get( 'topic_reason_due_from' ),
			$p_milestones[(int)$p_expectation['milestone_id']]['name'] ) ) );
	}

	# 2. A dependency of the latest assessment changed.
	if( $p_assessment !== null ) {
		$t_reasons = array();
		if( (int)$p_assessment['expectation_revision'] != (int)$p_expectation['revision'] ) {
			$t_reasons[] = lang_get( 'topic_reason_expectation_changed' );
		}
		foreach( $p_assessment['evidence'] as $t_dwg_id => $t_item ) {
			$t_label = isset( $p_evidence[$t_dwg_id] ) ? topic_document_label( $p_evidence[$t_dwg_id] ) : dwg_format_id( (int)$t_dwg_id );
			if( !isset( $p_evidence[$t_dwg_id] ) ) {
				$t_reasons[] = sprintf( lang_get( 'topic_reason_evidence_removed' ), $t_label );
			} else if( $p_evidence[$t_dwg_id]['version'] !== $t_item['version'] ) {
				$t_reasons[] = sprintf( lang_get( 'topic_reason_evidence_changed' ), $t_label );
			} else if( $p_evidence[$t_dwg_id]['locator'] !== $t_item['locator'] ) {
				$t_reasons[] = sprintf( lang_get( 'topic_reason_sections_changed' ), $t_label );
			}
		}
		foreach( $p_evidence as $t_dwg_id => $t_evidence ) {
			if( !isset( $p_assessment['evidence'][$t_dwg_id] ) ) {
				$t_reasons[] = sprintf( lang_get( 'topic_reason_evidence_added' ), topic_document_label( $t_evidence ) );
			}
		}
		if( $t_reasons ) {
			return array( 'state' => TOPIC_EXP_REASSESS, 'reasons' => $t_reasons );
		}
	}

	# 3. No evidence.
	if( !$p_evidence ) {
		return array( 'state' => TOPIC_EXP_MISSING, 'reasons' => array( lang_get( 'topic_reason_no_evidence' ) ) );
	}

	# 4. Assessed as a gap.
	if( $p_assessment !== null && (int)$p_assessment['finding'] == TOPIC_FINDING_GAP ) {
		return array( 'state' => TOPIC_EXP_GAP, 'reasons' => array( $p_assessment['rationale'] ) );
	}

	# 5. Not assessed, or evidence not mature enough or inactive.
	$t_reasons = array();
	if( $p_assessment === null ) {
		$t_reasons[] = lang_get( 'topic_reason_assessment_required' );
	}
	$t_inactive = array_map( 'intval', (array)config_get( 'topic_inactive_status', null, null, $t_project_id ) );
	$t_required = (int)$p_expectation['maturity'];
	foreach( $p_evidence as $t_evidence ) {
		$t_status = (int)$t_evidence['status'];
		$t_label = topic_document_label( $t_evidence );
		if( in_array( $t_status, $t_inactive, true ) ) {
			$t_reasons[] = sprintf( lang_get( 'topic_reason_inactive' ), $t_label );
			continue;
		}
		$t_maturity = topic_status_maturity( $t_status, $t_project_id );
		if( $t_maturity === null ) {
			$t_reasons[] = sprintf( lang_get( 'topic_reason_status_unmapped' ), $t_label, get_enum_element( 'dwg_status', $t_status ) );
		} else if( $t_maturity < $t_required ) {
			$t_reasons[] = sprintf( lang_get( 'topic_reason_maturity' ), $t_label, topic_maturity_label( $t_maturity ), topic_maturity_label( $t_required ) );
		}
	}
	if( $t_reasons ) {
		return array( 'state' => TOPIC_EXP_PENDING, 'reasons' => $t_reasons );
	}

	# 6. Satisfied.
	return array( 'state' => TOPIC_EXP_SATISFIED, 'reasons' => array() );
}

/**
 * State of a Topic from its expectations at a milestone.
 *
 * @param integer $p_applicability Topic applicability.
 * @param array   $p_states        TOPIC_EXP_* states of its enabled expectations.
 * @return string TOPIC_EXP_SATISFIED, _INCOMPLETE, _NOT_DUE, _NONE or TOPIC_STATE_NOT_APPLICABLE.
 */
function topic_expectations_topic_state( int $p_applicability, array $p_states ): string {
	if( $p_applicability == TOPIC_NOT_APPLICABLE ) {
		return TOPIC_STATE_NOT_APPLICABLE;
	}
	if( !$p_states ) {
		return TOPIC_EXP_NONE;
	}
	$t_due = array_filter( $p_states, function( $p_state ) { return $p_state != TOPIC_EXP_NOT_DUE; } );
	if( !$t_due ) {
		return TOPIC_EXP_NOT_DUE;
	}
	foreach( $t_due as $t_state ) {
		if( $t_state != TOPIC_EXP_SATISFIED ) {
			return TOPIC_EXP_INCOMPLETE;
		}
	}
	return TOPIC_EXP_SATISFIED;
}

# ── Profile authorisation ────────────────────────────────────────────────────

/**
 * A project's current profile authorisation.
 *
 * @param integer $p_project_id Project.
 * @return array|null Newest row of {topic_profile}, null when never authorised.
 */
function topic_profile_current( int $p_project_id ): ?array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_profile} WHERE project_id=' . db_param() . ' ORDER BY id DESC',
		array( $p_project_id ), 1 );
	$t_row = db_fetch_array( $t_result );
	return $t_row ?: null;
}

/**
 * Fingerprint of a project's profile content: applicability decisions,
 * expectation revisions and milestone names and order. Achieving a milestone
 * is progress, not a profile change, and is left out.
 *
 * @param integer $p_project_id Project.
 * @return string SHA-1 hex.
 */
function topic_profile_fingerprint( int $p_project_id ): string {
	$t_content = array( 'decisions' => array(), 'expectations' => array(), 'milestones' => array() );
	foreach( topic_applicability_current( $p_project_id ) as $t_topic_id => $t_row ) {
		$t_content['decisions'][$t_topic_id] = array( (int)$t_row['applicability'], $t_row['rationale'] );
	}
	foreach( topic_expectation_get_all( $p_project_id, 0, false ) as $t_id => $t_row ) {
		$t_content['expectations'][$t_id] = array( (int)$t_row['revision'], (int)$t_row['enabled'] );
	}
	foreach( topic_milestone_get_all( $p_project_id ) as $t_id => $t_row ) {
		$t_content['milestones'][$t_id] = array( $t_row['name'], (int)$t_row['sort_order'] );
	}
	ksort( $t_content['decisions'] );
	ksort( $t_content['expectations'] );
	return sha1( json_encode( $t_content ) );
}

/**
 * What prevents authorising a project's profile now.
 *
 * @param integer $p_project_id Project with Topics enabled.
 * @return array Readable reasons; empty when it can be authorised.
 */
function topic_profile_blockers( int $p_project_id ): array {
	$t_catalogue = topic_project_catalogue( $p_project_id );
	$t_blockers = array();
	if( !topic_milestone_get_all( $p_project_id ) ) {
		$t_blockers[] = lang_get( 'topic_blocker_no_milestones' );
	}
	$t_decisions = topic_applicability_current( $p_project_id );
	$t_expectations = topic_expectation_get_all( $p_project_id );
	$t_undecided = array();
	$t_without = array();
	foreach( topic_get_all( (int)$t_catalogue['id'], true ) as $t_topic_id => $t_topic ) {
		$t_applicability = isset( $t_decisions[$t_topic_id] ) ? (int)$t_decisions[$t_topic_id]['applicability'] : TOPIC_UNDECIDED;
		if( $t_applicability == TOPIC_UNDECIDED ) {
			$t_undecided[] = $t_topic['code'];
		} else if( $t_applicability == TOPIC_APPLICABLE ) {
			$t_has = false;
			foreach( $t_expectations as $t_expectation ) {
				$t_has = $t_has || (int)$t_expectation['topic_id'] == $t_topic_id;
			}
			if( !$t_has ) {
				$t_without[] = $t_topic['code'];
			}
		}
	}
	if( $t_undecided ) {
		$t_blockers[] = sprintf( lang_get( 'topic_blocker_undecided' ), implode( ', ', $t_undecided ) );
	}
	if( $t_without ) {
		$t_blockers[] = sprintf( lang_get( 'topic_blocker_no_expectations' ), implode( ', ', $t_without ) );
	}
	$t_no_owner = array();
	foreach( $t_expectations as $t_expectation ) {
		if( (int)$t_expectation['owner_id'] == 0 ) {
			$t_no_owner[] = topic_expectation_ref( $t_expectation );
		}
	}
	if( $t_no_owner ) {
		$t_blockers[] = sprintf( lang_get( 'topic_blocker_no_owner' ), implode( ', ', $t_no_owner ) );
	}
	return $t_blockers;
}

/**
 * Authorise a project's profile as a new revision.
 *
 * @param integer $p_project_id Project.
 * @param string  $p_note       Optional note.
 * @return integer The new revision number.
 * @throws ClientException
 */
function topic_profile_authorise( int $p_project_id, string $p_note = '' ): int {
	$t_blockers = topic_profile_blockers( $p_project_id );
	if( $t_blockers ) {
		topic_error( 'the profile cannot be authorised: ' . implode( ' ', $t_blockers ) );
	}
	$t_note = trim( $p_note );
	if( mb_strlen( $t_note ) > 255 ) {
		topic_error( 'the note is longer than 255 characters.' );
	}
	$t_current = topic_profile_current( $p_project_id );
	$t_revision = $t_current === null ? 1 : (int)$t_current['revision'] + 1;
	db_param_push();
	db_query( 'INSERT INTO {topic_profile} ( project_id, revision, note, fingerprint, user_id, date_authorised )
		VALUES ( ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ', ' . db_param() . ' )',
		array( $p_project_id, $t_revision, $t_note, topic_profile_fingerprint( $p_project_id ), auth_get_current_user_id(), db_now() ) );
	return $t_revision;
}

/**
 * Profile status of a project.
 *
 * @param integer $p_project_id Project.
 * @return array current (row or null), changed (bool: changes since the
 *               authorisation, or never authorised).
 */
function topic_profile_status( int $p_project_id ): array {
	$t_current = topic_profile_current( $p_project_id );
	return array(
		'current' => $t_current,
		'changed' => $t_current === null || $t_current['fingerprint'] !== topic_profile_fingerprint( $p_project_id ),
	);
}

# ── Coverage view ────────────────────────────────────────────────────────────

/**
 * The Topics page data with expectations: topic_project_view() plus, per
 * Topic, 'expectations' (each with evidence, latest assessment, state and
 * reasons) and 'expectation_state'; and for the project the milestones, the
 * selected milestone, expectation state counts, profile status and
 * documentation readiness.
 *
 * Evidence documents the reader cannot see are left out of 'evidence_visible'
 * (states still use every item).
 *
 * @param integer      $p_project_id   Project with Topics enabled.
 * @param integer      $p_milestone_id Selected milestone, 0 = current.
 * @param integer|null $p_user_id      Reader, default current.
 * @return array
 * @throws ClientException
 */
function topic_coverage_view( int $p_project_id, int $p_milestone_id = 0, ?int $p_user_id = null ): array {
	$t_view = topic_project_view( $p_project_id, $p_user_id );
	$t_milestones = topic_milestone_get_all( $p_project_id );
	if( !isset( $t_milestones[$p_milestone_id] ) ) {
		$p_milestone_id = topic_milestone_current( $t_milestones );
	}
	$t_expectations = topic_expectation_get_all( $p_project_id );

	$t_counts = array_fill_keys( array( TOPIC_EXP_SATISFIED, TOPIC_EXP_PENDING, TOPIC_EXP_REASSESS, TOPIC_EXP_GAP, TOPIC_EXP_MISSING, TOPIC_EXP_NOT_DUE ), 0 );
	foreach( $t_view['topics'] as $t_topic_id => $t_topic ) {
		$t_view['topics'][$t_topic_id]['expectations'] = array();
	}
	foreach( $t_expectations as $t_id => $t_expectation ) {
		$t_topic_id = (int)$t_expectation['topic_id'];
		if( !isset( $t_view['topics'][$t_topic_id] ) ) {
			continue;
		}
		$t_evidence = topic_evidence_get_all( $t_id );
		$t_history = topic_assessment_history( $t_id );
		$t_latest = $t_history[0] ?? null;
		$t_state = topic_expectation_state( $t_expectation, $t_milestones, $p_milestone_id, $t_evidence, $t_latest );
		$t_visible = array();
		foreach( $t_evidence as $t_dwg_id => $t_item ) {
			if( topic_can_see_dwg( $t_dwg_id, $p_user_id ) ) {
				$t_visible[$t_dwg_id] = $t_item;
			}
		}
		$t_expectation['evidence'] = $t_evidence;
		$t_expectation['evidence_visible'] = $t_visible;
		$t_expectation['assessment'] = $t_latest;
		$t_expectation['state'] = $t_state['state'];
		$t_expectation['reasons'] = $t_state['reasons'];
		$t_view['topics'][$t_topic_id]['expectations'][$t_id] = $t_expectation;
		if( $t_view['topics'][$t_topic_id]['applicability'] != TOPIC_NOT_APPLICABLE ) {
			$t_counts[$t_state['state']]++;
		}
	}

	$t_topic_counts = array_fill_keys( array( TOPIC_EXP_SATISFIED, TOPIC_EXP_INCOMPLETE, TOPIC_EXP_NOT_DUE, TOPIC_EXP_NONE ), 0 );
	$t_due_total = 0;
	$t_due_satisfied = 0;
	$t_undecided = false;
	foreach( $t_view['topics'] as $t_topic_id => $t_topic ) {
		$t_states = array_column( $t_topic['expectations'], 'state' );
		$t_topic_state = topic_expectations_topic_state( (int)$t_topic['applicability'], $t_states );
		$t_view['topics'][$t_topic_id]['expectation_state'] = $t_topic_state;
		if( isset( $t_topic_counts[$t_topic_state] ) && (int)$t_topic['applicability'] == TOPIC_APPLICABLE ) {
			$t_topic_counts[$t_topic_state]++;
		}
		$t_undecided = $t_undecided || ( (int)$t_topic['applicability'] == TOPIC_UNDECIDED && (int)$t_topic['enabled'] == 1 );
		if( (int)$t_topic['applicability'] != TOPIC_NOT_APPLICABLE ) {
			foreach( $t_states as $t_state ) {
				if( $t_state != TOPIC_EXP_NOT_DUE ) {
					$t_due_total++;
					$t_due_satisfied += $t_state == TOPIC_EXP_SATISFIED ? 1 : 0;
				}
			}
		}
	}

	$t_profile = topic_profile_status( $p_project_id );
	if( $t_profile['changed'] || $t_undecided || !$t_milestones ) {
		$t_readiness = TOPIC_PROFILE_UNRESOLVED;
	} else if( $t_due_total == 0 ) {
		$t_readiness = TOPIC_NOTHING_DUE;
	} else {
		$t_readiness = $t_due_satisfied == $t_due_total ? TOPIC_READY : TOPIC_NOT_READY;
	}

	$t_view['milestones'] = $t_milestones;
	$t_view['milestone_id'] = $p_milestone_id;
	$t_view['expectation_counts'] = $t_counts;
	$t_view['topic_counts'] = $t_topic_counts;
	$t_view['profile'] = $t_profile;
	$t_view['readiness'] = $t_readiness;
	$t_view['has_expectations'] = (bool)$t_expectations;
	return $t_view;
}

# ── Display helpers ──────────────────────────────────────────────────────────

/**
 * Display name of a milestone, marking achieved ones.
 *
 * @param array $p_milestone Row of {topic_milestone}.
 * @return string Plain text.
 */
function topic_milestone_label( array $p_milestone ): string {
	return $p_milestone['name'] . ( (int)$p_milestone['date_achieved'] > 0 ? ' (' . lang_get( 'topic_milestone_achieved' ) . ')' : '' );
}

/**
 * Print <option>s for a project's milestones.
 *
 * @param array   $p_milestones topic_milestone_get_all().
 * @param integer $p_selected   Selected milestone id.
 * @return void
 */
function print_topic_milestone_option_list( array $p_milestones, int $p_selected ): void {
	foreach( $p_milestones as $t_id => $t_milestone ) {
		echo '<option value="' . (int)$t_id . '"' . ( $t_id == $p_selected ? ' selected="selected"' : '' ) . '>'
			. string_display_line( topic_milestone_label( $t_milestone ) ) . '</option>';
	}
}

/**
 * Print a <select> of maturity levels.
 *
 * @param string  $p_name     Field name.
 * @param integer $p_selected Selected level.
 * @param string  $p_id       Element id.
 * @return void
 */
function print_topic_maturity_select( string $p_name, int $p_selected, string $p_id = '' ): void {
	echo '<select name="' . string_attribute( $p_name ) . '" class="input-sm"' . ( $p_id !== '' ? ' id="' . string_attribute( $p_id ) . '"' : '' ) . '>';
	foreach( array( TOPIC_MATURITY_DRAFT, TOPIC_MATURITY_UNDER_REVIEW, TOPIC_MATURITY_APPROVED ) as $t_level ) {
		echo '<option value="' . $t_level . '"' . ( $t_level == $p_selected ? ' selected="selected"' : '' ) . '>'
			. topic_maturity_label( $t_level ) . '</option>';
	}
	echo '</select>';
}

/**
 * Print a milestone selector form that reloads the current page.
 *
 * @param string $p_page        Page to reload.
 * @param array  $p_params      Other GET parameters to keep.
 * @param array  $p_milestones  topic_milestone_get_all().
 * @param int    $p_selected    Selected milestone.
 * @return void
 */
function print_topic_milestone_selector( string $p_page, array $p_params, array $p_milestones, int $p_selected ): void {
	if( !$p_milestones ) {
		return;
	}
	echo '<form method="get" action="' . string_attribute( $p_page ) . '" class="form-inline inline noprint">';
	foreach( $p_params as $t_name => $t_value ) {
		echo '<input type="hidden" name="' . string_attribute( $t_name ) . '" value="' . string_attribute( (string)$t_value ) . '" />';
	}
	echo '<label for="topic-milestone-select">' . lang_get( 'topic_milestone' ) . '</label> ';
	echo '<select id="topic-milestone-select" name="milestone_id" class="input-sm">';
	print_topic_milestone_option_list( $p_milestones, $p_selected );
	echo '</select> <button type="submit" class="btn btn-primary btn-xs btn-white btn-round">' . lang_get( 'topic_show' ) . '</button></form>';
}

/**
 * HTML of a state with its reasons, one per line.
 *
 * @param string $p_state   TOPIC_EXP_* state.
 * @param array  $p_reasons Reasons.
 * @return string HTML.
 */
function topic_exp_state_with_reasons_html( string $p_state, array $p_reasons ): string {
	$t_html = topic_exp_state_html( $p_state );
	foreach( $p_reasons as $t_reason ) {
		$t_html .= '<br /><span class="small grey">' . string_display_line( $t_reason ) . '</span>';
	}
	return $t_html;
}

/**
 * Name of a user for Topic pages, '—' for none.
 *
 * @param integer $p_user_id User id, 0 = none.
 * @return string Plain text.
 */
function topic_user_name( int $p_user_id ): string {
	return $p_user_id > 0 && user_exists( $p_user_id ) ? user_get_name( $p_user_id ) : '—';
}

/**
 * A project's profile authorisations, newest first.
 *
 * @param integer $p_project_id Project.
 * @return array Rows of {topic_profile}.
 */
function topic_profile_history( int $p_project_id ): array {
	db_param_push();
	$t_result = db_query( 'SELECT * FROM {topic_profile} WHERE project_id=' . db_param() . ' ORDER BY id DESC', array( $p_project_id ) );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
	}
	return $t_rows;
}

/**
 * Expectation rows of a project for export, from topic_coverage_view():
 * evidence the reader cannot see is shown only as restricted.
 *
 * @param array $p_view Result of topic_coverage_view().
 * @return array Rows: code, ref, expectation, owner, due, maturity, state, reasons, evidence, assessment.
 */
function topic_export_expectation_rows( array $p_view ): array {
	$t_rows = array();
	foreach( $p_view['topics'] as $t_topic ) {
		foreach( $t_topic['expectations'] as $t_expectation ) {
			$t_evidence = array();
			foreach( $t_expectation['evidence_visible'] as $t_item ) {
				$t_evidence[] = topic_document_label( $t_item ) . ' [' . topic_evidence_version_label( $t_item['version'] ) . ']'
					. ( $t_item['locator'] !== '' ? ' ' . $t_item['locator'] : '' );
			}
			if( count( $t_expectation['evidence_visible'] ) < count( $t_expectation['evidence'] ) ) {
				$t_evidence[] = lang_get( 'topic_restricted_short' );
			}
			$t_assessment = $t_expectation['assessment'];
			$t_rows[] = array(
				'code' => $t_topic['code'],
				'ref' => topic_expectation_ref( $t_expectation ),
				'expectation' => $t_expectation['statement'],
				'owner' => topic_user_name( (int)$t_expectation['owner_id'] ),
				'due' => $p_view['milestones'][(int)$t_expectation['milestone_id']]['name'] ?? '',
				'maturity' => topic_maturity_label( (int)$t_expectation['maturity'] ),
				'state' => topic_exp_state_label( $t_expectation['state'] ),
				'reasons' => implode( ' ', $t_expectation['reasons'] ),
				'evidence' => implode( '; ', $t_evidence ),
				'assessment' => $t_assessment === null ? '' : topic_finding_label( (int)$t_assessment['finding'] ) . ', '
					. date( config_get( 'short_date_format' ), (int)$t_assessment['date_assessed'] ) . ', '
					. topic_user_name( (int)$t_assessment['user_id'] ),
			);
		}
	}
	return $t_rows;
}
