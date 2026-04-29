<?php
define('IN_ERP_SETTINGS_PAGE', true);
require_once __DIR__ . '/backup_auto_helper.php';
// No PHP functions or logic below this line; UI only
?>
<style>
.auto-backup-wrapper .card { box-shadow:0 2px 12px rgba(99,102,241,0.07); border-radius:14px; border:1.5px solid #e0e7ff; margin-bottom:22px; }
.auto-backup-section-title { font-size:1.15rem; font-weight:700; color:#3730a3; display:flex; align-items:center; gap:8px; margin-bottom:10px; }
.auto-backup-toggle { display:flex; align-items:center; gap:10px; }
.auto-backup-toggle input[type=checkbox] { width:36px; height:20px; accent-color:#6366f1; }
.auto-backup-frequency { display:flex; gap:12px; margin-bottom:10px; }
.auto-backup-frequency label { font-weight:500; }
.auto-backup-time { margin-bottom:10px; }
.auto-backup-status-dot { display:inline-block; width:10px; height:10px; border-radius:50%; margin-right:6px; }
.auto-backup-status-success { background:#22c55e; }
.auto-backup-status-error { background:#ef4444; }
.auto-backup-status-warning { background:#f59e0b; }
.auto-backup-status-idle { background:#64748b; }
.auto-backup-history-table { width:100%; border-collapse:collapse; font-size:.97rem; }
.auto-backup-history-table th, .auto-backup-history-table td { border:1px solid #e5e7eb; padding:7px 10px; text-align:left; }
.auto-backup-history-table th { background:#f3f4f6; font-weight:700; }
.auto-backup-history-table tr:nth-child(even) { background:#f9fafb; }
.auto-backup-action-btn { border:none; background:none; color:#6366f1; cursor:pointer; font-size:1.1rem; margin-right:8px; }
.auto-backup-action-btn.delete { color:#ef4444; }
.auto-backup-help-section { background:linear-gradient(120deg,#f1f5f9 0%,#e0e7ff 100%); border-radius:12px; padding:18px 22px; margin-top:18px; }
.auto-backup-collapsible { cursor:pointer; color:#6366f1; font-weight:600; display:flex; align-items:center; gap:6px; }
.auto-backup-collapsible-content { display:none; margin-top:10px; }
.auto-backup-collapsible.open .auto-backup-collapsible-content { display:block; }
</style>
<div class="auto-backup-wrapper">
  <!-- 1. Backup Control Card -->
  <div class="card">
    <div class="auto-backup-section-title"><i class="bi bi-clock-history"></i> Auto Backup Control</div>
    <div class="auto-backup-toggle">
      <label for="auto-backup-enabled">Enable Auto Backup</label>
      <input type="checkbox" id="auto-backup-enabled" checked>
    </div>
    <div class="auto-backup-frequency">
      <label>Frequency:</label>
      <label><input type="radio" name="auto-backup-frequency" value="daily" checked> Daily</label>
      <label><input type="radio" name="auto-backup-frequency" value="weekly"> Weekly</label>
      <label><input type="radio" name="auto-backup-frequency" value="monthly"> Monthly</label>
    </div>
    <div class="auto-backup-time">
      <label for="auto-backup-time">Backup Time:</label>
      <input type="time" id="auto-backup-time" value="02:00">
    </div>
  </div>

  <!-- 2. Manual Backup Card -->
  <div class="card">
    <div class="auto-backup-section-title"><i class="bi bi-play-circle"></i> Manual Backup</div>
    <button id="auto-backup-now-btn" class="btn btn-primary"><i class="bi bi-cloud-arrow-up"></i> Backup Now</button>
    <span id="auto-backup-now-status" style="margin-left:16px;font-weight:500;"></span>
  </div>

  <!-- 3. Storage Card -->
  <div class="card">
    <div class="auto-backup-section-title"><i class="bi bi-hdd-network"></i> Storage Location</div>
    <select id="auto-backup-storage" style="margin-bottom:10px;">
      <option value="local" selected>Local Server</option>
      <option value="gdrive">Google Drive (Rclone)</option>
    </select>
    <div id="auto-backup-rclone-section" style="display:none;">
      <div style="margin-bottom:8px;">
        <span id="auto-backup-rclone-status" class="auto-backup-status-dot auto-backup-status-idle"></span>
        <span id="auto-backup-rclone-status-text">Checking Rclone status...</span>
      </div>
      <div id="auto-backup-rclone-setup" style="display:none;background:#fef3c7;border-radius:8px;padding:10px 14px;margin-bottom:8px;color:#b45309">
        <strong>Rclone is not installed.</strong><br>
        <ol style="margin:8px 0 0 18px;font-size:.97rem;">
          <li>Download and install <a href="https://rclone.org/downloads/" target="_blank">Rclone</a> on your server/hosting.</li>
          <li>After install, run <code>rclone config</code> and add a Google Drive remote.</li>
          <li>Return here and refresh this page.</li>
        </ol>
      </div>
      <div id="auto-backup-rclone-config" style="display:none;background:#f1f5f9;border-radius:8px;padding:10px 14px;margin-bottom:8px;">
        <strong>Rclone Setup Steps:</strong>
        <ol style="margin:8px 0 0 18px;font-size:.97rem;">
          <li>Run <code>rclone config</code> and add a Google Drive remote (follow prompts).</li>
          <li>Paste your remote name and folder path below.</li>
        </ol>
        <div style="margin-top:8px;display:flex;align-items:center;gap:12px;">
          <label>Remote Name:</label> <input type="text" id="auto-backup-rclone-remote" style="width:120px;" placeholder="e.g. gdrive">
          <label>Folder Path:</label> <input type="text" id="auto-backup-rclone-folder" style="width:180px;" placeholder="e.g. ERP_Backups/">
          <button id="auto-backup-rclone-test" class="btn btn-primary" type="button"><i class="bi bi-link-45deg"></i> Test Connection</button>
        </div>
        <div id="auto-backup-rclone-conn-status" style="margin-top:10px;font-weight:500;"></div>
      </div>
    </div>
  </div>

  <!-- 4. Status Card -->
  <div class="card">
    <div class="auto-backup-section-title"><i class="bi bi-activity"></i> Backup Status</div>
    <div><strong>Last Backup:</strong> <span id="auto-backup-last-time">Never</span></div>
    <div><strong>Status:</strong> <span id="auto-backup-last-status">Never Run</span></div>
    <div><strong>Size:</strong> <span id="auto-backup-last-size">-</span></div>
    <div><strong>Location:</strong> <span id="auto-backup-last-location">Local</span></div>
  </div>

  <!-- 5. History Table -->
  <div class="card">
    <div class="auto-backup-section-title"><i class="bi bi-clock"></i> Backup History</div>
    <table class="auto-backup-history-table">
      <thead>
        <tr><th>Date</th><th>File</th><th>Location</th><th>Status</th><th>Actions</th></tr>
      </thead>
      <tbody id="auto-backup-history-body">
        <tr><td colspan="5" style="text-align:center;color:#64748b">No backups yet.</td></tr>
      </tbody>
    </table>
  </div>

  <!-- 6. Cron Info Card -->
  <div class="card">
    <div class="auto-backup-section-title"><i class="bi bi-terminal"></i> Cron Setup</div>
    <div><strong>Cron Command:</strong> <span id="auto-backup-cron-cmd">php /path/to/backup_auto_helper.php --run-scheduled</span></div>
    <div style="color:#f59e0b;margin-top:8px;"><i class="bi bi-exclamation-triangle"></i> If cron is not available on your hosting, automatic backups may not run. Ask your provider for help.</div>
  </div>

  <!-- 7. Help Section -->
  <div class="auto-backup-help-section">
    <div class="auto-backup-collapsible">
      <i class="bi bi-question-circle"></i> How to Setup Auto Backup <span style="margin-left:auto"><i class="bi bi-chevron-down"></i></span>
      <div class="auto-backup-collapsible-content">
        <ol style="margin:10px 0 0 18px;font-size:.98rem;">
          <li>Enable Auto Backup and set your preferred schedule.</li>
          <li>Choose storage location: Local or Google Drive (Rclone).</li>
          <li>If using Google Drive, follow the Rclone setup steps above.</li>
          <li>Click "Backup Now" to test, or wait for the next scheduled backup.</li>
          <li>Check backup status and download/delete backups from the history table.</li>
        </ol>
      </div>
    </div>
  </div>
</div>
<script>
// UI interactivity for storage selection and help section
document.getElementById('auto-backup-storage').addEventListener('change', function() {
  document.getElementById('auto-backup-rclone-section').style.display = this.value === 'gdrive' ? '' : 'none';
});
document.querySelectorAll('.auto-backup-collapsible').forEach(function(el){
  el.addEventListener('click', function(){
    el.classList.toggle('open');
  });
});

// Rclone status check
function updateRcloneStatus() {
  fetch('backup_auto/ajax.php?action=get_rclone_status')
    .then(r => r.json())
    .then(function(res) {
      var dot = document.getElementById('auto-backup-rclone-status');
      var text = document.getElementById('auto-backup-rclone-status-text');
      var setup = document.getElementById('auto-backup-rclone-setup');
      var config = document.getElementById('auto-backup-rclone-config');
      if (res.installed) {
        dot.className = 'auto-backup-status-dot auto-backup-status-success';
        text.textContent = 'Rclone installed (' + (res.version || '') + ')';
        setup.style.display = 'none';
        config.style.display = '';
      } else {
        dot.className = 'auto-backup-status-dot auto-backup-status-error';
        text.textContent = 'Rclone not installed';
        setup.style.display = '';
        config.style.display = 'none';
      }
    });
}
// Call on load if GDrive selected
document.getElementById('auto-backup-storage').addEventListener('change', function() {
  document.getElementById('auto-backup-rclone-section').style.display = this.value === 'gdrive' ? '' : 'none';
  if (this.value === 'gdrive') updateRcloneStatus();
});
if (document.getElementById('auto-backup-storage').value === 'gdrive') updateRcloneStatus();

// Test Connection logic
document.getElementById('auto-backup-rclone-test').addEventListener('click', function() {
  var remote = document.getElementById('auto-backup-rclone-remote').value.trim();
  var folder = document.getElementById('auto-backup-rclone-folder').value.trim();
  var status = document.getElementById('auto-backup-rclone-conn-status');
  status.textContent = 'Testing...';
  status.style.color = '#64748b';
  fetch('backup_auto/ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=test_rclone_conn&remote=' + encodeURIComponent(remote) + '&folder=' + encodeURIComponent(folder)
  })
  .then(r => r.json())
  .then(function(res) {
    if (res.success) {
      status.textContent = 'Connected successfully!';
      status.style.color = '#22c55e';
    } else {
      status.textContent = res.error || 'Connection failed.';
      status.style.color = '#ef4444';
    }
  })
  .catch(function() {
    status.textContent = 'Connection failed (AJAX error).';
    status.style.color = '#ef4444';
  });
});

// Backup Now AJAX
document.getElementById('auto-backup-now-btn').addEventListener('click', function() {
  var btn = this;
  var status = document.getElementById('auto-backup-now-status');
  status.textContent = 'Processing...';
  btn.disabled = true;
  fetch('backup_auto/ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=backup_now'
  })
  .then(r => r.json())
  .then(function(res) {
    if (res.success) {
      status.textContent = 'Backup successful! (' + res.file + ')';
      status.style.color = '#22c55e';
      updateAutoBackupHistory();
      document.getElementById('auto-backup-last-time').textContent = res.date;
      document.getElementById('auto-backup-last-status').textContent = 'Success';
      document.getElementById('auto-backup-last-size').textContent = (res.size/1024).toFixed(1) + ' KB';
      document.getElementById('auto-backup-last-location').textContent = 'Local';
    } else {
      status.textContent = res.error || 'Backup failed.';
      status.style.color = '#ef4444';
    }
    btn.disabled = false;
  })
  .catch(function() {
    status.textContent = 'Backup failed (AJAX error).';
    status.style.color = '#ef4444';
    btn.disabled = false;
  });
});

// Update history table from backend
function updateAutoBackupHistory() {
  fetch('backup_auto/ajax.php?action=get_history')
    .then(r => r.json())
    .then(function(data) {
      var body = document.getElementById('auto-backup-history-body');
      if (!Array.isArray(data) || data.length === 0) {
        body.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#64748b">No backups yet.</td></tr>';
        return;
      }
      var html = '';
      data.slice().reverse().forEach(function(row) {
        var file = row.file || '-';
        var downloadBtn = '<button class="auto-backup-action-btn" title="Download" onclick="autoBackupDownload(\'' + file + '\')"><i class="bi bi-download"></i></button>';
        var deleteBtn = '<button class="auto-backup-action-btn delete" title="Delete" onclick="autoBackupDelete(\'' + file + '\')"><i class="bi bi-trash"></i></button>';
        html += '<tr>' +
          '<td>' + (row.date || '-') + '</td>' +
          '<td>' + file + '</td>' +
          '<td>' + (row.location || '-') + '</td>' +
          '<td>' + (row.status || '-') + '</td>' +
          '<td>' + downloadBtn + deleteBtn + '</td>' +
        '</tr>';
      });
      body.innerHTML = html;
    });
}

function autoBackupDownload(file) {
  if (!file) return;
  window.location = 'backup_auto/ajax.php?action=download&file=' + encodeURIComponent(file);
}

function autoBackupDelete(file) {
  if (!file) return;
  if (!confirm('Delete this backup file? This cannot be undone.')) return;
  var status = document.getElementById('auto-backup-now-status');
  status.textContent = 'Deleting...';
  status.style.color = '#64748b';
  fetch('backup_auto/ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'action=delete&file=' + encodeURIComponent(file)
  })
  .then(r => r.json())
  .then(function(res) {
    if (res.success) {
      status.textContent = 'Backup deleted.';
      status.style.color = '#22c55e';
      updateAutoBackupHistory();
    } else {
      status.textContent = res.error || 'Delete failed.';
      status.style.color = '#ef4444';
    }
  })
  .catch(function() {
    status.textContent = 'Delete failed (AJAX error).';
    status.style.color = '#ef4444';
  });
}
// Initial load
updateAutoBackupHistory();
</script>
