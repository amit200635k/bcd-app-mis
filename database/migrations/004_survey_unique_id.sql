-- Migration 004: Survey Unique ID (survey_code)
--
-- Adds an additional human-readable unique identifier to every survey record:
--   JH/{district_id}/{MM}/{YY}/{daywise_count}/{unix_last4}
-- e.g. JH/20/08/26/0003/4821
--
-- The existing `id` and `record_uuid` remain unchanged; survey_code is
-- additional. The counter table backs the global per-day sequence (resets
-- naturally each day because each day gets its own row).

ALTER TABLE `survey_records`
  ADD COLUMN IF NOT EXISTS `survey_code` VARCHAR(64) NULL AFTER `record_uuid`;

ALTER TABLE `survey_records`
  ADD UNIQUE KEY IF NOT EXISTS `uq_survey_records_code` (`survey_code`);

CREATE TABLE IF NOT EXISTS `survey_id_sequences` (
  `counter_date` DATE            NOT NULL,
  `last_value`   INT UNSIGNED    NOT NULL DEFAULT 0,
  PRIMARY KEY (`counter_date`)
) ENGINE=InnoDB;
