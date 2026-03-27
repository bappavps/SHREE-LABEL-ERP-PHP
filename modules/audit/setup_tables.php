<?php
// ============================================================
// ERP System — Audit Module: Auto-create tables
// Called once per page load; safe to run repeatedly.
// ============================================================

function ensureAuditTables() {
    $db = getDB();

    // --- inventory_audits ---
    $db->query("CREATE TABLE IF NOT EXISTS `inventory_audits` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `audit_id`        VARCHAR(30)   NOT NULL UNIQUE,
        `session_name`    VARCHAR(255)  NOT NULL,
        `status`          ENUM('In Progress','Finalized') NOT NULL DEFAULT 'In Progress',
        `total_erp`       INT           NOT NULL DEFAULT 0,
        `total_scanned`   INT           NOT NULL DEFAULT 0,
        `matched_count`   INT           NOT NULL DEFAULT 0,
        `missing_count`   INT           NOT NULL DEFAULT 0,
        `extra_count`     INT           NOT NULL DEFAULT 0,
        `match_percent`   DECIMAL(5,2)  NOT NULL DEFAULT 0.00,
        `created_by`      INT           DEFAULT NULL,
        `created_by_name` VARCHAR(100)  DEFAULT NULL,
        `finalized_at`    DATETIME      DEFAULT NULL,
        `finalized_by`    INT           DEFAULT NULL,
        `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- audit_scanned_rolls ---
    $db->query("CREATE TABLE IF NOT EXISTS `audit_scanned_rolls` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `audit_id`    INT           NOT NULL,
        `roll_no`     VARCHAR(50)   NOT NULL,
        `paper_type`  VARCHAR(100)  DEFAULT '',
        `dimension`   VARCHAR(100)  DEFAULT '',
        `scan_time`   DATETIME      NOT NULL,
        `status`      ENUM('Matched','Unknown') NOT NULL DEFAULT 'Unknown',
        `created_at`  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_audit_id` (`audit_id`),
        INDEX `idx_roll_no` (`roll_no`),
        CONSTRAINT `fk_asr_audit` FOREIGN KEY (`audit_id`) REFERENCES `inventory_audits`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
