-- Artwork Approval System Database Schema

CREATE TABLE IF NOT EXISTS artwork_users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'designer') DEFAULT 'designer',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS artwork_projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    designer_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    job_name VARCHAR(100),
    job_size VARCHAR(100) DEFAULT NULL,
    job_color VARCHAR(100) DEFAULT NULL,
    job_remark TEXT DEFAULT NULL,
    client_name VARCHAR(100) NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    status ENUM('pending', 'changes', 'approved') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (designer_id) REFERENCES artwork_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS artwork_files (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    filename VARCHAR(255) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    version INT DEFAULT 1,
    file_type VARCHAR(50),
    is_final TINYINT(1) DEFAULT 0,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES artwork_projects(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS artwork_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    file_id INT NOT NULL,
    user_name VARCHAR(100) NOT NULL, -- Client name or Designer name
    comment TEXT NOT NULL,
    type VARCHAR(32) DEFAULT 'point',
    x_pos DECIMAL(5,2) DEFAULT NULL,
    y_pos DECIMAL(5,2) DEFAULT NULL,
    width DECIMAL(6,2) DEFAULT NULL,
    height DECIMAL(6,2) DEFAULT NULL,
    drawing_data LONGTEXT DEFAULT NULL,
    attachment VARCHAR(255) DEFAULT NULL,
    parent_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (file_id) REFERENCES artwork_files(id) ON DELETE CASCADE,
    FOREIGN KEY (parent_id) REFERENCES artwork_comments(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS artwork_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES artwork_users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS artwork_activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    action TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES artwork_projects(id) ON DELETE CASCADE
);

-- Sample Data (Password is 'admin123' hashed)
INSERT INTO artwork_users (name, email, password, role) VALUES 
('Admin User', 'admin@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin'),
('Designer One', 'designer@example.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'designer');
