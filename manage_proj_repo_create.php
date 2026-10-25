<?php
# Doctis — give a project its own git repository.
#
# A project tree shares one repository by default (repository_id_for_project()
# walks up to the nearest linked ancestor).  This creates one owned by, and
# linked to, this project, so its documents live separately from its parent's.
#
# The reason to do it is that the push policy and the source-release setting
# are per repository: a repository cannot be append-only for document control
# and rewritable for source development at the same time.  See
# doc/git/SOURCE_REPOSITORIES.md.

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'repository_api.php' );

use Mantis\Exceptions\ClientException;

form_security_validate( 'manage_proj_repo_create' );

auth_reauthenticate();

$f_project_id = gpc_get_int( 'project_id' );

project_ensure_exists( $f_project_id );
access_ensure_project_level( config_get( 'manage_project_threshold' ), $f_project_id );

if( repository_id_linked_to_project( $f_project_id ) > 0 ) {
	throw new ClientException( 'Project ' . $f_project_id . ' already has its own repository',
		ERROR_INVALID_FIELD_VALUE, array( 'project_id' ) );
}

# Refuse while the project's documents are stored in the repository it
# currently inherits: relinking would leave every one of them pointing into a
# repository the project no longer resolves to.  Moving them is a separate
# operation (dwg_move() / file_dwg_primary_move_prepare()).
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

form_security_purge( 'manage_proj_repo_create' );

print_header_redirect( 'manage_proj_edit_page.php?project_id=' . $f_project_id );
