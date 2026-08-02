-- ------------------------------------------------------------
-- Migration 002 — Portal & form access tables (idempotent)
-- Apply: php database/migrate.php  (or run this file manually)
-- ------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `user_portal_access` (
  `user_id`    INT UNSIGNED NOT NULL,
  `portal`     ENUM('mis','admin') NOT NULL,
  `granted_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `portal`),
  KEY `idx_upa_portal` (`portal`),
  CONSTRAINT `fk_upa_user`   FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_upa_granted` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `user_form_access` (
  `user_id`    INT UNSIGNED NOT NULL,
  `form_id`    INT UNSIGNED NOT NULL,
  `can_fill`   TINYINT(1)   NOT NULL DEFAULT 1,
  `can_view`   TINYINT(1)   NOT NULL DEFAULT 1,
  `granted_by` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `form_id`),
  KEY `idx_ufa_form` (`form_id`),
  CONSTRAINT `fk_ufa_user`   FOREIGN KEY (`user_id`)    REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ufa_form`   FOREIGN KEY (`form_id`)    REFERENCES `survey_forms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ufa_granted` FOREIGN KEY (`granted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
