-- ============================================================
-- Calipot ERP Live Sync Migration
-- Date: 2026-04-01
-- Purpose:
--   1) Ensure tables/columns used by latest Jumbo/Slitting/Planning flows exist.
--   2) Ensure enum values required by current code are present.
--   3) Reset Planning Board (label-printing) default visible order:
--      S.N, Job No, Status, Job Name, Priority, ...
-- ============================================================

SET NAMES utf8mb4;

-- ------------------------------------------------------------
-- Bootstrap base tables for fresh/empty databases
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS planning (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_no VARCHAR(60) NULL,
  sales_order_id INT NULL,
  job_name VARCHAR(255) NOT NULL,
  machine VARCHAR(100) NULL,
  operator_name VARCHAR(100) NULL,
  scheduled_date DATE NULL,
  status ENUM('Pending','Preparing Slitting','Slitting Completed','Queued','In Progress','Completed','On Hold') NOT NULL DEFAULT 'Pending',
  priority ENUM('Low','Normal','High','Urgent') NOT NULL DEFAULT 'Normal',
  notes TEXT NULL,
  department VARCHAR(80) NULL,
  extra_data LONGTEXT NULL,
  sequence_order INT NOT NULL DEFAULT 0,
  created_by INT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_planning_job_no(job_no),
  INDEX idx_planning_status(status),
  INDEX idx_planning_dept(department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS jobs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_no VARCHAR(30) NOT NULL UNIQUE,
  planning_id INT NULL,
  sales_order_id INT NULL,
  roll_no VARCHAR(50) NULL,
  job_type ENUM('Slitting','Printing','Finishing','Jumbo','Flexo') NOT NULL DEFAULT 'Printing',
  status ENUM('Queued','Pending','Running','Closed','Finalized','Completed','QC Passed','QC Failed') DEFAULT 'Pending',
  started_at DATETIME NULL,
  completed_at DATETIME NULL,
  operator_id INT NULL,
  notes TEXT NULL,
  extra_data JSON NULL,
  duration_minutes INT NULL,
  sequence_order INT NOT NULL DEFAULT 1,
  department VARCHAR(50) NULL,
  previous_job_id INT NULL,
  deleted_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jobs_job_no(job_no),
  INDEX idx_jobs_status(status),
  INDEX idx_jobs_planning_id(planning_id),
  INDEX idx_jobs_dept(department),
  INDEX idx_jobs_deleted_at(deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Planning table compatibility
-- ------------------------------------------------------------
ALTER TABLE planning ADD COLUMN IF NOT EXISTS department VARCHAR(80) NOT NULL DEFAULT 'general' AFTER notes;
ALTER TABLE planning ADD COLUMN IF NOT EXISTS extra_data LONGTEXT NULL AFTER department;
ALTER TABLE planning ADD COLUMN IF NOT EXISTS job_no VARCHAR(60) NULL AFTER id;
ALTER TABLE planning ADD COLUMN IF NOT EXISTS sequence_order INT NOT NULL DEFAULT 0 AFTER extra_data;

-- Keep planning status enum aligned with slitting/planning code
ALTER TABLE planning
MODIFY status ENUM('Pending','Preparing Slitting','Slitting Completed','Queued','In Progress','Completed','On Hold')
NOT NULL DEFAULT 'Pending';

-- ------------------------------------------------------------
-- Jobs table compatibility
-- ------------------------------------------------------------
ALTER TABLE jobs
MODIFY job_type ENUM('Slitting','Printing','Finishing','Jumbo','Flexo')
NOT NULL DEFAULT 'Slitting';

ALTER TABLE jobs
MODIFY status ENUM('Queued','Pending','Running','Closed','Finalized','Completed','QC Passed','QC Failed')
DEFAULT 'Pending';

ALTER TABLE jobs ADD COLUMN IF NOT EXISTS extra_data JSON DEFAULT NULL AFTER notes;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS duration_minutes INT DEFAULT NULL AFTER extra_data;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS sequence_order INT NOT NULL DEFAULT 1 AFTER duration_minutes;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS department VARCHAR(50) DEFAULT NULL AFTER sequence_order;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS previous_job_id INT DEFAULT NULL AFTER department;
ALTER TABLE jobs ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL AFTER previous_job_id;

-- ------------------------------------------------------------
-- Slitting module tables
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slitting_batches (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_no VARCHAR(30) NOT NULL UNIQUE,
  status ENUM('Draft','Executing','Completed') NOT NULL DEFAULT 'Draft',
  operator_name VARCHAR(100) DEFAULT NULL,
  machine VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_sb_status (status),
  INDEX idx_sb_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS slitting_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  batch_id INT NOT NULL,
  parent_roll_no VARCHAR(50) NOT NULL,
  child_roll_no VARCHAR(50) NOT NULL,
  slit_width_mm DECIMAL(10,2) NOT NULL,
  slit_length_mtr DECIMAL(10,2) NOT NULL,
  qty INT NOT NULL DEFAULT 1,
  mode ENUM('WIDTH','LENGTH') NOT NULL DEFAULT 'WIDTH',
  destination ENUM('JOB','STOCK') NOT NULL DEFAULT 'STOCK',
  job_no VARCHAR(50) DEFAULT NULL,
  job_name VARCHAR(150) DEFAULT NULL,
  job_size VARCHAR(100) DEFAULT NULL,
  is_remainder TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_se_batch (batch_id),
  INDEX idx_se_parent (parent_roll_no),
  INDEX idx_se_child (child_roll_no),
  CONSTRAINT fk_se_batch FOREIGN KEY (batch_id) REFERENCES slitting_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Notifications + Jumbo request workflow tables
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS job_notifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  department VARCHAR(50) DEFAULT NULL,
  message VARCHAR(500) NOT NULL,
  type ENUM('info','warning','success','error') NOT NULL DEFAULT 'info',
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_jn_job (job_id),
  INDEX idx_jn_dept (department),
  INDEX idx_jn_read (is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_change_requests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_id INT NOT NULL,
  request_type VARCHAR(50) NOT NULL DEFAULT 'jumbo_roll_update',
  payload_json LONGTEXT NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'Pending',
  requested_by INT NULL,
  requested_by_name VARCHAR(120) NULL,
  requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_by INT NULL,
  reviewed_by_name VARCHAR(120) NULL,
  reviewed_at DATETIME NULL,
  review_note TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_jcr_job_id (job_id),
  INDEX idx_jcr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_delete_audit (
  id INT AUTO_INCREMENT PRIMARY KEY,
  root_job_id INT NOT NULL,
  root_job_no VARCHAR(60) NULL,
  root_job_type VARCHAR(50) NULL,
  planning_id INT NULL,
  parent_roll_no VARCHAR(80) NULL,
  action_status VARCHAR(20) NOT NULL DEFAULT 'completed',
  deleted_root TINYINT(1) NOT NULL DEFAULT 0,
  deleted_child_jobs INT NOT NULL DEFAULT 0,
  removed_child_rolls INT NOT NULL DEFAULT 0,
  parent_restored TINYINT(1) NOT NULL DEFAULT 0,
  planning_restored TINYINT(1) NOT NULL DEFAULT 0,
  blocked_jobs_json LONGTEXT NULL,
  reset_snapshot_json LONGTEXT NULL,
  requested_by INT NULL,
  requested_by_name VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_jda_root_job_id (root_job_id),
  INDEX idx_jda_status (action_status),
  INDEX idx_jda_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Planning board column configuration table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS planning_board_columns (
  id INT AUTO_INCREMENT PRIMARY KEY,
  department VARCHAR(80) NOT NULL,
  col_key VARCHAR(80) NOT NULL,
  col_label VARCHAR(120) NOT NULL,
  col_type VARCHAR(20) NOT NULL DEFAULT 'Text',
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uniq_dept_col (department, col_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- Reset default header order for label-printing planning board
-- ------------------------------------------------------------
DELETE FROM planning_board_columns WHERE department = 'label-printing';

INSERT INTO planning_board_columns (department, col_key, col_label, col_type, sort_order) VALUES
('label-printing', 'sn', 'S.N', 'Number', 1),
('label-printing', 'printing_planning', 'Status', 'Status', 2),
('label-printing', 'name', 'Job Name', 'Text', 3),
('label-printing', 'priority', 'Priority', 'Priority', 4),
('label-printing', 'order_date', 'Order Date', 'Date', 5),
('label-printing', 'dispatch_date', 'Dispatch Date', 'Date', 6),
('label-printing', 'plate_no', 'Plate No', 'Text', 7),
('label-printing', 'size', 'Size', 'Text', 8),
('label-printing', 'repeat', 'Repeat', 'Text', 9),
('label-printing', 'material', 'Material', 'Text', 10),
('label-printing', 'paper_size', 'Paper Size', 'Text', 11),
('label-printing', 'die', 'Die', 'Text', 12),
('label-printing', 'allocate_mtrs', 'MTRS', 'Number', 13),
('label-printing', 'qty_pcs', 'QTY', 'Number', 14),
('label-printing', 'core_size', 'Core', 'Text', 15),
('label-printing', 'qty_per_roll', 'Qty/Roll', 'Text', 16),
('label-printing', 'roll_direction', 'Direction', 'Text', 17),
('label-printing', 'remarks', 'Remarks', 'Text', 18);

-- End of migration
