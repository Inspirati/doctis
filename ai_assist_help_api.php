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
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

require_api( 'config_api.php' );
require_api( 'helper_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );


/**
 * Build the server-side system prompt for Help mode.
 *
 * Injects the current project context (project name and document count) so
 * the assistant can give project-aware answers without that context ever
 * reaching the browser.
 *
 * @param int $p_user_id  Current user ID (reserved for future personalisation).
 * @return string
 */
function ai_assist_help_system_prompt( int $p_user_id ): string {
	# Fetch current project context from the session cookie
	$t_project_id   = helper_get_current_project();
	$t_project_name = '';
	$t_doc_count    = '';

	if( $t_project_id > 0 && $t_project_id !== ALL_PROJECTS ) {
		$t_project_name = project_get_field( $t_project_id, 'name' );
		$t_result       = db_query(
			'SELECT COUNT(*) FROM {dwg} WHERE project_id = ' . db_param(),
			[ $t_project_id ]
		);
		$t_doc_count = (string) db_result( $t_result );
	}

	$t_prompt =
		'You are the Doctis AI Assistant, running inside the Doctis document ' .
		'issue-tracking system. Doctis is a PHP/MariaDB web application built ' .
		'on MantisBT that tracks controlled documents and the review issues ' .
		'raised against them during formal document review cycles.' . "\n\n" .
		'Your role in this session is to help users with:' . "\n" .
		'- Using Doctis features: creating documents, raising issues, filtering, ' .
		'  the review workflow, uploading primary document files, managing revisions' . "\n" .
		'- Understanding document statuses (pending \u2192 received \u2192 triage \u2192 JoS \u2192 ' .
		'  assigned to \u2192 review \u2192 rework \u2192 independent review \u2192 accepted \u2192 ' .
		'  incorporated \u2192 archived)' . "\n" .
		'- QMS document control concepts: what a controlled document is, why ' .
		'  revision tracking matters, ISO 9001 Clause 7.5 requirements' . "\n" .
		'- Finding the right Doctis page or function for a given task' . "\n" .
		'- Understanding the difference between a Doctis Document and an Issue' . "\n\n" .
		'Be concise and practical. Refer to Doctis page names where helpful ' .
		'(e.g. dwg_create_page.php, dwg_view.php, view_dwg_page.php). ' .
		'Do not invent features that do not exist. If you are unsure of a ' .
		'specific Doctis implementation detail, say so.';

	if( !is_blank( $t_project_name ) ) {
		$t_prompt .=
			"\n\n" . 'The user is currently working in the Doctis project ' .
			"\u{201C}" . $t_project_name . "\u{201D}" .
			( !is_blank( $t_doc_count )
				? ' which contains ' . $t_doc_count . ' document(s)'
				: '' ) . '.';
	}

	return $t_prompt;
}
