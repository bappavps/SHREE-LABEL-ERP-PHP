<div class="rm-panel" id="rm-panel-my">
  <div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
      <span class="card-title">My Requisitions</span>
      <button class="btn btn-sm" type="button" onclick="rmLoadMy()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
    </div>
    <div class="card-body" style="overflow:auto;">
      <table class="table rm-table" id="rm-my-table">
        <thead>
          <tr>
            <th>Req ID</th>
            <th>Date</th>
            <th>Image</th>
            <th>Items</th>
            <th>Total Qty</th>
            <th>Status</th>
            <th>Current Stock</th>
            <th>Comment</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <tr><td colspan="9" style="text-align:center;color:#64748b;">Loading...</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
