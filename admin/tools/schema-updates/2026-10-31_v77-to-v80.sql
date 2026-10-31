-- Doctis schema update script: database_version 77 -> 80
-- Doctis schema version: 77
--
-- Brings a database installed from schema.php at version 77 up to the
-- schema.php of 2026-10-31 without a rebuild. Run once through Manage >
-- System Operations > Load Data, with the transaction ticked. CREATE and
-- ALTER commit implicitly, so a failure part-way leaves earlier statements
-- applied; every statement here is safe to run again.
-- See CLAUDE.md, "Database Schema Changes".

-- {user}.meeting_invite: default changed from 0 (never) to 2 (all meetings).
ALTER TABLE `user` ALTER COLUMN `meeting_invite` SET DEFAULT 2;

-- schema.php step 78
CREATE TABLE IF NOT EXISTS `ai_conversation` (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `mode` varchar(16) NOT NULL DEFAULT '',
	  `context_key` varchar(80) NOT NULL DEFAULT '',
	  `created` int(10) unsigned NOT NULL DEFAULT 1,
	  `updated` int(10) unsigned NOT NULL DEFAULT 1,
	  `archived` int(10) unsigned NOT NULL DEFAULT 0,
	  `history` longtext NOT NULL,
	  `doc_id` varchar(80) NOT NULL DEFAULT '',
	  `dwg_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `busy_until` int(10) unsigned NOT NULL DEFAULT 0,
	  `pending_request_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `revision` int(10) unsigned NOT NULL DEFAULT 0,
	  PRIMARY KEY (`id`),
	  KEY `idx_ai_conversation_user_mode` (`user_id`, `mode`, `id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema.php step 79
CREATE TABLE IF NOT EXISTS `ai_request` (
	  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
	  `conversation_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `user_id` int(10) unsigned NOT NULL DEFAULT 0,
	  `started` int(10) unsigned NOT NULL DEFAULT 1,
	  `finished` int(10) unsigned NOT NULL DEFAULT 0,
	  `status` varchar(16) NOT NULL DEFAULT 'pending',
	  `request_data` longtext NOT NULL,
	  `response_data` longtext NOT NULL,
	  PRIMARY KEY (`id`),
	  KEY `idx_ai_request_conversation` (`conversation_id`, `id`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- schema.php step 80
INSERT INTO `ai_conversation` (user_id, mode, context_key, created, updated, history, doc_id, dwg_id)
	 SELECT s.user_id, s.mode, COALESCE(s.doc_id, ''), s.created, s.updated, s.history,
	        COALESCE(s.doc_id, ''), COALESCE(s.dwg_id, 0)
	 FROM `ai_sessions` s
	 WHERE NOT EXISTS (SELECT 1 FROM `ai_conversation` c WHERE c.user_id=s.user_id AND c.mode=s.mode);

-- Record the new version last, as the installer does after its final step.
UPDATE `config` SET `value` = '80'
 WHERE `config_id` = 'database_version' AND `project_id` = 0 AND `user_id` = 0;
