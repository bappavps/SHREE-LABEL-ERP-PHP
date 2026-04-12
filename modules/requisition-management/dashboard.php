<div class="rm-panel" id="rm-panel-dashboard">
  <div class="card" style="margin-bottom:12px;">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
      <span class="card-title">Requisition Dashboard</span>
      <button class="btn btn-sm" type="button" onclick="rmLoadDashboard()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    </div>
    <div class="card-body">
      <div class="rm-kpi-grid">
        <div class="rm-note" style="background:linear-gradient(160deg,#ecfeff,#cffafe);border-color:#a5f3fc;">
          <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#0e7490;">Total</div>
          <div style="font-size:1.35rem;font-weight:900;color:#0f172a;" id="rm-kpi-total">0</div>
        </div>
        <div class="rm-note" style="background:linear-gradient(160deg,#fffbeb,#fef3c7);border-color:#fde68a;">
          <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#a16207;">Pending</div>
          <div style="font-size:1.35rem;font-weight:900;color:#0f172a;" id="rm-kpi-pending">0</div>
        </div>
        <div class="rm-note" style="background:linear-gradient(160deg,#ecfdf5,#dcfce7);border-color:#86efac;">
          <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#166534;">Approved</div>
          <div style="font-size:1.35rem;font-weight:900;color:#0f172a;" id="rm-kpi-approved">0</div>
        </div>
        <div class="rm-note" style="background:linear-gradient(160deg,#fef2f2,#fee2e2);border-color:#fecaca;">
          <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#991b1b;">Rejected</div>
          <div style="font-size:1.35rem;font-weight:900;color:#0f172a;" id="rm-kpi-rejected">0</div>
        </div>
        <div class="rm-note" style="background:linear-gradient(160deg,#eff6ff,#dbeafe);border-color:#bfdbfe;">
          <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;color:#1d4ed8;">PO Created</div>
          <div style="font-size:1.35rem;font-weight:900;color:#0f172a;" id="rm-kpi-po">0</div>
        </div>
      </div>

      <div class="rm-queue-grid" style="margin-top:10px;">
        <div class="rm-note" id="rm-admin-queue-wrap" style="display:none;background:linear-gradient(160deg,#f0fdfa,#ccfbf1);border-color:#99f6e4;">
          <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#0f766e;">Admin Queue</div>
          <div style="margin-top:4px;color:#0f172a;">Pending Approval: <strong id="rm-kpi-admin-pending">0</strong></div>
        </div>
        <div class="rm-note" id="rm-accounts-queue-wrap" style="display:none;background:linear-gradient(160deg,#fff7ed,#ffedd5);border-color:#fdba74;">
          <div style="font-size:.72rem;font-weight:800;text-transform:uppercase;color:#c2410c;">Accounts Queue</div>
          <div style="margin-top:4px;color:#0f172a;">Approved Waiting PO: <strong id="rm-kpi-accounts-approved">0</strong></div>
          <div style="margin-top:2px;color:#0f172a;">Total PO: <strong id="rm-kpi-accounts-po-count">0</strong></div>
        </div>
      </div>
    </div>
  </div>
</div>
