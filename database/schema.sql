-- ============================================================
-- BCD Generic Dynamic Survey Platform — Core Schema (MySQL 8)
-- Run: mysql -u root -p < database/schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `bcd_survey`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `bcd_survey`;

-- ------------------------------------------------------------
-- RBAC
-- ------------------------------------------------------------
CREATE TABLE `roles` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(50)  NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `is_system`   TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_code` (`code`)
) ENGINE=InnoDB;

CREATE TABLE `permissions` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(100) NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `module`     VARCHAR(50)  NOT NULL DEFAULT 'mis',
  `guard`      ENUM('mis','mobile','api') NOT NULL DEFAULT 'mis',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_code` (`code`),
  KEY `idx_permissions_guard` (`guard`)
) ENGINE=InnoDB;

CREATE TABLE `role_permissions` (
  `role_id`       INT UNSIGNED NOT NULL,
  `permission_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`),
  KEY `idx_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_rp_role`       FOREIGN KEY (`role_id`)       REFERENCES `roles` (`id`)       ON DELETE CASCADE,
  CONSTRAINT `fk_rp_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Administrative hierarchy
-- ------------------------------------------------------------
CREATE TABLE `states` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(10)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_states_code` (`code`)
) ENGINE=InnoDB;

CREATE TABLE `departments` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(30)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `state_id`   INT UNSIGNED NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_departments_code` (`code`),
  KEY `idx_departments_state` (`state_id`),
  CONSTRAINT `fk_dept_state` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `districts` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `state_id`   INT UNSIGNED NOT NULL,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `short_name` VARCHAR(50)  NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_districts_code` (`code`),
  KEY `idx_districts_state` (`state_id`),
  CONSTRAINT `fk_district_state` FOREIGN KEY (`state_id`) REFERENCES `states` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `blocks` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `district_id` INT UNSIGNED NOT NULL,
  `code`        VARCHAR(20)  NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_blocks_code` (`code`),
  KEY `idx_blocks_district` (`district_id`),
  CONSTRAINT `fk_block_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `panchayats` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `block_id`   INT UNSIGNED NOT NULL,
  `code`       VARCHAR(20)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_panchayats_code` (`code`),
  KEY `idx_panchayats_block` (`block_id`),
  CONSTRAINT `fk_panchayat_block` FOREIGN KEY (`block_id`) REFERENCES `blocks` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `villages` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `panchayat_id` INT UNSIGNED NOT NULL,
  `code`        VARCHAR(20)  NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `latitude`    DECIMAL(10,7) NULL,
  `longitude`   DECIMAL(10,7) NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_villages_code` (`code`),
  KEY `idx_villages_panchayat` (`panchayat_id`),
  CONSTRAINT `fk_village_panchayat` FOREIGN KEY (`panchayat_id`) REFERENCES `panchayats` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Users, sessions, devices
-- ------------------------------------------------------------
CREATE TABLE `users` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`            VARCHAR(50)  NOT NULL,
  `password_hash`       VARCHAR(255) NOT NULL,
  `plain_password`      VARCHAR(255) NULL COMMENT 'DEV/LOCAL ONLY - raw password; never populated in production',
  `full_name`           VARCHAR(150) NOT NULL,
  `email`               VARCHAR(150) NULL,
  `mobile`              VARCHAR(15)  NULL,
  `department_id`       INT UNSIGNED NULL,
  `district_id`         INT UNSIGNED NULL,
  `block_id`            INT UNSIGNED NULL,
  `panchayat_id`        INT UNSIGNED NULL,
  `village_id`          INT UNSIGNED NULL,
  `status`              ENUM('active','inactive','locked') NOT NULL DEFAULT 'active',
  `must_change_password` TINYINT(1)  NOT NULL DEFAULT 0,
  `login_attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`        DATETIME     NULL,
  `last_login_at`       DATETIME     NULL,
  `created_by`          INT UNSIGNED NULL,
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at`          DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_district` (`district_id`),
  KEY `idx_users_block` (`block_id`),
  KEY `idx_users_status` (`status`),
  CONSTRAINT `fk_user_district` FOREIGN KEY (`district_id`) REFERENCES `districts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_user_block`    FOREIGN KEY (`block_id`)    REFERENCES `blocks` (`id`)    ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `user_roles` (
  `user_id` INT UNSIGNED NOT NULL,
  `role_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`),
  KEY `idx_user_roles_role` (`role_id`),
  CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `devices` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT UNSIGNED NOT NULL,
  `device_id`     VARCHAR(100) NOT NULL,
  `device_name`   VARCHAR(150) NULL,
  `platform`      VARCHAR(30)  NULL,
  `os_version`    VARCHAR(30)  NULL,
  `app_version`   VARCHAR(20)  NULL,
  `fcm_token`     VARCHAR(255) NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_synced_at` DATETIME    NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_devices_user_device` (`user_id`, `device_id`),
  KEY `idx_devices_device` (`device_id`),
  CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `api_tokens` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token_hash`  VARCHAR(64)  NOT NULL,
  `name`        VARCHAR(100) NULL,
  `expires_at`  DATETIME     NULL,
  `last_used_at` DATETIME    NULL,
  `revoked_at`  DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_api_tokens_user` (`user_id`),
  CONSTRAINT `fk_apitoken_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `refresh_tokens` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `device_id`   VARCHAR(100) NULL,
  `token_hash`  VARCHAR(64)  NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `revoked_at`  DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_refresh_tokens_user` (`user_id`),
  CONSTRAINT `fk_reftoken_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `password_resets` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`     INT UNSIGNED NOT NULL,
  `token_hash`  VARCHAR(64)  NOT NULL,
  `expires_at`  DATETIME     NOT NULL,
  `used_at`     DATETIME     NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_password_resets_user` (`user_id`),
  CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Survey builder
-- ------------------------------------------------------------
CREATE TABLE `survey_categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`        VARCHAR(50)  NOT NULL,
  `name`        VARCHAR(100) NOT NULL,
  `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_survey_categories_code` (`code`)
) ENGINE=InnoDB;

CREATE TABLE `survey_forms` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`          VARCHAR(50)  NOT NULL,
  `title`         VARCHAR(200) NOT NULL,
  `description`   TEXT         NULL,
  `category_id`   INT UNSIGNED NULL,
  `current_version` INT UNSIGNED NOT NULL DEFAULT 1,
  `status`        ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `created_by`    INT UNSIGNED NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_survey_forms_code` (`code`),
  KEY `idx_survey_forms_category` (`category_id`),
  KEY `idx_survey_forms_status` (`status`),
  CONSTRAINT `fk_form_category` FOREIGN KEY (`category_id`) REFERENCES `survey_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `survey_versions` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_id`      INT UNSIGNED NOT NULL,
  `version`      INT UNSIGNED NOT NULL DEFAULT 1,
  `status`       ENUM('draft','published','superseded') NOT NULL DEFAULT 'draft',
  `change_note`  VARCHAR(255) NULL,
  `published_at` DATETIME     NULL,
  `published_by` INT UNSIGNED NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_survey_versions` (`form_id`, `version`),
  KEY `idx_survey_versions_status` (`status`),
  CONSTRAINT `fk_version_form` FOREIGN KEY (`form_id`) REFERENCES `survey_forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `survey_sections` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `form_version_id` INT UNSIGNED NOT NULL,
  `title`          VARCHAR(200) NULL,
  `description`    TEXT         NULL,
  `is_heading`     TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_sections_version` (`form_version_id`),
  CONSTRAINT `fk_section_version` FOREIGN KEY (`form_version_id`) REFERENCES `survey_versions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `survey_fields` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `section_id`     INT UNSIGNED NOT NULL,
  `field_key`      VARCHAR(100) NOT NULL,
  `label`          VARCHAR(200) NOT NULL,
  `type`           ENUM('textbox','textarea','number','decimal','date','time','dropdown','radio','checkbox','multi_select','master','location_cascade','gps','camera','signature','barcode','qr_code','file_upload','heading','auto_number')
                   NOT NULL,
  `is_mandatory`   TINYINT(1)   NOT NULL DEFAULT 0,
  `placeholder`    VARCHAR(255) NULL,
  `default_value`  TEXT         NULL,
  `help_text`      TEXT         NULL,
  `show_in_table`  TINYINT(1)   NOT NULL DEFAULT 0,
  `allow_multiple` TINYINT(1)   NOT NULL DEFAULT 0,
  `sort_order`     INT          NOT NULL DEFAULT 0,
  `settings_json`  JSON         NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_fields_section` (`section_id`),
  KEY `idx_survey_fields_type` (`type`),
  CONSTRAINT `fk_field_section` FOREIGN KEY (`section_id`) REFERENCES `survey_sections` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `survey_field_options` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_id`      INT UNSIGNED NOT NULL,
  `option_label`  VARCHAR(200) NOT NULL,
  `option_value`  VARCHAR(200) NOT NULL,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  `is_default`    TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_field_options_field` (`field_id`),
  CONSTRAINT `fk_option_field` FOREIGN KEY (`field_id`) REFERENCES `survey_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `survey_field_validations` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_id`      INT UNSIGNED NOT NULL,
  `rule`          VARCHAR(50)  NOT NULL,
  `rule_value`    VARCHAR(255) NULL,
  `error_message` VARCHAR(255) NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_field_validations_field` (`field_id`),
  CONSTRAINT `fk_validation_field` FOREIGN KEY (`field_id`) REFERENCES `survey_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `survey_conditions` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `field_id`         INT UNSIGNED NOT NULL,
  `target_field_id`  INT UNSIGNED NOT NULL,
  `operator`         ENUM('equals','not_equals','in','not_in','greater_than','less_than','contains') NOT NULL DEFAULT 'equals',
  `condition_value`  VARCHAR(255) NULL,
  `action`           ENUM('show','hide','required') NOT NULL DEFAULT 'show',
  `sort_order`       INT          NOT NULL DEFAULT 0,
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_conditions_field` (`field_id`),
  KEY `idx_survey_conditions_target` (`target_field_id`),
  CONSTRAINT `fk_cond_field`   FOREIGN KEY (`field_id`)        REFERENCES `survey_fields` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cond_target`  FOREIGN KEY (`target_field_id`) REFERENCES `survey_fields` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Survey records & answers
-- ------------------------------------------------------------
CREATE TABLE `survey_records` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_uuid`       CHAR(36)        NOT NULL,
  `form_id`           INT UNSIGNED    NOT NULL,
  `form_version_id`   INT UNSIGNED    NOT NULL,
  `user_id`           INT UNSIGNED    NOT NULL,
  `submitted_by`      INT UNSIGNED    NULL,
  `status`            ENUM('draft','submitted','block_verified','district_verified','approved','published','rejected') NOT NULL DEFAULT 'draft',
  `current_stage`     INT UNSIGNED    NULL,
  `parent_record_id`  BIGINT UNSIGNED NULL,
  `rejection_reason`  TEXT            NULL,
  `device_id`         INT UNSIGNED    NULL,
  `synced_at`         DATETIME        NULL,
  `created_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_survey_records_uuid` (`record_uuid`),
  KEY `idx_survey_records_form` (`form_id`),
  KEY `idx_survey_records_user` (`user_id`),
  KEY `idx_survey_records_status` (`status`),
  KEY `idx_survey_records_updated` (`updated_at`),
  CONSTRAINT `fk_record_form`    FOREIGN KEY (`form_id`)         REFERENCES `survey_forms` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_record_version` FOREIGN KEY (`form_version_id`) REFERENCES `survey_versions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_record_user`    FOREIGN KEY (`user_id`)         REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `survey_answers` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id`   BIGINT UNSIGNED NOT NULL,
  `field_id`    INT UNSIGNED    NOT NULL,
  `field_key`   VARCHAR(100)    NOT NULL,
  `value_text`  TEXT            NULL,
  `value_number` DECIMAL(20,6)  NULL,
  `value_date`  DATE            NULL,
  `value_json`  JSON            NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_answers_record` (`record_id`),
  KEY `idx_survey_answers_field` (`field_id`),
  CONSTRAINT `fk_answer_record` FOREIGN KEY (`record_id`) REFERENCES `survey_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_answer_field`  FOREIGN KEY (`field_id`)  REFERENCES `survey_fields` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `survey_images` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id`    BIGINT UNSIGNED NOT NULL,
  `answer_id`    BIGINT UNSIGNED NULL,
  `file_path`    VARCHAR(500)    NOT NULL,
  `original_name` VARCHAR(255)   NULL,
  `mime_type`    VARCHAR(100)    NULL,
  `size_bytes`   BIGINT UNSIGNED NULL,
  `width`        INT UNSIGNED    NULL,
  `height`       INT UNSIGNED    NULL,
  `category`     ENUM('photo','signature','file','barcode','qr') NOT NULL DEFAULT 'photo',
  `created_at`   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_images_record` (`record_id`),
  CONSTRAINT `fk_image_record` FOREIGN KEY (`record_id`) REFERENCES `survey_records` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `gps_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id`   BIGINT UNSIGNED NULL,
  `user_id`     INT UNSIGNED    NOT NULL,
  `latitude`    DECIMAL(10,7)   NOT NULL,
  `longitude`   DECIMAL(10,7)   NOT NULL,
  `accuracy`    DECIMAL(10,2)   NULL,
  `altitude`    DECIMAL(10,2)   NULL,
  `captured_at` DATETIME        NULL,
  `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_gps_logs_record` (`record_id`),
  KEY `idx_gps_logs_user` (`user_id`),
  CONSTRAINT `fk_gps_record` FOREIGN KEY (`record_id`) REFERENCES `survey_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_gps_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE `record_workflow_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `record_id`  BIGINT UNSIGNED NOT NULL,
  `from_stage` VARCHAR(30)     NULL,
  `to_stage`   VARCHAR(30)     NOT NULL,
  `action`     VARCHAR(50)     NOT NULL,
  `acted_by`   INT UNSIGNED    NULL,
  `remark`     TEXT            NULL,
  `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_workflow_logs_record` (`record_id`),
  KEY `idx_workflow_logs_actor` (`acted_by`),
  CONSTRAINT `fk_wflog_record` FOREIGN KEY (`record_id`) REFERENCES `survey_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wflog_actor`  FOREIGN KEY (`acted_by`)  REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Masters (generic)
-- ------------------------------------------------------------
CREATE TABLE `master_groups` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `code`       VARCHAR(50)  NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `is_system`  TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_master_groups_code` (`code`)
) ENGINE=InnoDB;

CREATE TABLE `master_items` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `group_id`   INT UNSIGNED NOT NULL,
  `code`       VARCHAR(100) NOT NULL,
  `name`       VARCHAR(200) NOT NULL,
  `parent_id`  INT UNSIGNED NULL,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
  `extra_json` JSON         NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_master_items_group_code` (`group_id`, `code`),
  KEY `idx_master_items_group` (`group_id`),
  KEY `idx_master_items_parent` (`parent_id`),
  CONSTRAINT `fk_master_group` FOREIGN KEY (`group_id`)  REFERENCES `master_groups` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_master_parent` FOREIGN KEY (`parent_id`) REFERENCES `master_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Notifications, audit, sync, replication
-- ------------------------------------------------------------
CREATE TABLE `notifications` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(200) NOT NULL,
  `body`         TEXT         NULL,
  `type`         VARCHAR(50)  NOT NULL DEFAULT 'info',
  `target_role_id` INT UNSIGNED NULL,
  `target_user_id` INT UNSIGNED NULL,
  `created_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_role` (`target_role_id`),
  KEY `idx_notifications_user` (`target_user_id`),
  CONSTRAINT `fk_notif_role` FOREIGN KEY (`target_role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_notif_user` FOREIGN KEY (`target_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `notification_recipients` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `notification_id` INT UNSIGNED NOT NULL,
  `user_id`         INT UNSIGNED NOT NULL,
  `device_id`       INT UNSIGNED NULL,
  `is_read`         TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`         DATETIME     NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notif_recipients_user` (`user_id`),
  KEY `idx_notif_recipients_read` (`is_read`),
  CONSTRAINT `fk_nr_notification` FOREIGN KEY (`notification_id`) REFERENCES `notifications` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_nr_user`         FOREIGN KEY (`user_id`)         REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `audit_logs` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED NULL,
  `action`       VARCHAR(100) NOT NULL,
  `module`       VARCHAR(50)  NULL,
  `entity_type`  VARCHAR(50)  NULL,
  `entity_id`    VARCHAR(50)  NULL,
  `before_json`  JSON         NULL,
  `after_json`   JSON         NULL,
  `ip_address`   VARCHAR(45)  NULL,
  `user_agent`   VARCHAR(255) NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_logs_user` (`user_id`),
  KEY `idx_audit_logs_action` (`action`),
  KEY `idx_audit_logs_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE `sync_queue` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `device_id`      INT UNSIGNED NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `record_uuid`    CHAR(36)     NULL,
  `action`         ENUM('upsert','delete') NOT NULL DEFAULT 'upsert',
  `payload_json`   JSON         NOT NULL,
  `status`         ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
  `attempt_count`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error_message`  TEXT         NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`   DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sync_queue_status` (`status`),
  KEY `idx_sync_queue_device` (`device_id`),
  CONSTRAINT `fk_sync_device` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sync_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE `external_db_configs` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `db_type`       ENUM('mssql','oracle','postgres','mysql') NOT NULL,
  `host`          VARCHAR(150) NOT NULL,
  `port`          INT          NULL,
  `database_name` VARCHAR(100) NULL,
  `username`      VARCHAR(100) NULL,
  `password_enc`  VARCHAR(255) NULL,
  `enabled`       TINYINT(1)   NOT NULL DEFAULT 0,
  `last_success_at` DATETIME   NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_external_db_name` (`name`)
) ENGINE=InnoDB;

CREATE TABLE `replication_queue` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `entity_type`   VARCHAR(50)  NOT NULL,
  `entity_id`     VARCHAR(50)  NOT NULL,
  `operation`     ENUM('insert','update','delete') NOT NULL DEFAULT 'insert',
  `payload_json`  JSON         NOT NULL,
  `target_db_id`  INT UNSIGNED NULL,
  `status`        ENUM('pending','processing','success','failed') NOT NULL DEFAULT 'pending',
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error_message` TEXT         NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at`  DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_replication_status` (`status`),
  KEY `idx_replication_target` (`target_db_id`),
  CONSTRAINT `fk_repl_target` FOREIGN KEY (`target_db_id`) REFERENCES `external_db_configs` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Settings & misc
-- ------------------------------------------------------------
CREATE TABLE `settings` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key`  VARCHAR(100) NOT NULL,
  `setting_value` TEXT        NULL,
  `updated_by`   INT UNSIGNED NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_settings_key` (`setting_key`)
) ENGINE=InnoDB;

CREATE TABLE `otp_codes` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `mobile`        VARCHAR(15)  NOT NULL,
  `code`          VARCHAR(10)  NOT NULL,
  `purpose`       VARCHAR(50)  NOT NULL DEFAULT 'login',
  `expires_at`    DATETIME     NOT NULL,
  `attempt_count` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `verified_at`   DATETIME     NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_otp_mobile` (`mobile`),
  KEY `idx_otp_expires` (`expires_at`)
) ENGINE=InnoDB;

CREATE TABLE `import_batches` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `module`         VARCHAR(50)  NOT NULL,
  `file_name`      VARCHAR(255) NOT NULL,
  `file_path`      VARCHAR(500) NOT NULL,
  `status`         ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  `total_rows`     INT UNSIGNED NOT NULL DEFAULT 0,
  `processed_rows` INT UNSIGNED NOT NULL DEFAULT 0,
  `error_rows`     INT UNSIGNED NOT NULL DEFAULT 0,
  `created_by`     INT UNSIGNED NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_import_batches_status` (`status`),
  CONSTRAINT `fk_import_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;
