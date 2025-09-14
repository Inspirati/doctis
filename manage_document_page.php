require_once( 'core.php' );
require_api( 'document_api.php' );

access_ensure_global_level( config_get( 'manage_site_threshold' ) );

layout_page_header( lang_get( 'manage_documents_link' ) );
layout_page_begin( 'manage_overview_page.php' );

$t_documents = document_get_all();

echo '<div class="col-md-12 col-xs-12">';
print_manage_menu( 'manage_dwg_page.php' );

echo '<div class="form-container">';
echo '<h2>' . lang_get( 'manage_documents_title' ) . '</h2>';

echo '<table class="table table-bordered table-condensed table-striped">';
echo '<thead><tr><th>ID</th><th>Name</th><th>Number</th><th>Revision</th><th>Category</th><th>Revision Date</th><th>Release Date</th></tr></thead><tbody>';

foreach( $t_documents as $t_doc ) {
    echo '<tr>';
    echo '<td>' . $t_doc['id'] . '</td>';
    echo '<td>' . string_display_line( $t_doc['name'] ) . '</td>';
    echo '<td>' . string_display_line( $t_doc['number'] ) . '</td>';
    echo '<td>' . string_display_line( $t_doc['revision'] ) . '</td>';
    echo '<td>' . string_display_line( $t_doc['category'] ) . '</td>';
    echo '<td>' . date( 'Y-m-d', $t_doc['revision_date'] ) . '</td>';
    echo '<td>' . date( 'Y-m-d', $t_doc['release_date'] ) . '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

print_bracket_link( 'manage_document_edit_page.php', lang_get( 'add_document_link' ) );

echo '</div></div>';

layout_page_end();

