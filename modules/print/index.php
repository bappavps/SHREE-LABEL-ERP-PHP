<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();

// ============================================================
// Auto-create print_templates table
// ============================================================
@$db->query("CREATE TABLE IF NOT EXISTS print_templates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(255) NOT NULL,
  document_type VARCHAR(50) NOT NULL DEFAULT 'Industrial Label',
  paper_width DECIMAL(8,2) NOT NULL DEFAULT 210,
  paper_height DECIMAL(8,2) NOT NULL DEFAULT 297,
  elements LONGTEXT DEFAULT NULL,
  background LONGTEXT DEFAULT NULL,
  thumbnail LONGTEXT DEFAULT NULL,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Add is_system column if missing
try { $db->query("ALTER TABLE print_templates ADD COLUMN is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER is_default"); } catch (Exception $e) {}

// ============================================================
// AJAX Handler (returns JSON, exits early)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax'])) {
  header('Content-Type: application/json');
  if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF mismatch']);
    exit;
  }
  $action = $_POST['action'] ?? '';

  if ($action === 'save_template') {
    $templateId = (int)($_POST['template_id'] ?? 0);
    $data = json_decode($_POST['template_data'] ?? '{}', true);
    if (!$data || empty($data['name'])) {
      echo json_encode(['success' => false, 'error' => 'Invalid template data']);
      exit;
    }
    $name = trim($data['name']);
    $docType = $data['documentType'] ?? 'Industrial Label';
    $paperW = (float)($data['paperWidth'] ?? 210);
    $paperH = (float)($data['paperHeight'] ?? 297);
    $elements = json_encode($data['elements'] ?? []);
    $background = json_encode($data['background'] ?? null);
    $thumbnail = $data['thumbnail'] ?? '';
    $isDefault = (int)($data['isDefault'] ?? 0);

    if ($templateId > 0) {
      $stmt = $db->prepare("UPDATE print_templates SET name=?, document_type=?, paper_width=?, paper_height=?, elements=?, background=?, thumbnail=?, is_default=? WHERE id=?");
      $stmt->bind_param('ssddsssii', $name, $docType, $paperW, $paperH, $elements, $background, $thumbnail, $isDefault, $templateId);
      $stmt->execute();
      echo json_encode(['success' => true, 'id' => $templateId]);
    } else {
      $stmt = $db->prepare("INSERT INTO print_templates (name, document_type, paper_width, paper_height, elements, background, thumbnail, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
      $stmt->bind_param('ssddsssi', $name, $docType, $paperW, $paperH, $elements, $background, $thumbnail, $isDefault);
      $stmt->execute();
      echo json_encode(['success' => true, 'id' => $db->insert_id]);
    }
    exit;
  }

  if ($action === 'delete_template') {
    $id = (int)($_POST['template_id'] ?? 0);
    if ($id > 0) {
      // Prevent deleting system templates
      $chk = $db->prepare("SELECT is_system FROM print_templates WHERE id = ?");
      $chk->bind_param('i', $id);
      $chk->execute();
      $row = $chk->get_result()->fetch_assoc();
      if ($row && !empty($row['is_system'])) {
        echo json_encode(['success' => false, 'error' => 'System templates cannot be deleted']);
        exit;
      }
      $stmt = $db->prepare("DELETE FROM print_templates WHERE id = ? AND (is_system = 0 OR is_system IS NULL)");
      $stmt->bind_param('i', $id);
      $stmt->execute();
      echo json_encode(['success' => true]);
    } else {
      echo json_encode(['success' => false, 'error' => 'Invalid ID']);
    }
    exit;
  }

  echo json_encode(['success' => false, 'error' => 'Unknown action']);
  exit;
}

// ============================================================
// Regular POST Handlers (form submit with redirect)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!verifyCSRF($_POST['csrf_token'] ?? '')) {
    setFlash('error', 'Security token mismatch.');
    redirect(BASE_URL . '/modules/print/index.php');
  }
  $action = $_POST['action'] ?? '';

  if ($action === 'create_template') {
    $name = trim($_POST['template_name'] ?? '');
    $docType = trim($_POST['document_type'] ?? 'Industrial Label');
    $paperSize = $_POST['paper_size'] ?? 'A4';
    $customW = (float)($_POST['custom_width'] ?? 0);
    $customH = (float)($_POST['custom_height'] ?? 0);
    $sizes = ['A4'=>[210,297],'A5'=>[148,210],'Thermal150x100'=>[150,100],'Thermal100x50'=>[100,50]];
    if ($paperSize === 'Custom') { $w = $customW > 0 ? $customW : 100; $h = $customH > 0 ? $customH : 100; }
    else { $d = $sizes[$paperSize] ?? [210,297]; $w = $d[0]; $h = $d[1]; }
    if ($name === '') { setFlash('error', 'Template name is required.'); }
    else {
      $el = '[]';
      $bg = json_encode(['image'=>'','opacity'=>1,'mode'=>'fit','locked'=>true]);
      $stmt = $db->prepare("INSERT INTO print_templates (name, document_type, paper_width, paper_height, elements, background) VALUES (?, ?, ?, ?, ?, ?)");
      $stmt->bind_param('ssddss', $name, $docType, $w, $h, $el, $bg);
      if ($stmt->execute()) {
        setFlash('success', 'Template created.');
        redirect(BASE_URL . '/modules/print/index.php?edit=' . $db->insert_id);
      } else { setFlash('error', 'Error creating template.'); }
    }
    redirect(BASE_URL . '/modules/print/index.php');
  }

  if ($action === 'delete_template') {
    $id = (int)($_POST['template_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("DELETE FROM print_templates WHERE id=? AND (is_system = 0 OR is_system IS NULL)");
      $stmt->bind_param('i',$id);
      if ($stmt->execute() && $stmt->affected_rows > 0) {
        setFlash('success','Template deleted.');
      } else {
        setFlash('error','Cannot delete system-protected templates.');
      }
    }
    redirect(BASE_URL . '/modules/print/index.php');
  }

  if ($action === 'duplicate_template') {
    $id = (int)($_POST['template_id'] ?? 0);
    if ($id > 0) {
      $stmt = $db->prepare("SELECT * FROM print_templates WHERE id=?"); $stmt->bind_param('i',$id); $stmt->execute();
      $tpl = $stmt->get_result()->fetch_assoc();
      if ($tpl) {
        $nn = $tpl['name'].' (Copy)';
        $stmt2 = $db->prepare("INSERT INTO print_templates (name,document_type,paper_width,paper_height,elements,background,is_default) VALUES (?,?,?,?,?,?,0)");
        $stmt2->bind_param('ssddss', $nn, $tpl['document_type'], $tpl['paper_width'], $tpl['paper_height'], $tpl['elements'], $tpl['background']);
        $stmt2->execute(); setFlash('success','Template duplicated.');
      }
    }
    redirect(BASE_URL . '/modules/print/index.php');
  }
}

// ============================================================
// Load Data
// ============================================================
$result = $db->query("SELECT * FROM print_templates ORDER BY document_type, created_at DESC");
$templates = [];
if ($result) { while ($row = $result->fetch_assoc()) $templates[] = $row; }

$autoEditId = (int)($_GET['edit'] ?? 0);
$activeCategory = $_GET['category'] ?? 'All';
$filteredTemplates = $templates;
if ($activeCategory !== 'All') {
  $filteredTemplates = array_filter($templates, function($t) use ($activeCategory) {
    if ($activeCategory === 'Job Cards') return $t['document_type'] === 'Technical Job Card';
    if ($activeCategory === 'Labels') return $t['document_type'] === 'Industrial Label';
    if ($activeCategory === 'Reports') return $t['document_type'] === 'Report';
    if ($activeCategory === 'Billing') return in_array($t['document_type'], ['Tax Invoice','Proforma','Delivery Challan']);
    return true;
  });
}

// Prepare template data for JS (without large thumbnails for gallery load)
$jsTemplates = array_map(function($t) {
  return [
    'id' => (int)$t['id'],
    'name' => $t['name'],
    'documentType' => $t['document_type'],
    'paperWidth' => (float)$t['paper_width'],
    'paperHeight' => (float)$t['paper_height'],
    'elements' => json_decode($t['elements'] ?: '[]', true) ?: [],
    'background' => json_decode($t['background'] ?: '{}', true) ?: ['image'=>'','opacity'=>1],
    'thumbnail' => $t['thumbnail'] ?? '',
    'isDefault' => (bool)$t['is_default'],
    'isSystem' => (bool)($t['is_system'] ?? false),
  ];
}, $templates);

$appSettings = getAppSettings();
$imageLibrary = $appSettings['image_library'] ?? [];
$csrf = generateCSRF();
$pageTitle = 'Print Studio';
include __DIR__ . '/../../includes/header.php';
?>

<!-- ======================== GALLERY VIEW ======================== -->
<div id="ps-gallery">
  <div class="breadcrumb">
    <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Master</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Print Studio</span>
  </div>

  <div class="page-header" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
    <div>
      <h1 style="font-weight:900;text-transform:uppercase;letter-spacing:-.03em">Print Template Studio</h1>
      <p>Industrial document &amp; label designer.</p>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <label for="ps-import-file" class="btn btn-sm" style="border:2px solid #e2e8f0;font-weight:700;font-size:.75rem;cursor:pointer;display:flex;align-items:center;gap:6px">
        <i class="bi bi-file-earmark-arrow-up"></i> Import
      </label>
      <input type="file" id="ps-import-file" accept=".json" style="display:none" onchange="importTemplate(this)">
      <button class="btn btn-sm btn-primary" style="font-weight:800;padding:10px 20px" onclick="document.getElementById('ps-create-modal').style.display='flex'">
        <i class="bi bi-plus-lg"></i> Create Design
      </button>
    </div>
  </div>

  <!-- Category Tabs -->
  <div style="display:flex;gap:4px;margin-bottom:24px;background:#f1f5f9;padding:4px;border-radius:8px;width:fit-content;flex-wrap:wrap">
    <?php foreach (['All','Job Cards','Labels','Reports','Billing'] as $cat): ?>
      <a href="?category=<?= urlencode($cat) ?>" style="padding:8px 18px;border-radius:6px;text-decoration:none;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;transition:all .2s;<?= $activeCategory===$cat ? 'background:white;color:#0f172a;box-shadow:0 1px 3px rgba(0,0,0,.1)' : 'color:#64748b' ?>"><?= e($cat) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Template Cards Grid -->
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:20px">
    <?php if (empty($filteredTemplates)): ?>
      <div style="grid-column:1/-1;text-align:center;padding:80px 20px;color:#94a3b8">
        <i class="bi bi-palette" style="font-size:3.5rem;opacity:.2"></i>
        <p style="margin-top:16px;font-weight:600">No templates yet. Click <strong>"Create Design"</strong> to get started.</p>
      </div>
    <?php else: ?>
      <?php foreach ($filteredTemplates as $tpl): ?>
        <div class="card ps-tpl-card" style="overflow:hidden;border:2px solid transparent;transition:all .2s;cursor:default">
          <div style="background:#f1f5f9;aspect-ratio:3/4;position:relative;display:flex;align-items:center;justify-content:center;overflow:hidden">
            <?php if (!empty($tpl['thumbnail'])): ?>
              <img src="<?= e($tpl['thumbnail']) ?>" style="width:100%;height:100%;object-fit:contain" alt="Preview">
            <?php else: ?>
              <div style="text-align:center;opacity:.25"><i class="bi bi-file-earmark-text" style="font-size:2.5rem"></i><p style="font-size:.7rem;margin-top:8px;font-weight:700;text-transform:uppercase">No Preview</p></div>
            <?php endif; ?>
            <div class="ps-card-overlay">
              <button class="btn btn-sm" style="background:white;color:#0f172a;width:100%;font-weight:700;font-size:.8rem" onclick="openEditor(<?= $tpl['id'] ?>)"><i class="bi bi-pencil-square"></i> Edit Layout</button>
              <div style="display:flex;gap:6px;width:100%">
                <form method="POST" style="flex:1"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="duplicate_template"><input type="hidden" name="template_id" value="<?= $tpl['id'] ?>"><button type="submit" class="btn btn-xs" style="background:rgba(255,255,255,.15);color:white;border:1px solid rgba(255,255,255,.25);width:100%" title="Duplicate"><i class="bi bi-copy"></i></button></form>
                <button class="btn btn-xs" style="background:rgba(255,255,255,.15);color:white;border:1px solid rgba(255,255,255,.25);flex:1" title="Export JSON" onclick="exportTemplate(<?= $tpl['id'] ?>)"><i class="bi bi-download"></i></button>
                <button class="btn btn-xs" style="background:#ef4444;color:white;flex:1" title="Delete" onclick="confirmDeleteTpl(<?= $tpl['id'] ?>)"><i class="bi bi-trash"></i></button>
              </div>
            </div>
          </div>
          <div style="padding:12px;border-top:1px solid #e2e8f0">
            <div style="font-size:.8rem;font-weight:800;text-transform:uppercase;letter-spacing:-.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($tpl['name']) ?></div>
            <div style="font-size:.7rem;color:#6366f1;font-weight:700;text-transform:uppercase"><?= e($tpl['document_type']) ?></div>
            <div style="font-size:.65rem;color:#94a3b8;margin-top:2px"><?= e($tpl['paper_width']) ?> &times; <?= e($tpl['paper_height']) ?>mm</div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ======================== CREATE MODAL ======================== -->
<div id="ps-create-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center">
  <div style="width:92%;max-width:420px;background:white;border-radius:12px;padding:24px;box-shadow:0 25px 50px rgba(0,0,0,.25)">
    <h3 style="margin:0 0 20px;font-weight:800;text-transform:uppercase;font-size:1.05rem;display:flex;align-items:center;gap:8px"><i class="bi bi-plus-lg" style="color:#6366f1"></i> Create Design</h3>
    <form method="POST">
      <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
      <input type="hidden" name="action" value="create_template">
      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:4px;font-size:10px;font-weight:700;text-transform:uppercase;opacity:.5">Template Name</label>
        <input type="text" name="template_name" required placeholder="e.g. Industrial Label v1" style="width:100%;padding:10px;border:2px solid #e2e8f0;border-radius:8px;font-weight:600;font-size:.9rem">
      </div>
      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:4px;font-size:10px;font-weight:700;text-transform:uppercase;opacity:.5">Document Type</label>
        <select name="document_type" required style="width:100%;padding:10px;border:2px solid #e2e8f0;border-radius:8px;font-weight:600">
          <option value="Tax Invoice">Tax Invoice</option>
          <option value="Technical Job Card">Technical Job Card</option>
          <option value="Industrial Label" selected>Industrial Label</option>
          <option value="Delivery Challan">Delivery Challan</option>
          <option value="Purchase Order">Purchase Order</option>
          <option value="Proforma">Proforma Invoice</option>
          <option value="Report">Report / Audit Sheet</option>
        </select>
      </div>
      <div style="margin-bottom:16px">
        <label style="display:block;margin-bottom:4px;font-size:10px;font-weight:700;text-transform:uppercase;opacity:.5">Paper Size</label>
        <select name="paper_size" id="ps-paper-size" onchange="psUpdatePaperFields()" style="width:100%;padding:10px;border:2px solid #e2e8f0;border-radius:8px;font-weight:600">
          <option value="A4">A4 Paper (210×297mm)</option>
          <option value="A5">A5 Paper (148×210mm)</option>
          <option value="Thermal150x100" selected>Label 150×100mm</option>
          <option value="Thermal100x50">Label 100×50mm</option>
          <option value="Custom">Custom Size</option>
        </select>
      </div>
      <div id="ps-custom-size" style="margin-bottom:16px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px">
          <div><label style="display:block;margin-bottom:4px;font-size:9px;font-weight:700;text-transform:uppercase;color:#94a3b8">Width (mm)</label><input type="number" name="custom_width" id="ps-cw" value="150" readonly style="width:100%;padding:8px;border:2px solid #e2e8f0;border-radius:8px;font-weight:600;opacity:.6"></div>
          <div><label style="display:block;margin-bottom:4px;font-size:9px;font-weight:700;text-transform:uppercase;color:#94a3b8">Height (mm)</label><input type="number" name="custom_height" id="ps-ch" value="100" readonly style="width:100%;padding:8px;border:2px solid #e2e8f0;border-radius:8px;font-weight:600;opacity:.6"></div>
        </div>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('ps-create-modal').style.display='none'">Cancel</button>
        <button type="submit" class="btn btn-primary" style="font-weight:800;padding:10px 28px">Start Designing</button>
      </div>
    </form>
  </div>
</div>

<!-- ======================== DELETE MODAL ======================== -->
<div id="ps-delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10000;align-items:center;justify-content:center">
  <div style="width:92%;max-width:400px;background:white;border-radius:12px;padding:24px;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,.25)">
    <h3 style="font-size:1.1rem;font-weight:700;margin:0 0 8px">Delete Template?</h3>
    <p style="color:#64748b;margin:0 0 20px;font-size:.9rem">This action is permanent and cannot be undone.</p>
    <div style="display:flex;gap:8px;justify-content:center">
      <button class="btn btn-secondary" onclick="document.getElementById('ps-delete-modal').style.display='none'">Cancel</button>
      <form method="POST" style="display:inline"><input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" id="ps-delete-id" value=""><button type="submit" class="btn btn-danger">Delete</button></form>
    </div>
  </div>
</div>

<!-- ======================== IMAGE LIBRARY MODAL ======================== -->
<div id="ps-library-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10001;align-items:center;justify-content:center">
  <div style="width:92%;max-width:640px;max-height:80vh;background:white;border-radius:12px;padding:24px;box-shadow:0 25px 50px rgba(0,0,0,.25);display:flex;flex-direction:column">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-shrink:0">
      <h3 style="margin:0;font-weight:800;text-transform:uppercase;font-size:1rem;display:flex;align-items:center;gap:8px"><i class="bi bi-images" style="color:#6366f1"></i> Image Library</h3>
      <button class="btn btn-sm btn-ghost" onclick="document.getElementById('ps-library-modal').style.display='none'"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="ps-library-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;overflow-y:auto;flex:1"></div>
  </div>
</div>

<!-- ======================== FULL-SCREEN EDITOR ======================== -->
<div id="ps-editor" style="display:none;position:fixed;inset:0;z-index:9999;background:#f1f5f9;flex-direction:column;font-family:Inter,sans-serif">
  <!-- Top Toolbar -->
  <div style="height:56px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;padding:0 16px;background:white;box-shadow:0 1px 3px rgba(0,0,0,.05);flex-shrink:0">
    <div style="display:flex;align-items:center;gap:12px">
      <button class="btn btn-sm btn-ghost" onclick="closeEditor()" style="font-weight:700"><i class="bi bi-arrow-left"></i> Exit</button>
      <div style="width:1px;height:24px;background:#e2e8f0"></div>
      <div>
        <div id="ps-editor-name" style="font-size:.85rem;font-weight:800;text-transform:uppercase;letter-spacing:-.02em;line-height:1.2"></div>
        <div id="ps-editor-info" style="font-size:.65rem;color:#94a3b8;font-weight:600;letter-spacing:.03em"></div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:6px">
      <div style="display:flex;align-items:center;background:#f1f5f9;padding:2px;border-radius:8px;gap:2px;margin-right:8px">
        <button class="btn btn-xs btn-ghost" onclick="psSetZoom(Math.max(0.2,psZoom-0.1))"><i class="bi bi-dash"></i></button>
        <button class="btn btn-xs btn-ghost" onclick="psSetZoom(1)" style="font-size:10px;font-weight:700;min-width:48px" id="ps-zoom-display">100%</button>
        <button class="btn btn-xs btn-ghost" onclick="psSetZoom(Math.min(3,psZoom+0.1))"><i class="bi bi-plus"></i></button>
        <div style="width:1px;height:14px;background:#d1d5db;margin:0 2px"></div>
        <button class="btn btn-xs" id="ps-snap-btn" onclick="psToggleSnap()" title="Grid Snap" style="color:#6366f1"><i class="bi bi-magnet"></i></button>
        <button class="btn btn-xs" id="ps-grid-btn" onclick="psToggleGrid()" title="Guidelines" style="color:#6366f1"><i class="bi bi-grid-3x3"></i></button>
      </div>
      <button class="btn btn-sm" style="border:1px solid #e2e8f0;font-weight:700;font-size:.72rem" onclick="psExportCurrent()"><i class="bi bi-download"></i> Export</button>
      <button class="btn btn-sm" style="border:1px solid #e2e8f0;font-weight:700;font-size:.72rem" id="ps-print-btn" onclick="psPrint()"><i class="bi bi-printer"></i> Print</button>
      <button class="btn btn-sm btn-primary" style="font-weight:800;padding:8px 20px" id="ps-save-btn" onclick="psSave()"><i class="bi bi-save"></i> Save</button>
    </div>
  </div>

  <!-- Editor Body: 3 Panels -->
  <div style="flex:1;display:flex;overflow:hidden">
    <!-- LEFT SIDEBAR: Components + ERP Fields -->
    <div style="width:256px;border-right:1px solid #e2e8f0;overflow-y:auto;background:#f8fafc;flex-shrink:0;padding:20px" id="ps-left-sidebar">
      <div style="margin-bottom:28px">
        <label style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#6366f1;display:block;margin-bottom:12px">Components</label>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px" id="ps-components"></div>
      </div>
      <div>
        <label style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#6366f1;display:block;margin-bottom:12px">ERP Field Directory</label>
        <div id="ps-erp-fields" style="background:white;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden"></div>
      </div>
    </div>

    <!-- CENTER CANVAS -->
    <div style="flex:1;background:#cbd5e1;overflow:auto;display:flex;align-items:flex-start;justify-content:center;padding:60px;position:relative" id="ps-canvas-area" onclick="psDeselect(event)">
      <div id="studio-canvas" style="background:white;position:relative;overflow:hidden;box-shadow:0 25px 50px rgba(0,0,0,.15);outline:2px solid #fbbf24;transform-origin:top center">
        <div id="canvas-bg" style="position:absolute;inset:0;pointer-events:none;z-index:0;display:none"></div>
        <div id="canvas-grid" style="position:absolute;inset:0;pointer-events:none;z-index:5;opacity:.05;background-image:linear-gradient(#000 1px,transparent 1px),linear-gradient(90deg,#000 1px,transparent 1px);background-size:20px 20px"></div>
        <div id="canvas-elements" style="position:relative;z-index:10;width:100%;height:100%"></div>
      </div>
    </div>

    <!-- RIGHT SIDEBAR: Properties -->
    <div style="width:296px;border-left:1px solid #e2e8f0;overflow-y:auto;background:white;flex-shrink:0" id="ps-properties"></div>
  </div>
</div>

<!-- Toast -->
<div id="ps-toast" class="ps-toast"></div>

<!-- ======================== STYLES ======================== -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Roboto:wght@400;500;700;900&family=Oswald:wght@400;500;600;700&family=Poppins:wght@400;500;600;700;800;900&family=Montserrat:wght@400;500;600;700;800;900&family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
.ps-tpl-card:hover { border-color:#6366f1!important; }
.ps-card-overlay { position:absolute;inset:0;background:rgba(99,102,241,.92);opacity:0;transition:opacity .25s;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;padding:20px; }
.ps-tpl-card:hover .ps-card-overlay { opacity:1; }
.ps-toast { position:fixed;bottom:20px;left:50%;transform:translateX(-50%) translateY(100px);background:#1e293b;color:white;padding:12px 24px;border-radius:8px;font-size:.85rem;font-weight:600;z-index:99999;transition:transform .3s ease;pointer-events:none;white-space:nowrap; }
.ps-toast-show { transform:translateX(-50%) translateY(0)!important; }
.ps-toast-success { background:#16a34a; }
.ps-toast-error { background:#dc2626; }
.canvas-element { transition:box-shadow .15s; }
.canvas-element:hover { box-shadow:0 0 0 1px rgba(99,102,241,.3); }
.canvas-element.selected { box-shadow:0 0 0 2px #6366f1,0 0 0 4px rgba(99,102,241,.2)!important;z-index:60!important; }
.resize-handle { position:absolute;bottom:-5px;right:-5px;width:12px;height:12px;background:#6366f1;cursor:nwse-resize;border-radius:50%;border:2px solid white;box-shadow:0 1px 3px rgba(0,0,0,.3);z-index:70; }
.element-lock-badge { position:absolute;top:-8px;right:-8px;z-index:70;background:#1e293b;color:white;border-radius:50%;width:16px;height:16px;display:flex;align-items:center;justify-content:center;font-size:8px;box-shadow:0 1px 3px rgba(0,0,0,.3); }
.ps-prop-input { width:100%;padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;font-size:.8rem;font-weight:500;font-family:inherit;margin-top:4px; }
.ps-prop-input:focus { outline:none;border-color:#6366f1;box-shadow:0 0 0 2px rgba(99,102,241,.15); }
select.ps-prop-input { appearance:auto; }
textarea.ps-prop-input { resize:vertical;min-height:50px; }
.ps-comp-btn { display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;padding:12px 8px;background:white;border:2px solid #f1f5f9;border-radius:10px;cursor:pointer;transition:all .15s;font-size:0;line-height:1; }
.ps-comp-btn:hover { border-color:rgba(99,102,241,.3);background:rgba(99,102,241,.03); }
.ps-comp-btn:active { transform:scale(.95); }
.ps-comp-btn i { font-size:1.1rem;color:#94a3b8;transition:color .15s; }
.ps-comp-btn:hover i { color:#6366f1; }
.ps-comp-btn span { font-size:9px;font-weight:800;text-transform:uppercase;color:#64748b;letter-spacing:-.02em; }
.ps-comp-btn:hover span { color:#6366f1; }
.ps-field-btn { width:100%;display:flex;align-items:center;gap:8px;padding:6px 10px;border:none;background:none;cursor:pointer;border-radius:6px;transition:background .15s;text-align:left; }
.ps-field-btn:hover { background:rgba(99,102,241,.06); }
.ps-field-btn i { font-size:.75rem;color:#cbd5e1; }
.ps-field-btn:hover i { color:#6366f1; }
.ps-field-btn span { font-size:10px;font-weight:700;text-transform:uppercase;color:#64748b; }
.ps-field-btn:hover span { color:#6366f1; }
.ps-field-code-btn { width:100%;display:flex;flex-direction:column;align-items:flex-start;gap:3px;padding:8px 10px;border:none;background:none;cursor:pointer;border-radius:8px;transition:background .15s;text-align:left; }
.ps-field-code-btn:hover { background:rgba(99,102,241,.06); }
.ps-field-code-label { font-size:9px;font-weight:700;text-transform:uppercase;color:#64748b; }
.ps-field-code-value { font-size:10px;font-weight:700;color:#0f172a;background:#eef2ff;padding:2px 6px;border-radius:5px;word-break:break-all; }
.ps-field-code-preview { font-size:9px;color:#94a3b8; }
@media print { #ps-editor, #ps-gallery, .ps-toast, #ps-create-modal, #ps-delete-modal { display:none!important; } }
</style>

<!-- ======================== CDN SCRIPTS ======================== -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>

<script>
// ============================================================
// PRINT STUDIO ENGINE
// ============================================================
const MM_TO_PX = 3.78;
const CSRF_TOKEN = '<?= e($csrf) ?>';
const PAGE_URL = '<?= BASE_URL ?>/modules/print/index.php';
const AUTO_EDIT_ID = <?= $autoEditId ?>;
const TEMPLATES_DATA = <?= json_encode($jsTemplates, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const IMAGE_LIBRARY = <?= json_encode(array_map(function($img) { return ['path' => BASE_URL . '/' . ltrim((string)($img['path'] ?? ''), '/'), 'name' => (string)($img['name'] ?? ''), 'category' => (string)($img['category'] ?? 'misc')]; }, $imageLibrary), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const FONT_FAMILIES = [
  { id:'inter', name:'Inter (Sans)', value:"'Inter', sans-serif" },
  { id:'roboto', name:'Roboto', value:"'Roboto', sans-serif" },
  { id:'montserrat', name:'Montserrat', value:"'Montserrat', sans-serif" },
  { id:'poppins', name:'Poppins', value:"'Poppins', sans-serif" },
  { id:'oswald', name:'Oswald', value:"'Oswald', sans-serif" },
  { id:'open-sans', name:'Open Sans', value:"'Open Sans', sans-serif" },
  { id:'arial', name:'Arial', value:'Arial, sans-serif' },
  { id:'helvetica', name:'Helvetica', value:'Helvetica, sans-serif' },
  { id:'mono', name:'Monospace', value:"ui-monospace, SFMono-Regular, 'Courier New', monospace" },
  { id:'georgia', name:'Georgia', value:'Georgia, serif' },
  { id:'times', name:'Times New Roman', value:"'Times New Roman', serif" },
  { id:'verdana', name:'Verdana', value:'Verdana, sans-serif' }
];
function getFontValue(id) {
  if (!id) return 'sans-serif';
  // Already a CSS value (has comma or quotes)? Use as-is
  if (id.indexOf(',') !== -1 || id.indexOf("'") !== -1) return id;
  var f = FONT_FAMILIES.find(function(ff){ return ff.id === id; });
  return f ? f.value : ("'" + id + "', sans-serif");
}
function getFontId(val) {
  if (!val) return 'inter';
  var f = FONT_FAMILIES.find(function(ff){ return ff.id === val || ff.value === val; });
  return f ? f.id : 'inter';
}

const PLACEHOLDERS = {
  GENERAL: [
    { key:'{{job.companyName}}', label:'Company Name', icon:'bi-building', preview:'Shree Label Creation' },
    { key:'{{job.companyAddress}}', label:'Company Address', icon:'bi-file-text', preview:'Phase 1, Industrial Estate' },
    { key:'{{job.date}}', label:'Current Print Date', icon:'bi-calendar3', preview:'3/30/2026' },
    { key:'{{received_date}}', label:'Received Date', icon:'bi-calendar-event', preview:'30 Mar 2026' },
    { key:'{{job.receivedDate}}', label:'Received Date Slash', icon:'bi-calendar-event', preview:'3/29/2026' },
    { key:'{{print_date}}', label:'Print Date Slash', icon:'bi-calendar3', preview:'3/30/2026' },
    { key:'{{today_date}}', label:'Print Date Text', icon:'bi-calendar3', preview:'30 Mar 2026' },
    { key:'{{job.date_ddmmyyyy}}', label:'Print Date DD/MM/YYYY', icon:'bi-calendar3', preview:'30/03/2026' },
    { key:'{{job.date_yyyymmdd}}', label:'Print Date YYYY-MM-DD', icon:'bi-calendar3', preview:'2026-03-30' },
    { key:'{{received_date_ddmmyyyy}}', label:'Received Date DD/MM/YYYY', icon:'bi-calendar-event', preview:'29/03/2026' },
    { key:'{{received_date_yyyymmdd}}', label:'Received Date YYYY-MM-DD', icon:'bi-calendar-event', preview:'2026-03-29' }
  ],
  INVENTORY: [
    { key:'{{roll_no}}', label:'Roll Number', icon:'bi-box', preview:'T-1038-A' },
    { key:'{{paper_type}}', label:'Paper Type', icon:'bi-file-text', preview:'Chromo' },
    { key:'{{paper_company}}', label:'Paper Company', icon:'bi-building', preview:'Avery Dennison' },
    { key:'{{width}}', label:'Width (MM)', icon:'bi-arrows-expand', preview:'1020' },
    { key:'{{length}}', label:'Length (MTR)', icon:'bi-arrow-left-right', preview:'3000' },
    { key:'{{gsm}}', label:'GSM', icon:'bi-layers', preview:'80' },
    { key:'{{weight}}', label:'Weight (KG)', icon:'bi-speedometer2', preview:'245' },
    { key:'{{sqm}}', label:'Sq. Mtr', icon:'bi-aspect-ratio', preview:'306.00' },
    { key:'{{roll_url}}', label:'QR URL', icon:'bi-qr-code', preview:'https://erp.shreelabel.com/roll/T-1038-A' }
  ],
  PRODUCTION: [
    { key:'{{job.batchId}}', label:'Batch/Job ID', icon:'bi-hash', preview:'JJC-T1001-001' },
    { key:'{{job.machineId}}', label:'Machine Name', icon:'bi-gear', preview:'Jumbo Slitter A1' },
    { key:'{{job.operator}}', label:'Operator', icon:'bi-person', preview:'Mriganka Debnath' },
    { key:'{{sourceMaterials}}', label:'Source Material Grid', icon:'bi-grid-3x3', preview:'TABLE' },
    { key:'{{slittingOutputs}}', label:'Slitting Output Grid', icon:'bi-grid-3x3', preview:'TABLE' }
  ]
};

const DATE_FORMAT_OPTIONS = [
  { value:'{{job.date}}', label:'Current print date', preview:'3/30/2026' },
  { value:'{{today_date}}', label:'Current print date text', preview:'30 Mar 2026' },
  { value:'{{job.date_ddmmyyyy}}', label:'Current print date DD/MM/YYYY', preview:'30/03/2026' },
  { value:'{{job.date_yyyymmdd}}', label:'Current print date YYYY-MM-DD', preview:'2026-03-30' },
  { value:'{{received_date}}', label:'Received date text', preview:'30 Mar 2026' },
  { value:'{{job.receivedDate}}', label:'Received date slash', preview:'3/29/2026' },
  { value:'{{received_date_ddmmyyyy}}', label:'Received date DD/MM/YYYY', preview:'29/03/2026' },
  { value:'{{received_date_yyyymmdd}}', label:'Received date YYYY-MM-DD', preview:'2026-03-29' }
];

const JOB_CARD_VARIABLE_GROUPS = {
  CORE: [
    '{{job.batchId}}',
    '{{job.machineId}}',
    '{{job.operator}}',
    '{{job.jobNo}}',
    '{{job.type}}',
    '{{job.status}}',
    '{{job.notes}}'
  ],
  PLANNING: [
    '{{job.planningId}}',
    '{{job.planningJobName}}',
    '{{job.planningStatus}}',
    '{{job.planningPriority}}',
    '{{job.planningDate}}',
    '{{job.planningDie}}',
    '{{job.planningPlateNo}}',
    '{{job.planningLabelSize}}',
    '{{job.planningRepeatMm}}',
    '{{job.planningDirection}}',
    '{{job.planningOrderMtr}}',
    '{{job.planningOrderQty}}',
    '{{job.planningCoreSize}}',
    '{{job.planningQtyPerRoll}}',
    '{{job.planningMaterial}}',
    '{{job.planningPaperSize}}',
    '{{job.planningRemarks}}',
    '{{job.planningDispatchDate}}'
  ],
  MATERIAL: [
    '{{job.rollNo}}',
    '{{job.paperType}}',
    '{{job.paperCompany}}',
    '{{job.width}}',
    '{{job.length}}',
    '{{job.gsm}}',
    '{{job.weight}}',
    '{{job.sqm}}',
    '{{job.lotBatchNo}}'
  ],
  PRODUCTION: [
    '{{sourceMaterials}}',
    '{{slittingOutputs}}',
    '{{job.previousJobNo}}',
    '{{job.previousJobStatus}}',
    '{{job.department}}',
    '{{job.durationMinutes}}',
    '{{job.startedAt}}',
    '{{job.completedAt}}'
  ]
};

// Build preview data map
const PREVIEW_DATA = {};
Object.values(PLACEHOLDERS).flat().forEach(p => { PREVIEW_DATA[p.key.replace(/[{}]/g,'')] = p.preview; });
Object.values(JOB_CARD_VARIABLE_GROUPS).flat().forEach(function(code) {
  const key = code.replace(/[{}]/g, '');
  if (!(key in PREVIEW_DATA)) PREVIEW_DATA[key] = key;
});

// ============================================================
// STATE
// ============================================================
let currentTemplate = null;
let selectedElementId = null;
let psZoom = 1;
let psGridSnap = 5;
let psShowGrid = true;

// ============================================================
// HELPERS
// ============================================================
function psUuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  return 'xxxx-xxxx-4xxx-yxxx'.replace(/[xy]/g, c => { const r = Math.random()*16|0; return (c==='x'?r:(r&0x3|0x8)).toString(16); });
}
function psEscHtml(s) { const d=document.createElement('div'); d.textContent=s; return d.innerHTML; }
function psProcessText(text) { if(!text) return ''; return text.replace(/\{\{(.+?)\}\}/g, (m,k) => PREVIEW_DATA[k.trim()]||m); }
function psInsertDateVariable(code) {
  psAddElement('text', null, code);
}

function psInsertVariableCode(code) {
  const normalized = String(code || '').trim();
  if (!normalized) return;
  if (normalized === '{{sourceMaterials}}' || normalized === '{{slittingOutputs}}') {
    psAddElement('table', normalized.replace(/[{}]/g,''));
    return;
  }
  psAddElement('text', null, normalized);
}

function psToast(msg, type) {
  const t = document.getElementById('ps-toast');
  t.textContent = msg;
  t.className = 'ps-toast ps-toast-' + (type||'info') + ' ps-toast-show';
  clearTimeout(t._timer);
  t._timer = setTimeout(() => t.className = 'ps-toast', 3000);
}

// Optimized slider handlers (skip props panel re-render for smooth dragging)
function psSliderProp(id, prop, val) {
  const el = currentTemplate.elements.find(e => e.id === id);
  if (!el) return;
  el[prop] = val;
  const lbl = document.getElementById('ps-lbl-'+prop);
  if (lbl) lbl.textContent = val + (prop==='rotate'?'\u00B0':'');
  psRenderCanvas();
}
function psSliderStyle(id, prop, val) {
  const el = currentTemplate.elements.find(e => e.id === id);
  if (!el || !el.style) return;
  el.style[prop] = val;
  const lbl = document.getElementById('ps-lbl-'+prop);
  if (lbl) lbl.textContent = Math.round(val*100)+'%';
  psRenderCanvas();
}
function psSliderBgOpacity(val) {
  if (!currentTemplate || !currentTemplate.background) return;
  currentTemplate.background.opacity = val;
  const lbl = document.getElementById('ps-lbl-bg-opacity');
  if (lbl) lbl.textContent = Math.round(val*100)+'%';
  psRenderCanvas();
}

// Paper size field sync
function psUpdatePaperFields() {
  const sel = document.getElementById('ps-paper-size');
  const cw = document.getElementById('ps-cw');
  const ch = document.getElementById('ps-ch');
  if (!sel||!cw||!ch) return;
  const sizes = {A4:[210,297],A5:[148,210],Thermal150x100:[150,100],Thermal100x50:[100,50]};
  const isCustom = sel.value === 'Custom';
  if (!isCustom && sizes[sel.value]) { cw.value = sizes[sel.value][0]; ch.value = sizes[sel.value][1]; }
  cw.readOnly = !isCustom; ch.readOnly = !isCustom;
  cw.style.opacity = isCustom ? '1' : '.6'; ch.style.opacity = isCustom ? '1' : '.6';
}

// Image Library browser
function psOpenLibrary(target, elId) {
  const modal = document.getElementById('ps-library-modal');
  if (!modal) return;
  modal.dataset.target = target || '';
  modal.dataset.elId = elId || '';
  const grid = document.getElementById('ps-library-grid');
  let h = '';
  if (!IMAGE_LIBRARY || IMAGE_LIBRARY.length === 0) {
    h = '<div style="grid-column:1/-1;text-align:center;padding:40px 20px;color:#94a3b8"><i class="bi bi-images" style="font-size:2rem;opacity:.3;display:block;margin-bottom:12px"></i><p style="font-weight:600;font-size:.85rem">No images in library.</p><p style="font-size:.75rem">Upload images in <strong>Settings \u2192 Image Library</strong>.</p></div>';
  } else {
    IMAGE_LIBRARY.forEach(function(img, idx) {
      h += '<div style="cursor:pointer;border:2px solid #e2e8f0;border-radius:10px;overflow:hidden;transition:all .15s" onmouseover="this.style.borderColor=\'#6366f1\'" onmouseout="this.style.borderColor=\'#e2e8f0\'" onclick="psPickLibraryImage('+idx+')">';
      h += '<div style="aspect-ratio:1;background:#f8fafc;overflow:hidden"><img src="'+psEscHtml(img.path)+'" style="width:100%;height:100%;object-fit:cover" alt=""></div>';
      h += '<div style="padding:6px 8px;font-size:9px;font-weight:700;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#64748b">'+psEscHtml(img.name)+'</div>';
      h += '</div>';
    });
  }
  grid.innerHTML = h;
  modal.style.display = 'flex';
}
function psPickLibraryImage(idx) {
  const img = IMAGE_LIBRARY[idx];
  if (!img) return;
  const modal = document.getElementById('ps-library-modal');
  const target = modal.dataset.target;
  const elId = modal.dataset.elId;
  if (target === 'background') {
    currentTemplate.background.image = img.path;
    psRenderCanvas(); psRenderProps();
  } else if (target === 'element' && elId) {
    psUpdateProp(elId, 'content', img.path);
  }
  modal.style.display = 'none';
  psToast('Image applied from library', 'success');
}

// ============================================================
// GALLERY FUNCTIONS
// ============================================================
function confirmDeleteTpl(id) {
  document.getElementById('ps-delete-id').value = id;
  document.getElementById('ps-delete-modal').style.display = 'flex';
}

function exportTemplate(id) {
  const tpl = TEMPLATES_DATA.find(t => t.id === id);
  if (!tpl) return;
  const blob = new Blob([JSON.stringify(tpl, null, 2)], { type:'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = tpl.name.replace(/\s+/g,'_') + '_template.json';
  a.click();
  URL.revokeObjectURL(a.href);
  psToast('Template exported', 'success');
}

function importTemplate(input) {
  const file = input.files[0];
  if (!file) return;
  if (file.size > 2*1024*1024) { psToast('File too large (max 2MB)','error'); input.value=''; return; }
  const reader = new FileReader();
  reader.onload = async function(evt) {
    try {
      const json = JSON.parse(evt.target.result);
      if (!json.name || !json.elements) throw new Error('Invalid template structure');
      json.name = (json.name || 'Imported') + ' (Imported)';
      json.isDefault = false;
      json.thumbnail = '';
      const fd = new FormData();
      fd.append('ajax','1'); fd.append('csrf_token', CSRF_TOKEN);
      fd.append('action','save_template'); fd.append('template_id','0');
      fd.append('template_data', JSON.stringify(json));
      const resp = await fetch(PAGE_URL, { method:'POST', body:fd });
      const result = await resp.json();
      if (result.success) { psToast('Template imported!','success'); setTimeout(()=>location.reload(),500); }
      else psToast(result.error||'Import failed','error');
    } catch(e) { psToast('Invalid JSON file','error'); }
    input.value = '';
  };
  reader.readAsText(file);
}

// ============================================================
// EDITOR: OPEN / CLOSE
// ============================================================
function openEditor(templateId) {
  const tpl = TEMPLATES_DATA.find(t => t.id === templateId);
  if (!tpl) return;
  currentTemplate = JSON.parse(JSON.stringify(tpl));
  if (typeof currentTemplate.elements === 'string') currentTemplate.elements = JSON.parse(currentTemplate.elements) || [];
  if (typeof currentTemplate.background === 'string') currentTemplate.background = JSON.parse(currentTemplate.background) || {image:'',opacity:1};
  if (!currentTemplate.background) currentTemplate.background = {image:'',opacity:1};
  selectedElementId = null;
  psZoom = 1;
  document.getElementById('ps-gallery').style.display = 'none';
  document.getElementById('ps-editor').style.display = 'flex';
  document.getElementById('ps-editor-name').textContent = currentTemplate.name;
  document.getElementById('ps-editor-info').textContent = currentTemplate.documentType + ' \u2022 ' + currentTemplate.paperWidth + '\u00D7' + currentTemplate.paperHeight + 'mm';
  psRenderComponents();
  psRenderErpFields();
  psRenderCanvas();
  psRenderProps();
  psUpdateZoomDisplay();
}

function closeEditor() {
  document.getElementById('ps-editor').style.display = 'none';
  document.getElementById('ps-gallery').style.display = 'block';
  currentTemplate = null;
  selectedElementId = null;
}

// ============================================================
// ZOOM / GRID / SNAP
// ============================================================
function psSetZoom(v) { psZoom = Math.round(v*100)/100; psUpdateZoomDisplay(); psRenderCanvas(); }
function psUpdateZoomDisplay() { document.getElementById('ps-zoom-display').textContent = Math.round(psZoom*100)+'%'; }
function psToggleSnap() { psGridSnap = psGridSnap > 0 ? 0 : 5; document.getElementById('ps-snap-btn').style.color = psGridSnap > 0 ? '#6366f1' : '#94a3b8'; }
function psToggleGrid() { psShowGrid = !psShowGrid; document.getElementById('ps-grid-btn').style.color = psShowGrid ? '#6366f1' : '#94a3b8'; psRenderCanvas(); }
function psDeselect(e) { if (e.target.id === 'ps-canvas-area' || e.target.id === 'studio-canvas' || e.target.id === 'canvas-elements' || e.target.id === 'canvas-grid') { selectedElementId = null; psRenderCanvas(); psRenderProps(); } }

// ============================================================
// LEFT SIDEBAR: COMPONENTS
// ============================================================
function psRenderComponents() {
  const comps = [
    { type:'text', icon:'bi-type', label:'Text' },
    { type:'image', icon:'bi-image', label:'Image' },
    { type:'rectangle', icon:'bi-square', label:'Shape' },
    { type:'line', icon:'bi-dash-lg', label:'Divider' },
    { type:'barcode', icon:'bi-upc-scan', label:'Barcode' },
    { type:'qr', icon:'bi-qr-code', label:'QR Code' },
    { type:'circle', icon:'bi-circle', label:'Circle' },
    { type:'table', icon:'bi-table', label:'Table' }
  ];
  const c = document.getElementById('ps-components');
  c.innerHTML = comps.map(x => '<button class="ps-comp-btn" onclick="psAddElement(\''+x.type+'\')"><i class="bi '+x.icon+'"></i><span>'+x.label+'</span></button>').join('');
}

function psRenderErpFields() {
  const c = document.getElementById('ps-erp-fields');
  let html = '';
  html += '<div style="padding:10px 10px 6px;border-bottom:1px solid #e2e8f0;background:#f8fafc">';
  html += '<p style="font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px">Date Format Picker</p>';
  html += '<div style="display:grid;gap:6px">';
  DATE_FORMAT_OPTIONS.forEach(function(opt) {
    html += '<button class="ps-field-code-btn" onclick="psInsertDateVariable(\''+psEscHtml(opt.value)+'\')">';
    html += '<span class="ps-field-code-label">'+psEscHtml(opt.label)+'</span>';
    html += '<code class="ps-field-code-value">'+psEscHtml(opt.value)+'</code>';
    html += '<span class="ps-field-code-preview">'+psEscHtml(opt.preview)+'</span>';
    html += '</button>';
  });
  html += '</div></div>';
  Object.entries(PLACEHOLDERS).forEach(([group, fields]) => {
    html += '<div style="padding:8px"><p style="font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;padding:0 6px;margin:0 0 4px">'+group+'</p>';
    fields.forEach(f => {
      const isTable = f.preview === 'TABLE';
      const onclick = isTable ? "psAddElement('table','"+f.key.replace(/[{}]/g,'')+"')" : "psAddElement('text',null,'"+f.key+"')";
      html += '<button class="ps-field-btn" onclick="'+onclick+'"><i class="bi '+f.icon+'"></i><span>'+f.label+'</span></button>';
    });
    html += '</div>';
  });
  html += '<div style="padding:10px;border-top:1px solid #e2e8f0;background:#fff">';
  html += '<p style="font-size:8px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px">Job Card Variable Codes</p>';
  Object.entries(JOB_CARD_VARIABLE_GROUPS).forEach(function(entry) {
    const group = entry[0];
    const codes = entry[1];
    html += '<div style="margin-bottom:10px">';
    html += '<div style="font-size:8px;font-weight:800;color:#cbd5e1;text-transform:uppercase;letter-spacing:1px;padding:0 4px 4px">'+psEscHtml(group)+'</div>';
    codes.forEach(function(code) {
      html += '<button class="ps-field-code-btn" onclick="psInsertVariableCode(\''+psEscHtml(code)+'\')">';
      html += '<code class="ps-field-code-value">'+psEscHtml(code)+'</code>';
      html += '</button>';
    });
    html += '</div>';
  });
  html += '</div>';
  c.innerHTML = html;
}

// ============================================================
// ELEMENT CRUD
// ============================================================
function psAddElement(type, placeholder, content) {
  if (!currentTemplate) return;
  const id = psUuid();
  const defaults = {
    text: { w:150, h:30 }, title: { w:200, h:40 }, image: { w:150, h:150 },
    barcode: { w:200, h:60 }, qr: { w:80, h:80 }, line: { w:400, h:2 },
    rectangle: { w:100, h:100 }, circle: { w:80, h:80 }, table: { w:500, h:180 }
  };
  const d = defaults[type] || { w:150, h:30 };
  const el = {
    id, type, x:80, y:80, width:d.w, height:d.h, rotate:0,
    content: content || (type==='text'||type==='title' ? 'New Text' : ''),
    placeholder: placeholder || '',
    isLocked: false,
    barcodeType: type==='barcode' ? 'CODE128' : null,
    style: {
      fontSize: type==='title' ? 24 : 14,
      fontWeight: type==='title' ? 'bold' : 'normal',
      fontFamily: 'inter',
      textAlign: 'left',
      color: '#000000',
      backgroundColor: (type==='rectangle'||type==='circle') ? '#ffffff' : 'transparent',
      borderWidth: (type==='rectangle'||type==='circle'||type==='line'||type==='table') ? 2 : 0,
      borderColor: '#000000',
      borderRadius: 0,
      opacity: 1,
      lineStyle: 'solid'
    }
  };
  currentTemplate.elements.push(el);
  selectedElementId = id;
  psRenderCanvas();
  psRenderProps();
}

function psUpdateProp(id, prop, val) {
  const el = currentTemplate.elements.find(e => e.id === id);
  if (!el) return;
  el[prop] = val;
  psRenderCanvas();
  psRenderProps();
}

function psUpdateStyle(id, prop, val) {
  const el = currentTemplate.elements.find(e => e.id === id);
  if (!el) return;
  el.style[prop] = val;
  psRenderCanvas();
  psRenderProps();
}

function psDeleteElement(id) {
  if (!currentTemplate) return;
  const el = currentTemplate.elements.find(e => e.id === id);
  if (el && el.isLocked) { psToast('Element is locked','error'); return; }
  currentTemplate.elements = currentTemplate.elements.filter(e => e.id !== id);
  if (selectedElementId === id) selectedElementId = null;
  psRenderCanvas();
  psRenderProps();
}

function psDuplicateElement(id) {
  const el = currentTemplate.elements.find(e => e.id === id);
  if (!el) return;
  const newEl = JSON.parse(JSON.stringify(el));
  newEl.id = psUuid();
  newEl.x += 15;
  newEl.y += 15;
  newEl.isLocked = false;
  currentTemplate.elements.push(newEl);
  selectedElementId = newEl.id;
  psRenderCanvas();
  psRenderProps();
  psToast('Element duplicated','success');
}

function psToggleLock(id) {
  const el = currentTemplate.elements.find(e => e.id === id);
  if (!el) return;
  el.isLocked = !el.isLocked;
  psToast(el.isLocked ? 'Locked' : 'Unlocked','success');
  psRenderCanvas();
  psRenderProps();
}

function psMoveLayer(dir) {
  if (!selectedElementId || !currentTemplate) return;
  const els = currentTemplate.elements;
  const idx = els.findIndex(e => e.id === selectedElementId);
  if (idx === -1) return;
  const el = els.splice(idx, 1)[0];
  if (dir === 'front') els.push(el);
  else if (dir === 'back') els.unshift(el);
  else if (dir === 'forward') els.splice(Math.min(els.length, idx + 1), 0, el);
  else els.splice(Math.max(0, idx - 1), 0, el);
  psRenderCanvas();
}

// ============================================================
// CANVAS RENDERING
// ============================================================
function psRenderCanvas() {
  const canvas = document.getElementById('studio-canvas');
  if (!canvas || !currentTemplate) return;
  canvas.style.width = (currentTemplate.paperWidth * MM_TO_PX) + 'px';
  canvas.style.height = (currentTemplate.paperHeight * MM_TO_PX) + 'px';
  canvas.style.transform = 'scale(' + psZoom + ')';

  // Background
  const bgLayer = document.getElementById('canvas-bg');
  if (currentTemplate.background && currentTemplate.background.image) {
    bgLayer.innerHTML = '<img src="'+currentTemplate.background.image+'" style="width:100%;height:100%;object-fit:contain" alt="">';
    bgLayer.style.opacity = currentTemplate.background.opacity || 1;
    bgLayer.style.display = 'block';
  } else { bgLayer.innerHTML = ''; bgLayer.style.display = 'none'; }

  // Grid
  document.getElementById('canvas-grid').style.display = psShowGrid ? 'block' : 'none';

  // Elements
  const layer = document.getElementById('canvas-elements');
  layer.innerHTML = '';
  currentTemplate.elements.forEach(el => {
    const div = document.createElement('div');
    div.className = 'canvas-element' + (selectedElementId === el.id ? ' selected' : '');
    div.dataset.elementId = el.id;
    Object.assign(div.style, { position:'absolute', left:el.x+'px', top:el.y+'px', width:el.width+'px', height:el.height+'px', transform:'rotate('+(el.rotate||0)+'deg)', cursor:el.isLocked?'default':'move', userSelect:'none' });

    if (el.isLocked) { const lb = document.createElement('div'); lb.className='element-lock-badge'; lb.innerHTML='<i class="bi bi-lock-fill"></i>'; div.appendChild(lb); }

    const content = document.createElement('div');
    content.style.cssText = 'width:100%;height:100%;overflow:hidden';
    psRenderElementContent(el, content);
    div.appendChild(content);

    if (selectedElementId === el.id && !el.isLocked) {
      const h = document.createElement('div'); h.className='resize-handle';
      h.addEventListener('mousedown', function(e) { psStartResize(e, el); });
      div.appendChild(h);
    }

    div.addEventListener('mousedown', function(e) { e.stopPropagation(); selectedElementId = el.id; psRenderCanvas(); psRenderProps(); psStartDrag(e, el); });
    div.addEventListener('click', function(e) { e.stopPropagation(); });
    layer.appendChild(div);
  });

  // Post-render: barcodes and QR
  currentTemplate.elements.forEach(el => {
    if (el.type === 'barcode') psRenderBarcode(el);
    if (el.type === 'qr') psRenderQR(el);
  });
}

function psRenderElementContent(el, c) {
  const bg = el.style.backgroundColor || 'transparent';
  const bw = el.style.borderWidth || 0;
  const bc = el.style.borderColor || '#000';
  const ls = el.style.lineStyle || 'solid';
  const br = el.type === 'circle' ? '100%' : ((el.style.borderRadius||0)+'px');
  const op = el.style.opacity !== undefined ? el.style.opacity : 1;
  const border = bw ? (bw+'px '+ls+' '+bc) : 'none';

  switch(el.type) {
    case 'text': case 'title': {
      var align = el.style.textAlign || 'left';
      var jc = align === 'center' ? 'center' : (align === 'right' ? 'flex-end' : 'flex-start');
      Object.assign(c.style, { backgroundColor:bg, border, borderRadius:br, opacity:op, display:'flex', alignItems:'center', justifyContent:jc, padding:'4px' });
      const sp = document.createElement('div');
      Object.assign(sp.style, { fontSize:el.style.fontSize+'px', fontFamily:getFontValue(el.style.fontFamily), fontWeight:el.style.fontWeight||'normal', color:el.style.color||'#000', width:'100%', textAlign:align, wordBreak:'break-word', lineHeight:'1.3' });
      sp.textContent = psProcessText(el.content||'');
      c.appendChild(sp);
      break;
    }

    case 'image':
      Object.assign(c.style, { borderRadius:br, opacity:op });
      if (el.content) { c.innerHTML = '<img src="'+psEscHtml(el.content)+'" style="width:100%;height:100%;object-fit:contain;border-radius:'+br+'" alt="">'; }
      else { c.innerHTML = '<div style="width:100%;height:100%;border:2px dashed #d1d5db;display:flex;align-items:center;justify-content:center;font-size:10px;color:#94a3b8;font-weight:700;text-transform:uppercase">No Image</div>'; }
      break;

    case 'barcode':
      Object.assign(c.style, { backgroundColor:bg, border, opacity:op, display:'flex', alignItems:'center', justifyContent:'center' });
      const svg = document.createElementNS("http://www.w3.org/2000/svg","svg");
      svg.classList.add('barcode-svg');
      c.appendChild(svg);
      break;

    case 'qr':
      Object.assign(c.style, { backgroundColor:bg, border, opacity:op, display:'flex', alignItems:'center', justifyContent:'center' });
      const qd = document.createElement('div'); qd.classList.add('qr-container');
      c.appendChild(qd);
      break;

    case 'rectangle':
      Object.assign(c.style, { backgroundColor:bg, border, borderRadius:br, opacity:op });
      break;

    case 'circle':
      Object.assign(c.style, { backgroundColor:bg, border, borderRadius:'100%', opacity:op });
      break;

    case 'line':
      Object.assign(c.style, { height:(bw||2)+'px', backgroundColor:bc, opacity:op, marginTop:Math.max(0,(el.height-(bw||2))/2)+'px' });
      break;

    case 'table':
      Object.assign(c.style, { border, opacity:op, display:'flex', flexDirection:'column', fontSize:'9px' });
      const isSrc = el.placeholder === 'sourceMaterials';
      let th = '<div style="background:#1e293b;color:white;padding:6px 8px;font-weight:700;text-transform:uppercase;display:flex;justify-content:space-between">';
      th += isSrc ? '<span>ROLL ID</span><span>GSM</span><span>DIM</span><span>CO</span>' : '<span>ID</span><span>WIDTH</span><span>GSM</span><span>DEST</span>';
      th += '</div>';
      for (let i=0;i<3;i++) {
        th += '<div style="border-bottom:1px solid #e2e8f0;padding:6px 8px;font-weight:600;display:flex;justify-content:space-between;opacity:.5;text-transform:uppercase">';
        th += isSrc ? '<span>T-100'+i+'</span><span>80</span><span>400x3k</span><span>Avery</span>' : '<span>T-1001-A</span><span>250</span><span>80</span><span>JOB</span>';
        th += '</div>';
      }
      c.innerHTML = th;
      break;
  }
}

function psRenderBarcode(el) {
  const svg = document.querySelector('[data-element-id="'+el.id+'"] .barcode-svg');
  if (!svg || typeof JsBarcode === 'undefined') return;
  try {
    let val = psProcessText(el.content || el.placeholder || 'PREVIEW');
    const fmt = el.barcodeType || 'CODE128';
    if (fmt==='EAN13'||fmt==='UPC') val = '123456789012';
    JsBarcode(svg, val, { format:fmt, height:Math.max(20,el.height-30), width:1.5, fontSize:10, displayValue:true, margin:4 });
  } catch(e) {}
}

function psRenderQR(el) {
  const container = document.querySelector('[data-element-id="'+el.id+'"] .qr-container');
  if (!container || typeof qrcode === 'undefined') return;
  try {
    const val = psProcessText(el.content || el.placeholder || 'PREVIEW');
    const sz = Math.min(el.width, el.height) - 10;
    const qr = qrcode(0, 'M');
    qr.addData(val);
    qr.make();
    const mc = qr.getModuleCount();
    const cs = Math.max(1, Math.floor(sz / mc));
    container.innerHTML = qr.createSvgTag({ cellSize:cs, margin:0 });
  } catch(e) { container.innerHTML = '<span style="font-size:9px;color:#94a3b8">QR Error</span>'; }
}

// ============================================================
// DRAG & RESIZE
// ============================================================
function psStartDrag(e, el) {
  if (el.isLocked) return;
  const startX = e.clientX, startY = e.clientY;
  const origX = el.x, origY = el.y;
  function onMove(me) {
    let dx = (me.clientX - startX) / psZoom;
    let dy = (me.clientY - startY) / psZoom;
    let nx = origX + dx, ny = origY + dy;
    if (psGridSnap > 0) { nx = Math.round(nx/psGridSnap)*psGridSnap; ny = Math.round(ny/psGridSnap)*psGridSnap; }
    el.x = nx; el.y = ny;
    const div = document.querySelector('[data-element-id="'+el.id+'"]');
    if (div) { div.style.left = nx+'px'; div.style.top = ny+'px'; }
  }
  function onUp() { window.removeEventListener('mousemove',onMove); window.removeEventListener('mouseup',onUp); }
  window.addEventListener('mousemove',onMove);
  window.addEventListener('mouseup',onUp);
}

function psStartResize(e, el) {
  e.stopPropagation();
  if (el.isLocked) return;
  const startX = e.clientX, startY = e.clientY;
  const origW = el.width, origH = el.height;
  function onMove(me) {
    let nw = origW + (me.clientX - startX) / psZoom;
    let nh = el.type==='line' ? el.height : origH + (me.clientY - startY) / psZoom;
    if (psGridSnap > 0) { nw = Math.round(nw/psGridSnap)*psGridSnap; nh = Math.round(nh/psGridSnap)*psGridSnap; }
    el.width = Math.max(10, nw);
    el.height = Math.max(el.type==='line'?1:10, nh);
    const div = document.querySelector('[data-element-id="'+el.id+'"]');
    if (div) { div.style.width = el.width+'px'; div.style.height = el.height+'px'; }
  }
  function onUp() {
    window.removeEventListener('mousemove',onMove);
    window.removeEventListener('mouseup',onUp);
    psRenderCanvas();
    psRenderProps();
  }
  window.addEventListener('mousemove',onMove);
  window.addEventListener('mouseup',onUp);
}

// ============================================================
// PROPERTIES PANEL
// ============================================================
function psRenderProps() {
  const panel = document.getElementById('ps-properties');
  if (!currentTemplate) { panel.innerHTML=''; return; }
  const el = selectedElementId ? currentTemplate.elements.find(e => e.id === selectedElementId) : null;

  if (!el) {
    // Canvas setup panel
    let h = '<div style="padding:20px"><h4 style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#6366f1;display:flex;align-items:center;gap:8px;padding-bottom:12px;border-bottom:1px solid #e2e8f0"><i class="bi bi-image"></i> Canvas Setup</h4>';
    h += '<div style="margin-top:20px"><label style="font-size:9px;font-weight:700;text-transform:uppercase;opacity:.5;display:block;margin-bottom:8px">Background Image</label>';
    h += '<div style="display:flex;gap:6px"><button class="btn btn-sm" style="flex:1;border:2px dashed #e2e8f0;padding:16px;font-size:9px;font-weight:700;text-transform:uppercase" onclick="document.getElementById(\'bg-upload\').click()"><i class="bi bi-upload"></i> Upload</button><button class="btn btn-sm" style="flex:1;border:2px solid #e2e8f0;padding:16px;font-size:9px;font-weight:700;text-transform:uppercase;color:#6366f1" onclick="psOpenLibrary(\'background\')"><i class="bi bi-images"></i> Library</button><input type="file" id="bg-upload" accept="image/*" style="display:none" onchange="psUploadBg(this)"></div>';
    if (currentTemplate.background && currentTemplate.background.image) {
      h += '<div style="margin-top:16px"><label style="font-size:9px;font-weight:700;text-transform:uppercase;display:block;margin-bottom:4px">Opacity: <span id="ps-lbl-bg-opacity">'+Math.round((currentTemplate.background.opacity||1)*100)+'</span>%</label>';
      h += '<input type="range" min="0" max="100" value="'+Math.round((currentTemplate.background.opacity||1)*100)+'" style="width:100%;cursor:pointer" oninput="psSliderBgOpacity(this.value/100)">';
      h += '<button class="btn btn-xs" style="color:#ef4444;margin-top:8px;width:100%;font-size:9px;font-weight:700;text-transform:uppercase" onclick="currentTemplate.background.image=\'\';psRenderCanvas();psRenderProps()"><i class="bi bi-eraser"></i> Remove</button></div>';
    }
    h += '</div>';
    h += '<div style="margin-top:24px;padding-top:16px;border-top:1px solid #f1f5f9"><p style="font-size:9px;color:#94a3b8;font-weight:600;text-transform:uppercase"><i class="bi bi-info-circle"></i> Click an element on the canvas to edit its properties.</p></div>';
    h += '</div>';
    panel.innerHTML = h;
    return;
  }

  // Element properties
  let h = '<div style="padding:20px">';
  // Header with actions
  h += '<div style="display:flex;justify-content:space-between;align-items:center;background:#f8fafc;margin:-20px -20px 16px;padding:14px 20px;border-bottom:1px solid #e2e8f0">';
  h += '<h4 style="font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:1px;color:#6366f1;display:flex;align-items:center;gap:6px;margin:0"><i class="bi bi-gear"></i> Properties</h4>';
  h += '<div style="display:flex;gap:4px">';
  h += '<button class="btn btn-xs btn-ghost" title="Duplicate" onclick="psDuplicateElement(\''+el.id+'\')"><i class="bi bi-copy"></i></button>';
  h += '<button class="btn btn-xs btn-ghost" title="'+(el.isLocked?'Unlock':'Lock')+'" onclick="psToggleLock(\''+el.id+'\')"><i class="bi bi-'+(el.isLocked?'lock-fill':'unlock')+'"></i></button>';
  h += '<button class="btn btn-xs" style="color:#ef4444" title="Delete" onclick="psDeleteElement(\''+el.id+'\')" '+(el.isLocked?'disabled':'')+'><i class="bi bi-trash"></i></button>';
  h += '</div></div>';

  // Position
  h += '<div style="margin-bottom:20px">';
  h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">Layout & Position</label>';
  h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">';
  h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">X</label><input type="number" class="ps-prop-input" value="'+Math.round(el.x)+'" onchange="psUpdateProp(\''+el.id+'\',\'x\',Number(this.value))"></div>';
  h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Y</label><input type="number" class="ps-prop-input" value="'+Math.round(el.y)+'" onchange="psUpdateProp(\''+el.id+'\',\'y\',Number(this.value))"></div>';
  h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Width</label><input type="number" class="ps-prop-input" value="'+Math.round(el.width)+'" onchange="psUpdateProp(\''+el.id+'\',\'width\',Number(this.value))"></div>';
  h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Height</label><input type="number" class="ps-prop-input" value="'+Math.round(el.height)+'" onchange="psUpdateProp(\''+el.id+'\',\'height\',Number(this.value))"></div>';
  h += '</div>';
  h += '<div style="margin-top:10px"><label style="font-size:9px;font-weight:700;text-transform:uppercase">Rotation: <span id="ps-lbl-rotate">'+(el.rotate||0)+'</span>&deg;</label>';
  h += '<input type="range" min="0" max="360" value="'+(el.rotate||0)+'" style="width:100%;cursor:pointer" oninput="psSliderProp(\''+el.id+'\',\'rotate\',Number(this.value))">';
  h += '</div></div>';

  // Typography (text/title)
  if (el.type==='text'||el.type==='title') {
    h += '<div style="margin-bottom:20px">';
    h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">Typography</label>';
    h += '<div style="margin-bottom:10px"><label style="font-size:9px;font-weight:700;text-transform:uppercase">Content</label><textarea class="ps-prop-input" rows="2" onchange="psUpdateProp(\''+el.id+'\',\'content\',this.value)">'+psEscHtml(el.content||'')+'</textarea></div>';
    h += '<div style="margin-bottom:10px"><label style="font-size:9px;font-weight:700;text-transform:uppercase">Font</label><select class="ps-prop-input" onchange="psUpdateStyle(\''+el.id+'\',\'fontFamily\',this.value)">';
    var curFontId = getFontId(el.style.fontFamily);
    FONT_FAMILIES.forEach(f => { h += '<option value="'+f.id+'"'+(curFontId===f.id?' selected':'')+'>'+f.name+'</option>'; });
    h += '</select></div>';
    h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px">';
    h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Size</label><input type="number" class="ps-prop-input" value="'+el.style.fontSize+'" onchange="psUpdateStyle(\''+el.id+'\',\'fontSize\',Number(this.value))"></div>';
    h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Weight</label><select class="ps-prop-input" onchange="psUpdateStyle(\''+el.id+'\',\'fontWeight\',this.value)"><option value="normal"'+(el.style.fontWeight==='normal'?' selected':'')+'>Normal</option><option value="bold"'+(el.style.fontWeight==='bold'?' selected':'')+'>Bold</option><option value="900"'+(el.style.fontWeight==='900'?' selected':'')+'>Black</option></select></div>';
    h += '</div>';
    h += '<div style="margin-bottom:10px"><label style="font-size:9px;font-weight:700;text-transform:uppercase">Color</label><div style="display:flex;gap:6px"><input type="color" value="'+el.style.color+'" style="width:36px;height:32px;padding:2px;border:1px solid #e2e8f0;border-radius:4px;cursor:pointer" onchange="psUpdateStyle(\''+el.id+'\',\'color\',this.value)"><input type="text" class="ps-prop-input" value="'+el.style.color+'" style="flex:1;font-family:monospace;font-size:10px" onchange="psUpdateStyle(\''+el.id+'\',\'color\',this.value)"></div></div>';
    h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Alignment</label><div style="display:flex;gap:3px;background:#f1f5f9;padding:3px;border-radius:6px;margin-top:4px">';
    ['left','center','right','justify'].forEach(a => {
      h += '<button class="btn btn-xs'+(el.style.textAlign===a?' btn-primary':'')+'" style="flex:1" onclick="psUpdateStyle(\''+el.id+'\',\'textAlign\',\''+a+'\')"><i class="bi bi-text-'+a+'"></i></button>';
    });
    h += '</div></div></div>';
  }

  // Barcode
  if (el.type==='barcode') {
    h += '<div style="margin-bottom:20px">';
    h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">Barcode</label>';
    h += '<div style="margin-bottom:10px"><label style="font-size:9px;font-weight:700;text-transform:uppercase">Value</label><input type="text" class="ps-prop-input" value="'+psEscHtml(el.content||el.placeholder||'')+'" onchange="psUpdateProp(\''+el.id+'\',\'content\',this.value)"></div>';
    h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Format</label><select class="ps-prop-input" onchange="psUpdateProp(\''+el.id+'\',\'barcodeType\',this.value)"><option value="CODE128"'+(el.barcodeType==='CODE128'?' selected':'')+'>CODE 128</option><option value="CODE39"'+(el.barcodeType==='CODE39'?' selected':'')+'>CODE 39</option><option value="EAN13"'+(el.barcodeType==='EAN13'?' selected':'')+'>EAN-13</option></select></div>';
    h += '</div>';
  }

  // QR
  if (el.type==='qr') {
    h += '<div style="margin-bottom:20px">';
    h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">QR Code</label>';
    h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Value / URL</label><input type="text" class="ps-prop-input" value="'+psEscHtml(el.content||el.placeholder||'')+'" onchange="psUpdateProp(\''+el.id+'\',\'content\',this.value)"></div>';
    h += '</div>';
  }

  // Image
  if (el.type==='image') {
    h += '<div style="margin-bottom:20px">';
    h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">Image</label>';
    h += '<div style="display:flex;gap:6px;margin-bottom:10px"><button class="btn btn-sm" style="flex:1;border:2px dashed #e2e8f0;padding:12px;font-size:9px;font-weight:700;text-transform:uppercase" onclick="document.getElementById(\'img-up-'+el.id+'\').click()"><i class="bi bi-upload"></i> Upload</button><button class="btn btn-sm" style="flex:1;border:2px solid #e2e8f0;padding:12px;font-size:9px;font-weight:700;text-transform:uppercase;color:#6366f1" onclick="psOpenLibrary(\'element\',\''+el.id+'\')"><i class="bi bi-images"></i> Library</button><input type="file" id="img-up-'+el.id+'" accept="image/*" style="display:none" onchange="psUploadImg(this,\''+el.id+'\')"></div>';
    h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Or URL</label><input type="text" class="ps-prop-input" value="'+psEscHtml(el.content||'')+'" placeholder="https://..." onchange="psUpdateProp(\''+el.id+'\',\'content\',this.value)"></div>';
    h += '</div>';
  }

  // Shapes
  if (['rectangle','circle','line'].includes(el.type)) {
    h += '<div style="margin-bottom:20px">';
    h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">Appearance</label>';
    if (el.type!=='line') {
      h += '<div style="margin-bottom:10px"><label style="font-size:9px;font-weight:700;text-transform:uppercase">Fill</label><div style="display:flex;gap:6px"><input type="color" value="'+(el.style.backgroundColor||'#ffffff')+'" style="width:36px;height:32px;padding:2px;border:1px solid #e2e8f0;border-radius:4px;cursor:pointer" onchange="psUpdateStyle(\''+el.id+'\',\'backgroundColor\',this.value)"><input type="text" class="ps-prop-input" value="'+(el.style.backgroundColor||'#ffffff')+'" style="flex:1;font-family:monospace;font-size:10px" onchange="psUpdateStyle(\''+el.id+'\',\'backgroundColor\',this.value)"></div></div>';
    }
    h += '<div style="margin-bottom:10px"><label style="font-size:9px;font-weight:700;text-transform:uppercase">Stroke</label><div style="display:flex;gap:6px"><input type="color" value="'+(el.style.borderColor||'#000000')+'" style="width:36px;height:32px;padding:2px;border:1px solid #e2e8f0;border-radius:4px;cursor:pointer" onchange="psUpdateStyle(\''+el.id+'\',\'borderColor\',this.value)"><input type="text" class="ps-prop-input" value="'+(el.style.borderColor||'#000000')+'" style="flex:1;font-family:monospace;font-size:10px" onchange="psUpdateStyle(\''+el.id+'\',\'borderColor\',this.value)"></div></div>';
    h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">';
    h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Stroke Width</label><input type="number" class="ps-prop-input" value="'+(el.style.borderWidth||0)+'" onchange="psUpdateStyle(\''+el.id+'\',\'borderWidth\',Number(this.value))"></div>';
    if (el.type==='rectangle') h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Radius</label><input type="number" class="ps-prop-input" value="'+(el.style.borderRadius||0)+'" onchange="psUpdateStyle(\''+el.id+'\',\'borderRadius\',Number(this.value))"></div>';
    else h += '<div></div>';
    h += '</div></div>';
  }

  // Effects
  h += '<div style="margin-bottom:20px">';
  h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">Effects</label>';
  h += '<div><label style="font-size:9px;font-weight:700;text-transform:uppercase">Opacity: <span id="ps-lbl-opacity">'+Math.round((el.style.opacity||1)*100)+'</span>%</label>';
  h += '<input type="range" min="0" max="100" value="'+Math.round((el.style.opacity||1)*100)+'" style="width:100%;cursor:pointer" oninput="psSliderStyle(\''+el.id+'\',\'opacity\',Number(this.value)/100)"></div>';
  h += '</div>';

  // Arrangement
  h += '<div style="margin-bottom:20px">';
  h += '<label style="font-size:10px;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:1px;display:block;padding-bottom:8px;border-bottom:1px solid #f1f5f9;margin-bottom:12px">Arrangement</label>';
  h += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px">';
  h += '<button class="btn btn-xs" style="font-size:9px;font-weight:700;text-transform:uppercase;border:1px solid #e2e8f0" onclick="psMoveLayer(\'front\')"><i class="bi bi-layer-forward"></i> Front</button>';
  h += '<button class="btn btn-xs" style="font-size:9px;font-weight:700;text-transform:uppercase;border:1px solid #e2e8f0" onclick="psMoveLayer(\'back\')"><i class="bi bi-layer-backward"></i> Back</button>';
  h += '<button class="btn btn-xs" style="font-size:9px;font-weight:700;text-transform:uppercase;border:1px solid #e2e8f0" onclick="psMoveLayer(\'forward\')"><i class="bi bi-chevron-up"></i> Up</button>';
  h += '<button class="btn btn-xs" style="font-size:9px;font-weight:700;text-transform:uppercase;border:1px solid #e2e8f0" onclick="psMoveLayer(\'backward\')"><i class="bi bi-chevron-down"></i> Down</button>';
  h += '</div></div>';

  h += '</div>';
  panel.innerHTML = h;
}

// ============================================================
// IMAGE UPLOAD HELPERS
// ============================================================
function psUploadBg(input) {
  const file = input.files[0]; if (!file) return;
  if (file.size > 500*1024) { psToast('Max 500KB for background','error'); return; }
  const reader = new FileReader();
  reader.onload = function(e) {
    currentTemplate.background.image = e.target.result;
    psRenderCanvas();
    psRenderProps();
  };
  reader.readAsDataURL(file);
}

function psUploadImg(input, elId) {
  const file = input.files[0]; if (!file) return;
  if (file.size > 1024*1024) { psToast('Max 1MB for images','error'); return; }
  const reader = new FileReader();
  reader.onload = function(e) { psUpdateProp(elId, 'content', e.target.result); };
  reader.readAsDataURL(file);
}

// ============================================================
// SAVE (AJAX)
// ============================================================
async function psSave() {
  if (!currentTemplate) return;
  const btn = document.getElementById('ps-save-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-arrow-repeat spin-icon"></i> Saving...';

  // Generate thumbnail
  let thumbnail = currentTemplate.thumbnail || '';
  const canvasEl = document.getElementById('studio-canvas');
  if (canvasEl && typeof html2canvas !== 'undefined') {
    try {
      // Temporarily deselect + reset transform for clean capture
      const prevSel = selectedElementId;
      const prevTransform = canvasEl.style.transform;
      selectedElementId = null;
      psRenderCanvas();
      canvasEl.style.transform = 'none';
      const thumbCanvas = await html2canvas(canvasEl, { scale:0.3, useCORS:true, logging:false, backgroundColor:'#fff', allowTaint:true });
      thumbnail = thumbCanvas.toDataURL('image/jpeg', 0.6);
      selectedElementId = prevSel;
      canvasEl.style.transform = prevTransform;
      psRenderCanvas();
    } catch(e) { console.warn('Thumbnail generation failed:', e); }
  }

  const data = { ...currentTemplate, thumbnail };
  const fd = new FormData();
  fd.append('ajax','1');
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('action','save_template');
  fd.append('template_id', currentTemplate.id || '0');
  fd.append('template_data', JSON.stringify(data));

  try {
    const resp = await fetch(PAGE_URL, { method:'POST', body:fd });
    const result = await resp.json();
    if (result.success) {
      currentTemplate.id = result.id;
      currentTemplate.thumbnail = thumbnail;
      const idx = TEMPLATES_DATA.findIndex(t => t.id === result.id);
      if (idx >= 0) TEMPLATES_DATA[idx] = JSON.parse(JSON.stringify(currentTemplate));
      else TEMPLATES_DATA.push(JSON.parse(JSON.stringify(currentTemplate)));
      psToast('Template saved!','success');
    } else { psToast(result.error||'Save failed','error'); }
  } catch(e) { psToast('Network error','error'); }

  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-save"></i> Save';
}

// ============================================================
// PRINT
// ============================================================
async function psPrint() {
  const canvasEl = document.getElementById('studio-canvas');
  if (!canvasEl || !currentTemplate) return;

  const btn = document.getElementById('ps-print-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-arrow-repeat spin-icon"></i> Rendering...';

  // Clean render (deselect + remove zoom)
  const prevSel = selectedElementId;
  const prevZoom = psZoom;
  selectedElementId = null;
  psZoom = 1;
  psRenderCanvas();

  // Small delay for DOM to settle before capture
  await new Promise(function(r){ setTimeout(r, 100); });

  try {
    const imgCanvas = await html2canvas(canvasEl, { scale:4, useCORS:true, allowTaint:true, logging:false, backgroundColor:'#fff', width:currentTemplate.paperWidth*MM_TO_PX, height:currentTemplate.paperHeight*MM_TO_PX });
    const imgData = imgCanvas.toDataURL('image/png', 1.0);

    // Restore canvas state immediately after capture
    selectedElementId = prevSel;
    psZoom = prevZoom;
    psRenderCanvas();
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-printer"></i> Print';

    const iframe = document.createElement('iframe');
    iframe.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
    document.body.appendChild(iframe);
    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write('<html><head><title>Print</title><style>@page{size:'+currentTemplate.paperWidth+'mm '+currentTemplate.paperHeight+'mm;margin:0}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}body{margin:0;padding:0;width:100%;height:100%}img{width:100%;height:100%;object-fit:contain;display:block}</style></head><body><img src="'+imgData+'"></body></html>');
    doc.close();
    setTimeout(function(){
      try { iframe.contentWindow.focus(); iframe.contentWindow.print(); } catch(pe){}
      setTimeout(function(){ try{iframe.remove();}catch(re){} }, 3000);
    }, 800);
  } catch(e) {
    psToast('Print error','error');
    selectedElementId = prevSel;
    psZoom = prevZoom;
    psRenderCanvas();
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-printer"></i> Print';
  }
}

// ============================================================
// EXPORT / IMPORT
// ============================================================
function psExportCurrent() {
  if (!currentTemplate) return;
  const blob = new Blob([JSON.stringify(currentTemplate, null, 2)], { type:'application/json' });
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = currentTemplate.name.replace(/\s+/g,'_') + '_template.json';
  a.click();
  URL.revokeObjectURL(a.href);
  psToast('Template exported','success');
}

// ============================================================
// KEYBOARD SHORTCUTS
// ============================================================
document.addEventListener('keydown', function(e) {
  // Only in editor mode
  if (!currentTemplate) return;

  if (e.key === 'Escape') {
    if (selectedElementId) { selectedElementId = null; psRenderCanvas(); psRenderProps(); }
    else closeEditor();
    return;
  }

  if ((e.key === 'Delete' || e.key === 'Backspace') && selectedElementId) {
    if (!['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName||'')) {
      psDeleteElement(selectedElementId);
    }
  }
});

// ============================================================
// INIT
// ============================================================
(function() {
  // Auto-open editor if ?edit=ID
  if (AUTO_EDIT_ID > 0) {
    setTimeout(function() { openEditor(AUTO_EDIT_ID); }, 100);
  }

  psUpdatePaperFields();

  // Close modals on click outside
  ['ps-create-modal','ps-delete-modal','ps-library-modal'].forEach(id => {
    const modal = document.getElementById(id);
    if (modal) modal.addEventListener('click', function(e) { if (e.target === modal) modal.style.display = 'none'; });
  });
})();
</script>

<style>
@keyframes spin { from { transform:rotate(0deg) } to { transform:rotate(360deg) } }
.spin-icon { animation:spin 1s linear infinite; display:inline-block; }
</style>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
