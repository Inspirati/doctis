<?php
# Doctis — AI Assistant Help mode functions
#
# Contains ai_assist_help_system_prompt() which builds the server-side
# system prompt for Help mode.  Required by ai_assist_api.php via require_once.
#
# Building the prompt server-side (rather than in JS) keeps all context data
# off the wire and makes Help mode architecturally consistent with Meeting mode:
# neither mode sends a system prompt from the browser.
#
# The prompt has two system blocks:
#   1. cached (the same for every user): role, teaching rules, the user manual
#      and the global knowledge base entries;
#   2. per user: the pages this user can open (read from the live menus), the
#      current project, and project knowledge entries the user may see.
# See ai_assist_knowledge_api.php.
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );
require_api( 'helper_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_once( __DIR__ . '/ai_assist_knowledge_api.php' );


/**
 * Build the server-side system prompt for Help mode.
 *
 * @param int $p_user_id  Current user ID.
 * @return array System content blocks (the first carries cache_control).
 */
function ai_assist_help_system_prompt( int $p_user_id ): array {
	$t_entries = ai_assist_knowledge_entries( $p_user_id );

	# ── Block 1: shared by every user (cached) ───────────────────────────────
	$t_static = <<<TEXT
You are the Doctis AI Assistant, running inside the Doctis document issue-tracking system.
Doctis is a PHP/MariaDB web application built on MantisBT that tracks controlled documents
and the review issues raised against them during formal document review cycles. It also
records meetings (agendas, minutes, actions) and holds an organisational chart.

Your role is to help users with:
- Using Doctis features: documents, issues, filtering, the review workflow, primary document
  files and revisions, meetings, and the other pages listed in the navigation map below
- Understanding document statuses (pending → received → triage → JoS → assigned to →
  review → rework → independent review → accepted → incorporated → archived)
- QMS document control concepts: what a controlled document is, why revision tracking
  matters, ISO 9001 Clause 7.5 requirements
- Finding the right Doctis page for a task: name the menu path the user should follow
  (e.g. "My View → Organisational Chart") as well as the page file

Be concise and practical. Answer from the navigation map, the user manual and the knowledge
base below, and your general knowledge of MantisBT and quality management.

TEXT;
	$t_static .= "\n" . ai_assist_knowledge_teaching_text() . "\n\n";
	$t_manual = ai_assist_knowledge_manual();
	if( $t_manual !== '' ) {
		$t_static .= "## DOCTIS USER MANUAL\n\n" . $t_manual . "\n\n";
	}
	$t_static .= ai_assist_knowledge_intro_text() . "\n\n"
		. ( empty( $t_entries['global'] ) ? '(No entries yet.)' : ai_assist_knowledge_entries_text( $t_entries['global'] ) );

	# ── Block 2: this user ───────────────────────────────────────────────────
	$t_dynamic = "## PAGES THIS USER CAN OPEN (generated live from the Doctis menus)\n\n"
		. ai_assist_knowledge_nav_map();

	$t_project_id = helper_get_current_project();
	if( $t_project_id > 0 && $t_project_id !== ALL_PROJECTS ) {
		$t_result = db_query( 'SELECT COUNT(*) FROM {dwg} WHERE project_id = ' . db_param(), [ $t_project_id ] );
		$t_dynamic .= "\n\nThe user is currently working in the Doctis project \u{201C}"
			. project_get_field( $t_project_id, 'name' ) . "\u{201D}, which contains "
			. (string)db_result( $t_result ) . ' document(s).';
	}
	if( !empty( $t_entries['project'] ) ) {
		$t_dynamic .= "\n\n## PROJECT KNOWLEDGE ENTRIES (only for users with access to these projects)\n\n"
			. ai_assist_knowledge_entries_text( $t_entries['project'] );
	}

	return [
		[ 'type' => 'text', 'text' => $t_static, 'cache_control' => [ 'type' => 'ephemeral' ] ],
		[ 'type' => 'text', 'text' => $t_dynamic ],
	];
}
