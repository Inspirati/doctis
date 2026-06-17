<?php
/**
 * Doctis — AI Sessions table migration
 *
 * Creates or upgrades the {ai_sessions} table used to persist conversation
 * history for the AI Assistant feature between page loads.
 *
 * Phase 2: initial table creation (history, user_id, mode, created, updated)
 * Phase 3: adds doc_id and dwg_id columns for the Meeting Assistant
 *
 * Run on vaio as www-data:
 *   sudo -u www-data php /var/www/html/doctis/admin/ai_sessions_migrate.php
 *
 * Safe to run multiple times — checks for table/column existence before acting.
 *
 * NOTE: This script is a development convenience only.  The authoritative
 * table definition is in admin/schema.php (steps 250–253) which is used by
 * admin/tools/doctis-drop-and-create-new-database.sh for fresh installs.
 */

define( 'MANTIS_FAST_SKIP_CACHE_WARMUP', true );
require_once( dirname( __DIR__ ) . '/core.php' );

$t_table = db_get_table( 'ai_sessions' );

# ── Step 1: create table if it does not exist ─────────────────────────────

if( !db_table_exists( $t_table ) ) {
	$t_sql = "
CREATE TABLE {$t_table} (
    id         INT UNSIGNED    NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED    NOT NULL,
    mode       VARCHAR(16)     NOT NULL DEFAULT 'help',
    created    INT UNSIGNED    NOT NULL,
    updated    INT UNSIGNED    NOT NULL,
    history    LONGTEXT        NOT NULL,
    doc_id     VARCHAR(80)     NULL     DEFAULT NULL,
    dwg_id     INT UNSIGNED    NULL     DEFAULT NULL,
    INDEX idx_ai_sessions_user (user_id, mode)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";
	db_query( $t_sql );

	if( db_table_exists( $t_table ) ) {
		echo "Table '{$t_table}' created successfully.\n";
	} else {
		echo "ERROR: table '{$t_table}' was not created.\n";
		exit( 1 );
	}
} else {
	echo "Table '{$t_table}' already exists.\n";
}

# ── Step 2: add doc_id column if missing (Phase 3 addition) ──────────────

$t_check_doc_id = db_query(
	"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = DATABASE()
	   AND TABLE_NAME = " . db_param() . "
	   AND COLUMN_NAME = 'doc_id'",
	[ $t_table ]
);

if( (int) db_result( $t_check_doc_id ) === 0 ) {
	db_query( "ALTER TABLE {$t_table} ADD COLUMN doc_id VARCHAR(80) NULL DEFAULT NULL" );
	echo "Column 'doc_id' added.\n";
} else {
	echo "Column 'doc_id' already exists.\n";
}

# ── Step 3: add dwg_id column if missing (Phase 3 addition) ──────────────

$t_check_dwg_id = db_query(
	"SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
	 WHERE TABLE_SCHEMA = DATABASE()
	   AND TABLE_NAME = " . db_param() . "
	   AND COLUMN_NAME = 'dwg_id'",
	[ $t_table ]
);

if( (int) db_result( $t_check_dwg_id ) === 0 ) {
	db_query( "ALTER TABLE {$t_table} ADD COLUMN dwg_id INT UNSIGNED NULL DEFAULT NULL" );
	echo "Column 'dwg_id' added.\n";
} else {
	echo "Column 'dwg_id' already exists.\n";
}

echo "Migration complete.\n";
exit( 0 );
