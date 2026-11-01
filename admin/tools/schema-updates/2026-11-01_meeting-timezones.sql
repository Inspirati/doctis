-- Doctis schema version: 80
-- Canonical definitions: admin/schema.php steps 60 and 63, schema version 80.
-- Run once through System Operations > Load Data, before using the new code.
-- Occurrence timestamps are not converted. Legacy timezone intent is unknown.
ALTER TABLE `meeting` ADD COLUMN `timezone` varchar(64) NOT NULL DEFAULT '' AFTER `date_start`;
ALTER TABLE `meeting_series` ADD COLUMN `timezone` varchar(64) NOT NULL DEFAULT '' AFTER `recurrence`;
-- Require organisers to confirm the zone before an existing series runs again.
UPDATE `meeting_series` SET active=0,
 last_error='Confirm the series timezone and save Repeats to resume automatic scheduling.'
 WHERE timezone='' AND active=1;
