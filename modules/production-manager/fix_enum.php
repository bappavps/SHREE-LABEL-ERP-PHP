<?php
require_once __DIR__ . '/../../config/db.php';
$db = getDB();

// Modify the status ENUM to include packing-related statuses
$sql = "ALTER TABLE jobs MODIFY COLUMN status ENUM(
    'Queued','Pending','Running','Closed','Finalized','Completed','QC Passed','QC Failed',
    'Packed','Packing','Packing Done','Finished Production','Finished Barcode','Dispatched','On Hold'
) NULL DEFAULT 'Pending'";

if ($db->query($sql)) {
    echo json_encode(['success' => true, 'message' => 'Status ENUM updated successfully']);
} else {
    echo json_encode(['success' => false, 'error' => $db->error]);
}
