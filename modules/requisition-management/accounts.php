<div class="rm-panel" id="rm-panel-accounts">
  <?php if (!$rmCanAccounts): ?>
    <div class="card"><div class="card-body">Access denied. Accounts/Purchase role required.</div></div>
  <?php else: ?>
    <div class="card" style="margin-bottom:12px;">
      <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <span class="card-title">PO Management (Approved Requisitions)</span>
        <button class="btn btn-sm" type="button" onclick="rmLoadApproved()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
      </div>
      <div class="card-body" style="overflow:auto;">
        <table class="table rm-table" id="rm-acc-table">
          <thead>
            <tr>
              <th>Req ID</th>
              <th>Department</th>
              <th>Items</th>
              <th>Primary Category</th>
              <th>Total Qty</th>
              <th>Unit</th>
              <th>Required Date</th>
              <th>Approved By</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>
            <tr><td colspan="9" style="text-align:center;color:#64748b;">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><span class="card-title">Generated Purchase Orders</span></div>
      <div class="card-body" style="overflow:auto;">
        <table class="table rm-table" id="rm-po-table">
          <thead>
            <tr>
              <th>PO ID</th>
              <th>Req ID</th>
              <th>Vendor</th>
              <th>Rate</th>
              <th>GST%</th>
              <th>Total</th>
              <th>Delivery Date</th>
              <th>Payment Terms</th>
              <th>Created</th>
            </tr>
          </thead>
          <tbody>
            <tr><td colspan="9" style="text-align:center;color:#64748b;">Loading...</td></tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal" id="rm-po-modal" aria-hidden="true" style="display:none;">
      <div class="modal-content" style="max-width:680px;">
        <div class="modal-header">
          <h5 class="modal-title">Generate Purchase Order</h5>
          <button type="button" class="btn btn-sm" onclick="rmClosePoModal()">Close</button>
        </div>
        <div class="modal-body">
          <form id="rm-po-form" class="rm-grid">
            <input type="hidden" name="requisition_id">
            <div class="rm-col-2 rm-note" id="rm-po-autofill"></div>
            <div>
              <label>Vendor Name</label>
              <input type="text" name="vendor_name" required>
            </div>
            <div>
              <label>Rate</label>
              <input type="number" min="0" step="0.01" name="rate" required>
            </div>
            <div>
              <label>GST (%)</label>
              <input type="number" min="0" step="0.01" name="gst" value="0" required>
            </div>
            <div>
              <label>Delivery Date</label>
              <input type="date" name="delivery_date" required>
            </div>
            <div class="rm-col-2">
              <label>Payment Terms</label>
              <textarea name="payment_terms" rows="2" placeholder="e.g. 30 days credit"></textarea>
            </div>
          </form>
        </div>
        <div class="modal-footer" style="display:flex;justify-content:flex-end;gap:8px;">
          <button class="btn" type="button" onclick="rmClosePoModal()">Cancel</button>
          <button class="btn btn-primary" type="button" onclick="rmSubmitPO()">Save PO</button>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
