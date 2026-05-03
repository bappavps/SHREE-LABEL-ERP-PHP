-- Final artwork file server metadata table
-- Safe to run multiple times.

CREATE TABLE IF NOT EXISTS artwork_final_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NULL,
    legacy_artwork_file_id INT NULL,
    client_name VARCHAR(150) NOT NULL,
    plate_number VARCHAR(100) DEFAULT NULL,
    die_number VARCHAR(100) DEFAULT NULL,
    color_job VARCHAR(100) DEFAULT NULL,
    job_date DATE DEFAULT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) DEFAULT NULL,
    uploaded_by INT NULL,
    uploaded_by_name VARCHAR(120) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_final_stored_name (stored_name),
    UNIQUE KEY uq_final_legacy_file (legacy_artwork_file_id),
    KEY idx_final_project (project_id),
    KEY idx_final_client (client_name),
    KEY idx_final_plate (plate_number),
    KEY idx_final_die (die_number),
    KEY idx_final_color (color_job),
    KEY idx_final_job_date (job_date),
    KEY idx_final_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
