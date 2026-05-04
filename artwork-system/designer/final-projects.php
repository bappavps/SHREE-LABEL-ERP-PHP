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
    $where[] = '(client_name LIKE ? OR original_name LIKE ? OR plate_number LIKE ? OR die_number LIKE ? OR color_job LIKE ? OR job_name LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like;
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

$listSql = 'SELECT id, project_id, client_name, job_name, plate_number, date_received, job_size, paper_size, paper_type, make_by, die_number, color_job, job_date, original_name, stored_name, file_size, created_at, notes
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
<div class="glass-card" style="padding:1rem;margin-bottom:1rem;border:2px solid #fde68a;">
    <div style="background:linear-gradient(90deg,#fef9c3 0%,#fef3c7 100%);border:1px solid #fcd34d;border-radius:10px;padding:0.75rem 1rem;margin-bottom:1rem;display:flex;align-items:flex-start;gap:0.65rem;">
        <i class="fas fa-lightbulb" style="color:#d97706;margin-top:0.15rem;font-size:1.05rem;flex-shrink:0;"></i>
        <div>
            <div style="font-size:0.83rem;font-weight:800;color:#92400e;margin-bottom:0.2rem;">Search by Plate Number</div>
            <div style="font-size:0.78rem;color:#78350f;line-height:1.5;">Type in the <strong>Plate Number</strong> field — a suggestion list will appear. Selecting a plate will automatically fill in Job Name, Size, Paper Size, Paper Type, Make By and Die. Any field can be manually overridden.</div>
        </div>
    </div>
    <h3 style="margin:0 0 0.8rem;font-size:1rem;font-weight:800;color:#0f172a;">Upload Final PDF</h3>
    <form id="final-upload-form" enctype="multipart/form-data">
        <div style="display:grid;grid-template-columns:1.5fr 1.5fr 1fr;gap:0.65rem;align-items:start;margin-bottom:0.65rem;">
            <div style="position:relative;">
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#0369a1;">Plate Number <span style="color:#f59e0b;font-size:0.7rem;">(type to search)</span></label>
                <input type="text" id="up-plate-input" name="plate_number" autocomplete="off" placeholder="e.g. P-1234" style="width:100%;padding:0.62rem;border-radius:10px;border:2px solid #bfdbfe;outline:none;">
                <div id="plate-suggestions" style="display:none;position:absolute;top:100%;left:0;right:0;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.12);z-index:500;max-height:220px;overflow-y:auto;"></div>
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Client Name *</label>
                <input type="text" name="client_name" required placeholder="Client name" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Date of Recv.</label>
                <input type="date" id="up-date-received" name="date_received" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:0.65rem;align-items:start;margin-bottom:0.65rem;">
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Job Name</label>
                <input type="text" id="up-job-name" name="job_name" placeholder="Auto-filled from plate" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Size (Artwork)</label>
                <input type="text" id="up-job-size" name="job_size" placeholder="e.g. 200×300 mm" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Paper Size</label>
                <input type="text" id="up-paper-size" name="paper_size" placeholder="Auto-filled" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Paper Type</label>
                <input type="text" id="up-paper-type" name="paper_type" placeholder="Auto-filled" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:0.65rem;align-items:start;margin-bottom:0.65rem;">
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Make By</label>
                <input type="text" id="up-make-by" name="make_by" placeholder="Auto-filled" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Die</label>
                <input type="text" id="up-die" name="die_number" placeholder="Auto-filled" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Color Job</label>
                <input type="text" name="color_job" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Upload Date</label>
                <input type="date" name="job_date" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
        </div>
        <div style="display:grid;grid-template-columns:2fr 2fr;gap:0.65rem;align-items:start;margin-bottom:0.65rem;">
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">PDF File *</label>
                <input type="file" name="final_pdf" accept="application/pdf,.pdf" required style="width:100%;padding:0.47rem;border-radius:10px;border:1px solid #dbeafe;outline:none;background:#fff;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;color:#475569;">Notes</label>
                <input type="text" name="notes" placeholder="Optional note" style="width:100%;padding:0.62rem;border-radius:10px;border:1px solid #dbeafe;outline:none;">
            </div>
        </div>
        <div style="display:flex;justify-content:flex-end;">
            <button type="submit" class="btn-primary" style="padding:0.62rem 1rem;font-size:0.82rem;"><i class="fas fa-upload"></i> Upload PDF</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="glass-card" style="padding:0;overflow:auto;">
    <table style="width:100%;border-collapse:collapse;min-width:1300px;">
        <thead>
            <tr style="background:#f8fafc;border-bottom:1px solid #e2e8f0;">
                <th style="text-align:center;padding:0.8rem 0.5rem;font-size:0.76rem;color:#475569;width:46px;">#</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Plate Number</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Date of Recv.</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Job Name</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Client Name</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Size</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Paper Size</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Paper Type</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Make By</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Die</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Preview</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Download</th>
                <th style="text-align:left;padding:0.8rem;font-size:0.76rem;color:#475569;">Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr>
                <td colspan="13" style="padding:1.2rem;text-align:center;color:#64748b;">No files found for current filters.</td>
            </tr>
        <?php else: ?>
            <?php foreach ($rows as $rowIdx => $row):
                $slNo = $offset + $rowIdx + 1;
                $displayDate = ($row['date_received'] && $row['date_received'] !== '0000-00-00')
                    ? date('d M Y', strtotime((string)$row['date_received'])) : 'N/A';
                $dispJobName   = ($row['job_name'] !== null && trim($row['job_name']) !== '') ? $row['job_name'] : 'N/A';
                $dispSize      = ($row['job_size'] !== null && trim($row['job_size']) !== '') ? $row['job_size'] : 'N/A';
                $dispPaperSize = ($row['paper_size'] !== null && trim($row['paper_size']) !== '') ? $row['paper_size'] : 'N/A';
                $dispPaperType = ($row['paper_type'] !== null && trim($row['paper_type']) !== '') ? $row['paper_type'] : 'N/A';
                $dispMakeBy    = ($row['make_by'] !== null && trim($row['make_by']) !== '') ? $row['make_by'] : 'N/A';
                $dispDie       = ($row['die_number'] !== null && trim($row['die_number']) !== '') ? $row['die_number'] : 'N/A';
                $dispPlate     = ($row['plate_number'] !== null && trim($row['plate_number']) !== '') ? $row['plate_number'] : '—';
            ?>
                <tr style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:0.78rem 0.5rem;font-size:0.78rem;color:#94a3b8;text-align:center;"><?php echo $slNo; ?></td>
                    <td style="padding:0.78rem;font-size:0.83rem;font-weight:700;color:#0f172a;"><?php echo sanitize((string)$dispPlate); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;white-space:nowrap;"><?php echo sanitize($displayDate); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#0f172a;max-width:180px;">
                        <div style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;" title="<?php echo sanitize($dispJobName); ?>"><?php echo sanitize($dispJobName); ?></div>
                    </td>
                    <td style="padding:0.78rem;font-size:0.81rem;font-weight:700;color:#0f172a;"><?php echo sanitize((string)$row['client_name']); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize($dispSize); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize($dispPaperSize); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize($dispPaperType); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize($dispMakeBy); ?></td>
                    <td style="padding:0.78rem;font-size:0.81rem;color:#334155;"><?php echo sanitize($dispDie); ?></td>
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
                                data-jobname="<?php echo sanitize((string)($row['job_name'] ?? '')); ?>"
                                data-plate="<?php echo sanitize((string)($row['plate_number'] ?? '')); ?>"
                                data-daterecv="<?php echo sanitize((string)($row['date_received'] ?? '')); ?>"
                                data-jobsize="<?php echo sanitize((string)($row['job_size'] ?? '')); ?>"
                                data-papersize="<?php echo sanitize((string)($row['paper_size'] ?? '')); ?>"
                                data-papertype="<?php echo sanitize((string)($row['paper_type'] ?? '')); ?>"
                                data-makeby="<?php echo sanitize((string)($row['make_by'] ?? '')); ?>"
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
    <div style="width:min(680px,96vw);background:#fff;border-radius:14px;border:1px solid #cbd5e1;box-shadow:0 20px 50px rgba(15,23,42,.35);padding:1rem;max-height:90vh;overflow-y:auto;">
        <h4 style="margin:0 0 0.8rem;font-size:1rem;font-weight:800;color:#0f172a;">Edit Final File Metadata</h4>
        <form id="edit-final-form" style="display:grid;grid-template-columns:1fr 1fr;gap:0.65rem;">
            <input type="hidden" name="id" id="edit-id">
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Client Name *</label>
                <input type="text" name="client_name" id="edit-client" required style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Job Name</label>
                <input type="text" name="job_name" id="edit-jobname" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Plate Number</label>
                <input type="text" name="plate_number" id="edit-plate" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Date of Recv.</label>
                <input type="date" name="date_received" id="edit-daterecv" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Size (Artwork)</label>
                <input type="text" name="job_size" id="edit-jobsize" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Paper Size</label>
                <input type="text" name="paper_size" id="edit-papersize" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Paper Type</label>
                <input type="text" name="paper_type" id="edit-papertype" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Make By</label>
                <input type="text" name="make_by" id="edit-makeby" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Die</label>
                <input type="text" name="die_number" id="edit-die" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Color Job</label>
                <input type="text" name="color_job" id="edit-color" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
            </div>
            <div>
                <label style="display:block;font-size:0.74rem;font-weight:700;margin-bottom:0.35rem;">Upload Date</label>
                <input type="date" name="job_date" id="edit-date" style="width:100%;padding:0.6rem;border-radius:10px;border:1px solid #dbeafe;">
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
    /* ── Plate suggestion + autofill ────────────────────────────────── */
    var plateLookupBase = '<?php echo rtrim(ERP_BASE_URL, '/'); ?>/api/plate-lookup.php';
    var plateInput = document.getElementById('up-plate-input');
    var suggBox   = document.getElementById('plate-suggestions');

    var upFields = {
        job_name     : document.getElementById('up-job-name'),
        date_received: document.getElementById('up-date-received'),
        job_size     : document.getElementById('up-job-size'),
        paper_size   : document.getElementById('up-paper-size'),
        paper_type   : document.getElementById('up-paper-type'),
        make_by      : document.getElementById('up-make-by'),
        die_number   : document.getElementById('up-die'),
    };

    var debounceTimer = null;

    function hideSuggestions() {
        if (suggBox) suggBox.style.display = 'none';
    }

    function markFilled(input) {
        if (!input) return;
        input.style.border = '2px solid #16a34a';
        input.style.background = '#f0fdf4';
        var wrap = input.parentElement;
        var existing = wrap.querySelector('.autofill-tick');
        if (!existing) {
            var tick = document.createElement('span');
            tick.className = 'autofill-tick';
            tick.innerHTML = ' &#10003;';
            tick.style.cssText = 'color:#16a34a;font-size:0.78rem;font-weight:800;margin-left:4px;';
            var lbl = wrap.querySelector('label');
            if (lbl) lbl.appendChild(tick);
        }
    }

    function clearFillMark(input) {
        if (!input) return;
        input.style.border = '';
        input.style.background = '';
        var wrap = input.parentElement;
        var tick = wrap && wrap.querySelector('.autofill-tick');
        if (tick) tick.remove();
    }

    function parseDateToISO(v) {
        if (!v) return '';
        v = String(v).trim();
        /* YYYY-MM-DD */
        if (v.match(/^\d{4}-\d{2}-\d{2}$/)) return v;
        /* DD-MM-YYYY or DD/MM/YYYY */
        var m = v.match(/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/);
        if (m) return m[3] + '-' + m[2].padStart(2,'0') + '-' + m[1].padStart(2,'0');
        /* Try native Date parse as last resort */
        var d = new Date(v);
        if (!isNaN(d.getTime())) {
            return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
        }
        return '';
    }

    function fillFromPlate(data) {
        if (!data) return;
        var na = function (v) { return (v === null || v === undefined || String(v).trim() === '' || String(v).trim().toLowerCase() === 'n/a') ? 'N/A' : String(v).trim(); };
        /* mark plate input itself green */
        markFilled(plateInput);
        var pairs = [
            [upFields.job_name,      na(data.job_name)],
            [upFields.job_size,      na(data.job_size)],
            [upFields.paper_size,    na(data.paper_size)],
            [upFields.paper_type,    na(data.paper_type)],
            [upFields.make_by,       na(data.make_by)],
            [upFields.die_number,    na(data.die_number)],
        ];
        pairs.forEach(function (p) {
            if (p[0]) { p[0].value = p[1]; markFilled(p[0]); }
        });
        if (upFields.date_received && data.date_received) {
            var iso = parseDateToISO(data.date_received);
            if (iso) {
                upFields.date_received.value = iso;
                markFilled(upFields.date_received);
            }
        }
    }

    /* clear green mark when user manually edits a field */
    Object.values(upFields).concat([plateInput]).forEach(function (inp) {
        if (!inp) return;
        inp.addEventListener('input', function () { clearFillMark(inp); });
    });

    if (plateInput && suggBox) {
        plateInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            var q = plateInput.value.trim();
            if (q.length < 1) { hideSuggestions(); return; }
            debounceTimer = setTimeout(function () {
                fetch(plateLookupBase + '?type=suggest&query=' + encodeURIComponent(q))
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d.ok || !d.suggestions || d.suggestions.length === 0) { hideSuggestions(); return; }
                        suggBox.innerHTML = '';
                        d.suggestions.forEach(function (s) {
                            var item = document.createElement('div');
                            item.textContent = s.label;
                            item.style.cssText = 'padding:0.55rem 0.75rem;cursor:pointer;font-size:0.82rem;border-bottom:1px solid #f1f5f9;';
                            item.addEventListener('mouseenter', function () { item.style.background = '#eff6ff'; });
                            item.addEventListener('mouseleave', function () { item.style.background = ''; });
                            item.addEventListener('mousedown', function (ev) {
                                ev.preventDefault();
                                plateInput.value = s.plate;
                                hideSuggestions();
                                /* fetch full record for autofill */
                                fetch(plateLookupBase + '?type=get&query=' + encodeURIComponent(s.plate))
                                    .then(function (r2) { return r2.json(); })
                                    .then(function (d2) { if (d2.ok && d2.data) fillFromPlate(d2.data); })
                                    .catch(function () {});
                            });
                            suggBox.appendChild(item);
                        });
                        suggBox.style.display = 'block';
                    })
                    .catch(function () { hideSuggestions(); });
            }, 220);
        });

        plateInput.addEventListener('blur', function () {
            setTimeout(function () {
                hideSuggestions();
                var q = plateInput.value.trim();
                if (q.length > 0) {
                    fetch(plateLookupBase + '?type=get&query=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (d) { if (d.ok && d.data) fillFromPlate(d.data); })
                        .catch(function () {});
                }
            }, 200);
        });
        plateInput.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { hideSuggestions(); return; }
            if (ev.key === 'Enter' || ev.key === 'Tab') {
                hideSuggestions();
                var q = plateInput.value.trim();
                if (q.length > 0) {
                    fetch(plateLookupBase + '?type=get&query=' + encodeURIComponent(q))
                        .then(function (r) { return r.json(); })
                        .then(function (d) { if (d.ok && d.data) fillFromPlate(d.data); })
                        .catch(function () {});
                }
            }
        });
    }

    /* ── Upload progress bar overlay ──────────────────────────────────── */
    function showUploadProgress(show) {
        var el = document.getElementById('final-upload-progress');
        if (el) el.style.display = show ? 'flex' : 'none';
        if (show && typeof window._startUploadBar === 'function') window._startUploadBar();
        if (!show && typeof window._finishUploadBar === 'function') window._finishUploadBar();
    }

    function showUploadSuccessModal(data) {
        var overlay = document.createElement('div');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:13000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:16px;';
        var thumb = data.thumbnail_url
            ? '<div style="margin-bottom:1rem;text-align:center;">'
              + '<img src="' + data.thumbnail_url + '" alt="PDF Thumbnail" style="max-width:220px;max-height:220px;border-radius:12px;border:2px solid #86efac;box-shadow:0 8px 24px rgba(0,0,0,.18);object-fit:cover;">'
              + '<div style="font-size:0.7rem;color:#64748b;margin-top:6px;">PDF Page 1 Preview</div>'
              + '</div>'
            : '';
        var plateInfo = data.plate_number ? '<div style="font-size:0.8rem;color:#475569;margin-top:4px;">Plate: <strong>' + data.plate_number + '</strong> — Plate Manager image updated</div>' : '';
        overlay.innerHTML =
            '<div style="width:min(440px,96vw);background:#fff;border-radius:16px;border:1px solid #e2e8f0;box-shadow:0 24px 56px rgba(2,6,23,.28);overflow:hidden;">'
            + '<div style="display:flex;align-items:center;gap:10px;padding:14px 18px;background:linear-gradient(90deg,#14532d,#166534);color:#fff;">'
            +   '<i class="fas fa-check-circle" style="font-size:1.3rem;"></i>'
            +   '<div style="font-size:1rem;font-weight:800;">Upload Successful</div>'
            + '</div>'
            + '<div style="padding:1.25rem 1.25rem 0.5rem;">'
            + thumb
            + '<div style="font-size:0.85rem;font-weight:700;color:#0f172a;">' + (data.original_name || 'File uploaded') + '</div>'
            + '<div style="font-size:0.8rem;color:#16a34a;margin-top:4px;">' + (data.client_name || '') + (data.job_name ? ' — ' + data.job_name : '') + '</div>'
            + plateInfo
            + '</div>'
            + '<div style="display:flex;justify-content:flex-end;gap:8px;padding:0.8rem 1.25rem;background:#f8fafc;border-top:1px solid #e2e8f0;">'
            +   '<button id="finalUploadSuccessClose" style="border:0;background:#0f172a;color:#fff;border-radius:10px;padding:9px 20px;font-weight:700;font-size:0.82rem;cursor:pointer;">OK, Reload</button>'
            + '</div>'
            + '</div>';
        document.body.appendChild(overlay);
        overlay.querySelector('#finalUploadSuccessClose').addEventListener('click', function () {
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            window.location.reload();
        });
        overlay.addEventListener('click', function (ev) {
            if (ev.target === overlay) {
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                window.location.reload();
            }
        });
    }

    /* ── Upload form ─────────────────────────────────────────────────── */
    var uploadForm = document.getElementById('final-upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function (event) {
            event.preventDefault();
            showUploadProgress(true);
            var fd = new FormData(uploadForm);
            fetch('api/upload-final-library.php', { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    showUploadProgress(false);
                    if (data.status !== 'success') {
                        if (typeof window.showERPToast === 'function') {
                            window.showERPToast(data.message || 'Upload failed.', 'error');
                        } else {
                            alert(data.message || 'Upload failed.');
                        }
                        return;
                    }
                    showUploadSuccessModal(data.data || {});
                })
                .catch(function (err) {
                    showUploadProgress(false);
                    if (typeof window.showERPToast === 'function') {
                        window.showERPToast('Upload failed: ' + (err.message || 'network error'), 'error');
                    } else {
                        alert('Upload failed due to network/server issue.');
                    }
                });
        });
    }

    /* ── Delete ──────────────────────────────────────────────────────── */
    document.querySelectorAll('.btn-delete-final').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            if (!id) return;
            if (!window.confirm('Delete this final file?')) return;
            var fd = new FormData();
            fd.append('id', id);
            fetch('api/delete-final-library.php', { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status !== 'success') { alert(data.message || 'Delete failed.'); return; }
                    window.location.reload();
                })
                .catch(function () { alert('Delete failed due to network/server issue.'); });
        });
    });

    /* ── Edit modal ──────────────────────────────────────────────────── */
    var editModal  = document.getElementById('edit-final-modal');
    var editForm   = document.getElementById('edit-final-form');
    var editCancel = document.getElementById('edit-cancel');

    function closeEditModal() { if (editModal) editModal.style.display = 'none'; }
    if (editCancel) editCancel.addEventListener('click', closeEditModal);
    if (editModal) editModal.addEventListener('click', function (ev) { if (ev.target === editModal) closeEditModal(); });

    document.querySelectorAll('.btn-edit-final').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!editModal) return;
            document.getElementById('edit-id').value        = btn.getAttribute('data-id')       || '';
            document.getElementById('edit-client').value    = btn.getAttribute('data-client')   || '';
            document.getElementById('edit-jobname').value   = btn.getAttribute('data-jobname')  || '';
            document.getElementById('edit-plate').value     = btn.getAttribute('data-plate')    || '';
            document.getElementById('edit-daterecv').value  = btn.getAttribute('data-daterecv') || '';
            document.getElementById('edit-jobsize').value   = btn.getAttribute('data-jobsize')  || '';
            document.getElementById('edit-papersize').value = btn.getAttribute('data-papersize')|| '';
            document.getElementById('edit-papertype').value = btn.getAttribute('data-papertype')|| '';
            document.getElementById('edit-makeby').value    = btn.getAttribute('data-makeby')   || '';
            document.getElementById('edit-die').value       = btn.getAttribute('data-die')      || '';
            document.getElementById('edit-color').value     = btn.getAttribute('data-color')    || '';
            document.getElementById('edit-date').value      = btn.getAttribute('data-date')     || '';
            document.getElementById('edit-notes').value     = btn.getAttribute('data-notes')    || '';
            editModal.style.display = 'flex';
        });
    });

    if (editForm) {
        editForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var fd = new FormData(editForm);
            fetch('api/update-final-library.php', { method: 'POST', body: fd })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status !== 'success') { alert(data.message || 'Update failed.'); return; }
                    closeEditModal();
                    window.location.reload();
                })
                .catch(function () { alert('Update failed due to network/server issue.'); });
        });
    }
})();
</script>

<!-- Upload progress overlay -->
<div id="final-upload-progress" style="display:none;position:fixed;inset:0;z-index:14000;background:rgba(15,23,42,.6);flex-direction:column;align-items:center;justify-content:center;gap:1.2rem;">
    <div style="text-align:center;color:#fff;">
        <i class="fas fa-file-pdf" style="font-size:2.5rem;color:#f87171;display:block;margin-bottom:0.6rem;"></i>
        <div style="font-size:1rem;font-weight:800;margin-bottom:0.35rem;">Uploading PDF…</div>
        <div style="font-size:0.8rem;color:#94a3b8;">Generating thumbnail, please wait</div>
    </div>
    <div style="width:min(340px,85vw);background:rgba(255,255,255,.15);border-radius:999px;height:8px;overflow:hidden;">
        <div id="final-upload-bar" style="height:100%;width:0%;background:linear-gradient(90deg,#22c55e,#16a34a);border-radius:999px;transition:width 0.4s ease;"></div>
    </div>
</div>
<script>
/* animate the loading bar while upload is in progress */
(function () {
    var bar = document.getElementById('final-upload-bar');
    var timer = null;
    var pct = 0;
    window._startUploadBar = function () {
        pct = 0;
        if (bar) bar.style.width = '0%';
        timer = setInterval(function () {
            if (pct < 88) { pct += (pct < 50 ? 3 : 1); if (bar) bar.style.width = pct + '%'; }
        }, 180);
    };
    window._finishUploadBar = function () {
        clearInterval(timer);
        if (bar) { bar.style.width = '100%'; }
    };
    /* hook into showUploadProgress */
    var origShow = window.showUploadProgress;
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
