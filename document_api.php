function document_add( $p_name, $p_number, $p_revision, $p_category, $p_reference, $p_revision_date, $p_release_date ) {
    $t_date_submitted = db_now();
    $t_last_updated   = $t_date_submitted;

    $query = "INSERT INTO {document}
                (name, number, revision, category, reference, revision_date, release_date, date_submitted, last_updated)
              VALUES (" . db_param() . "," . db_param() . "," . db_param() . "," . db_param() . "," . db_param() . "," . db_param() . "," . db_param() . "," . db_param() . "," . db_param() . ")";
    db_query( $query, array( $p_name, $p_number, $p_revision, $p_category, $p_reference, $p_revision_date, $p_release_date, $t_date_submitted, $t_last_updated ) );

    return db_insert_id( db_get_table( 'document' ) );
}

function document_get_all() {
    $query = "SELECT * FROM {document} ORDER BY category, number";
    $result = db_query( $query );
    $rows = array();
    while( $row = db_fetch_array( $result ) ) {
        $rows[] = $row;
    }
    return $rows;
}

