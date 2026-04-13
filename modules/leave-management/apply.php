<div class="rm-pane active" id="lm-panel-apply">
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Apply Leave</div>
        <div class="lm-subtitle">Simple form, large controls, and voice-first support for Bangla, Hindi, and English.</div>
      </div>
    </div>
    <div class="card-body">
      <form id="lm-apply-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">
        <div class="lm-form-grid">
          <div>
            <label for="lm-employee-name">Employee Name</label>
            <input id="lm-employee-name" type="text" value="<?= e($lmEmployeeName) ?>" readonly>
          </div>
          <div>
            <label for="lm-department">Department</label>
            <select id="lm-department" name="department" required>
              <option value="">Select Department</option>
              <?php foreach ($lmDepartments as $department): ?>
                <option value="<?= e($department) ?>" <?= $department === $lmDefaultDepartment ? 'selected' : '' ?>><?= e($department) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="lm-leave-type">Leave Type</label>
            <select id="lm-leave-type" name="leave_type" required>
              <?php foreach ($lmLeaveTypes as $type): ?>
                <option value="<?= e($type) ?>"><?= e($type) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div>
            <label for="lm-from-date">From Date</label>
            <input id="lm-from-date" name="from_date" type="date" required>
          </div>
          <div>
            <label for="lm-to-date">To Date</label>
            <input id="lm-to-date" name="to_date" type="date" required>
          </div>
          <div>
            <label for="lm-total-days">Total Days</label>
            <input id="lm-total-days" name="total_days" type="number" min="1" readonly placeholder="Auto">
          </div>
          <div class="lm-span-2">
            <label for="lm-reason">Reason</label>
            <textarea id="lm-reason" name="reason_text" rows="4" placeholder="Type the leave reason here or use the voice button."></textarea>
          </div>
        </div>

        <div class="lm-voice-card">
          <div class="lm-voice-head">
            <div>
              <strong><i class="bi bi-mic-fill"></i> Voice Input</strong>
              <span>Select <strong>Hindi</strong> to speak Hindi + English mixed &mdash; both will appear as text automatically. Submit is locked while recording is active.</span>
            </div>
            <div class="lm-voice-lang">
              <label for="lm-voice-language">Language</label>
              <select id="lm-voice-language">
                <?php foreach ($lmVoiceLanguages as $lang): ?>
                  <option value="<?= e($lang['code']) ?>"><?= e($lang['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="lm-voice-actions">
            <button type="button" class="lm-big-btn lm-btn-record" id="lm-record-btn">
              <i class="bi bi-mic-fill"></i>
              <span>Record Voice</span>
            </button>
            <button type="button" class="lm-big-btn lm-btn-muted" id="lm-clear-voice-btn">
              <i class="bi bi-trash3"></i>
              <span>Clear Voice</span>
            </button>
          </div>
          <div class="lm-voice-status" id="lm-voice-status">Voice input is optional. You can submit with form input, voice input, or both.</div>
          <div class="lm-voice-note">
            <div><i class="bi bi-translate"></i> Browser speech-to-text support: <span id="lm-stt-state">Checking...</span></div>
            <div><i class="bi bi-cloud-arrow-up"></i> Audio file upload: <span id="lm-audio-state">Ready</span></div>
          </div>
        </div>

        <div class="lm-submit-row">
          <button type="submit" class="lm-primary-submit" id="lm-submit-btn">
            <i class="bi bi-send-check-fill"></i>
            <span>Submit Leave Request</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</div>