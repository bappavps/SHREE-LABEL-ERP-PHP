<?php
$pageTitle = 'Final Artwork';
$activePage = 'final-projects';
require_once __DIR__ . '/../includes/header.php';

$db = Db::getInstance();
$user = getAuthUser();
$isAdmin = (($designer['role'] ?? '') === 'admin');
$canUpload = in_array(($designer['role'] ?? ''), ['admin', 'designer'], true);

function syncLegacyFinalFiles(PDO $db, int $userId, string $userName): void {
    $legacyStmt = $db->query("SELECT f.id AS legacy_file_id, f.project_id, f.filename, f.original_name, f.uploaded_at, p.client_name, p.job_color
                             FROM artwork_files f
                             INNER JOIN artwork_projects p ON p.id = f.project_id
                             WHERE f.is_final = 1");
    $legacyRows = $legacyStmt ? $legacyStmt->fetchAll() : [];

    $insert = $db->prepare('INSERT INTO artwork_final_files
        (project_id, legacy_artwork_file_id, client_name, color_job, original_name, stored_name, file_size, mime_type, uploaded_by, uploaded_by_name, notes, is_active, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');

    foreach ($legacyRows as $legacy) {
        $legacyId = (int)($legacy['legacy_file_id'] ?? 0);
        if ($legacyId <= 0) {
            continue;
        }

        $exists = $db->prepare('SELECT id FROM artwork_final_files WHERE legacy_artwork_file_id = ? LIMIT 1');
        $exists->execute([$legacyId]);
        if ($exists->fetchColumn()) {
            continue;
        }

        $projectId = (int)($legacy['project_id'] ?? 0);
        $baseName = basename((string)($legacy['filename'] ?? ''));
        if ($baseName === '') {
            continue;
        }

        $storedName = 'project_' . $projectId . '_final_' . $baseName;
        $finalPath = UPLOAD_FINAL_DIR . DIRECTORY_SEPARATOR . $storedName;
        $sourcePath = UPLOAD_PROJECT_DIR . DIRECTORY_SEPARATOR . $baseName;

        if (!is_file($finalPath) && is_file($sourcePath)) {
            @copy($sourcePath, $finalPath);
        }

        if (!is_file($finalPath)) {
            continue;
        }

        $size = (int)@filesize($finalPath);
        $mime = 'application/pdf';

        $insert->execute([
            $projectId > 0 ? $projectId : null,
            $legacyId,
            (string)($legacy['client_name'] ?? 'Unknown Client'),
            (string)($legacy['job_color'] ?? ''),
            (string)($legacy['original_name'] ?? $baseName),
            $storedName,
            max(0, $size),
            $mime,
            $userId,
            $userName,
            'Imported from legacy final marker',
            (string)($legacy['uploaded_at'] ?? date('Y-m-d H:i:s')),
        ]);
    }
}

syncLegacyFinalFiles($db, (int)($user['id'] ?? 0), (string)($user['name'] ?? 'System'));

$search = sanitize((string)($_GET['search'] ?? ''));
$client = sanitize((string)($_GET['client'] ?? ''));
$plate = sanitize((string)($_GET['plate'] ?? ''));
$die = sanitize((string)($_GET['die'] ?? ''));
$color = sanitize((string)($_GET['color'] ?? ''));
$dateFrom = sanitize((string)($_GET['date_from'] ?? ''));
$dateTo = sanitize((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 25;
$offset = ($page - 1) * $perPage;

$where = ['is_active = 1'];
$params = [];

if ($search !== '') {
    $where[] = '(client_name LIKE ? OR original_name LIKE ? OR plate_number LIKE ? OR die_number LIKE ? OR color_job LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
if ($client !== '') {
    $where[] = 'client_name LIKE ?';
    $params[] = '%' . $client . '%';
}
if ($plate !== '') {
    $where[] = 'plate_number LIKE ?';
    $params[] = '%' . $plate . '%';
}
if ($die !== '') {
    $where[] = 'die_number LIKE ?';
    $params[] = '%' . $die . '%';
}
if ($color !== '') {
    $where[] = 'color_job LIKE ?';
    $params[] = '%' . $color . '%';
}
if ($dateFrom !== '') {
    $where[] = 'DATE(created_at) >= ?';
    $params[] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(created_at) <= ?';
    $params[] = $dateTo;
}

$whereSql = implode(' AND ', $where);

$countStmt = $db->prepare('SELECT COUNT(*) FROM artwork_final_files WHERE ' . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$listSql = 'SELECT id, project_id, client_name, plate_number, die_number, color_job, job_date, original_name, stored_name, file_size, created_at, notes
            FROM artwork_final_files
            WHERE ' . $whereSql . '
            ORDER BY created_at DESC
            LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset;
$listStmt = $db->prepare($listSql);
$listStmt->execute($params);
$rows = $listStmt->fetchAll();

$effectiveLimitBytes = getEffectiveFinalUploadLimitBytes();
$effectiveLimitMb = $effectiveLimitBytes > 0 ? round($effectiveLimitBytes / 1024 / 1024, 2) : 0;
?>

<div class="section-header" style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;margin-bottom:1rem;">
    <div>
        <h2 style="margin:0 0 0.25rem;font-weight:800;letter-spacing:-0.02em;">Final Artwork File Server</h2>
        <p style="margin:0;color:var(--text-muted);font-size:0.9rem;">Central PDF archive for final design files. Users can view/upload/download, admin can edit/delete.</p>
    </div>
    <div style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;">
        <span style="font-size:0.78rem;font-weight:700;color:#0f766e;background:#ecfeff;border:1px solid #99f6e4;padding:0.45rem 0.65rem;border-radius:999px;">PDF Only</span>
        <span style="font-size:0.78rem;font-weight:700;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;padding:0.45rem 0.65rem;border-radius:999px;">Max Upload: <?php echo $effectiveLimitMb > 0 ? $effectiveLimitMb . ' MB' : 'Server Limited'; ?></span>
        <?php if ($isAdmin): ?>
            <span style="font-size:0.78rem;font-weight:700;color:#7c2d12;background:#fff7ed;border:1px solid #fed7aa;padding:0.45rem 0.65rem;border-radius:999px;">Admin: Edit + Delete</span>
        <?php else: ?>
            <span style="font-size:0.78rem;font-weight:700;color:#14532d;background:#f0fdf4;border:1px solid #bbf7d0;padding:0.45rem 0.65rem;border-radius:999px;">User: View + Upload + Download</span>
        <?php endif; ?>
    </div>
</div>

<div class="glass-card" style="padding:1rem 1rem 0.6rem;margin-bottom:1rem;">
    <form method="GET" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.65rem;align-items:end;">
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Quick Search</label>
            <input type="text" name="search" value="<?php echo sanitize($search); ?>" placeholder="Client, plate, die, color, filename" style="width:100%;padding:0.65rem 0.75rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Date From</label>
            <input type="date" name="date_from" value="<?php echo sanitize($dateFrom); ?>" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Date To</label>
            <input type="date" name="date_to" value="<?php echo sanitize($dateTo); ?>" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div style="display:flex;gap:0.5rem;">
            <button class="btn-primary" type="submit" style="padding:0.62rem 0.85rem;font-size:0.8rem;"><i class="fas fa-search"></i> Filter</button>
            <a href="final-projects.php" class="btn-primary" style="padding:0.62rem 0.85rem;font-size:0.8rem;background:#334155;text-decoration:none;"><i class="fas fa-rotate-left"></i></a>
        </div>
        <div>
            <input type="text" name="client" value="<?php echo sanitize($client); ?>" placeholder="Client filter" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <input type="text" name="plate" value="<?php echo sanitize($plate); ?>" placeholder="Plate no" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <input type="text" name="die" value="<?php echo sanitize($die); ?>" placeholder="Die no" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <input type="text" name="color" value="<?php echo sanitize($color); ?>" placeholder="Color job" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
    </form>
</div>

<?php if ($canUpload): ?>
<div class="glass-card" style="padding:1rem;margin-bottom:1rem;">
    <h3 style="margin:0 0 0.8rem;font-size:1rem;font-weight:800;color:#0f172a;">Upload Final PDF</h3>
    <form id="final-upload-form" enctype="multipart/form-data" style="display:grid;grid-template-columns:1.2fr 1fr 1fr 1fr;gap:0.65rem;align-items:end;">
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Client Name *</label>
            <input type="text" name="client_name" required style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Plate Number</label>
            <input type="text" name="plate_number" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Die Number</label>
            <input type="text" name="die_number" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Color Job</label>
            <input type="text" name="color_job" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Project (Optional)</label>
            <input type="number" min="1" name="project_id" placeholder="Project ID" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Date</label>
            <input type="date" name="job_date" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">PDF File *</label>
            <input type="file" name="final_pdf" accept="application/pdf,.pdf" required style="width:100%;padding:0.47rem;border-radius:10px;border:1px solid #dbeafe;outline:none;background:#fff;">
        </div>
        <div>
            <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Notes</label>
            <input type="text" name="notes" placeholder="Optional note" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
        </div>
        <div style="grid-column:1/-1;display:flex;justify-content:flex-end;">
            <button type="submit" class="btn-primary" style="padding:0.62rem 1rem;font-size:0.82rem;"><i class="fas fa-upload"></i> Upload PDF</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="glass-card" style="padding:0;overflow:auto;">
    <table style="width:100%;border-collapse:collapse;min-width:1080px;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Client</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Date</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Plate No</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Die No</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Color Job</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">File Name</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Preview</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Download</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr>
                <td colspan="9" style="padding:1.2rem;text-align:center;color:#64748b;">No files found for current filters.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.78rem;font-size:0.83rem;font-weight:700;color:#0f172a;"><?php echo sanitize((string)$row['client_name']); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo date('M d, Y', strtotime((string)$row['created_at'])); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize((string)($row['plate_number'] ?? '')); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize((string)($row['die_number'] ?? '')); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize((string)($row['color_job'] ?? '')); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#0f172a;max-width:260px;">
                        <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo sanitize((string)$row['original_name']); ?>"><?php echo sanitize((string)$row['original_name']); ?></div>
                        <div style="font-size:0.72rem;color:#64748b;"><?php echo formatSize((int)$row['file_size']); ?></div>
                    </td>
                    <td style="padding:0.78rem;">
                        <a class="btn-primary" style="padding:0.45rem 0.7rem;font-size:0.75rem;text-decoration:none;" target="_blank" href="api/final-file.php?id=<?php echo (int)$row['id']; ?>&mode=preview"><i class="fas fa-eye"></i> Preview</a>
                    </td>
                    <td style="padding:0.78rem;">
                        <a class="btn-primary" style="padding:0.45rem 0.7rem;font-size:0.75rem;text-decoration:none;background:#0f172a;" href="api/final-file.php?id=<?php echo (int)$row['id']; ?>&mode=download"><i class="fas fa-download"></i> Download</a>
                    </td>
                    <td style="padding:0.78rem;">
                        <?php if ($isAdmin): ?>
                            <button type="button" class="btn-primary btn-edit-final" 
                                data-id="<?php echo (int)$row['id']; ?>"
                                data-client="<?php echo sanitize((string)$row['client_name']); ?>"
                                data-plate="<?php echo sanitize((string)($row['plate_number'] ?? '')); ?>"
                                data-die="<?php echo sanitize((string)($row['die_number'] ?? '')); ?>"
                                data-color="<?php echo sanitize((string)($row['color_job'] ?? '')); ?>"
                                data-date="<?php echo sanitize((string)($row['job_date'] ?? '')); ?>"
                                data-notes="<?php echo sanitize((string)($row['notes'] ?? '')); ?>"
                                style="padding:0.45rem 0.7rem;font-size:0.75rem;background:#0369a1;">
                                <i class="fas fa-pen"></i> Edit
                            </button>
                            <button type="button" class="btn-primary btn-delete-final" data-id="<?php echo (int)$row['id']; ?>" style="padding:0.45rem 0.7rem;font-size:0.75rem;background:#dc2626;">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        <?php else: ?>
                            <span style="font-size:0.75rem;color:#94a3b8;font-weight:700;">Admin only</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.9rem;">
    <p style="margin:0;font-size:0.8rem;color:#64748b;">Showing page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $totalRows; ?> files)</p>
    <div style="display:flex;gap:0.4rem;">
        <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
            <?php
                $query = $_GET;
                $query['page'] = $p;
                $href = 'final-projects.php?' . http_build_query($query);
            ?>
            <a href="<?php echo sanitize($href); ?>" style="text-decoration:none;padding:0.42rem 0.64rem;border-radius:8px;border:1px solid #cbd5e1;background:<?php echo $p === $page ? '#0f766e' : '#fff'; ?>;color:<?php echo $p === $page ? '#fff' : '#334155'; ?>;font-size:0.78rem;font-weight:700;"><?php echo $p; ?></a>
        <?php endfor; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div id="edit-final-modal" style="position:fixed;inset:0;display:none;align-items:center;justify-content:center;background:rgba(2,6,23,0.5);z-index:2500;padding:1rem;">
    <div style="width:min(520px,96vw);background:#fff;border-radius:14px;border:1px solid #cbd5e1;box-shadow:0 20px 50px rgba(15,23,42,.35);padding:1rem;">
        <h4 style="margin:0 0 0.8rem;font-size:1rem;font-weight:800;color:#0f172a;">Edit Final File Metadata</h4>
        <form id="edit-final-form" style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;">
            <input type="hidden" name="id" id="edit-id">
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Client Name *</label>
                <input type="text" name="client_name" id="edit-client" required style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Date</label>
                <input type="date" name="job_date" id="edit-date" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Plate Number</label>
                <input type="text" name="plate_number" id="edit-plate" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Die Number</label>
                <input type="text" name="die_number" id="edit-die" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div style="grid-column:1/-1;">
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Color Job</label>
                <input type="text" name="color_job" id="edit-color" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div style="grid-column:1/-1;">
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Notes</label>
                <textarea name="notes" id="edit-notes" rows="3" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;resize:vertical;"></textarea>
            </div>
            <div style="grid-column:1/-1;display:flex;justify-content:flex-end;gap:0.5rem;">
                <button type="button" id="edit-cancel" class="btn-primary" style="background:#334155;padding:0.56rem 0.85rem;font-size:0.8rem;">Cancel</button>
                <button type="submit" class="btn-primary" style="padding:0.56rem 0.85rem;font-size:0.8rem;">Save</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    const uploadForm = document.getElementById('final-upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const fd = new FormData(uploadForm);
            fetch('api/upload-final-library.php', {
                method: 'POST',
                body: fd
            }).then(function (res) { return res.json(); })
              .then(function (data) {
                  if (data.status !== 'success') {
                      alert(data.message || 'Upload failed.');
                      return;
                  }
                  alert('Upload successful.');
                  window.location.reload();
              })
              .catch(function () {
                  alert('Upload failed due to network/server issue.');
              });
        });
    }

    const deleteButtons = document.querySelectorAll('.btn-delete-final');
    deleteButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-id');
            if (!id) return;
            if (!window.confirm('Delete this final file?')) return;

            const fd = new FormData();
            fd.append('id', id);
            fetch('api/delete-final-library.php', {
                method: 'POST',
                body: fd
            }).then(function (res) { return res.json(); })
              .then(function (data) {
                  if (data.status !== 'success') {
                      alert(data.message || 'Delete failed.');
                      return;
                  }
                  window.location.reload();
              })
              .catch(function () {
                  alert('Delete failed due to network/server issue.');
              });
        });
    });

    const editModal = document.getElementById('edit-final-modal');
    const editForm = document.getElementById('edit-final-form');
    const editCancel = document.getElementById('edit-cancel');

    function closeEditModal() {
        if (editModal) {
            editModal.style.display = 'none';
        }
    }

    if (editCancel) {
        editCancel.addEventListener('click', closeEditModal);
    }

    const editButtons = document.querySelectorAll('.btn-edit-final');
    editButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!editModal) return;
            document.getElementById('edit-id').value = btn.getAttribute('data-id') || '';
            document.getElementById('edit-client').value = btn.getAttribute('data-client') || '';
            document.getElementById('edit-plate').value = btn.getAttribute('data-plate') || '';
            document.getElementById('edit-die').value = btn.getAttribute('data-die') || '';
            document.getElementById('edit-color').value = btn.getAttribute('data-color') || '';
            document.getElementById('edit-date').value = btn.getAttribute('data-date') || '';
            document.getElementById('edit-notes').value = btn.getAttribute('data-notes') || '';
            editModal.style.display = 'flex';
        });
    });

    if (editForm) {
        editForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const fd = new FormData(editForm);
            fetch('api/update-final-library.php', {
                method: 'POST',
                body: fd
            }).then(function (res) { return res.json(); })
              .then(function (data) {
                  if (data.status !== 'success') {
                      alert(data.message || 'Update failed.');
                      return;
                  }
                  closeEditModal();
                  window.location.reload();
              })
              .catch(function () {
                  alert('Update failed due to network/server issue.');
              });
        });
    }

    if (editModal) {
        editModal.addEventListener('click', function (event) {
            if (event.target === editModal) {
                closeEditModal();
            }
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
