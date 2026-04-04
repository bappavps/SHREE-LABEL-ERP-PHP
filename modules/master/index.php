<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$errors = [];
$settings = getAppSettings();
ensurePaperMasterSchema();
// ============================================================
// Auto-create master tables if they don't exist
// ============================================================
$createTablesSQL = "
CREATE TABLE IF NOT EXISTS master_suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  gst_number VARCHAR(30) DEFAULT NULL,
  contact_person VARCHAR(100) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  city VARCHAR(50) DEFAULT NULL,
  state VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS master_raw_materials (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(100) NOT NULL,
  gsm DECIMAL(6,2) DEFAULT NULL,
  width_mm DECIMAL(8,2) DEFAULT NULL,
  supplier_id INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (supplier_id) REFERENCES master_suppliers(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS master_boms (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bom_name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS master_bom_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bom_id INT NOT NULL,
  raw_material_id INT NOT NULL,
  quantity DECIMAL(10,3) NOT NULL,
  unit VARCHAR(20) DEFAULT 'kg',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (bom_id) REFERENCES master_boms(id) ON DELETE CASCADE,
  FOREIGN KEY (raw_material_id) REFERENCES master_raw_materials(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS master_machines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  type VARCHAR(100) DEFAULT NULL,
  section VARCHAR(100) DEFAULT NULL,
  operator_name VARCHAR(150) DEFAULT NULL,
  status ENUM('Active','Inactive','Maintenance') NOT NULL DEFAULT 'Active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS master_cylinders (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  size_inch DECIMAL(6,2) DEFAULT NULL,
  teeth INT DEFAULT NULL,
  material_type VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS master_clients (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  contact_person VARCHAR(100) DEFAULT NULL,
  phone VARCHAR(20) DEFAULT NULL,
  email VARCHAR(150) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  credit_period_days INT DEFAULT 0,
  credit_limit DECIMAL(12,2) DEFAULT 0,
  city VARCHAR(50) DEFAULT NULL,
  state VARCHAR(50) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
";

$tableStatements = array_filter(array_map('trim', explode(';', $createTablesSQL)));
foreach ($tableStatements as $stmt) {
  if ($stmt !== '') {
    @$db->query($stmt);
  }
}

if (!function_exists('masterColumnExists')) {
  function masterColumnExists($db, $table, $column) {
    $sql = "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    return (bool)$result->fetch_row();
  }
}

if (!masterColumnExists($db, 'master_suppliers', 'gst_number')) {
  @$db->query("ALTER TABLE master_suppliers ADD COLUMN gst_number VARCHAR(30) DEFAULT NULL AFTER name");
}
if (!masterColumnExists($db, 'master_suppliers', 'notes')) {
  @$db->query("ALTER TABLE master_suppliers ADD COLUMN notes TEXT DEFAULT NULL AFTER address");
}
if (!masterColumnExists($db, 'master_clients', 'credit_period_days')) {
  @$db->query("ALTER TABLE master_clients ADD COLUMN credit_period_days INT DEFAULT 0 AFTER address");
}
if (!masterColumnExists($db, 'master_clients', 'credit_limit')) {
  @$db->query("ALTER TABLE master_clients ADD COLUMN credit_limit DECIMAL(12,2) DEFAULT 0 AFTER credit_period_days");
}
if (!masterColumnExists($db, 'master_machines', 'operator_name')) {
  @$db->query("ALTER TABLE master_machines ADD COLUMN operator_name VARCHAR(150) DEFAULT NULL AFTER section");
}


// ============================================================
// HELPER FUNCTION: CRUD utilities
// ============================================================
function getMasterRecords($db, $table, $orderBy = 'created_at DESC') {
  $query = "SELECT * FROM $table ORDER BY $orderBy";
  $result = $db->query($query);
  return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

function getSupplierName($db, $supplierId) {
  if (!$supplierId) return '';
  $stmt = $db->prepare("SELECT name FROM master_suppliers WHERE id = ?");
  $stmt->bind_param('i', $supplierId);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  return $result ? $result['name'] : '';
}

function getRawMaterialName($db, $materialId) {
  if (!$materialId) return '';
  $stmt = $db->prepare("SELECT name FROM master_raw_materials WHERE id = ?");
  $stmt->bind_param('i', $materialId);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  return $result ? $result['name'] : '';
}

function getById($db, $table, $id) {
  $id = (int)$id;
  if ($id <= 0) return null;
  $stmt = $db->prepare("SELECT * FROM $table WHERE id = ? LIMIT 1");
  if (!$stmt) return null;
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  return $row ?: null;
}

// ============================================================
// TAB & CSRF SETUP
// ============================================================
$activeTab = $_GET['tab'] ?? 'raw_materials';
$allowedTabs = ['raw_materials', 'bom', 'suppliers', 'machines', 'cylinders', 'clients', 'paper_masters', 'prefix'];
$isSystemAdmin = isAdmin();
if (!in_array($activeTab, $allowedTabs, true)) $activeTab = 'raw_materials';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token mismatch. Please retry.');
    redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($activeTab));
  }

  $action = $_POST['action'] ?? '';

  // ============================================================
  // SUPPLIERS CRUD
  // ============================================================
  if ($action === 'add_supplier') {
    $name = trim((string)($_POST['supplier_name'] ?? ''));
    $gst_number = strtoupper(trim((string)($_POST['supplier_gst_number'] ?? '')));
    $contact_person = trim((string)($_POST['supplier_contact_person'] ?? ''));
    $phone = trim((string)($_POST['supplier_phone'] ?? ''));
    $email = trim((string)($_POST['supplier_email'] ?? ''));
    $address = trim((string)($_POST['supplier_address'] ?? ''));
    $notes = trim((string)($_POST['supplier_notes'] ?? ''));
    $city = trim((string)($_POST['supplier_city'] ?? ''));
    $state = trim((string)($_POST['supplier_state'] ?? ''));

    if ($name === '') {
      setFlash('error', 'Supplier name is required.');
    } else {
      $stmt = $db->prepare("INSERT INTO master_suppliers (name, gst_number, contact_person, phone, email, address, notes, city, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param('sssssssss', $name, $gst_number, $contact_person, $phone, $email, $address, $notes, $city, $state);
      if ($stmt->execute()) {
        setFlash('success', 'Supplier added successfully.');
      } else {
        setFlash('error', 'Error adding supplier: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=suppliers');
  }

  if ($action === 'edit_supplier') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    $name = trim((string)($_POST['supplier_name'] ?? ''));
    $gst_number = strtoupper(trim((string)($_POST['supplier_gst_number'] ?? '')));
    $contact_person = trim((string)($_POST['supplier_contact_person'] ?? ''));
    $phone = trim((string)($_POST['supplier_phone'] ?? ''));
    $email = trim((string)($_POST['supplier_email'] ?? ''));
    $address = trim((string)($_POST['supplier_address'] ?? ''));
    $notes = trim((string)($_POST['supplier_notes'] ?? ''));
    $city = trim((string)($_POST['supplier_city'] ?? ''));
    $state = trim((string)($_POST['supplier_state'] ?? ''));

    if ($id <= 0 || $name === '') {
      setFlash('error', 'Invalid data.');
    } else {
      $stmt = $db->prepare("UPDATE master_suppliers SET name=?, gst_number=?, contact_person=?, phone=?, email=?, address=?, notes=?, city=?, state=? WHERE id=?");
      $stmt->bind_param('sssssssssi', $name, $gst_number, $contact_person, $phone, $email, $address, $notes, $city, $state, $id);
      if ($stmt->execute()) {
        setFlash('success', 'Supplier updated successfully.');
      } else {
        setFlash('error', 'Error updating supplier: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=suppliers');
  }

  if ($action === 'delete_supplier') {
    $id = (int)($_POST['supplier_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM master_suppliers WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'Supplier deleted successfully.');
      } else {
        setFlash('error', 'Cannot delete supplier: may be in use.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=suppliers');
  }

  // ============================================================
  // RAW MATERIALS CRUD
  // ============================================================
  if ($action === 'add_raw_material') {
    $name = trim((string)($_POST['rm_name'] ?? ''));
    $type = trim((string)($_POST['rm_type'] ?? ''));
    $gsm = trim((string)($_POST['rm_gsm'] ?? ''));
    $width_mm = trim((string)($_POST['rm_width'] ?? ''));
    $supplier_id = (int)($_POST['rm_supplier_id'] ?? 0);

    if ($name === '' || $type === '') {
      setFlash('error', 'Name and type are required.');
    } else {
      $gsm_val = $gsm ? (float)$gsm : null;
      $width_val = $width_mm ? (float)$width_mm : null;
      $supplier_val = $supplier_id > 0 ? $supplier_id : null;
      
      $stmt = $db->prepare("INSERT INTO master_raw_materials (name, type, gsm, width_mm, supplier_id) VALUES (?, ?, ?, ?, ?)");
      $stmt->bind_param('ssddi', $name, $type, $gsm_val, $width_val, $supplier_val);
      if ($stmt->execute()) {
        setFlash('success', 'Raw material added successfully.');
      } else {
        setFlash('error', 'Error adding raw material: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=raw_materials');
  }

  if ($action === 'edit_raw_material') {
    $id = (int)($_POST['rm_id'] ?? 0);
    $name = trim((string)($_POST['rm_name'] ?? ''));
    $type = trim((string)($_POST['rm_type'] ?? ''));
    $gsm = trim((string)($_POST['rm_gsm'] ?? ''));
    $width_mm = trim((string)($_POST['rm_width'] ?? ''));
    $supplier_id = (int)($_POST['rm_supplier_id'] ?? 0);

    if ($id <= 0 || $name === '' || $type === '') {
      setFlash('error', 'Invalid data.');
    } else {
      $gsm_val = $gsm ? (float)$gsm : null;
      $width_val = $width_mm ? (float)$width_mm : null;
      $supplier_val = $supplier_id > 0 ? $supplier_id : null;
      
      $stmt = $db->prepare("UPDATE master_raw_materials SET name=?, type=?, gsm=?, width_mm=?, supplier_id=? WHERE id=?");
      $stmt->bind_param('ssddii', $name, $type, $gsm_val, $width_val, $supplier_val, $id);
      if ($stmt->execute()) {
        setFlash('success', 'Raw material updated successfully.');
      } else {
        setFlash('error', 'Error updating raw material: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=raw_materials');
  }

  if ($action === 'delete_raw_material') {
    $id = (int)($_POST['rm_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM master_raw_materials WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'Raw material deleted successfully.');
      } else {
        setFlash('error', 'Cannot delete material: may be in use.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=raw_materials');
  }

  // ============================================================
  // MACHINES CRUD
  // ============================================================
  if ($action === 'add_machine') {
    $name = trim((string)($_POST['machine_name'] ?? ''));
    $type = trim((string)($_POST['machine_type'] ?? ''));
    $section = erp_normalize_department_selection(
      $_POST['machine_section'] ?? '',
      erp_get_machine_departments($db),
      []
    );
    $operatorName = trim((string)($_POST['machine_operator_name'] ?? ''));
    $status = trim((string)($_POST['machine_status'] ?? 'Active'));

    if ($name === '') {
      setFlash('error', 'Machine name is required.');
    } else {
      $stmt = $db->prepare("INSERT INTO master_machines (name, type, section, operator_name, status) VALUES (?, ?, ?, ?, ?)");
      $stmt->bind_param('sssss', $name, $type, $section, $operatorName, $status);
      if ($stmt->execute()) {
        setFlash('success', 'Machine added successfully.');
      } else {
        setFlash('error', 'Error adding machine: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=machines');
  }

  if ($action === 'edit_machine') {
    $id = (int)($_POST['machine_id'] ?? 0);
    $name = trim((string)($_POST['machine_name'] ?? ''));
    $type = trim((string)($_POST['machine_type'] ?? ''));
    $section = erp_normalize_department_selection(
      $_POST['machine_section'] ?? '',
      erp_get_machine_departments($db),
      []
    );
    $operatorName = trim((string)($_POST['machine_operator_name'] ?? ''));
    $status = trim((string)($_POST['machine_status'] ?? 'Active'));

    if ($id <= 0 || $name === '') {
      setFlash('error', 'Invalid data.');
    } else {
      $stmt = $db->prepare("UPDATE master_machines SET name=?, type=?, section=?, operator_name=?, status=? WHERE id=?");
      $stmt->bind_param('sssssi', $name, $type, $section, $operatorName, $status, $id);
      if ($stmt->execute()) {
        setFlash('success', 'Machine updated successfully.');
      } else {
        setFlash('error', 'Error updating machine: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=machines');
  }

  if ($action === 'delete_machine') {
    $id = (int)($_POST['machine_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM master_machines WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'Machine deleted successfully.');
      } else {
        setFlash('error', 'Cannot delete machine: may be in use.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=machines');
  }

  // ============================================================
  // CYLINDERS CRUD
  // ============================================================
  if ($action === 'add_cylinder') {
    $name = trim((string)($_POST['cylinder_name'] ?? ''));
    $size_inch = trim((string)($_POST['cylinder_size'] ?? ''));
    $teeth = trim((string)($_POST['cylinder_teeth'] ?? ''));
    $material_type = trim((string)($_POST['cylinder_material'] ?? ''));

    if ($name === '') {
      setFlash('error', 'Cylinder name is required.');
    } else {
      $size_val = $size_inch ? (float)$size_inch : null;
      $teeth_val = $teeth ? (int)$teeth : null;
      
      $stmt = $db->prepare("INSERT INTO master_cylinders (name, size_inch, teeth, material_type) VALUES (?, ?, ?, ?)");
      $stmt->bind_param('sdis', $name, $size_val, $teeth_val, $material_type);
      if ($stmt->execute()) {
        setFlash('success', 'Cylinder added successfully.');
      } else {
        setFlash('error', 'Error adding cylinder: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=cylinders');
  }

  if ($action === 'edit_cylinder') {
    $id = (int)($_POST['cylinder_id'] ?? 0);
    $name = trim((string)($_POST['cylinder_name'] ?? ''));
    $size_inch = trim((string)($_POST['cylinder_size'] ?? ''));
    $teeth = trim((string)($_POST['cylinder_teeth'] ?? ''));
    $material_type = trim((string)($_POST['cylinder_material'] ?? ''));

    if ($id <= 0 || $name === '') {
      setFlash('error', 'Invalid data.');
    } else {
      $size_val = $size_inch ? (float)$size_inch : null;
      $teeth_val = $teeth ? (int)$teeth : null;
      
      $stmt = $db->prepare("UPDATE master_cylinders SET name=?, size_inch=?, teeth=?, material_type=? WHERE id=?");
      $stmt->bind_param('sdisi', $name, $size_val, $teeth_val, $material_type, $id);
      if ($stmt->execute()) {
        setFlash('success', 'Cylinder updated successfully.');
      } else {
        setFlash('error', 'Error updating cylinder: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=cylinders');
  }

  if ($action === 'delete_cylinder') {
    $id = (int)($_POST['cylinder_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM master_cylinders WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'Cylinder deleted successfully.');
      } else {
        setFlash('error', 'Cannot delete cylinder: may be in use.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=cylinders');
  }

  // ============================================================
  // CLIENTS CRUD
  // ============================================================
  if ($action === 'add_client') {
    $name = trim((string)($_POST['client_name'] ?? ''));
    $contact_person = trim((string)($_POST['client_contact_person'] ?? ''));
    $phone = trim((string)($_POST['client_phone'] ?? ''));
    $email = trim((string)($_POST['client_email'] ?? ''));
    $address = trim((string)($_POST['client_address'] ?? ''));
    $credit_period_days = (int)($_POST['client_credit_period_days'] ?? 0);
    $credit_limit = (float)($_POST['client_credit_limit'] ?? 0);
    $city = trim((string)($_POST['client_city'] ?? ''));
    $state = trim((string)($_POST['client_state'] ?? ''));

    if ($name === '' || $credit_period_days < 0 || $credit_limit < 0) {
      setFlash('error', 'Client name, credit period and credit limit are required.');
    } else {
      $stmt = $db->prepare("INSERT INTO master_clients (name, contact_person, phone, email, address, credit_period_days, credit_limit, city, state) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param('sssssddss', $name, $contact_person, $phone, $email, $address, $credit_period_days, $credit_limit, $city, $state);
      if ($stmt->execute()) {
        setFlash('success', 'Client added successfully.');
      } else {
        setFlash('error', 'Error adding client: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=clients');
  }

  if ($action === 'edit_client') {
    $id = (int)($_POST['client_id'] ?? 0);
    $name = trim((string)($_POST['client_name'] ?? ''));
    $contact_person = trim((string)($_POST['client_contact_person'] ?? ''));
    $phone = trim((string)($_POST['client_phone'] ?? ''));
    $email = trim((string)($_POST['client_email'] ?? ''));
    $address = trim((string)($_POST['client_address'] ?? ''));
    $credit_period_days = (int)($_POST['client_credit_period_days'] ?? 0);
    $credit_limit = (float)($_POST['client_credit_limit'] ?? 0);
    $city = trim((string)($_POST['client_city'] ?? ''));
    $state = trim((string)($_POST['client_state'] ?? ''));

    if ($id <= 0 || $name === '' || $credit_period_days < 0 || $credit_limit < 0) {
      setFlash('error', 'Invalid data.');
    } else {
      $stmt = $db->prepare("UPDATE master_clients SET name=?, contact_person=?, phone=?, email=?, address=?, credit_period_days=?, credit_limit=?, city=?, state=? WHERE id=?");
      $stmt->bind_param('sssssddssi', $name, $contact_person, $phone, $email, $address, $credit_period_days, $credit_limit, $city, $state, $id);
      if ($stmt->execute()) {
        setFlash('success', 'Client updated successfully.');
      } else {
        setFlash('error', 'Error updating client: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=clients');
  }

  if ($action === 'delete_client') {
    $id = (int)($_POST['client_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM master_clients WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'Client deleted successfully.');
      } else {
        setFlash('error', 'Cannot delete client: may be in use.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=clients');
  }

  // ============================================================
  // PAPER COMPANIES CRUD
  // ============================================================
  if ($action === 'add_paper_company') {
    if (!$isSystemAdmin) {
      setFlash('error', 'Only system admin can manage paper companies.');
      redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
    }

    $name = trim((string)($_POST['paper_company_name'] ?? ''));
    $isActive = isset($_POST['paper_company_is_active']) ? 1 : 0;

    if ($name === '') {
      setFlash('error', 'Paper company name is required.');
    } else {
      $stmt = $db->prepare("INSERT INTO master_paper_companies (name, is_active) VALUES (?, ?)");
      $stmt->bind_param('si', $name, $isActive);
      if ($stmt->execute()) {
        setFlash('success', 'Paper company added successfully.');
      } else {
        setFlash('error', 'Error adding paper company. Name may already exist.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
  }

  if ($action === 'edit_paper_company') {
    if (!$isSystemAdmin) {
      setFlash('error', 'Only system admin can manage paper companies.');
      redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
    }

    $id = (int)($_POST['paper_company_id'] ?? 0);
    $name = trim((string)($_POST['paper_company_name'] ?? ''));
    $isActive = isset($_POST['paper_company_is_active']) ? 1 : 0;

    if ($id <= 0 || $name === '') {
      setFlash('error', 'Invalid paper company data.');
    } else {
      $stmt = $db->prepare("UPDATE master_paper_companies SET name=?, is_active=? WHERE id=?");
      $stmt->bind_param('sii', $name, $isActive, $id);
      if ($stmt->execute()) {
        setFlash('success', 'Paper company updated successfully.');
      } else {
        setFlash('error', 'Error updating paper company. Name may already exist.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
  }

  if ($action === 'delete_paper_company') {
    if (!$isSystemAdmin) {
      setFlash('error', 'Only system admin can manage paper companies.');
      redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
    }

    $id = (int)($_POST['paper_company_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM master_paper_companies WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'Paper company deleted successfully.');
      } else {
        setFlash('error', 'Unable to delete paper company.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
  }

  // ============================================================
  // PAPER TYPES CRUD
  // ============================================================
  if ($action === 'add_paper_type') {
    if (!$isSystemAdmin) {
      setFlash('error', 'Only system admin can manage paper types.');
      redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
    }

    $name = trim((string)($_POST['paper_type_name'] ?? ''));
    $isActive = isset($_POST['paper_type_is_active']) ? 1 : 0;

    if ($name === '') {
      setFlash('error', 'Paper type name is required.');
    } else {
      $stmt = $db->prepare("INSERT INTO master_paper_types (name, is_active) VALUES (?, ?)");
      $stmt->bind_param('si', $name, $isActive);
      if ($stmt->execute()) {
        setFlash('success', 'Paper type added successfully.');
      } else {
        setFlash('error', 'Error adding paper type. Name may already exist.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
  }

  if ($action === 'edit_paper_type') {
    if (!$isSystemAdmin) {
      setFlash('error', 'Only system admin can manage paper types.');
      redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
    }

    $id = (int)($_POST['paper_type_id'] ?? 0);
    $name = trim((string)($_POST['paper_type_name'] ?? ''));
    $isActive = isset($_POST['paper_type_is_active']) ? 1 : 0;

    if ($id <= 0 || $name === '') {
      setFlash('error', 'Invalid paper type data.');
    } else {
      $stmt = $db->prepare("UPDATE master_paper_types SET name=?, is_active=? WHERE id=?");
      $stmt->bind_param('sii', $name, $isActive, $id);
      if ($stmt->execute()) {
        setFlash('success', 'Paper type updated successfully.');
      } else {
        setFlash('error', 'Error updating paper type. Name may already exist.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
  }

  if ($action === 'delete_paper_type') {
    if (!$isSystemAdmin) {
      setFlash('error', 'Only system admin can manage paper types.');
      redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
    }

    $id = (int)($_POST['paper_type_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM master_paper_types WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'Paper type deleted successfully.');
      } else {
        setFlash('error', 'Unable to delete paper type.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=paper_masters');
  }

  // ============================================================
  // BOMs CRUD
  // ============================================================
  if ($action === 'add_bom') {
    $bom_name = trim((string)($_POST['bom_name'] ?? ''));
    $description = trim((string)($_POST['bom_description'] ?? ''));
    $status = trim((string)($_POST['bom_status'] ?? 'Active'));

    if ($bom_name === '') {
      setFlash('error', 'BOM name is required.');
    } else {
      $stmt = $db->prepare("INSERT INTO master_boms (bom_name, description, status) VALUES (?, ?, ?)");
      $stmt->bind_param('sss', $bom_name, $description, $status);
      if ($stmt->execute()) {
        setFlash('success', 'BOM created successfully.');
      } else {
        setFlash('error', 'Error creating BOM: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=bom');
  }

  if ($action === 'edit_bom') {
    $id = (int)($_POST['bom_id'] ?? 0);
    $bom_name = trim((string)($_POST['bom_name'] ?? ''));
    $description = trim((string)($_POST['bom_description'] ?? ''));
    $status = trim((string)($_POST['bom_status'] ?? 'Active'));

    if ($id <= 0 || $bom_name === '') {
      setFlash('error', 'Invalid data.');
    } else {
      $stmt = $db->prepare("UPDATE master_boms SET bom_name=?, description=?, status=? WHERE id=?");
      $stmt->bind_param('sssi', $bom_name, $description, $status, $id);
      if ($stmt->execute()) {
        setFlash('success', 'BOM updated successfully.');
      } else {
        setFlash('error', 'Error updating BOM: ' . $db->error);
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=bom');
  }

  if ($action === 'delete_bom') {
    $id = (int)($_POST['bom_id'] ?? 0);
    if ($id > 0) {
      $db->query("DELETE FROM master_bom_items WHERE bom_id = $id");
      $stmt = $db->prepare("DELETE FROM master_boms WHERE id = ?");
      $stmt->bind_param('i', $id);
      if ($stmt->execute()) {
        setFlash('success', 'BOM deleted successfully.');
      } else {
        setFlash('error', 'Cannot delete BOM.');
      }
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=bom');
  }

  if ($action === 'save_prefix') {
    $idg = getPrefixSettings();

    $yearFormat = strtoupper(trim((string)($_POST['prefix_year_format'] ?? 'YY')));
    if (!in_array($yearFormat, ['YY', 'YYYY'], true)) {
      $yearFormat = 'YY';
    }

    $separator = trim((string)($_POST['prefix_separator'] ?? '/'));
    if ($separator === '') $separator = '/';
    if (strlen($separator) > 4) $separator = substr($separator, 0, 4);

    $padding = (int)($_POST['prefix_padding'] ?? 3);
    if ($padding < 1) $padding = 1;
    if ($padding > 8) $padding = 8;

    $moduleFieldMap = [
      'roll' => 'prefix_roll',
      'job' => 'prefix_job',
      'invoice' => 'prefix_invoice',
      'estimate' => 'prefix_estimate',
      'quotation' => 'prefix_quotation',
      'batch' => 'prefix_batch',
      'sales_order' => 'prefix_sales_order',
      'planning' => 'prefix_planning',
      'jumbo_job' => 'prefix_jumbo_job',
      'printing_job' => 'prefix_printing_job',
    ];

    foreach ($moduleFieldMap as $type => $field) {
      $newPrefix = strtoupper(trim((string)($_POST[$field] ?? '')));
      if ($newPrefix === '') {
        $newPrefix = (string)($idg['modules'][$type]['prefix'] ?? '');
      }
      $idg['modules'][$type]['prefix'] = $newPrefix;
    }

    $idg['year_format'] = $yearFormat;
    $idg['separator'] = $separator;
    $idg['padding'] = $padding;

    $settings['id_generation'] = $idg;
    if (saveAppSettings($settings)) {
      setFlash('success', 'Prefix settings saved successfully. New records will use updated format.');
    } else {
      setFlash('error', 'Unable to save prefix settings.');
    }
    redirect(BASE_URL . '/modules/master/index.php?tab=prefix');
  }

  if ($action === 'reset_prefix_counters') {
    $idg = getPrefixSettings();

    if (!isset($idg['modules']) || !is_array($idg['modules'])) {
      $idg['modules'] = [];
    }

    foreach ($idg['modules'] as $type => $moduleCfg) {
      $idg['modules'][$type]['counter'] = 0;
    }

    $idg['global_job_counter'] = 0;

    $settings['id_generation'] = $idg;
    if (saveAppSettings($settings)) {
      setFlash('success', 'All prefix counters reset successfully. Next IDs will start from 0001.');
    } else {
      setFlash('error', 'Unable to reset prefix counters.');
    }

    redirect(BASE_URL . '/modules/master/index.php?tab=prefix');
  }

  // ============================================================
  // RESET TEST DATA — planning, jumbo, flexo counters + rows
  // ============================================================
  if ($action === 'reset_test_data') {
    $errors = [];

    // 1. Reset only planning, jumbo_job and printing_job prefix counters
    $idg = getPrefixSettings();
    if (!isset($idg['modules']) || !is_array($idg['modules'])) {
      $idg['modules'] = [];
    }
    foreach (['planning', 'jumbo_job', 'printing_job'] as $type) {
      if (isset($idg['modules'][$type])) {
        $idg['modules'][$type]['counter'] = 0;
      }
    }
    $settings['id_generation'] = $idg;
    if (!saveAppSettings($settings)) {
      $errors[] = 'Could not reset prefix counters.';
    }

    // 2. Delete database rows — jobs (jumbo + printing job cards), planning rows,
    //    slitting batches and entries (all test slitting output)
    try {
      // Disable FK checks temporarily so truncates succeed regardless of references
      $db->query("SET FOREIGN_KEY_CHECKS=0");
      $db->query("DELETE FROM slitting_entries");
      $db->query("DELETE FROM slitting_batches");
      $db->query("DELETE FROM job_change_requests");
      $db->query("DELETE FROM jobs");
      $db->query("DELETE FROM planning");
      $db->query("SET FOREIGN_KEY_CHECKS=1");
    } catch (Exception $e) {
      $errors[] = 'DB error: ' . $e->getMessage();
    }

    if (empty($errors)) {
      setFlash('success', 'Test data reset: planning board, jumbo job cards, flexo job cards and slitting batches cleared. Counters restarted from 0001.');
    } else {
      setFlash('error', 'Reset partially failed: ' . implode(' ', $errors));
    }

    redirect(BASE_URL . '/modules/master/index.php?tab=prefix');
  }

  // ============================================================
  // CSV UPLOAD HANDLER
  // ============================================================
  if ($action === 'upload_master_data') {
    $uploadTab = trim((string)($_POST['upload_tab'] ?? ''));
    $allowedUploadTabs = ['raw_materials', 'suppliers', 'clients', 'machines', 'cylinders', 'bom'];
    if (!in_array($uploadTab, $allowedUploadTabs, true)) {
      setFlash('error', 'Invalid upload target.');
      redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($activeTab));
    }

    if (!isset($_FILES['upload_file']) || $_FILES['upload_file']['error'] !== UPLOAD_ERR_OK) {
      setFlash('error', 'Please select a valid CSV file to upload.');
      redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($uploadTab));
    }

    $file = $_FILES['upload_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
      setFlash('error', 'Only CSV files are supported. Please save your Excel file as CSV first.');
      redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($uploadTab));
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
      setFlash('error', 'Could not read uploaded file.');
      redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($uploadTab));
    }

    // Read header row
    $header = fgetcsv($handle);
    if (!$header) {
      fclose($handle);
      setFlash('error', 'CSV file is empty or has no header row.');
      redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($uploadTab));
    }
    $header = array_map(function($h) { return strtolower(trim($h)); }, $header);

    // Column mappings per tab
    $columnMaps = [
      'suppliers' => [
        'required' => ['name'],
        'columns' => ['name'=>'name', 'gst number'=>'gst_number', 'gst'=>'gst_number', 'gst_number'=>'gst_number', 'contact person'=>'contact_person', 'contact'=>'contact_person', 'contact_person'=>'contact_person', 'phone'=>'phone', 'email'=>'email', 'address'=>'address', 'notes'=>'notes', 'city'=>'city', 'state'=>'state']
      ],
      'clients' => [
        'required' => ['name'],
        'columns' => ['name'=>'name', 'contact person'=>'contact_person', 'contact'=>'contact_person', 'contact_person'=>'contact_person', 'phone'=>'phone', 'email'=>'email', 'address'=>'address', 'credit period'=>'credit_period_days', 'credit_period_days'=>'credit_period_days', 'credit period days'=>'credit_period_days', 'credit limit'=>'credit_limit', 'credit_limit'=>'credit_limit', 'city'=>'city', 'state'=>'state']
      ],
      'raw_materials' => [
        'required' => ['name', 'type'],
        'columns' => ['name'=>'name', 'type'=>'type', 'gsm'=>'gsm', 'width'=>'width_mm', 'width_mm'=>'width_mm', 'width mm'=>'width_mm']
      ],
      'machines' => [
        'required' => ['name'],
        'columns' => ['name'=>'name', 'type'=>'type', 'section'=>'section', 'operator name'=>'operator_name', 'operator_name'=>'operator_name', 'operator'=>'operator_name', 'status'=>'status']
      ],
      'cylinders' => [
        'required' => ['name'],
        'columns' => ['name'=>'name', 'size'=>'size_inch', 'size_inch'=>'size_inch', 'size inch'=>'size_inch', 'teeth'=>'teeth', 'material type'=>'material_type', 'material_type'=>'material_type', 'material'=>'material_type']
      ],
      'bom' => [
        'required' => ['bom_name'],
        'columns' => ['bom name'=>'bom_name', 'bom_name'=>'bom_name', 'name'=>'bom_name', 'description'=>'description', 'status'=>'status']
      ]
    ];

    $map = $columnMaps[$uploadTab];
    // Map header indices to DB column names
    $colIndex = [];
    foreach ($header as $idx => $h) {
      if (isset($map['columns'][$h])) {
        $colIndex[$idx] = $map['columns'][$h];
      }
    }

    // Check required columns are present
    $mappedCols = array_values($colIndex);
    foreach ($map['required'] as $req) {
      if (!in_array($req, $mappedCols)) {
        fclose($handle);
        setFlash('error', 'Missing required column: ' . $req . '. Found columns: ' . implode(', ', $header));
        redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($uploadTab));
      }
    }

    $tableMap = [
      'suppliers' => 'master_suppliers',
      'clients' => 'master_clients',
      'raw_materials' => 'master_raw_materials',
      'machines' => 'master_machines',
      'cylinders' => 'master_cylinders',
      'bom' => 'master_boms'
    ];
    $table = $tableMap[$uploadTab];

    $success = 0;
    $skipped = 0;
    $rowNum = 1;
    while (($row = fgetcsv($handle)) !== false) {
      $rowNum++;
      if (count($row) < count($header)) {
        $skipped++;
        continue;
      }

      $data = [];
      foreach ($colIndex as $idx => $dbCol) {
        $data[$dbCol] = trim((string)($row[$idx] ?? ''));
      }

      // Check required fields have values
      $valid = true;
      foreach ($map['required'] as $req) {
        if (empty($data[$req])) {
          $valid = false;
          break;
        }
      }
      if (!$valid) {
        $skipped++;
        continue;
      }

      // Build INSERT
      $cols = array_keys($data);
      $placeholders = array_fill(0, count($cols), '?');
      $sql = "INSERT INTO $table (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
      $stmt = $db->prepare($sql);
      if ($stmt) {
        $types = str_repeat('s', count($cols));
        $values = array_values($data);
        $stmt->bind_param($types, ...$values);
        if ($stmt->execute()) {
          $success++;
        } else {
          $skipped++;
        }
      } else {
        $skipped++;
      }
    }
    fclose($handle);

    setFlash('success', "Upload complete: $success rows imported, $skipped rows skipped.");
    redirect(BASE_URL . '/modules/master/index.php?tab=' . urlencode($uploadTab));
  }
}

$csrf = generateCSRF();
$pageTitle = 'Master Control Panel';
include __DIR__ . '/../../includes/header.php';

$idg = getPrefixSettings();
$prefixModules = [
  'roll' => 'Roll Prefix',
  'job' => 'Job Prefix',
  'invoice' => 'Invoice Prefix',
  'estimate' => 'Estimate Prefix',
  'quotation' => 'Quotation Prefix',
  'batch' => 'Batch Prefix',
  'sales_order' => 'Sales Order Prefix',
  'planning' => 'Planning Job Prefix',
  'jumbo_job' => 'Jumbo Slitting Job Prefix',
  'printing_job' => 'Flexo Printing Job Prefix',
];

$sampleSuppliers = [
  ['name' => 'STP', 'gst_number' => '-', 'contact_person' => '-', 'address' => '-', 'notes' => '-', 'phone' => '-', 'email' => '-', 'city' => '-'],
  ['name' => 'NITIN', 'gst_number' => '-', 'contact_person' => '-', 'address' => '-', 'notes' => '-', 'phone' => '-', 'email' => '-', 'city' => '-'],
];

$sampleMachines = [
  ['name' => 'Label Slitting', 'type' => 'Slitting', 'section' => 'Production', 'operator_name' => 'Operator 1', 'status' => 'Active'],
  ['name' => 'Printing Machine', 'type' => 'Printing', 'section' => 'Production', 'operator_name' => 'Operator 2', 'status' => 'Active'],
];

$sampleCylinders = [
  ['name' => 'Cyl 508mm (60T)', 'size_inch' => '-', 'teeth' => '-', 'material_type' => '-'],
  ['name' => 'Cyl 406mm (48T)', 'size_inch' => '-', 'teeth' => '-', 'material_type' => '-'],
];

$sampleClients = [
  ['name' => 'ABC Pharma', 'contact_person' => '-', 'phone' => '-', 'email' => '-', 'city' => '-', 'credit_period_days' => 30, 'credit_limit' => 100000],
  ['name' => 'XYZ Packaging', 'contact_person' => '-', 'phone' => '-', 'email' => '-', 'city' => '-', 'credit_period_days' => 45, 'credit_limit' => 150000],
];

$sampleRawMaterials = [
  ['name' => 'Chromo Paper', 'type' => 'Paper', 'gsm' => '80', 'width_mm' => '1000', 'supplier_name' => 'STP'],
  ['name' => 'PP White', 'type' => 'Film', 'gsm' => '60', 'width_mm' => '900', 'supplier_name' => 'NITIN'],
];

$sampleBoms = [
  ['bom_name' => 'Sample BOM', 'description' => 'Chromo Paper (2.500 kg), Ink Base (0.300 kg), Adhesive (0.200 kg)', 'status' => 'Active', 'created_at' => date('Y-m-d H:i:s')],
];

// Load data for active tab
$suppliers = [];
$raw_materials = [];
$machines = [];
$machineDepartments = erp_get_machine_departments($db);
$cylinders = [];
$clients = [];
$paperCompanies = [];
$paperTypes = [];
$stockCompanies = [];
$stockTypes = [];
$boms = [];

if ($activeTab === 'suppliers' || $activeTab === 'raw_materials') {
  $suppliers = getMasterRecords($db, 'master_suppliers', 'created_at DESC');
}
if ($activeTab === 'raw_materials') {
  $raw_materials = getMasterRecords($db, 'master_raw_materials', 'created_at DESC');
}
if ($activeTab === 'machines') {
  $machines = getMasterRecords($db, 'master_machines', 'created_at DESC');
}
if ($activeTab === 'cylinders') {
  $cylinders = getMasterRecords($db, 'master_cylinders', 'created_at DESC');
}
if ($activeTab === 'clients') {
  $clients = getMasterRecords($db, 'master_clients', 'created_at DESC');
}
if ($activeTab === 'paper_masters') {
  ensurePaperMasterSchema();
  $paperCompanies = getMasterRecords($db, 'master_paper_companies', 'name ASC');
  $paperTypes     = getMasterRecords($db, 'master_paper_types',     'name ASC');
  // Existing values already saved in paper_stock (read-only reference)
  $stockCompanies = [];
  $res = $db->query("SELECT DISTINCT company FROM paper_stock WHERE company IS NOT NULL AND TRIM(company)<>'' ORDER BY company");
  if ($res) { while ($r = $res->fetch_assoc()) $stockCompanies[] = $r['company']; }
  $stockTypes = [];
  $res = $db->query("SELECT DISTINCT paper_type FROM paper_stock WHERE paper_type IS NOT NULL AND TRIM(paper_type)<>'' ORDER BY paper_type");
  if ($res) { while ($r = $res->fetch_assoc()) $stockTypes[] = $r['paper_type']; }
}
if ($activeTab === 'bom') {
  $boms = getMasterRecords($db, 'master_boms', 'created_at DESC');
}

$suppliersData = !empty($suppliers) ? $suppliers : $sampleSuppliers;
$clientsData = !empty($clients) ? $clients : $sampleClients;
$hasSuppliersData = !empty($suppliers);
$hasClientsData = !empty($clients);

$editSupplier = null;
$editClient = null;
$editSupplierId = (int)($_GET['edit_supplier_id'] ?? 0);
$editClientId = (int)($_GET['edit_client_id'] ?? 0);
if ($activeTab === 'suppliers' && $editSupplierId > 0) {
  $editSupplier = getById($db, 'master_suppliers', $editSupplierId);
}
if ($activeTab === 'clients' && $editClientId > 0) {
  $editClient = getById($db, 'master_clients', $editClientId);
}
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Master</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Master Control Panel</span>
</div>

<div class="page-header">
  <div>
    <h1>Master Control Panel</h1>
    <p>Centralized management for core ERP master entities and number prefixes.</p>
  </div>
</div>

<div class="card settings-card settings-modern">
  <div class="settings-tabs" role="tablist" aria-label="Master Data Tabs">
    <a class="settings-tab <?= $activeTab==='raw_materials'?'active':'' ?>" href="?tab=raw_materials">Raw Materials</a>
    <a class="settings-tab <?= $activeTab==='bom'?'active':'' ?>" href="?tab=bom">BOM Master</a>
    <a class="settings-tab <?= $activeTab==='suppliers'?'active':'' ?>" href="?tab=suppliers">Suppliers</a>
    <a class="settings-tab <?= $activeTab==='machines'?'active':'' ?>" href="?tab=machines">Machines</a>
    <a class="settings-tab <?= $activeTab==='cylinders'?'active':'' ?>" href="?tab=cylinders">Cylinders</a>
    <a class="settings-tab" href="<?= BASE_URL ?>/modules/master/cylinder-data.php">Cylinder Data</a>
    <a class="settings-tab <?= $activeTab==='clients'?'active':'' ?>" href="?tab=clients">Clients</a>
    <a class="settings-tab <?= $activeTab==='paper_masters'?'active':'' ?>" href="?tab=paper_masters">Paper Lists</a>
    <a class="settings-tab <?= $activeTab==='prefix'?'active':'' ?>" href="?tab=prefix">Number Prefix Settings</a>
  </div>

  <div class="settings-body">
    <?php if ($activeTab === 'raw_materials'): ?>
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
          <span class="card-title">Raw Materials</span>
          <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-primary" onclick="openRawMaterialModal()"><i class="bi bi-plus"></i> Add Material</button>
            <button class="btn btn-sm btn-success" onclick="openUploadModal('raw_materials')"><i class="bi bi-upload"></i> Upload CSV</button>
          </div>
        </div>
        <?php $rawMaterialsView = !empty($raw_materials) ? $raw_materials : $sampleRawMaterials; ?>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>GSM</th>
                  <th>Width (mm)</th>
                  <th>Supplier</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rawMaterialsView as $rm): ?>
                  <tr>
                    <td><?= e($rm['name']) ?></td>
                    <td><?= e($rm['type']) ?></td>
                    <td><?= $rm['gsm'] ? e((string)$rm['gsm']) : '-' ?></td>
                    <td><?= $rm['width_mm'] ? e((string)$rm['width_mm']) : '-' ?></td>
                    <td><?= !empty($raw_materials) ? e(getSupplierName($db, $rm['supplier_id'])) : e((string)($rm['supplier_name'] ?? '-')) ?></td>
                    <td>
                      <?php if (!empty($raw_materials)): ?>
                        <button class="btn btn-xs btn-info" onclick="editRawMaterial(<?= $rm['id'] ?>)"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="confirmDelete('raw_material', <?= $rm['id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                      <?php else: ?>
                        <button class="btn btn-xs btn-info" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-trash"></i> Delete</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>

      <!-- Modal for Raw Material -->
      <div id="rmModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:500px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit Raw Material</h3>
          <form method="POST" id="rmForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="rmAction" value="add_raw_material">
            <input type="hidden" name="rm_id" id="rmId" value="">

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Name *</label>
              <input type="text" name="rm_name" id="rmName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Type *</label>
              <input type="text" name="rm_type" id="rmType" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">GSM</label>
              <input type="number" step="0.01" name="rm_gsm" id="rmGsm" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Width (mm)</label>
              <input type="number" step="0.01" name="rm_width" id="rmWidth" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Supplier</label>
              <select name="rm_supplier_id" id="rmSupplierId" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
                <option value="">-- Select Supplier --</option>
                <?php foreach ($suppliers as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= e($s['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closeRawMaterialModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

    <?php if ($activeTab === 'suppliers'): ?>
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
          <span class="card-title">Suppliers</span>
          <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-primary" onclick="openSupplierModal()"><i class="bi bi-plus"></i> Add Supplier</button>
            <button class="btn btn-sm btn-success" onclick="openUploadModal('suppliers')"><i class="bi bi-upload"></i> Upload CSV</button>
          </div>
        </div>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>GST Number</th>
                  <th>Contact Person</th>
                  <th>Phone</th>
                  <th>Email</th>
                  <th>City</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($suppliersData as $s): ?>
                  <tr>
                    <td>
                      <?php if ($hasSuppliersData): ?>
                        <a href="<?= BASE_URL ?>/modules/master/supplier_view.php?id=<?= (int)$s['id'] ?>" style="text-decoration:none;color:inherit"><?= e($s['name']) ?></a>
                      <?php else: ?>
                        <?= e($s['name']) ?>
                      <?php endif; ?>
                    </td>
                    <td><?= e($s['gst_number'] ?? '-') ?></td>
                    <td><?= e($s['contact_person'] ?? '-') ?></td>
                    <td><?= e($s['phone'] ?? '-') ?></td>
                    <td><?= e($s['email'] ?? '-') ?></td>
                    <td><?= e($s['city'] ?? '-') ?></td>
                    <td>
                      <?php if ($hasSuppliersData): ?>
                        <button class="btn btn-xs btn-info" onclick="editSupplier(<?= $s['id'] ?>)"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="confirmDelete('supplier', <?= $s['id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                      <?php else: ?>
                        <button class="btn btn-xs btn-info" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-trash"></i> Delete</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>

      <!-- Modal for Supplier -->
      <div id="supplierModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:500px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px;max-height:90vh;overflow-y:auto">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit Supplier</h3>
          <form method="POST" id="supplierForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="supplierAction" value="add_supplier">
            <input type="hidden" name="supplier_id" id="supplierId" value="">

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Name *</label>
              <input type="text" name="supplier_name" id="supplierName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">GST Number</label>
              <input type="text" name="supplier_gst_number" id="supplierGstNumber" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Contact Person</label>
              <input type="text" name="supplier_contact_person" id="supplierContactPerson" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Phone</label>
              <input type="tel" name="supplier_phone" id="supplierPhone" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Email</label>
              <input type="email" name="supplier_email" id="supplierEmail" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Address</label>
              <textarea name="supplier_address" id="supplierAddress" rows="3" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px"></textarea>
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Notes</label>
              <textarea name="supplier_notes" id="supplierNotes" rows="2" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px"></textarea>
            </div>

            <div style="margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div>
                <label style="display:block;margin-bottom:4px;font-weight:500">City</label>
                <input type="text" name="supplier_city" id="supplierCity" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              </div>
              <div>
                <label style="display:block;margin-bottom:4px;font-weight:500">State</label>
                <input type="text" name="supplier_state" id="supplierState" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closeSupplierModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

    <?php if ($activeTab === 'machines'): ?>
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
          <span class="card-title">Machines</span>
          <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-primary" onclick="openMachineModal()"><i class="bi bi-plus"></i> Add Machine</button>
            <button class="btn btn-sm btn-success" onclick="openUploadModal('machines')"><i class="bi bi-upload"></i> Upload CSV</button>
          </div>
        </div>
        <?php $machinesView = !empty($machines) ? $machines : $sampleMachines; ?>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Department</th>
                  <th>Operator Name</th>
                  <th>Status</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($machinesView as $m): ?>
                  <tr>
                    <td><?= e($m['name']) ?></td>
                    <td><?= e($m['type'] ?? '') ?></td>
                    <td><?= e($m['section'] ?? '') ?></td>
                    <td><?= e($m['operator_name'] ?? '-') ?></td>
                    <td><span class="badge" style="background:<?= $m['status'] === 'Active' ? '#10b981' : ($m['status'] === 'Maintenance' ? '#f59e0b' : '#6b7280') ?>;color:white;padding:4px 8px;border-radius:4px;font-size:0.85rem"><?= e($m['status']) ?></span></td>
                    <td>
                      <?php if (!empty($machines)): ?>
                        <button class="btn btn-xs btn-info" onclick="editMachine(<?= $m['id'] ?>)"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="confirmDelete('machine', <?= $m['id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                      <?php else: ?>
                        <button class="btn btn-xs btn-info" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-trash"></i> Delete</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>

      <!-- Modal for Machine -->
      <div id="machineModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:500px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit Machine</h3>
          <form method="POST" id="machineForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="machineAction" value="add_machine">
            <input type="hidden" name="machine_id" id="machineId" value="">

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Name *</label>
              <input type="text" name="machine_name" id="machineName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Type</label>
              <input type="text" name="machine_type" id="machineType" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:6px;font-weight:500">Department</label>
              <input type="hidden" name="machine_section" id="machineSection">
              <div id="machineSectionChooser" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;padding:10px;border:1px solid #ddd;border-radius:8px;background:#f8fafc">
                <?php foreach ($machineDepartments as $dept): ?>
                  <label style="display:flex;align-items:center;gap:8px;font-size:.9rem;color:#334155">
                    <input type="checkbox" class="machine-section-check" value="<?= e($dept) ?>">
                    <span><?= e($dept) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Operator Name</label>
              <input type="text" name="machine_operator_name" id="machineOperatorName" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px" placeholder="Assigned operator name">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Status</label>
              <select name="machine_status" id="machineStatus" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
                <option value="Maintenance">Maintenance</option>
              </select>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closeMachineModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

    <?php if ($activeTab === 'cylinders'): ?>
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
          <span class="card-title">Cylinders</span>
          <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-primary" onclick="openCylinderModal()"><i class="bi bi-plus"></i> Add Cylinder</button>
            <button class="btn btn-sm btn-success" onclick="openUploadModal('cylinders')"><i class="bi bi-upload"></i> Upload CSV</button>
          </div>
        </div>
        <?php $cylindersView = !empty($cylinders) ? $cylinders : $sampleCylinders; ?>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Size (inch)</th>
                  <th>Teeth</th>
                  <th>Material Type</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($cylindersView as $c): ?>
                  <tr>
                    <td><?= e($c['name']) ?></td>
                    <td><?= $c['size_inch'] ? e((string)$c['size_inch']) : '-' ?></td>
                    <td><?= $c['teeth'] ? e((string)$c['teeth']) : '-' ?></td>
                    <td><?= e($c['material_type'] ?? '') ?></td>
                    <td>
                      <?php if (!empty($cylinders)): ?>
                        <button class="btn btn-xs btn-info" onclick="editCylinder(<?= $c['id'] ?>)"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="confirmDelete('cylinder', <?= $c['id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                      <?php else: ?>
                        <button class="btn btn-xs btn-info" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-trash"></i> Delete</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>

      <!-- Modal for Cylinder -->
      <div id="cylinderModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:500px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit Cylinder</h3>
          <form method="POST" id="cylinderForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="cylinderAction" value="add_cylinder">
            <input type="hidden" name="cylinder_id" id="cylinderId" value="">

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Name *</label>
              <input type="text" name="cylinder_name" id="cylinderName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Size (inch)</label>
              <input type="number" step="0.01" name="cylinder_size" id="cylinderSize" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Teeth</label>
              <input type="number" name="cylinder_teeth" id="cylinderTeeth" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Material Type</label>
              <input type="text" name="cylinder_material" id="cylinderMaterial" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closeCylinderModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

    <?php if ($activeTab === 'clients'): ?>
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
          <span class="card-title">Clients</span>
          <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-primary" onclick="openClientModal()"><i class="bi bi-plus"></i> Add Client</button>
            <button class="btn btn-sm btn-success" onclick="openUploadModal('clients')"><i class="bi bi-upload"></i> Upload CSV</button>
          </div>
        </div>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Contact Person</th>
                  <th>Phone</th>
                  <th>Email</th>
                  <th>Credit Period</th>
                  <th>Credit Limit</th>
                  <th>City</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($clientsData as $cl): ?>
                  <tr>
                    <td>
                      <?php if ($hasClientsData): ?>
                        <a href="<?= BASE_URL ?>/modules/master/client_view.php?id=<?= (int)$cl['id'] ?>" style="text-decoration:none;color:inherit"><?= e($cl['name']) ?></a>
                      <?php else: ?>
                        <?= e($cl['name']) ?>
                      <?php endif; ?>
                    </td>
                    <td><?= e($cl['contact_person'] ?? '-') ?></td>
                    <td><?= e($cl['phone'] ?? '-') ?></td>
                    <td><?= e($cl['email'] ?? '-') ?></td>
                    <td><?= e((string)($cl['credit_period_days'] ?? 0)) ?> days</td>
                    <td><?= e(number_format((float)($cl['credit_limit'] ?? 0), 2)) ?></td>
                    <td><?= e($cl['city'] ?? '-') ?></td>
                    <td>
                      <?php if ($hasClientsData): ?>
                        <button class="btn btn-xs btn-info" onclick="editClient(<?= $cl['id'] ?>)"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="confirmDelete('client', <?= $cl['id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                      <?php else: ?>
                        <button class="btn btn-xs btn-info" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-trash"></i> Delete</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>

      <!-- Modal for Client -->
      <div id="clientModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:500px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px;max-height:90vh;overflow-y:auto">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit Client</h3>
          <form method="POST" id="clientForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="clientAction" value="add_client">
            <input type="hidden" name="client_id" id="clientId" value="">

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Name *</label>
              <input type="text" name="client_name" id="clientName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Contact Person</label>
              <input type="text" name="client_contact_person" id="clientContactPerson" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Phone</label>
              <input type="tel" name="client_phone" id="clientPhone" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Email</label>
              <input type="email" name="client_email" id="clientEmail" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Address</label>
              <textarea name="client_address" id="clientAddress" rows="3" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px"></textarea>
            </div>

            <div style="margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div>
                <label style="display:block;margin-bottom:4px;font-weight:500">Credit Period (days) *</label>
                <input type="number" min="0" name="client_credit_period_days" id="clientCreditPeriodDays" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              </div>
              <div>
                <label style="display:block;margin-bottom:4px;font-weight:500">Credit Limit *</label>
                <input type="number" min="0" step="0.01" name="client_credit_limit" id="clientCreditLimit" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              </div>
            </div>

            <div style="margin-bottom:16px;display:grid;grid-template-columns:1fr 1fr;gap:10px">
              <div>
                <label style="display:block;margin-bottom:4px;font-weight:500">City</label>
                <input type="text" name="client_city" id="clientCity" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              </div>
              <div>
                <label style="display:block;margin-bottom:4px;font-weight:500">State</label>
                <input type="text" name="client_state" id="clientState" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
              </div>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closeClientModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

    <?php if ($activeTab === 'bom'): ?>
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center">
          <span class="card-title">BOM Master</span>
          <div style="display:flex;gap:8px">
            <button class="btn btn-sm btn-primary" onclick="openBomModal()"><i class="bi bi-plus"></i> Add BOM</button>
            <button class="btn btn-sm btn-success" onclick="openUploadModal('bom')"><i class="bi bi-upload"></i> Upload CSV</button>
          </div>
        </div>
        <?php $bomsView = !empty($boms) ? $boms : $sampleBoms; ?>
          <div class="table-responsive">
            <table class="table">
              <thead>
                <tr>
                  <th>BOM Name</th>
                  <th>Description</th>
                  <th>Status</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($bomsView as $b): ?>
                  <tr>
                    <td><?= e($b['bom_name']) ?></td>
                    <td><?= e(substr($b['description'] ?? '', 0, 50)) ?></td>
                    <td><span class="badge" style="background:<?= $b['status'] === 'Active' ? '#10b981' : '#6b7280' ?>;color:white;padding:4px 8px;border-radius:4px;font-size:0.85rem"><?= e($b['status']) ?></span></td>
                    <td><?= date('M d, Y', strtotime($b['created_at'])) ?></td>
                    <td>
                      <?php if (!empty($boms)): ?>
                        <button class="btn btn-xs btn-info" onclick="editBom(<?= $b['id'] ?>)"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" onclick="confirmDelete('bom', <?= $b['id'] ?>)"><i class="bi bi-trash"></i> Delete</button>
                      <?php else: ?>
                        <button class="btn btn-xs btn-info" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-pencil"></i> Edit</button>
                        <button class="btn btn-xs btn-danger" type="button" disabled title="Sample data - add real records to enable actions" style="opacity:.55;cursor:not-allowed"><i class="bi bi-trash"></i> Delete</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
      </div>

      <!-- Modal for BOM -->
      <div id="bomModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:500px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit BOM</h3>
          <form method="POST" id="bomForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="bomAction" value="add_bom">
            <input type="hidden" name="bom_id" id="bomId" value="">

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">BOM Name *</label>
              <input type="text" name="bom_name" id="bomName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Description</label>
              <textarea name="bom_description" id="bomDescription" rows="3" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px"></textarea>
            </div>

            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Status</label>
              <select name="bom_status" id="bomStatus" style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
                <option value="Active">Active</option>
                <option value="Inactive">Inactive</option>
              </select>
            </div>

            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closeBomModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>

    <?php endif; ?>

    <?php if ($activeTab === 'paper_masters'): ?>
      <!-- Paper Companies section -->
      <div class="card" style="margin-bottom:16px">
        <div class="card-header" style="padding:14px 16px">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;width:100%">
            <input type="checkbox" id="chkCompanies" checked
              onchange="document.getElementById('companiesSection').style.display=this.checked?'block':'none'"
              style="width:18px;height:18px;accent-color:#f97316;flex-shrink:0">
            <span class="card-title" style="margin:0">Paper Companies</span>
            <?php if ($isSystemAdmin): ?>
              <button class="btn btn-sm btn-primary" style="margin-left:auto" onclick="event.preventDefault();openPaperCompanyModal()">
                <i class="bi bi-plus"></i> Add Company
              </button>
            <?php endif; ?>
          </label>
        </div>
        <div id="companiesSection" class="card-body" style="padding-top:12px">
          <div class="alert alert-info" style="margin-bottom:14px;font-size:0.875rem">
            Only system admin can add or edit these names. Active entries appear as suggestions in Add Roll — users can type to filter but must select from the list. Old rolls with unlisted values still show their saved data.
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
            <div>
              <h6 style="font-weight:600;margin:0 0 8px;color:#374151;font-size:0.9rem"><i class="bi bi-list-check"></i> Master List</h6>
              <div class="table-responsive">
                <table class="table" style="font-size:0.875rem">
                  <thead>
                    <tr>
                      <th>Name</th><th>Status</th><th>Updated</th>
                      <?php if ($isSystemAdmin): ?><th>Actions</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($paperCompanies)): ?>
                      <tr><td colspan="4" class="text-muted">No paper companies added yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($paperCompanies as $pc): ?>
                        <tr>
                          <td><?= e($pc['name']) ?></td>
                          <td><span class="badge" style="background:<?= (int)$pc['is_active']===1?'#10b981':'#6b7280' ?>;color:white;padding:3px 8px;border-radius:4px;font-size:0.8rem"><?= (int)$pc['is_active']===1?'Active':'Inactive' ?></span></td>
                          <td><?= !empty($pc['updated_at'])?e(date('M d, Y',strtotime($pc['updated_at']))):'-' ?></td>
                          <?php if ($isSystemAdmin): ?>
                            <td>
                              <button class="btn btn-xs btn-info" onclick="editPaperCompany(<?= (int)$pc['id'] ?>)"><i class="bi bi-pencil"></i></button>
                              <button class="btn btn-xs btn-danger" onclick="confirmDelete('paper_company', <?= (int)$pc['id'] ?>)"><i class="bi bi-trash"></i></button>
                            </td>
                          <?php endif; ?>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div>
              <h6 style="font-weight:600;margin:0 0 8px;color:#374151;font-size:0.9rem"><i class="bi bi-archive"></i> Existing in Paper Stock <small style="font-weight:400;color:#9ca3af">(reference)</small></h6>
              <?php if (empty($stockCompanies)): ?>
                <p class="text-muted" style="font-size:0.875rem">No company values saved in paper stock yet.</p>
              <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                  <?php
                    $masterCompanyNames = array_column($paperCompanies, 'name');
                    foreach ($stockCompanies as $sc):
                      $inMaster = in_array($sc, $masterCompanyNames, true);
                  ?>
                    <span title="<?= $inMaster?'In master list':'Legacy value — not in master list' ?>"
                      style="display:inline-block;padding:4px 10px;border-radius:20px;font-size:0.8rem;font-weight:500;background:<?= $inMaster?'#dcfce7':'#fef3c7' ?>;color:<?= $inMaster?'#166534':'#92400e' ?>;border:1px solid <?= $inMaster?'#86efac':'#fcd34d' ?>">
                      <?= e($sc) ?><?= $inMaster ? '' : ' ⚠' ?>
                    </span>
                  <?php endforeach; ?>
                </div>
                <p style="font-size:0.78rem;color:#9ca3af;margin-top:8px"><i class="bi bi-info-circle"></i> Green = in master. Yellow ⚠ = legacy value (still shown on existing rolls).</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Paper Types section -->
      <div class="card">
        <div class="card-header" style="padding:14px 16px">
          <label style="display:flex;align-items:center;gap:10px;cursor:pointer;margin:0;width:100%">
            <input type="checkbox" id="chkTypes" checked
              onchange="document.getElementById('typesSection').style.display=this.checked?'block':'none'"
              style="width:18px;height:18px;accent-color:#f97316;flex-shrink:0">
            <span class="card-title" style="margin:0">Paper Types</span>
            <?php if ($isSystemAdmin): ?>
              <button class="btn btn-sm btn-primary" style="margin-left:auto" onclick="event.preventDefault();openPaperTypeModal()">
                <i class="bi bi-plus"></i> Add Type
              </button>
            <?php endif; ?>
          </label>
        </div>
        <div id="typesSection" class="card-body" style="padding-top:12px">
          <div class="alert alert-info" style="margin-bottom:14px;font-size:0.875rem">
            Only system admin can add or edit paper types. Active entries appear as suggestions in Add Roll — users can type to filter but must select from the list.
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px">
            <div>
              <h6 style="font-weight:600;margin:0 0 8px;color:#374151;font-size:0.9rem"><i class="bi bi-list-check"></i> Master List</h6>
              <div class="table-responsive">
                <table class="table" style="font-size:0.875rem">
                  <thead>
                    <tr>
                      <th>Name</th><th>Status</th><th>Updated</th>
                      <?php if ($isSystemAdmin): ?><th>Actions</th><?php endif; ?>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($paperTypes)): ?>
                      <tr><td colspan="4" class="text-muted">No paper types added yet.</td></tr>
                    <?php else: ?>
                      <?php foreach ($paperTypes as $pt): ?>
                        <tr>
                          <td><?= e($pt['name']) ?></td>
                          <td><span class="badge" style="background:<?= (int)$pt['is_active']===1?'#10b981':'#6b7280' ?>;color:white;padding:3px 8px;border-radius:4px;font-size:0.8rem"><?= (int)$pt['is_active']===1?'Active':'Inactive' ?></span></td>
                          <td><?= !empty($pt['updated_at'])?e(date('M d, Y',strtotime($pt['updated_at']))):'-' ?></td>
                          <?php if ($isSystemAdmin): ?>
                            <td>
                              <button class="btn btn-xs btn-info" onclick="editPaperType(<?= (int)$pt['id'] ?>)"><i class="bi bi-pencil"></i></button>
                              <button class="btn btn-xs btn-danger" onclick="confirmDelete('paper_type', <?= (int)$pt['id'] ?>)"><i class="bi bi-trash"></i></button>
                            </td>
                          <?php endif; ?>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
            <div>
              <h6 style="font-weight:600;margin:0 0 8px;color:#374151;font-size:0.9rem"><i class="bi bi-archive"></i> Existing in Paper Stock <small style="font-weight:400;color:#9ca3af">(reference)</small></h6>
              <?php if (empty($stockTypes)): ?>
                <p class="text-muted" style="font-size:0.875rem">No paper type values saved in paper stock yet.</p>
              <?php else: ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px">
                  <?php
                    $masterTypeNames = array_column($paperTypes, 'name');
                    foreach ($stockTypes as $st):
                      $inMaster = in_array($st, $masterTypeNames, true);
                  ?>
                    <span title="<?= $inMaster?'In master list':'Legacy value — not in master list' ?>"
                      style="display:inline-block;padding:4px 10px;border-radius:20px;font-size:0.8rem;font-weight:500;background:<?= $inMaster?'#dcfce7':'#fef3c7' ?>;color:<?= $inMaster?'#166534':'#92400e' ?>;border:1px solid <?= $inMaster?'#86efac':'#fcd34d' ?>">
                      <?= e($st) ?><?= $inMaster ? '' : ' ⚠' ?>
                    </span>
                  <?php endforeach; ?>
                </div>
                <p style="font-size:0.78rem;color:#9ca3af;margin-top:8px"><i class="bi bi-info-circle"></i> Green = in master. Yellow ⚠ = legacy value (still shown on existing rolls).</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <!-- Paper Company Modal -->
      <div id="paperCompanyModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:480px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit Paper Company</h3>
          <form method="POST" id="paperCompanyForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="paperCompanyAction" value="add_paper_company">
            <input type="hidden" name="paper_company_id" id="paperCompanyId" value="">
            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Paper Company Name *</label>
              <input type="text" name="paper_company_name" id="paperCompanyName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:16px">
              <input type="checkbox" name="paper_company_is_active" id="paperCompanyIsActive" value="1" checked style="width:16px;height:16px">
              <span>Active and available in Add Roll</span>
            </label>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closePaperCompanyModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>

      <!-- Paper Type Modal -->
      <div id="paperTypeModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:1000">
        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:480px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px">
          <h3 style="margin:0 0 20px;font-size:1.25rem">Add / Edit Paper Type</h3>
          <form method="POST" id="paperTypeForm">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
            <input type="hidden" name="action" id="paperTypeAction" value="add_paper_type">
            <input type="hidden" name="paper_type_id" id="paperTypeId" value="">
            <div style="margin-bottom:16px">
              <label style="display:block;margin-bottom:4px;font-weight:500">Paper Type Name *</label>
              <input type="text" name="paper_type_name" id="paperTypeName" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
            </div>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin-bottom:16px">
              <input type="checkbox" name="paper_type_is_active" id="paperTypeIsActive" value="1" checked style="width:16px;height:16px">
              <span>Active and available in Add Roll</span>
            </label>
            <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:24px">
              <button type="button" class="btn btn-secondary" onclick="closePaperTypeModal()">Cancel</button>
              <button type="submit" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($activeTab === 'prefix'): ?>
      <form method="POST" class="form-grid-2" id="prefix-settings-form">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <input type="hidden" name="action" value="save_prefix">

        <div class="form-group">
          <label>Year Format</label>
          <select name="prefix_year_format" id="prefix_year_format">
            <option value="YY" <?= (($idg['year_format'] ?? 'YY') === 'YY') ? 'selected' : '' ?>>YY (e.g. 26)</option>
            <option value="YYYY" <?= (($idg['year_format'] ?? 'YY') === 'YYYY') ? 'selected' : '' ?>>YYYY (e.g. 2026)</option>
          </select>
        </div>

        <div class="form-group">
          <label>Separator</label>
          <input type="text" name="prefix_separator" id="prefix_separator" value="<?= e((string)($idg['separator'] ?? '/')) ?>" maxlength="4">
        </div>

        <div class="form-group">
          <label>Padding</label>
          <input type="number" min="1" max="8" name="prefix_padding" id="prefix_padding" value="<?= e((string)($idg['padding'] ?? 3)) ?>">
        </div>

        <div class="form-group">
          <label>Preview Year</label>
          <input type="text" value="<?= e(($idg['year_format'] ?? 'YY') === 'YYYY' ? date('Y') : date('y')) ?>" readonly>
        </div>

        <?php foreach ($prefixModules as $type => $label): ?>
          <div class="form-group">
            <label><?= e($label) ?></label>
            <input
              type="text"
              name="prefix_<?= e($type) ?>"
              id="prefix_<?= e($type) ?>"
              data-prefix-type="<?= e($type) ?>"
              value="<?= e((string)($idg['modules'][$type]['prefix'] ?? '')) ?>"
            >
          </div>
        <?php endforeach; ?>

        <div class="form-group col-span-2">
          <label>Live Preview</label>
          <div class="card">
            <div class="card-body" id="prefix-preview-box">
              <?php foreach ($prefixModules as $type => $label): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 0;border-bottom:1px solid var(--border)">
                  <span><?= e($label) ?></span>
                  <strong id="preview_<?= e($type) ?>"><?= e(buildFormattedId(
                    (string)($idg['modules'][$type]['prefix'] ?? ''),
                    ($idg['year_format'] ?? 'YY') === 'YYYY' ? date('Y') : date('y'),
                    ((int)($idg['modules'][$type]['counter'] ?? 0)) + 1,
                    (string)($idg['separator'] ?? '/'),
                    (int)($idg['padding'] ?? 3)
                  )) ?></strong>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="form-actions col-span-2">
          <button class="btn btn-primary" type="submit"><i class="bi bi-save"></i> Save Prefix Settings</button>
          <button
            class="btn btn-secondary"
            type="submit"
            name="action"
            value="reset_prefix_counters"
            onclick="return confirm('Reset all prefix counters? Next generated IDs will start from 0001.');"
          ><i class="bi bi-arrow-counterclockwise"></i> Reset All Counters to 0001</button>
          <button
            class="btn btn-danger"
            type="submit"
            name="action"
            value="reset_test_data"
            onclick="return confirm('⚠️ TESTING RESET\n\nThis will permanently delete:\n• All Planning Board rows\n• All Jumbo job cards\n• All Flexo/Printing job cards\n• All Slitting batches and entries\n• All Jumbo change requests\n\nAnd reset PLN / JMB / FLX counters to 0001.\n\nThis cannot be undone. Continue only on a test environment.');"
            style="background:#dc2626;border-color:#dc2626;color:#fff"
          ><i class="bi bi-trash3"></i> Reset Planning / Jumbo / Flexo (Testing)</button>
        </div>
      </form>

      <script>
      (function(){
        var form = document.getElementById('prefix-settings-form');
        if (!form) return;

        function currentYearToken(){
          var yf = document.getElementById('prefix_year_format');
          var d = new Date();
          if (yf && yf.value === 'YYYY') return String(d.getFullYear());
          return String(d.getFullYear()).slice(-2);
        }

        function rebuildPreview(){
          var sepEl = document.getElementById('prefix_separator');
          var padEl = document.getElementById('prefix_padding');
          var sep = sepEl && sepEl.value !== '' ? sepEl.value : '/';
          var pad = parseInt(padEl ? padEl.value : '3', 10);
          if (!Number.isFinite(pad) || pad < 1) pad = 1;
          if (pad > 8) pad = 8;

          var yearToken = currentYearToken();

          form.querySelectorAll('[data-prefix-type]').forEach(function(input){
            var type = input.getAttribute('data-prefix-type');
            var prefix = (input.value || '').trim().toUpperCase();
            var seq = String(1).padStart(pad, '0');
            var out = prefix + sep + yearToken + sep + seq;
            var holder = document.getElementById('preview_' + type);
            if (holder) holder.textContent = out;
          });
        }

        form.addEventListener('input', function(e){
          var t = e.target;
          if (t && t.hasAttribute('data-prefix-type')) {
            t.value = String(t.value || '').toUpperCase();
          }
          rebuildPreview();
        });

        var yearFormat = document.getElementById('prefix_year_format');
        if (yearFormat) yearFormat.addEventListener('change', rebuildPreview);

        rebuildPreview();
      })();
      </script>
    <?php endif; ?>
  </div>
</div>

<!-- Delete Confirmation Modal -->
<div id="deleteModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:2000;display:none">
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:400px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px;text-align:center">
    <h3 style="margin:0 0 12px;font-size:1.15rem">Confirm Delete</h3>
    <p style="margin:0 0 24px;color:#6b7280">Are you sure you want to delete this record? This action cannot be undone.</p>
    <div style="display:flex;gap:10px;justify-content:center">
      <button class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
      <button class="btn btn-danger" onclick="submitDelete()">Delete</button>
    </div>
  </div>
</div>

<!-- Upload CSV Modal -->
<div id="uploadModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:2000">
  <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:90%;max-width:480px;background:white;border-radius:8px;box-shadow:0 4px 6px rgba(0,0,0,0.1);padding:24px">
    <h3 style="margin:0 0 16px;font-size:1.15rem"><i class="bi bi-upload"></i> Upload CSV File</h3>
    <form method="POST" enctype="multipart/form-data" id="uploadForm">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="upload_master_data">
      <input type="hidden" name="upload_tab" id="uploadTab" value="">
      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:4px;font-weight:500">Select CSV File *</label>
        <input type="file" name="upload_file" id="uploadFile" accept=".csv" required style="width:100%;padding:8px;border:1px solid #ddd;border-radius:4px">
      </div>
      <div style="margin-bottom:16px;padding:12px;background:#f0f9ff;border-radius:6px;border:1px solid #bae6fd">
        <strong style="font-size:0.85rem">CSV Format Guide:</strong>
        <div id="uploadHint" style="font-size:0.82rem;color:#475569;margin-top:6px"></div>
      </div>
      <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px">
        <button type="button" class="btn btn-secondary" onclick="closeUploadModal()">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Upload</button>
      </div>
    </form>
  </div>
</div>

<!-- Delete Form (hidden) -->
<form id="deleteForm" method="POST" style="display:none">
  <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
  <input type="hidden" name="action" id="deleteAction">
  <input type="hidden" name="<?php echo 'id'; ?>" id="deleteId">
</form>

<script>
const suppliersData = <?= json_encode($suppliers, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const clientsData = <?= json_encode($clients, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const machinesData = <?= json_encode($machines, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const machineDepartmentsData = <?= json_encode($machineDepartments, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const paperCompaniesData = <?= json_encode($paperCompanies, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const paperTypesData = <?= json_encode($paperTypes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

var currentDeleteType = '';
var currentDeleteId = '';
var deleteFieldMap = {
  'raw_material': { action: 'delete_raw_material', field: 'rm_id' },
  'supplier': { action: 'delete_supplier', field: 'supplier_id' },
  'machine': { action: 'delete_machine', field: 'machine_id' },
  'cylinder': { action: 'delete_cylinder', field: 'cylinder_id' },
  'client': { action: 'delete_client', field: 'client_id' },
  'paper_company': { action: 'delete_paper_company', field: 'paper_company_id' },
  'paper_type': { action: 'delete_paper_type', field: 'paper_type_id' },
  'bom': { action: 'delete_bom', field: 'bom_id' }
};

function confirmDelete(type, id) {
  currentDeleteType = type;
  currentDeleteId = id;
  document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal() {
  document.getElementById('deleteModal').style.display = 'none';
}

function submitDelete() {
  var mapping = deleteFieldMap[currentDeleteType];
  if (!mapping) return;
  
  var form = document.getElementById('deleteForm');
  document.getElementById('deleteAction').value = mapping.action;
  var newField = document.createElement('input');
  newField.type = 'hidden';
  newField.name = mapping.field;
  newField.value = currentDeleteId;
  
  form.innerHTML = '<input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="' + mapping.action + '"><input type="hidden" name="' + mapping.field + '" value="' + currentDeleteId + '">';
  form.submit();
}

// Raw Material Modal Functions
function openRawMaterialModal() {
  document.getElementById('rmForm').reset();
  document.getElementById('rmAction').value = 'add_raw_material';
  document.getElementById('rmId').value = '';
  document.getElementById('rmModal').style.display = 'flex';
}

function closeRawMaterialModal() {
  document.getElementById('rmModal').style.display = 'none';
}

function editRawMaterial(id) {
  if (!id) return;
  var xhr = new XMLHttpRequest();
  xhr.open('POST', window.location.href, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onload = function() {
    if (xhr.status === 200) {
      var response = xhr.responseText;
      var match = response.match(/<script[^>]*id="rm-edit-data"[^>]*>(.*?)<\/script>/s);
      if (match) {
        try {
          var data = JSON.parse(match[1]);
          document.getElementById('rmId').value = data.id;
          document.getElementById('rmName').value = data.name;
          document.getElementById('rmType').value = data.type;
          document.getElementById('rmGsm').value = data.gsm || '';
          document.getElementById('rmWidth').value = data.width_mm || '';
          document.getElementById('rmSupplierId').value = data.supplier_id || '';
          document.getElementById('rmAction').value = 'edit_raw_material';
          document.getElementById('rmModal').style.display = 'flex';
        } catch(e) {
          alert('Error loading record');
        }
      }
    }
  };
  xhr.send('_method=get_raw_material&id=' + id);
}

// Supplier Modal Functions
function openSupplierModal() {
  document.getElementById('supplierForm').reset();
  document.getElementById('supplierAction').value = 'add_supplier';
  document.getElementById('supplierId').value = '';
  var cp = document.getElementById('supplierContactPerson'); if (cp) cp.value = '';
  var ph = document.getElementById('supplierPhone'); if (ph) ph.value = '';
  var em = document.getElementById('supplierEmail'); if (em) em.value = '';
  var ad = document.getElementById('supplierAddress'); if (ad) ad.value = '';
  var ct = document.getElementById('supplierCity'); if (ct) ct.value = '';
  var st = document.getElementById('supplierState'); if (st) st.value = '';
  var gst = document.getElementById('supplierGstNumber'); if (gst) gst.value = '';
  var nt = document.getElementById('supplierNotes'); if (nt) nt.value = '';
  document.getElementById('supplierModal').style.display = 'flex';
}

function closeSupplierModal() {
  document.getElementById('supplierModal').style.display = 'none';
}

function editSupplier(id) {
  var rows = suppliersData;
  var item = null;
  for (var i = 0; i < rows.length; i++) {
    if (parseInt(rows[i].id, 10) === parseInt(id, 10)) {
      item = rows[i];
      break;
    }
  }
  if (!item) return;
  document.getElementById('supplierAction').value = 'edit_supplier';
  document.getElementById('supplierId').value = item.id || '';
  document.getElementById('supplierName').value = item.name || '';
  document.getElementById('supplierContactPerson').value = item.contact_person || '';
  document.getElementById('supplierPhone').value = item.phone || '';
  document.getElementById('supplierEmail').value = item.email || '';
  document.getElementById('supplierAddress').value = item.address || '';
  document.getElementById('supplierCity').value = item.city || '';
  document.getElementById('supplierState').value = item.state || '';
  var gst = document.getElementById('supplierGstNumber'); if (gst) gst.value = item.gst_number || '';
  var nt = document.getElementById('supplierNotes'); if (nt) nt.value = item.notes || '';
  document.getElementById('supplierModal').style.display = 'flex';
}

function parseMachineDepartments(value) {
  var raw = Array.isArray(value) ? value.slice() : String(value || '').split(/\s*,\s*|\r\n|\r|\n/);
  var seen = {};
  var out = [];
  raw.forEach(function(item) {
    var text = String(item || '').trim();
    if (!text) return;
    var key = text.toLowerCase();
    if (seen[key]) return;
    seen[key] = true;
    out.push(text);
  });
  return out;
}

function syncMachineSectionInput(selectedValue, customValue) {
  var selected = parseMachineDepartments(selectedValue);
  document.getElementById('machineSection').value = selected.join(', ');
}

function setMachineDepartmentChooser(value) {
  var selected = parseMachineDepartments(value);
  document.querySelectorAll('#machineSectionChooser .machine-section-check').forEach(function(box) {
    box.checked = selected.some(function(item){ return item.toLowerCase() === String(box.value || '').toLowerCase(); });
  });
  syncMachineSectionInput(selected, '');
}

function bindMachineDepartmentChooser() {
  document.querySelectorAll('#machineSectionChooser .machine-section-check').forEach(function(box) {
    box.addEventListener('change', function() {
      var selected = Array.prototype.slice.call(document.querySelectorAll('#machineSectionChooser .machine-section-check:checked')).map(function(item){ return item.value; });
      syncMachineSectionInput(selected, '');
    });
  });
}

bindMachineDepartmentChooser();
var machineFormEl = document.getElementById('machineForm');
if (machineFormEl) {
  machineFormEl.addEventListener('submit', function() {
    var selected = Array.prototype.slice.call(document.querySelectorAll('#machineSectionChooser .machine-section-check:checked')).map(function(item){ return item.value; });
    syncMachineSectionInput(selected, '');
  });
}

// Machine Modal Functions
function openMachineModal() {
  document.getElementById('machineForm').reset();
  document.getElementById('machineAction').value = 'add_machine';
  document.getElementById('machineId').value = '';
  var op = document.getElementById('machineOperatorName'); if (op) op.value = '';
  setMachineDepartmentChooser('');
  document.getElementById('machineModal').style.display = 'flex';
}

function closeMachineModal() {
  document.getElementById('machineModal').style.display = 'none';
}

function editMachine(id) {
  var rows = machinesData;
  var item = null;
  for (var i = 0; i < rows.length; i++) {
    if (parseInt(rows[i].id, 10) === parseInt(id, 10)) {
      item = rows[i];
      break;
    }
  }
  if (!item) return;
  document.getElementById('machineAction').value = 'edit_machine';
  document.getElementById('machineId').value = item.id || '';
  document.getElementById('machineName').value = item.name || '';
  document.getElementById('machineType').value = item.type || '';
  setMachineDepartmentChooser(item.section || '');
  var op = document.getElementById('machineOperatorName'); if (op) op.value = item.operator_name || '';
  document.getElementById('machineStatus').value = item.status || 'Active';
  document.getElementById('machineModal').style.display = 'flex';
}

// Cylinder Modal Functions
function openCylinderModal() {
  document.getElementById('cylinderForm').reset();
  document.getElementById('cylinderAction').value = 'add_cylinder';
  document.getElementById('cylinderId').value = '';
  document.getElementById('cylinderModal').style.display = 'flex';
}

function closeCylinderModal() {
  document.getElementById('cylinderModal').style.display = 'none';
}

// Client Modal Functions
function openClientModal() {
  document.getElementById('clientForm').reset();
  document.getElementById('clientAction').value = 'add_client';
  document.getElementById('clientId').value = '';
  var cp = document.getElementById('clientContactPerson'); if (cp) cp.value = '';
  var ph = document.getElementById('clientPhone'); if (ph) ph.value = '';
  var em = document.getElementById('clientEmail'); if (em) em.value = '';
  var ad = document.getElementById('clientAddress'); if (ad) ad.value = '';
  var ct = document.getElementById('clientCity'); if (ct) ct.value = '';
  var st = document.getElementById('clientState'); if (st) st.value = '';
  var cpd = document.getElementById('clientCreditPeriodDays'); if (cpd) cpd.value = 0;
  var cl = document.getElementById('clientCreditLimit'); if (cl) cl.value = 0;
  document.getElementById('clientModal').style.display = 'flex';
}

function closeClientModal() {
  document.getElementById('clientModal').style.display = 'none';
}

function openPaperCompanyModal() {
  document.getElementById('paperCompanyForm').reset();
  document.getElementById('paperCompanyAction').value = 'add_paper_company';
  document.getElementById('paperCompanyId').value = '';
  document.getElementById('paperCompanyIsActive').checked = true;
  document.getElementById('paperCompanyModal').style.display = 'flex';
}

function closePaperCompanyModal() {
  document.getElementById('paperCompanyModal').style.display = 'none';
}

function editPaperCompany(id) {
  var rows = paperCompaniesData;
  var item = null;
  for (var i = 0; i < rows.length; i++) {
    if (parseInt(rows[i].id, 10) === parseInt(id, 10)) {
      item = rows[i];
      break;
    }
  }
  if (!item) return;
  document.getElementById('paperCompanyAction').value = 'edit_paper_company';
  document.getElementById('paperCompanyId').value = item.id || '';
  document.getElementById('paperCompanyName').value = item.name || '';
  document.getElementById('paperCompanyIsActive').checked = parseInt(item.is_active, 10) === 1;
  document.getElementById('paperCompanyModal').style.display = 'flex';
}

function openPaperTypeModal() {
  document.getElementById('paperTypeForm').reset();
  document.getElementById('paperTypeAction').value = 'add_paper_type';
  document.getElementById('paperTypeId').value = '';
  document.getElementById('paperTypeIsActive').checked = true;
  document.getElementById('paperTypeModal').style.display = 'flex';
}

function closePaperTypeModal() {
  document.getElementById('paperTypeModal').style.display = 'none';
}

function editPaperType(id) {
  var rows = paperTypesData;
  var item = null;
  for (var i = 0; i < rows.length; i++) {
    if (parseInt(rows[i].id, 10) === parseInt(id, 10)) {
      item = rows[i];
      break;
    }
  }
  if (!item) return;
  document.getElementById('paperTypeAction').value = 'edit_paper_type';
  document.getElementById('paperTypeId').value = item.id || '';
  document.getElementById('paperTypeName').value = item.name || '';
  document.getElementById('paperTypeIsActive').checked = parseInt(item.is_active, 10) === 1;
  document.getElementById('paperTypeModal').style.display = 'flex';
}

function editClient(id) {
  var rows = clientsData;
  var item = null;
  for (var i = 0; i < rows.length; i++) {
    if (parseInt(rows[i].id, 10) === parseInt(id, 10)) {
      item = rows[i];
      break;
    }
  }
  if (!item) return;
  document.getElementById('clientAction').value = 'edit_client';
  document.getElementById('clientId').value = item.id || '';
  document.getElementById('clientName').value = item.name || '';
  document.getElementById('clientContactPerson').value = item.contact_person || '';
  document.getElementById('clientPhone').value = item.phone || '';
  document.getElementById('clientEmail').value = item.email || '';
  document.getElementById('clientAddress').value = item.address || '';
  document.getElementById('clientCity').value = item.city || '';
  document.getElementById('clientState').value = item.state || '';
  var cpd = document.getElementById('clientCreditPeriodDays'); if (cpd) cpd.value = item.credit_period_days || 0;
  var cl = document.getElementById('clientCreditLimit'); if (cl) cl.value = item.credit_limit || 0;
  document.getElementById('clientModal').style.display = 'flex';
}

// BOM Modal Functions
function openBomModal() {
  document.getElementById('bomForm').reset();
  document.getElementById('bomAction').value = 'add_bom';
  document.getElementById('bomId').value = '';
  document.getElementById('bomModal').style.display = 'flex';
}

function closeBomModal() {
  document.getElementById('bomModal').style.display = 'none';
}

// Upload Modal Functions
var uploadHints = {
  'raw_materials': 'Required: <b>Name</b>, <b>Type</b><br>Optional: GSM, Width (or Width_mm)',
  'suppliers': 'Required: <b>Name</b><br>Optional: GST Number, Contact Person, Phone, Email, Address, Notes, City, State',
  'clients': 'Required: <b>Name</b><br>Optional: Contact Person, Phone, Email, Address, Credit Period Days, Credit Limit, City, State',
  'machines': 'Required: <b>Name</b><br>Optional: Type, Section, Operator Name, Status (Active/Inactive/Maintenance)',
  'cylinders': 'Required: <b>Name</b><br>Optional: Size (or Size_inch), Teeth, Material Type (or Material)',
  'bom': 'Required: <b>BOM Name</b> (or Name)<br>Optional: Description, Status (Active/Inactive)'
};

function openUploadModal(tab) {
  document.getElementById('uploadTab').value = tab;
  document.getElementById('uploadFile').value = '';
  document.getElementById('uploadHint').innerHTML = uploadHints[tab] || '';
  document.getElementById('uploadModal').style.display = 'flex';
}

function closeUploadModal() {
  document.getElementById('uploadModal').style.display = 'none';
}

// Close modals on escape key
document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape') {
    document.getElementById('rmModal').style.display = 'none';
    document.getElementById('supplierModal').style.display = 'none';
    document.getElementById('machineModal').style.display = 'none';
    document.getElementById('cylinderModal').style.display = 'none';
    document.getElementById('clientModal').style.display = 'none';
    document.getElementById('paperCompanyModal').style.display = 'none';
    document.getElementById('paperTypeModal').style.display = 'none';
    document.getElementById('bomModal').style.display = 'none';
    document.getElementById('deleteModal').style.display = 'none';
    document.getElementById('uploadModal').style.display = 'none';
  }
});

<?php if ($editSupplier): ?>
window.addEventListener('load', function() {
  editSupplier(<?= (int)$editSupplier['id'] ?>);
});
<?php endif; ?>

<?php if ($editClient): ?>
window.addEventListener('load', function() {
  editClient(<?= (int)$editClient['id'] ?>);
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
