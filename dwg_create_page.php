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
 * This file POSTs data to report_bug.php
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses core.php
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses bug_api.php
 * @uses collapse_api.php
 * @uses columns_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses custom_field_api.php
 * @uses date_api.php
 * @uses error_api.php
 * @uses event_api.php
 * @uses file_api.php
 * @uses form_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses profile_api.php
 * @uses project_api.php
 * @uses relationship_api.php
 * @uses string_api.php
 * @uses utility_api.php
 * @uses version_api.php
 */

require_once( 'core.php' );
require_api( 'access_api.php' );
require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );

require_api( 'dwg_api.php' );

require_api( 'collapse_api.php' );
require_api( 'columns_api.php' );

require_api( 'columns_dwg_api.php' );

require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'custom_field_api.php' );
require_api( 'date_api.php' );
require_api( 'error_api.php' );
require_api( 'event_api.php' );
require_api( 'file_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );

require_api( 'print_dwg_api.php' );

require_api( 'profile_api.php' );
require_api( 'project_api.php' );
require_api( 'relationship_api.php' );
require_api( 'string_api.php' );
require_api( 'utility_api.php' );
require_api( 'version_api.php' );

/*
    In MantisBT, the term “master bug” comes from its bug relationship feature.
    A master bug is simply the parent in a relationship.
    It’s the bug that other bugs (called related, duplicate of, dependent on, child of, etc.) are linked to.
    The most common case is dependency tracking:
    If Bug A is marked as "dependent on" Bug **B", then Bug B is considered the master bug, and Bug A is the child (or dependent).
    The master bug must usually be resolved before dependent bugs can be completed.
    So in practical terms:
    👉 A master bug is the higher-level issue that other bugs depend on. It acts as an umbrella or blocker in the relationship hierarchy.
 */
$f_master_bug_id = gpc_get_int( 'm_dwg_id', 0 );

if( $f_master_bug_id > 0 ) {
	# master bug exists...
	bug_ensure_exists( $f_master_bug_id );

	# master bug is not read-only...
	if( bug_is_readonly( $f_master_bug_id ) ) {
		error_parameters( $f_master_bug_id );
		trigger_error( ERROR_BUG_READ_ONLY_ACTION_DENIED, ERROR );
	}

	# User can view the master bug
	access_ensure_bug_level( config_get( 'view_dwg_threshold' ), $f_master_bug_id );

	$t_bug = bug_get( $f_master_bug_id, true );

	#@@@ (thraxisp) Note that the master bug is cloned into the same project as the master, independent of
	#       what the current project is set to.
	if( $t_bug->project_id != helper_get_current_project() ) {
		# in case the current project is not the same project of the bug we are viewing...
		# ... override the current project. This to avoid problems with categories and handlers lists etc.
		$g_project_override = $t_bug->project_id;
		$t_changed_project = true;
	} else {
		$t_changed_project = false;
	}

	access_ensure_project_level( config_get( 'create_dwg_threshold' ) );

	$f_build				= $t_bug->build;
	$f_platform				= $t_bug->platform;
	$f_os					= $t_bug->os;
	$f_os_build				= $t_bug->os_build;
	$f_product_version		= $t_bug->version;
	$f_target_version		= $t_bug->target_version;
	$f_profile_id			= 0;
	$f_handler_id			= $t_bug->handler_id;

	$f_category_id			= $t_bug->category_id;
	$f_reproducibility		= $t_bug->reproducibility;
	$f_eta					= $t_bug->eta;
	$f_severity				= $t_bug->severity;
	$f_priority				= $t_bug->priority;
	$f_summary				= $t_bug->summary;
	$f_description			= $t_bug->description;
	$f_steps_to_reproduce	= $t_bug->steps_to_reproduce;
	$f_additional_info		= $t_bug->additional_information;
	$f_view_state			= (int)$t_bug->view_state;
	$f_due_date				= $t_bug->due_date;

	$t_project_id			= $t_bug->project_id;
} else {
	# Get Project Id and set it as current
	$t_current_project = helper_get_current_project();
	$t_project_id = gpc_get_int( 'project_id', $t_current_project );

	# If all projects, use default project if set
	$t_default_project = user_pref_get_pref( auth_get_current_user_id(), 'default_project' );
	if( ALL_PROJECTS == $t_project_id && ALL_PROJECTS != $t_default_project ) {
		$t_project_id = $t_default_project;
	}

	# Check for bug report threshold
	if( !access_has_project_level( config_get( 'create_dwg_threshold' ) ) ) {
		# If can't report on current project, show project selector if there is any other allowed project
		access_ensure_any_project_level( 'create_dwg_threshold' );
		print_header_redirect( 'login_select_proj_page.php?ref=dwg_create_page.php' );
	}

	if( ( ALL_PROJECTS == $t_project_id || project_exists( $t_project_id ) )
		&& $t_project_id != $t_current_project
		&& project_enabled( $t_project_id ) ) {
		helper_set_current_project( $t_project_id );
		# Reloading the page is required so that the project browser
		# reflects the new current project
		print_header_redirect( $_SERVER['REQUEST_URI'], true, false, true );
	}

	# New issues cannot be reported for the 'All Project' selection
	if( ALL_PROJECTS == $t_current_project ) {
		if( !print_header_redirect( 'login_select_proj_page.php?ref=dwg_create_page.php' ) ) {
			die;
		}
	}

	access_ensure_project_level( config_get( 'create_dwg_threshold' ) );

	$f_build				= gpc_get_string( 'build', '' );
	$f_platform				= gpc_get_string( 'platform', '' );
	$f_os					= gpc_get_string( 'os', '' );
	$f_os_build				= gpc_get_string( 'os_build', '' );
	$f_product_version		= gpc_get_string( 'product_version', '' );
	$f_target_version		= gpc_get_string( 'target_version', '' );
	$f_profile_id			= gpc_get_int( 'profile_id', 0 );
	$f_handler_id			= gpc_get_int( 'handler_id', 0 );

	$f_category_id			= gpc_get_int( 'category_id', 0 );
	$f_reproducibility		= gpc_get_int( 'reproducibility', (int)config_get( 'default_bug_reproducibility' ) );
	$f_eta					= gpc_get_int( 'eta', (int)config_get( 'default_bug_eta' ) );
	$f_severity				= gpc_get_int( 'severity', (int)config_get( 'default_bug_severity' ) );
	$f_priority				= gpc_get_int( 'priority', (int)config_get( 'default_bug_priority' ) );
	$f_summary				= gpc_get_string( 'summary', '' );
	$f_description			= gpc_get_string( 'description', config_get( 'default_bug_description' ) );
	$f_steps_to_reproduce	= gpc_get_string( 'steps_to_reproduce', config_get( 'default_bug_steps_to_reproduce' ) );
	$f_additional_info		= gpc_get_string( 'additional_info', config_get( 'default_bug_additional_info' ) );
	$f_view_state			= gpc_get_int( 'view_state', (int)config_get( 'default_bug_view_status' ) );
	$f_due_date				= gpc_get_string( 'due_date', date_strtotime( config_get( 'due_date_default' ) ) );

	if( $f_due_date == '' ) {
		$f_due_date = date_get_null();
	}

	$t_changed_project		= false;
}

$f_dwg_entry_stay				= gpc_get_bool( 'dwg_entry_stay', false );
$f_copy_notes_from_parent		= gpc_get_bool( 'copy_notes_from_parent', false );
$f_copy_attachments_from_parent	= gpc_get_bool( 'copy_attachments_from_parent', false );

$t_fields = config_get( 'bug_report_page_fields' );
$t_fields = columns_filter_disabled( $t_fields );

$t_show_category = in_array( 'category_id', $t_fields );
$t_show_reproducibility = in_array( 'reproducibility', $t_fields );
$t_show_eta = in_array( 'eta', $t_fields );
$t_show_severity = in_array( 'severity', $t_fields );
$t_show_priority = in_array( 'priority', $t_fields );
$t_show_steps_to_reproduce = in_array( 'steps_to_reproduce', $t_fields );
$t_show_handler = in_array( 'handler', $t_fields )
	&& access_has_project_level( config_get( 'update_bug_assign_threshold' ) );
$t_show_monitors = in_array( 'monitors', $t_fields )
	&& access_has_project_level( config_get( 'monitor_add_others_bug_threshold' ) );
$t_show_profiles = config_get( 'enable_profiles' );
$t_show_platform = $t_show_profiles && in_array( 'platform', $t_fields );
$t_show_os = $t_show_profiles && in_array( 'os', $t_fields );
$t_show_os_build = $t_show_profiles && in_array( 'os_build', $t_fields );
$t_show_resolution = in_array( 'resolution', $t_fields );
$t_show_status = in_array( 'status', $t_fields );
$t_show_tags =
	in_array( 'tags', $t_fields ) &&
	access_has_project_level(
		config_get( 'tag_attach_threshold', /* default */ null, /* user */ null, $t_project_id ),
		$t_project_id );

$t_show_versions = version_should_show_product_version( $t_project_id );
$t_show_product_version = $t_show_versions && in_array( 'product_version', $t_fields );
$t_show_product_build = $t_show_versions && in_array( 'product_build', $t_fields ) && config_get( 'enable_product_build' ) == ON;
$t_show_target_version = $t_show_versions && in_array( 'target_version', $t_fields ) && access_has_project_level( config_get( 'roadmap_update_threshold' ) );
$t_show_additional_info = in_array( 'additional_info', $t_fields );
$t_show_due_date = in_array( 'due_date', $t_fields ) && access_has_project_level( config_get( 'due_date_update_threshold' ), helper_get_current_project(), auth_get_current_user_id() );
$t_show_attachments = in_array( 'attachments', $t_fields ) && file_allow_bug_upload();
$t_show_view_state = in_array( 'view_state', $t_fields ) && access_has_project_level( config_get( 'set_view_status_threshold' ) );



// $t_show_category = false;
$t_show_reproducibility = false;
$t_show_eta = false;
$t_show_severity = false;
$t_show_priority = false;
$t_show_steps_to_reproduce = false;
$t_show_handler = false;
$t_show_monitors = false;
$t_show_profiles = false;
$t_show_platform = false;
$t_show_os = false;
$t_show_os_build = false;
$t_show_resolution = false;
$t_show_status = false;
$t_show_tags = false;
$t_show_versions = false;
$t_show_product_version = false;
$t_show_product_build = false;
$t_show_target_version = false;
$t_show_additional_info = false;
$t_show_due_date = false;
$t_show_attachments = false;
$t_show_view_state = false;



$t_has_profiles = count( profile_get_all_for_user( auth_get_current_user_id() ) ) > 0;

# don't index bug report page
html_robots_noindex();

layout_page_header( lang_get( 'create_dwg_link' ) );

layout_page_begin( __FILE__ );

$t_form_encoding = '';
if( $t_show_attachments ) {
	$t_form_encoding = 'enctype="multipart/form-data"';
}
?>
<div class="col-md-12 col-xs-12">
<form id="report_bug_form"
	method="post" <?php echo $t_form_encoding; ?>
	action="dwg_create.php">
<?php echo form_security_field( 'bug_report' ) ?>
<input type="hidden" name="m_dwg_id" value="<?php echo $f_master_bug_id ?>" />
<input type="hidden" name="project_id" value="<?php echo $t_project_id ?>" />
<div class="widget-box widget-color-blue2">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
				<?php print_icon( 'fa-edit', 'ace-icon' ); ?>
				<?php echo lang_get( 'enter_create_dwg_details_title' ) ?>
		</h4>
	</div>
<div class="widget-body dz-clickable">
<div class="widget-main no-padding">
<div class="table-responsive">
<table class="table table-bordered table-condensed">
<?php
	event_signal( 'EVENT_CREATE_DWG_FORM_TOP', array( $t_project_id ) );

	if( $t_show_category ) {
		$t_allow_no_category = config_get( 'allow_no_dwg_category' );
?>
	<tr>
		<th class="category width-30">
			<?php echo $t_allow_no_category ? '' : '<span class="required">*</span> '; ?>
			<label for="category_id">
				<?php print_documentation_link( 'category' ); ?>
			</label>
		</th>
		<td>
			<?php if( $t_changed_project ) {
				/** @noinspection PhpUndefinedVariableInspection */
				echo '[' . project_get_field( $t_bug->project_id, 'name' ) . '] ';
			} ?>
			<select id="category_id" name="category_id" class="autofocus input-sm" <?php
				echo helper_get_tab_index();
				echo $t_allow_no_category ? '' : ' required';
			?>>
				<?php
					print_category_option_list( $f_category_id, null, true );
				?>
			</select>
		</td>
	</tr>
<?php
	}
?>

<?php
	if( $t_show_severity ) {
?>
	<tr>
		<th class="category">
			<label for="severity"><?php print_documentation_link( 'severity' ) ?></label>
		</th>
		<td>
			<select <?php echo helper_get_tab_index() ?> id="severity" name="severity" class="input-sm">
				<?php print_enum_string_option_list( 'severity', $f_severity ) ?>
			</select>
		</td>
	</tr>
<?php
	}

	if( $t_show_priority ) {
?>
	<tr>
		<th class="category">
			<label for="priority"><?php print_documentation_link( 'priority' ) ?></label>
		</th>
		<td>
			<select <?php echo helper_get_tab_index() ?> id="priority" name="priority" class="input-sm">
				<?php print_enum_string_option_list( 'priority', $f_priority ) ?>
			</select>
		</td>
	</tr>
<?php
	}

	if( $t_show_due_date ) {
		$t_date_to_display = '';

		if( !date_is_null( $f_due_date ) ) {
			$t_date_to_display = date( config_get( 'normal_date_format' ), $f_due_date );
		}
?>
	<tr>
		<th class="category">
			<label for="due_date"><?php print_documentation_link( 'due_date' ) ?></label>
		</th>
		<td>
			<?php echo '<input ' . helper_get_tab_index() . ' type="text" id="due_date" name="due_date" class="datetimepicker input-sm" ' .
				'data-picker-locale="' . lang_get_current_datetime_locale() .
				'" data-picker-format="' . config_get( 'datetime_picker_format' ) . '" ' .
				'size="20" maxlength="16" value="' . $t_date_to_display . '" />' ?>
			<?php print_icon( 'fa-calendar', 'fa-xlg datetimepicker' ); ?>
		</td>
	</tr>
<?php } ?>


<?php if( $t_show_status ) { ?>
	<tr>
		<th class="category">
			<label for="status"><?php echo lang_get( 'status' ) ?></label>
		</th>
		<td>
			<select id="status" <?php echo helper_get_tab_index() ?> name="status" class="input-sm">
			<?php
			$t_resolution_options = get_status_option_list(
				access_get_project_level( $t_project_id ),
				config_get( 'bug_submit_status' ),
				true,
				ON == config_get( 'allow_reporter_close' ),
				$t_project_id );
			foreach ( $t_resolution_options as $t_key => $t_value ) {
			?>
				<option value="<?php echo $t_key ?>" <?php check_selected( $t_key, config_get( 'bug_submit_status' ) ); ?> >
					<?php echo $t_value ?>
				</option>
			<?php } ?>
			</select>
		</td>
	</tr>
<?php } ?>

<?php event_signal( 'EVENT_CREATE_DWG_FORM', array( $t_project_id ) ) ?>
	<tr>
		<th class="category">
			<span class="required">*</span>
			<label for="name"><?php print_documentation_link( 'name' ) ?></label>
		</th>
		<td>
			<input <?php echo helper_get_tab_index() ?> type="text" id="name" name="name" size="105" maxlength="128" value="<?php echo string_attribute( $f_summary ) ?>" required />
		</td>
	</tr>
	<tr>
		<th class="category">
			<span class="required">*</span>
			<label for="number"><?php print_documentation_link( 'number' ) ?></label>
		</th>
		<td>
			<input <?php echo helper_get_tab_index() ?> type="text" id="number" name="number" size="105" maxlength="128" value="<?php echo string_attribute( $f_summary ) ?>" required />
		</td>
	</tr>
	<tr>
		<th class="category">
			<label for="description"><?php print_documentation_link( 'description' ) ?></label>
		</th>
		<td>
			<?php # Newline after opening textarea tag is intentional, see #25839 ?>
			<textarea class="form-control" <?php echo helper_get_tab_index() ?> id="description" name="description" cols="80" rows="10">
<?php echo string_textarea( $f_description ) ?>
</textarea>
		</td>
	</tr>
	<tr>
		<th class="category">
			<label for="summary"><?php print_documentation_link( 'summary' ) ?></label>
		</th>
		<td>
			<input <?php echo helper_get_tab_index() ?> type="text" id="summary" name="summary" size="105" maxlength="128" value="<?php echo string_attribute( $f_summary ) ?>" />
		</td>
	</tr>

<?php

	$t_custom_fields_found = false;
	$t_related_custom_field_ids = custom_field_get_linked_ids( $t_project_id );

	foreach( $t_related_custom_field_ids as $t_id ) {
		$t_def = custom_field_get_definition( $t_id );
		if( ( $t_def['display_report'] || $t_def['require_report']) && custom_field_has_write_access_to_project( $t_id, $t_project_id ) ) {
			$t_custom_fields_found = true;

			if( $t_def['type'] != CUSTOM_FIELD_TYPE_RADIO && $t_def['type'] != CUSTOM_FIELD_TYPE_CHECKBOX ) {
				$t_label_for = 'for="custom_field_' . string_attribute( $t_def['id'] ) . '" ';
			} else {
				$t_label_for = '';
			}
?>
	<tr>
		<th class="category">
			<?php if( $t_def['require_report'] ) {?><span class="required">*</span><?php } ?>
			<?php if( $t_def['type'] != CUSTOM_FIELD_TYPE_RADIO && $t_def['type'] != CUSTOM_FIELD_TYPE_CHECKBOX ) { ?>
				<label for="custom_field_<?php echo string_attribute( $t_def['id'] ) ?>">
					<?php echo string_attribute( lang_get_defaulted( $t_def['name'] ) ) ?>
				</label>
			<?php } else { echo string_attribute( lang_get_defaulted( $t_def['name'] ) ); } ?>
		</th>
		<td>
			<?php print_custom_field_input( $t_def, ( $f_master_bug_id === 0 ) ? null : $f_master_bug_id, $t_def['require_report'] ) ?>
		</td>
	</tr>
<?php
		}
	} # foreach( $t_related_custom_field_ids as $t_id )
?>
<?php
	# File Upload (if enabled)
	if( $t_show_attachments ) {
		$t_max_file_size = file_get_max_file_size();
		$t_file_upload_max_num = max( 1, config_get( 'file_upload_max_num' ) );
?>
	<tr>
		<th class="category">
			<label for="ufile[]"><?php echo lang_get( $t_file_upload_max_num == 1 ? 'upload_file' : 'upload_files' ) ?></label>
			<br />
			<?php print_max_filesize( $t_max_file_size ); ?>
		</th>
		<td>
			<?php print_dropzone_template() ?>
			<input type="hidden" name="max_file_size" value="<?php echo $t_max_file_size ?>" />
			<div class="dropzone center" <?php print_dropzone_form_data() ?>>
				<?php print_icon( 'fa-cloud-upload', 'upload-icon ace-icon blue fa-3x' ); ?>
				<br>
				<span class="bigger-150 grey"><?php echo lang_get( 'dropzone_default_message' ) ?></span>
				<div id="dropzone-previews-box" class="dropzone-previews dz-max-files-reached"></div>
			</div>
			<div class="fallback">
				<div class="dz-message" data-dz-message></div>
				<input <?php echo helper_get_tab_index() ?> id="ufile[]" name="ufile[]" type="file" size="60" />
			</div>
		</td>
	</tr>

<?php
	}

	if( $t_show_view_state ) {
?>
	<tr>
		<th class="category">
			<?php echo lang_get( 'view_status' ) ?>
		</th>
		<td>
			<label>
				<input <?php echo helper_get_tab_index() ?> type="radio" class="ace" name="view_state" value="<?php echo VS_PUBLIC ?>" <?php check_checked( $f_view_state, VS_PUBLIC ) ?> />
				<span class="lbl padding-6"><?php echo lang_get( 'public' ) ?></span>
			</label>
			&#160;&#160;&#160;&#160;
			<label>
				<input <?php echo helper_get_tab_index() ?> type="radio" class="ace" name="view_state" value="<?php echo VS_PRIVATE ?>" <?php check_checked( $f_view_state, VS_PRIVATE ) ?> />
				<span class="lbl padding-6"><?php echo lang_get( 'private' ) ?></span>
			</label>
		</td>
	</tr>
<?php
	}

	# Relationship (in case of cloned bug creation...)
	if( $f_master_bug_id > 0 ) {
?>
	<tr>
		<th class="category">
			<?php echo lang_get( 'relationship_with_parent' ) ?>
		</th>
		<td>
			<?php print_relationship_list_box( config_get( 'default_bug_relationship_clone' ), "rel_type", false, true ) ?>
			<?php echo '<strong>' . lang_get( 'bug' ) . ' ' . bug_format_id( $f_master_bug_id ) . '</strong>' ?>
		</td>
	</tr>

	<tr>
		<td class="category">
			<?php echo lang_get( 'copy_from_parent' ) ?>
		</td>
		<td>
			<label>
				<input <?php echo helper_get_tab_index() ?> type="checkbox" class="ace" id="copy_notes_from_parent" name="copy_notes_from_parent" <?php check_checked( $f_copy_notes_from_parent ) ?> />
				<span class="lbl padding-6"><?php echo lang_get( 'copy_notes_from_parent' ) ?></span>
			</label>
			&#160;&#160;&#160;&#160;
			<label>
				<input <?php echo helper_get_tab_index() ?> type="checkbox" class="ace" id="copy_attachments_from_parent" name="copy_attachments_from_parent" <?php check_checked( $f_copy_attachments_from_parent ) ?> />
				<span class="lbl padding-6"><?php echo lang_get( 'copy_attachments_from_parent' ) ?></span>
			</label>
		</td>
	</tr>
<?php
	}
?>
	<tr>
		<th class="category">
			<?php print_documentation_link( 'dwg_entry_stay' ) ?>
		</th>
		<td>
			<label>
				<input <?php echo helper_get_tab_index() ?> type="checkbox" class="ace" id="dwg_entry_stay" name="dwg_entry_stay" <?php check_checked( $f_dwg_entry_stay ) ?> />
				<span class="lbl padding-6"><?php echo lang_get( 'check_enter_more_dwgs' ) ?></span>
			</label>
		</td>
	</tr>
</table>
</div>
</div>
<div class="widget-toolbox padding-8 clearfix">
	<span class="required pull-right"> * <?php echo lang_get( 'required' ) ?></span>
	<input <?php echo helper_get_tab_index() ?>
        type="submit"
        class="btn btn-primary btn-white btn-round"
        value="<?php echo lang_get( 'submit_dwg_button' ) ?>" />
</div>
</div>
</div>
</form>
</div>
<?php
layout_page_end();
