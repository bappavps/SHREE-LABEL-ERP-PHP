<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

$pageTitle = 'Job Approvals';
$csrf = generateCSRF();
$canReview = hasRole('admin', 'manager');
include __DIR__ . '/../../includes/header.php';
?>

<div class="breadcrumb">
  <a href="<?= BASE_URL ?>/modules/dashboard/index.php">Dashboard</a>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Master</span>
  <span class="breadcrumb-sep">&#8250;</span>
  <span>Job Approvals</span>
</div>

<div class="page-header">
  <div>
    <h1>Job Approvals</h1>
    <p>Review Jumbo Operator edit requests. Approve to apply changes, reject to keep existing roll details.</p>
  </div>
</div>

<div class="card" style="margin-bottom:12px;">
  <div style="padding:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span style="font-size:.78rem;color:#6b7280;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">Filter</span>
    <button type="button" class="btn btn-sm ap-filter active" data-filter="pending">Pending</button>
    <button type="button" class="btn btn-sm ap-filter" data-filter="history">History</button>
    <button type="button" class="btn btn-sm ap-filter" data-filter="all">All</button>
    <button type="button" class="btn btn-sm btn-secondary" id="btnRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
  </div>
</div>

<div class="card">
  <div class="card-header"><span class="card-title">Jumbo Change Requests</span></div>
  <div style="padding:12px;overflow:auto;">
    <table class="table" style="min-width:980px;">
      <thead>
        <tr>
          <th>#</th>
          <th>Job Card</th>
          <th>Requested By</th>
          <th>Wastage (kg)</th>
          <th>Rows</th>
          <th>Status</th>
          <th>Requested At</th>
          <th>Reviewed By</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody id="reqBody">
        <tr><td colspan="9" style="text-align:center;color:#6b7280;">Loading requests...</td></tr>
      </tbody>
    </table>
  </div>
</div>

<div class="modal" id="reqModal" aria-hidden="true" style="display:none">
  <div class="modal-content" style="max-width:1100px;width:94vw;max-height:90vh;overflow:hidden;">
    <div class="modal-header">
      <h5 class="modal-title" id="reqTitle">Request Detail</h5>
      <button type="button" class="btn btn-sm" id="btnCloseModal">Close</button>
    </div>
    <div class="modal-body" style="max-height:65vh;overflow:auto;">
      <div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:12px;">
        <div><div style="font-size:.8rem;color:#6b7280;">Job Card</div><div id="mJob" style="font-weight:600;">-</div></div>
        <div><div style="font-size:.8rem;color:#6b7280;">Requested By</div><div id="mBy" style="font-weight:600;">-</div></div>
        <div><div style="font-size:.8rem;color:#6b7280;">Remarks</div><div id="mRemarks" style="font-weight:600;">-</div></div>
      </div>

      <div class="card">
        <div class="card-header"><span class="card-title">Requested Roll Changes</span></div>
        <div style="padding:10px;overflow:auto;">
          <table class="table" style="min-width:1000px;">
            <thead>
              <tr>
                <th>Bucket</th>
                <th>Roll No</th>
                <th>Width</th>
                <th>Length</th>
                <th>Wastage</th>
                <th>Status</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody id="mRows"></tbody>
          </table>
        </div>
      </div>

      <div class="card" id="mManagerApplyCard" style="margin-top:12px;display:none;">
        <div class="card-header"><span class="card-title">Manager Accept Edit (Selected Parent Roll Only)</span></div>
        <div style="padding:10px;overflow:auto;">
          <div class="grid" style="grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:10px;">
            <div>
              <div style="font-size:.75rem;color:#6b7280;margin-bottom:4px;">Old Parent Roll</div>
              <input type="text" id="mOldParent" class="form-control" readonly>
            </div>
            <div>
              <div style="font-size:.75rem;color:#6b7280;margin-bottom:4px;">New Parent Roll</div>
              <input type="text" id="mNewParent" class="form-control" placeholder="Enter replacement parent roll">
            </div>
            <div>
              <div style="font-size:.75rem;color:#6b7280;margin-bottom:4px;">Review Note</div>
              <input type="text" id="mApplyNote" class="form-control" placeholder="Manager note (optional)">
            </div>
          </div>

          <table class="table" style="min-width:1000px;">
            <thead>
              <tr>
                <th>Bucket</th>
                <th>Roll No</th>
                <th>Width</th>
                <th>Length</th>
                <th>Wastage</th>
                <th>Status</th>
                <th>Remarks</th>
              </tr>
            </thead>
            <tbody id="mEditRows"></tbody>
          </table>
        </div>
      </div>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:space-between;gap:8px;">
      <div>
        <textarea id="reviewNote" class="form-control" rows="2" style="min-width:380px;" placeholder="Review note (optional)"></textarea>
      </div>
      <div style="display:flex;gap:8px;align-items:center;">
        <button type="button" class="btn" id="btnCloseModalFooter">Close</button>
        <button type="button" class="btn btn-danger" id="btnReject">Reject</button>
        <button type="button" class="btn btn-success" id="btnApprove">Accept &amp; Edit</button>
        <button type="button" class="btn btn-success" id="btnApplyAccept" style="display:none;">Apply Accepted Change</button>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const API_URL = '<?= BASE_URL ?>/modules/jobs/api.php';
  const CSRF = <?= json_encode($csrf) ?>;
  const CAN_REVIEW = <?= $canReview ? 'true' : 'false' ?>;
  const tbody = document.getElementById('reqBody');
  const modal = document.getElementById('reqModal');
  const managerApplyCard = document.getElementById('mManagerApplyCard');
  let allRequests = [];
  let activeFilter = 'pending';
  let activeReq = null;
  let managerEditMode = false;

  function toast(msg, type) {
    if (typeof showToast === 'function') { showToast(msg, type || 'info'); return; }
    if (typeof erpToast === 'function') { erpToast(msg, type || 'info'); return; }
    alert(msg);
  }

  async function apiGet(params) {
    const qs = new URLSearchParams(params);
    const res = await fetch(API_URL + '?' + qs.toString(), { credentials: 'same-origin' });
    return res.json();
  }

  async function apiPost(payload) {
    const body = new URLSearchParams(payload);
    body.set('csrf_token', CSRF);
    const res = await fetch(API_URL, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body,
      credentials: 'same-origin'
    });
    return res.json();
  }

  function statusBadge(status) {
    const s = String(status || '').toLowerCase();
    if (s === 'approved') return '<span class="badge badge-approved">Approved</span>';
    if (s === 'rejected') return '<span class="badge badge-rejected">Rejected</span>';
    return '<span class="badge badge-pending">Pending</span>';
  }

  function filteredRequests() {
    if (activeFilter === 'all') return allRequests.slice();
    if (activeFilter === 'history') return allRequests.filter(r => String(r.status || '').toLowerCase() !== 'pending');
    return allRequests.filter(r => String(r.status || '').toLowerCase() === 'pending');
  }

  function render() {
    const rows = filteredRequests();
    if (!rows.length) {
      tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#6b7280;">No requests found.</td></tr>';
      return;
    }

    tbody.innerHTML = rows.map(r => {
      const p = r.payload || {};
      const requestedRows = Array.isArray(p.rows) ? p.rows.length : 0;
      const canReview = CAN_REVIEW && String(r.status || '').toLowerCase() === 'pending';
      return `
      <tr>
        <td>${r.id}</td>
        <td>${r.job_no || ('JOB-' + r.job_id)}</td>
        <td>${r.requested_by_name || '-'}</td>
        <td>${Number(p.wastage_kg || 0).toFixed(2)}</td>
        <td>${requestedRows}</td>
        <td>${statusBadge(r.status)}</td>
        <td>${r.requested_at || '-'}</td>
        <td>${r.reviewed_by_name || '-'}</td>
        <td>
          <button class="btn btn-sm" onclick="window.apOpen(${r.id})">Open</button>
          ${canReview ? '<button class="btn btn-sm btn-success" onclick="window.apApprove(' + r.id + ')">Accept</button>' : ''}
          ${canReview ? '<button class="btn btn-sm btn-danger" onclick="window.apReject(' + r.id + ')">Reject</button>' : ''}
        </td>
      </tr>`;
    }).join('');
  }

  async function loadRequests(showToastOnDone) {
    const data = await apiGet({ action: 'list_jumbo_change_requests', status: 'all', limit: 300 });
    allRequests = Array.isArray(data && data.requests) ? data.requests : [];
    render();
    if (showToastOnDone) toast('Requests refreshed', 'success');
  }

  function openModal() {
    modal.style.display = 'flex';
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    modal.style.display = 'none';
    modal.setAttribute('aria-hidden', 'true');
    activeReq = null;
    managerEditMode = false;
    managerApplyCard.style.display = 'none';
    document.getElementById('btnApplyAccept').style.display = 'none';
    document.getElementById('btnApprove').style.display = '';
  }

  function pickParentMapping(req) {
    const p = req.payload || {};
    const changes = Array.isArray(p.roll_changes) ? p.roll_changes : [];
    if (changes.length > 0) {
      const first = changes[0] || {};
      return {
        oldParent: String(first.original_roll || '').trim(),
        newParent: String(first.substitute_roll || '').trim()
      };
    }
    const rows = Array.isArray(p.rows) ? p.rows : [];
    const oldParentFromRows = rows.map(r => String(r.parent_roll_no || '').trim()).find(Boolean) || '';
    return {
      oldParent: oldParentFromRows,
      newParent: String(p.parent_roll_no || '').trim()
    };
  }

  function buildEditRows(rows) {
    const body = document.getElementById('mEditRows');
    if (!rows.length) {
      body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#6b7280;">No rows found in request.</td></tr>';
      return;
    }
    body.innerHTML = rows.map((r, i) => {
      const bucket = String(r.bucket || 'child').toLowerCase() === 'stock' ? 'stock' : 'child';
      return `
      <tr data-idx="${i}">
        <td>
          <select class="form-control ap-row-bucket">
            <option value="child" ${bucket === 'child' ? 'selected' : ''}>child</option>
            <option value="stock" ${bucket === 'stock' ? 'selected' : ''}>stock</option>
          </select>
        </td>
        <td><input class="form-control ap-row-roll" value="${String(r.roll_no || '')}"></td>
        <td><input type="number" step="0.01" class="form-control ap-row-width" value="${Number(r.width || 0)}"></td>
        <td><input type="number" step="0.01" class="form-control ap-row-length" value="${Number(r.length || 0)}"></td>
        <td><input type="number" step="0.01" class="form-control ap-row-wastage" value="${Number(r.wastage || 0)}"></td>
        <td><input class="form-control ap-row-status" value="${String(r.status || '')}"></td>
        <td><input class="form-control ap-row-remarks" value="${String(r.remarks || '')}"></td>
      </tr>`;
    }).join('');
  }

  function collectEditRows() {
    return Array.from(document.querySelectorAll('#mEditRows tr[data-idx]')).map(tr => ({
      bucket: tr.querySelector('.ap-row-bucket')?.value || 'child',
      roll_no: tr.querySelector('.ap-row-roll')?.value || '',
      width: Number(tr.querySelector('.ap-row-width')?.value || 0),
      length: Number(tr.querySelector('.ap-row-length')?.value || 0),
      wastage: Number(tr.querySelector('.ap-row-wastage')?.value || 0),
      status: tr.querySelector('.ap-row-status')?.value || '',
      remarks: tr.querySelector('.ap-row-remarks')?.value || ''
    })).filter(r => String(r.roll_no || '').trim() !== '');
  }

  function bindModal(req) {
    activeReq = req;
    managerEditMode = false;
    const p = req.payload || {};
    const rows = Array.isArray(p.rows) ? p.rows : [];
    document.getElementById('reqTitle').textContent = 'Request #' + req.id + ' - ' + (req.job_no || ('JOB-' + req.job_id));
    document.getElementById('mJob').textContent = req.job_no || ('JOB-' + req.job_id);
    document.getElementById('mBy').textContent = req.requested_by_name || '-';
    document.getElementById('mRemarks').textContent = p.operator_remarks || '-';
    document.getElementById('reviewNote').value = '';

    const canReview = CAN_REVIEW && String(req.status || '').toLowerCase() === 'pending';
    document.getElementById('btnApprove').disabled = !canReview;
    document.getElementById('btnReject').disabled = !canReview;
    document.getElementById('btnApplyAccept').disabled = !canReview;
    document.getElementById('btnApplyAccept').style.display = 'none';
    document.getElementById('btnApprove').style.display = '';
    managerApplyCard.style.display = 'none';

    const map = pickParentMapping(req);
    document.getElementById('mOldParent').value = map.oldParent;
    document.getElementById('mNewParent').value = map.newParent;
    document.getElementById('mApplyNote').value = '';
    buildEditRows(rows);

    const mRows = document.getElementById('mRows');
    if (!rows.length) {
      mRows.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#6b7280;">No row changes in this request.</td></tr>';
      return;
    }

    mRows.innerHTML = rows.map(r => {
      return `
      <tr>
        <td>${r.bucket || '-'}</td>
        <td>${r.roll_no || '-'}</td>
        <td>${Number(r.width || 0).toFixed(2)}</td>
        <td>${Number(r.length || 0).toFixed(2)}</td>
        <td>${Number(r.wastage || 0).toFixed(2)}</td>
        <td>${r.status || '-'}</td>
        <td>${r.remarks || '-'}</td>
      </tr>`;
    }).join('');
  }

  async function review(decision, requestId) {
    const note = document.getElementById('reviewNote').value || '';
    const res = await apiPost({
      action: 'review_jumbo_change_request',
      request_id: String(requestId),
      decision: decision,
      review_note: note
    });
    if (!res || !res.ok) {
      toast((res && res.error) || 'Review failed', 'error');
      return;
    }
    toast('Request ' + decision.toLowerCase(), 'success');
    closeModal();
    loadRequests(false);
  }

  function startAcceptEdit() {
    if (!activeReq) return;
    const canReview = CAN_REVIEW && String(activeReq.status || '').toLowerCase() === 'pending';
    if (!canReview) return;
    managerEditMode = true;
    managerApplyCard.style.display = '';
    document.getElementById('btnApplyAccept').style.display = '';
    document.getElementById('btnApprove').style.display = 'none';
  }

  async function applyAcceptedChange() {
    if (!activeReq || !managerEditMode) return;
    const oldParent = String(document.getElementById('mOldParent').value || '').trim();
    const newParent = String(document.getElementById('mNewParent').value || '').trim();
    const note = String(document.getElementById('mApplyNote').value || '').trim() || String(document.getElementById('reviewNote').value || '').trim();
    const rows = collectEditRows();

    if (!oldParent || !newParent) {
      toast('Old/new parent roll is required', 'error');
      return;
    }
    if (!rows.length) {
      toast('At least one row is required', 'error');
      return;
    }

    const payload = {
      action: 'apply_jumbo_manager_roll_update',
      job_id: String(activeReq.job_id),
      request_id: String(activeReq.id),
      old_parent_roll_no: oldParent,
      old_parent_prev_status: 'Main',
      rows_json: JSON.stringify(rows),
      review_note: note
    };

    const res = await apiPost(payload);
    if (!res || !res.ok) {
      toast((res && res.error) || 'Apply failed', 'error');
      return;
    }
    toast('Accepted and applied successfully', 'success');
    closeModal();
    loadRequests(false);
  }

  window.apOpen = function(id) {
    const req = allRequests.find(r => Number(r.id) === Number(id));
    if (!req) return;
    bindModal(req);
    openModal();
  };

  window.apApprove = function(id) {
    const req = allRequests.find(r => Number(r.id) === Number(id));
    if (!req) return;
    bindModal(req);
    openModal();
  };

  window.apReject = function(id) {
    const req = allRequests.find(r => Number(r.id) === Number(id));
    if (!req) return;
    bindModal(req);
    openModal();
  };

  document.querySelectorAll('.ap-filter').forEach(btn => {
    btn.addEventListener('click', function() {
      activeFilter = String(this.getAttribute('data-filter') || 'pending');
      document.querySelectorAll('.ap-filter').forEach(b => b.classList.remove('active'));
      this.classList.add('active');
      render();
    });
  });

  document.getElementById('btnRefresh').addEventListener('click', () => loadRequests(true));
  document.getElementById('btnCloseModal').addEventListener('click', closeModal);
  document.getElementById('btnCloseModalFooter').addEventListener('click', closeModal);
  document.getElementById('btnApprove').addEventListener('click', function() {
    if (!activeReq) return;
    startAcceptEdit();
  });
  document.getElementById('btnReject').addEventListener('click', function() {
    if (!activeReq) return;
    review('Rejected', activeReq.id);
  });
  document.getElementById('btnApplyAccept').addEventListener('click', function() {
    applyAcceptedChange();
  });
  modal.addEventListener('click', function(e){ if (e.target === modal) closeModal(); });

  loadRequests(false);
  setInterval(() => loadRequests(false), 30000);
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
