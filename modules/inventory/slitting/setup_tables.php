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

    // --- Ensure planning.status supports final 3-step slitting flow ---
    $db->query("ALTER TABLE `planning` MODIFY `status` ENUM('Pending','Preparing Slitting','Slitting Completed','Queued','In Progress','Completed','On Hold') NOT NULL DEFAULT 'Pending'");

    // --- Ensure jobs.job_type supports 'Printing' if it wasn't there ---
    $db->query("ALTER TABLE `jobs` MODIFY `job_type` ENUM('Slitting','Printing','Finishing','Jumbo','Flexo') NOT NULL DEFAULT 'Slitting'");

    // --- Ensure jobs.status supports Closed/Finalized lifecycle for Jumbo history ---
    $db->query("ALTER TABLE `jobs` MODIFY `status` ENUM('Queued','Pending','Running','Closed','Finalized','Completed','QC Passed','QC Failed') DEFAULT 'Pending'");

    // --- Add new columns to jobs table for enhanced job card system ---
    try { $db->query("ALTER TABLE `jobs` ADD COLUMN `extra_data` JSON DEFAULT NULL AFTER `notes`"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE `jobs` ADD COLUMN `duration_minutes` INT DEFAULT NULL AFTER `extra_data`"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE `jobs` ADD COLUMN `sequence_order` INT NOT NULL DEFAULT 1 AFTER `duration_minutes`"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE `jobs` ADD COLUMN `department` VARCHAR(50) DEFAULT NULL AFTER `sequence_order`"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE `jobs` ADD COLUMN `previous_job_id` INT DEFAULT NULL AFTER `department`"); } catch (Exception $e) {}
    try { $db->query("ALTER TABLE `jobs` ADD COLUMN `deleted_at` DATETIME DEFAULT NULL AFTER `previous_job_id`"); } catch (Exception $e) {}

    // --- Job notifications table ---
    $db->query("CREATE TABLE IF NOT EXISTS `job_notifications` (
        `id`          INT AUTO_INCREMENT PRIMARY KEY,
        `job_id`      INT NOT NULL,
        `department`  VARCHAR(50) DEFAULT NULL,
        `message`     VARCHAR(500) NOT NULL,
        `type`        ENUM('info','warning','success','error') NOT NULL DEFAULT 'info',
        `is_read`     TINYINT(1) NOT NULL DEFAULT 0,
        `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX `idx_jn_job` (`job_id`),
        INDEX `idx_jn_dept` (`department`),
        INDEX `idx_jn_read` (`is_read`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
