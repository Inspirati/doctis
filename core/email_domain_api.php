<?php
# Global email-domain policy. Reads work before login and have no company dependency.
use Mantis\Exceptions\ClientException;

require_api( 'config_api.php' );
require_api( 'database_api.php' );
require_api( 'lang_api.php' );

const EMAIL_DOMAIN_OPTIONS = array( 'limit_email_domains', 'signup_captcha_exempt_domains' );

function email_domain_fail( string $p_key ): void {
	throw new ClientException( lang_get( $p_key ), ERROR_GENERIC_DETAILS, array( lang_get( $p_key ) ) );
}

/** Domain-only input, canonicalised without DNS calls or a new intl dependency. */
function email_domain_normalize( string $p_domain ): string {
	$t_domain = strtolower( trim( $p_domain ) );
	if( strlen( $t_domain ) > 253 || strpos( $t_domain, '.' ) === false ) {
		email_domain_fail( 'email_domains_invalid' );
	}
	foreach( explode( '.', $t_domain ) as $t_label ) {
		if( !preg_match( '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $t_label ) ) {
			email_domain_fail( 'email_domains_invalid' );
		}
	}
	return $t_domain;
}

function email_domain_normalize_list( $p_domains ): array {
	if( !is_array( $p_domains ) || count( $p_domains ) > 500 ) {
		email_domain_fail( 'email_domains_unavailable' );
	}
	$t_domains = array();
	foreach( $p_domains as $t_domain ) {
		if( !is_string( $t_domain ) ) { email_domain_fail( 'email_domains_unavailable' ); }
		$t_domains[] = email_domain_normalize( $t_domain );
	}
	$t_domains = array_values( array_unique( $t_domains ) );
	sort( $t_domains, SORT_STRING );
	return $t_domains;
}

/** Existing config cache provides one snapshot; never depend on current project/user. */
function email_domain_list( string $p_option ): array {
	if( !in_array( $p_option, EMAIL_DOMAIN_OPTIONS, true ) ) {
		email_domain_fail( 'email_domains_unavailable' );
	}
	return email_domain_normalize_list( config_get( $p_option, null, ALL_USERS, ALL_PROJECTS ) );
}

function email_domain_matches( string $p_email, array $p_domains ): bool {
	$t_at = strrpos( $p_email, '@' );
	return $t_at !== false && in_array( strtolower( substr( $p_email, $t_at + 1 ) ), $p_domains, true );
}

/** Validate the entire editor, before writes. Empty restricted lists are never saved. */
function email_domain_from_rows( $p_rows, bool $p_restricted ): array {
	if( !is_array( $p_rows ) || count( $p_rows ) > 200 ) { email_domain_fail( 'email_domains_invalid' ); }
	$t_lists = array_fill_keys( EMAIL_DOMAIN_OPTIONS, array() );
	foreach( $p_rows as $t_row ) {
		if( !is_array( $t_row ) || !isset( $t_row['domain'] ) || !is_string( $t_row['domain'] ) ) {
			email_domain_fail( 'email_domains_invalid' );
		}
		if( !empty( $t_row['remove'] ) || trim( $t_row['domain'] ) === '' ) { continue; }
		$t_domain = email_domain_normalize( $t_row['domain'] );
		$t_allowed = !empty( $t_row['allowed'] );
		$t_exempt = !empty( $t_row['exempt'] );
		if( $p_restricted && $t_exempt && !$t_allowed ) { email_domain_fail( 'email_domains_unused_exemption' ); }
		if( $p_restricted && $t_allowed ) { $t_lists['limit_email_domains'][] = $t_domain; }
		if( $t_exempt ) { $t_lists['signup_captcha_exempt_domains'][] = $t_domain; }
	}
	foreach( $t_lists as &$t_list ) { $t_list = email_domain_normalize_list( $t_list ); }
	unset( $t_list );
	if( $p_restricted && !$t_lists['limit_email_domains'] ) { email_domain_fail( 'email_domains_empty_restricted' ); }
	return $t_lists;
}

/** Raw records are also part of the stale-edit check (including scoped overrides). */
function email_domain_snapshot( bool $p_allow_repair = false ): array {
	$t_result = db_query( 'SELECT config_id, user_id, project_id, type, value, access_reqd FROM {config}'
		. ' WHERE config_id IN (\'limit_email_domains\', \'signup_captcha_exempt_domains\') ORDER BY config_id, user_id, project_id' );
	$t_rows = array();
	$t_scoped = 0;
	$t_sources = array_fill_keys( EMAIL_DOMAIN_OPTIONS, 'email_domains_source_fallback' );
	while( $t_row = db_fetch_array( $t_result ) ) {
		$t_rows[] = $t_row;
		if( (int)$t_row['user_id'] || (int)$t_row['project_id'] ) { ++$t_scoped; }
		else { $t_sources[$t_row['config_id']] = 'email_domains_source_database'; }
	}
	$t_lists = array();
	$t_invalid = false;
	$t_fallback = array();
	foreach( EMAIL_DOMAIN_OPTIONS as $t_option ) {
		$t_fallback[$t_option] = config_get_global( $t_option );
		try { $t_lists[$t_option] = email_domain_list( $t_option ); }
		catch( ClientException $t_error ) {
			if( !$p_allow_repair ) { throw $t_error; }
			# Admin-only editor may replace malformed lists; public reads still fail closed.
			email_domain_ensure_manage_access();
			$t_lists[$t_option] = array();
			$t_invalid = true;
		}
	}
	return array( 'lists' => $t_lists, 'sources' => $t_sources, 'scoped' => $t_scoped,
		'invalid' => $t_invalid,
		'revision' => hash( 'sha256', json_encode( array( $t_rows, $t_lists, $t_fallback ), JSON_THROW_ON_ERROR ) ) );
}

function email_domain_ensure_manage_access(): void {
	require_api( 'access_api.php' );
	if( !access_has_global_level( config_get_global( 'admin_site_threshold' ) ) ) {
		throw new ClientException( 'Access denied to manage email domains', ERROR_ACCESS_DENIED );
	}
}

/** Shared engine for the web editor and one-time configuration migration. */
function email_domain_save( array $p_lists, string $p_revision, bool $p_clear_scoped = false ): void {
	global $g_db;
	email_domain_ensure_manage_access();
	$t_lists = array();
	foreach( EMAIL_DOMAIN_OPTIONS as $t_option ) {
		$t_lists[$t_option] = email_domain_normalize_list( $p_lists[$t_option] ?? null );
	}
	if( $t_lists['limit_email_domains'] && array_diff( $t_lists['signup_captcha_exempt_domains'], $t_lists['limit_email_domains'] ) ) {
		email_domain_fail( 'email_domains_unused_exemption' );
	}
	$t_link = $g_db->_connectionID ?? null;
	if( !( $t_link instanceof mysqli ) ) { email_domain_fail( 'email_domains_save_failed' ); }
	# An advisory lock covers missing global rows as well as existing ones.
	$t_lock_name = 'email-domains-' . substr( hash( 'sha256', config_get_global( 'database_name' ) . db_get_table( 'config' ) ), 0, 40 );
	$t_lock = $t_link->execute_query( 'SELECT GET_LOCK(?, 5)', array( $t_lock_name ) );
	if( !$t_lock || (int)$t_lock->fetch_row()[0] !== 1 ) { email_domain_fail( 'email_domains_stale' ); }
	$t_before = null;
	try {
		$t_engine = $t_link->execute_query( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', array( db_get_table( 'config' ) ) );
		if( !$t_engine || strcasecmp( (string)$t_engine->fetch_row()[0], 'InnoDB' ) !== 0 ) {
			email_domain_fail( 'email_domains_save_failed' );
		}
		if( !$t_link->begin_transaction() ) { throw new RuntimeException( 'Cannot begin transaction' ); }
		# Also block independent SQL writers of existing policy rows during the switch.
		$t_table = '`' . str_replace( '`', '``', db_get_table( 'config' ) ) . '`';
		if( !$t_link->query( "SELECT config_id FROM $t_table WHERE config_id IN ('limit_email_domains','signup_captcha_exempt_domains') FOR UPDATE" ) ) {
			throw new RuntimeException( 'Cannot lock policy records' );
		}
		config_flush_cache();
		$t_before = email_domain_snapshot( true );
		if( !hash_equals( $t_before['revision'], $p_revision ) ) { email_domain_fail( 'email_domains_stale' ); }
		if( $t_before['scoped'] && !$p_clear_scoped ) { email_domain_fail( 'email_domains_scoped' ); }
		# Use config_set's JSON/CONFIG_TYPE_COMPLEX format. Prepared writes here
		# can fail without the generic query handler terminating before rollback.
		foreach( $t_lists as $t_option => $t_domains ) {
			$t_write = $t_link->execute_query( "INSERT INTO $t_table (config_id,user_id,project_id,type,value,access_reqd) VALUES (?,0,0,?,?,?)"
				. ' ON DUPLICATE KEY UPDATE type=VALUES(type), value=VALUES(value), access_reqd=VALUES(access_reqd)',
				array( $t_option, CONFIG_TYPE_COMPLEX, json_encode( $t_domains, JSON_THROW_ON_ERROR ), config_get_global( 'admin_site_threshold' ) ) );
			if( !$t_write ) { throw new RuntimeException( 'Cannot write policy record' ); }
		}
		if( $p_clear_scoped ) {
			if( !$t_link->query( "DELETE FROM $t_table WHERE config_id IN ('limit_email_domains','signup_captcha_exempt_domains') AND (user_id<>0 OR project_id<>0)" ) ) {
				throw new RuntimeException( 'Cannot remove scoped policy records' );
			}
		}
		if( !$t_link->commit() ) { throw new RuntimeException( 'Cannot commit policy' ); }
	} catch( Throwable $t_error ) {
		$t_link->rollback();
		if( $t_error instanceof ClientException ) { throw $t_error; }
		error_log( 'Email domain policy save failed: ' . get_class( $t_error ) );
		email_domain_fail( 'email_domains_save_failed' );
	} finally {
		config_flush_cache();
		$t_link->execute_query( 'SELECT RELEASE_LOCK(?)', array( $t_lock_name ) );
	}
	error_log( 'ADMIN: user ' . auth_get_current_user_id() . ' updated email domains: '
		. json_encode( array( 'before' => $t_before['lists'], 'after' => $t_lists, 'removed_scoped' => $p_clear_scoped ), JSON_UNESCAPED_SLASHES ) );
}

/** Only administrators use this preview; no account data is sent to public forms. */
function email_domain_affected_users( array $p_lists ): array {
	email_domain_ensure_manage_access();
	$t_old = email_domain_snapshot( true )['lists']['limit_email_domains'];
	$t_result = db_query( 'SELECT id, username, email FROM {user} WHERE email<>\'\' ORDER BY username' );
	$t_users = array();
	while( $t_user = db_fetch_array( $t_result ) ) {
		if( ( !$t_old || email_domain_matches( $t_user['email'], $t_old ) )
			&& $p_lists['limit_email_domains'] && !email_domain_matches( $t_user['email'], $p_lists['limit_email_domains'] ) ) {
			$t_users[] = $t_user;
		}
	}
	return $t_users;
}
