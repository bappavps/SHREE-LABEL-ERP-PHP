<div class="rm-panel" id="rm-panel-admin">
  <?php if (!$rmCanAdmin): ?>
    <div class="card"><div class="card-body">Access denied. Admin/Manager only.</div></div>
  <?php else: ?>
    <div class="card">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">Approval Panel</span>
        <button class="btn btn-sm" type="button" onclick="rmLoadAdmin()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
      </div>
      <div class="card-body" style="overflow:auto;">
        <table class="table rm-table" id="rm-admin-table">
          <thead>
            <tr>
              <th>Req ID</th>
              <th>User</th>
              <th>Department</th>
              <th>Items</th>
              <th>Primary Category</th>
              <th>Total Qty</th>
              <th>Status</th>
              <th>Required Date</th>
              <th>Priority</th>
              <th>Attachment</th>
              <th>Open</th>
            </tr>
          </thead>
          <tbody>
            <tr><td colspan="11" style="text-align:center;color:#64748b;">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
</div>
