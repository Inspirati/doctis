<?php
# Doctis — save a project's repository settings from the Manage Project page
# (manage_proj_repo_inc.php, doc/git/SOURCE_REPOSITORIES.md).
#
# Two actions arrive from the one form:
#   update — the repository's push policy and this project's source-release
#            setting;
#   create — give this project its own repository, so it stops sharing its
#            ancestor's.  Structural, and confirmed before it runs.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );

use Mantis\Exceptions\ClientException;

form_security_validate( 'manage_proj_repo_update' );

auth_reauthenticate();

$f_project_id = gpc_get_int( 'project_id' );
$f_action     = gpc_get_string( 'action', 'update' );

project_ensure_exists( $f_project_id );
$g_project_override = $f_project_id;
access_ensure_project_level( config_get( 'manage_project_threshold' ), $f_project_id );

/**
 * Give this project its own repository, so its documents stop living in the
 * one it inherits.  Refused while it already stores documents there: moving
 * them is a separate operation (file_dwg_primary_move_prepare()), and
 * relinking first would leave every one of them pointing into a repository
 * the project no longer resolves to.
 *
 * @return void
 */
function manage_proj_repo_create_own(): void {
	global $f_project_id;

	if( repository_id_linked_to_project( $f_project_id ) > 0 ) {
		throw new ClientException( 'Project ' . $f_project_id . ' already has its own repository',
			ERROR_INVALID_FIELD_VALUE, array( 'project_id' ) );
	}

	db_param_push();
	$t_count = (int)db_result( db_query(
		'SELECT COUNT(*) FROM {dwg_primary_file} f INNER JOIN {dwg} d ON d.id = f.dwg_id'
		. ' WHERE d.project_id=' . db_param(), array( $f_project_id ) ) );
	if( $t_count > 0 ) {
		throw new ClientException(
			'Project ' . $f_project_id . ' already stores ' . $t_count . ' document file(s) in the '
				. 'repository it inherits; move them before giving it its own',
			ERROR_INVALID_FIELD_VALUE, array( 'project_id' ) );
	}

	helper_ensure_confirmed(
		lang_get( 'project_repository_create_confirm' ),
		lang_get( 'project_repository_create_button' )
	);

	$t_repository_id = repository_create( (string)project_get_field( $f_project_id, 'name' ), $f_project_id );
	repository_ensure_on_disk( $t_repository_id );
}

/**
 * Save the push policy and this project's source-release setting.
 *
 * The policy belongs to the repository, not the project, so it is accepted
 * only from a project that owns one, and only from an administrator —
 * relaxing history protection is the same decision Manage → Git Store
 * gates the same way.  Source releases are a per-project capability and
 * follow the project-management threshold.
 *
 * @return void
 */
function manage_proj_repo_save_settings(): void {
	global $f_project_id;

	$t_repo_id  = repository_id_for_project( $f_project_id );
	$t_repo_own = $t_repo_id > 0 && repository_id_linked_to_project( $f_project_id ) === $t_repo_id;
	$t_bare     = $t_repo_id > 0 ? repository_bare_path( $t_repo_id ) : '';

	$f_policy = gpc_get_string( 'push_policy', '' );
	if( $f_policy !== '' && $t_repo_own && $t_bare !== '' && is_dir( $t_bare )
	 && access_has_global_level( ADMINISTRATOR )
	 && $f_policy !== repository_get_push_policy( $t_bare ) ) {
		repository_set_push_policy( $t_bare, $f_policy );
	}

	# An explicit OFF is stored rather than deleted only when it differs from
	# what the project would inherit, so the table keeps no redundant rows.
	$t_wanted = gpc_get_bool( 'source_release_enabled' ) ? ON : OFF;
	config_delete( 'dwg_source_release_enabled', ALL_USERS, $f_project_id );
	if( $t_wanted != config_get( 'dwg_source_release_enabled', OFF, null, $f_project_id ) ) {
		config_set( 'dwg_source_release_enabled', $t_wanted, ALL_USERS, $f_project_id );
	}
}

if( $f_action === 'create' ) {
	manage_proj_repo_create_own();
} else {
	manage_proj_repo_save_settings();
}

form_security_purge( 'manage_proj_repo_update' );

print_header_redirect( 'manage_proj_edit_page.php?project_id=' . $f_project_id . '#repository' );
