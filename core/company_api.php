<?php
# Doctis — managed company identities shared by user profiles.

use Mantis\Exceptions\ClientException;

require_api( 'access_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );
require_api( 'lang_api.php' );
require_api( 'string_api.php' );

/** Return a company, including disabled entries retained by existing users. */
function company_get( int $p_id ): array {
	$t_result = db_query( 'SELECT * FROM {company} WHERE id=' . db_param(), array( $p_id ) );
	$t_row = db_fetch_array( $t_result );
	if( !$t_row ) {
		throw new ClientException( 'Company does not exist', ERROR_INVALID_FIELD_VALUE, array( 'company_id' ) );
	}
	return $t_row;
}

/** Return enabled companies plus the current selection, or all for management. */
function company_get_rows( int $p_current_id = COMPANY_NOT_SPECIFIED, bool $p_all = false ): array {
	$t_sql = 'SELECT * FROM {company}';
	$t_params = array();
	if( !$p_all ) {
		$t_sql .= ' WHERE enabled=1 OR id=' . db_param();
		$t_params[] = $p_current_id;
	}
	$t_sql .= ' ORDER BY CASE WHEN id=1 THEN 0 ELSE 1 END, name';
	$t_result = db_query( $t_sql, $t_params );
	$t_rows = array();
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[(int)$t_row['id']] = $t_row;
	}
	return $t_rows;
}

/** Validate an ID before any user/account fields are written. */
function company_validate_selection( $p_id, int $p_current_id ): int {
	if( !( is_int( $p_id ) || ( is_string( $p_id ) && ctype_digit( $p_id ) ) )
		|| (int)$p_id < 1 || (string)(int)$p_id !== (string)$p_id
	) {
		throw new ClientException( 'Company selection is invalid', ERROR_INVALID_FIELD_VALUE, array( 'company_id' ) );
	}
	$t_id = (int)$p_id;
	$t_company = company_get( $t_id );
	if( !$t_company['enabled'] && $t_id !== $p_current_id ) {
		throw new ClientException( 'Company is disabled', ERROR_INVALID_FIELD_VALUE, array( 'company_id' ) );
	}
	return $t_id;
}

/** Company names are unique under the table's case-insensitive collation. */
function company_validate_name( string $p_name, int $p_id = 0 ): string {
	$t_name = trim( $p_name );
	if( $t_name === '' || mb_strlen( $t_name ) > DB_FIELD_SIZE_COMPANY ) {
		throw new ClientException( 'Company name is empty or too long', ERROR_INVALID_FIELD_VALUE, array( 'company' ) );
	}
	$t_result = db_query( 'SELECT id FROM {company} WHERE name=' . db_param() . ' AND id<>' . db_param(), array( $t_name, $p_id ) );
	if( db_num_rows( $t_result ) ) {
		throw new ClientException( 'Company name already exists', ERROR_INVALID_FIELD_VALUE, array( 'company' ) );
	}
	return $t_name;
}

/** Administrators maintain companies; referenced companies are disabled, not deleted. */
function company_ensure_manage_access(): void {
	if( !access_has_global_level( config_get( 'admin_site_threshold' ) ) ) {
		throw new ClientException( 'Access denied to manage companies', ERROR_ACCESS_DENIED );
	}
}

function company_add( string $p_name ): int {
	company_ensure_manage_access();
	$t_name = company_validate_name( $p_name );
	db_query( 'INSERT INTO {company} (name, enabled) VALUES (' . db_param() . ', 1)', array( $t_name ) );
	return db_insert_id( db_get_table( 'company' ) );
}

/** The reserved default must always remain available under its original name. */
function company_update( int $p_id, string $p_name, bool $p_enabled ): void {
	company_ensure_manage_access();
	company_get( $p_id );
	$t_name = company_validate_name( $p_name, $p_id );
	if( $p_id === COMPANY_NOT_SPECIFIED && ( $t_name !== 'Not specified' || !$p_enabled ) ) {
		throw new ClientException( 'The default company cannot be renamed or disabled', ERROR_INVALID_FIELD_VALUE, array( 'company' ) );
	}
	db_query( 'UPDATE {company} SET name=' . db_param() . ', enabled=' . db_param() . ' WHERE id=' . db_param(), array( $t_name, (int)$p_enabled, $p_id ) );
}

/** Shared selector for both profile forms; an existing disabled value may be retained. */
function company_print_options( int $p_current_id ): void {
	foreach( company_get_rows( $p_current_id ) as $t_id => $t_company ) {
		$t_label = $t_company['name'];
		if( !$t_company['enabled'] ) {
			$t_label .= ' (' . lang_get( 'disabled' ) . ')';
		}
		echo '<option value="' . $t_id . '"' . ( $t_id === $p_current_id ? ' selected="selected"' : '' ) . '>'
			. string_html_specialchars( $t_label ) . '</option>';
	}
}
