<?php
// Production application behavior for native development installations.
// Local connection details and excluded integrations remain in config_inc.php.
$g_allow_signup = ON;
$g_allow_anonymous_login = OFF;
$g_anonymous_account = '';
$g_allow_file_upload = ON;
$g_from_name = 'Doctis';
$g_show_copyright_footer = OFF;
$g_news_enabled = ON;
$g_display_bug_padding = 5;
$g_display_dwg_padding = 4;
$g_display_bugnote_padding = 5;
$g_display_dwgnote_padding = 4;
$g_severity_enum_string = '20:comment,30:query,50:minor,60:major';
$g_default_bug_severity = 20;
$g_reauthentication = OFF;
$g_reauthentication_expiry = 86400;
$USE_LOREM_IPSUM = true;
$g_git_http_backend = '/usr/lib/git-core/git-http-backend';

// Optional integrations: no key or remote service is enabled by this template.
$g_anthropic_api_key = getenv( 'ANTHROPIC_API_KEY' ) ?: '';
$g_ai_model = getenv( 'AI_MODEL' ) ?: 'claude-sonnet-4-6';
$g_ai_assist_threshold = REPORTER;
$g_hcrqms_repo_path = '/var/git/doctis';

$_log_level = getenv( 'LOG_LEVEL' ) ?: 'none';
switch ( $_log_level ) {
    case 'all': $g_log_level = LOG_ALL; break;
    case 'email': $g_log_level = LOG_EMAIL; break;
    case 'db': $g_log_level = LOG_DATABASE; break;
    case 'api': $g_log_level = LOG_WEBSERVICE | LOG_AJAX; break;
    case 'debug': $g_log_level = LOG_EMAIL | LOG_WEBSERVICE | LOG_AJAX | LOG_DATABASE; break;
    default: $g_log_level = LOG_NONE;
}
$g_log_destination = ( $g_log_level !== LOG_NONE ) ? 'file:/var/log/doctis/mantis.log' : 'none';
$g_show_log_threshold = ADMINISTRATOR;
unset( $_log_level );

$_doctis_page_fields = array(
    'additional_info',
    'attachments',
    'category_id',
    'document_id',
    'date_submitted',
    'description',
    'due_date',
    'fixed_in_version',
    'handler',
    'id',
    'last_updated',
    'priority',
    'project',
    'reporter',
    'resolution',
    'severity',
    'status',
    'summary',
    'target_version',
    'view_state',
);
$g_bug_report_page_fields = $_doctis_page_fields;
$g_bug_view_page_fields   = $_doctis_page_fields;
$g_bug_update_page_fields = $_doctis_page_fields;
unset( $_doctis_page_fields );
