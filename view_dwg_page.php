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
 * View documents page, customised from view all bug page
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses authentication_api.php
 * @uses compress_api.php
 * @uses config_api.php
 * @uses current_user_api.php
 * @uses filter_api.php
 * @uses gpc_api.php
 * @uses html_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses project_api.php
 * @uses user_api.php
 */

require_once( 'core.php' );
require_api( 'authentication_api.php' );
require_api( 'compress_api.php' );
require_api( 'config_api.php' );
require_api( 'current_user_api.php' );
require_api( 'filter_dwg_api.php' );
require_api( 'gpc_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'user_api.php' );

require_js( 'bugFilter.js' );  // there is nothing 'bug' specific in the javascript
require_css( 'status_config.php' );

auth_ensure_user_authenticated();

$f_page_number		= gpc_get_int( 'page_number', 1 );
# Get Project Id and set it as current
$t_project_id = gpc_get_int( 'project_id', helper_get_current_project() );
if( ( ALL_PROJECTS == $t_project_id || project_exists( $t_project_id ) ) && $t_project_id != helper_get_current_project() ) {
	helper_set_current_project( $t_project_id );
	# Reloading the page is required so that the project browser
	# reflects the new current project
	print_dwg_header_redirect( $_SERVER['REQUEST_URI'], true, false, true );
}

$t_per_page = 0;
$t_bug_count = 0;
$t_page_count = 0;

$t_rows = filter_dwg_get_dwg_rows( $f_page_number, $t_per_page, $t_page_count, $t_bug_count, null, null, null, true );
if( $t_rows === false ) {
	print_dwg_header_redirect( 'view_dwg_set.php?type=0' );
}

$t_dwgslist = array();
$t_unique_user_ids = array();
$t_unique_project_ids = array();
$t_row_count = count( $t_rows );
for( $i=0; $i < $t_row_count; $i++ ) {
	array_push( $t_dwgslist, $t_rows[$i]->id );
	$t_project_id = $t_rows[$i]->project_id;
	$t_unique_project_ids[$t_project_id] = $t_project_id;
}
gpc_set_cookie( config_get_global( 'dwg_list_cookie' ), implode( ',', $t_dwgslist ) );

compress_enable();

# don't index view documents pages
html_robots_noindex();

layout_page_header_begin( lang_get( 'view_dwgs_link' ) );

$t_refresh_delay = current_user_get_pref( 'refresh_delay' );
if( $t_refresh_delay > 0 ) {
	$t_query = '?';

	if( $f_page_number > 1 )  {
		$t_query .= 'page_number=' . $f_page_number . '&';
	}

	$t_query .= 'refresh=true';

	html_meta_redirect( 'view_dwg_page.php' . $t_query, $t_refresh_delay * 60 );
}

layout_page_header_end();

layout_page_begin( __FILE__, true );

define( 'VIEW_DWG_INC_ALLOW', true );
include( __DIR__ . '/view_dwg_inc.php' );

layout_page_end();
