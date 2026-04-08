ALTER TABLE `paper_stock` ADD COLUMN IF NOT EXISTS `parent_roll_no` VARCHAR(50) DEFAULT NULL AFTER `company_roll_no`;
ALTER TABLE `paper_stock` ADD COLUMN IF NOT EXISTS `source_batch_id` INT DEFAULT NULL AFTER `parent_roll_no`;
ALTER TABLE `paper_stock` ADD COLUMN IF NOT EXISTS `source_allocation_id` INT DEFAULT NULL AFTER `source_batch_id`;
ALTER TABLE `paper_stock` ADD INDEX `idx_ps_parent_roll_no` (`parent_roll_no`);
ALTER TABLE `paper_stock` ADD INDEX `idx_ps_source_batch_id` (`source_batch_id`);
ALTER TABLE `paper_stock` ADD INDEX `idx_ps_source_allocation_id` (`source_allocation_id`);

ALTER TABLE `slitting_batches` ADD COLUMN IF NOT EXISTS `execution_mode` VARCHAR(30) DEFAULT 'single_plan' AFTER `machine`;
ALTER TABLE `slitting_batches` ADD COLUMN IF NOT EXISTS `planning_ids_json` LONGTEXT DEFAULT NULL AFTER `execution_mode`;

ALTER TABLE `slitting_entries` ADD COLUMN IF NOT EXISTS `planning_id` INT DEFAULT NULL AFTER `destination`;
ALTER TABLE `slitting_entries` ADD COLUMN IF NOT EXISTS `plan_no` VARCHAR(60) DEFAULT NULL AFTER `planning_id`;
ALTER TABLE `slitting_entries` ADD COLUMN IF NOT EXISTS `allocation_id` INT DEFAULT NULL AFTER `plan_no`;
ALTER TABLE `slitting_entries` ADD COLUMN IF NOT EXISTS `allocation_sequence` INT DEFAULT NULL AFTER `allocation_id`;
ALTER TABLE `slitting_entries` ADD COLUMN IF NOT EXISTS `department_route` VARCHAR(255) DEFAULT NULL AFTER `allocation_sequence`;
ALTER TABLE `slitting_entries` ADD INDEX `idx_se_planning_id` (`planning_id`);
ALTER TABLE `slitting_entries` ADD INDEX `idx_se_plan_no` (`plan_no`);
ALTER TABLE `slitting_entries` ADD INDEX `idx_se_allocation_id` (`allocation_id`);

CREATE TABLE IF NOT EXISTS `roll_allocations` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id`           INT          NOT NULL,
  `parent_roll_no`     VARCHAR(50)  NOT NULL,
  `planning_id`        INT          DEFAULT NULL,
  `plan_no`            VARCHAR(60)  DEFAULT NULL,
  `job_name`           VARCHAR(255) DEFAULT NULL,
  `job_size`           VARCHAR(100) DEFAULT NULL,
  `department_route`   VARCHAR(255) DEFAULT NULL,
  `destination`        ENUM('JOB','STOCK') NOT NULL DEFAULT 'JOB',
  `allocated_width_mm` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `allocated_length_mtr` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `allocation_sequence` INT         NOT NULL DEFAULT 1,
  `status`             ENUM('Allocated','Executed','Cancelled') NOT NULL DEFAULT 'Allocated',
  `meta_json`          LONGTEXT     DEFAULT NULL,
  `created_by`         INT          DEFAULT NULL,
  `created_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_ra_batch` (`batch_id`),
  INDEX `idx_ra_parent` (`parent_roll_no`),
  INDEX `idx_ra_plan` (`planning_id`),
  INDEX `idx_ra_plan_no` (`plan_no`),
  CONSTRAINT `fk_ra_batch` FOREIGN KEY (`batch_id`) REFERENCES `slitting_batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;