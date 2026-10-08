<?php
# Doctis - Document Issue Tracking System
#
# Data scripts: SQL snapshots of selected tables, and loading of uploaded SQL
# scripts, for the System Operations "Data Snapshot" and "Load Data" pages.
#
# Both run on the application's own database connection, so they need no
# sudoers rule and act only on what the Doctis database account may change.
# A snapshot holds data only (no CREATE/DROP TABLE). Each INSERT or REPLACE
# names its columns, so a snapshot still loads after a rebuild that adds
# columns with defaults.

require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'database_api.php' );

/**
 * Rows per INSERT/REPLACE statement in a snapshot.
 */
const DB_SCRIPT_ROWS_PER_STATEMENT = 100;

/**
 * Header line recording the schema version a snapshot was taken from.
 */
const DB_SCRIPT_VERSION_PREFIX = '-- Doctis schema version: ';

/**
 * Return the mysqli link behind the ADOdb connection.
 *
 * @return mysqli
 */
function db_script_link(): mysqli {
	global $g_db;

	$t_link = $g_db->_connectionID ?? null;
	if( !( $t_link instanceof mysqli ) ) {
		error_parameters( 'Data scripts require the mysqli database driver.' );
		trigger_error( ERROR_GENERIC, ERROR );
	}
	return $t_link;
}

/**
 * Current schema version recorded by the installer.
 *
 * @return int Version, or -1 when unknown.
 */
function db_script_schema_version(): int {
	return (int)config_get( 'database_version', -1, ALL_USERS, ALL_PROJECTS );
}

/**
 * List the tables of the Doctis database with their row counts.
 *
 * @return array<string,int> Table name => row count, sorted by name.
 */
function db_script_tables(): array {
	$t_tables = array();
	foreach( db_get_table_list() as $t_table ) {
		$t_result = db_query( 'SELECT COUNT(*) FROM ' . db_script_quote_identifier( $t_table ) );
		$t_tables[$t_table] = (int)db_result( $t_result );
	}
	ksort( $t_tables );
	return $t_tables;
}

/**
 * Quote a table or column name with backticks.
 *
 * @param string $p_name Identifier.
 * @return string
 */
function db_script_quote_identifier( string $p_name ): string {
	return '`' . str_replace( '`', '``', $p_name ) . '`';
}

/**
 * Format one fetched value as an SQL literal.
 *
 * Binary columns are written as hex literals so their bytes survive any
 * client character set; numeric columns are written unquoted.
 *
 * @param mysqli      $p_link  Connection, for escaping.
 * @param string|null $p_value Value as returned by mysqli.
 * @param object      $p_field Field metadata from fetch_fields().
 * @return string
 */
function db_script_literal( mysqli $p_link, ?string $p_value, object $p_field ): string {
	static $s_numeric = array(
		MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_LONGLONG,
		MYSQLI_TYPE_INT24, MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL,
		MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_YEAR,
	);

	if( $p_value === null ) {
		return 'NULL';
	}
	if( in_array( $p_field->type, $s_numeric, true ) && is_numeric( $p_value ) ) {
		return $p_value;
	}
	if( $p_field->charsetnr == 63 ) {
		# Binary string or blob.
		return $p_value === '' ? "''" : '0x' . bin2hex( $p_value );
	}
	return "'" . $p_link->real_escape_string( $p_value ) . "'";
}

/**
 * Write a data snapshot of the given tables as an SQL script.
 *
 * @param array    $p_tables  Table names; each must exist in the database.
 * @param boolean  $p_replace True for REPLACE (overwrite rows with the same
 *                            key), false for INSERT (fail on duplicates).
 * @param callable $p_write   Receives each chunk of script text.
 * @return void
 */
function db_script_snapshot( array $p_tables, bool $p_replace, callable $p_write ): void {
	$t_link = db_script_link();
	$t_known = db_script_tables();
	$t_verb = $p_replace ? 'REPLACE' : 'INSERT';

	$t_lines = array(
		'-- Doctis data snapshot',
		'-- Generated: ' . date( 'c' ) . ' by ' . current_user_get_field( 'username' ),
		'-- Database: ' . config_get_global( 'database_name' ),
		DB_SCRIPT_VERSION_PREFIX . db_script_schema_version(),
		'-- Mode: ' . $t_verb . ( $p_replace
			? ' (rows with the same primary or unique key are overwritten)'
			: ' (a row whose key already exists stops the load)' ),
		'-- Load with Manage > System Operations > Load Data.',
	);
	foreach( $p_tables as $t_table ) {
		if( !isset( $t_known[$t_table] ) ) {
			error_parameters( 'Unknown table: ' . $t_table );
			trigger_error( ERROR_GENERIC, ERROR );
		}
		$t_lines[] = '--   ' . $t_table . ': ' . $t_known[$t_table] . ' rows';
	}
	$t_lines[] = '';
	$t_lines[] = 'SET NAMES ' . $t_link->character_set_name() . ';';
	$p_write( implode( "\n", $t_lines ) . "\n" );

	foreach( $p_tables as $t_table ) {
		$t_quoted = db_script_quote_identifier( $t_table );
		$p_write( "\n-- Table " . $t_table . "\n" );

		$t_result = $t_link->query( 'SELECT * FROM ' . $t_quoted, MYSQLI_USE_RESULT );
		if( $t_result === false ) {
			error_parameters( 'Cannot read table ' . $t_table . ': ' . $t_link->error );
			trigger_error( ERROR_GENERIC, ERROR );
		}
		$t_fields = $t_result->fetch_fields();
		$t_columns = array();
		foreach( $t_fields as $t_field ) {
			$t_columns[] = db_script_quote_identifier( $t_field->name );
		}
		$t_prefix = $t_verb . ' INTO ' . $t_quoted . ' (' . implode( ', ', $t_columns ) . ") VALUES\n";

		$t_batch = array();
		while( ( $t_row = $t_result->fetch_row() ) !== null ) {
			$t_values = array();
			foreach( $t_row as $t_index => $t_value ) {
				$t_values[] = db_script_literal( $t_link, $t_value, $t_fields[$t_index] );
			}
			$t_batch[] = '(' . implode( ', ', $t_values ) . ')';
			if( count( $t_batch ) >= DB_SCRIPT_ROWS_PER_STATEMENT ) {
				$p_write( $t_prefix . implode( ",\n", $t_batch ) . ";\n" );
				$t_batch = array();
			}
		}
		$t_result->free();
		if( $t_batch ) {
			$p_write( $t_prefix . implode( ",\n", $t_batch ) . ";\n" );
		}
	}
}

/**
 * Largest script the server accepts in one request (max_allowed_packet).
 *
 * @return int Bytes.
 */
function db_script_max_bytes(): int {
	$t_result = db_query( 'SELECT @@max_allowed_packet' );
	return (int)db_result( $t_result );
}

/**
 * Schema version named in a snapshot header, if any.
 *
 * @param string $p_sql Script text.
 * @return int|null
 */
function db_script_header_version( string $p_sql ): ?int {
	$t_pattern = '/^' . preg_quote( DB_SCRIPT_VERSION_PREFIX, '/' ) . '(-?\d+)\s*$/m';
	if( preg_match( $t_pattern, substr( $p_sql, 0, 4096 ), $t_matches ) ) {
		return (int)$t_matches[1];
	}
	return null;
}

/**
 * Whether a script contains statements that MariaDB commits implicitly, so a
 * transaction cannot roll them back.
 *
 * @param string $p_sql Script text.
 * @return boolean
 */
function db_script_has_ddl( string $p_sql ): bool {
	return (bool)preg_match( '/^\s*(CREATE|DROP|ALTER|TRUNCATE|RENAME)\s/mi', $p_sql );
}

/**
 * Run an SQL script on the Doctis database.
 *
 * Statements run in order and stop at the first error. With a transaction,
 * everything is rolled back on error, except statements MariaDB commits
 * implicitly (CREATE, DROP, ALTER, TRUNCATE, RENAME).
 *
 * @param string  $p_sql         Script text.
 * @param boolean $p_transaction Run inside one transaction.
 * @return array{ok: bool, statements: int, rows: int, errno: int, error: string, rolled_back: bool}
 *         statements: statements that completed; on failure the failing one
 *         is statements + 1.
 */
function db_script_run( string $p_sql, bool $p_transaction ): array {
	$t_link = db_script_link();
	$t_outcome = array(
		'ok' => true, 'statements' => 0, 'rows' => 0,
		'errno' => 0, 'error' => '', 'rolled_back' => false,
	);

	if( $p_transaction ) {
		$t_link->begin_transaction();
	}

	try {
		if( !$t_link->multi_query( $p_sql ) ) {
			$t_outcome['ok'] = false;
		} else {
			while( true ) {
				$t_outcome['statements']++;
				$t_result = $t_link->store_result();
				if( $t_result instanceof mysqli_result ) {
					$t_result->free();
				} else if( $t_link->affected_rows > 0 ) {
					$t_outcome['rows'] += $t_link->affected_rows;
				}
				if( !$t_link->more_results() ) {
					break;
				}
				if( !$t_link->next_result() ) {
					$t_outcome['ok'] = false;
					break;
				}
			}
		}
		if( !$t_outcome['ok'] ) {
			$t_outcome['errno'] = $t_link->errno;
			$t_outcome['error'] = $t_link->error;
		}
	} catch( mysqli_sql_exception $e ) {
		$t_outcome['ok'] = false;
		$t_outcome['errno'] = $e->getCode();
		$t_outcome['error'] = $e->getMessage();
	}

	if( $p_transaction ) {
		if( $t_outcome['ok'] ) {
			$t_link->commit();
		} else {
			$t_link->rollback();
			$t_outcome['rolled_back'] = true;
		}
	}

	# A script may have switched database with USE; the rest of this request
	# (page layout, logging) must use the Doctis database again.
	$t_link->select_db( config_get_global( 'database_name' ) );

	return $t_outcome;
}
