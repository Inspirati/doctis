<?php
# One-time import of effective global domain settings; no schema or user changes.
# php admin/tools/doctis-migrate-email-domains.php [--apply --admin-user=NAME]
if( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require_once dirname( __DIR__, 2 ) . '/core.php';
require_api( 'email_domain_api.php' );

$t_options = getopt( '', array( 'apply', 'admin-user:' ) );
try {
	$t_snapshot = email_domain_snapshot();
	$t_has_both = !in_array( 'email_domains_source_fallback', $t_snapshot['sources'], true );
	if( $t_snapshot['scoped'] ) { email_domain_fail( 'email_domains_scoped' ); }
	if( isset( $t_options['apply'] ) ) {
		if( empty( $t_options['admin-user'] ) ) { throw new RuntimeException( '--admin-user is required for apply' ); }
		if( !auth_attempt_script_login( $t_options['admin-user'] ) ) { throw new RuntimeException( 'Administrator login failed' ); }
		email_domain_ensure_manage_access();
		if( !$t_has_both ) {
			email_domain_save( $t_snapshot['lists'], $t_snapshot['revision'] );
		}
	}
	echo json_encode( array( 'applied' => isset( $t_options['apply'] ) && !$t_has_both,
		'already_migrated' => $t_has_both, 'scoped_overrides' => $t_snapshot['scoped'],
		'permitted_count' => count( $t_snapshot['lists']['limit_email_domains'] ),
		'exempt_count' => count( $t_snapshot['lists']['signup_captcha_exempt_domains'] ),
		'revision' => $t_snapshot['revision'] ), JSON_PRETTY_PRINT ) . "\n";
} catch( Throwable $t_error ) {
	fwrite( STDERR, $t_error->getMessage() . "\n" );
	exit( 1 );
}
