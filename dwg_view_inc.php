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
 * This include file prints out the bug information
 *
 * @package MantisBT
 * @copyright Copyright 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright 2002  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses access_api.php
 * @uses authentication_api.php
 * @uses bug_api.php
 * @uses bug_activity_api.php
 * @uses category_api.php
 * @uses columns_api.php
 * @uses compress_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses custom_field_api.php
 * @uses date_api.php
 * @uses event_api.php
 * @uses gpc_api.php
 * @uses helper_api.php
 * @uses html_api.php
 * @uses lang_api.php
 * @uses prepare_api.php
 * @uses print_api.php
 * @uses project_api.php
 * @uses string_api.php
 * @uses tag_api.php
 * @uses utility_api.php
 * @uses version_api.php
 *
 * Variables referenced in this include, but declared in the calling script.
 * @var bool   $t_force_readonly
 * @var bool   $t_show_page_header
 * @var string $t_mantis_dir

 * Ignoring warnings caused by includes with a dynamic path
 * @noinspection PhpIncludeInspection
 */

use Mantis\Exceptions\ClientException;

if( !defined( 'DWG_VIEW_INC_ALLOW' ) ) {
	return;
}

require_api( 'access_dwg_api.php' );
require_api( 'authentication_api.php' );
require_api( 'dwg_api.php' );
require_api( 'dwg_activity_api.php' );
require_api( 'category_api.php' );
require_api( 'columns_api.php' );
require_api( 'compress_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'custom_field_api.php' );
require_api( 'date_api.php' );
require_api( 'event_api.php' );
require_api( 'gpc_api.php' );
require_api( 'helper_api.php' );
require_api( 'html_api.php' );
require_api( 'lang_api.php' );
require_api( 'prepare_api.php' );
require_api( 'print_dwg_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'tag_dwg_api.php' );
require_api( 'utility_api.php' );
require_api( 'version_api.php' );

require_css( 'status_config.php' );

/**
 * Variables defined in parent script including this one.
 * @var string $t_mantis_dir
 * @var bool $t_show_page_header
 * @var bool $t_force_readonly
 */

$f_dwg_id = gpc_get_int( 'id' );
$f_history = gpc_get_bool( 'history', config_get( 'history_default_visible' ) );

# compat variables for included pages
$f_bug_id = $f_dwg_id;
$t_dwg = dwg_get( $f_dwg_id, true );

$t_data = array(
	'query' => array( 'id' => $f_dwg_id ),
	'options' => array( 'force_readonly' => $t_force_readonly )
);
$t_cmd = new DwgViewPageCommand( $t_data );
$t_result = $t_cmd->execute();

$t_issue = $t_result['issue'];
$t_issue_view = $t_result['issue_view'];
$t_flags = $t_result['flags'];

compress_enable();

if( $t_show_page_header ) {
	layout_page_header( dwg_format_summary( $f_dwg_id, SUMMARY_CAPTION ), null, 'view-issue-page', 'dwg_view.php?id=' . $f_dwg_id );
	layout_page_begin( 'view_dwg_page.php', true );
}

$t_action_button_position = config_get( 'action_button_position' );

$t_dwgslist = gpc_get_cookie( config_get_global( 'dwg_list_cookie' ), false );

$t_top_buttons_enabled = !$t_force_readonly && ( $t_action_button_position == POSITION_TOP || $t_action_button_position == POSITION_BOTH );
$t_bottom_buttons_enabled = !$t_force_readonly && ( $t_action_button_position == POSITION_BOTTOM || $t_action_button_position == POSITION_BOTH );

#
# Start of Template
#

echo '<div class="col-md-12 col-xs-12">';
echo '<div class="widget-box widget-color-blue2">';
echo '<div class="widget-header widget-header-small">';
echo '<h4 class="widget-title lighter">';
print_icon( 'fa-bars', 'ace-icon' );
echo string_display_line( $t_issue_view['dwg_form_title'] );
echo '</h4>';
echo '</div>';

echo '<div class="widget-body">';

echo '<div class="widget-toolbox padding-8 clearfix noprint">';
echo '<div class="btn-group pull-left">';

# Send Bug Reminder
if( $t_flags['reminder_can_add'] ) {
	print_dwg_small_button( 'dwg_reminder_page.php?dwg_id=' . $f_dwg_id, lang_get( 'dwg_reminder' ) );
}

if( isset( $t_issue_view['wiki_link'] ) ) {
	print_dwg_small_button( $t_issue_view['wiki_link'], lang_get( 'wiki' ) );
}

# TODO: should be moved to command
foreach ( $t_issue_view['links'] as $t_plugin => $t_hooks ) {
	foreach( $t_hooks as $t_hook ) {
		if( is_array( $t_hook ) ) {
			foreach( $t_hook as $t_label => $t_href ) {
				if( is_numeric( $t_label ) ) {
					print_dwg_bracket_link_prepared( $t_href );
				} else {
					print_dwg_small_button( $t_href, $t_label );
				}
			}
		} elseif( !empty( $t_hook ) ) {
			print_dwg_bracket_link_prepared( $t_hook );
		}
	}
}

# Jump to Bugnotes — only shown when the notes feature is enabled
if( $t_flags['dwgnotes_show'] ) {
	print_dwg_small_button( '#dwgnotes', lang_get( 'jump_to_dwgnotes' ) );
}

# Display or Jump to History
if( $t_flags['history_show'] ) {
	if( $f_history ) {
		$t_history_link = '#history';
		$t_history_label = lang_get( 'jump_to_history' );
	} else {
		$t_history_link = 'dwg_view.php?id=' . $f_dwg_id . '&history=1#history';
		$t_history_label = lang_get( 'display_history' );
	}
	print_dwg_small_button( $t_history_link, $t_history_label );
}

////////////////////////////////////////////////////////////////////////////////
# Copy citation to clipboard
// print_small_button( '#citation', lang_get( 'copy_citation' ) );
// print_dwg_small_button( '#dwgcitation', lang_get( 'copy_citation' ) );

# In dwg_view_inc.php
// print_small_button( '#', lang_get( 'copy_citation' ), false, 'copy-citation-button' );

// echo '<a href="#" class="btn btn-primary btn-white btn-round btn-sm js-copy-citation">'
//      . lang_get( 'copy_citation' )
//      . '</a>';
/*
	# Document Title (screen wide fields)
	if( isset( $t_issue['title'] ) ) {
		echo '<tr>';
		echo '<th class="bug-summary category">', lang_get( 'dwg_title' ), '</th>';
		echo '<td class="bug-summary" colspan="5">', string_display_line( $t_issue['title'] ), '</td>';
		echo '</tr>';
	}

	# Labels
	echo '<tr class="bug-header">';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_reference' ) : '', '</th>';
	echo '<th class="bug-project category width-20">', $t_flags['project_show'] ? lang_get( 'dwg_number' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_revision' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_version' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_author' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_release_date' ) : '', '</th>';
	echo '</tr>';
	echo '<tr class="bug-header-data">';

	# Reference
	if( $t_flags['project_show'] ) {
		echo '<td class="bug-project">';
		print_dwg_reference_link( $t_dwg->id, $t_dwg->reference, false );
		echo '</td>';
	}
	# Document Details
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['number'] ) ? string_display_line( $t_issue['number'] ) : '', '</td>';
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['revision'] ) ? string_display_line( $t_issue['revision'] ) : '', '</td>';
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['version'] ) ? string_display_line( $t_issue['version'] ) : '', '</td>';
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['author'] ) ? string_display_line( $t_issue['author'] ) : '', '</td>';
	$t_date_format = 'Y-m-d';
	$t_release_date = date( $t_date_format, $t_issue['release_date'] );
	$t_release_date = string_display_line( date( $t_date_format, $t_issue['release_date'] ) );
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['release_date'] ) ? $t_release_date : '', '</td>';
	echo '</tr>';
	print_table_spacer( 6 );
}
 */
// $t_document_id = $f_id;  // or however your current document ID is defined
// $t_document_url = helper_mantis_url( 'dwg_view.php?id=' . $t_document_id );

/*
# Build citation text for clipboard
$t_citation = sprintf(
	'%s (%s, Rev %s, Ver %s) by %s – %s – Doctis – %s',
	string_display_line( $t_issue['title'] ?? '' ),
	string_display_line( $t_issue['number'] ?? '' ),
	string_display_line( $t_issue['revision'] ?? '' ),
	string_display_line( $t_issue['version'] ?? '' ),
	string_display_line( $t_issue['author'] ?? '' ),
	string_display_line( $t_release_date ?? '' ),
	helper_mantis_url( 'dwg_view.php?id=' . $t_dwg->id )
);

$t_document_id = $t_dwg->id;  // or however your current document ID is defined
// $t_document_url = $t_dwg->reference;
$t_document_url = $t_citation;

echo '<a href="#" 
		  class="btn btn-primary btn-white btn-round btn-sm js-copy-citation" 
		  data-citation="' . htmlspecialchars( $t_document_id . ': - Doctis – ' . $t_document_url ) . '">'
	 . lang_get( 'copy_citation' )
	 . '</a>';
*/

# Build both plain-text and HTML versions of the citation
$t_url = helper_mantis_url( 'dwg_view.php?id=' . $t_dwg->id );

$t_citation_text = sprintf(
	'%s (%s, Rev %s, Ver %s) by %s – %s – Doctis – %s',
	string_display_line( $t_issue['title'] ?? '' ),
	string_display_line( $t_issue['number'] ?? '' ),
	string_display_line( $t_issue['revision'] ?? '' ),
	string_display_line( $t_issue['version'] ?? '' ),
	string_display_line( $t_issue['author'] ?? '' ),
	string_display_line( $t_release_date ?? '' ),
	$t_url
);

$t_citation_html = sprintf(
	'%s (%s, Rev %s, Ver %s) by %s – %s – <a href="%s">Doctis</a>',
	string_display_line( $t_issue['title'] ?? '' ),
	string_display_line( $t_issue['number'] ?? '' ),
	string_display_line( $t_issue['revision'] ?? '' ),
	string_display_line( $t_issue['version'] ?? '' ),
	string_display_line( $t_issue['author'] ?? '' ),
	string_display_line( $t_release_date ?? '' ),
	$t_url
);
/*
echo '<a href="#" 
		  class="btn btn-primary btn-white btn-round btn-sm js-copy-citation"
		  data-citation="' . htmlspecialchars( $t_citation_text, ENT_QUOTES ) . '"
		  data-citation-html="' . htmlspecialchars( $t_citation_html, ENT_QUOTES ) . '">'
	 . lang_get( 'copy_citation' )
	 . '</a>';
 */
// echo '<a href="#" 
//         class="btn btn-primary btn-white btn-round btn-sm js-copy-citation"
//         data-citation="', string_attribute($t_citation_text), '"
//         data-citation-html="', string_attribute($t_citation_html), '"
//         data-copied-text="', lang_get('copied'), '"
//         data-default-text="', lang_get('copy_citation'), '">'
//      . lang_get('copy_citation')
//      . '</a>';
/*
$citation_text = $t_dwg->reference . ': - ' . $t_issue['title'] . ' – ' 
				 . string_get_bug_view_url($t_dwg->id);

echo '<a href="#" class="btn btn-primary btn-white btn-round btn-sm js-copy-citation" '
   . 'data-citation="' . htmlspecialchars($citation_text) . '">'
   . lang_get('copy_citation') 
   . '</a>';
 */
/*
$citation_text = $t_dwg->reference . ': - ' . $t_issue['title'] . ' – '
				 . string_get_bug_view_url($t_dwg->id);

// HTML version for Word (hyperlink)
$citation_html = $t_dwg->reference . ': - ' . htmlspecialchars($t_issue['title']) 
				 . ' – <a href="' . string_get_bug_view_url($t_dwg->id) . '">'
				 . string_get_bug_view_url($t_dwg->id) . '</a>';

echo '<a href="#" class="btn btn-primary btn-white btn-round btn-sm js-copy-citation" '
   . 'data-citation="' . htmlspecialchars($citation_text) . '" '
   . 'data-citation-html="' . htmlspecialchars($citation_html) . '">'
   . lang_get('copy_citation') 
   . '</a>';
*/
// // Prepare plain text
// $citation_text = $t_dwg->reference . ': ' . $t_issue['title'] . "\n"
//                . 'Number: ' . ($t_issue['number'] ?? '') . "\n"
//                . 'Revision: ' . ($t_issue['revision'] ?? '') . "\n"
//                . 'Version: ' . ($t_issue['version'] ?? '') . "\n"
//                . 'Author: ' . ($t_issue['author'] ?? '') . "\n"
//                . 'Release date: ' . date('Y-m-d', $t_issue['release_date']) . "\n"
//                . 'URL: ' . string_get_bug_view_url($t_dwg->id);

// // Prepare HTML for Word
// $citation_html = '<strong>' . htmlspecialchars($t_dwg->reference) . '</strong>: '
//                . htmlspecialchars($t_issue['title']) . '<br>'
//                . 'Number: ' . htmlspecialchars($t_issue['number'] ?? '') . '<br>'
//                . 'Revision: ' . htmlspecialchars($t_issue['revision'] ?? '') . '<br>'
//                . 'Version: ' . htmlspecialchars($t_issue['version'] ?? '') . '<br>'
//                . 'Author: ' . htmlspecialchars($t_issue['author'] ?? '') . '<br>'
//                . 'Release date: ' . htmlspecialchars(date('Y-m-d', $t_issue['release_date'])) . '<br>'
//                . 'URL: <a href="' . string_get_bug_view_url($t_dwg->id) . '">'
//                . string_get_bug_view_url($t_dwg->id) . '</a>';

// // Print button
// echo '<a href="#" class="btn btn-primary btn-white btn-round btn-sm js-copy-citation" '
//    . 'data-citation="' . htmlspecialchars($citation_text) . '" '
//    . 'data-citation-html="' . htmlspecialchars($citation_html) . '">'
//    . lang_get('copy_citation') 
//    . '</a>';

$author = $t_issue['author'] ?? 'Unknown';
$title = $t_issue['title'] ?? '';
$year = $t_issue['release_date'] ? date('Y', $t_issue['release_date']) : 'n.d.';
$reference = $t_dwg->reference ?? '';
$number = $t_issue['number'] ?? '';
$revision = $t_issue['revision'] ?? '';
$version = $t_issue['version'] ?? '';
$url = string_get_bug_view_url($t_dwg->id);

// Plain text APA-style citation
$apa_text = "{$author} ({$year}). {$title} [{$reference}";
if ($number) $apa_text .= ", No. {$number}";
if ($version) $apa_text .= ", Version {$version}";
if ($revision) $apa_text .= ", Rev {$revision}";
$apa_text .= "]. Available at: {$url}";

// HTML version (for Word) with hyperlink
$apa_html = "{$author} ({$year}). {$title} [{$reference}";
if ($number) $apa_html .= ", No. {$number}";
if ($version) $apa_html .= ", Version {$version}";
if ($revision) $apa_html .= ", Rev {$revision}";
$apa_html .= "]. Available at: <a href=\"{$url}\">{$url}</a>";

echo '<a href="#" class="btn btn-primary btn-white btn-round btn-sm js-copy-citation" '
   . 'data-citation="' . htmlspecialchars($apa_text) . '" '
   . 'data-citation-html="' . htmlspecialchars($apa_html) . '">'
   . lang_get('copy_citation') 
   . '</a>';

////////////////////////////////////////////////////////////////////////////////
echo '</div>'; // end of presenting buttons on left of row

# prev/next links
echo '<div class="btn-group pull-right">';
if( $t_dwgslist ) {
	$t_dwgslist = explode( ',', $t_dwgslist );
	$t_index = array_search( $f_dwg_id, $t_dwgslist );
	if( false !== $t_index ) {
		if( isset( $t_dwgslist[$t_index-1] ) ) {
			print_dwg_small_button( 'dwg_view.php?id='.$t_dwgslist[$t_index-1], '&lt;&lt;' );
		}

		if( isset( $t_dwgslist[$t_index+1] ) ) {
			print_dwg_small_button( 'dwg_view.php?id='.$t_dwgslist[$t_index+1], '&gt;&gt;' );
		}
	}
}
echo '</div>'; // end of presenting buttons on right of row
echo '</div>'; // end of presenting buttons row

echo '<div class="widget-main no-padding">';
echo '<div class="table-responsive">';
echo '<table class="table table-bordered table-condensed">';

if( $t_top_buttons_enabled ) {
	echo '<thead>';
	echo '<tr class="top-buttons noprint"><td colspan="6">';
	/** @noinspection PhpUnhandledExceptionInspection */
	dwg_view_action_buttons( $f_dwg_id, $t_flags );
	echo '</td></tr>';
	echo '</thead>';
}

echo '<tbody>';

if( true
) {
#
# Document Title (screen wide fields)
#
// # Summary
// if( $t_flags['summary_show'] && isset( $t_issue['summary'] ) ) {
// 	echo '<tr>';
// 	echo '<th class="bug-summary category">', lang_get( 'document_summary' ), '</th>';
// 	echo '<td class="bug-summary" colspan="5">', bug_format_summary( $f_dwg_id, SUMMARY_FIELD ), '</td>';
// 	echo '</tr>';
// }
	# Title
	if( isset( $t_issue['title'] ) ) {
		echo '<tr>';
		echo '<th class="bug-summary category">', lang_get( 'dwg_title' ), '</th>';
		echo '<td class="bug-summary" colspan="5">', string_display_line( $t_issue['title'] ), '</td>';
		echo '</tr>';
	}
	// print_table_spacer( 6 );
}

if( true
) {
	# Labels
	echo '<tr class="bug-header">';
	// echo '<th class="bug-project category width-15">', $t_flags['reference_show'] ? lang_get( 'dwg_reference' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', lang_get( 'dwg_reference' ), '</th>';
	if( $t_flags['number_show'] ) {
		echo '<th class="bug-project category width-20">', $t_flags['number_show'] ? lang_get( 'dwg_number' ) : '', '</th>';
	}
	if( $t_flags['edition_show'] ) {
		echo '<th class="bug-project category width-15">', $t_flags['edition_show'] ? lang_get( 'dwg_edition' ) : '', '</th>';
	}
	if( $t_flags['revision_show'] ) {
		echo '<th class="bug-project category width-15">', $t_flags['revision_show'] ? lang_get( 'dwg_revision' ) : '', '</th>';
	}
	if( $t_flags['version_show'] ) {
		echo '<th class="bug-project category width-15">', $t_flags['version_show'] ? lang_get( 'dwg_version' ) : '', '</th>';
	}
	if( $t_flags['author_show'] ) {
		echo '<th class="bug-project category width-15">', $t_flags['author_show'] ? lang_get( 'dwg_author' ) : '', '</th>';
	}
	if( $t_flags['publisher_show'] ) {
		echo '<th class="bug-project category width-15">', $t_flags['publisher_show'] ? lang_get( 'dwg_publisher' ) : '', '</th>';
	}
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_release_date' ) : '', '</th>';
	echo '</tr>';

	echo '<tr class="bug-header-data">';
//	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['reference'] ) ? string_display_line( $t_issue['reference'] ) : '', '</td>';

	# Reference
	// if( $t_flags['project_show'] ) {
	if( true ) { // the reference is a mandatory field so we always show it
		echo '<td class="bug-project">';
//		echo string_display_line( $t_issue['reference'] );
		print_dwg_reference_link( $t_dwg->id, $t_dwg->reference, false );
		echo '</td>';
	}
	if( $t_flags['number_show'] ) {
		echo '<td class="bug-project">', $t_flags['number_show'] && isset( $t_issue['number'] ) ? string_display_line( $t_issue['number'] ) : '', '</td>';
	}
	if( $t_flags['edition_show'] ) {
		echo '<td class="bug-project">', $t_flags['edition_show'] && isset( $t_issue['edition'] ) ? string_display_line( $t_issue['edition'] ) : '', '</td>';
	}
	if( $t_flags['revision_show'] ) {
		echo '<td class="bug-project">', $t_flags['revision_show'] && isset( $t_issue['revision'] ) ? string_display_line( $t_issue['revision'] ) : '', '</td>';
	}
	if( $t_flags['version_show'] ) {
		echo '<td class="bug-project">', $t_flags['version_show'] && isset( $t_issue['version'] ) ? string_display_line( $t_issue['version'] ) : '', '</td>';
	}
	if( $t_flags['author_show'] ) {
		echo '<td class="bug-project">', $t_flags['author_show'] && isset( $t_issue['author'] ) ? string_display_line( $t_issue['author'] ) : '', '</td>';
	}
	if( $t_flags['publisher_show'] ) {
		echo '<td class="bug-project">', $t_flags['publisher_show'] && isset( $t_issue['publisher'] ) ? string_display_line( $t_issue['publisher'] ) : '', '</td>';
	}
	$t_date_format = 'Y-m-d';
	$t_release_date = date( $t_date_format, $t_issue['release_date'] );
	$t_release_date = string_display_line( date( $t_date_format, $t_issue['release_date'] ) );
	// $t_release_date = string_display_line( date( $t_date_format, strtotime( $t_issue['release_date'] ) ) );
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['release_date'] ) ? $t_release_date : '', '</td>';
	echo '</tr>';
	print_table_spacer( 6 );
}

if( $t_flags['id_show'] || $t_flags['project_show'] || $t_flags['category_show'] ||
	$t_flags['view_state_show'] || $t_flags['created_at_show'] || $t_flags['updated_at_show']
) {

	# Labels
	echo '<tr class="bug-header">';
	echo '<th class="bug-id category width-15">', $t_flags['id_show'] ? lang_get( 'id' ) : '', '</th>';
	echo '<th class="bug-project category width-20">', $t_flags['project_show'] ? lang_get( 'email_project' ) : '', '</th>';
	echo '<th class="bug-category category width-15">', $t_flags['category_show'] ? lang_get( 'category' ) : '', '</th>';
	// echo '<th class="bug-view-status category width-15">', $t_flags['view_state_show'] ? lang_get( 'view_status' ) : '', '</th>';
	echo '<th class="bug-view-status category width-15">', $t_flags['view_state_show'] ? lang_get( 'dwg_discipline' ) : '', '</th>';
	echo '<th class="bug-date-submitted category width-15">', $t_flags['created_at_show'] ? lang_get( 'date_submitted' ) : '', '</th>';
	echo '<th class="bug-last-modified category width-20">', $t_flags['updated_at_show'] ? lang_get( 'last_update' ) : '','</th>';
	echo '</tr>';

	echo '<tr class="bug-header-data">';

	# Bug ID
	echo '<td class="bug-id">', $t_flags['id_show'] ? $t_issue_view['id_formatted'] : '', '</td>';

	# Project
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['project']['name'] ) ? string_display_line( $t_issue['project']['name'] ) : '', '</td>';

	# Category
	echo '<td class="bug-category">';
	if( $t_flags['category_show'] && isset( $t_issue['category']['name'] ) ) {
		if( $t_flags['can_update'] && !category_is_enabled( $t_issue['category']['id'] ) ) {
			print_icon( 'warning',
				'bigger-125 red',
				lang_get( 'category_disabled' )
			);
			echo "&nbsp;";
		}
		echo string_display_line( $t_issue['category']['name'] );
	}
	echo '</td>';

	# View Status
	// echo '<td class="bug-view-status">', $t_flags['view_state_show'] && isset( $t_issue['view_state']['label'] ) ? string_display_line( $t_issue['view_state']['label'] ) : '', '</td>';
	echo '<td class="bug-view-status">', $t_flags['view_state_show'] && isset( $t_issue['dwg_discipline'] ) ? string_display_line( $t_issue['discipline'] ) : '', '</td>';

	# Date Submitted
	if( isset( $t_issue_view['created_at'] ) ) {
		echo '<td class="bug-date-submitted">', $t_flags['created_at_show'] ? $t_issue_view['created_at'] : '', '</td>';
	}

	# Date Updated
	echo '<td class="bug-last-modified">',  $t_flags['updated_at_show'] ? $t_issue_view['updated_at'] : '', '</td>';

	echo '</tr>';

	print_table_spacer( 6 );
}

#
# Document, Reference, Author
#

if( false
) {
	# Labels
	echo '<tr class="bug-header">';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_title' ) : '', '</th>';
	echo '<th class="bug-project category width-20">', $t_flags['project_show'] ? lang_get( 'dwg_number' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_revision' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_reference' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_release_date' ) : '', '</th>';
	echo '<th class="bug-project category width-15">', $t_flags['project_show'] ? lang_get( 'dwg_classification' ) : '', '</th>';
	echo '</tr>';

	echo '<tr class="bug-header-data">';

	# Project

	// $t_release_date = date( $t_date_format, strtotime( $t_issue['release_date'] ) );

	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['title'] ) ? string_display_line( $t_issue['title'] ) : '', '</td>';
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['number'] ) ? string_display_line( $t_issue['number'] ) : '', '</td>';
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['revision'] ) ? string_display_line( $t_issue['revision'] ) : '', '</td>';
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['reference'] ) ? string_display_line( $t_issue['reference'] ) : '', '</td>';

	// results in a unix time being printed:
	// echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['release_date'] ) ? string_display_line( $t_issue['release_date'] ) : '', '</td>';

	// results in a sortable ordered date string being printed:
	// $t_release_date = $t_issue['release_date'] ? date( 'Y-m-d', $t_issue['release_date'] ) : '';
	// echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['release_date'] ) ? $t_release_date : '', '</td>';

//	$g_dwg_date_picker_format

$t_date_format = 'Y-m-d';

	$t_release_date = date( $t_date_format, $t_issue['release_date'] );
	$t_release_date = string_display_line( date( $t_date_format, $t_issue['release_date'] ) );
	// $t_release_date = string_display_line( date( $t_date_format, strtotime( $t_issue['release_date'] ) ) );
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['release_date'] ) ? $t_release_date : '', '</td>';
	echo '<td class="bug-project">', $t_flags['project_show'] && isset( $t_issue['classification'] ) ? string_display_line( $t_issue['classification'] ) : '', '</td>';
	echo '</tr>';
	print_table_spacer( 6 );
}

#
# Creator, Handler, Due Date
#

if( $t_flags['creator_show'] || $t_flags['handler_show'] || $t_flags['classification_show'] ) {
	echo '<tr>';

	$t_spacer = 0;

	# Handler
	if( $t_flags['handler_show'] ) {
		echo '<th class="bug-assigned-to category">', lang_get( 'assigned_to' ), '</th>';
		echo '<td class="bug-assigned-to">';
		if( isset( $t_issue['handler'] ) ) {
			print_dwg_user_with_subject( $t_issue['handler']['id'], $f_dwg_id );
		}
		echo '</td>';
	} else {
		$t_spacer += 2;
	}

	# Creator
	if( $t_flags['creator_show'] ) {
		echo '<th class="dwg-creator category">', lang_get( 'dwg_creator' ), '</th>';
		echo '<td class="dwg-creator">';
		print_dwg_user_with_subject( $t_issue['creator']['id'], $f_dwg_id );
		echo '</td>';
	} else {
		$t_spacer += 2;
	}

	# Classification
	if( $t_flags['classification_show'] ) {
		echo '<th class="bug-classification category">', lang_get( 'dwg_classification' ), '</th>';
		// echo '<td class="bug-classificatin">', string_display_line( $t_issue['classification']['label'] ), '</td>';
		echo '<td class="bug-classification">', isset( $t_issue['classification'] ) ? string_display_line( $t_issue['classification'] ) : '', '</td>';
	} else {
		$t_spacer += 2;
	}

	echo '</tr>';
}

#
# Priority, Severity, Reproducibility
#

if( $t_flags['priority_show'] || $t_flags['severity_show'] || $t_flags['reproducibility_show'] ) {
	echo '<tr>';

	$t_spacer = 0;

	# Priority
	if( $t_flags['priority_show'] ) {
		echo '<th class="bug-priority category">', lang_get( 'priority' ), '</th>';
		echo '<td class="bug-priority">';
		$t_icon = $t_dwg->priority;
		$t_status_icon_arr = config_get( 'status_icon_arr' );
		$t_priotext = get_enum_element( 'priority', $t_icon );
		if( isset( $t_status_icon_arr[$t_icon] ) && !is_blank( $t_status_icon_arr[$t_icon] ) ) {
			echo '&nbsp' . icon_get( $t_status_icon_arr[$t_icon] ) . '&nbsp';
		}
//		echo ' ' . string_display_line( $t_issue['priority']['label'] ), '</td>';
		echo ' ' . string_display_line( $t_priotext ), '</td>';
	} else {
		$t_spacer += 2;
	}

	# Severity
	if( $t_flags['severity_show'] ) {
		echo '<th class="bug-severity category">', lang_get( 'severity' ), '</th>';
		echo '<td class="bug-severity">', string_display_line( $t_issue['severity']['label'] ), '</td>';
	} else {
		$t_spacer += 2;
	}

	# Reproducibility
	if( $t_flags['reproducibility_show'] ) {
		echo '<th class="bug-reproducibility category">', lang_get( 'reproducibility' ), '</th>';
		echo '<td class="bug-reproducibility">', string_display_line( $t_issue['reproducibility']['label'] ), '</td>';
	} else {
		$t_spacer += 2;
	}

	# spacer
	if( $t_spacer > 0 ) {
		echo '<td colspan="', $t_spacer, '">&#160;</td>';
	}

	echo '</tr>';
}

#
# Status, Resolution
#

if( $t_flags['status_show'] || $t_flags['resolution_show'] ) {
	echo '<tr>';

	$t_spacer = 2;

	# Status
	if( $t_flags['status_show'] ) {
		echo '<th class="bug-status category">', lang_get( 'status' ), '</th>';

		# choose color based on status
		$t_status_css = html_get_status_css_fg( $t_issue['status']['id'] );

		echo '<td class="bug-status">';
		print_icon( 'fa-square', 'fa-status-box ' . $t_status_css );
		echo ' ' . string_display_line( $t_issue['status']['label'] ), '</td>';
	} else {
		$t_spacer += 2;
	}

	# Resolution
	if( $t_flags['resolution_show'] ) {
		echo '<th class="bug-resolution category">', lang_get( 'resolution' ), '</th>';
		echo '<td class="bug-resolution">', string_display_line( $t_issue['resolution']['label'] ), '</td>';
	} else {
		$t_spacer += 2;
	}

	# Due Date
	if( $t_flags['due_date_show'] ) {
		echo '<th class="bug-due-date category">', lang_get( 'due_date' ), '</th>';

		$t_css = 'dwg-due-date';
		if( $t_issue_view['overdue'] !== false ) {
			$t_css .= ' due-' . $t_issue_view['overdue'];
		}
		echo '<td class="' . $t_css . '">', $t_issue_view['due_date'], '</td>';
	} else {
		$t_spacer += 2;
	}

	if( $t_spacer > 0 ) {
		echo '<td colspan="', $t_spacer, '">&#160;</td>';
	}

	# spacer
	if( $t_spacer > 0 ) {
		echo '<td colspan="', $t_spacer, '">&#160;</td>';
	}

	echo '</tr>';
}

#
# Projection, ETA
#
if( $t_flags['projection_show'] || $t_flags['eta_show'] ) {
	echo '<tr>';

	$t_spacer = 2;

	if( $t_flags['projection_show'] ) {
		# Projection
		echo '<th class="bug-projection category">', lang_get( 'projection' ), '</th>';
		echo '<td class="bug-projection">', string_display_line( $t_issue['projection']['label'] ), '</td>';
	} else {
		$t_spacer += 2;
	}

	# ETA
	if( $t_flags['eta_show'] ) {
		echo '<th class="bug-eta category">', lang_get( 'eta' ), '</th>';
		echo '<td class="bug-eta">', string_display_line( $t_issue['eta']['label'] ), '</td>';
	} else {
		$t_spacer += 2;
	}

	echo '<td colspan="', $t_spacer, '">&#160;</td>';
	echo '</tr>';
}

#
# Platform, OS, OS Version
#

// if( ( $t_flags['profiles_platform_show'] && isset( $t_issue['platform'] ) && !is_blank( $t_issue['platform'] ) ) ||
// 	( $t_flags['profiles_os_show'] && isset( $t_issue['os'] ) && !is_blank( $t_issue['os'] ) ) ||
// 	( $t_flags['profiles_os_build_show'] && isset( $t_issue['os_build'] ) && !is_blank( $t_issue['os_build'] ) ) ) {
// 	$t_spacer = 0;

// 	echo '<tr>';

// 	# Platform
// 	if( $t_flags['profiles_platform_show'] && isset( $t_issue['platform'] ) && !is_blank( $t_issue['platform'] ) ) {
// 		echo '<th class="bug-platform category">', lang_get( 'platform' ), '</th>';
// 		echo '<td class="bug-platform">', string_display_line( $t_issue['platform'] ), '</td>';
// 	} else {
// 		$t_spacer += 2;
// 	}

// 	# Operating System
// 	if( $t_flags['profiles_os_show'] && isset( $t_issue['os'] ) && !is_blank( $t_issue['os'] ) ) {
// 		echo '<th class="bug-os category">', lang_get( 'os' ), '</th>';
// 		echo '<td class="bug-os">', string_display_line( $t_issue['os'] ), '</td>';
// 	} else {
// 		$t_spacer += 2;
// 	}

// 	# OS Version
// 	if( $t_flags['profiles_os_build_show'] && isset( $t_issue['os_build'] ) && !is_blank( $t_issue['os_build'] ) ) {
// 		echo '<th class="bug-os-build category">', lang_get( 'os_build' ), '</th>';
// 		echo '<td class="bug-os-build">', string_display_line( $t_issue['os_build'] ), '</td>';
// 	} else {
// 		$t_spacer += 2;
// 	}

// 	if( $t_spacer > 0 ) {
// 		echo '<td colspan="', $t_spacer, '">&#160;</td>';
// 	}

// 	echo '</tr>';
// }

#
# Product Version, Product Build
#

// if( ( $t_flags['versions_product_version_show'] && isset( $t_issue['version'] ) ) ||
// 	( $t_flags['versions_product_build_show'] && isset( $t_issue['build'] ) ) ) {
// 	$t_spacer = 2;

// 	echo '<tr>';

// 	# Product Version
// 	if( $t_flags['versions_product_version_show'] && isset( $t_issue['version'] ) ) {
// 		echo '<th class="bug-product-version category">', lang_get( 'product_version' ), '</th>';
// 		echo '<td class="bug-product-version">', string_display_line( $t_issue_view['product_version'] ), '</td>';
// 	} else {
// 		$t_spacer += 2;
// 	}

// 	# Product Build
// 	if( $t_flags['versions_product_build_show'] && isset( $t_issue['build'] ) ) {
// 		echo '<th class="bug-product-build category">', lang_get( 'product_build' ), '</th>';
// 		echo '<td class="bug-product-build">', string_display_line( $t_issue['build'] ), '</td>';
// 	} else {
// 		$t_spacer += 2;
// 	}

// 	# spacer
// 	echo '<td colspan="', $t_spacer, '">&#160;</td>';

// 	echo '</tr>';
// }

#
# Target Version, Fixed In Version
#

// if( ( $t_flags['versions_target_version_show'] && isset( $t_issue['target_version'] ) ) ||
//     ( $t_flags['versions_fixed_in_version_show'] && isset( $t_issue['fixed_in_version'] ) ) ) {
// 	$t_spacer = 2;

// 	echo '<tr>';

// 	# target version
// 	if( $t_flags['versions_target_version_show'] && isset( $t_issue['target_version'] ) ) {
// 		# Target Version
// 		echo '<th class="bug-target-version category">', lang_get( 'target_version' ), '</th>';
// 		echo '<td class="bug-target-version">', string_display_line( $t_issue_view['target_version'] ), '</td>';
// 	} else {
// 		$t_spacer += 2;
// 	}

// 	# fixed in version
// 	if( $t_flags['versions_fixed_in_version_show'] && isset( $t_issue['fixed_in_version'] ) ) {
// 		echo '<th class="bug-fixed-in-version category">', lang_get( 'fixed_in_version' ), '</th>';
// 		echo '<td class="bug-fixed-in-version">', string_display_line( $t_issue_view['fixed_in_version'] ), '</td>';
// 	} else {
// 		$t_spacer += 2;
// 	}

// 	# spacer
// 	echo '<td colspan="', $t_spacer, '">&#160;</td>';

// 	echo '</tr>';
// }

#
# Bug Details Event Signal
#

event_signal( 'EVENT_VIEW_DWG_DETAILS', array( $f_dwg_id ) );

print_table_spacer( 6 );

// #
// # Bug Details (screen wide fields)
// #

// # Summary
// if( $t_flags['summary_show'] && isset( $t_issue['summary'] ) ) {
// 	echo '<tr>';
// 	echo '<th class="bug-summary category">', lang_get( 'document_summary' ), '</th>';
// 	echo '<td class="bug-summary" colspan="5">', dwg_format_summary( $f_dwg_id, SUMMARY_FIELD ), '</td>';
// 	echo '</tr>';
// }

// # Description
// if( $t_flags['description_show'] && isset( $t_issue['description'] ) ) {
// 	echo '<tr>';
// 	echo '<th class="bug-description category">', lang_get( 'description' ), '</th>';
// 	echo '<td class="bug-description" colspan="5">', string_display_links( $t_issue['description'] ), '</td>';
// 	echo '</tr>';
// }

// # Steps to Reproduce
// if( $t_flags['steps_to_reproduce_show'] && isset( $t_issue['steps_to_reproduce'] ) ) {
// 	echo '<tr>';
// 	echo '<th class="bug-steps-to-reproduce category">', lang_get( 'steps_to_reproduce' ), '</th>';
// 	echo '<td class="bug-steps-to-reproduce" colspan="5">', string_display_links( $t_issue['steps_to_reproduce'] ), '</td>';
// 	echo '</tr>';
// }

// # Additional Information
// if( $t_flags['additional_information_show'] && isset( $t_issue['additional_information'] ) ) {
// 	echo '<tr>';
// 	echo '<th class="bug-additional-information category">', lang_get( 'additional_information' ), '</th>';
// 	echo '<td class="bug-additional-information" colspan="5">', string_display_links( $t_issue['additional_information'] ), '</td>';
// 	echo '</tr>';
// }

// # Tagging
// if( $t_flags['tags_show'] ) {
// 	echo '<tr>';
// 	echo '<th class="bug-tags category">', lang_get( 'tags' ), '</th>';
// 	echo '<td class="bug-tags" colspan="5">';
// 	tag_display_attached( $f_dwg_id );
// 	echo '</td></tr>';
// }

// # Attach Tags
// if( $t_flags['tags_can_attach'] ) {
// 	echo '<tr class="noprint">';
// 	echo '<th class="bug-attach-tags category">', lang_get( 'tag_attach_long' ), '</th>';
// 	echo '<td class="bug-attach-tags" colspan="5">';
// 	print_tag_attach_form( $f_dwg_id );
// 	echo '</td></tr>';
// }

# Attachments
if( !empty( $t_result['issue']['attachments'] ) ) {
	echo '<tr class="noprint">';
	echo '<th class="bug-attach-tags category">', lang_get( 'attached_files' ), '</th>';
	echo '<td class="bug-attach-tags" colspan="5">';

	$t_dwg_activity_get_all_result = dwg_activity_get_all( $f_dwg_id, /* include_attachments */ true );
	$t_activities = $t_dwg_activity_get_all_result['activities'];
	$t_security_token_attachments_delete = form_security_token( 'dwg_file_delete' );

	foreach( $t_activities as $t_activity ) {
		if( $t_activity['type'] !== ENTRY_TYPE_ATTACHMENT ) {
			continue;
		}

		foreach( $t_activity['attachments'] as $t_attachment ) {
			print_dwg_attachment( $t_attachment, $t_security_token_attachments_delete );
		}
	}

	echo '</td></tr>';
}

print_table_spacer( 6 );

# Custom Fields
if( isset( $t_issue['custom_fields'] ) ) {
	foreach( $t_issue['custom_fields'] as $t_custom_field ) {
		$t_def = custom_field_get_definition( $t_custom_field['field']['id'] );
		$t_class = $t_def['type'] == CUSTOM_FIELD_TYPE_TEXTAREA ? ' cfdef-textarea' : '';

		echo '<tr>';
		echo '<th class="bug-custom-field category">', string_attribute( lang_get_defaulted( $t_def['name'] ) ), '</th>';
		echo '<td class="bug-custom-field' . $t_class . '" colspan="5">';
		print_custom_field_value( $t_def, $t_custom_field['field']['id'], $f_dwg_id );
		echo '</td></tr>';
	}

	print_table_spacer( 6 );
}

echo '</tbody>';

if( $t_bottom_buttons_enabled ) {
	echo '<tfoot>';
	echo '<tr class="noprint"><td colspan="6">';
	/** @noinspection PhpUnhandledExceptionInspection */
	dwg_view_action_buttons( $f_dwg_id, $t_flags );
	echo '</td></tr>';
	echo '</tfoot>';
}

echo '</table>';

echo '</div></div></div></div></div>';

# User list sponsoring the bug
if( $t_flags['sponsorships_show'] ) {
	define( 'DWG_SPONSORSHIP_LIST_VIEW_INC_ALLOW', true );
	include( $t_mantis_dir . 'dwg_sponsorship_list_view_inc.php' );
}

# ── Primary Document File ────────────────────────────────────────────────────
$t_primary_file = file_dwg_primary_get( $f_dwg_id );
$t_can_upload_primary = !$t_force_readonly &&
	access_has_dwg_level( config_get( 'update_dwg_threshold' ), $f_dwg_id );
# Sync to HEAD is a privileged operation — restricted to manager level and above.
$t_can_sync_to_head = !$t_force_readonly &&
	access_has_dwg_level( MANAGER, $f_dwg_id );
$t_git_head_info     = file_dwg_git_head_info( $f_dwg_id );
$t_git_head_sha      = $t_git_head_info ? $t_git_head_info['sha']      : null;
$t_git_head_date     = $t_git_head_info ? $t_git_head_info['date']     : null;
$t_git_head_author   = $t_git_head_info ? $t_git_head_info['author']   : null;
$t_git_head_filename = $t_git_head_info ? $t_git_head_info['filename'] : null;
$t_collapse_block = is_collapsed( 'primary_document' );
$t_block_css = $t_collapse_block ? 'collapsed' : '';
$t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';
?>
<div class="col-md-12 col-xs-12">
<div class="space-10"></div>

<div id="primary_document" class="widget-box widget-color-blue2 <?php echo $t_block_css ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-file', 'ace-icon' ); ?>
			<?php echo lang_get( 'primary_document_section' ) ?>
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
<?php
$t_head_diverged = $t_git_head_sha !== null && $t_git_head_sha !== ( $t_primary_file['git_sha'] ?? null );
?>
<?php if( $t_primary_file ): ?>
				<tr class="bug-header">
					<th class="category width-10"></th>
					<th class="category width-25"><?php echo lang_get( 'primary_document_file' ) ?></th>
					<th class="category width-15"><?php echo lang_get( 'date_added_modified' ) ?></th>
					<th class="category width-10">SHA</th>
					<th class="category width-20">Author</th>
					<th class="category width-20">Action</th>
				</tr>
				<tr>
					<th class="category">
						<span class="label label-success"><?php echo lang_get( 'primary_document_approved' ) ?></span>
					</th>
					<td>
						<a href="file_download.php?type=dwg_primary&amp;id=<?php echo $f_dwg_id ?>"><?php
							echo string_display_line( $t_primary_file['filename'] )
						?></a>
						&nbsp;<span class="small">(<?php echo number_format( $t_primary_file['filesize'] ) ?> <?php echo lang_get( 'bytes' ) ?>)</span>
					</td>
					<td><?php echo date( config_get( 'normal_date_format' ), $t_primary_file['date_added'] ) ?></td>
					<td><code title="<?php echo htmlspecialchars( $t_primary_file['git_sha'] ) ?>"><?php echo htmlspecialchars( substr( $t_primary_file['git_sha'], 0, 8 ) ) ?></code></td>
					<td><?php echo htmlspecialchars( user_get_name( (int)$t_primary_file['user_id'] ) ) ?></td>
					<td>
<?php	if( $t_can_sync_to_head ): ?>
						<a href="dwg_primary_file_tag_page.php?id=<?php echo $f_dwg_id ?>"
							class="btn btn-info btn-xs btn-white btn-round">
							<?php echo lang_get( 'primary_document_tag' ) ?>
						</a>
<?php	endif; ?>
					</td>
				</tr>
				<tr>
					<th class="category">
						<span class="label label-default"><?php echo lang_get( 'primary_document_draft' ) ?></span>
					</th>
					<td>
<?php	if( $t_git_head_filename !== null ): ?>
						<a href="dwg_primary_head_warn.php?id=<?php echo $f_dwg_id ?>"><?php
							echo htmlspecialchars( $t_git_head_filename )
						?></a>
<?php		if( $t_git_head_filename !== $t_primary_file['filename'] ): ?>
						&nbsp;<span class="label label-info">renamed</span>
<?php		endif; ?>
<?php	else: ?>
						<span class="small">—</span>
<?php	endif; ?>
					</td>
					<td><?php echo $t_git_head_date !== null ? date( config_get( 'normal_date_format' ), $t_git_head_date ) : '<span class="small">—</span>' ?></td>
					<td>
<?php	if( $t_git_head_sha !== null ): ?>
						<code title="<?php echo htmlspecialchars( $t_git_head_sha ) ?>"><?php echo htmlspecialchars( substr( $t_git_head_sha, 0, 8 ) ) ?></code>
<?php		if( $t_head_diverged ): ?>
						&nbsp;<span class="label label-warning">updated</span>
<?php		endif; ?>
<?php	else: ?>
						<span class="small">—</span>
<?php	endif; ?>
					</td>
					<td><?php echo $t_git_head_author !== null ? htmlspecialchars( $t_git_head_author ) : '<span class="small">—</span>' ?></td>
					<td>
<?php	if( $t_can_sync_to_head ): ?>
						<form method="post" action="dwg_primary_file_touch.php" style="display:inline">
							<?php echo form_security_field( 'dwg_primary_file_touch' ) ?>
							<input type="hidden" name="dwg_id" value="<?php echo $f_dwg_id ?>" />
							<input type="submit"
								class="btn btn-default btn-xs btn-white btn-round"
								value="<?php echo lang_get( 'primary_document_touch' ) ?>" />
						</form>
<?php	endif; ?>
<?php	if( $t_can_sync_to_head && $t_head_diverged ): ?>
						<form method="post" action="dwg_primary_file_sync_head.php" style="display:inline">
							<?php echo form_security_field( 'dwg_primary_file_sync_head' ) ?>
							<input type="hidden" name="dwg_id" value="<?php echo $f_dwg_id ?>" />
							<input type="submit"
								class="btn btn-warning btn-xs btn-white btn-round"
								value="<?php echo lang_get( 'primary_document_sync_head' ) ?>" />
						</form>
<?php	endif; ?>
					</td>
				</tr>
<?php	if( !is_blank( $t_primary_file['description'] ) ): ?>
				<tr>
					<th class="category"><?php echo lang_get( 'description' ) ?></th>
					<td colspan="5"><?php echo string_display_line( $t_primary_file['description'] ) ?></td>
				</tr>
<?php	endif; ?>
<?php	if( $t_can_upload_primary ): ?>
				<tr>
					<th class="category"><?php echo lang_get( 'primary_document_replace' ) ?></th>
					<td colspan="5">
						<form method="post" enctype="multipart/form-data" action="dwg_primary_file_update.php">
							<?php echo form_security_field( 'dwg_primary_file_update' ) ?>
							<input type="hidden" name="dwg_id" value="<?php echo $f_dwg_id ?>" />
							<input type="file" name="primary_document_file" class="input-sm" />
							<input type="text" name="primary_document_description" class="input-sm width-40"
								maxlength="255" placeholder="<?php echo lang_get( 'primary_document_description_hint' ) ?>" />
							<input type="submit" class="btn btn-warning btn-sm btn-white btn-round"
								value="<?php echo lang_get( 'primary_document_replace_button' ) ?>" />
						</form>
					</td>
				</tr>
<?php	endif; ?>
<?php else: ?>
				<tr>
					<td colspan="6" class="center">
<?php	if( $t_can_upload_primary ): ?>
						<form method="post" enctype="multipart/form-data" action="dwg_primary_file_update.php">
							<?php echo form_security_field( 'dwg_primary_file_update' ) ?>
							<input type="hidden" name="dwg_id" value="<?php echo $f_dwg_id ?>" />
							<table class="table table-condensed no-border">
							<tr>
								<th class="category width-15">
									<label for="primary_document_file_view"><?php echo lang_get( 'primary_document_file' ) ?></label>
								</th>
								<td>
									<input id="primary_document_file_view" type="file" name="primary_document_file" class="input-sm" />
								</td>
							</tr>
							<tr>
								<th class="category width-15">
									<label for="primary_document_description_view"><?php echo lang_get( 'description' ) ?></label>
								</th>
								<td>
									<input id="primary_document_description_view" type="text"
										name="primary_document_description" class="input-sm width-60"
										maxlength="255"
										placeholder="<?php echo lang_get( 'primary_document_description_hint' ) ?>" />
								</td>
							</tr>
							<tr>
								<td colspan="2">
									<input type="submit" class="btn btn-primary btn-sm btn-white btn-round"
										value="<?php echo lang_get( 'primary_document_upload_button' ) ?>" />
								</td>
							</tr>
							</table>
						</form>
<?php	else: ?>
						<span class="grey"><?php echo lang_get( 'primary_document_none' ) ?></span>
<?php	endif; ?>
					</td>
				</tr>
<?php endif; ?>
				</table>
			</div>
		</div>
	</div>
</div>
</div>

<?php
# Bug Relationships
if( $t_flags['relationships_show'] ) {
	/** @noinspection PhpUnhandledExceptionInspection */
	dwg_view_relationship_view_box( $f_dwg_id, /* can_update */ $t_flags['relationships_can_update'] );
}

# User list monitoring the dwg
if( $t_flags['monitor_show'] ) {
	// $t_collapse_block = is_collapsed( 'monitoring' );
	$t_collapse_block = is_collapsed( 'monitors' );
	$t_block_css = $t_collapse_block ? 'collapsed' : '';
	$t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';
?>
	<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>

	<div id="monitors" class="widget-box widget-color-blue2 <?php echo $t_block_css ?>">
		<div class="widget-header widget-header-small">
			<h4 class="widget-title lighter">
				<?php print_icon( 'fa-users', 'ace-icon' ); ?>
				<?php echo lang_get( 'users_monitoring_dwg' ) ?>
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
			<label for="dwg_monitor_list_user_to_add">
				<?php echo lang_get( 'monitoring_user_list' ); ?>
			</label>
		</th>
		<td class="width-85">
	<?php
			if( !isset( $t_issue['monitors'] ) || count( $t_issue['monitors'] ) == 0 ) {
				echo lang_get( 'no_users_monitoring_bug' );
			} else {
				$t_first_user = true;
				foreach( $t_issue['monitors'] as $t_monitor_user ) {
					if( $t_first_user ) {
						$t_first_user = false;
					} else {
						echo ', ';
					}

					print_user( $t_monitor_user['id'] );
					if( $t_flags['monitor_can_delete'] ) {
						echo ' <a class="btn btn-xs btn-primary btn-white btn-round" '
							. 'href="' . helper_mantis_url( 'dwg_monitor_delete.php' )
							. '?bug_id=' . $f_dwg_id . '&amp;user_id=' . $t_monitor_user['id']
							. htmlspecialchars(form_security_param( 'dwg_monitor_delete' ))
							. '">'
							. icon_get( 'fa-times' )
							. '</a>';
					}
				 }
			}
	
			if( $t_flags['monitor_can_add'] ) {
	?>
			<br /><br />
			<form method="post" action="dwg_monitor_add.php" class="form-inline noprint">
				<?php echo form_security_field( 'dwg_monitor_add' ) ?>
				<input type="hidden" name="bug_id" value="<?php echo (integer)$f_dwg_id; ?>" />
				<!--suppress HtmlFormInputWithoutLabel -->
				<input type="text" class="input-sm" id="dwg_monitor_list_user_to_add" name="user_to_add" />
				<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="<?php echo lang_get( 'add' ) ?>" />
			</form>
			<?php } ?>
		</td>
	</tr>
					</table>
				</div>
			</div>
		</div>
	</div>
	</div>
<?php
}

# Licenses applied to the dwg
if( $t_flags['license_show'] ) {
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
				<?php echo lang_get( 'licenses_applied_dwg' ) ?>
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

$t_license_applied = array();
$t_license_apply_for = array();
$t_license_applied_name = array();
$t_license_apply_for_name = array();

$t_can_manage_licenses = false;
$t_show_request_license = false;
$t_show_apply_license = false;
			if( !isset( $t_issue['licenses'] ) || count( $t_issue['licenses'] ) == 0 ) {
				echo lang_get( 'no_licenses_dwg' );
			} else {
$t_current_user_id = auth_get_current_user_id();
$t_current_project = helper_get_current_project();
$t_can_manage_licenses = access_has_license_level( config_get( 'manage_license_threshold', null, $t_current_user_id, $t_current_project ) );
// $t_can_manage_licenses = access_has_license_level( config_get( 'manage_license_threshold', null, $t_user_id, null ) );
$t_show_link = $t_can_manage_licenses;

				$t_first = true;
				foreach( $t_issue['licenses'] as $t_license ) {

// $t_show_link = access_has_license_level( config_get( 'license_user_threshold' ), $t_license['id'], );

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

	// foreach( $p_user_ids as $t_id ) {
	// 	$t_user_ids[] = (int)$t_id;
	// }

$t_license_applied[] = $t_license['id'];
$t_license_applied_name[] = license_get_name( $t_license['id'] );
$t_show_apply_license = true;

						} else {
							print_license( $t_license['id'], $t_show_link, 'red' );

$t_license_apply_for[] = $t_license['id'];
$t_license_apply_for_name[] = license_get_name( $t_license['id'] );
$t_show_request_license = true;

						}
					}

					if( $t_can_manage_licenses || $t_flags['license_can_delete'] ) {
						echo ' <a class="btn btn-xs btn-primary btn-white btn-round" '
							. 'href="' . helper_mantis_url( 'dwg_license_delete.php' )
							. '?bug_id=' . $f_dwg_id . '&amp;user_id=' . $t_license['id']
							. htmlspecialchars(form_security_param( 'dwg_license_delete' ))
							. '">'
							. icon_get( 'fa-times' )
							. '</a>';
					}
				 }
			}

			if( $t_show_request_license ) {

$t_license_list = implode( ", ", $t_license_apply_for_name );

// function print_dwg_license_request_form( $p_dwg_id, $p_string = '' ) {}
print_dwg_license_request_form( $f_dwg_id, $t_license_list );

?>
			<form method="post" action="dwg_license_update.php" class="form-inline noprint">
				<?php echo form_security_field( 'dwg_license_update' ) ?>
				<input type="hidden" name="bug_id" value="<?php echo (integer)$f_dwg_id; ?>" />
				<input type="hidden" name="user_id" value="<?php echo (integer)$t_current_user_id; ?>" />
				<input type="hidden" name="project_id" value="<?php echo (integer)$t_issue['project']; ?>" />
				<input type="hidden" name="access_level" value="10" />
				<?php
					foreach( $t_license_apply_for as $t_license ) {
						echo '<input type="hidden" name="license_id[]" value="' . $t_license . '" />' . "\n";
					}
				?>
				<!--suppress HtmlFormInputWithoutLabel -->
				<!-- <input type="text" class="input-sm" id="dwg_license_list_license_to_add" name="license_to_add" /> -->
				<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="<?php echo lang_get( 'license_request_access' ) ?>" />
				<input type="text" class="input-sm" id="dwg_license_list_license_to_add" name="license_to_add" size="64" maxlength="256" value="<?php echo $t_license_list ?>" />
	<span class="required pull-right"> * <?php echo lang_get( 'license_request_access_tip' ) ?></span>
			</form>
<?php
			}

// function dwg_group_action_print_hidden_fields( array $p_bug_ids_array ) {
// 	foreach( $p_bug_ids_array as $t_bug_id ) {
// 		echo '<input type="hidden" name="dwg_arr[]" value="' . $t_bug_id . '" />' . "\n";
// 	}
// }

			if( $t_show_apply_license ) {

// $t_license_list = implode( ", ", $t_license_applied );
$t_license_list = implode( ", ", $t_license_applied_name );


?>
			<form method="post" action="dwg_license_update.php" class="form-inline noprint">
				<?php echo form_security_field( 'dwg_license_update' ) ?>

				<input type="hidden" name="bug_id" value="<?php echo (integer)$f_dwg_id; ?>" />
				<input type="hidden" name="user_id" value="<?php echo (integer)$t_current_user_id; ?>" />
				<input type="hidden" name="project_id" value="<?php echo (integer)$t_issue['project']; ?>" />
				<input type="hidden" name="access_level" value="20" />
				<?php
					foreach( $t_license_applied as $t_license ) {
						echo '<input type="hidden" name="license_id[]" value="' . $t_license . '" />' . "\n";
					}
				?>
				<!--suppress HtmlFormInputWithoutLabel -->
				<!-- <input type="text" class="input-sm" id="dwg_license_list_license_to_add" name="license_to_add" /> -->
				<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="<?php echo lang_get( 'license_apply_access' ) ?>" />

<?php /*				<input type="text" class="input-sm" id="dwg_license_list_license_to_add" name="license_to_add" value="<?php echo license_get_name( $t_license ) ?>" /> */ ?>

				<input type="text" class="input-sm" id="dwg_license_list_license_to_add" name="license_to_add" size="64" maxlength="256" value="<?php echo $t_license_list ?>" />

<?php /*			<input <?php echo helper_get_tab_index() ?> type="text" id="dwg_title" name="dwg_title" size="105" maxlength="255" value="<?php echo string_attribute( $f_dwg_title ) ?>" required /> */ ?>

			</form>
<?php
			}



			if( $t_can_manage_licenses || $t_flags['license_can_add'] ) {
	?>
			<br /><br />
			<form method="post" action="dwg_license_add.php" class="form-inline noprint">
				<?php echo form_security_field( 'dwg_license_add' ) ?>
				<input type="hidden" name="bug_id" value="<?php echo (integer)$f_dwg_id; ?>" />
				<!--suppress HtmlFormInputWithoutLabel -->
				<input type="text" class="input-sm" id="dwg_license_list_license_to_add" name="license_to_add" />
				<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="<?php echo lang_get( 'add' ) ?>" />
			</form>
			<?php

// function print_dwg_license_request_form( $p_dwg_id, $p_string = '' ) {}
// print_dwg_license_add_form( $f_dwg_id, $t_license_list );
print_dwg_license_add_form( $f_dwg_id );

			}
			?>
		</td>
	</tr>
					</table>
				</div>
			</div>
		</div>
	</div>
	</div>
<?php
}

if( $t_flags['dwgnotes_show'] && access_has_dwg_level( config_get( 'dwgnote_view_threshold' ), $f_dwg_id ) ) {
	# Dwgnotes and "Add Note" box
	if( 'ASC' == current_user_get_pref( 'dwgnote_order' ) ) {
		define( 'DWGNOTE_VIEW_INC_ALLOW', true );
		include( $t_mantis_dir . 'dwgnote_view_inc.php' );

		if( !$t_force_readonly ) {
			define( 'DWGNOTE_ADD_INC_ALLOW', true );
			include( $t_mantis_dir . 'dwgnote_add_inc.php' );
		}
	} else {
		if( !$t_force_readonly ) {
			define( 'DWGNOTE_ADD_INC_ALLOW', true );
			include( $t_mantis_dir . 'dwgnote_add_inc.php' );
		}

		define( 'DWGNOTE_VIEW_INC_ALLOW', true );
		include( $t_mantis_dir . 'dwgnote_view_inc.php' );
	}
}

# Allow plugins to display stuff after notes
event_signal( 'EVENT_VIEW_DWG_EXTRA', array( $f_dwg_id ) );

# Time tracking statistics
if( config_get( 'time_tracking_enabled' ) &&
	access_has_dwg_level( config_get( 'time_tracking_view_threshold' ), $f_dwg_id ) ) {
	define( 'DWGNOTE_STATS_INC_ALLOW', true );
	include( $t_mantis_dir . 'dwgnote_stats_inc.php' );
}

# History
if( $t_flags['history_show'] && $f_history ) {
?>
	<div class="col-md-12 col-xs-12">
		<div class="space-10"></div>
<?php
	$t_collapse_block = is_collapsed( 'history' );
	$t_block_css = $t_collapse_block ? 'collapsed' : '';
	$t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';
	$t_history = history_dwg_get_events_array( $f_dwg_id );
?>
		<div id="history" class="widget-box widget-color-blue2 <?php echo $t_block_css ?>">
			<div class="widget-header widget-header-small">
				<h4 class="widget-title lighter">
					<?php print_icon( 'fa-history', 'ace-icon' ); ?>
					<?php echo lang_get( 'dwg_history' ) ?>
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
	<table class="table table-bordered table-condensed table-hover table-striped">
		<thead>
			<tr>
				<th class="small-caption">
					<?php echo lang_get( 'date_modified' ) ?>
				</th>
				<th class="small-caption">
					<?php echo lang_get( 'username' ) ?>
				</th>
				<th class="small-caption">
					<?php echo lang_get( 'field' ) ?>
				</th>
				<th class="small-caption">
					<?php echo lang_get( 'change' ) ?>
				</th>
			</tr>
		</thead>

		<tbody>
<?php
	foreach( $t_history as $t_item ) {
?>
			<tr>
				<td class="small-caption">
					<?php echo $t_item['date'] ?>
				</td>
				<td class="small-caption">
					<?php print_user( $t_item['userid'] ) ?>
				</td>
				<td class="small-caption">
					<?php echo string_display_line( $t_item['note'] ) ?>
				</td>
				<td class="small-caption">
					<?php echo ( $t_item['raw'] ? string_display_line_links( $t_item['change'] ) : $t_item['change'] ) ?>
				</td>
			</tr>
<?php
	} # end for loop
?>
		</tbody>
	</table>
					</div>
				</div>
			</div>
		</div>
	</div>
<?php
}

layout_page_end();

/**
 * Return formatted string with all the details on the requested relationship.
 *
 * @param integer             $p_bug_id       A bug identifier.
 * @param DwgRelationshipData $p_relationship A bug relationship object.
 * @param boolean             $p_html_preview Whether to include
 *                                            style/hyperlinks - if preview is
 *                                            false, we prettify the output.
 * @param boolean             $p_show_project Show Project details.
 *
 * @return string
 *
 * @throws ClientException
 */
function dwg_view_relationship_get_details( $p_bug_id, DwgRelationshipData $p_relationship, $p_html_preview = false, $p_show_project = false ) {
	if( $p_bug_id == $p_relationship->src_dwg_id ) {
		# root bug is in the source side, related bug in the destination side
		$t_related_project_id = $p_relationship->dest_dwg_id;
		$t_related_project_name = project_get_name( $p_relationship->dest_project_id );
		$t_related_bug_id = $p_relationship->dest_dwg_id;
		$t_relationship_descr = dwg_relationship_get_description_src_side( $p_relationship->type );
	} else {
		# root bug is in the dest side, related bug in the source side
		$t_related_project_id = $p_relationship->src_dwg_id;
		$t_related_bug_id = $p_relationship->src_dwg_id;
		$t_related_project_name = project_get_name( $p_relationship->src_project_id );
		$t_relationship_descr = dwg_relationship_get_description_dest_side( $p_relationship->type );
	}

	# related bug not existing...
	if( !dwg_exists( $t_related_bug_id ) ) {
		return '';
	}

	# user can access to the related bug at least as a viewer
	if( !access_has_dwg_level( config_get( 'view_dwg_threshold', null, null, $t_related_project_id ), $t_related_bug_id ) ) {
		return '';
	}

	if( !$p_html_preview ) {
		$t_td = '<td>';
	} else {
		$t_td = '<td class="print">';
	}

	# get the information from the related bug and prepare the link
	$t_current_user_id = auth_get_current_user_id();
	$t_dwg = dwg_get( $t_related_bug_id );
	$t_status_string = get_enum_element( 'dwg_status', $t_dwg->status, $t_current_user_id, $t_dwg->project_id );
	$t_resolution_string = get_enum_element( 'resolution', $t_dwg->resolution, $t_current_user_id, $t_dwg->project_id );

	$t_relationship_info_html = $t_td . string_no_break( $t_relationship_descr ) . '&#160;</td>';
	if( !$p_html_preview ) {
		# choose color based on status
		$t_status_css = html_get_status_css_fg( $t_dwg->status, $t_current_user_id, $t_dwg->project_id );
		$t_relationship_info_html .= '<td><a href="' . string_get_dwg_view_url( $t_related_bug_id ) . '">' . string_display_line( dwg_format_id( $t_related_bug_id ) ) . '</a></td>';
		$t_relationship_info_html .= '<td>' . icon_get( 'fa-square', 'fa-status-box ' . $t_status_css );
		$t_relationship_info_html .= ' <span class="issue-status" title="' . string_attribute( $t_resolution_string ) . '">' . string_display_line( $t_status_string ) . '</span></td>';
	} else {
		$t_relationship_info_html .= $t_td . string_display_line( dwg_format_id( $t_related_bug_id ) ) . '</td>';
		$t_relationship_info_html .= $t_td . string_display_line( $t_status_string ) . '&#160;</td>';
	}

	# get the handler name of the related bug
	$t_relationship_info_html .= $t_td;
	if( $t_dwg->handler_id > 0 ) {
		$t_relationship_info_html .= string_no_break( prepare_user_name( $t_dwg->handler_id ) );
	}

	$t_relationship_info_html .= '&#160;</td>';

	# add project name
	if( $p_show_project ) {
		$t_relationship_info_html .= $t_td . string_display_line( $t_related_project_name ) . '&#160;</td>';
	}

	# add summary
	$t_relationship_info_html .= $t_td . string_display_line_links( $t_dwg->summary );
	if( VS_PRIVATE == $t_dwg->view_state ) {
		$t_relationship_info_html .= icon_get( 'fa-lock', '', lang_get( 'private' ) );
	}

	# add delete link if bug not read only and user has access level
	if( !dwg_is_readonly( $p_bug_id ) && !current_user_is_anonymous() && !$p_html_preview ) {
		if( access_has_dwg_level( config_get( 'update_dwg_threshold' ), $p_bug_id ) ) {
			$t_relationship_info_html .= ' <a class="red noprint zoom-130" '
				. 'href="dwg_relationship_delete.php?bug_id=' . $p_bug_id
				. '&amp;rel_id=' . $p_relationship->id
				. htmlspecialchars( form_security_param( 'dwg_relationship_delete' ) )
				. '">'
				. icon_get( 'fa-trash-o', 'ace-icon bigger-115' )
				. '</a>';
		}
	}

	$t_relationship_info_html .= '&#160;</td>';
	return '<tr>' . $t_relationship_info_html . '</tr>';
}

/**
 * Print all the relationships of a specific bug.
 *
 * @param integer $p_bug_id A bug identifier.
 * @return string
 *
 * @throws ClientException
 */
function dwg_view_relationship_get_summary_html( $p_bug_id ) {
	$t_summary = '';

	# A variable that will be set by the following call to indicate if relationships belong
	# to multiple projects.
	$t_show_project = false;

	$t_relationship_all = dwg_relationship_get_all( $p_bug_id, $t_show_project );
	$t_relationship_all_count = count( $t_relationship_all );

	# prepare the relationships table
	for( $i = 0; $i < $t_relationship_all_count; $i++ ) {
		$t_summary .= dwg_view_relationship_get_details( $p_bug_id, $t_relationship_all[$i], /* html_preview */ false, $t_show_project );
	}

	if( !is_blank( $t_summary ) ) {
		if( !dwg_relationship_can_resolve_dwg( $p_bug_id ) ) {
			$t_summary .= '<tr><td colspan="' . ( 5 + $t_show_project ) . '"><strong>' .
				lang_get( 'relationship_warning_blocking_dwgs_not_resolved' ) . '</strong></td></tr>';
		}
		$t_summary = '<table class="table table-bordered table-condensed table-hover">' . $t_summary . '</table>';
	}

	return $t_summary;
}

/**
 * Print HTML relationship form.
 *
 * @param integer $p_bug_id     A bug identifier.
 * @param bool    $p_can_update Can update relationships?
 * @return void
 *
 * @throws ClientException
 */
function dwg_view_relationship_view_box( $p_bug_id, $p_can_update ) {
	$t_relationships_html = dwg_view_relationship_get_summary_html( $p_bug_id );

	if( !$p_can_update && empty( $t_relationships_html ) ) {
		return;
	}

	$t_relationship_graph = ON == config_get( 'relationship_graph_enable' );
	$t_event_buttons = event_signal( 'EVENT_MENU_ISSUE_RELATIONSHIP', $p_bug_id );
	$t_show_top_div = $p_can_update || $t_relationship_graph || !empty( $t_event_buttons );
?>
	<div class="col-md-12 col-xs-12">
	<div class="space-10"></div>
<?php
	$t_collapse_block = is_collapsed( 'relationships' );
	$t_block_css = $t_collapse_block ? 'collapsed' : '';
	$t_block_icon = $t_collapse_block ? 'fa-chevron-down' : 'fa-chevron-up';
?>
	<div id="relationships" class="widget-box widget-color-blue2 <?php echo $t_block_css ?>">
	<div class="widget-header widget-header-small">
		<h4 class="widget-title lighter">
			<?php print_icon( 'fa-sitemap', 'ace-icon' ); ?>
			<?php echo lang_get( 'dwg_relationships' ) ?>
		</h4>
		<div class="widget-toolbar">
			<a data-action="collapse" href="#">
				<?php print_icon( $t_block_icon, '1 ace-icon bigger-125' ); ?>
			</a>
		</div>
	</div>
	<div class="widget-body">
<?php
	if( $t_show_top_div ) {
?>
		<div class="widget-toolbox padding-8 clearfix">
<?php
		# Default relationship buttons
		$t_buttons = array();
		if( $t_relationship_graph ) {
			$t_buttons[lang_get( 'relation_graph' )] =
				'dwg_relationship_graph.php?bug_id=' . $p_bug_id . '&graph=relation';
			$t_buttons[lang_get( 'dependency_graph' )] =
				'dwg_relationship_graph.php?bug_id=' . $p_bug_id . '&graph=dependency';
		}

		# Plugin-added buttons
		foreach( $t_event_buttons as $t_plugin_buttons ) {
			foreach( $t_plugin_buttons as $t_callback_buttons ) {
				if( is_array( $t_callback_buttons ) ) {
					$t_buttons = array_merge( $t_buttons, $t_callback_buttons );
				}
			}
		}
?>
		<div class="btn-group pull-right noprint">
<?php
		# Print the buttons, if any
		foreach( $t_buttons as $t_label => $t_url ) {
			print_dwg_small_button( $t_url, $t_label );
		}
?>
		</div>

<?php
		if( $p_can_update ) {
?>
		<form method="post" action="dwg_relationship_add.php" class="form-inline noprint">
			<?php echo form_security_field( 'dwg_relationship_add' ) ?>
			<input type="hidden" name="src_bug_id" value="<?php echo $p_bug_id?>" />
			<label class="inline"><?php echo lang_get( 'this_dwg' ) ?>&#160;&#160;</label>
			<?php print_dwg_relationship_list_box( config_get( 'default_dwg_relationship' ) )?>
			<!--suppress HtmlFormInputWithoutLabel -->
			<input type="text" class="input-sm" name="dest_bug_id" value="" />
			<input type="submit" class="btn btn-primary btn-sm btn-white btn-round"
				   name="add_relationship" value="<?php echo lang_get( 'add' )?>" />
		</form>
<?php
		} # can update
?>
		</div>
<?php
	} # show top div
?>

		<div class="widget-main no-padding">
			<div class="table-responsive">
				<?php echo $t_relationships_html; ?>
			</div>
		</div>
	</div>
	</div>
	</div>
<?php
}

/**
 * Print Change Status to: button
 * This code is similar to print_status_option_list except
 * there is no masking, except for the current state
 *
 * @param DwgData $p_bug A valid bug object.
 *
 * @return void
 *
 * @throws ClientException
 */
function dwg_view_button_dwg_change_status( DwgData $p_bug ) {
	$t_current_access = access_get_project_level( $p_bug->project_id );

	$t_enum_list = print_dwg_get_status_option_list(
		$t_current_access,
		$p_bug->status,
		false,
		# Add close if user is bug's creator, still has rights to report issues
		# (to prevent users downgraded to viewers from updating issues) and
		# creators are allowed to close their own issues
		(  dwg_is_user_creator( $p_bug->id, auth_get_current_user_id() )
		&& access_has_dwg_level( config_get( 'create_dwg_threshold' ), $p_bug->id )
		&& ON == config_get( 'allow_creator_close' )
		),
		$p_bug->project_id );

	if( count( $t_enum_list ) > 0 ) {
		# resort the list into ascending order after noting the key from the first element (the default)
		$t_default = key( $t_enum_list );
		ksort( $t_enum_list );

		echo '<form method="post" action="dwg_change_status_page.php" class="form-inline">';
		# CSRF protection not required here - form does not result in modifications

		$t_button_text = lang_get( 'dwg_status_to_button' );
		echo '<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="' . $t_button_text . '" />';

		echo ' <select name="new_status" class="input-sm">';

		# space at beginning of line is important
		foreach( $t_enum_list as $t_key => $t_val ) {
			echo '<option value="' . $t_key . '" ';
			check_selected( $t_key, $t_default );
			echo '>' . $t_val . '</option>';
		}
		echo '</select>';

		$t_dwg_id = string_attribute( $p_bug->id );
		echo '<input type="hidden" name="id" value="' . $t_dwg_id . '" />' . "\n";
		echo '<input type="hidden" name="change_type" value="' . DWG_UPDATE_TYPE_CHANGE_STATUS . '" />' . "\n";

		echo '</form>' . "\n";
	}
}

/**
 * Print Assign To: combo box of possible handlers.
 *
 * @param DwgData $p_bug Bug object.
 *
 * @return void
 *
 * @throws ClientException
 */
function dwg_view_button_dwg_assign_to( DwgData $p_bug ) {
	$t_current_user_id = auth_get_current_user_id();
	$t_options = array();
	$t_default_assign_to = null;

	if( ( $p_bug->handler_id != $t_current_user_id )
		&& access_has_dwg_level( config_get( 'handle_dwg_threshold' ), $p_bug->id, $t_current_user_id )
	) {
		$t_options[] = array(
			$t_current_user_id,
			'[' . lang_get( 'myself' ) . ']',
		);
		$t_default_assign_to = $t_current_user_id;
	}

	if( ( $p_bug->handler_id != $p_bug->creator_id )
		&& user_exists( $p_bug->creator_id )
		&& access_has_dwg_level( config_get( 'handle_dwg_threshold' ), $p_bug->id, $p_bug->creator_id )
	) {
		$t_options[] = array(
			$p_bug->creator_id,
			'[' . lang_get( 'creator' ) . ']',
		);

		if( $t_default_assign_to === null ) {
			$t_default_assign_to = $p_bug->creator_id;
		}
	}

	echo '<form method="post" action="dwg_update.php" class="form-inline">';
	echo form_security_field( 'dwg_update' );
	echo '<input type="hidden" name="last_updated" value="' . $p_bug->last_updated . '" />';
	echo '<input type="hidden" name="action_type" value="' . DWG_UPDATE_TYPE_ASSIGN . '" />';

	$t_button_text = lang_get( 'dwg_assign_to_button' );
	echo '<input type="submit" class="btn btn-primary btn-sm btn-white btn-round" value="' . $t_button_text . '" />';

	echo ' <select class="input-sm" name="handler_id">';

	# space at beginning of line is important

	$t_already_selected = false;

	foreach( $t_options as $t_entry ) {
		$t_id = (int)$t_entry[0];
		$t_caption = string_attribute( $t_entry[1] );

		# if current user and creator can't be selected, then select the first
		# user in the list.
		if( $t_default_assign_to === null ) {
			$t_default_assign_to = $t_id;
		}

		echo '<option value="' . $t_id . '" ';

		if( ( $t_id == $t_default_assign_to ) && !$t_already_selected ) {
			check_selected( $t_id, $t_default_assign_to );
			$t_already_selected = true;
		}

		echo '>' . $t_caption . '</option>';
	}

	# allow un-assigning if already assigned.
	if( $p_bug->handler_id != 0 ) {
		echo '<option value="0">&nbsp;</option>';
	}

	# 0 means currently selected
	print_dwg_assign_to_option_list( 0, $p_bug->project_id );
	echo '</select>';

	$t_dwg_id = string_attribute( $p_bug->id );
	echo '<input type="hidden" name="bug_id" value="' . $t_dwg_id . '" />' . "\n";

	echo '</form>' . "\n";
}

/**
 * Print all buttons for view pages.
 *
 * @param integer $p_bug_id A valid bug identifier.
 * @param array   $p_flags  Flags from issue view command
 *
 * @return void
 *
 * @throws ClientException
 */
function dwg_view_action_buttons( $p_bug_id, $p_flags ) {
	$t_dwg = dwg_get( $p_bug_id );

	echo '<div class="btn-group">';
	# UPDATE button
	if( $p_flags['can_update'] ) {
		echo '<div class="pull-left padding-right-8">';
		html_button( string_get_dwg_update_page(), lang_get( 'edit' ), array( 'bug_id' => $p_bug_id ) );
		echo '</div>';
	}

	# ASSIGN button
	if( $p_flags['can_assign'] ) {
		echo '<div class="pull-left padding-right-8">';
		dwg_view_button_dwg_assign_to( $t_dwg );
		echo '</div>';
	}

	# Change status button/dropdown
	if( $p_flags['can_change_status'] ) {
		echo '<div class="pull-left padding-right-8">';
		dwg_view_button_dwg_change_status( $t_dwg );
		echo '</div>';
	}

	# Unmonitor
	if( $p_flags['can_unmonitor'] ) {
		echo '<div class="pull-left padding-right-2">';
		html_button( 'dwg_monitor_delete.php', lang_get( 'unmonitor_dwg_button' ), array( 'bug_id' => $p_bug_id ) );
		echo '</div>';
	}

	# Monitor
	if( $p_flags['can_monitor'] ) {
		echo '<div class="pull-left padding-right-2">';
		html_button( 'dwg_monitor_add.php', lang_get( 'monitor_dwg_button' ), array( 'bug_id' => $p_bug_id ) );
		echo '</div>';
	}

	# Stick
	if( $p_flags['can_sticky'] ) {
		echo '<div class="pull-left padding-right-2">';
		html_button( 'dwg_stick.php', lang_get( 'stick_dwg_button' ), array( 'bug_id' => $p_bug_id, 'action' => 'stick' ) );
		echo '</div>';
	}

	# Unstick
	if( $p_flags['can_unsticky'] ) {
		echo '<div class="pull-left padding-right-2">';
		html_button( 'dwg_stick.php', lang_get( 'unstick_dwg_button' ), array( 'bug_id' => $p_bug_id, 'action' => 'unstick' ) );
		echo '</div>';
	}

	# CLONE button
	if( $p_flags['can_clone'] ) {
		echo '<div class="pull-left padding-right-2">';
		html_button( string_get_dwg_create_url(), lang_get( 'create_child_dwg_button' ), array( 'm_dwg_id' => $p_bug_id ) );
		echo '</div>';
	}

	# REOPEN button
	if( $p_flags['can_reopen'] ) {
		echo '<div class="pull-left padding-right-2">';
		$t_reopen_status = config_get( 'dwg_reopen_status', null, null, $t_dwg->project_id );
		html_button(
			'dwg_change_status_page.php',
			lang_get( 'reopen_dwg_button' ),
			array( 'id' => $t_dwg->id, 'new_status' => $t_reopen_status, 'change_type' => DWG_UPDATE_TYPE_REOPEN ) );
		echo '</div>';
	}

	# CLOSE button
	if( $p_flags['can_close'] ) {
		$t_closed_status = config_get( 'dwg_closed_status_threshold', null, null, $t_dwg->project_id );
		echo '<div class="pull-left padding-right-2">';
		html_button(
			'dwg_change_status_page.php',
			lang_get( 'close' ),
			array( 'id' => $t_dwg->id, 'new_status' => $t_closed_status, 'change_type' => DWG_UPDATE_TYPE_CLOSE ) );
		echo '</div>';
	}

	# MOVE button
	if( $p_flags['can_move'] ) {
		echo '<div class="pull-left padding-right-2">';
		html_button( 'dwg_actiongroup_page.php', lang_get( 'move' ), array( 'dwg_arr[]' => $p_bug_id, 'action' => 'MOVE' ) );
		echo '</div>';
	}

	# DELETE button
	if( $p_flags['can_delete'] ) {
		echo '<div class="pull-left padding-right-2">';
		html_button( 'dwg_actiongroup_page.php', lang_get( 'delete' ), array( 'dwg_arr[]' => $p_bug_id, 'action' => 'DELETE' ) );
		echo '</div>';
	}

	helper_call_custom_function( 'print_dwg_view_page_custom_buttons', array( $p_bug_id ) );

	echo '</div>';
}

