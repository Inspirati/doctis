<?php
# Coordinate a store replacement with PHP web requests and scheduled jobs.
# No application configuration/database access is needed at this early point.

function git_restore_gate_path(): string {
	return sys_get_temp_dir() . '/doctis-store-' . hash( 'sha256', dirname( __DIR__ ) ) . '.lock';
}

function git_restore_is_management_request(): bool {
	return PHP_SAPI !== 'cli' && in_array( basename( $_SERVER['SCRIPT_FILENAME'] ?? '' ),
		array( 'manage_git_restore_page.php', 'manage_git_restore_action.php', 'login_page.php',
			'login_password_page.php', 'login.php', 'logout_page.php' ), true );
}

function git_restore_request_lock(): void {
	global $g_git_restore_request_lock;
	$t_path = git_restore_gate_path();
	if( is_link( $t_path ) ) {
		throw new RuntimeException( 'Invalid Git store lock file.' );
	}
	$t_created = @fopen( $t_path, 'x' );
	if( $t_created !== false ) {
		chmod( $t_path, 0644 );
		fclose( $t_created );
	}
	# Linux flock also supports an exclusive lock on a read-only descriptor.
	# This permits CLI jobs and FPM to share the file regardless of its owner.
	$g_git_restore_request_lock = @fopen( $t_path, 'r' );
	if( $g_git_restore_request_lock === false ) {
		throw new RuntimeException( 'Cannot open the Git store lock file.' );
	}
	if( !flock( $g_git_restore_request_lock, LOCK_SH | LOCK_NB )
		|| ( is_file( $t_path . '.restore' ) && !git_restore_is_management_request() ) ) {
		http_response_code( 503 );
		header( 'Retry-After: 10' );
		exit( 'Document storage restoration is in progress. Please try again shortly.' );
	}
}
