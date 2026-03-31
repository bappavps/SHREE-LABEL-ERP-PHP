-- ============================================================
-- Shree Label ERP — Database Schema
-- Compatible with InfinityFree / shared hosting phpMyAdmin
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET NAMES utf8mb4;

-- --------------------------------
-- Table: users
-- --------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100)  NOT NULL,
  `email`      VARCHAR(150)  NOT NULL UNIQUE,
  `password`   VARCHAR(255)  NOT NULL,
  `role`       ENUM('admin','manager','operator','viewer') NOT NULL DEFAULT 'operator',
  `group_id`   INT DEFAULT NULL,
  `is_active`  TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_users_group_id` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: user_groups
-- --------------------------------
CREATE TABLE IF NOT EXISTS `user_groups` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `name`        VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: group_page_permissions
-- --------------------------------
CREATE TABLE IF NOT EXISTS `group_page_permissions` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `group_id`   INT NOT NULL,
  `page_path`  VARCHAR(190) NOT NULL,
  `can_view`   TINYINT(1) NOT NULL DEFAULT 1,
  `can_add`    TINYINT(1) NOT NULL DEFAULT 0,
  `can_edit`   TINYINT(1) NOT NULL DEFAULT 0,
  `can_delete` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_group_page` (`group_id`,`page_path`),
  KEY `idx_perm_group` (`group_id`),
  CONSTRAINT `fk_gpp_group` FOREIGN KEY (`group_id`) REFERENCES `user_groups`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: paper_stock
-- --------------------------------
CREATE TABLE IF NOT EXISTS `paper_stock` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `roll_no`         VARCHAR(50)   NOT NULL UNIQUE,
  `paper_type`      VARCHAR(100)  NOT NULL,
  `company`         VARCHAR(100)  NOT NULL,
  `width_mm`        DECIMAL(10,2) NOT NULL,
  `length_mtr`      DECIMAL(10,2) NOT NULL,
  `gsm`             DECIMAL(6,2)  DEFAULT NULL,
  `weight_kg`       DECIMAL(8,2)  DEFAULT NULL,
  `purchase_rate`   DECIMAL(10,2) DEFAULT NULL,
  `sqm`             DECIMAL(10,4) DEFAULT NULL,
  `lot_batch_no`    VARCHAR(50)   DEFAULT NULL,
  `company_roll_no` VARCHAR(50)   DEFAULT NULL,
  `status`          ENUM('Main','Stock','Slitting','Job Assign','In Production','Consumed') NOT NULL DEFAULT 'Main',
  `job_no`          VARCHAR(50)   DEFAULT NULL,
  `job_size`        VARCHAR(100)  DEFAULT NULL,
  `job_name`        VARCHAR(150)  DEFAULT NULL,
  `date_received`   DATE          DEFAULT NULL,
  `date_used`       DATE          DEFAULT NULL,
  `remarks`         TEXT          DEFAULT NULL,
  `created_by`      INT           DEFAULT NULL,
  `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ps_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: estimates
-- --------------------------------
CREATE TABLE IF NOT EXISTS `estimates` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `estimate_no`     VARCHAR(30)   NOT NULL UNIQUE,
  `client_name`     VARCHAR(150)  NOT NULL,
  `label_length_mm` DECIMAL(8,2)  NOT NULL,
  `label_width_mm`  DECIMAL(8,2)  NOT NULL,
  `quantity`        INT           NOT NULL,
  `material_type`   VARCHAR(100)  DEFAULT NULL,
  `printing_colors` TINYINT       DEFAULT 1,
  `sqm_required`    DECIMAL(10,4) DEFAULT NULL,
  `material_rate`   DECIMAL(10,2) DEFAULT NULL,
  `printing_rate`   DECIMAL(10,2) DEFAULT NULL,
  `waste_factor`    DECIMAL(5,3)  DEFAULT 1.150,
  `material_cost`   DECIMAL(12,2) DEFAULT NULL,
  `printing_cost`   DECIMAL(12,2) DEFAULT NULL,
  `total_cost`      DECIMAL(12,2) DEFAULT NULL,
  `margin_pct`      DECIMAL(5,2)  DEFAULT 20.00,
  `selling_price`   DECIMAL(12,2) DEFAULT NULL,
  `status`          ENUM('Draft','Sent','Approved','Rejected','Converted') NOT NULL DEFAULT 'Draft',
  `notes`           TEXT          DEFAULT NULL,
  `created_by`      INT           DEFAULT NULL,
  `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_est_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: sales_orders
-- --------------------------------
CREATE TABLE IF NOT EXISTS `sales_orders` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `order_no`          VARCHAR(30)   NOT NULL UNIQUE,
  `estimate_id`       INT           DEFAULT NULL,
  `client_name`       VARCHAR(150)  NOT NULL,
  `label_length_mm`   DECIMAL(8,2)  DEFAULT NULL,
  `label_width_mm`    DECIMAL(8,2)  DEFAULT NULL,
  `quantity`          INT           NOT NULL,
  `material_type`     VARCHAR(100)  DEFAULT NULL,
  `selling_price`     DECIMAL(12,2) DEFAULT NULL,
  `status`            ENUM('Pending','In Production','Completed','Dispatched','Cancelled') NOT NULL DEFAULT 'Pending',
  `due_date`          DATE          DEFAULT NULL,
  `notes`             TEXT          DEFAULT NULL,
  `created_by`        INT           DEFAULT NULL,
  `created_at`        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_so_estimate`   FOREIGN KEY (`estimate_id`) REFERENCES `estimates`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_so_created_by` FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: planning
-- --------------------------------
CREATE TABLE IF NOT EXISTS `planning` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `job_no`         VARCHAR(60)  DEFAULT NULL,
  `sales_order_id` INT          DEFAULT NULL,
  `job_name`       VARCHAR(255) NOT NULL,
  `machine`        VARCHAR(100) DEFAULT NULL,
  `operator_name`  VARCHAR(100) DEFAULT NULL,
  `scheduled_date` DATE         DEFAULT NULL,
  `status`         ENUM('Pending','Preparing Slitting','Slitting Completed','Queued','In Progress','Completed','On Hold') NOT NULL DEFAULT 'Pending',
  `priority`       ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  `department`     VARCHAR(80)  DEFAULT NULL,
  `extra_data`     LONGTEXT     DEFAULT NULL,
  `sequence_order` INT          NOT NULL DEFAULT 0,
  `notes`          TEXT         DEFAULT NULL,
  `created_by`     INT          DEFAULT NULL,
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_plan_so`         FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_plan_created_by` FOREIGN KEY (`created_by`)     REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: jobs
-- --------------------------------
CREATE TABLE IF NOT EXISTS `jobs` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `job_no`         VARCHAR(30)  NOT NULL UNIQUE,
  `planning_id`    INT          DEFAULT NULL,
  `sales_order_id` INT          DEFAULT NULL,
  `roll_no`        VARCHAR(50)  DEFAULT NULL,
  `job_type`       ENUM('Slitting','Printing','Finishing','Jumbo','Flexo') NOT NULL DEFAULT 'Printing',
  `status`         ENUM('Queued','Pending','Running','Closed','Finalized','Completed','QC Passed','QC Failed') DEFAULT 'Pending',
  `started_at`     DATETIME     DEFAULT NULL,
  `completed_at`   DATETIME     DEFAULT NULL,
  `operator_id`    INT          DEFAULT NULL,
  `notes`          TEXT         DEFAULT NULL,
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `extra_data`       JSON          DEFAULT NULL,
  `duration_minutes` INT           DEFAULT NULL,
  `sequence_order`   INT           NOT NULL DEFAULT 1,
  `department`       VARCHAR(50)   DEFAULT NULL,
  `previous_job_id`  INT           DEFAULT NULL,
  `deleted_at`       DATETIME      DEFAULT NULL,
  CONSTRAINT `fk_jobs_planning`  FOREIGN KEY (`planning_id`)    REFERENCES `planning`(`id`)     ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_so`        FOREIGN KEY (`sales_order_id`) REFERENCES `sales_orders`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_jobs_operator`  FOREIGN KEY (`operator_id`)    REFERENCES `users`(`id`)        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: inventory_logs
-- --------------------------------
CREATE TABLE IF NOT EXISTS `inventory_logs` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `action_type`     ENUM('IN','OUT','ADJUST','SLITTING') NOT NULL,
  `roll_no`         VARCHAR(50)   DEFAULT NULL,
  `paper_stock_id`  INT           DEFAULT NULL,
  `quantity_change` DECIMAL(10,3) DEFAULT NULL,
  `job_no`          VARCHAR(50)   DEFAULT NULL,
  `description`     TEXT          DEFAULT NULL,
  `performed_by`    INT           DEFAULT NULL,
  `created_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_invlog_ps` FOREIGN KEY (`paper_stock_id`) REFERENCES `paper_stock`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invlog_by` FOREIGN KEY (`performed_by`)   REFERENCES `users`(`id`)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: system_settings
-- --------------------------------
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `setting_key`   VARCHAR(100) NOT NULL UNIQUE,
  `setting_value` VARCHAR(500) NOT NULL,
  `description`   VARCHAR(255) DEFAULT NULL,
  `updated_at`    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`, `description`) VALUES
  ('estimate_prefix',  'EST',          'Prefix for estimate numbers'),
  ('order_prefix',     'SO',           'Prefix for sales order numbers'),
  ('job_prefix',       'JC',           'Prefix for job card numbers'),
  ('company_name',     'Shree Label',  'Company display name'),
  ('default_margin',   '20',           'Default profit margin percentage'),
  ('waste_factor',     '1.15',         'Material waste factor for SQM calculation');

-- --------------------------------
-- Table: master_suppliers
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_suppliers` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(255) NOT NULL,
  `gst_number`      VARCHAR(30)  DEFAULT NULL,
  `contact_person`  VARCHAR(100) DEFAULT NULL,
  `phone`           VARCHAR(20)  DEFAULT NULL,
  `email`           VARCHAR(150) DEFAULT NULL,
  `address`         TEXT         DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `city`            VARCHAR(50)  DEFAULT NULL,
  `state`           VARCHAR(50)  DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_raw_materials
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_raw_materials` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(255) NOT NULL,
  `type`       VARCHAR(100) NOT NULL,
  `gsm`        DECIMAL(6,2) DEFAULT NULL,
  `width_mm`   DECIMAL(8,2) DEFAULT NULL,
  `supplier_id` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_mrm_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `master_suppliers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_boms
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_boms` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `bom_name`    VARCHAR(255) NOT NULL,
  `description` TEXT         DEFAULT NULL,
  `status`      ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_bom_items
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_bom_items` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `bom_id`            INT NOT NULL,
  `raw_material_id`   INT NOT NULL,
  `quantity`          DECIMAL(10,3) NOT NULL,
  `unit`              VARCHAR(20) DEFAULT 'kg',
  `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT `fk_bomi_bom`      FOREIGN KEY (`bom_id`)          REFERENCES `master_boms`(`id`)          ON DELETE CASCADE,
  CONSTRAINT `fk_bomi_material` FOREIGN KEY (`raw_material_id`) REFERENCES `master_raw_materials`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_machines
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_machines` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(255) NOT NULL,
  `type`       VARCHAR(100) DEFAULT NULL,
  `section`    VARCHAR(100) DEFAULT NULL,
  `operator_name` VARCHAR(100) DEFAULT NULL,
  `status`     ENUM('Active','Inactive','Maintenance') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_cylinders
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_cylinders` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(255) NOT NULL,
  `size_inch`     DECIMAL(6,2) DEFAULT NULL,
  `teeth`         INT          DEFAULT NULL,
  `material_type` VARCHAR(100) DEFAULT NULL,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_clients
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_clients` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(255) NOT NULL,
  `contact_person`  VARCHAR(100) DEFAULT NULL,
  `phone`           VARCHAR(20)  DEFAULT NULL,
  `email`           VARCHAR(150) DEFAULT NULL,
  `address`         TEXT         DEFAULT NULL,
  `credit_period_days` INT       DEFAULT 0,
  `credit_limit`    DECIMAL(12,2) DEFAULT 0,
  `city`            VARCHAR(50)  DEFAULT NULL,
  `state`           VARCHAR(50)  DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_paper_companies
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_paper_companies` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(150) NOT NULL UNIQUE,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: master_paper_types
-- --------------------------------
CREATE TABLE IF NOT EXISTS `master_paper_types` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(150) NOT NULL UNIQUE,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------
-- Table: inventory_audits
-- --------------------------------
CREATE TABLE IF NOT EXISTS `inventory_audits` (
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
  `updated_at`      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_ia_created_by`   FOREIGN KEY (`created_by`)   REFERENCES `users`(`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ia_finalized_by` FOREIGN KEY (`finalized_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: audit_scanned_rolls
-- --------------------------------
CREATE TABLE IF NOT EXISTS `audit_scanned_rolls` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `audit_id`    INT           NOT NULL,
  `roll_no`     VARCHAR(50)   NOT NULL,
  `paper_type`  VARCHAR(100)  DEFAULT '',
  `dimension`   VARCHAR(100)  DEFAULT '',
  `scan_time`   DATETIME      NOT NULL,
  `status`      ENUM('Matched','Unknown') NOT NULL DEFAULT 'Unknown',
  `created_at`  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_asr_audit_id` (`audit_id`),
  INDEX `idx_asr_roll_no` (`roll_no`),
  CONSTRAINT `fk_asr_audit` FOREIGN KEY (`audit_id`) REFERENCES `inventory_audits`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Slitting Module Tables (ADDITIVE ONLY — safe, no existing table changes)
-- ============================================================

-- --------------------------------
-- Table: slitting_batches
-- --------------------------------
CREATE TABLE IF NOT EXISTS `slitting_batches` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `batch_no`       VARCHAR(30)  NOT NULL UNIQUE,
  `status`         ENUM('Draft','Executing','Completed') NOT NULL DEFAULT 'Draft',
  `operator_name`  VARCHAR(100) DEFAULT NULL,
  `machine`        VARCHAR(100) DEFAULT NULL,
  `notes`          TEXT         DEFAULT NULL,
  `created_by`     INT          DEFAULT NULL,
  `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_sb_status` (`status`),
  INDEX `idx_sb_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: slitting_entries
-- --------------------------------
CREATE TABLE IF NOT EXISTS `slitting_entries` (
  `id`               INT AUTO_INCREMENT PRIMARY KEY,
  `batch_id`         INT          NOT NULL,
  `parent_roll_no`   VARCHAR(50)  NOT NULL,
  `child_roll_no`    VARCHAR(50)  NOT NULL,
  `slit_width_mm`    DECIMAL(10,2) NOT NULL,
  `slit_length_mtr`  DECIMAL(10,2) NOT NULL,
  `qty`              INT          NOT NULL DEFAULT 1,
  `mode`             ENUM('WIDTH','LENGTH') NOT NULL DEFAULT 'WIDTH',
  `destination`      ENUM('JOB','STOCK') NOT NULL DEFAULT 'STOCK',
  `job_no`           VARCHAR(50)  DEFAULT NULL,
  `job_name`         VARCHAR(255) DEFAULT NULL,
  `job_size`         VARCHAR(100) DEFAULT NULL,
  `is_remainder`     TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_se_batch` (`batch_id`),
  INDEX `idx_se_parent` (`parent_roll_no`),
  INDEX `idx_se_child` (`child_roll_no`),
  CONSTRAINT `fk_se_batch` FOREIGN KEY (`batch_id`) REFERENCES `slitting_batches`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Job Card Extended Tables
-- ============================================================

-- --------------------------------
-- Table: job_change_requests
-- --------------------------------
CREATE TABLE IF NOT EXISTS `job_change_requests` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `job_id`              INT NOT NULL,
  `request_type`        VARCHAR(50) NOT NULL DEFAULT 'jumbo_roll_update',
  `payload_json`        LONGTEXT NOT NULL,
  `status`              VARCHAR(20) NOT NULL DEFAULT 'Pending',
  `requested_by`        INT NULL,
  `requested_by_name`   VARCHAR(120) NULL,
  `requested_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_by`         INT NULL,
  `reviewed_by_name`    VARCHAR(120) NULL,
  `reviewed_at`         DATETIME NULL,
  `review_note`         TEXT NULL,
  `created_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_jcr_job_id` (`job_id`),
  INDEX `idx_jcr_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: job_delete_audit
-- --------------------------------
CREATE TABLE IF NOT EXISTS `job_delete_audit` (
  `id`                    INT AUTO_INCREMENT PRIMARY KEY,
  `root_job_id`           INT NOT NULL,
  `root_job_no`           VARCHAR(60) NULL,
  `root_job_type`         VARCHAR(50) NULL,
  `planning_id`           INT NULL,
  `parent_roll_no`        VARCHAR(80) NULL,
  `action_status`         VARCHAR(20) NOT NULL DEFAULT 'completed',
  `deleted_root`          TINYINT(1) NOT NULL DEFAULT 0,
  `deleted_child_jobs`    INT NOT NULL DEFAULT 0,
  `removed_child_rolls`   INT NOT NULL DEFAULT 0,
  `parent_restored`       TINYINT(1) NOT NULL DEFAULT 0,
  `planning_restored`     TINYINT(1) NOT NULL DEFAULT 0,
  `blocked_jobs_json`     LONGTEXT NULL,
  `reset_snapshot_json`   LONGTEXT NULL,
  `requested_by`          INT NULL,
  `requested_by_name`     VARCHAR(120) NULL,
  `created_at`            TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_jda_root_job_id` (`root_job_id`),
  INDEX `idx_jda_status` (`action_status`),
  INDEX `idx_jda_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------
-- Table: job_notifications
-- --------------------------------
CREATE TABLE IF NOT EXISTS `job_notifications` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Print Studio
-- ============================================================

-- --------------------------------
-- Table: print_templates
-- --------------------------------
CREATE TABLE IF NOT EXISTS `print_templates` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `name`            VARCHAR(255) NOT NULL,
  `document_type`   VARCHAR(50) NOT NULL DEFAULT 'Industrial Label',
  `paper_width`     DECIMAL(8,2) NOT NULL DEFAULT 210,
  `paper_height`    DECIMAL(8,2) NOT NULL DEFAULT 297,
  `elements`        LONGTEXT DEFAULT NULL,
  `background`      LONGTEXT DEFAULT NULL,
  `thumbnail`       LONGTEXT DEFAULT NULL,
  `is_default`      TINYINT(1) NOT NULL DEFAULT 0,
  `is_system`       TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- Planning Module
-- ============================================================

-- --------------------------------
-- Table: planning_board_columns
-- --------------------------------
CREATE TABLE IF NOT EXISTS `planning_board_columns` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `department`  VARCHAR(80) NOT NULL,
  `col_key`     VARCHAR(80) NOT NULL,
  `col_label`   VARCHAR(120) NOT NULL,
  `col_type`    VARCHAR(20) NOT NULL DEFAULT 'Text',
  `sort_order`  INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_dept_col` (`department`, `col_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- ENUM expansions for existing installs
-- (fresh installs already have the expanded ENUMs in CREATE TABLE above)
-- ============================================================

-- jobs: expand status ENUM
ALTER TABLE `jobs`
  MODIFY `status` ENUM('Queued','Pending','Running','Closed','Finalized','Completed','QC Passed','QC Failed') DEFAULT 'Pending';

-- jobs: expand job_type ENUM
ALTER TABLE `jobs`
  MODIFY `job_type` ENUM('Slitting','Printing','Finishing','Jumbo','Flexo') NOT NULL DEFAULT 'Slitting';

-- planning: expand status ENUM
ALTER TABLE `planning`
  MODIFY `status` ENUM('Pending','Preparing Slitting','Slitting Completed','Queued','In Progress','Completed','On Hold') NOT NULL DEFAULT 'Pending';

-- planning: add department, extra_data, job_no for existing installs
ALTER TABLE `planning` ADD COLUMN IF NOT EXISTS `department` VARCHAR(80) DEFAULT NULL;
ALTER TABLE `planning` ADD COLUMN IF NOT EXISTS `extra_data` LONGTEXT DEFAULT NULL;
ALTER TABLE `planning` ADD COLUMN IF NOT EXISTS `job_no` VARCHAR(60) DEFAULT NULL;

-- master_machines: add operator_name for existing installs
ALTER TABLE `master_machines` ADD COLUMN IF NOT EXISTS `operator_name` VARCHAR(100) DEFAULT NULL;

-- planning: add sequence_order for job reordering
ALTER TABLE `planning` ADD COLUMN IF NOT EXISTS `sequence_order` INT NOT NULL DEFAULT 0;

-- group_page_permissions: granular add/edit/delete permissions
ALTER TABLE `group_page_permissions` ADD COLUMN IF NOT EXISTS `can_add` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `group_page_permissions` ADD COLUMN IF NOT EXISTS `can_edit` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `group_page_permissions` ADD COLUMN IF NOT EXISTS `can_delete` TINYINT(1) NOT NULL DEFAULT 0;
