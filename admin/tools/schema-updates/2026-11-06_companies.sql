-- Doctis schema 80 -> 82, unprefixed installation. Run once while offline.
-- For configured table prefixes/suffixes, adapt identifiers to that deployment.
-- Canonical definitions: admin/schema.php steps 47, 81 and 82.
-- Take a protected external SQL backup first. No in-database backup tables.
-- All users are deliberately reset to Not specified; no name matching occurs.
CREATE TABLE IF NOT EXISTS `company` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL DEFAULT '',
  `enabled` tinyint(4) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_company_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_general_ci;
INSERT INTO `company` (id, name, enabled) VALUES (1, 'Not specified', 1);
ALTER TABLE `user` DROP COLUMN `company`,
 ADD COLUMN `company_id` int(10) unsigned NOT NULL DEFAULT 1 AFTER `position_title`,
 ADD KEY `idx_company_id` (`company_id`);
UPDATE `user` SET company_id=1;
UPDATE `config` SET value='82' WHERE config_id='database_version' AND project_id=0 AND user_id=0;
