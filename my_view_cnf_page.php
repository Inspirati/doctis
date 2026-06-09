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
 * Overview Page
 *
 * @package MantisBT
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses event_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses lang_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'category_api.php' );
require_api( 'compress_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'event_api.php' );
require_api( 'filter_api.php' );
require_api( 'filter_dwg_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'license_api.php' );
require_api( 'print_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'user_api.php' );
require_api( 'layout_api.php' );
require_css( 'status_config.php' );

const TIMELINE_INC_ALLOW = true;
const TIMELINE_DWG_INC_ALLOW = true;

//auth_reauthenticate();
//access_ensure_global_level( config_get( 'manage_site_threshold' ) );

auth_ensure_user_authenticated();

$t_current_user_id = auth_get_current_user_id();
$t_current_project_id = helper_get_current_project();

# Improve performance by caching category data in one pass
category_get_all_rows( $t_current_project_id );

compress_enable();

# don't index my view page
html_robots_noindex();

layout_page_header( lang_get( 'my_view_link' ) );

//layout_page_header_begin( lang_get( 'my_view_link' ) );
//$t_refresh_delay = current_user_get_pref( 'refresh_delay' );
//if( $t_refresh_delay > 0 ) {
//	html_meta_redirect( 'my_view_cnf_page.php?refresh=true', $t_refresh_delay * 60 );
//}
//layout_page_header_end();

layout_page_begin( 'my_view_bug_page.php', true );

print_my_view_menu( 'my_view_cnf_page.php' );


?>
<div class="space-10"></div>
<div class="col-md-12 col-xs-12">
	<div class="alert alert-warning center">
	<?php
		echo 'This page is a Work In Progress (WIP)';
	?>
	</div>
</div>
<?php


$f_page_number = gpc_get_int( 'page_number', 1 );

$t_per_page = config_get( 'my_view_dwg_count' );
$t_bug_count = null;
$t_dwg_count = null;
$t_page_count = null;

# The projects that need to be evaluated are those that will be included in the filters
# used for each box. At this point, those filter are created for "current" project, and
# may include subprojects, or not, based on the default "_view_type" property
# Unless these following checks are redesigned to account for the actual filters used,
# we will assume if subprojects are included by inspecting a default filter for current project.
if( $t_current_project_id == ALL_PROJECTS ) {
	$t_project_ids_to_check = null;
} else {
	# this creates a filter with the specific project informes, in the same way that
	# those that will be used later for the boxes
	$t_test_filter = filter_ensure_valid_filter( array( FILTER_PROPERTY_PROJECT_ID => [$t_current_project_id]) );
	$t_project_ids_to_check = filter_get_included_projects( $t_test_filter );
}

# Retrieve the boxes to display
# - exclude hidden boxes per configuration (order == 0)
# - remove boxes that do not make sense in the user's context (access level)
$t_boxes = array_filter( config_get( 'my_view_boxes' ) );
$t_anonymous_user = current_user_is_anonymous();
foreach( $t_boxes as $t_box_title => $t_box_display ) {
	if( # Remove "Assigned to Me" box for users that can't handle issues
		(  $t_box_title == 'assigned'
		&& (  $t_anonymous_user
		   || !access_has_any_project_level('handle_dwg_threshold', $t_project_ids_to_check, $t_current_user_id )
		   )
		) ||
		# Remove "Monitored by Me" box for users that can't monitor issues
		(  $t_box_title == 'monitored'
		&& (  $t_anonymous_user
		   || !access_has_any_project_level( 'monitor_dwg_threshold', $t_project_ids_to_check, $t_current_user_id )
		   )
		) ||
		# Remove display of "Reported by Me", "Awaiting Feedback" and
		# "Awating confirmation of resolution" boxes for users that can't report bugs
		(  in_array( $t_box_title, array( 'reported', 'feedback', 'verify' ) )
		&& (  $t_anonymous_user
		   || !access_has_any_project_level( 'create_dwg_threshold', $t_project_ids_to_check, $t_current_user_id )
		   )
		)
	) {
		unset( $t_boxes[$t_box_title] );
	}
}
asort( $t_boxes );

$t_timeline_view_threshold_access = access_has_any_project_level( config_get( 'timeline_view_threshold' ), $t_project_ids_to_check, $t_current_user_id );
$t_timeline_view_class = ( $t_timeline_view_threshold_access ) ? "col-md-7" : "col-md-6";
?>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-info', 'ace-icon' ); ?>
			<?php echo lang_get('user_status') ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table id="manage-overview-table" class="table table-hover table-bordered table-condensed">
<?php
echo '<div class="col-md-6 col-xs-12">';
if( !current_user_is_anonymous() ) {
	$t_current_user_id = auth_get_current_user_id();
	$t_hide_status = config_get( 'bug_resolved_status_threshold' );
	echo '<span class="bigger-120">';
	echo lang_get( 'open_and_assigned_to_me_label' ) . lang_get( 'word_separator' );
	print_hyperlink( "view_all_set.php?type=" . FILTER_ACTION_PARSE_NEW
		. "&handler_id=$t_current_user_id&hide_status=$t_hide_status",
		current_user_get_assigned_open_bug_count()
	);
	echo '<br />';
	echo lang_get( 'dwg_open_and_assigned_to_me_label' ) . lang_get( 'word_separator' );
	print_hyperlink( "view_dwg_set.php?type=" . FILTER_ACTION_PARSE_NEW
		. "&handler_id=$t_current_user_id&hide_status=$t_hide_status",
		current_user_get_assigned_open_dwg_count()
	);
	echo '<br />';
	echo lang_get( 'open_and_reported_to_me_label' ) . lang_get( 'word_separator' );
	print_hyperlink( "view_all_set.php?type=" . FILTER_ACTION_PARSE_NEW
		. "&reporter_id=$t_current_user_id&hide_status=$t_hide_status",
		current_user_get_reported_open_bug_count()
	);
	echo '<br />';
	echo lang_get( 'dwg_open_and_created_to_me_label' ) . lang_get( 'word_separator' );
	print_hyperlink( "view_dwg_set.php?type=" . FILTER_ACTION_PARSE_NEW
		. "&reporter_id=$t_current_user_id&hide_status=$t_hide_status",
		current_user_get_created_open_dwg_count()
	);
	echo '<br />';
	echo lang_get( 'last_visit_label' ) . lang_get( 'word_separator' );
	echo date( config_get( 'normal_date_format' ), current_user_get_field( 'last_visit' ) );
	echo '</span>';
}
echo '</div>';
?>

	<?php
	// print_table_spacer( 2 );
	$t_is_admin = !current_user_is_anonymous();
	?>
	</table>
	</div>
	</div>
	</div>
	</div>
</div>

<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-info', 'ace-icon' ); ?>
			<?php echo lang_get('user_configuration') ?>
		</h4>
	</div>
	<div class="widget-body">
	<div class="widget-main no-padding">
	<div class="table-responsive">
	<table id="manage-overview-table" class="table table-hover table-bordered table-condensed">
<?php /* ...
		<tr>
			<th class="category"><?php echo lang_get( 'mantis_version' ) ?></th>
			<td><?php echo MANTIS_VERSION . config_get_global( 'version_suffix' ) ?></td>
		</tr>
		<tr>
			<th class="category"><?php echo lang_get( 'schema_version' ) ?></th>
			<td><?php echo config_get( 'database_version', 0, ALL_USERS, ALL_PROJECTS ) ?></td>
		</tr>
 */ ?>
 TODO:<br>
   add timelines for only events pertinent to the user, both Issues and Documents<br>
   add projects list which allows user to enable/disable participation in each project<br>
   add checkbox to enable dark theme (after implementing a dark theme)<br>
	<?php
	print_table_spacer( 2 );
	$t_is_admin = !current_user_is_anonymous();
	if( $t_is_admin ) {
	?>
<?php /* ...
		<tr>
			<th class="category"><?php echo lang_get( 'php_version' ) ?></th>
			<td><?php echo phpversion() ?></td>
		</tr>
 */ ?>
	<?php
		print_table_spacer( 2 );
	}

	// event_signal( 'EVENT_MANAGE_OVERVIEW_INFO', array( $t_is_admin ) )
	?>
	</table>
	</div>
	</div>
	</div>
	</div>
</div>

<?php
define( 'MY_VIEW_CNF_INC_ALLOW', true );

# Determine the box number where column 2 should start
# Use shift-right bitwise operator to divide by 2 as integer
$t_column2_start = ( count( $t_boxes ) + 1 ) >> 1;

$t_counter = 0;
foreach( $t_boxes as $t_box_title => $t_box_display ) {
    # If timeline is OFF, display boxes on 2 columns
    if( !$t_timeline_view_threshold_access && $t_counter++ == $t_column2_start ) {
        # End of 1st column
        echo '</div>';
        echo '<div class="col-xs-12 col-md-6">';
    }
    // include( __DIR__ . '/my_view_cnf_inc.php' );
    echo '<div class="space-10"></div>';
}
?>
</div>

<?php /*
<?php if( $t_timeline_view_threshold_access ) { ?>
<div class="col-xs-12 col-md-5">
	<?php
		# Build a simple filter that gets all bugs for current project
		$g_timeline_filter = array();
		$g_timeline_filter[FILTER_PROPERTY_HIDE_STATUS] = array( META_FILTER_NONE );
		$g_timeline_filter[FILTER_PROPERTY_HANDLER_ID] = $t_current_user_id;
//		FILTER_PROPERTY_PROJECT_ID => array( ALL_PROJECTS ),
		$g_timeline_filter = filter_ensure_valid_filter( $g_timeline_filter );
		include( 'timeline_inc.php' );
	?>
	<div class="space-10"></div>
</div>
<?php } ?>
 */ ?>


<?php
# License Menu Form BEGIN
$f_sort	= gpc_get_string( 'sort', 'name' );
$f_dir	= gpc_get_string( 'dir', 'ASC' );

if( 'ASC' == $f_dir ) {
	$t_direction = ASCENDING;
} else {
	$t_direction = DESCENDING;
}

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
?>



<?php
if( true ) {
	$t_collapse_block = is_collapsed( 'licenses' );
	$t_block_css = $t_collapse_block ? 'collapsed' : '';
	$t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';
?>
	<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<div id="licenses" class="widget-box widget-color-blue2 <?php echo $t_block_css ?>">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-users', 'ace-icon' ); ?>
				<?php echo lang_get( 'licenses_dwg_mine' ) ?>
			</h4>
			<div class="widget-toolbar">
				<a data-action="collapse" href="#">
					<?php print_icon( $t_block_icon, '1 ace-icon bigger-125' ); ?>
				</a>
			</div>
		</div>

		<div class="widget-body">
		<div class="widget-main no-padding">
		<div class="table-responsive">
			<table class="table table-bordered table-condensed table-striped">
			<tr>
				<th class="category width-15">
					<label for="dwg_license_list_license_to_add">
						<?php echo lang_get( 'licenses_dwg_list' ); ?>
					</label>
				</th>
				<td class="width-85">
<?php
	// $t_license_ids = user_get_accessible_licenses( auth_get_current_user_id(), true );
	// $t_license_ids = current_user_get_accessible_licenses( true );
	$t_license_ids = user_get_granted_licenses( auth_get_current_user_id(), true );
	$t_show_link = false;
	$t_first = true;
	foreach( $t_license_ids as $t_license_id ) {
//		$t_license = array();
//		foreach ( $t_licenses as $t_license_id ) {
			// $t_license[] = license_get_row( $t_license_id );
			$t_license = license_get_row( $t_license_id );
//		}
		if( $t_first ) {
			$t_first = false;
		} else {
			if( $t_show_link ) {
				echo ' ';
			} else {
				echo ', ';
			}
		}
		// $t_show_link = config_get( 'manage_license_threshold' );
		if( license_user_has_applied($t_license['id'], 20) ) {
			print_license( $t_license['id'], $t_show_link );
		} else {
			// print_license( $t_license['id'], true, '#cc0000' );
			if( license_user_has_applied($t_license['id'], 10) ) {
				print_license( $t_license['id'], $t_show_link, 'brown' );
			} else {
				print_license( $t_license['id'], $t_show_link );
			}
		}
	}
 ?>
				
			<br /><br />
			<form method="post" action="manage_license_user_add.php" class="form-inline noprint">
				<?php echo form_security_field( 'manage_license_user_add' ) ?>
<?php
$f_project_id = 0;
$f_license_id = 0;
$f_user_id = 0;
?>

<?php /*
	<input type="hidden" name="bug_id" value="<?php echo (integer)$f_issue_id; ?>" />
	<input type="hidden" name="user_id" value="<?php echo (integer)$t_current_user_id; ?>" />
	<input type="hidden" name="project_id" value="<?php echo (integer)$t_issue['project']; ?>" />
	<input type="hidden" name="access_level" value="10" />
	<?php
		foreach( $t_license_apply_for as $t_license ) {
			echo '<input type="hidden" name="license_id[]" value="' . $t_license . '" />' . "\n";
		}
	?>
 */ ?>

				<input type="hidden" name="project_id" value="<?php echo $f_project_id ?>" />
				<input type="hidden" name="license_id" value="<?php echo $f_license_id ?>" />
				<input type="hidden" name="user_id" value="<?php echo $f_user_id ?>" />

				<!--suppress HtmlFormInputWithoutLabel -->
				<input type="text" class="input-sm" id="dwg_license_list_license_to_add" name="license_to_add" />
				<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="<?php echo lang_get( 'add' ) ?>" />
			</form>


				</td>
			</tr>
			</table>
			

		</div>
		</div>
		</div>
	<!-- </div>
	</div>
	</div> -->

</div>
<div class="space-10"></div>
<div class="space-10"></div>

<?php }
layout_page_end();
