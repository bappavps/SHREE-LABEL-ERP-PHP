<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../../../includes/auth_check.php';

$isOperatorView = (string)($_GET['view'] ?? '') === 'operator';
$pageTitle = $isOperatorView ? 'Die-Cutting Operator' : 'Die-Cutting Job Cards';
$db = getDB();

$jobsSql = "
    SELECT j.*, ps.paper_type, ps.company, ps.width_mm, ps.length_mtr, ps.gsm, ps.weight_kg,
           ps.status AS roll_status,
           p.job_name AS planning_job_name, p.status AS planning_status, p.priority AS planning_priority,
           p.extra_data AS planning_extra_data,
           prev.job_no AS prev_job_no, prev.status AS prev_job_status
    FROM jobs j
    LEFT JOIN paper_stock ps ON j.roll_no = ps.roll_no
    LEFT JOIN planning p ON j.planning_id = p.id
    LEFT JOIN jobs prev ON j.previous_job_id = prev.id
    WHERE (
            LOWER(COALESCE(j.department, '')) IN ('flatbed', 'die-cutting', 'die_cutting')
         OR LOWER(COALESCE(j.job_type, '')) IN ('die-cutting', 'diecutting')
         OR (LOWER(COALESCE(j.job_type, '')) = 'finishing' AND LOWER(COALESCE(j.department, '')) IN ('flatbed', 'die-cutting', 'die_cutting'))
    )
      AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
    ORDER BY j.created_at DESC
    LIMIT 300
";
$jobsRes = $db->query($jobsSql);
$jobs = $jobsRes instanceof mysqli_result ? $jobsRes->fetch_all(MYSQLI_ASSOC) : [];

$finishStates = ['Closed', 'Finalized', 'Completed', 'QC Passed'];
foreach ($jobs as &$job) {
  $job['extra_data_parsed'] = json_decode((string)($job['extra_data'] ?? '{}'), true) ?: [];
  $planningExtra = json_decode((string)($job['planning_extra_data'] ?? '{}'), true) ?: [];
  $job['planning_die_size'] = (string)($planningExtra['size'] ?? ($planningExtra['die_size'] ?? ''));
  $job['planning_repeat'] = (string)($planningExtra['repeat'] ?? '');
  $job['planning_order_qty'] = (string)($planningExtra['qty_pcs'] ?? '');
  $job['planning_material'] = (string)($planningExtra['material'] ?? ($job['paper_type'] ?? ''));
  $imagePath = trim((string)($planningExtra['image_path'] ?? ($planningExtra['planning_image_path'] ?? '')));
  if ($imagePath !== '' && !preg_match('/^https?:\/\//i', $imagePath)) {
    $imagePath = BASE_URL . '/' . ltrim($imagePath, '/');
  }
  $job['planning_image_url'] = $imagePath;
  $displayName = trim((string)($job['planning_job_name'] ?? ''));
  $job['display_job_name'] = $displayName !== '' ? $displayName : (trim((string)($job['job_no'] ?? '')) ?: '—');
  $prevStatus = trim((string)($job['prev_job_status'] ?? ''));
  $hasPrev = (int)($job['previous_job_id'] ?? 0) > 0;
  $job['upstream_ready'] = !$hasPrev || in_array($prevStatus, $finishStates, true);
}
unset($job);

$activeJobs = array_values(array_filter($jobs, function ($j) use ($finishStates) {
  return !in_array((string)($j['status'] ?? ''), $finishStates, true);
}));
$historyJobs = array_values(array_filter($jobs, function ($j) use ($finishStates) {
  return in_array((string)($j['status'] ?? ''), $finishStates, true);
}));

$csrf = generateCSRF();
include __DIR__ . '/../../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <?php if ($isOperatorView): ?>
    <span>Operator</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Machine Operators</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Die-Cutting Operator</span>
  <?php else: ?>
    <span>Production</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Job Cards</span>
    <span class="breadcrumb-sep">&#8250;</span>
    <span>Die-Cutting</span>
  <?php endif; ?>
</div>

<style>
.dc-head{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
.dc-head h1{margin:0;font-size:1.3rem;font-weight:900;color:#0f172a}
.dc-meta{font-size:.75rem;color:#64748b;font-weight:700}
.dc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:14px}
.dc-card{background:#fff;border:1px solid #e2e8f0;border-left:4px solid #0ea5a4;border-radius:12px;overflow:hidden;display:flex;flex-direction:column}
.dc-card.locked{border-left-color:#f59e0b}
.dc-card.done{border-left-color:#16a34a}
.dc-card-h{padding:12px 14px;border-bottom:1px solid #e2e8f0;background:#f8fafc;display:flex;justify-content:space-between;align-items:center;gap:8px}
.dc-job{font-size:.82rem;font-weight:900;color:#0f172a}
.dc-status{font-size:.62rem;font-weight:800;padding:3px 9px;border-radius:999px;background:#e2e8f0;color:#334155}
.dc-status.running{background:#dbeafe;color:#1d4ed8}
.dc-status.done{background:#dcfce7;color:#15803d}
.dc-status.pending{background:#fef3c7;color:#92400e}
.dc-body{padding:12px 14px;display:grid;gap:6px;font-size:.8rem}
.dc-row{display:flex;justify-content:space-between;gap:10px}
.dc-k{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#94a3b8;letter-spacing:.05em}
.dc-v{font-weight:700;color:#0f172a;text-align:right}
.dc-timer{font-family:Consolas,monospace;color:#0f766e;font-size:.8rem;font-weight:900}
.dc-foot{padding:10px 14px;border-top:1px solid #e2e8f0;display:flex;gap:8px;flex-wrap:wrap}
.dc-btn{height:32px;padding:0 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font-size:.72rem;font-weight:800;cursor:pointer}
.dc-btn.primary{background:#0ea5a4;border-color:#0ea5a4;color:#fff}
.dc-btn.warn{background:#f59e0b;border-color:#f59e0b;color:#fff}
.dc-btn.good{background:#16a34a;border-color:#16a34a;color:#fff}
.dc-btn[disabled]{opacity:.45;cursor:not-allowed}
.dc-gate{font-size:.68rem;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:7px;padding:5px 8px}
.dc-empty{padding:36px 16px;text-align:center;color:#94a3b8}

.dc-modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:2100;align-items:center;justify-content:center;padding:16px}
.dc-modal.active{display:flex}
.dc-modal-card{width:100%;max-width:860px;max-height:92vh;overflow:auto;background:#fff;border-radius:14px;border:1px solid #dbeafe}
.dc-modal-head{padding:12px 14px;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;background:#ecfeff}
.dc-modal-body{padding:14px;display:grid;gap:12px}
.dc-sec{border:1px solid #e2e8f0;border-radius:10px;padding:10px}
.dc-sec h3{margin:0 0 8px;font-size:.72rem;font-weight:900;text-transform:uppercase;color:#475569}
.dc-od{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 12px}
.dc-field{display:flex;flex-direction:column;gap:4px}
.dc-field label{font-size:.62rem;font-weight:800;text-transform:uppercase;color:#64748b}
.dc-field input,.dc-field textarea{border:1px solid #cbd5e1;border-radius:8px;padding:8px 10px;font-size:.82rem}
.dc-field textarea{min-height:70px}
.dc-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.dc-preview{max-width:220px;max-height:140px;border:1px solid #dbeafe;border-radius:8px;object-fit:contain;background:#fff}
.dc-note{font-size:.72rem;color:#64748b}
@media (max-width:760px){.dc-od{grid-template-columns:1fr}.dc-grid{grid-template-columns:1fr}}
</style>

<div class="dc-head">
  <h1><?= $isOperatorView ? 'Die-Cutting Operator' : 'Die-Cutting Job Cards' ?></h1>
  <div class="dc-meta">Active: <?= count($activeJobs) ?> | History: <?= count($historyJobs) ?></div>
</div>

<div class="card" style="margin-bottom:14px">
  <div class="card-header"><span class="card-title">Active Jobs</span></div>
  <div style="padding:12px">
    <?php if (empty($activeJobs)): ?>
      <div class="dc-empty"><i class="bi bi-inbox"></i><div>No active Die-Cutting jobs found.</div></div>
    <?php else: ?>
      <div class="dc-grid">
        <?php foreach ($activeJobs as $job): ?>
          <?php
            $statusRaw = (string)($job['status'] ?? 'Pending');
            $statusClass = 'pending';
            if ($statusRaw === 'Running') $statusClass = 'running';
            elseif (in_array($statusRaw, ['Closed','Finalized','Completed','QC Passed'], true)) $statusClass = 'done';
            $extra = $job['extra_data_parsed'] ?? [];
            $accSec = (int)round((float)($extra['timer_accumulated_seconds'] ?? 0));
            $isLocked = !$job['upstream_ready'];
          ?>
          <div class="dc-card <?= $isLocked ? 'locked' : '' ?>">
            <div class="dc-card-h">
              <div class="dc-job"><?= e((string)$job['job_no']) ?></div>
              <span class="dc-status <?= e($statusClass) ?>"><?= e($statusRaw) ?></span>
            </div>
            <div class="dc-body">
              <div class="dc-row"><span class="dc-k">Job Name</span><span class="dc-v"><?= e((string)$job['display_job_name']) ?></span></div>
              <div class="dc-row"><span class="dc-k">Material</span><span class="dc-v"><?= e((string)($job['planning_material'] ?: ($job['paper_type'] ?? '-'))) ?></span></div>
              <div class="dc-row"><span class="dc-k">Order Quantity</span><span class="dc-v"><?= e((string)($job['planning_order_qty'] ?: '-')) ?></span></div>
              <div class="dc-row"><span class="dc-k">Total Length (Mtr)</span><span class="dc-v"><?= e((string)($job['length_mtr'] ?? '-')) ?></span></div>
              <div class="dc-row"><span class="dc-k">Timer</span><span class="dc-timer" data-card-timer="<?= (int)$job['id'] ?>" data-seconds="<?= (int)$accSec ?>">00:00:00</span></div>
              <?php if ($isLocked): ?>
                <div class="dc-gate"><i class="bi bi-lock-fill"></i> Active হবে যখন Printing Done হবে. Prev: <?= e((string)($job['prev_job_no'] ?: 'N/A')) ?></div>
              <?php endif; ?>
            </div>
            <div class="dc-foot">
              <button type="button" class="dc-btn" onclick="openDieCuttingCard(<?= (int)$job['id'] ?>)">View</button>
              <button type="button" class="dc-btn primary" onclick="setRunning(<?= (int)$job['id'] ?>)" <?= ($isLocked || $statusRaw === 'Running') ? 'disabled' : '' ?>>Start</button>
              <button type="button" class="dc-btn warn" onclick="pauseTimer(<?= (int)$job['id'] ?>)" <?= $statusRaw !== 'Running' ? 'disabled' : '' ?>>Pause</button>
              <button type="button" class="dc-btn good" onclick="completeDieCutting(<?= (int)$job['id'] ?>)" <?= ($isLocked || $statusRaw === 'Queued') ? 'disabled' : '' ?>>Complete</button>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">History</span></div>
  <div style="padding:12px;overflow:auto">
    <?php if (empty($historyJobs)): ?>
      <div class="dc-empty"><div>No completed Die-Cutting jobs yet.</div></div>
    <?php else: ?>
      <table class="module-table" style="min-width:780px">
        <thead><tr><th>Job No</th><th>Job Name</th><th>Status</th><th>Qty (Pcs)</th><th>Wastage Pcs</th><th>Wastage Mtr</th><th>Completed</th></tr></thead>
        <tbody>
          <?php foreach ($historyJobs as $job): ?>
            <?php $ex = $job['extra_data_parsed'] ?? []; ?>
            <tr>
              <td><?= e((string)$job['job_no']) ?></td>
              <td><?= e((string)$job['display_job_name']) ?></td>
              <td><?= e((string)$job['status']) ?></td>
              <td><?= e((string)($ex['die_cutting_total_qty_pcs'] ?? '-')) ?></td>
              <td><?= e((string)($ex['die_cutting_wastage_pcs'] ?? '-')) ?></td>
              <td><?= e((string)($ex['die_cutting_wastage_mtr'] ?? '-')) ?></td>
              <td><?= e((string)($job['completed_at'] ?? '-')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>

<div id="dcModal" class="dc-modal">
  <div class="dc-modal-card">
    <div class="dc-modal-head">
      <strong id="dcModalTitle">Die Cutting</strong>
      <button type="button" class="dc-btn" onclick="closeDieCuttingCard()">Close</button>
    </div>
    <div class="dc-modal-body">
      <div class="dc-sec">
        <h3>Order Details</h3>
        <div class="dc-od" id="dcOrderDetails"></div>
      </div>
      <div class="dc-sec">
        <h3>Job Preview</h3>
        <img id="dcPreviewImage" class="dc-preview" alt="Job Preview" style="display:none">
        <div id="dcPreviewEmpty" class="dc-note">No preview image available.</div>
      </div>
      <div class="dc-sec">
        <h3>Operator Submission</h3>
        <div class="dc-od">
          <div class="dc-field"><label>Total Qnt. (Pcs)</label><input type="number" min="0" step="1" id="dcQtyPcs"></div>
          <div class="dc-field"><label>Wastage (Pcs)</label><input type="number" min="0" step="1" id="dcWastagePcs"></div>
          <div class="dc-field"><label>Wastage (Mtr)</label><input type="number" min="0" step="0.01" id="dcWastageMtr"></div>
          <div class="dc-field"><label>Total Length (Printed Roll) Mtr</label><input type="text" id="dcPrintedMtr" readonly></div>
        </div>
        <div class="dc-field" style="margin-top:8px"><label>Notes (Text)</label><textarea id="dcNotes"></textarea></div>
        <div class="dc-actions" style="margin-top:10px;justify-content:flex-start">
          <button type="button" class="dc-btn" onclick="uploadDieCuttingPhoto()"><i class="bi bi-camera"></i> Capture Photo</button>
          <button type="button" class="dc-btn" id="dcVoiceBtn" onclick="toggleVoiceRecord()"><i class="bi bi-mic"></i> Record Voice</button>
          <span class="dc-note" id="dcMediaState">No media uploaded.</span>
        </div>
      </div>
      <div class="dc-actions">
        <button type="button" class="dc-btn" onclick="saveDieCuttingDraft()">Save Draft</button>
        <button type="button" class="dc-btn good" onclick="submitAndCompleteDieCutting()">Submit & Complete</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  var apiUrl = <?= json_encode(BASE_URL . '/modules/jobs/api.php', JSON_UNESCAPED_SLASHES) ?>;
  var csrf = <?= json_encode($csrf, JSON_UNESCAPED_SLASHES) ?>;
  var jobs = <?= json_encode($jobs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || [];
  var jobMap = {};
  jobs.forEach(function(j){ jobMap[String(j.id)] = j; });
  var activeJobId = 0;
  var voiceRecorder = null;
  var voiceChunks = [];
  var lastPhotoPath = '';
  var lastVoicePath = '';

  function as2(n){ return n < 10 ? ('0' + n) : String(n); }
  function toTimer(seconds){
    var s = Math.max(0, parseInt(seconds || 0, 10) || 0);
    var h = Math.floor(s / 3600); s = s % 3600;
    var m = Math.floor(s / 60); var ss = s % 60;
    return as2(h) + ':' + as2(m) + ':' + as2(ss);
  }

  document.querySelectorAll('[data-card-timer]').forEach(function(node){
    var sec = parseInt(node.getAttribute('data-seconds') || '0', 10) || 0;
    node.textContent = toTimer(sec);
  });

  function postForm(params){
    var body = new URLSearchParams();
    Object.keys(params).forEach(function(k){ body.append(k, params[k]); });
    body.append('csrf_token', csrf);
    return fetch(apiUrl, {
      method: 'POST',
      headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
      body: body.toString()
    }).then(function(r){ return r.json(); });
  }

  function setMediaState(msg){
    var n = document.getElementById('dcMediaState');
    if (n) n.textContent = msg;
  }

  window.setRunning = function(jobId){
    postForm({action:'update_status', job_id:String(jobId), status:'Running'}).then(function(res){
      if (!res || !res.ok) { alert((res && res.error) || 'Failed to start job'); return; }
      location.reload();
    }).catch(function(){ alert('Failed to start job'); });
  };

  window.pauseTimer = function(jobId){
    postForm({action:'pause_timer_session', job_id:String(jobId)}).then(function(res){
      if (!res || !res.ok) { alert((res && res.error) || 'Failed to pause timer'); return; }
      location.reload();
    }).catch(function(){ alert('Failed to pause timer'); });
  };

  window.completeDieCutting = function(jobId){
    openDieCuttingCard(jobId);
  };

  function fillOrderDetails(job){
    var root = document.getElementById('dcOrderDetails');
    if (!root) return;
    var ex = job.extra_data_parsed || {};
    var fields = [
      ['Job name', job.display_job_name || '-'],
      ['Material', job.planning_material || job.paper_type || '-'],
      ['Die Size', job.planning_die_size || '-'],
      ['Repeat', job.planning_repeat || '-'],
      ['Order Quantity', job.planning_order_qty || '-'],
      ['Total Length (Printed Roll) Mtr', (job.length_mtr || '-')]
    ];
    root.innerHTML = fields.map(function(pair){
      return '<div class="dc-field"><label>' + pair[0] + '</label><input type="text" readonly value="' + String(pair[1] || '').replace(/"/g, '&quot;') + '"></div>';
    }).join('');

    document.getElementById('dcQtyPcs').value = ex.die_cutting_total_qty_pcs || '';
    document.getElementById('dcWastagePcs').value = ex.die_cutting_wastage_pcs || '';
    document.getElementById('dcWastageMtr').value = ex.die_cutting_wastage_mtr || '';
    document.getElementById('dcNotes').value = ex.die_cutting_notes_text || '';
    document.getElementById('dcPrintedMtr').value = String(job.length_mtr || '');
    lastPhotoPath = String(ex.die_cutting_photo_path || '');
    lastVoicePath = String(ex.die_cutting_voice_note_path || '');
    setMediaState((lastPhotoPath ? 'Photo added. ' : '') + (lastVoicePath ? 'Voice added.' : 'No media uploaded.'));

    var img = document.getElementById('dcPreviewImage');
    var empty = document.getElementById('dcPreviewEmpty');
    var src = String(job.planning_image_url || '');
    if (src) {
      img.src = src;
      img.style.display = '';
      empty.style.display = 'none';
    } else {
      img.removeAttribute('src');
      img.style.display = 'none';
      empty.style.display = '';
    }
  }

  window.openDieCuttingCard = function(jobId){
    var job = jobMap[String(jobId)];
    if (!job) { alert('Job not found'); return; }
    activeJobId = parseInt(jobId, 10) || 0;
    document.getElementById('dcModalTitle').textContent = 'Die Cutting - ' + (job.job_no || '');
    fillOrderDetails(job);
    document.getElementById('dcModal').classList.add('active');
  };

  window.closeDieCuttingCard = function(){
    activeJobId = 0;
    document.getElementById('dcModal').classList.remove('active');
  };

  function uploadMedia(actionName, file, fieldName){
    var fd = new FormData();
    fd.append('action', actionName);
    fd.append('job_id', String(activeJobId));
    fd.append('csrf_token', csrf);
    fd.append(fieldName, file);
    return fetch(apiUrl, {method:'POST', body:fd}).then(function(r){ return r.json(); });
  }

  window.uploadDieCuttingPhoto = function(){
    if (!activeJobId) return;
    var input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.capture = 'environment';
    input.onchange = function(){
      var file = input.files && input.files[0] ? input.files[0] : null;
      if (!file) return;
      uploadMedia('upload_die_cutting_photo', file, 'photo').then(function(res){
        if (!res || !res.ok) { alert((res && res.error) || 'Photo upload failed'); return; }
        lastPhotoPath = String(res.photo_path || '');
        setMediaState('Photo added' + (lastVoicePath ? ' and voice added.' : '.'));
      }).catch(function(){ alert('Photo upload failed'); });
    };
    input.click();
  };

  window.toggleVoiceRecord = function(){
    if (!activeJobId) return;
    var btn = document.getElementById('dcVoiceBtn');
    if (!voiceRecorder) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        alert('Microphone is not supported in this browser.');
        return;
      }
      navigator.mediaDevices.getUserMedia({audio:true}).then(function(stream){
        voiceChunks = [];
        voiceRecorder = new MediaRecorder(stream);
        voiceRecorder.ondataavailable = function(e){ if (e.data && e.data.size > 0) voiceChunks.push(e.data); };
        voiceRecorder.onstop = function(){
          stream.getTracks().forEach(function(t){ t.stop(); });
          var blob = new Blob(voiceChunks, {type:'audio/webm'});
          var file = new File([blob], 'die-cutting-note.webm', {type:'audio/webm'});
          uploadMedia('upload_die_cutting_voice', file, 'voice').then(function(res){
            if (!res || !res.ok) { alert((res && res.error) || 'Voice upload failed'); return; }
            lastVoicePath = String(res.voice_path || '');
            setMediaState((lastPhotoPath ? 'Photo added. ' : '') + 'Voice added.');
          }).catch(function(){ alert('Voice upload failed'); });
          voiceRecorder = null;
          if (btn) btn.innerHTML = '<i class="bi bi-mic"></i> Record Voice';
        };
        voiceRecorder.start();
        if (btn) btn.innerHTML = '<i class="bi bi-stop-circle"></i> Stop Recording';
        setMediaState('Recording voice... click Stop when done.');
      }).catch(function(){
        alert('Microphone permission denied.');
      });
      return;
    }
    voiceRecorder.stop();
  };

  function buildSubmissionExtra(){
    return {
      die_cutting_total_qty_pcs: (document.getElementById('dcQtyPcs').value || '').trim(),
      die_cutting_wastage_pcs: (document.getElementById('dcWastagePcs').value || '').trim(),
      die_cutting_wastage_mtr: (document.getElementById('dcWastageMtr').value || '').trim(),
      die_cutting_notes_text: (document.getElementById('dcNotes').value || '').trim(),
      die_cutting_printed_roll_length_mtr: (document.getElementById('dcPrintedMtr').value || '').trim(),
      die_cutting_photo_path: lastPhotoPath,
      die_cutting_voice_note_path: lastVoicePath,
      die_cutting_submitted_at: new Date().toISOString()
    };
  }

  window.saveDieCuttingDraft = function(){
    if (!activeJobId) return;
    var extra = buildSubmissionExtra();
    postForm({action:'submit_extra_data', job_id:String(activeJobId), extra_data:JSON.stringify(extra)}).then(function(res){
      if (!res || !res.ok) { alert((res && res.error) || 'Save failed'); return; }
      var job = jobMap[String(activeJobId)] || {};
      job.extra_data_parsed = Object.assign({}, job.extra_data_parsed || {}, extra);
      jobMap[String(activeJobId)] = job;
      alert('Draft saved');
    }).catch(function(){ alert('Save failed'); });
  };

  window.submitAndCompleteDieCutting = function(){
    if (!activeJobId) return;
    var extra = buildSubmissionExtra();
    if (!extra.die_cutting_total_qty_pcs || !extra.die_cutting_wastage_pcs || !extra.die_cutting_wastage_mtr) {
      alert('Total Qty, Wastage Pcs and Wastage Mtr required.');
      return;
    }
    if (!extra.die_cutting_photo_path) {
      alert('Job end করতে physical photo capture/upload required.');
      return;
    }

    postForm({action:'submit_extra_data', job_id:String(activeJobId), extra_data:JSON.stringify(extra)}).then(function(res){
      if (!res || !res.ok) { alert((res && res.error) || 'Submission failed'); return; }
      return postForm({action:'end_timer_session', job_id:String(activeJobId)});
    }).then(function(endRes){
      if (!endRes || !endRes.ok) { alert((endRes && endRes.error) || 'Failed to end timer'); return; }
      return postForm({action:'update_status', job_id:String(activeJobId), status:'Completed'});
    }).then(function(doneRes){
      if (!doneRes || !doneRes.ok) { alert((doneRes && doneRes.error) || 'Failed to complete'); return; }
      location.reload();
    }).catch(function(){ alert('Submission failed'); });
  };

})();
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
