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
 * View all bugs include file
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses category_api.php
 * @uses columns_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses event_api.php
 * @uses filter_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses lang_api.php
 * @uses print_api.php
 */

if( !defined( 'VIEW_DWG_INC_ALLOW' ) ) {
	return;
}

require_api( 'category_api.php' );
require_api( 'columns_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'event_api.php' );
require_api( 'filter_dwg_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_dwg_api.php' );
// require_api( 'dwg_api.php' );  // we are picking this up from filter_dwg_api.php, but should probably have it here anyway

/**
 * Variables defined in parent script.
 * @var array $g_dwg_filter
 * @var array $t_rows
 * @var array $t_unique_project_ids
 * @var int $f_page_number
 * @var int $t_page_count
 * @var int $t_bug_count
 */
$t_filter = current_user_get_dwg_filter();
filter_dwg_init( $t_filter );

list( $t_sort, ) = explode( ',', $g_dwg_filter['sort'] );
list( $t_dir, ) = explode( ',', $g_dwg_filter['dir'] );

$g_checkboxes_exist = false;

$t_current_project = helper_get_current_project();
# Improve performance by caching category data in one pass
if( $t_current_project > 0 ) {
	category_get_all_rows( $t_current_project );
}

$g_columns = helper_get_dwg_columns_to_view( COLUMNS_TARGET_VIEW_PAGE );

dwg_cache_columns_data( $t_rows, $g_columns );

$t_filter_position = config_get( 'filter_position' );

# -- ====================== FILTER FORM ========================= --
if( ( $t_filter_position & FILTER_POSITION_TOP ) == FILTER_POSITION_TOP ) {
	filter_dwg_draw_selection_area();
}
# -- ====================== end of FILTER FORM ================== --


# -- ====================== BUG LIST ============================ --

?>
<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
	<form id="dwg_action" method="post" action="dwg_actiongroup_page.php">
		<?php # CSRF protection not required here - form does not result in modifications ?>
		<div class="widget-box widget-color-blue2">
			<div class="widget-header widget-header-small">
				<h4 class="widget-title lighter">
<?php
	print_icon( 'fa-columns', 'ace-icon' );
	echo lang_get( 'viewing_dwg_title' );

	# Viewing range info
	$v_start = 0;
	$v_end = 0;
	if (count($t_rows) > 0) {
		$v_start = $g_dwg_filter['per_page'] * ($f_page_number - 1) + 1;
		$v_end = $v_start + count($t_rows) - 1;
	}
	echo '<span class="badge"> ' . $v_start . ' - ' . $v_end . ' / ' . $t_bug_count . '</span>' ;
?>
				</h4>
			</div>

<?php
	# -- ====================== TOP TOOLBAR ============================ --

	$t_filter_param = filter_dwg_get_temporary_key_param( $t_filter );
	if( empty( $t_filter_param ) ) {
		$t_summary_link = 'view_dwg_set.php?summary=1&temporary=y';
	} else {
		$t_filter_param = '?' . $t_filter_param;
		$t_summary_link = 'summary_page.php' . $t_filter_param;
	}

	$t_can_print_reports = access_has_project_level( config_get( 'print_reports_threshold' ), $t_current_project );
	$t_can_export_issues = access_has_project_level( config_get( 'export_issues_threshold' ), $t_current_project );
	$t_can_view_summary = access_has_project_level( config_get( 'view_summary_threshold' ), $t_current_project );

	# Plugin menu items
	$t_event_menu_options = event_signal( 'EVENT_MENU_FILTER' );
	ob_start();
	foreach( $t_event_menu_options as $t_plugin => $t_plugin_menu_options ) {
		foreach( $t_plugin_menu_options as $t_callback => $t_callback_menu_options ) {
			if( !is_array( $t_callback_menu_options ) ) {
				$t_callback_menu_options = array( $t_callback_menu_options );
			}

			foreach( $t_callback_menu_options as $t_menu_option ) {
				if( $t_menu_option ) {
					echo $t_menu_option;
				}
			}
		}
	}
	$t_plugin_menu_items = ob_get_clean();

	# Page number links
	ob_start();
	print_page_links(
		'view_dwg_page.php',
		1, $t_page_count, $f_page_number,
		filter_dwg_get_temporary_key( $t_filter )
	);
	$t_page_number_links = ob_get_clean();

	# Only display the toolbar if there's anything to print in it
	if( $t_can_print_reports
		|| $t_can_export_issues
		|| $t_can_view_summary
		|| $t_plugin_menu_items
		|| $t_page_number_links
	) {
?>
			<div class="widget-body">
				<div class="widget-toolbox padding-8 clearfix">
					<div class="btn-toolbar">
						<div class="btn-group pull-left">
<?php
		if( $t_can_print_reports ) {
			print_small_button(
				'print_dwg_page.php' . $t_filter_param,
				lang_get( 'print_dwg_page_link' )
			);
		}
		if( $t_can_export_issues ) {
			print_small_button( 'csv_export.php' . $t_filter_param, lang_get( 'csv_export' ) );
			print_small_button( 'excel_xml_export.php' . $t_filter_param, lang_get( 'excel_export' ) );
		}
		if( $t_can_view_summary ) {
			print_small_button( $t_summary_link, lang_get( 'summary_link' ) );
		}

		echo $t_plugin_menu_items;
?>
						</div>
<?php
		if( $t_page_number_links ) {
?>
						<div class="btn-group pull-right">
							<?php echo $t_page_number_links ?>
						</div>
<?php
		}
?>
					</div>
				</div>
			</div>
<?php
	}

	# -- ====================== end of TOP TOOLBAR ============================ --
?>

			<div class="widget-main no-padding">
				<div class="table-responsive checkbox-range-selection">
					<table id="buglist" class="table table-bordered table-condensed table-hover table-striped">
						<thead>
<?php # -- Bug list column header row -- ?>
							<tr class="buglist-headers">
<?php
	$t_title_function = 'print_dwg_column_title';  // @TODO RobD - setting this causes most* all the column title hyperlinks to not be hyperlinks (* only the first column 'status' remains as a hyperlink?)
	$t_sort_properties = filter_dwg_get_visible_sort_properties_array( $t_filter, COLUMNS_TARGET_VIEW_PAGE );
	foreach( $g_columns as $t_column ) {
		helper_call_custom_function( $t_title_function, array( $t_column, COLUMNS_TARGET_VIEW_PAGE, $t_sort_properties ) );
	}
?>
							</tr>
						</thead>

						<tbody>

<?php
	write_dwg_rows( $t_rows );
	# -- ====================== end of BUG LIST ========================= --
?>

						</tbody>
					</table>
				</div>

				<div class="widget-toolbox padding-8 clearfix">
<?php
# -- ====================== MASS BUG MANIPULATION =================== --
# @@@ ideally buglist-footer would be in <tfoot>, but that's not possible due to global g_checkboxes_exist set via write_dwg_rows()
?>
					<div class="form-inline pull-left">
<?php
		/**
		 * Global $g_checkboxes_exist is set in write_dwg_rows() via the
		 * print_column_value custom function.
		 * @noinspection PhpConditionAlreadyCheckedInspection
		 */
		if( $g_checkboxes_exist ) {
			echo '<label class="inline">';
			echo '<input class="ace check_all input-sm" type="checkbox" id="dwg_arr_all" name="dwg_arr_all" value="all" />';
			echo '<span class="lbl padding-6">' . lang_get( 'select_all' ) . ' </span > ';
			echo '</label>';
?>
			<!--suppress HtmlFormInputWithoutLabel -->
			<select name="action" class="input-sm">
				<?php print_dwg_all_dwg_action_option_list( $t_unique_project_ids ) ?>
			</select>
			<input type="submit" class="btn btn-primary btn-white btn-sm btn-round" value="<?php echo lang_get('ok'); ?>"/>
<?php
		} else {
			echo '&#160;';
		}
?>
					</div>

					<div class="btn-group pull-right">
						<?php echo $t_page_number_links ?>
					</div>
<?php # -- ====================== end of MASS BUG MANIPULATION ========================= -- ?>
				</div>

			</div>
		</div>
	</form>
</div>
<?php

# -- ====================== FILTER FORM ========================= --
if( ( $t_filter_position & FILTER_POSITION_BOTTOM ) == FILTER_POSITION_BOTTOM ) {
	filter_dwg_draw_selection_area();
}
# -- ====================== end of FILTER FORM ================== --

/**
 * Output Rows
 *
 * @param array $p_rows An array of objects.
 * @return void
 */
function write_dwg_rows( array $p_rows ) {
	global $g_columns, $g_dwg_filter;

	$t_in_stickies = ( $g_dwg_filter && ( 'on' == $g_dwg_filter[FILTER_PROPERTY_STICKY] ) );

	# Loop over rows
	$t_rows = count( $p_rows );
	for( $i = 0; $i < $t_rows; $i++ ) {
		$t_row = $p_rows[$i];

		if( ( 0 == $t_row->sticky ) && ( 0 == $i ) ) {
			$t_in_stickies = false;
		}
		if( ( 0 == $t_row->sticky ) && $t_in_stickies ) {
			# demarcate stickies, if any have been shown
			echo '<tr class="sticky-separator"><td colspan="' . count( $g_columns ) . '"></td></tr>';
			$t_in_stickies = false;
		}

		echo '<tr>';
		foreach( $g_columns as $t_column ) {
			helper_call_custom_function( 'print_dwg_column_value', array( $t_column, $t_row ) );
		}
		echo '</tr>';
	}
}
