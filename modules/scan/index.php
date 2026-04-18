<?php
// ============================================================
// ERP System — Scan Terminal: Full-screen scanning interface
// Designed for production floor / warehouse use
// ============================================================
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

function scan_resolve_payload(mysqli $db, string $raw): array {
  $raw = trim($raw);
  if ($raw === '') {
    return ['ok' => false, 'error' => 'Empty QR'];
  }

  $emitJobByNo = static function(string $jobNo): array {
    return [
      'ok' => true,
      'type' => 'job',
      'label' => 'Job Journey: ' . $jobNo,
      'url' => BASE_URL . '/modules/scan/job.php?jn=' . urlencode($jobNo),
    ];
  };

  if (preg_match('/modules\/scan\/(?:job|dossier)\.php\?jn=(.+)$/i', $raw, $m)) {
    return $emitJobByNo(urldecode($m[1]));
  }

  if (preg_match('/^J([0-9A-Z]{1,10})$/i', strtoupper($raw), $m)) {
    $jobId = (int)base_convert($m[1], 36, 10);
    if ($jobId > 0) {
      $stmt = $db->prepare("SELECT job_no FROM jobs WHERE id = ? AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('i', $jobId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row && trim((string)($row['job_no'] ?? '')) !== '') {
          return $emitJobByNo((string)$row['job_no']);
        }
      }
    }
  }

  if (preg_match('/^JOB\s*:\s*(.+)$/i', $raw, $m)) {
    $jn = trim((string)$m[1]);
    if ($jn !== '') {
      return $emitJobByNo($jn);
    }
  }

  if (preg_match('/^[A-Z0-9][A-Z0-9\/_\-]{4,60}$/i', $raw)) {
    $jobNoRaw = trim((string)$raw);
    $stmt = $db->prepare("SELECT job_no FROM jobs WHERE UPPER(TRIM(job_no)) = UPPER(TRIM(?)) AND (deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00') LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('s', $jobNoRaw);
      $stmt->execute();
      $jobRow = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($jobRow && trim((string)($jobRow['job_no'] ?? '')) !== '') {
        return $emitJobByNo((string)$jobRow['job_no']);
      }
    }
  }

  if (preg_match('/modules\/paper_stock\/view\.php\?id=(\d+)/i', $raw, $m)) {
    $rollId = (int)$m[1];
    $stmt = $db->prepare("SELECT id, roll_no, paper_type, status FROM paper_stock WHERE id = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('i', $rollId);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($row) {
        return [
          'ok' => true,
          'type' => 'roll',
          'label' => 'Roll ' . $row['roll_no'] . ' — ' . ($row['paper_type'] ?? '') . ' (' . ($row['status'] ?? '') . ')',
          'url' => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$row['id'],
        ];
      }
    }
    return ['ok' => false, 'type' => 'roll_not_found', 'error' => 'Roll ID ' . $rollId . ' not found in ERP.'];
  }

  if (preg_match('/^ROLL\s*:\s*([^\|]+)/i', $raw, $m)) {
    $rollNo = strtoupper(trim($m[1]));
    $stmt = $db->prepare("SELECT id, roll_no, paper_type, status FROM paper_stock WHERE UPPER(TRIM(roll_no)) = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('s', $rollNo);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($row) {
        return [
          'ok' => true,
          'type' => 'roll',
          'label' => 'Roll ' . $row['roll_no'] . ' — ' . ($row['paper_type'] ?? '') . ' (' . ($row['status'] ?? '') . ')',
          'url' => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$row['id'],
        ];
      }
    }
    return ['ok' => false, 'type' => 'roll_not_found', 'error' => 'Roll "' . htmlspecialchars($rollNo, ENT_QUOTES) . '" not found in ERP.'];
  }

  if (BASE_URL !== '' && stripos($raw, BASE_URL) === 0) {
    return ['ok' => true, 'type' => 'url', 'label' => 'ERP Page', 'url' => $raw];
  }

  $decoded = json_decode($raw, true);
  if (is_array($decoded) && ($decoded['type'] ?? '') === 'slitting-traceability') {
    return [
      'ok' => true,
      'type' => 'slitting',
      'label' => 'Slitting Batch: ' . ($decoded['batch_no'] ?? ''),
      'url' => BASE_URL . '/modules/inventory/slitting/index.php',
    ];
  }

  if (preg_match('/^[A-Z0-9\/\-]+$/i', $raw) && strlen($raw) <= 30) {
    $rollNo = strtoupper(trim($raw));
    $stmt = $db->prepare("SELECT id, roll_no, paper_type, status FROM paper_stock WHERE UPPER(TRIM(roll_no)) = ? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param('s', $rollNo);
      $stmt->execute();
      $row = $stmt->get_result()->fetch_assoc();
      $stmt->close();
      if ($row) {
        return [
          'ok' => true,
          'type' => 'roll',
          'label' => 'Roll ' . $row['roll_no'] . ' — ' . ($row['paper_type'] ?? '') . ' (' . ($row['status'] ?? '') . ')',
          'url' => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$row['id'],
        ];
      }
    }
  }

  if (preg_match('/^R([0-9A-Z]{1,10})$/i', strtoupper($raw), $m)) {
    $rollId = (int)base_convert($m[1], 36, 10);
    if ($rollId > 0) {
      $stmt = $db->prepare("SELECT id, roll_no, paper_type, status FROM paper_stock WHERE id = ? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('i', $rollId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
          return [
            'ok' => true,
            'type' => 'roll',
            'label' => 'Roll ' . $row['roll_no'] . ' — ' . ($row['paper_type'] ?? '') . ' (' . ($row['status'] ?? '') . ')',
            'url' => BASE_URL . '/modules/paper_stock/view.php?id=' . (int)$row['id'],
          ];
        }
      }
    }
  }

  return [
    'ok' => false,
    'type' => 'unknown',
    'error' => 'QR not recognised. Scanned: ' . htmlspecialchars(mb_substr($raw, 0, 80), ENT_QUOTES),
  ];
}

// ── QR Resolve API (?action=resolve) ────────────────────────
// Called via fetch from the dashboard scanner widget.
// Only requires login — NOT full scan-page RBAC permission.
// Dashboard-only users (e.g. Jumbo group) can use this API
// without having access to the Scan Terminal page itself.
if (($_GET['action'] ?? '') === 'resolve') {
    if (session_status() === PHP_SESSION_NONE) session_start();
    header('Content-Type: application/json');
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
        exit;
    }
    $db  = getDB();
    $raw = trim((string)($_POST['qr'] ?? $_GET['qr'] ?? ''));
    echo json_encode(scan_resolve_payload($db, $raw));
    exit;
}

// Full Scan Terminal page — RBAC check required from here
require_once __DIR__ . '/../../includes/auth_check.php';

require_once __DIR__ . '/../audit/setup_tables.php';
ensureAuditTables();

$db   = getDB();
$csrf = generateCSRF();

$directQr = trim((string)($_GET['qr'] ?? ''));
if ($directQr !== '') {
  $resolved = scan_resolve_payload($db, $directQr);
  if (!empty($resolved['ok']) && !empty($resolved['url'])) {
    redirect((string)$resolved['url']);
  }
  setFlash('error', (string)($resolved['error'] ?? 'QR not recognised.'));
  redirect(BASE_URL . '/modules/scan/index.php');
}

$pageTitle = 'Scan Terminal';
include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Scan Terminal Styles ─────────────────────────────────── */
.st-wrap{min-height:calc(100vh - 80px);display:flex;flex-direction:column}

/* Session selector bar */
.st-session-bar{background:#0f172a;border-radius:14px;padding:16px 20px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px}
.st-session-bar .sb-left{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.st-session-bar .sb-label{color:#94a3b8;font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em}
.st-session-bar select{height:40px;border:2px solid rgba(255,255,255,.15);border-radius:10px;background:rgba(255,255,255,.08);color:#fff;padding:0 14px;font-size:.85rem;font-weight:600;min-width:200px;cursor:pointer}
.st-session-bar select option{background:#1e293b;color:#fff}
.st-session-bar .sb-status{font-size:.78rem;display:flex;align-items:center;gap:6px}
.st-session-bar .sb-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.st-session-bar .sb-dot.live{background:#22c55e;box-shadow:0 0 8px rgba(34,197,94,.5);animation:sbPulse 2s infinite}
.st-session-bar .sb-dot.locked{background:#64748b}
@keyframes sbPulse{0%,100%{opacity:1}50%{opacity:.5}}

/* Main scanning area */
.st-main{display:grid;grid-template-columns:1fr 1fr;gap:20px;flex:1}
@media(max-width:900px){ .st-main{grid-template-columns:1fr} }

/* Left: Scan zone */
.st-scan-zone{background:#fff;border:1px solid var(--border);border-radius:16px;padding:24px;display:flex;flex-direction:column}
.st-scan-title{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#94a3b8;margin-bottom:16px;display:flex;align-items:center;gap:8px}

.st-input-wrap{display:flex;gap:10px;margin-bottom:20px}
.st-input{flex:1;height:64px;border:3px solid var(--border);border-radius:14px;padding:0 20px;font-size:1.5rem;font-weight:800;font-family:monospace;text-align:center;transition:border-color .2s}
.st-input:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 6px rgba(34,197,94,.12)}
.st-input.success{border-color:#22c55e;background:#f0fdf4;animation:stFlash .3s}
.st-input.warning{border-color:#f59e0b;background:#fffbeb;animation:stFlash .3s}
.st-input.error{border-color:#ef4444;background:#fef2f2;animation:stFlash .3s}
@keyframes stFlash{0%{transform:scale(1)}50%{transform:scale(1.02)}100%{transform:scale(1)}}

.st-cam-btn{height:64px;width:64px;border-radius:14px;border:3px solid var(--border);background:#fff;color:var(--text-muted);font-size:1.4rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s}
.st-cam-btn:hover{border-color:var(--brand);color:var(--brand)}
.st-cam-btn.active{border-color:#7c3aed;color:#7c3aed;background:#faf5ff}

/* Camera viewport */
.st-camera{display:none;background:#000;border-radius:12px;overflow:hidden;margin-bottom:20px}
.st-camera.open{display:block}
.st-camera #st-camera-reader{text-align:center}
.st-camera #st-camera-reader video{display:block;margin:0 auto;width:100% !important;height:auto !important;max-height:none !important;object-fit:cover;background:#000}
.st-camera #st-camera-reader #qr-shaded-region{margin:0 auto !important}

/* Duplicate popup */
.st-dup-popup{display:none;position:fixed;top:50%;left:50%;transform:translate(-50%,-50%) scale(.8);z-index:9999;background:#fff;border:3px solid #dc2626;border-radius:16px;padding:28px 36px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);opacity:0;transition:all .2s ease}
.st-dup-popup.show{display:block;opacity:1;transform:translate(-50%,-50%) scale(1)}
.st-dup-popup .dup-icon{font-size:2.5rem;color:#dc2626;margin-bottom:8px}
.st-dup-popup .dup-title{font-size:1.1rem;font-weight:800;color:#dc2626;margin-bottom:4px}
.st-dup-popup .dup-roll{font-family:monospace;font-size:1rem;font-weight:700;color:#0f172a;margin-bottom:4px}
.st-dup-popup .dup-msg{font-size:.82rem;color:#64748b}
.st-dup-overlay{display:none;position:fixed;inset:0;z-index:9998;background:rgba(220,38,38,.08)}
.st-dup-overlay.show{display:block}

/* Last scan flash card */
.st-last-scan{display:none;border-radius:14px;padding:20px;margin-bottom:16px;text-align:center;transition:all .2s}
.st-last-scan.visible{display:block}
.st-last-scan.matched{background:#f0fdf4;border:2px solid #86efac}
.st-last-scan.unknown{background:#fef2f2;border:2px solid #fca5a5}
.st-last-scan.duplicate{background:#fffbeb;border:2px solid #fde68a}
.st-last-icon{font-size:2.2rem;margin-bottom:6px}
.st-last-roll{font-size:1.6rem;font-family:monospace;font-weight:900}
.st-last-detail{font-size:.82rem;color:#64748b;margin-top:4px}

/* Stats strip */
.st-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:auto;padding-top:20px}
.st-stat{background:#f8fafc;border:1px solid var(--border);border-radius:12px;padding:12px;text-align:center}
.st-stat .sv{font-size:1.4rem;font-weight:900;color:#0f172a}
.st-stat .sl{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:#94a3b8;margin-top:2px}
.st-stat.green{border-color:#bbf7d0;background:#f0fdf4}.st-stat.green .sv{color:#16a34a}
.st-stat.red{border-color:#fecaca;background:#fef2f2}.st-stat.red .sv{color:#dc2626}
.st-stat.amber{border-color:#fde68a;background:#fffbeb}.st-stat.amber .sv{color:#d97706}
.st-stat.blue{border-color:#bfdbfe;background:#eff6ff}.st-stat.blue .sv{color:#2563eb}

/* Right: Recent scans feed */
.st-feed-zone{background:#fff;border:1px solid var(--border);border-radius:16px;padding:24px;display:flex;flex-direction:column}
.st-feed-title{font-size:.72rem;font-weight:800;text-transform:uppercase;letter-spacing:.12em;color:#94a3b8;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between}
.st-feed{flex:1;overflow-y:auto;scrollbar-width:thin;max-height:calc(100vh - 320px)}
.st-fi{display:flex;align-items:center;gap:14px;padding:10px 12px;border-bottom:1px solid #f1f5f9;border-radius:8px;transition:background .1s}
.st-fi:hover{background:#f8fafc}
.st-fi:last-child{border:none}
.st-fi-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0}
.st-fi-dot.matched{background:#22c55e}
.st-fi-dot.unknown{background:#ef4444}
.st-fi-body{flex:1;min-width:0}
.st-fi-roll{font-family:monospace;font-weight:700;font-size:.88rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.st-fi-meta{font-size:.72rem;color:#94a3b8;margin-top:2px}
.st-fi-time{font-size:.72rem;color:#94a3b8;white-space:nowrap}
.st-fi-num{width:28px;height:28px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:800;color:#64748b;flex-shrink:0}

.st-empty{text-align:center;padding:40px;color:#94a3b8;flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center}
.st-empty i{font-size:3rem;opacity:.2;display:block;margin-bottom:12px}
.st-empty p{font-size:.88rem}

/* No session selected overlay */
.st-no-session{text-align:center;padding:60px 20px;grid-column:1/-1;color:#94a3b8}
.st-no-session i{font-size:3.5rem;opacity:.15;display:block;margin-bottom:16px}
.st-no-session h3{font-size:1.1rem;font-weight:700;color:#64748b;margin-bottom:6px}
.st-no-session p{font-size:.85rem}

/* Accessibility: bigger touch targets for mobile */
@media(max-width:900px){
  .st-main{grid-template-columns:1fr}
}
@media(max-width:768px){
  .st-session-bar{flex-direction:column;align-items:stretch;gap:10px;padding:14px 16px}
  .st-session-bar .sb-left{flex-direction:column;gap:8px}
  .st-session-bar select{min-width:unset;width:100%}
  .st-scan-zone,.st-feed-zone{padding:16px}
  .st-input-wrap{flex-direction:column}
  .st-input{height:56px;font-size:1.2rem}
  .st-cam-btn{width:100%;height:48px}
  .st-last-scan{padding:14px}
  .st-last-roll{font-size:1.2rem}
  .st-stats{grid-template-columns:repeat(2,1fr);gap:8px}
  .st-stat{padding:10px 8px}
  .st-stat .sv{font-size:1.1rem}
  .st-feed{max-height:350px}
  .st-fi{padding:8px 8px;gap:10px}
  .st-fi-roll{font-size:.8rem}
  .st-no-session{padding:40px 16px}
}
@media(max-width:480px){
  .st-input{height:50px;font-size:1rem}
  .st-stats{grid-template-columns:repeat(2,1fr)}
  .st-stat .sv{font-size:1rem}
}
</style>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">›</span>
  <span>Inventory Hub</span>
  <span class="breadcrumb-sep">›</span>
  <span>Physical Stock Check</span>
  <span class="breadcrumb-sep">›</span>
  <span>Scan Terminal</span>
</div>

<div class="st-wrap">

  <!-- ── Session Bar ───────────────────────────────────────── -->
  <div class="st-session-bar">
    <div class="sb-left">
      <div class="sb-label"><i class="bi bi-collection"></i> Audit Session</div>
      <select id="st-session-select" onchange="stSelectSession(this.value)">
        <option value="">— Select a session —</option>
      </select>
      <div class="sb-status" id="st-session-status" style="display:none">
        <span class="sb-dot" id="st-dot"></span>
        <span id="st-status-text" style="color:#e2e8f0;font-size:.82rem;font-weight:600">—</span>
      </div>
    </div>
    <a href="<?= BASE_URL ?>/modules/audit/index.php" class="btn btn-sm" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2)">
      <i class="bi bi-clipboard-check"></i> Open Audit Hub
    </a>
  </div>

  <!-- ── Main Content ──────────────────────────────────────── -->
  <div class="st-main" id="st-main">
    <div class="st-no-session" id="st-no-session">
      <i class="bi bi-upc-scan"></i>
      <h3>No Session Selected</h3>
      <p>Select an active audit session from the dropdown above to start scanning.<br>Or create a new session in the <a href="<?= BASE_URL ?>/modules/audit/index.php" style="color:var(--brand);font-weight:600">Audit Hub</a>.</p>
    </div>

    <!-- Left: Scan Zone (hidden until session selected) -->
    <div class="st-scan-zone" id="st-scan-zone" style="display:none">
      <div class="st-scan-title"><i class="bi bi-upc-scan"></i> Scan or Enter Roll Number</div>

      <div class="st-input-wrap">
        <input type="text" class="st-input" id="st-input" placeholder="Scan here..." autocomplete="off" inputmode="text">
        <button class="st-cam-btn" id="st-cam-toggle" onclick="stToggleCamera()" title="Toggle Camera"><i class="bi bi-camera-video"></i></button>
      </div>

      <div class="st-camera" id="st-camera">
        <div id="st-camera-reader" style="width:100%"></div>
      </div>

      <!-- Last scan flash -->
      <div class="st-last-scan" id="st-last-scan">
        <div class="st-last-icon"><i class="bi bi-check-circle-fill"></i></div>
        <div class="st-last-roll" id="st-last-roll">—</div>
        <div class="st-last-detail" id="st-last-detail">—</div>
      </div>

      <!-- Stats -->
      <div class="st-stats">
        <div class="st-stat blue"><div class="sv" id="ss-scanned">0</div><div class="sl">Scanned</div></div>
        <div class="st-stat green"><div class="sv" id="ss-matched">0</div><div class="sl">Matched</div></div>
        <div class="st-stat red"><div class="sv" id="ss-missing">0</div><div class="sl">Missing</div></div>
        <div class="st-stat amber"><div class="sv" id="ss-extra">0</div><div class="sl">Extra</div></div>
      </div>
    </div>

    <!-- Right: Feed Zone (hidden until session selected) -->
    <div class="st-feed-zone" id="st-feed-zone" style="display:none">
      <div class="st-feed-title">
        <span><i class="bi bi-clock-history"></i> Recent Scans</span>
        <span id="st-feed-count" style="font-size:.75rem;font-weight:700;color:#64748b">0 scanned</span>
      </div>
      <div class="st-feed" id="st-feed"></div>
      <div class="st-empty" id="st-feed-empty">
        <i class="bi bi-inbox"></i>
        <p>Scanned rolls will appear here in real-time.</p>
      </div>
    </div>
  </div>

</div>

<!-- ── Duplicate Popup ─────────────────────────────────────── -->
<div class="st-dup-overlay" id="st-dup-overlay"></div>
<div class="st-dup-popup" id="st-dup-popup">
  <div class="dup-icon"><i class="bi bi-ban"></i></div>
  <div class="dup-title">Duplicate Scan Not Allowed</div>
  <div class="dup-roll" id="st-dup-roll">—</div>
  <div class="dup-msg">This roll has already been scanned in this session.</div>
</div>

<!-- ── html5-qrcode CDN ────────────────────────────────────── -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
(function(){
'use strict';

var API  = '<?= BASE_URL ?>/modules/audit/api.php';
var CSRF = '<?= e($csrf) ?>';

var currentSessionId = null;
var currentSession   = null;
var html5Scanner     = null;
var cameraActive     = false;
var scanCount        = 0;
var lastScannedCode  = '';
var scanCooldown     = false;

// ── Audio ─────────────────────────────────────────────────
var audioCtx = null;
function getAudioCtx(){ if(!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)(); return audioCtx; }

function playTone(freq, dur, type){
  try {
    var ctx = getAudioCtx();
    var osc = ctx.createOscillator();
    var gain = ctx.createGain();
    osc.type = type || 'sine';
    osc.frequency.value = freq;
    gain.gain.value = .3;
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + dur/1000);
  } catch(e){}
}

// ── Helpers ───────────────────────────────────────────────
function $(id){ return document.getElementById(id); }

function postAPI(action, data, cb){
  var fd = new FormData();
  fd.append('action', action);
  fd.append('csrf_token', CSRF);
  for(var k in data) fd.append(k, data[k]);
  fetch(API, {method:'POST', body:fd, credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(cb)
    .catch(function(e){ console.error(e); });
}

function getAPI(action, params, cb){
  var url = API + '?action=' + action;
  for(var k in params) url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
  fetch(url, {credentials:'same-origin'})
    .then(function(r){ return r.json(); })
    .then(cb)
    .catch(function(e){ console.error(e); });
}

function escHtml(s){ var d=document.createElement('div'); d.textContent=s||''; return d.innerHTML; }

function fmtTime(d){
  if(!d) return '';
  var dt = new Date(d.replace(' ','T'));
  return dt.toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit',second:'2-digit',hour12:true});
}

// ── Load Sessions into Dropdown ───────────────────────────
function loadSessions(){
  getAPI('list_sessions', {}, function(res){
    if(!res.ok) return;
    var sel = $('st-session-select');
    var html = '<option value="">— Select a session —</option>';
    res.sessions.forEach(function(s){
      html += '<option value="'+s.id+'">'+escHtml(s.session_name)+' ('+escHtml(s.audit_id)+') — '+escHtml(s.status)+'</option>';
    });
    sel.innerHTML = html;
  });
}

// ── Select Session ────────────────────────────────────────
window.stSelectSession = function(id){
  if(!id){
    currentSessionId = null;
    currentSession = null;
    $('st-no-session').style.display = '';
    $('st-scan-zone').style.display = 'none';
    $('st-feed-zone').style.display = 'none';
    $('st-session-status').style.display = 'none';
    return;
  }

  currentSessionId = id;
  getAPI('get_session', {id:id}, function(res){
    if(!res.ok){ alert(res.error); return; }
    currentSession = res.session;

    $('st-no-session').style.display = 'none';
    $('st-scan-zone').style.display = '';
    $('st-feed-zone').style.display = '';

    var isFinalized = currentSession.status === 'Finalized';
    $('st-session-status').style.display = '';
    $('st-dot').className = 'sb-dot ' + (isFinalized ? 'locked' : 'live');
    $('st-status-text').textContent = isFinalized ? 'Finalized (Read-only)' : 'Live — Scanning Active';
    $('st-status-text').style.color = isFinalized ? '#94a3b8' : '#86efac';

    $('st-input').disabled = isFinalized;
    $('st-cam-toggle').disabled = isFinalized;

    renderFeed(currentSession.scanned_rolls || []);
    loadStats();

    if(!isFinalized){
      setTimeout(function(){ $('st-input').focus(); }, 100);
    }
  });
};

// ── Render Feed ───────────────────────────────────────────
function renderFeed(scans){
  var feed = $('st-feed');
  var empty = $('st-feed-empty');
  $('st-feed-count').textContent = scans.length + ' scanned';

  if(scans.length === 0){ feed.innerHTML=''; empty.style.display=''; return; }
  empty.style.display='none';

  var html = '';
  var reversed = scans.slice().reverse(); // newest first
  reversed.forEach(function(s, i){
    html += '<div class="st-fi">'
      + '<div class="st-fi-num">'+(scans.length - i)+'</div>'
      + '<div class="st-fi-dot '+(s.status==='Matched'?'matched':'unknown')+'"></div>'
      + '<div class="st-fi-body">'
      +   '<div class="st-fi-roll">'+escHtml(s.roll_no)+'</div>'
      +   '<div class="st-fi-meta">'+(s.paper_type ? escHtml(s.paper_type) : 'Unknown')+(s.dimension?' · '+escHtml(s.dimension):'')+'</div>'
      + '</div>'
      + '<div class="st-fi-time">'+fmtTime(s.scan_time)+'</div>'
      + '</div>';
  });
  feed.innerHTML = html;
  feed.scrollTop = 0;
}

// ── Load Stats ────────────────────────────────────────────
function loadStats(){
  if(!currentSessionId) return;
  getAPI('reconcile', {session_id:currentSessionId}, function(res){
    if(!res.ok) return;
    $('ss-scanned').textContent = res.total_scanned;
    $('ss-matched').textContent = res.matched_count;
    $('ss-missing').textContent = res.missing_count;
    $('ss-extra').textContent = res.extra_count;
  });
}

// ── Submit Scan ───────────────────────────────────────────
function stSubmitScan(){
  var input = $('st-input');
  var val = input.value.trim();
  if(!val || !currentSessionId) return;

  var isFinalized = currentSession && currentSession.status === 'Finalized';
  if(isFinalized){ return; }

  input.disabled = true;
  postAPI('scan_roll', {session_id:currentSessionId, roll_no:val}, function(res){
    input.disabled = false;
    input.value = '';
    if(!cameraActive) input.focus();

    var lastScan = $('st-last-scan');
    var icon = lastScan.querySelector('.st-last-icon i');

    if(!res.ok){
      if(res.duplicate){
        // Duplicate
        playTone(220, 300, 'square');
        input.className = 'st-input error';
        lastScan.className = 'st-last-scan visible duplicate';
        icon.className = 'bi bi-ban';
        icon.style.color = '#dc2626';
        $('st-last-roll').textContent = val;
        $('st-last-detail').textContent = 'Duplicate — Not Allowed';
        showDupPopup(val);
      } else {
        playTone(220, 300, 'square');
        input.className = 'st-input error';
        lastScan.className = 'st-last-scan visible unknown';
        icon.className = 'bi bi-x-circle-fill';
        icon.style.color = '#dc2626';
        $('st-last-roll').textContent = val;
        $('st-last-detail').textContent = res.error || 'Error';
      }
      setTimeout(function(){ input.className = 'st-input'; }, 600);
      return;
    }

    // Success
    if(res.status === 'Matched'){
      playTone(880, 100, 'sine');
      input.className = 'st-input success';
      lastScan.className = 'st-last-scan visible matched';
      icon.className = 'bi bi-check-circle-fill';
      icon.style.color = '#16a34a';
      $('st-last-detail').textContent = 'Matched — ' + (res.paper_type || 'Found in ERP');
    } else {
      playTone(220, 200, 'square');
      input.className = 'st-input error';
      lastScan.className = 'st-last-scan visible unknown';
      icon.className = 'bi bi-question-circle-fill';
      icon.style.color = '#ef4444';
      $('st-last-detail').textContent = 'Not found in ERP inventory';
    }

    $('st-last-roll').textContent = res.roll_no || val;
    setTimeout(function(){ input.className = 'st-input'; }, 600);

    // Reload feed & stats
    getAPI('get_session', {id:currentSessionId}, function(r){
      if(!r.ok) return;
      currentSession = r.session;
      renderFeed(r.session.scanned_rolls || []);
    });
    loadStats();
  });
}

$('st-input').addEventListener('keydown', function(e){
  if(e.key === 'Enter'){ e.preventDefault(); stSubmitScan(); }
});

// ── Camera ────────────────────────────────────────────────
window.stToggleCamera = function(){
  if(cameraActive){ stStopCamera(); return; }
  stStartCamera();
};

function stStartCamera(){
  $('st-camera').classList.add('open');
  $('st-cam-toggle').classList.add('active');
  cameraActive = true;

  setTimeout(function(){
    html5Scanner = new Html5QrcodeScanner('st-camera-reader', {
      fps: 15,
      qrbox: {width: 250, height: 250},
      rememberLastUsedCamera: false
    }, false);
    html5Scanner.render(function(text){
      if(scanCooldown && text === lastScannedCode) return;
      lastScannedCode = text;
      scanCooldown = true;
      setTimeout(function(){ scanCooldown = false; }, 3000);
      $('st-input').value = text;
      stSubmitScan();
    }, function(){});
  }, 100);
}

function showDupPopup(rollNo){
  $('st-dup-roll').textContent = rollNo;
  $('st-dup-overlay').classList.add('show');
  $('st-dup-popup').classList.add('show');
  setTimeout(function(){
    $('st-dup-overlay').classList.remove('show');
    $('st-dup-popup').classList.remove('show');
  }, 2000);
}

function stStopCamera(){
  if(html5Scanner){
    try { html5Scanner.clear(); } catch(e){}
    html5Scanner = null;
  }
  $('st-camera').classList.remove('open');
  $('st-cam-toggle').classList.remove('active');
  cameraActive = false;
  $('st-camera-reader').innerHTML = '';
}

// ── Init ──────────────────────────────────────────────────
loadSessions();

})();
</script>
