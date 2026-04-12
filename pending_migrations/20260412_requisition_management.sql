-- Requisition Management module schema

CREATE TABLE IF NOT EXISTS requisitions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    department VARCHAR(120) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    category ENUM('Paper','Ink','Plate','Consumable') NOT NULL,
    qty DECIMAL(12,2) NOT NULL,
    unit ENUM('Kg','Nos','Meter') NOT NULL,
    required_date DATE NOT NULL,
    priority ENUM('Normal','Urgent') NOT NULL DEFAULT 'Normal',
    remarks TEXT NULL,
    attachment VARCHAR(255) NULL,
    status ENUM('pending','approved','rejected','po_created') NOT NULL DEFAULT 'pending',
    approved_by INT NULL,
    approved_date DATETIME NULL,
    admin_comment TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_req_user (user_id),
    INDEX idx_req_status (status),
    INDEX idx_req_required_date (required_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS purchase_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT NOT NULL,
    vendor_name VARCHAR(255) NOT NULL,
    rate DECIMAL(12,2) NOT NULL,
    gst DECIMAL(8,2) NOT NULL DEFAULT 0,
    total_amount DECIMAL(12,2) NOT NULL,
    delivery_date DATE NULL,
    payment_terms VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_po_requisition (requisition_id),
    CONSTRAINT fk_po_requisition FOREIGN KEY (requisition_id)
        REFERENCES requisitions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
