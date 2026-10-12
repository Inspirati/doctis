<?php
# Doctis — Record an adequacy assessment of a Documentation Topics
# expectation's evidence (authorised assessors only; see
# $g_topic_assess_threshold and $g_topic_assessor_licenses).

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'print_api.php' );
require_api( 'topic_expectation_api.php' );

form_security_validate( 'topic_assessment_add' );

$f_expectation_id = gpc_get_int( 'expectation_id' );
$f_milestone_id = gpc_get_int( 'milestone_id', 0 );
$t_expectation = topic_expectation_get( $f_expectation_id );
$t_project_id = (int)$t_expectation['project_id'];
if( !topic_can_assess( $t_project_id, topic_get( (int)$t_expectation['topic_id'] )['code'] ) ) {
	access_denied();
}

topic_assessment_record( $f_expectation_id, gpc_get_int( 'finding' ), gpc_get_string( 'rationale' ), $f_milestone_id );

form_security_purge( 'topic_assessment_add' );
print_header_redirect( 'topic_expectation_page.php?id=' . $f_expectation_id . '&milestone_id=' . $f_milestone_id . '#assessments' );
