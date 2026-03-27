<?php
// ============================================================
// ERP System — Slitting Module: Auto-create tables
// Called once per page load; safe to run repeatedly.
// Does NOT modify any existing tables.
// ============================================================

function ensureSlittingTables() {
    $db = getDB();

    // --- slitting_batches ---
    $db->query("CREATE TABLE IF NOT EXISTS `slitting_batches` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `batch_no`      VARCHAR(30)   NOT NULL UNIQUE,
        `status`        ENUM('Draft','Executing','Completed') NOT NULL DEFAULT 'Draft',
        `operator_name` VARCHAR(100)  DEFAULT NULL,
        `machine`       VARCHAR(100)  DEFAULT NULL,
        `notes`         TEXT          DEFAULT NULL,
        `created_by`    INT           DEFAULT NULL,
        `created_at`    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX `idx_sb_status`  (`status`),
        INDEX `idx_sb_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // --- slitting_entries ---
    $db->query("CREATE TABLE IF NOT EXISTS `slitting_entries` (
        `id`              INT AUTO_INCREMENT PRIMARY KEY,
        `batch_id`        INT           NOT NULL,
        `parent_roll_no`  VARCHAR(50)   NOT NULL,
        `child_roll_no`   VARCHAR(50)   NOT NULL,
        `slit_width_mm`   DECIMAL(10,2) NOT NULL,
        `slit_length_mtr` DECIMAL(10,2) NOT NULL,
        `qty`             INT           NOT NULL DEFAULT 1,
        `mode`            ENUM('WIDTH','LENGTH') NOT NULL DEFAULT 'WIDTH',
        `destination`     ENUM('JOB','STOCK') NOT NULL DEFAULT 'STOCK',
        `job_no`          VARCHAR(50)   DEFAULT NULL,
        `job_name`        VARCHAR(150)  DEFAULT NULL,
        `job_size`        VARCHAR(100)  DEFAULT NULL,
        `is_remainder`    TINYINT(1)    NOT NULL DEFAULT 0,
        `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_se_batch`  (`batch_id`),
        INDEX `idx_se_parent` (`parent_roll_no`),
        INDEX `idx_se_child`  (`child_roll_no`),
        CONSTRAINT `fk_se_batch` FOREIGN KEY (`batch_id`) REFERENCES `slitting_batches`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
