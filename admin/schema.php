<?php
# Doctis — flat schema definition
#
# Every table is defined once in its current final form using a raw MySQL
# CREATE TABLE IF NOT EXISTS statement.  There are no incremental ALTER TABLE
# steps.  When the schema needs to change, edit the relevant CREATE TABLE here
# and rebuild the database with:
#
#   bash admin/tools/doctis-drop-and-create-new-database.sh
#
# RULE: never append AddColumnSQL / RenameColumnSQL / AlterColumnSQL steps.
#       Modify the base table definition and rebuild from scratch.
#
# Each array entry uses the 'UpdateSQL' operation so the installer executes
# the raw SQL directly via ADOdb's ExecuteSQLArray, without any column-type
# translation.
#
# @package    Doctis
# @copyright  Copyright 2025 Inspirati
# @license    GPL-2.0-or-later

/**
 * @uses install_helper_functions_api.php
 */
require_api( 'install_helper_functions_api.php' );

/**
 * Begin schema definition — one entry per table, flat, no incremental steps.
 *
 * IMPORTANT: {config} MUST be step 0 — the installer calls config_set()
 * after each successful step to record database_version, so {config} must
 * exist before step 1 executes.
 */
$g_upgrade = array();
$t_idx = 0;

# ── Step 0: config ──────────────────────────────────────────────────────────
# MUST be first — installer writes database_version into config after each step.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'config' ) . " (
	  `config_id` varchar(64) NOT NULL,
	  `project_id` int(11) NOT NULL DEFAULT 0,
	  `user_id` int(11) NOT NULL DEFAULT 0,
	  `access_reqd` int(11) DEFAULT 0,
	  `type` int(11) DEFAULT 90,
	  `value` longtext NOT NULL,
	  PRIMARY KEY (`config_id`,`project_id`,`user_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 1: ai_sessions ─────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'ai_sessions' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `mode` varchar(16) NOT NULL DEFAULT 'help',
	  `created` int(10) unsigned NOT NULL DEFAULT 1,
	  `updated` int(10) unsigned NOT NULL DEFAULT 1,
	  `history` longtext NOT NULL,
	  `doc_id` varchar(80) DEFAULT NULL,
	  `dwg_id` int(10) unsigned DEFAULT NULL,
	  PRIMARY KEY (`id`),
	  KEY `idx_ai_sessions_user_mode` (`user_id`,`mode`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 2: api_token ───────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'api_token' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `name` varchar(128) NOT NULL,
	  `hash` varchar(128) NOT NULL,
	  `date_created` int(10) unsigned NOT NULL DEFAULT 1,
	  `date_used` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  UNIQUE KEY `idx_user_id_name` (`user_id`,`name`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 3: bug ─────────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `reporter_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `handler_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `duplicate_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `priority` smallint(6) NOT NULL DEFAULT 30,
	  `severity` smallint(6) NOT NULL DEFAULT 50,
	  `reproducibility` smallint(6) NOT NULL DEFAULT 10,
	  `status` smallint(6) NOT NULL DEFAULT 10,
	  `resolution` smallint(6) NOT NULL DEFAULT 10,
	  `projection` smallint(6) NOT NULL DEFAULT 10,
	  `eta` smallint(6) NOT NULL DEFAULT 10,
	  `bug_text_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `os` varchar(32) NOT NULL DEFAULT '',
	  `os_build` varchar(32) NOT NULL DEFAULT '',
	  `platform` varchar(32) NOT NULL DEFAULT '',
	  `version` varchar(64) NOT NULL DEFAULT '',
	  `fixed_in_version` varchar(64) NOT NULL DEFAULT '',
	  `build` varchar(32) NOT NULL DEFAULT '',
	  `profile_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `view_state` smallint(6) NOT NULL DEFAULT 10,
	  `summary` varchar(128) NOT NULL DEFAULT '',
	  `sponsorship_total` int(11) NOT NULL DEFAULT 0,
	  `sticky` tinyint(4) NOT NULL DEFAULT 0,
	  `target_version` varchar(64) NOT NULL DEFAULT '',
	  `category_id` int(10) unsigned NOT NULL DEFAULT 1,
	  `date_submitted` int(10) unsigned NOT NULL DEFAULT 1,
	  `due_date` int(10) unsigned NOT NULL DEFAULT 1,
	  `last_updated` int(10) unsigned NOT NULL DEFAULT 1,
	  `document_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `document_sha` varchar(40) NOT NULL DEFAULT '',
	  PRIMARY KEY (`id`),
	  KEY `idx_bug_sponsorship_total` (`sponsorship_total`),
	  KEY `idx_bug_fixed_in_version` (`fixed_in_version`),
	  KEY `idx_bug_status` (`status`),
	  KEY `idx_project` (`project_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 4: bug_file ────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug_file' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `bug_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `title` varchar(250) NOT NULL DEFAULT '',
	  `description` varchar(250) NOT NULL DEFAULT '',
	  `diskfile` varchar(250) NOT NULL DEFAULT '',
	  `filename` varchar(250) NOT NULL DEFAULT '',
	  `folder` varchar(250) NOT NULL DEFAULT '',
	  `filesize` int(11) NOT NULL DEFAULT 0,
	  `file_type` varchar(250) NOT NULL DEFAULT '',
	  `content` longblob DEFAULT NULL,
	  `date_added` int(10) unsigned NOT NULL DEFAULT 1,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `bugnote_id` int(10) unsigned DEFAULT 0,
	  PRIMARY KEY (`id`),
	  KEY `idx_bug_file_bug_id` (`bug_id`),
	  KEY `idx_diskfile` (`diskfile`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 5: bug_history ─────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug_history' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `bug_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `field_name` varchar(64) NOT NULL,
	  `old_value` varchar(255) NOT NULL,
	  `new_value` varchar(255) NOT NULL,
	  `type` smallint(6) NOT NULL DEFAULT 0,
	  `date_modified` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_bug_history_bug_id` (`bug_id`),
	  KEY `idx_history_user_id` (`user_id`),
	  KEY `idx_bug_history_date_modified` (`date_modified`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 6: bug_monitor ─────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug_monitor' ) . " (
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `bug_id` int(10) unsigned NOT NULL DEFAULT 0,
	  PRIMARY KEY (`user_id`,`bug_id`),
	  KEY `idx_bug_id` (`bug_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 7: bug_relationship ────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug_relationship' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `source_bug_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `destination_bug_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `relationship_type` smallint(6) NOT NULL DEFAULT 0,
	  PRIMARY KEY (`id`),
	  KEY `idx_relationship_source` (`source_bug_id`),
	  KEY `idx_relationship_destination` (`destination_bug_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 8: bug_revision ────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug_revision' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `bug_id` int(10) unsigned NOT NULL,
	  `bugnote_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL,
	  `type` int(10) unsigned NOT NULL,
	  `value` longtext NOT NULL,
	  `timestamp` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_bug_rev_type` (`type`),
	  KEY `idx_bug_rev_id_time` (`bug_id`,`timestamp`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 9: bug_tag ─────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug_tag' ) . " (
	  `bug_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `tag_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `date_attached` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`bug_id`,`tag_id`),
	  KEY `idx_bug_tag_tag_id` (`tag_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 10: bug_text ───────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bug_text' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `description` longtext NOT NULL,
	  `steps_to_reproduce` longtext NOT NULL,
	  `additional_information` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 11: bugnote ────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bugnote' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `bug_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `reporter_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `bugnote_text_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `view_state` smallint(6) NOT NULL DEFAULT 10,
	  `note_type` int(11) DEFAULT 0,
	  `note_attr` varchar(250) DEFAULT '',
	  `time_tracking` int(10) unsigned NOT NULL DEFAULT 0,
	  `last_modified` int(10) unsigned NOT NULL DEFAULT 1,
	  `date_submitted` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_bug` (`bug_id`),
	  KEY `idx_last_mod` (`last_modified`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 12: bugnote_text ───────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'bugnote_text' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `note` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 13: category ───────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'category' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `name` varchar(128) NOT NULL DEFAULT '',
	  `status` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  UNIQUE KEY `idx_category_project_name` (`project_id`,`name`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 14: custom_field ───────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'custom_field' ) . " (
	  `id` int(11) NOT NULL AUTO_INCREMENT,
	  `name` varchar(64) NOT NULL DEFAULT '',
	  `type` smallint(6) NOT NULL DEFAULT 0,
	  `possible_values` text NOT NULL,
	  `default_value` varchar(255) NOT NULL DEFAULT '',
	  `valid_regexp` varchar(255) NOT NULL DEFAULT '',
	  `access_level_r` smallint(6) NOT NULL DEFAULT 0,
	  `access_level_rw` smallint(6) NOT NULL DEFAULT 0,
	  `length_min` int(11) NOT NULL DEFAULT 0,
	  `length_max` int(11) NOT NULL DEFAULT 0,
	  `require_report` tinyint(4) NOT NULL DEFAULT 0,
	  `require_update` tinyint(4) NOT NULL DEFAULT 0,
	  `display_report` tinyint(4) NOT NULL DEFAULT 0,
	  `display_update` tinyint(4) NOT NULL DEFAULT 1,
	  `require_resolved` tinyint(4) NOT NULL DEFAULT 0,
	  `display_resolved` tinyint(4) NOT NULL DEFAULT 0,
	  `display_closed` tinyint(4) NOT NULL DEFAULT 0,
	  `require_closed` tinyint(4) NOT NULL DEFAULT 0,
	  `filter_by` tinyint(4) NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_custom_field_name` (`name`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 15: custom_field_project ───────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'custom_field_project' ) . " (
	  `field_id` int(11) NOT NULL DEFAULT 0,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `sequence` smallint(6) NOT NULL DEFAULT 0,
	  PRIMARY KEY (`field_id`,`project_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 16: custom_field_string ────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'custom_field_string' ) . " (
	  `field_id` int(11) NOT NULL DEFAULT 0,
	  `bug_id` int(11) NOT NULL DEFAULT 0,
	  `value` varchar(255) NOT NULL DEFAULT '',
	  `text` longtext DEFAULT NULL,
	  PRIMARY KEY (`field_id`,`bug_id`),
	  KEY `idx_custom_field_bug` (`bug_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 17: documents ──────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'documents' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `title` varchar(255) NOT NULL,
	  `author` varchar(255) NOT NULL DEFAULT '',
	  `publisher` varchar(255) NOT NULL DEFAULT '',
	  `reference` varchar(64) NOT NULL DEFAULT '',
	  `number` varchar(64) NOT NULL DEFAULT '',
	  `edition` varchar(64) NOT NULL DEFAULT '',
	  `revision` varchar(64) NOT NULL DEFAULT '',
	  `link_url` varchar(2048) NOT NULL DEFAULT '',
	  `classification` varchar(64) NOT NULL DEFAULT '',
	  `revision_date` int(10) unsigned NOT NULL DEFAULT 1,
	  `release_date` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_documents_reference` (`reference`),
	  KEY `idx_documents_number` (`number`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 18: dwg ────────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `creator_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `handler_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `duplicate_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `category_id` int(10) unsigned NOT NULL DEFAULT 1,
	  `document_id` int(10) unsigned NOT NULL DEFAULT 1,
	  `enabled` tinyint(4) NOT NULL DEFAULT 1,
	  `status` smallint(6) NOT NULL DEFAULT 110,
	  `priority` smallint(6) NOT NULL DEFAULT 30,
	  `view_state` smallint(6) NOT NULL DEFAULT 10,
	  `version` varchar(64) NOT NULL DEFAULT '',
	  `discipline` varchar(64) NOT NULL DEFAULT '',
	  `classification` varchar(64) NOT NULL DEFAULT '',
	  `summary` varchar(255) NOT NULL DEFAULT '',
	  `link_url` varchar(2048) NOT NULL DEFAULT '',
	  `date_submitted` int(10) unsigned NOT NULL DEFAULT 1,
	  `last_updated` int(10) unsigned NOT NULL DEFAULT 1,
	  `due_date` int(10) unsigned NOT NULL DEFAULT 1,
	  `dwg_text_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `sticky` tinyint(4) NOT NULL DEFAULT 0,
	  PRIMARY KEY (`id`),
	  KEY `idx_dwg_status` (`status`),
	  KEY `idx_dwg_project_status` (`project_id`, `status`),
	  KEY `idx_dwg_document_id` (`document_id`),
	  KEY `idx_dwg_last_updated` (`last_updated`),
	  KEY `idx_dwg_date_submitted` (`date_submitted`),
	  KEY `idx_dwg_creator` (`creator_id`),
	  KEY `idx_dwg_handler` (`handler_id`),
	  KEY `idx_dwg_view_state` (`view_state`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 19: dwg_file ───────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_file' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `dwgnote_id` int(10) unsigned DEFAULT 0,
	  `title` varchar(250) NOT NULL DEFAULT '',
	  `description` varchar(250) NOT NULL DEFAULT '',
	  `diskfile` varchar(250) NOT NULL DEFAULT '',
	  `filename` varchar(250) NOT NULL DEFAULT '',
	  `folder` varchar(250) NOT NULL DEFAULT '',
	  `filesize` int(11) NOT NULL DEFAULT 0,
	  `file_type` varchar(250) NOT NULL DEFAULT '',
	  `date_added` int(10) unsigned NOT NULL DEFAULT 1,
	  `content` longblob DEFAULT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 20: dwg_filters ────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_filters' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(11) NOT NULL DEFAULT 0,
	  `project_id` int(11) NOT NULL DEFAULT 0,
	  `is_public` tinyint(4) DEFAULT NULL,
	  `name` varchar(64) NOT NULL DEFAULT '',
	  `filter_string` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 21: dwg_history ────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_history' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `field_name` varchar(64) NOT NULL,
	  `old_value` varchar(255) NOT NULL,
	  `new_value` varchar(255) NOT NULL,
	  `type` smallint(6) NOT NULL DEFAULT 0,
	  `date_modified` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_dwg_history_dwg_id` (`dwg_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 22: dwg_monitor ────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_monitor' ) . " (
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  PRIMARY KEY (`user_id`,`dwg_id`),
	  KEY `idx_dwg_id` (`dwg_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 23: dwg_primary_file ───────────────────────────────────────────────
# One canonical primary document file per dwg record.
# git_sha holds the commit SHA when using the GIT storage backend.
# git_branch records the branch at time of upload.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_primary_file' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `filename` varchar(250) NOT NULL DEFAULT '',
	  `filesize` int(11) NOT NULL DEFAULT 0,
	  `file_type` varchar(250) NOT NULL DEFAULT '',
	  `git_sha` varchar(250) NOT NULL DEFAULT '',
	  `folder` varchar(250) NOT NULL DEFAULT '',
	  `content` longblob DEFAULT NULL,
	  `date_added` int(10) unsigned NOT NULL DEFAULT 1,
	  `description` varchar(255) NOT NULL DEFAULT '',
	  `git_branch` varchar(64) NOT NULL DEFAULT 'main',
	  PRIMARY KEY (`id`),
	  UNIQUE KEY `idx_dwg_primary_file_dwg_id` (`dwg_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 24: dwg_relationship ───────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_relationship' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `source_dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `destination_dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `relationship_type` smallint(6) NOT NULL DEFAULT 0,
	  PRIMARY KEY (`id`),
	  KEY `idx_dwg_relationship_source` (`source_dwg_id`),
	  KEY `idx_dwg_relationship_destination` (`destination_dwg_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 25: dwg_revision ───────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_revision' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `dwgnote_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `timestamp` int(10) unsigned NOT NULL DEFAULT 1,
	  `type` int(10) unsigned NOT NULL,
	  `value` longtext NOT NULL,
	  PRIMARY KEY (`id`),
	  KEY `idx_dwg_rev_id_time` (`dwg_id`,`timestamp`),
	  KEY `idx_dwg_rev_type` (`type`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 26: dwg_tag ────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_tag' ) . " (
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `tag_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `date_attached` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`dwg_id`,`tag_id`),
	  KEY `idx_dwg_tag_tag_id` (`tag_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 27: dwg_text ───────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwg_text' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `description` longtext NOT NULL,
	  `steps_to_reproduce` longtext NOT NULL,
	  `additional_information` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 28: dwgnote ────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwgnote' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `creator_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `dwgnote_text_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `view_state` smallint(6) NOT NULL DEFAULT 10,
	  `date_submitted` int(10) unsigned NOT NULL DEFAULT 1,
	  `last_modified` int(10) unsigned NOT NULL DEFAULT 1,
	  `note_type` int(11) DEFAULT 0,
	  `time_tracking` int(10) unsigned NOT NULL DEFAULT 0,
	  `note_attr` varchar(250) DEFAULT '',
	  PRIMARY KEY (`id`),
	  KEY `idx_dwg` (`dwg_id`),
	  KEY `idx_dwg_last_mod` (`last_modified`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 29: dwgnote_text ───────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'dwgnote_text' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `note` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 30: email ──────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'email' ) . " (
	  `email_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `email` varchar(191) NOT NULL DEFAULT '',
	  `subject` varchar(250) NOT NULL DEFAULT '',
	  `metadata` longtext NOT NULL,
	  `body` longtext NOT NULL,
	  `submitted` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`email_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 31: filters ────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'filters' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(11) NOT NULL DEFAULT 0,
	  `project_id` int(11) NOT NULL DEFAULT 0,
	  `is_public` tinyint(4) DEFAULT NULL,
	  `name` varchar(64) NOT NULL DEFAULT '',
	  `filter_string` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 32: license ────────────────────────────────────────────────────────
# Doctis-specific: a skill, clearance, or qualification held by a user.
# Controls document access. Unrelated to software licensing.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'license' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `enabled` tinyint(4) NOT NULL DEFAULT 1,
	  `name` varchar(128) NOT NULL DEFAULT '',
	  `match_str` varchar(128) NOT NULL DEFAULT '',
	  `type` varchar(128) NOT NULL DEFAULT '',
	  `status` smallint(6) NOT NULL DEFAULT 10,
	  `view_state` smallint(6) NOT NULL DEFAULT 10,
	  `access_min` smallint(6) NOT NULL DEFAULT 10,
	  `description` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 33: license_dwg_list ───────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'license_dwg_list' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `license_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `status` smallint(6) NOT NULL DEFAULT 10,
	  `date_added` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 34: license_user_list ──────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'license_user_list' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `license_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `status` smallint(6) NOT NULL DEFAULT 10,
	  `date_added` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 35: news ───────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'news' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `poster_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `view_state` smallint(6) NOT NULL DEFAULT 10,
	  `announcement` tinyint(4) NOT NULL DEFAULT 0,
	  `headline` varchar(64) NOT NULL DEFAULT '',
	  `body` longtext NOT NULL,
	  `last_modified` int(10) unsigned NOT NULL DEFAULT 1,
	  `date_posted` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 36: plugin ─────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'plugin' ) . " (
	  `basename` varchar(40) NOT NULL,
	  `enabled` tinyint(4) NOT NULL DEFAULT 0,
	  `protected` tinyint(4) NOT NULL DEFAULT 0,
	  `priority` int(10) unsigned NOT NULL DEFAULT 3,
	  PRIMARY KEY (`basename`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 37: project ────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'project' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `name` varchar(128) NOT NULL DEFAULT '',
	  `status` smallint(6) NOT NULL DEFAULT 10,
	  `enabled` tinyint(4) NOT NULL DEFAULT 1,
	  `view_state` smallint(6) NOT NULL DEFAULT 10,
	  `access_min` smallint(6) NOT NULL DEFAULT 10,
	  `file_path` varchar(250) NOT NULL DEFAULT '',
	  `description` longtext NOT NULL,
	  `category_id` int(10) unsigned NOT NULL DEFAULT 1,
	  `inherit_global` tinyint(4) NOT NULL DEFAULT 0,
	  `reference_url1` varchar(255) NOT NULL DEFAULT '',
	  `reference_url2` varchar(255) NOT NULL DEFAULT '',
	  `classification` varchar(255) NOT NULL DEFAULT '',
	  `due_date` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  UNIQUE KEY `idx_project_name` (`name`),
	  KEY `idx_project_view` (`view_state`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 38: project_file ───────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'project_file' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `title` varchar(250) NOT NULL DEFAULT '',
	  `description` varchar(250) NOT NULL DEFAULT '',
	  `diskfile` varchar(250) NOT NULL DEFAULT '',
	  `filename` varchar(250) NOT NULL DEFAULT '',
	  `folder` varchar(250) NOT NULL DEFAULT '',
	  `filesize` int(11) NOT NULL DEFAULT 0,
	  `file_type` varchar(250) NOT NULL DEFAULT '',
	  `content` longblob DEFAULT NULL,
	  `date_added` int(10) unsigned NOT NULL DEFAULT 1,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 39: project_hierarchy ──────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'project_hierarchy' ) . " (
	  `child_id` int(10) unsigned NOT NULL,
	  `parent_id` int(10) unsigned NOT NULL,
	  `inherit_parent` tinyint(4) NOT NULL DEFAULT 0,
	  UNIQUE KEY `idx_project_hierarchy` (`child_id`,`parent_id`),
	  KEY `idx_project_hierarchy_child_id` (`child_id`),
	  KEY `idx_project_hierarchy_parent_id` (`parent_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 40: project_user_list ──────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'project_user_list' ) . " (
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `access_level` smallint(6) NOT NULL DEFAULT 10,
	  PRIMARY KEY (`project_id`,`user_id`),
	  KEY `idx_project_user` (`user_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 41: project_version ────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'project_version' ) . " (
	  `id` int(11) NOT NULL AUTO_INCREMENT,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `version` varchar(64) NOT NULL DEFAULT '',
	  `description` longtext NOT NULL,
	  `released` tinyint(4) NOT NULL DEFAULT 1,
	  `obsolete` tinyint(4) NOT NULL DEFAULT 0,
	  `date_order` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  UNIQUE KEY `idx_project_version` (`project_id`,`version`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 42: sponsorship ────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'sponsorship' ) . " (
	  `id` int(11) NOT NULL AUTO_INCREMENT,
	  `bug_id` int(11) NOT NULL DEFAULT 0,
	  `user_id` int(11) NOT NULL DEFAULT 0,
	  `amount` int(11) NOT NULL DEFAULT 0,
	  `logo` varchar(128) NOT NULL DEFAULT '',
	  `url` varchar(128) NOT NULL DEFAULT '',
	  `paid` tinyint(4) NOT NULL DEFAULT 0,
	  `date_submitted` int(10) unsigned NOT NULL DEFAULT 1,
	  `last_updated` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_sponsorship_bug_id` (`bug_id`),
	  KEY `idx_sponsorship_user_id` (`user_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 43: tag ────────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'tag' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `name` varchar(100) NOT NULL DEFAULT '',
	  `description` longtext NOT NULL,
	  `date_created` int(10) unsigned NOT NULL DEFAULT 1,
	  `date_updated` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`,`name`),
	  KEY `idx_tag_name` (`name`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 44: tokens ─────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'tokens' ) . " (
	  `id` int(11) NOT NULL AUTO_INCREMENT,
	  `owner` int(11) NOT NULL,
	  `type` int(11) NOT NULL,
	  `value` longtext NOT NULL,
	  `timestamp` int(10) unsigned NOT NULL DEFAULT 1,
	  `expiry` int(10) unsigned NOT NULL DEFAULT 1,
	  PRIMARY KEY (`id`),
	  KEY `idx_typeowner` (`type`,`owner`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 45: user ───────────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'user' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `username` varchar(191) NOT NULL DEFAULT '',
	  `realname` varchar(191) NOT NULL DEFAULT '',
	  `email` varchar(191) NOT NULL DEFAULT '',
	  `password` varchar(64) NOT NULL DEFAULT '',
	  `enabled` tinyint(4) NOT NULL DEFAULT 1,
	  `protected` tinyint(4) NOT NULL DEFAULT 0,
	  `access_level` smallint(6) NOT NULL DEFAULT 10,
	  `login_count` int(11) NOT NULL DEFAULT 0,
	  `lost_password_request_count` smallint(6) NOT NULL DEFAULT 0,
	  `failed_login_count` smallint(6) NOT NULL DEFAULT 0,
	  `cookie_string` varchar(64) NOT NULL DEFAULT '',
	  `last_visit` int(10) unsigned NOT NULL DEFAULT 1,
	  `date_created` int(10) unsigned NOT NULL DEFAULT 1,
	  `position_title` varchar(128) NOT NULL DEFAULT '',
	  `company` varchar(128) NOT NULL DEFAULT '',
	  `phone` varchar(32) NOT NULL DEFAULT '',
	  `department` varchar(64) NOT NULL DEFAULT '',
	  `meeting_invite` tinyint(4) NOT NULL DEFAULT 0,
	  `email_secondary` varchar(191) NOT NULL DEFAULT '',
	  PRIMARY KEY (`id`),
	  UNIQUE KEY `idx_user_cookie_string` (`cookie_string`),
	  UNIQUE KEY `idx_user_username` (`username`),
	  KEY `idx_enable` (`enabled`),
	  KEY `idx_access` (`access_level`),
	  KEY `idx_email` (`email`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 46: user_pref ──────────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'user_pref' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `project_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `default_profile` int(10) unsigned NOT NULL DEFAULT 0,
	  `default_project` int(10) unsigned NOT NULL DEFAULT 0,
	  `refresh_delay` int(11) NOT NULL DEFAULT 0,
	  `redirect_delay` int(11) NOT NULL DEFAULT 0,
	  `bugnote_order` varchar(4) NOT NULL DEFAULT 'ASC',
	  `email_on_new` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_assigned` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_feedback` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_resolved` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_closed` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_reopened` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_bugnote` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_status` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_priority` tinyint(4) NOT NULL DEFAULT 0,
	  `email_on_priority_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_status_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_bugnote_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_reopened_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_closed_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_resolved_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_feedback_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_assigned_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_on_new_min_severity` smallint(6) NOT NULL DEFAULT 10,
	  `email_bugnote_limit` smallint(6) NOT NULL DEFAULT 0,
	  `language` varchar(32) NOT NULL DEFAULT 'english',
	  `timezone` varchar(32) NOT NULL DEFAULT '',
	  `dwgnote_order` varchar(4) NOT NULL DEFAULT 'ASC',
	  `email_dwgnote_limit` smallint(6) NOT NULL DEFAULT 0,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 47: user_print_pref ────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'user_print_pref' ) . " (
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `print_pref` varchar(64) NOT NULL,
	  PRIMARY KEY (`user_id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 48: user_profile ───────────────────────────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"CREATE TABLE IF NOT EXISTS " . db_get_table( 'user_profile' ) . " (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `platform` varchar(32) NOT NULL DEFAULT '',
	  `os` varchar(32) NOT NULL DEFAULT '',
	  `os_build` varchar(32) NOT NULL DEFAULT '',
	  `description` longtext NOT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci"
);

# ── Step 49: seed — default Global category ─────────────────────────────────
# id=1, project_id=0 = global (available to all projects).
# project.category_id defaults to 1, so this row must exist on a fresh install.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"INSERT INTO " . db_get_table( 'category' ) . "
	 (project_id, user_id, name, status)
	 VALUES (0, 0, 'General', 1)"
);

# ── Step 50: seed — MantisCoreFormatting plugin ─────────────────────────────
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"INSERT INTO " . db_get_table( 'plugin' ) . "
	 (basename, enabled)
	 VALUES ('MantisCoreFormatting', 1)"
);

# ── Step 51: seed — default administrator user ──────────────────────────────
# Password is 'administrator' (MD5).  The cookie_string is randomised at
# install time so it is unique per installation.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"INSERT INTO " . db_get_table( 'user' ) . "
	 (username, realname, email, password,
	  enabled, protected, access_level,
	  login_count, lost_password_request_count, failed_login_count,
	  cookie_string, last_visit, date_created)
	 VALUES ('administrator', '', 'root@localhost', '63a9f0ea7bb98050796b649e85481845',
	         1, 0, 90, 3, 0, 0,
	         '" . md5( mt_rand( 0, mt_getrandmax() ) + mt_rand( 0, mt_getrandmax() ) ) . md5( time() ) . "',
	         UNIX_TIMESTAMP(), UNIX_TIMESTAMP())"
);

# ── Step 52: seed — dwg_text anchor row (id=1) ──────────────────────────────
# The placeholder dwg row (step 54) references dwg_text_id=1.
# longtext columns have no DEFAULT so all three must be specified.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"INSERT INTO " . db_get_table( 'dwg_text' ) . "
	 (description, steps_to_reproduce, additional_information)
	 VALUES ('Empty', 'Empty', 'Empty')"
);

# ── Step 53: seed — documents anchor row (id=1) ─────────────────────────────
# The placeholder dwg row (step 54) has document_id DEFAULT 1.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"INSERT INTO " . db_get_table( 'documents' ) . "
	 (title)
	 VALUES ('Empty')"
);

# ── Step 54: seed — placeholder dwg row (id=1, status=archived) ─────────────
# Issues whose target document has been deleted are reassigned to this dwg.
$g_upgrade[$t_idx++] = array( 'UpdateSQL',
	"INSERT INTO " . db_get_table( 'dwg' ) . "
	 (dwg_text_id, status)
	 VALUES (1, 195)"
);

# ── End of schema definition ─────────────────────────────────────────────────
# $t_idx = 55 → database_version = 54 on a fresh install.
#
# To add a new table: append a new step here and rebuild the database.
# Do NOT insert steps between existing entries — always append.
# Do NOT add incremental ALTER TABLE steps — modify the base CREATE TABLE.

unset( $t_idx );
