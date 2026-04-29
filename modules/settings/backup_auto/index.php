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
.auto-backup-help-trigger { display:flex; align-items:center; justify-content:space-between; gap:12px; }
.auto-backup-help-title { color:#1e293b; font-size:1.02rem; font-weight:700; display:flex; align-items:center; gap:8px; }
.auto-backup-help-btn { border:none; border-radius:10px; padding:10px 14px; background:#2563eb; color:#fff; font-weight:600; cursor:pointer; }
.auto-backup-help-btn:hover { background:#1d4ed8; }
.auto-backup-inline-status { margin-left:16px; font-weight:500; }
.auto-backup-rclone-formrow { margin-top:8px; display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
.auto-backup-rclone-formrow input { width:100%; max-width:220px; }
.auto-backup-history-wrap { overflow-x:auto; }
.auto-backup-cron-cmd { display:inline-block; margin-top:4px; font-family:Consolas, Monaco, 'Courier New', monospace; word-break:break-all; }
.auto-backup-guide-modal { position:fixed; inset:0; background:rgba(2,6,23,0.55); display:none; align-items:center; justify-content:center; z-index:1100; padding:20px; }
.auto-backup-guide-modal.open { display:flex; }
.auto-backup-guide-dialog { background:#fff; width:min(980px, 96vw); max-height:88vh; border-radius:16px; overflow:hidden; box-shadow:0 24px 64px rgba(15,23,42,0.35); border:1px solid #cbd5e1; }
.auto-backup-guide-header { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:16px 20px; border-bottom:1px solid #e2e8f0; background:#f8fafc; }
.auto-backup-guide-header h2 { margin:0; font-size:1.1rem; color:#0f172a; }
.auto-backup-guide-actions { display:flex; align-items:center; gap:10px; }
.auto-backup-guide-print { border:none; border-radius:8px; padding:7px 12px; background:#0f766e; color:#fff; font-weight:600; cursor:pointer; }
.auto-backup-guide-print:hover { background:#0d9488; }
.auto-backup-guide-close { border:none; background:transparent; color:#475569; font-size:1.5rem; line-height:1; cursor:pointer; padding:2px 6px; border-radius:6px; }
.auto-backup-guide-close:hover { background:#e2e8f0; color:#0f172a; }
.auto-backup-guide-content { padding:20px; overflow:auto; max-height:calc(88vh - 72px); color:#1e293b; font-size:.96rem; line-height:1.6; }
.auto-backup-guide-content h3 { margin:18px 0 8px; color:#0f172a; font-size:1.02rem; }
.auto-backup-guide-content hr { border:none; border-top:1px solid #e2e8f0; margin:14px 0; }
.auto-backup-guide-content p { margin:7px 0; }
.auto-backup-guide-content ul, .auto-backup-guide-content ol { margin:8px 0 10px 20px; }
.auto-backup-guide-content pre { margin:8px 0 10px; padding:10px 12px; background:#0f172a; color:#e2e8f0; border-radius:8px; overflow:auto; }
@media (max-width: 768px) {
  .auto-backup-wrapper .card { padding:14px; border-radius:12px; }
  .auto-backup-section-title { font-size:1.03rem; }
  .auto-backup-toggle { flex-wrap:wrap; }
  .auto-backup-frequency { flex-direction:column; gap:8px; }
  .auto-backup-frequency label { display:flex; align-items:center; gap:8px; }
  .auto-backup-time label { display:block; margin-bottom:6px; }
  #auto-backup-time,
  #auto-backup-storage,
  #auto-backup-rclone-remote,
  #auto-backup-rclone-folder,
  #auto-backup-rclone-test,
  #auto-backup-now-btn,
  .auto-backup-help-btn { width:100%; max-width:100%; }
  .auto-backup-rclone-formrow { display:grid; grid-template-columns:1fr; gap:8px; }
  .auto-backup-rclone-formrow label { margin:0; }
  .auto-backup-rclone-formrow input { max-width:100%; }
  .auto-backup-inline-status { display:block; margin-left:0; margin-top:10px; }
  .auto-backup-history-table { min-width:640px; }
  .auto-backup-cron-cmd { font-size:.84rem; }
  .auto-backup-guide-dialog { width:100%; max-height:92vh; }
  .auto-backup-guide-content { padding:14px; max-height:calc(92vh - 68px); }
  .auto-backup-help-trigger { flex-direction:column; align-items:flex-start; }
}
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
    <span id="auto-backup-now-status" class="auto-backup-inline-status"></span>
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
        <div class="auto-backup-rclone-formrow">
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
    <div class="auto-backup-history-wrap">
      <table class="auto-backup-history-table">
        <thead>
          <tr><th>Date</th><th>File</th><th>Location</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody id="auto-backup-history-body">
          <tr><td colspan="5" style="text-align:center;color:#64748b">No backups yet.</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- 6. Cron Info Card -->
  <div class="card">
    <div class="auto-backup-section-title"><i class="bi bi-terminal"></i> Cron Setup</div>
    <div><strong>Cron Command:</strong> <span id="auto-backup-cron-cmd" class="auto-backup-cron-cmd">php /path/to/backup_auto_helper.php --run-scheduled</span></div>
    <div style="color:#f59e0b;margin-top:8px;"><i class="bi bi-exclamation-triangle"></i> If cron is not available on your hosting, automatic backups may not run. Ask your provider for help.</div>
  </div>

  <!-- 7. Help Section -->
  <div class="auto-backup-help-section">
    <div class="auto-backup-help-trigger">
      <div class="auto-backup-help-title"><i class="bi bi-question-circle"></i> How to Setup Auto Backup</div>
      <button id="auto-backup-open-guide" class="auto-backup-help-btn" type="button">Open Setup Guide</button>
    </div>
  </div>

  <div id="auto-backup-guide-modal" class="auto-backup-guide-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="auto-backup-guide-heading">
    <div class="auto-backup-guide-dialog">
      <div class="auto-backup-guide-header">
        <h2 id="auto-backup-guide-heading">AUTO BACKUP SETUP GUIDE</h2>
        <div class="auto-backup-guide-actions">
          <button id="auto-backup-print-guide" class="auto-backup-guide-print" type="button"><i class="bi bi-printer"></i> Print Guide</button>
          <button id="auto-backup-close-guide" class="auto-backup-guide-close" type="button" aria-label="Close">&times;</button>
        </div>
      </div>
      <div class="auto-backup-guide-content">
        <h3>🔷 AUTO BACKUP SETUP GUIDE</h3>
        <hr>

        <h3>1. Overview</h3>
        <p>The Auto Backup system allows you to safely store your ERP database backups.</p>
        <p>It supports:</p>
        <ul>
          <li>Local backups (stored on your server)</li>
          <li>Cloud backups (Google Drive via Rclone)</li>
        </ul>
        <p>Backups can be created manually or automatically.</p>
        <hr>

        <h3>2. Manual Backup (Basic Usage)</h3>
        <ol>
          <li>Go to: Settings → Auto Backup</li>
          <li>Click the <strong>"Backup Now"</strong> button</li>
          <li>The system will:
            <ul>
              <li>Generate a database backup</li>
              <li>Save it locally</li>
              <li>Upload to Google Drive (if enabled)</li>
            </ul>
          </li>
          <li>You can view all backups in the <strong>Backup History</strong> table</li>
        </ol>
        <hr>

        <h3>3. Install Rclone (Required for Google Drive)</h3>
        <p>Rclone is required for cloud backup.</p>
        <p>Steps:</p>
        <ol>
          <li>Download Rclone from official website</li>
          <li>Extract the ZIP file</li>
          <li>Copy <code>rclone.exe</code> to:</li>
        </ol>
        <pre>C:\Windows\System32\</pre>
        <ol start="4">
          <li>Open PowerShell and run:</li>
        </ol>
        <pre>rclone --version</pre>
        <p>If version is displayed, installation is successful.</p>
        <hr>

        <h3>4. Configure Google Drive (Rclone Setup)</h3>
        <p>Run the following command:</p>
        <pre>rclone config</pre>
        <p>Follow the steps:</p>
        <ul>
          <li>Press: n (New remote)</li>
          <li>Name: gdrive</li>
          <li>Storage: drive (Google Drive)</li>
          <li>Client ID: Press Enter</li>
          <li>Client Secret: Press Enter</li>
          <li>Scope: 1 (Full access)</li>
          <li>Root folder: Press Enter</li>
          <li>Service account: Press Enter</li>
          <li>Advanced config: n</li>
          <li>Auto config: y</li>
        </ul>
        <p>A browser window will open:</p>
        <ul>
          <li>Login to your Google account</li>
          <li>Allow access</li>
        </ul>
        <p>Finally press:</p>
        <ul>
          <li>y (Yes, save configuration)</li>
        </ul>
        <hr>

        <h3>5. Verify Rclone Connection</h3>
        <p>Run:</p>
        <pre>rclone listremotes</pre>
        <p>Expected output:</p>
        <pre>gdrive:</pre>
        <p>Optional test:</p>
        <pre>rclone lsd gdrive:</pre>
        <hr>

        <h3>6. Create Backup Folder (IMPORTANT)</h3>
        <p>Run:</p>
        <pre>rclone mkdir gdrive:ERP_Backups</pre>
        <p>This creates a folder for storing backups.</p>
        <hr>

        <h3>7. Connect Google Drive in ERP</h3>
        <p>Go to:</p>
        <p>Settings → Auto Backup</p>
        <p>Enter:</p>
        <ul>
          <li>Storage Location: Google Drive</li>
          <li>Remote Name: gdrive</li>
          <li>Folder Path: ERP_Backups</li>
        </ul>
        <p>Click:</p>
        <p>Test Connection</p>
        <p>Expected:</p>
        <p>Connected successfully</p>
        <hr>

        <h3>8. Run Backup with Google Drive</h3>
        <p>Click:</p>
        <p>Backup Now</p>
        <p>The system will:</p>
        <ul>
          <li>Create local backup</li>
          <li>Upload to Google Drive</li>
          <li>Save backup history</li>
        </ul>
        <hr>

        <h3>9. Automatic Backup (Advanced)</h3>
        <p>Enable Auto Backup:</p>
        <ul>
          <li>Turn ON Auto Backup</li>
          <li>Select Frequency:
            <ul>
              <li>Daily</li>
              <li>Weekly</li>
              <li>Monthly</li>
            </ul>
          </li>
          <li>Set Backup Time</li>
        </ul>
        <hr>

        <h3>10. Setup Cron Job / Task Scheduler</h3>
        <p>For Windows (Localhost):</p>
        <p>Use Task Scheduler:</p>
        <p>Program:</p>
        <pre>C:\xampp\php\php.exe</pre>
        <p>Arguments:</p>
        <pre>C:\xampp\htdocs\calipot-erp\shree-label-php\modules\settings\backup_auto\backup_auto_helper.php --run-scheduled</pre>
        <hr>

        <p>For Hosting (Linux / VPS):</p>
        <p>Cron command:</p>
        <pre>php /path/to/backup_auto_helper.php --run-scheduled</pre>
        <hr>

        <h3>11. Backup Safety Features</h3>
        <ul>
          <li>If Google Drive upload fails:<br>→ Backup is saved locally</li>
          <li>Backup history is always maintained</li>
          <li>No data loss</li>
        </ul>
        <hr>

        <h3>12. Best Practices</h3>
        <ul>
          <li>Always test connection before backup</li>
          <li>Keep Google Drive enabled for safety</li>
          <li>Monitor backup history regularly</li>
          <li>Do not delete recent backups manually</li>
        </ul>
      </div>
    </div>
  </div>
</div>
<script>
// UI interactivity for storage selection and guide modal
document.getElementById('auto-backup-storage').addEventListener('change', function() {
  document.getElementById('auto-backup-rclone-section').style.display = this.value === 'gdrive' ? '' : 'none';
});

var autoBackupGuideModal = document.getElementById('auto-backup-guide-modal');
var autoBackupGuideOpen = document.getElementById('auto-backup-open-guide');
var autoBackupGuideClose = document.getElementById('auto-backup-close-guide');
var autoBackupGuidePrint = document.getElementById('auto-backup-print-guide');
var autoBackupGuideContent = document.querySelector('.auto-backup-guide-content');
var autoBackupEnabledEl = document.getElementById('auto-backup-enabled');
var autoBackupTimeEl = document.getElementById('auto-backup-time');
var autoBackupStorageEl = document.getElementById('auto-backup-storage');
var autoBackupRemoteEl = document.getElementById('auto-backup-rclone-remote');
var autoBackupFolderEl = document.getElementById('auto-backup-rclone-folder');

function autoBackupOpenGuide() {
  autoBackupGuideModal.classList.add('open');
  autoBackupGuideModal.setAttribute('aria-hidden', 'false');
}

function autoBackupCloseGuide() {
  autoBackupGuideModal.classList.remove('open');
  autoBackupGuideModal.setAttribute('aria-hidden', 'true');
}

autoBackupGuideOpen.addEventListener('click', autoBackupOpenGuide);
autoBackupGuideClose.addEventListener('click', autoBackupCloseGuide);
autoBackupGuidePrint.addEventListener('click', function() {
  var printWindow = window.open('', '_blank', 'width=900,height=700');
  if (!printWindow) {
    return;
  }
  var printHtml = '<!doctype html><html><head><meta charset="utf-8"><title>Auto Backup Setup Guide</title>' +
    '<style>body{font-family:Segoe UI,Arial,sans-serif;color:#0f172a;line-height:1.6;padding:24px;max-width:900px;margin:0 auto}h3{margin:18px 0 8px}hr{border:none;border-top:1px solid #cbd5e1;margin:14px 0}pre{margin:8px 0 10px;padding:10px 12px;background:#0f172a;color:#e2e8f0;border-radius:6px;white-space:pre-wrap}ul,ol{margin:8px 0 10px 20px}</style>' +
    '</head><body>' + autoBackupGuideContent.innerHTML + '</body></html>';
  printWindow.document.open();
  printWindow.document.write(printHtml);
  printWindow.document.close();
  printWindow.focus();
  printWindow.print();
});

autoBackupGuideModal.addEventListener('click', function(e) {
  if (e.target === autoBackupGuideModal) {
    autoBackupCloseGuide();
  }
});

document.addEventListener('keydown', function(e) {
  if (e.key === 'Escape' && autoBackupGuideModal.classList.contains('open')) {
    autoBackupCloseGuide();
  }
});

function autoBackupSelectedFrequency() {
  var selected = document.querySelector('input[name="auto-backup-frequency"]:checked');
  return selected ? selected.value : 'daily';
}

function autoBackupApplySettings(settings) {
  if (!settings || typeof settings !== 'object') {
    return;
  }

  autoBackupEnabledEl.checked = !!settings.enabled;
  autoBackupTimeEl.value = settings.backup_time || '02:00';
  autoBackupStorageEl.value = settings.storage === 'gdrive' ? 'gdrive' : 'local';

  var freq = settings.frequency || 'daily';
  var freqEl = document.querySelector('input[name="auto-backup-frequency"][value="' + freq + '"]');
  if (freqEl) {
    freqEl.checked = true;
  }

  autoBackupRemoteEl.value = settings.remote || '';
  autoBackupFolderEl.value = settings.folder || '';

  document.getElementById('auto-backup-rclone-section').style.display = autoBackupStorageEl.value === 'gdrive' ? '' : 'none';
  if (autoBackupStorageEl.value === 'gdrive') {
    updateRcloneStatus();
  }
}

function autoBackupSaveSettings() {
  var body = new URLSearchParams();
  body.append('action', 'save_settings');
  body.append('enabled', autoBackupEnabledEl.checked ? '1' : '0');
  body.append('frequency', autoBackupSelectedFrequency());
  body.append('backup_time', autoBackupTimeEl.value || '02:00');
  body.append('storage', autoBackupStorageEl.value || 'local');
  body.append('remote', autoBackupRemoteEl.value.trim());
  body.append('folder', autoBackupFolderEl.value.trim());

  fetch('backup_auto/ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  }).catch(function(){});
}

function autoBackupLoadSettings() {
  fetch('backup_auto/ajax.php?action=get_settings')
    .then(r => r.json())
    .then(function(res) {
      if (res && res.success && res.settings) {
        autoBackupApplySettings(res.settings);
      }
    })
    .catch(function(){});
}

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
  autoBackupSaveSettings();
});

autoBackupEnabledEl.addEventListener('change', autoBackupSaveSettings);
autoBackupTimeEl.addEventListener('change', autoBackupSaveSettings);
document.querySelectorAll('input[name="auto-backup-frequency"]').forEach(function(el) {
  el.addEventListener('change', autoBackupSaveSettings);
});
autoBackupRemoteEl.addEventListener('change', autoBackupSaveSettings);
autoBackupFolderEl.addEventListener('change', autoBackupSaveSettings);

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
  var selectedStorage = autoBackupStorageEl.value || 'local';
  var remote = autoBackupRemoteEl.value.trim();
  var folder = autoBackupFolderEl.value.trim();
  status.textContent = 'Processing...';
  btn.disabled = true;

  var body = new URLSearchParams();
  body.append('action', 'backup_now');
  body.append('storage', selectedStorage);
  body.append('remote', remote);
  body.append('folder', folder);

  fetch('backup_auto/ajax.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: body.toString()
  })
  .then(r => r.json())
  .then(function(res) {
    if (res.success) {
      status.textContent = res.message || ('Backup successful! (' + res.file + ')');
      status.style.color = '#22c55e';
      updateAutoBackupHistory();
      document.getElementById('auto-backup-last-time').textContent = res.date;
      document.getElementById('auto-backup-last-status').textContent = res.upload_status || 'Success';
      document.getElementById('auto-backup-last-size').textContent = (res.size/1024).toFixed(1) + ' KB';
      document.getElementById('auto-backup-last-location').textContent = res.location || 'Local';
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
autoBackupLoadSettings();
updateAutoBackupHistory();
</script>
