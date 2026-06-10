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
 * Project Page
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses category_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses icon_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses license_api.php
 * @uses string_api.php
 * @uses user_api.php
 * @uses utility_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'category_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'icon_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'license_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );
require_api( 'utility_api.php' );

auth_reauthenticate();

$f_sort	= gpc_get_string( 'sort', 'name' );
$f_dir	= gpc_get_string( 'dir', 'ASC' );

if( 'ASC' == $f_dir ) {
	$t_direction = ASCENDING;
} else {
	$t_direction = DESCENDING;
}

layout_page_header( lang_get( 'manage_licenses_link' ) );

layout_page_begin( 'manage_overview_page.php' );

print_manage_menu( 'manage_license_page.php' );

# License Menu Form BEGIN
?>

<div class="col-md-12 col-xs-12">
    <div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-puzzle-piece', 'ace-icon' ); ?>
			<?php echo lang_get( 'licenses_title' ) ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="widget-toolbox padding-8 clearfix">
		<?php
		# Check the user's global access level before allowing license creation
		if( access_has_global_level ( config_get( 'create_license_threshold' ) ) ) {
			print_form_button(
				'manage_license_create_page.php',
				lang_get( 'create_new_license_link' ),
				[],
				null,
				'btn btn-primary btn-white btn-round'
			);
		} ?>
	</div>
	<div class="table-responsive">
	<table class="table table-striped table-bordered table-condensed table-hover">
		<thead>
			<tr>
				<th><?php
					print_manage_license_sort_link(
						'manage_license_page.php', lang_get( 'name' ),
						'name',
						$t_direction,
						$f_sort
					);
					print_sort_icon( $t_direction, $f_sort, 'name' ); ?>
				</th>
				<th><?php
					print_manage_license_sort_link(
						'manage_license_page.php',
						lang_get( 'status' ),
						'status',
						$t_direction,
						$f_sort
					);
					print_sort_icon( $t_direction, $f_sort, 'status' ); ?>
				</th>
				<th class="center"><?php
					print_manage_license_sort_link(
						'manage_license_page.php',
						lang_get( 'enabled' ),
						'enabled',
						$t_direction,
						$f_sort
					);
					print_sort_icon( $t_direction, $f_sort, 'enabled' ); ?>
				</th>
				<th><?php
					print_manage_license_sort_link(
						'manage_license_page.php',
						lang_get( 'view_status' ),
						'view_state',
						$t_direction,
						$f_sort
					);
					print_sort_icon( $t_direction, $f_sort, 'view_state' ); ?>
				</th>
				<th><?php
					print_manage_license_sort_link(
						'manage_license_page.php',
						lang_get( 'description' ),
						'description',
						$t_direction,
						$f_sort
					);
					print_sort_icon( $t_direction, $f_sort, 'description' ); ?>
				</th>
			</tr>
		</thead>

		<tbody>
<?php
	// return user_get_accessible_license( auth_get_current_user_id(), $p_show_disabled );
		$t_manage_license_threshold = config_get( 'manage_license_threshold' );
		$t_licenses = user_get_accessible_licenses( auth_get_current_user_id(), true );
		// $t_licenses = user_get_accessible_licenses( auth_get_current_user_id(), false );
		$t_full_licenses = array();
		foreach ( $t_licenses as $t_license_id ) {
			$t_full_licenses[] = license_get_row( $t_license_id );
		}
		$t_licenses = multi_sort( $t_full_licenses, $f_sort, $t_direction );
		$t_stack = array( $t_licenses );

		while( 0 < count( $t_stack ) ) {
			$t_licenses = array_shift( $t_stack );

			if( 0 == count( $t_licenses ) ) {
				continue;
			}

			$t_license = array_shift( $t_licenses );
			$t_license_id = $t_license['id'];
			$t_level      = count( $t_stack );
$t_project_id = helper_get_current_project();
			# only print row if user has license management privileges
			// if( access_has_license_level( $t_manage_license_threshold, $t_license_id, auth_get_current_user_id() ) ) { ?>
			<tr>
				<td>
					<a href="manage_license_edit_page.php?project_id=<?php echo $t_project_id ?>&license_id=<?php echo $t_license['id'] ?>">
<?php /*					<a href="manage_license_edit_page.php?project_id=1&license_id=<?php echo $t_license['id'] ?>"> */ ?>
<?php /*					<a href="manage_license_edit_page.php?license_id=<?php echo $t_license['id'] ?>"> */ ?>
						<?php echo str_repeat( "&raquo; ", $t_level )
							. string_display_line( $t_license['name'] ) ?>
					</a>
				</td>
				<td><?php echo get_enum_element( 'license_status', $t_license['status'] ) ?></td>
				<td class="center"><?php echo trans_bool( $t_license['enabled'] ) ?></td>
				<td><?php echo get_enum_element( 'license_view_state', $t_license['view_state'] ) ?></td>
				<td><?php echo string_display_links( $t_license['description'] ) ?></td>
			</tr><?php
			// }
			// $t_sublicenses = license_hierarchy_get_sublicenses( $t_license_id, true );
			$t_sublicenses = array();

			if( 0 < count( $t_licenses ) || 0 < count( $t_sublicenses ) ) {
				array_unshift( $t_stack, $t_licenses );
			}

			// if( 0 < count( $t_sublicenses ) ) {
			// 	$t_full_licenses = array();
			// 	foreach ( $t_sublicenses as $t_license_id ) {
			// 		$t_full_licenses[] = license_get_row( $t_license_id );
			// 	}
			// 	$t_sublicenses = multi_sort( $t_full_licenses, $f_sort, $t_direction );
			// 	array_unshift( $t_stack, $t_sublicenses );
			// }
		} ?>
		</tbody>
	</table>
</div>
	</div>
	</div>
	</div>

	<div class="space-10"></div>

<?php
echo '</div>';
layout_page_end();
