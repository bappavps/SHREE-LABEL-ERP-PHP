<div class="rm-panel" id="rm-panel-user">
  <div class="card" style="margin-bottom:12px;">
    <div class="card-header"><span class="card-title">New Requisition</span></div>
    <div class="card-body">
      <form id="rm-new-form" class="rm-grid">
        <div>
          <label>Department</label>
          <select name="department" required>
            <?php foreach ($rmDepartments as $dep): ?>
              <option value="<?= e($dep) ?>"><?= e($dep) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Required Date</label>
          <input type="date" name="required_date" required>
        </div>
        <div>
          <label>Priority</label>
          <select name="priority" required>
            <option value="Normal">Normal</option>
            <option value="Urgent">Urgent</option>
          </select>
        </div>
        <div class="rm-col-2">
          <label>Requisition Notes</label>
          <textarea name="remarks" rows="2" placeholder="Overall note for this requisition..."></textarea>
        </div>

        <div class="rm-col-2">
          <div class="rm-section-head">
            <label>Items (Serial Wise)</label>
            <button type="button" class="btn btn-sm btn-primary" onclick="rmAddItemRow()"><i class="bi bi-plus-circle"></i> Add Item</button>
          </div>
          <div id="rm-item-rows" style="display:grid;gap:8px;"></div>
        </div>

        <div class="rm-col-2" style="display:flex;justify-content:flex-end;">
          <button class="btn btn-primary" type="submit"><i class="bi bi-send"></i> Submit Requisition</button>
        </div>
      </form>
    </div>
  </div>
</div>

<template id="rm-item-row-template">
  <div class="rm-item-row">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;gap:8px;">
      <strong class="rm-item-serial">Item #1</strong>
      <button type="button" class="btn btn-sm" onclick="rmRemoveItemRow(this)"><i class="bi bi-trash"></i> Remove</button>
    </div>
    <div class="rm-grid" style="grid-template-columns:2fr 1fr 1fr 1fr 1.5fr 1.5fr;align-items:end;">
      <div>
        <label>Item Name</label>
        <input type="text" name="item_name[]" placeholder="e.g. Marker Pen" required>
      </div>
      <div>
        <label>Category</label>
        <select name="category[]" required>
          <?php foreach ($rmCategories as $cat): ?>
            <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Quantity</label>
        <input type="number" min="0.01" step="0.01" name="qty[]" required>
      </div>
      <div>
        <label>Unit</label>
        <select name="unit[]" required>
          <?php foreach ($rmUnits as $unit): ?>
            <option value="<?= e($unit) ?>"><?= e($unit) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label>Item Image (Camera/Upload)</label>
        <input type="file" name="item_image[]" accept="image/*" capture="environment">
        <div class="rm-item-preview-wrap" style="margin-top:6px;display:none;">
          <img class="rm-item-preview" alt="Item preview" style="width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #cbd5e1;display:block;">
        </div>
      </div>
      <div>
        <label>Item Remark (optional)</label>
        <input type="text" name="item_remarks[]" placeholder="Per item note...">
      </div>
    </div>
  </div>
</template>
