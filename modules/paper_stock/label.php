<?php
// ============================================================
// ERP System — Paper Stock: Print Label
// Template-based label printing with live preview
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$db = getDB();
$appSettings = getAppSettings();
$companyName = $appSettings['company_name'] ?? APP_NAME;
$logoPath = $appSettings['logo_path'] ?? '';
$logoUrl = $logoPath ? (BASE_URL . '/' . $logoPath) : '';
$companyLogoUrl = $logoUrl !== '' ? $logoUrl : (BASE_URL . '/assets/img/logo.svg');
$themeColor = (string)($appSettings['sidebar_button_color'] ?? '#22c55e');

// ── Auto-create print_templates table if missing ──────────────
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

// ── Ensure default label template exists ──────────────────────
// Mark any old default system labels and ensure exactly one exists
$chk = $db->query("SELECT id FROM print_templates WHERE is_system = 1 AND document_type = 'Industrial Label' LIMIT 1");
if (!$chk || $chk->num_rows === 0) {
    // Also check by name (may exist without is_system flag from prior version)
    $chkName = $db->query("SELECT id FROM print_templates WHERE name = 'Default Paper Stock Label' AND document_type = 'Industrial Label' LIMIT 1");
    if ($chkName && $chkName->num_rows > 0) {
        $existId = (int)$chkName->fetch_assoc()['id'];
        $builtinElements = json_encode([['type'=>'builtin','layout'=>'default_stock_label']]);
        $db->query("UPDATE print_templates SET is_system = 1, is_default = 1, elements = '" . $db->real_escape_string($builtinElements) . "' WHERE id = {$existId}");
    } else {
        $defaultElements = json_encode([['type'=>'builtin','layout'=>'default_stock_label']]);
        $defaultBg = json_encode(['image'=>'','opacity'=>1]);
        $name = 'Default Paper Stock Label';
        $docType = 'Industrial Label';
        $pw = 150; $ph = 100;
        $isDefault = 1; $isSystem = 1;
        $stmt = $db->prepare("INSERT INTO print_templates (name, document_type, paper_width, paper_height, elements, background, is_default, is_system) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('ssddssii', $name, $docType, $pw, $ph, $defaultElements, $defaultBg, $isDefault, $isSystem);
        $stmt->execute();
    }
} else {
    // System template exists — ensure it uses the latest built-in layout
    $sysId = (int)$chk->fetch_assoc()['id'];
    $builtinElements = json_encode([['type'=>'builtin','layout'=>'default_stock_label']]);
    $db->query("UPDATE print_templates SET elements = '" . $db->real_escape_string($builtinElements) . "' WHERE id = {$sysId} AND (elements IS NULL OR elements NOT LIKE '%builtin%')");
}

// ── Get roll data ─────────────────────────────────────────────
$idsParam = trim($_GET['ids'] ?? '');
if ($idsParam === '') {
    setFlash('error', 'No rolls selected for label printing.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}
$ids = array_filter(array_map('intval', explode(',', $idsParam)), function($id) { return $id > 0; });
if (empty($ids)) {
    setFlash('error', 'Invalid roll IDs.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}
$placeholders = implode(',', array_fill(0, count($ids), '?'));
$stmt = $db->prepare("SELECT * FROM paper_stock WHERE id IN ($placeholders) ORDER BY id DESC");
$stmt->bind_param(str_repeat('i', count($ids)), ...$ids);
$stmt->execute();
$rolls = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($rolls as &$r) {
    $r['sqm'] = round(((float)($r['width_mm'] ?? 0) / 1000) * (float)($r['length_mtr'] ?? 0), 2);
}
unset($r);

if (empty($rolls)) {
    setFlash('error', 'No rolls found.');
    redirect(BASE_URL . '/modules/paper_stock/index.php');
}

// ── Load label templates ──────────────────────────────────────
$tplRes = $db->query("SELECT * FROM print_templates WHERE document_type = 'Industrial Label' ORDER BY is_default DESC, is_system DESC, name ASC");
$templates = [];
if ($tplRes) { while ($t = $tplRes->fetch_assoc()) $templates[] = $t; }

// JS-safe roll data (includes Firebase Print Studio compatible aliases)
$companyAddr = $appSettings['company_address'] ?? '';
$jsRolls = array_map(function($r) use ($companyName, $companyAddr) {
    $dateFormatted  = ($r['date_received'] ?? '') ? date('d M Y', strtotime($r['date_received'])) : '';
    $dateSlash      = ($r['date_received'] ?? '') ? date('n/j/Y', strtotime($r['date_received'])) : date('n/j/Y');
    $lengthVal      = number_format((float)($r['length_mtr'] ?? 0), 0);
    $sqmVal         = number_format((float)($r['sqm'] ?? 0), 2);
    $weightVal      = ($r['weight_kg'] !== null && $r['weight_kg'] !== '') ? (string)$r['weight_kg'] : '0';
    $widthVal       = (string)(int)(float)($r['width_mm'] ?? 0);
    $gsmVal         = (string)(int)(float)($r['gsm'] ?? 0);
    $rollNo         = $r['roll_no'] ?? '';
    $paperType      = $r['paper_type'] ?? '';
    $paperCompany   = $r['company'] ?? '';
    $jobNo          = $r['job_no'] ?? '';
    $lotBatch       = $r['lot_batch_no'] ?? '';
    return [
        // ── Original PHP keys ──
        'id'              => (int)$r['id'],
        'roll_no'         => $rollNo,
        'status'          => $r['status'] ?? '',
        'company'         => $paperCompany,
        'paper_type'      => $paperType,
        'width_mm'        => $widthVal,
        'length_mtr'      => $lengthVal,
        'sqm'             => $sqmVal,
        'gsm'             => $gsmVal,
        'weight_kg'       => $weightVal,
        'purchase_rate'   => $r['purchase_rate'] ? '₹' . number_format((float)$r['purchase_rate'], 2) : '',
        'date_received'   => $dateFormatted,
        'date_used'       => ($r['date_used'] ?? '') ? date('d M Y', strtotime($r['date_used'])) : '',
        'job_no'          => $jobNo,
        'job_size'        => $r['job_size'] ?? '',
        'job_name'        => $r['job_name'] ?? '',
        'lot_batch_no'    => $lotBatch,
        'company_roll_no' => $r['company_roll_no'] ?? '',
        'remarks'         => $r['remarks'] ?? '',
        'company_name'    => $companyName,
        // ── Firebase Print Studio aliases ──
        'paper_company'       => $paperCompany,
        'width'               => $widthVal,
        'length'              => $lengthVal,
        'weight'              => $weightVal,
        'roll_url'            => '',
        'view_url'            => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$r['id'],
        'job.companyName'     => $companyName,
        'job.companyAddress'  => $companyAddr,
        'job.date'            => $dateSlash,
        'job.batchId'         => $jobNo ?: $lotBatch,
        'job.machineId'       => '',
        'job.operator'        => '',
    ];
}, $rolls);

$jsTemplates = array_map(function($t) {
    $bg = json_decode($t['background'] ?: '{}', true) ?: [];
    return [
        'id'         => (int)$t['id'],
        'name'       => $t['name'],
        'paperWidth' => (float)$t['paper_width'],
        'paperHeight'=> (float)$t['paper_height'],
        'elements'   => json_decode($t['elements'] ?: '[]', true) ?: [],
        'background' => ['image' => $bg['image'] ?? '', 'opacity' => (float)($bg['opacity'] ?? 1)],
        'isDefault'  => (bool)$t['is_default'],
        'isSystem'   => (bool)($t['is_system'] ?? false),
        'thumbnail'  => $t['thumbnail'] ?? '',
    ];
}, $templates);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Print Labels — <?= e($companyName) ?></title>
<link rel="icon" href="<?= e($companyLogoUrl) ?>">
<link rel="apple-touch-icon" href="<?= e($companyLogoUrl) ?>">
<link rel="manifest" href="<?= BASE_URL ?>/manifest.php">
<meta name="theme-color" content="<?= e($themeColor) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;900&family=Roboto:wght@400;500;700;900&family=Montserrat:wght@400;600;700;900&family=Poppins:wght@400;500;600;700;900&family=Oswald:wght@400;500;600;700&family=Open+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --brand: #f97316; --dark: #0f172a; --border: #e2e8f0; --muted: #94a3b8; }
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: 'Segoe UI', Arial, sans-serif; background: #f1f5f9; color: #1e293b; }

/* ── Top Toolbar ── */
.label-toolbar {
    display: flex; align-items: center; justify-content: space-between; gap: 12px;
    padding: 14px 24px; background: #fff; border-bottom: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0,0,0,.06); flex-wrap: wrap;
}
.label-toolbar h1 { font-size: 15px; font-weight: 900; color: var(--dark); display: flex; align-items: center; gap: 8px; }
.label-toolbar h1 .count-badge {
    background: var(--brand); color: #fff; font-size: 10px; font-weight: 800;
    padding: 2px 8px; border-radius: 10px;
}
.toolbar-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
.toolbar-actions button, .toolbar-actions select {
    padding: 8px 16px; border-radius: 10px; font-size: 12px; font-weight: 700; cursor: pointer;
    border: 1px solid var(--border); background: #fff; display: inline-flex; align-items: center; gap: 6px;
}
.toolbar-actions .btn-print { background: var(--dark); color: #fff; border-color: var(--dark); }
.toolbar-actions .btn-print:hover { background: #1e293b; }
.toolbar-actions .btn-close { color: #64748b; }
.toolbar-actions .btn-close:hover { background: #f8fafc; }
.toolbar-actions select { min-width: 200px; }

/* ── Sidebar + Preview Split ── */
.label-layout { display: flex; height: calc(100vh - 62px); }
.label-sidebar {
    width: 280px; min-width: 260px; background: #fff; border-right: 1px solid var(--border);
    overflow-y: auto; padding: 16px; flex-shrink: 0;
}
.label-preview-area {
    flex: 1; overflow: auto; padding: 30px;
    display: flex; flex-wrap: wrap; gap: 24px; align-content: flex-start; justify-content: center;
}

/* ── Template Card ── */
.tpl-section-title {
    font-size: 9px; font-weight: 800; text-transform: uppercase; letter-spacing: .12em;
    color: var(--muted); margin-bottom: 10px;
}
.tpl-card {
    padding: 10px 12px; border: 2px solid var(--border); border-radius: 10px;
    cursor: pointer; margin-bottom: 8px; transition: all .15s;
}
.tpl-card:hover { border-color: #94a3b8; background: #f8fafc; }
.tpl-card.active { border-color: var(--brand); background: #fff7ed; }
.tpl-card .tpl-name { font-size: 12px; font-weight: 700; color: var(--dark); }
.tpl-card .tpl-size { font-size: 10px; color: var(--muted); margin-top: 2px; }
.tpl-card .tpl-badge { display: inline-block; font-size: 8px; font-weight: 800; text-transform: uppercase; letter-spacing: .06em; padding: 1px 6px; border-radius: 6px; margin-top: 4px; }
.tpl-badge.system { background: #dbeafe; color: #2563eb; }
.tpl-badge.default { background: #dcfce7; color: #16a34a; }

/* ── Roll selector ── */
.roll-section { margin-top: 20px; border-top: 1px solid var(--border); padding-top: 14px; }
.roll-item {
    display: flex; align-items: center; gap: 8px; padding: 6px 8px;
    border-radius: 8px; font-size: 11px; cursor: pointer; transition: background .1s;
}
.roll-item:hover { background: #f8fafc; }
.roll-item.active { background: #fff7ed; font-weight: 700; }
.roll-item input { cursor: pointer; }
.roll-item .roll-id { font-family: monospace; color: var(--brand); font-weight: 700; }

/* ── Label Preview Card ── */
.label-card {
    background: #fff; border: 1px solid #d1d5db; border-radius: 4px;
    box-shadow: 0 4px 12px rgba(0,0,0,.08); position: relative; overflow: hidden;
    page-break-inside: avoid; break-inside: avoid;
}
.label-canvas { position: relative; overflow: hidden; }
.label-el { position: absolute; white-space: pre-wrap; }
.label-line { position: absolute; }
.label-roll-id {
    position: absolute; top: 4px; right: 6px; font-size: 7px; font-weight: 700;
    color: var(--muted); background: rgba(255,255,255,.8); padding: 1px 4px; border-radius: 3px;
}

/* ── Print styles ── */
@media print {
    .no-print { display: none !important; }
    * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; color-adjust: exact !important; }
    html, body { margin: 0; padding: 0; background: #fff; }
    .label-layout { height: auto; display: block; overflow: visible; }
    .label-sidebar { display: none; }
    .label-preview-area {
        padding: 0 !important; margin: 0 !important; gap: 0 !important;
        display: block !important; overflow: visible !important;
        background: #fff !important;
    }
    .label-card {
        box-shadow: none !important; border: none !important; border-radius: 0 !important;
        page-break-after: always; page-break-inside: avoid;
        margin: 0 !important; padding: 0 !important;
        overflow: hidden !important;
        /* Keep JS-set pixel dimensions — they match @page size at 3.78px/mm (96 DPI) */
    }
    .label-card:last-child { page-break-after: auto; }
}

@page { margin: 0; }
#dynamic-page-style { }
/* ── Built-in Default Label ── */
.builtin-label {
    width: 100%; height: 100%; display: flex; flex-direction: column;
    font-family: 'Segoe UI', Arial, sans-serif; padding: 14px 16px 10px;
}
.bl-header { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
.bl-company { font-size: 14px; font-weight: 900; color: #0f172a; line-height: 1.2; flex: 1; }
.bl-qr { flex-shrink: 0; width: 72px; height: 72px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
.bl-qr svg, .bl-qr canvas, .bl-qr img { display: block; width: 70px !important; height: 70px !important; }
.bl-divider { height: 2px; background: linear-gradient(90deg, #f97316, #fb923c, transparent); margin: 6px 0 8px; border-radius: 2px; }
.bl-roll-no {
    font-size: 22px; font-weight: 900; color: #f97316; font-family: 'Consolas', 'Courier New', monospace;
    letter-spacing: 1px; margin-bottom: 6px; line-height: 1.1;
}
.bl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 12px; flex: 1; }
.bl-field { display: flex; flex-direction: column; }
.bl-field-label { font-size: 7px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: .6px; line-height: 1.2; }
.bl-field-value { font-size: 10px; font-weight: 700; color: #1e293b; line-height: 1.3; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bl-footer { display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 6px; border-top: 1px solid #e2e8f0; }
.bl-status {
    display: inline-block; font-size: 9px; font-weight: 800; text-transform: uppercase;
    padding: 2px 10px; border-radius: 8px; letter-spacing: .5px;
}
.bl-status.available { background: #dcfce7; color: #16a34a; }
.bl-status.in-use, .bl-status.in_use { background: #dbeafe; color: #2563eb; }
.bl-status.finished { background: #fee2e2; color: #dc2626; }
.bl-status.reserved { background: #fef3c7; color: #d97706; }
.bl-status.default-status { background: #f1f5f9; color: #64748b; }
.bl-date { font-size: 8px; color: #94a3b8; font-weight: 600; }
</style>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<script>
// Fallback: if CDN fails, provide a minimal placeholder generator
if (typeof qrcode === 'undefined') {
    window.qrcode = function() {
        return {
            addData: function(){},
            make: function(){},
            getModuleCount: function(){ return 21; },
            createSvgTag: function(){ return '<svg viewBox="0 0 70 70" width="70" height="70"><rect fill="#f1f5f9" width="70" height="70" rx="4"/><text x="35" y="35" text-anchor="middle" dy=".3em" fill="#94a3b8" font-size="10" font-family="sans-serif">QR</text></svg>'; },
            createImgTag: function(){ return '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==" width="70" height="70" alt="QR">'; }
        };
    };
}
</script>
</head>
<body>

<!-- Toolbar -->
<div class="label-toolbar no-print">
    <h1>
        <i class="bi bi-printer" style="color:var(--brand)"></i>
        Print Labels
        <span class="count-badge"><?= count($rolls) ?> roll<?= count($rolls) > 1 ? 's' : '' ?></span>
    </h1>
    <div class="toolbar-actions">
        <select id="tpl-quick-select" onchange="selectTemplateById(this.value)">
            <?php foreach ($templates as $t): ?>
            <option value="<?= $t['id'] ?>" <?= $t['is_default'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= $t['paper_width'] ?>×<?= $t['paper_height'] ?>mm)</option>
            <?php endforeach; ?>
            <?php if (empty($templates)): ?>
            <option value="0">No templates found</option>
            <?php endif; ?>
        </select>
        <button class="btn-print" onclick="window.print()"><i class="bi bi-printer"></i> Print Labels</button>
        <button class="btn-close" onclick="window.close()"><i class="bi bi-x-lg"></i> Close</button>
    </div>
</div>

<div class="label-layout">
    <!-- Sidebar -->
    <div class="label-sidebar no-print">
        <div class="tpl-section-title"><i class="bi bi-palette"></i> Label Templates</div>
        <?php if (empty($templates)): ?>
        <div style="font-size:11px;color:var(--muted);padding:10px 0">No label templates found. <a href="<?= BASE_URL ?>/modules/print/index.php" style="color:var(--brand);font-weight:700">Create one in Print Studio</a></div>
        <?php endif; ?>
        <div id="tpl-list">
        <?php foreach ($templates as $t): ?>
        <div class="tpl-card<?= $t['is_default'] ? ' active' : '' ?>" data-tpl-id="<?= $t['id'] ?>" onclick="selectTemplateById(<?= $t['id'] ?>)">
            <div class="tpl-name"><?= e($t['name']) ?></div>
            <div class="tpl-size"><?= $t['paper_width'] ?> × <?= $t['paper_height'] ?> mm</div>
            <?php if ($t['is_system'] ?? false): ?><span class="tpl-badge system">System</span><?php endif; ?>
            <?php if ($t['is_default']): ?><span class="tpl-badge default">Default</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>

        <div class="roll-section">
            <div class="tpl-section-title"><i class="bi bi-list-check"></i> Rolls to Print</div>
            <label style="display:flex;align-items:center;gap:6px;font-size:11px;font-weight:700;margin-bottom:8px;cursor:pointer">
                <input type="checkbox" id="select-all-rolls" checked onchange="toggleAllRolls(this.checked)">
                Select All (<?= count($rolls) ?>)
            </label>
            <?php foreach ($rolls as $r): ?>
            <label class="roll-item active" data-roll-id="<?= $r['id'] ?>">
                <input type="checkbox" class="roll-cb" value="<?= $r['id'] ?>" checked onchange="updatePreview()">
                <span class="roll-id"><?= e($r['roll_no']) ?></span>
                <span style="color:var(--muted);font-size:10px"><?= e($r['company']) ?></span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Preview Area -->
    <div class="label-preview-area" id="label-preview-area">
        <!-- Labels rendered by JS -->
    </div>
</div>

<script>
(function(){
'use strict';

var rolls = <?= json_encode($jsRolls, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var templates = <?= json_encode($jsTemplates, JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var activeTemplateId = 0;

// Find default template
for (var i = 0; i < templates.length; i++) {
    if (templates[i].isDefault) { activeTemplateId = templates[i].id; break; }
}
if (!activeTemplateId && templates.length) activeTemplateId = templates[0].id;

/* ── Font ID → CSS family mapping (matches Firebase Print Studio) ── */
var FONT_MAP = {
    'inter':      "'Inter', sans-serif",
    'roboto':     "'Roboto', sans-serif",
    'montserrat': "'Montserrat', sans-serif",
    'poppins':    "'Poppins', sans-serif",
    'oswald':     "'Oswald', sans-serif",
    'open-sans':  "'Open Sans', sans-serif",
    'arial':      "Arial, sans-serif",
    'helvetica':  "Helvetica, sans-serif",
    'mono':       "ui-monospace, SFMono-Regular, 'Courier New', monospace"
};
function getFontFamily(id) {
    if (!id) return 'sans-serif';
    // If it's already a CSS value (contains comma or quotes), use as-is
    if (id.indexOf(',') !== -1 || id.indexOf("'") !== -1) return id;
    return FONT_MAP[id.toLowerCase()] || ("'" + id + "', sans-serif");
}

function getTemplate(id) {
    for (var i = 0; i < templates.length; i++) {
        if (templates[i].id === id) return templates[i];
    }
    return templates[0] || null;
}

function getSelectedRollIds() {
    var out = [];
    document.querySelectorAll('.roll-cb').forEach(function(cb) {
        if (cb.checked) out.push(parseInt(cb.value));
    });
    return out;
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = String(s != null ? s : '');
    return d.innerHTML;
}

/* ── QR Code Generator (optional size, default 70px) ── */
function generateQR(data, size) {
    size = size || 70;
    try {
        if (typeof qrcode !== 'function') throw new Error('QR lib not loaded');
        var qr = qrcode(0, 'M');
        qr.addData(String(data || ''));
        qr.make();
        var modules = qr.getModuleCount();
        var cellSize = Math.max(1, Math.floor(size / modules));
        return qr.createSvgTag(cellSize, 0);
    } catch (e) {
        return '<svg viewBox="0 0 70 70" width="' + size + '" height="' + size + '"><rect fill="#f1f5f9" width="70" height="70" rx="6"/><text x="35" y="32" text-anchor="middle" fill="#94a3b8" font-size="9" font-family="sans-serif">QR Code</text><text x="35" y="44" text-anchor="middle" fill="#cbd5e1" font-size="7" font-family="sans-serif">unavailable</text></svg>';
    }
}

/* ── Status class helper ── */
function getStatusClass(status) {
    var s = String(status || '').toLowerCase().replace(/[\s-]/g, '_');
    if (s === 'available' || s === 'in_stock') return 'available';
    if (s === 'in_use' || s === 'in_process') return 'in-use';
    if (s === 'finished' || s === 'used' || s === 'consumed') return 'finished';
    if (s === 'reserved' || s === 'hold') return 'reserved';
    return 'default-status';
}

/* ── Placeholder replacer (supports {key}, {{key}}, and {{dotted.key}}) ── */
function replacePlaceholders(text, roll) {
    return String(text || '').replace(/\{\{([^}]+)\}\}|\{(\w+)\}/g, function(match, dblKey, sglKey) {
        var key = (dblKey || sglKey || '').trim();
        if (roll[key] !== undefined && roll[key] !== null && roll[key] !== '') return roll[key];
        return match;
    });
}

/* ── Built-in Professional Label (for system/default template) ── */
function renderBuiltinLabel(roll, tpl) {
    var scale = 3.78;
    var w = tpl.paperWidth * scale;
    var h = tpl.paperHeight * scale;

    var card = document.createElement('div');
    card.className = 'label-card';
    card.style.width = w + 'px';
    card.style.height = h + 'px';

    try {
        var qrData = roll.view_url || (window.location.origin + '/modules/paper_stock/view.php?id=' + roll.id);

        var qrSvg = generateQR(qrData);
        var statusCls = getStatusClass(roll.status);

        var html = '<div class="builtin-label">';
        html += '<div class="bl-header">';
        html += '<div class="bl-company">' + escHtml(roll.company_name) + '</div>';
        html += '<div class="bl-qr">' + qrSvg + '</div>';
        html += '</div>';
        html += '<div class="bl-divider"></div>';
        html += '<div class="bl-roll-no">' + escHtml(roll.roll_no) + '</div>';
        html += '<div class="bl-grid">';
        html += '<div class="bl-field"><span class="bl-field-label">Company / Mill</span><span class="bl-field-value">' + escHtml(roll.company || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Paper Type</span><span class="bl-field-value">' + escHtml(roll.paper_type || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Width</span><span class="bl-field-value">' + escHtml(roll.width_mm || '—') + ' mm</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Length</span><span class="bl-field-value">' + escHtml(roll.length_mtr || '—') + ' MTR</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">GSM</span><span class="bl-field-value">' + escHtml(roll.gsm || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Weight</span><span class="bl-field-value">' + escHtml(roll.weight_kg || '—') + ' KG</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">SQM</span><span class="bl-field-value">' + escHtml(roll.sqm || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Lot / Batch</span><span class="bl-field-value">' + escHtml(roll.lot_batch_no || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Job No</span><span class="bl-field-value">' + escHtml(roll.job_no || '—') + '</span></div>';
        html += '<div class="bl-field"><span class="bl-field-label">Job Size</span><span class="bl-field-value">' + escHtml(roll.job_size || '—') + '</span></div>';
        html += '</div>';
        html += '<div class="bl-footer">';
        html += '<span class="bl-status ' + statusCls + '">' + escHtml(roll.status || 'Unknown') + '</span>';
        html += '<span class="bl-date">' + escHtml(roll.date_received || '') + '</span>';
        html += '</div>';
        html += '</div>';
        card.innerHTML = html;
    } catch (e) {
        card.innerHTML = '<div style="padding:16px;font-size:12px;color:#ef4444"><b>Render Error</b><br>' + escHtml(roll.roll_no) + '</div>';
    }
    return card;
}

/* ── Print Studio custom template renderer ── */
function renderCustomLabel(roll, tpl) {
    var scale = 3.78;
    var w = tpl.paperWidth * scale;
    var h = tpl.paperHeight * scale;

    var card = document.createElement('div');
    card.className = 'label-card';
    card.style.width = w + 'px';
    card.style.height = h + 'px';

    var canvas = document.createElement('div');
    canvas.style.width = '100%';
    canvas.style.height = '100%';
    canvas.style.position = 'relative';
    canvas.style.overflow = 'hidden';

    // Background image
    if (tpl.background && tpl.background.image) {
        var bgDiv = document.createElement('div');
        bgDiv.style.position = 'absolute';
        bgDiv.style.inset = '0';
        bgDiv.style.zIndex = '0';
        bgDiv.style.pointerEvents = 'none';
        bgDiv.style.opacity = (tpl.background.opacity != null) ? tpl.background.opacity : 1;
        var bgImg = document.createElement('img');
        bgImg.src = tpl.background.image;
        bgImg.style.width = '100%';
        bgImg.style.height = '100%';
        bgImg.style.objectFit = 'contain';
        bgDiv.appendChild(bgImg);
        canvas.appendChild(bgDiv);
    }

    (tpl.elements || []).forEach(function(el) {
        // Print Studio format: {x, y, width, height, content, style:{...}, type, rotate}
        var sty = el.style || {};
        var px = parseFloat(el.x) || 0;
        var py = parseFloat(el.y) || 0;
        var pw = parseFloat(el.width) || 0;
        var ph = parseFloat(el.height) || 0;
        var rot = parseFloat(el.rotate) || 0;

        if (el.type === 'text') {
            var align = sty.textAlign || 'left';
            var jc = align === 'center' ? 'center' : (align === 'right' ? 'flex-end' : 'flex-start');
            var div = document.createElement('div');
            div.style.position = 'absolute';
            div.style.left = px + 'px';
            div.style.top = py + 'px';
            div.style.width = pw + 'px';
            div.style.height = ph + 'px';
            div.style.overflow = 'hidden';
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.justifyContent = jc;
            div.style.zIndex = '1';
            if (sty.backgroundColor && sty.backgroundColor !== 'transparent') {
                div.style.backgroundColor = sty.backgroundColor;
            }
            if (parseFloat(sty.borderWidth) > 0) {
                div.style.border = (sty.borderWidth || 1) + 'px ' + (sty.lineStyle || 'solid') + ' ' + (sty.borderColor || '#000');
            }
            if (parseFloat(sty.borderRadius) > 0) {
                div.style.borderRadius = sty.borderRadius + 'px';
            }
            if (rot) div.style.transform = 'rotate(' + rot + 'deg)';
            var content = replacePlaceholders(el.content || '', roll);
            var textInner = document.createElement('div');
            textInner.style.width = '100%';
            textInner.style.textAlign = align;
            textInner.style.fontSize = (sty.fontSize || 14) + 'px';
            textInner.style.fontWeight = sty.fontWeight || 'normal';
            textInner.style.fontFamily = getFontFamily(sty.fontFamily);
            textInner.style.color = sty.color || '#000';
            textInner.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
            textInner.style.wordBreak = 'break-word';
            textInner.style.lineHeight = '1.3';
            textInner.textContent = content;
            div.appendChild(textInner);
            canvas.appendChild(div);
        }
        else if (el.type === 'qr' || el.type === 'barcode') {
            var qrWrap = document.createElement('div');
            qrWrap.style.position = 'absolute';
            qrWrap.style.left = px + 'px';
            qrWrap.style.top = py + 'px';
            qrWrap.style.width = pw + 'px';
            qrWrap.style.height = ph + 'px';
            qrWrap.style.display = 'flex';
            qrWrap.style.alignItems = 'center';
            qrWrap.style.justifyContent = 'center';
            qrWrap.style.zIndex = '1';
            if (rot) qrWrap.style.transform = 'rotate(' + rot + 'deg)';
            var qrContent = replacePlaceholders(el.content || '', roll);
            if (!qrContent || qrContent === '') {
                qrContent = roll.view_url || (window.location.origin + '/modules/paper_stock/view.php?id=' + roll.id);
            }
            var qrSz = Math.min(pw, ph);
            qrWrap.innerHTML = generateQR(qrContent, qrSz);
            var svgEl = qrWrap.querySelector('svg');
            if (svgEl) {
                svgEl.style.width = qrSz + 'px';
                svgEl.style.height = qrSz + 'px';
            }
            canvas.appendChild(qrWrap);
        }
        else if (el.type === 'line' || el.type === 'divider') {
            var line = document.createElement('div');
            line.style.position = 'absolute';
            line.style.left = px + 'px';
            line.style.top = py + 'px';
            line.style.width = pw + 'px';
            line.style.height = Math.max(ph, parseFloat(sty.borderWidth) || 1) + 'px';
            line.style.background = sty.borderColor || sty.color || '#000';
            line.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
            line.style.zIndex = '1';
            if (rot) line.style.transform = 'rotate(' + rot + 'deg)';
            canvas.appendChild(line);
        }
        else if (el.type === 'rect' || el.type === 'shape' || el.type === 'circle') {
            var rect = document.createElement('div');
            rect.style.position = 'absolute';
            rect.style.left = px + 'px';
            rect.style.top = py + 'px';
            rect.style.width = pw + 'px';
            rect.style.height = ph + 'px';
            if (sty.backgroundColor && sty.backgroundColor !== 'transparent') {
                rect.style.backgroundColor = sty.backgroundColor;
            }
            if (parseFloat(sty.borderWidth) > 0) {
                rect.style.border = (sty.borderWidth || 1) + 'px ' + (sty.lineStyle || 'solid') + ' ' + (sty.borderColor || '#000');
            }
            var br = parseFloat(sty.borderRadius) || 0;
            if (el.type === 'circle') br = Math.max(pw, ph);
            if (br > 0) rect.style.borderRadius = br + 'px';
            rect.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
            rect.style.zIndex = '1';
            if (rot) rect.style.transform = 'rotate(' + rot + 'deg)';
            canvas.appendChild(rect);
        }
        else if (el.type === 'image') {
            var imgWrap = document.createElement('div');
            imgWrap.style.position = 'absolute';
            imgWrap.style.left = px + 'px';
            imgWrap.style.top = py + 'px';
            imgWrap.style.width = pw + 'px';
            imgWrap.style.height = ph + 'px';
            imgWrap.style.zIndex = '1';
            imgWrap.style.overflow = 'hidden';
            if (rot) imgWrap.style.transform = 'rotate(' + rot + 'deg)';
            if (parseFloat(sty.borderRadius) > 0) {
                imgWrap.style.borderRadius = sty.borderRadius + 'px';
            }
            var imgSrc = el.content || el.src || '';
            if (imgSrc) {
                var img = document.createElement('img');
                img.src = imgSrc;
                img.style.width = '100%';
                img.style.height = '100%';
                img.style.objectFit = 'contain';
                img.style.opacity = (sty.opacity != null) ? sty.opacity : 1;
                img.style.display = 'block';
                img.onerror = function() { this.style.display = 'none'; };
                imgWrap.appendChild(img);
            } else {
                imgWrap.style.backgroundColor = '#f1f5f9';
                imgWrap.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:#94a3b8;font-size:10px">No Image</div>';
            }
            canvas.appendChild(imgWrap);
        }
        else if (el.type === 'table') {
            // Table element — render as simple grid
            var tbl = document.createElement('div');
            tbl.style.position = 'absolute';
            tbl.style.left = px + 'px';
            tbl.style.top = py + 'px';
            tbl.style.width = pw + 'px';
            tbl.style.height = ph + 'px';
            tbl.style.zIndex = '1';
            tbl.style.fontSize = (sty.fontSize || 10) + 'px';
            tbl.style.fontFamily = getFontFamily(sty.fontFamily);
            tbl.style.color = sty.color || '#000';
            tbl.style.border = '1px solid ' + (sty.borderColor || '#000');
            tbl.style.overflow = 'hidden';
            var content = replacePlaceholders(el.content || '', roll);
            tbl.textContent = content;
            canvas.appendChild(tbl);
        }
    });

    card.appendChild(canvas);
    return card;
}

/* ── Main render dispatcher ── */
function renderLabel(roll, tpl) {
    // System template or builtin element → professional built-in layout
    if (tpl.isSystem) return renderBuiltinLabel(roll, tpl);

    var els = tpl.elements || [];
    // Check for builtin marker
    for (var i = 0; i < els.length; i++) {
        if (els[i].type === 'builtin') return renderBuiltinLabel(roll, tpl);
    }
    // Empty elements → fallback to built-in
    if (els.length === 0) return renderBuiltinLabel(roll, tpl);

    // Custom Print Studio template
    return renderCustomLabel(roll, tpl);
}

window.updatePreview = function() {
    var area = document.getElementById('label-preview-area');
    area.innerHTML = '';
    var tpl = getTemplate(activeTemplateId);
    if (!tpl) {
        area.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:14px;padding:40px"><i class="bi bi-exclamation-triangle" style="font-size:32px;display:block;margin-bottom:10px"></i>No template selected.</div>';
        return;
    }

    // Dynamic @page size based on template paper dimensions
    var dynStyle = document.getElementById('dynamic-page-style');
    if (!dynStyle) {
        dynStyle = document.createElement('style');
        dynStyle.id = 'dynamic-page-style';
        document.head.appendChild(dynStyle);
    }
    dynStyle.textContent = '@page { size: ' + tpl.paperWidth + 'mm ' + tpl.paperHeight + 'mm; margin: 0; }';

    var selectedIds = getSelectedRollIds();
    var rendered = 0;
    for (var i = 0; i < rolls.length; i++) {
        if (selectedIds.indexOf(rolls[i].id) === -1) continue;
        area.appendChild(renderLabel(rolls[i], tpl));
        rendered++;
    }

    if (rendered === 0) {
        area.innerHTML = '<div style="text-align:center;color:#94a3b8;font-size:14px;padding:40px"><i class="bi bi-check2-square" style="font-size:32px;display:block;margin-bottom:10px"></i>No rolls selected. Check rolls in the sidebar to preview labels.</div>';
    }

    // Sync sidebar roll items
    document.querySelectorAll('.roll-item').forEach(function(item) {
        var cb = item.querySelector('.roll-cb');
        item.classList.toggle('active', cb && cb.checked);
    });
};

window.selectTemplateById = function(id) {
    activeTemplateId = parseInt(id);
    document.querySelectorAll('.tpl-card').forEach(function(c) {
        c.classList.toggle('active', parseInt(c.dataset.tplId) === activeTemplateId);
    });
    var sel = document.getElementById('tpl-quick-select');
    if (sel) sel.value = activeTemplateId;
    updatePreview();
};

window.toggleAllRolls = function(checked) {
    document.querySelectorAll('.roll-cb').forEach(function(cb) { cb.checked = checked; });
    updatePreview();
};

// Initial render
updatePreview();

})();
</script>
</body>
</html>
