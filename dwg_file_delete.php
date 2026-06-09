<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Delete a file from a dwg and then view the document
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses access_api.php
 * @uses bug_api.php
 * @uses config_api.php
 * @uses file_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses lang_api.php
 * @uses print_api.php
 */

require_once( 'core.php' );
require_api( 'access_dwg_api.php' );
require_api( 'dwg_api.php' );
require_api( 'config_api.php' );
require_api( 'file_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_dwg_api.php' );

form_security_validate( 'dwg_file_delete' );

$f_file_id = gpc_get_int( 'file_id' );

$t_bug_id = file_dwg_get_field( $f_file_id, 'dwg_id', 'dwg' );

$t_bug = dwg_get( $t_bug_id, true );
if( $t_bug->project_id != helper_get_current_project() ) {
	# in case the current project is not the same project of the bug we are viewing...
	# ... override the current project. This to avoid problems with categories and handlers lists etc.
	$g_project_override = $t_bug->project_id;
}

$t_attachment_owner = file_dwg_get_field( $f_file_id, 'user_id', 'dwg' );
$t_current_user_is_attachment_owner = $t_attachment_owner == auth_get_current_user_id();
if( !$t_current_user_is_attachment_owner || ( $t_current_user_is_attachment_owner && !config_get( 'allow_delete_own_attachments' ) ) ) {
	access_ensure_dwg_level( config_get( 'delete_attachments_threshold' ), $t_bug_id );
}

helper_ensure_confirmed( lang_get( 'delete_attachment_sure_msg' ), lang_get( 'delete' ) );

# @TODO RobD - it looks like file_api.php is already (mostly) parametised to use alternative tables
file_dwg_delete( $f_file_id, 'dwg' );

form_security_purge( 'dwg_file_delete' );

print_dwg_header_redirect_view( $t_bug_id );
