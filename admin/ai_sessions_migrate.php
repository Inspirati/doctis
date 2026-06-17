<?php
/**
 * Doctis — AI Sessions table migration
 *
 * Creates the {ai_sessions} table used to persist conversation history
 * for the AI Assistant feature between page loads.
 *
 * Run on vaio as www-data:
 *   sudo -u www-data php /var/www/html/doctis/admin/ai_sessions_migrate.php
 *
 * Safe to run multiple times — checks for table existence before creating.
 */

define( 'MANTIS_FAST_SKIP_CACHE_WARMUP', true );
require_once( dirname( __DIR__ ) . '/core.php' );

$t_table = db_get_table( 'ai_sessions' );

if( db_table_exists( $t_table ) ) {
	echo "Table '{$t_table}' already exists — nothing to do.\n";
	exit( 0 );
}

$t_sql = "
CREATE TABLE {$t_table} (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    NOT NULL,
    mode       VARCHAR(16)     NOT NULL DEFAULT 'help',
    created    INT UNSIGNED    NOT NULL,
    updated    INT UNSIGNED    NOT NULL,
    history    LONGTEXT        NOT NULL,
    INDEX idx_ai_sessions_user (user_id, mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

db_query( $t_sql );

if( db_table_exists( $t_table ) ) {
	echo "Table '{$t_table}' created successfully.\n";
	exit( 0 );
} else {
	echo "ERROR: table '{$t_table}' was not created.\n";
	exit( 1 );
}
