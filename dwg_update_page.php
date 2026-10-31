<?php
# Doctis - Document Issue Tracking System
#
# dwg_update_page.php — edit a document's record.
#
# Rewritten 2026-11-01.  The page it replaces was a copy of MantisBT's
# bug_update_page.php that had never been finished: it was titled "(WIP)",
# offered severity, reproducibility, resolution, projection and ETA (issue
# fields a document has no column for), had summary and description
# commented out, and did not offer the document's own record -- title,
# author, publisher, number, edition, revision, classification, discipline
# and its dates -- at all.  Its "Edit Document" button led to a copy of the
# create page that would have made a new document rather than edit this one.
#
# This page edits the whole record, in four parts that mirror the view page:
#
#   Document  the bibliographic record ({documents} and its {dwg} siblings)
#   Record    where it sits and how it is tracked: category, status, view
#             state, priority, due date
#   People    owner, assignee, and (read-only) who created it
#   Text      description and additional information
#
# then custom fields and an optional note, as before.
#
# Each field still follows $g_dwg_update_page_fields, so an install can hide
# what it does not use; the title is always shown, because a document must
# have one.
#
# The Owner is deliberately not an ordinary field.  Changing who is
# responsible for a document is a significant act, so it is shown as
# "name [Edit]" and the picker appears only when asked for -- in place, by
# js/dwg_update.js, so nothing typed elsewhere on the form is lost; without
# scripting the [Edit] link reloads the page with the picker open.  Until it
# is opened the picker is disabled, so the form cannot change the owner by
# accident.  Ownership rules are enforced in dwg_update.php.
#
# A read-only (for example archived) document cannot be edited here.  Its
# owner can still be replaced from the view page, which ownership is exempt
# for (doc/dwg/OWNERSHIP.md §9.2).
#
# Posts to dwg_update.php.

require_once( 'core.php' );
require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'category_api.php' );
require_api( 'columns_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'custom_field_api.php' );
require_api( 'date_api.php' );
require_api( 'dwg_api.php' );
require_api( 'error_api.php' );
require_api( 'event_api.php' );
require_api( 'file_dwg_api.php' );
require_api( 'form_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'last_visited_dwg_api.php' );
require_api( 'print_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

require_css( 'status_config.php' );

$f_bug_id     = gpc_get_int( 'bug_id' );
$f_owner_edit = gpc_get_bool( 'owner_edit' );

$t_bug = dwg_get( $f_bug_id, true );

if( $t_bug->project_id != helper_get_current_project() ) {
	# Categories, handlers and custom fields belong to the document's project.
	$g_project_override = $t_bug->project_id;
}

if( dwg_is_readonly( $f_bug_id ) ) {
	error_parameters( $f_bug_id );
	trigger_error( ERROR_DWG_READ_ONLY_ACTION_DENIED, ERROR );
}

access_ensure_dwg_level( config_get( 'update_dwg_threshold' ), $f_bug_id );

$t_fields = columns_filter_disabled( config_get( 'dwg_update_page_fields' ) );
$t_show   = function( string $p_field ) use ( $t_fields ): bool {
	return in_array( $p_field, $t_fields, true );
};

$t_primary  = file_dwg_primary_get( $f_bug_id );
$t_has_file = $t_primary !== null;

# Who may change what.
$t_can_view_state = $t_show( 'view_state' )
	&& access_has_dwg_level( config_get( 'change_view_status_threshold' ), $f_bug_id );
$t_can_assign = access_has_dwg_level(
	config_get( 'update_dwg_assign_threshold', config_get( 'update_dwg_threshold' ) ), $f_bug_id );
$t_can_change_owner = access_dwg_can_set_owner( $f_bug_id )
	|| access_dwg_can_claim_owner( $f_bug_id )
	|| access_dwg_can_release_owner( $f_bug_id );
$t_can_due_date = access_has_dwg_level( config_get( 'due_date_update_threshold' ), $f_bug_id );
$t_show_handler = $t_show( 'handler' )
	&& access_has_dwg_level( config_get( 'view_handler_threshold' ), $f_bug_id );
$t_show_due_date = $t_show( 'due_date' )
	&& access_has_dwg_level( config_get( 'due_date_view_threshold' ), $f_bug_id );

/**
 * A date as the date picker shows it, or '' for none.
 *
 * @param mixed $p_time Unix timestamp; 1 (date_get_null()) means none.
 * @return string
 */
function dwg_update_page_date( $p_time ): string {
	return dwg_format_date( $p_time );
}

/**
 * Print one labelled text input row cell pair.
 *
 * @param string $p_name      Form field name, also the label key.
 * @param string $p_value     Current value.
 * @param int    $p_maxlength Column width.
 * @return void
 */
function dwg_update_page_text( string $p_name, string $p_value, int $p_maxlength ): void {
	echo '<th class="category width-15"><label for="', $p_name, '">', lang_get( $p_name ), '</label></th>';
	echo '<td class="width-35"><input ', helper_get_tab_index(), ' type="text" id="', $p_name,
		'" name="', $p_name, '" class="form-control input-sm" maxlength="', $p_maxlength,
		'" value="', string_attribute( $p_value ), '" /></td>';
}

/**
 * Print one labelled date picker cell pair.
 *
 * @param string $p_name  Form field name, also the label key.
 * @param mixed  $p_value Unix timestamp.
 * @return void
 */
function dwg_update_page_date_input( string $p_name, $p_value ): void {
	echo '<th class="category width-15"><label for="', $p_name, '">', lang_get( $p_name ), '</label></th>';
	echo '<td class="width-35"><input ', helper_get_tab_index(), ' type="text" id="', $p_name,
		'" name="', $p_name, '" class="datetimepicker input-sm" size="16" maxlength="16"',
		' data-picker-locale="', lang_get_current_datetime_locale(), '"',
		' data-picker-format="', config_get( 'dwg_date_picker_format' ), '"',
		' value="', string_attribute( dwg_update_page_date( $p_value ) ), '" /> ';
	print_icon( 'fa-calendar', 'fa-xlg datetimepicker' );
	echo '</td>';
}

/**
 * Lay out a list of [label+input] printers two to a row.
 *
 * @param array $p_cells Callables, each printing one <th>+<td> pair.
 * @return void
 */
function dwg_update_page_rows( array $p_cells ): void {
	foreach( array_chunk( $p_cells, 2 ) as $t_pair ) {
		echo '<tr>';
		foreach( $t_pair as $t_cell ) {
			$t_cell();
		}
		if( count( $t_pair ) === 1 ) {
			echo '<td colspan="2">&#160;</td>';
		}
		echo '</tr>';
	}
}

layout_page_header( dwg_format_summary( $f_bug_id, SUMMARY_CAPTION ) );
layout_page_begin( null, true );
?>
<div class="col-md-12 col-xs-12">
<div id="dwg-update" class="form-container">
	<form id="update_dwg_form" method="post" action="dwg_update.php">
		<?php echo form_security_field( 'dwg_update' ); ?>
		<input type="hidden" name="bug_id" value="<?php echo (int)$f_bug_id ?>" />
		<input type="hidden" name="last_updated" value="<?php echo string_attribute( (string)$t_bug->last_updated ) ?>" />

		<div class="widget-box widget-color-blue2">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-pencil', 'ace-icon' ); ?>
				<?php echo lang_get( 'updating_dwg_advanced_title' ) ?>
				&mdash; <?php echo string_display_line( dwg_format_id( $f_bug_id ) ) ?>
			</h4>
			<div class="widget-toolbar no-border">
				<div class="widget-menu">
					<?php print_dwg_extra_small_button( string_get_dwg_view_url( $f_bug_id ), lang_get( 'back_to_dwg_link' ) ); ?>
				</div>
			</div>
		</div>
		<div class="widget-body">
		<div class="widget-main no-padding">
		<div class="table-responsive">
		<table class="table table-bordered table-condensed">
			<tbody>
<?php
event_signal( 'EVENT_UPDATE_DWG_FORM_TOP', array( $f_bug_id ) );

# ── Document ────────────────────────────────────────────────────────────────
echo '<tr><td colspan="4" class="bold">', lang_get( 'dwg_edit_section_document' ), '</td></tr>';

# The title is always offered: a document must have one.
echo '<tr><th class="category"><span class="required">*</span> <label for="dwg_title">',
	lang_get( 'dwg_title' ), '</label></th>';
echo '<td colspan="3"><input ', helper_get_tab_index(), ' type="text" id="dwg_title" name="dwg_title"',
	' class="form-control" maxlength="255" required value="',
	string_attribute( (string)( $t_bug->title !== '' ? $t_bug->title : $t_bug->summary ) ), '" /></td></tr>';

$t_cells = array();
foreach( array(
	'number'         => array( 'dwg_number', 64 ),
	'revision'       => array( 'dwg_revision', 64 ),
	'edition'        => array( 'dwg_edition', 64 ),
	'version'        => array( 'dwg_version', 64 ),
	'author'         => array( 'dwg_author', 255 ),
	'publisher'      => array( 'dwg_publisher', 255 ),
	'discipline'     => array( 'dwg_discipline', 64 ),
	'classification' => array( 'dwg_classification', 64 ),
) as $t_property => list( $t_name, $t_max ) ) {
	if( $t_show( $t_property ) ) {
		$t_value = (string)$t_bug->$t_property;
		$t_cells[] = function() use ( $t_name, $t_value, $t_max ) {
			dwg_update_page_text( $t_name, $t_value, $t_max );
		};
	}
}
foreach( array( 'revision_date' => 'dwg_revision_date', 'release_date' => 'dwg_release_date' ) as $t_property => $t_name ) {
	if( $t_show( $t_property ) ) {
		$t_value = $t_bug->$t_property;
		$t_cells[] = function() use ( $t_name, $t_value ) {
			dwg_update_page_date_input( $t_name, $t_value );
		};
	}
}
dwg_update_page_rows( $t_cells );

if( $t_show( 'reference' ) ) {
	echo '<tr><th class="category"><label for="dwg_reference">', lang_get( 'dwg_reference' ), '</label></th><td colspan="3">';
	if( $t_has_file ) {
		# Doctis's own once a file is registered: the on-record commit.
		echo '<code>', string_display_line( (string)$t_bug->reference ), '</code>',
			' <span class="small grey">', lang_get( 'dwg_reference_managed' ), '</span>';
	} else {
		echo '<input ', helper_get_tab_index(), ' type="text" id="dwg_reference" name="dwg_reference"',
			' class="form-control input-sm" maxlength="64" value="', string_attribute( (string)$t_bug->reference ), '" />';
	}
	echo '</td></tr>';
}

if( $t_show( 'link_url' ) ) {
	echo '<tr><th class="category"><label for="dwg_link_url">', lang_get( 'dwg_link_url' ), '</label></th>';
	echo '<td colspan="3"><input ', helper_get_tab_index(), ' type="url" id="dwg_link_url" name="dwg_link_url"',
		' class="form-control" maxlength="2048" value="', string_attribute( (string)$t_bug->link_url ), '" /></td></tr>';
}

# ── Record ──────────────────────────────────────────────────────────────────
echo '<tr><td colspan="4" class="bold">', lang_get( 'dwg_edit_section_record' ), '</td></tr>';

$t_cells = array();
if( $t_show( 'project' ) ) {
	$t_cells[] = function() use ( $t_bug ) {
		echo '<th class="category">', lang_get( 'email_project' ), '</th><td>',
			string_display_line( project_get_name( $t_bug->project_id ) ), '</td>';
	};
}
if( $t_show( 'category_id' ) ) {
	$t_cells[] = function() use ( $t_bug ) {
		$t_allow_none = config_get( 'allow_no_category' );
		echo '<th class="category">', $t_allow_none ? '' : '<span class="required">*</span> ',
			'<label for="category_id">', lang_get( 'category' ), '</label></th><td>';
		if( !category_is_enabled( $t_bug->category_id ) ) {
			print_icon( 'warning', 'fa-status-box bigger-125 red', lang_get( 'category_disabled' ) );
			echo '&#160;';
		}
		echo '<select ', helper_get_tab_index(), $t_allow_none ? '' : ' required',
			' id="category_id" name="category_id" class="input-sm">';
		print_category_option_list( $t_bug->category_id, $t_bug->project_id, true );
		echo '</select></td>';
	};
}
if( $t_show( 'status' ) ) {
	$t_cells[] = function() use ( $t_bug ) {
		echo '<th class="category"><label for="status">', lang_get( 'status' ), '</label></th><td class="bug-status">';
		print_icon( 'fa-square', 'fa-status-box ' . html_get_status_css_fg( $t_bug->status ) );
		echo '&#160;';
		# Only the transitions the workflow allows this user from here.
		print_dwg_status_option_list( 'status', $t_bug->status, access_can_close_dwg( $t_bug ), $t_bug->project_id );
		echo '</td>';
	};
}
if( $t_show( 'priority' ) ) {
	$t_cells[] = function() use ( $t_bug ) {
		echo '<th class="category"><label for="priority">', lang_get( 'priority' ), '</label></th><td>';
		echo '<select ', helper_get_tab_index(), ' id="priority" name="priority" class="input-sm">';
		print_enum_string_option_list( 'priority', $t_bug->priority );
		echo '</select></td>';
	};
}
if( $t_show( 'view_state' ) ) {
	$t_cells[] = function() use ( $t_bug, $t_can_view_state ) {
		echo '<th class="category"><label for="view_state">', lang_get( 'view_status' ), '</label></th><td>';
		if( $t_can_view_state ) {
			echo '<select ', helper_get_tab_index(), ' id="view_state" name="view_state" class="input-sm">';
			print_dwg_enum_string_option_list( 'view_state', (int)$t_bug->view_state );
			echo '</select>';
		} else {
			echo string_display_line( get_enum_element( 'view_state', $t_bug->view_state ) );
		}
		echo '</td>';
	};
}
if( $t_show_due_date ) {
	$t_cells[] = function() use ( $t_bug, $f_bug_id, $t_can_due_date ) {
		$t_level = dwg_overdue_level( $f_bug_id );
		echo '<th class="category"><label for="due_date">', lang_get( 'due_date' ), '</label></th>';
		echo '<td', $t_level === false ? '' : ' class="due-' . $t_level . '"', '>';
		$t_shown_date = date_is_null( $t_bug->due_date ) ? '' : date( config_get( 'normal_date_format' ), $t_bug->due_date );
		if( $t_can_due_date ) {
			echo '<input ', helper_get_tab_index(), ' type="text" id="due_date" name="due_date"',
				' class="datetimepicker input-sm" size="16" maxlength="16"',
				' data-picker-locale="', lang_get_current_datetime_locale(), '"',
				' data-picker-format="', config_get( 'datetime_picker_format' ), '"',
				' value="', string_attribute( $t_shown_date ), '" /> ';
			print_icon( 'fa-calendar', 'fa-xlg datetimepicker' );
		} else {
			echo string_display_line( $t_shown_date );
		}
		echo '</td>';
	};
}
if( $t_show( 'date_submitted' ) ) {
	$t_cells[] = function() use ( $t_bug ) {
		echo '<th class="category">', lang_get( 'date_submitted' ), '</th><td>',
			date( config_get( 'normal_date_format' ), $t_bug->date_submitted ), '</td>';
	};
}
if( $t_show( 'last_updated' ) ) {
	$t_cells[] = function() use ( $t_bug ) {
		echo '<th class="category">', lang_get( 'last_update' ), '</th><td>',
			date( config_get( 'normal_date_format' ), $t_bug->last_updated ), '</td>';
	};
}
dwg_update_page_rows( $t_cells );

# Approving a file held in a source repository: say so where the status is
# chosen, as Change Status does (core/print_dwg_api.php).
if( $t_show( 'status' ) && $t_has_file && file_dwg_in_source_repository( $f_bug_id ) ) {
	echo '<tr><td colspan="4">';
	print_dwg_source_repository_notice( 'approve' );
	echo '</td></tr>';
}

# ── People ──────────────────────────────────────────────────────────────────
if( $t_show( 'owner' ) || $t_show_handler || $t_show( 'creator' ) ) {
	echo '<tr><td colspan="4" class="bold">', lang_get( 'dwg_edit_section_people' ), '</td></tr>';
}

$t_cells = array();
if( $t_show( 'owner' ) ) {
	$t_cells[] = function() use ( $t_bug, $f_bug_id, $f_owner_edit, $t_can_change_owner ) {
		$t_owner_name = $t_bug->owner_id == NO_USER
			? lang_get( 'dwg_owner_none' )
			: string_display_line( user_get_name( $t_bug->owner_id ) );
		echo '<th class="category"><label for="owner_id">', lang_get( 'dwg_owner' ), '</label></th><td id="owner">';
		if( !$t_can_change_owner ) {
			echo $t_owner_name;
		} else {
			# "name [Edit]": the picker is there but disabled, so the form
			# cannot change the owner until somebody chooses to.
			echo '<span id="dwg-owner-display"', $f_owner_edit ? ' style="display:none"' : '', '>',
				$t_owner_name, ' [<a id="dwg-owner-edit" href="',
				string_attribute( string_get_dwg_update_url( $f_bug_id ) . '&owner_edit=1#owner' ), '">',
				lang_get( 'edit' ), '</a>]</span>';
			echo '<span id="dwg-owner-picker"', $f_owner_edit ? '' : ' style="display:none"', '>';
			echo '<select ', helper_get_tab_index(), ' id="owner_id" name="owner_id" class="input-sm"',
				$f_owner_edit ? '' : ' disabled="disabled"', '>';
			echo '<option value="0">', lang_get( 'dwg_owner_none' ), '</option>';
			print_dwg_owner_option_list( $t_bug->owner_id, $t_bug->project_id );
			echo '</select>';
			echo '<br /><span class="small">', lang_get( 'dwg_owner_edit_hint' ), '</span></span>';
		}
		echo '</td>';
	};
}
if( $t_show_handler ) {
	$t_cells[] = function() use ( $t_bug, $t_can_assign ) {
		echo '<th class="category"><label for="handler_id">', lang_get( 'assigned_to' ), '</label></th><td>';
		if( $t_can_assign ) {
			echo '<select ', helper_get_tab_index(), ' id="handler_id" name="handler_id" class="input-sm">';
			echo '<option value="0">&#160;</option>';
			print_dwg_assign_to_option_list( $t_bug->handler_id, $t_bug->project_id );
			echo '</select>';
		} else if( $t_bug->handler_id != NO_USER ) {
			echo string_display_line( user_get_name( $t_bug->handler_id ) );
		}
		echo '</td>';
	};
}
if( $t_show( 'creator' ) ) {
	$t_cells[] = function() use ( $t_bug ) {
		# Read-only provenance, as a MantisBT reporter is (OWNERSHIP.md §3.3).
		echo '<th class="category">', lang_get( 'dwg_creator' ), '</th><td>',
			string_display_line( user_get_name( $t_bug->creator_id ) ), '</td>';
	};
}
dwg_update_page_rows( $t_cells );

# ── Text ────────────────────────────────────────────────────────────────────
$t_text_fields = array();
if( $t_show( 'description' ) ) {
	$t_text_fields['description'] = array( 'description', (string)$t_bug->description );
}
if( $t_show( 'additional_info' ) ) {
	$t_text_fields['additional_information'] = array( 'additional_information', (string)$t_bug->additional_information );
}
if( $t_text_fields ) {
	echo '<tr><td colspan="4" class="bold">', lang_get( 'dwg_edit_section_text' ), '</td></tr>';
	foreach( $t_text_fields as $t_name => list( $t_label, $t_value ) ) {
		echo '<tr><th class="category"><label for="', $t_name, '">', lang_get( $t_label ), '</label></th>';
		echo '<td colspan="3"><textarea ', helper_get_tab_index(), ' class="form-control" cols="80" rows="8"',
			' id="', $t_name, '" name="', $t_name, '">', "\n", string_textarea( $t_value ), '</textarea></td></tr>';
	}
}

event_signal( 'EVENT_UPDATE_DWG_FORM', array( $f_bug_id ) );

# ── Custom fields ───────────────────────────────────────────────────────────
$t_custom_heading = false;
foreach( custom_field_get_linked_ids( $t_bug->project_id ) as $t_id ) {
	$t_def = custom_field_get_definition( $t_id );
	if( !( $t_def['display_update'] || $t_def['require_update'] ) || !custom_field_has_write_access( $t_id, $f_bug_id ) ) {
		continue;
	}
	if( !$t_custom_heading ) {
		echo '<tr><td colspan="4" class="bold">', lang_get( 'dwg_edit_section_custom' ), '</td></tr>';
		$t_custom_heading = true;
	}
	$t_label_for = ( $t_def['type'] != CUSTOM_FIELD_TYPE_RADIO && $t_def['type'] != CUSTOM_FIELD_TYPE_CHECKBOX )
		? ' for="custom_field_' . string_attribute( $t_def['id'] ) . '"' : '';
	echo '<tr><th class="category"><label', $t_def['require_update'] ? ' class="required"' : '', $t_label_for, '>',
		string_display_line( lang_get_defaulted( $t_def['name'] ) ), '</label></th><td colspan="3">';
	print_custom_field_input( $t_def, $f_bug_id, $t_def['require_update'] );
	echo '</td></tr>';
}

# ── Note ────────────────────────────────────────────────────────────────────
echo '<tr><td colspan="4" class="bold">', lang_get( 'add_dwgnote_title' ), '</td></tr>';
$t_note_private = config_get( 'default_dwgnote_view_status' ) == VS_PRIVATE;
echo '<tr><th class="category"><label for="dwgnote_text">', lang_get( 'dwg_edit_note_label' ), '</label></th>';
echo '<td colspan="3"><textarea ', helper_get_tab_index(), ' id="dwgnote_text" name="dwgnote_text"',
	' class="form-control', $t_note_private ? ' bugnote-private' : '', '" cols="80" rows="4"',
	' placeholder="', string_attribute( lang_get( 'dwg_edit_note_hint' ) ), '"></textarea></td></tr>';

if( access_has_dwg_level( config_get( 'private_dwgnote_threshold' ), $f_bug_id ) ) {
	echo '<tr><th class="category">', lang_get( 'private' ), '</th><td colspan="3">';
	if( access_has_dwg_level( config_get( 'set_view_status_threshold' ), $f_bug_id ) ) {
		echo '<label><input ', helper_get_tab_index(), ' type="checkbox" class="ace" id="private" name="private" ',
			check_checked( config_get( 'default_dwgnote_view_status' ), VS_PRIVATE ), ' /><span class="lbl"></span></label>';
	} else {
		echo get_enum_element( 'view_state', config_get( 'default_dwgnote_view_status' ) );
	}
	echo '</td></tr>';
}

if( config_get( 'time_tracking_enabled' )
	&& access_has_dwg_level( config_get( 'time_tracking_edit_threshold' ), $f_bug_id ) ) {
	echo '<tr><th class="category"><label for="time_tracking">', lang_get( 'time_tracking' ), '</label></th>';
	echo '<td colspan="3"><input ', helper_get_tab_index(), ' type="text" id="time_tracking" name="time_tracking"',
		' class="input-sm" size="5" placeholder="hh:mm" /></td></tr>';
}

event_signal( 'EVENT_DWGNOTE_ADD_FORM', array( $f_bug_id ) );
?>
			</tbody>
		</table>
		</div>
		</div>
		<div class="widget-toolbox padding-8 clearfix">
			<input <?php echo helper_get_tab_index(); ?> type="submit" class="btn btn-primary btn-white btn-round"
				value="<?php echo lang_get( 'dwg_update_button' ); ?>" />
			&#160;
			<a class="btn btn-default btn-white btn-round" href="<?php echo string_attribute( string_get_dwg_view_url( $f_bug_id ) ) ?>"><?php
				echo lang_get( 'primary_document_tag_cancel' ) ?></a>
		</div>
		</div>
		</div>
	</form>
</div>
</div>
<?php
html_javascript_link( 'dwg_update.js' );

define( 'DWGNOTE_VIEW_INC_ALLOW', true );
include( __DIR__ . '/dwgnote_view_inc.php' );
layout_page_end();

last_visited_dwg_issue( $f_bug_id );
