<?php
$pageTitle = 'New Project';
$activePage = 'projects';
require_once __DIR__ . '/../includes/header.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = sanitize($_POST['title']);
    $job_name = sanitize($_POST['job_name']);
    $job_size = sanitize($_POST['job_size'] ?? '');
    $job_color = sanitize($_POST['job_color'] ?? '');
    $job_remark = sanitize($_POST['job_remark'] ?? '');
    $client_name = sanitize($_POST['client_name']);
    
    if (empty($title) || empty($client_name) || empty($_FILES['artwork']['name'])) {
        $error = 'Please fill in all required fields and select a file.';
    } else {
        $db = Db::getInstance();
        $token = generateToken();
        
        try {
            $db->beginTransaction();
            
            // Insert Project
            $stmt = $db->prepare("INSERT INTO artwork_projects (designer_id, title, job_name, job_size, job_color, job_remark, client_name, token) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([(int)($designer['id'] ?? 0), $title, $job_name, $job_size, $job_color, $job_remark, $client_name, $token]);
            $projectId = $db->lastInsertId();
            
            // Handle File Upload
            $file = $_FILES['artwork'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'ai', 'cdr'];
            
            if (!in_array($ext, $allowed)) {
                throw new Exception('Invalid file type. Allowed: PDF, JPG, PNG, AI, CDR');
            }
            
            $filename = $token . '_' . time() . '.' . $ext;
            $uploadPath = UPLOAD_DIR . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
                // Insert File
                $stmt = $db->prepare("INSERT INTO artwork_files (project_id, filename, original_name, file_type, version) VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$projectId, $filename, $file['name'], $ext]);
                
                // Log Activity
                $stmt = $db->prepare("INSERT INTO artwork_activity_log (project_id, action) VALUES (?, ?)");
                $stmt->execute([$projectId, "Project created and initial artwork uploaded."]);
                
                $db->commit();
                redirect('projects.php');
            } else {
                throw new Exception('Failed to upload file. Check folder permissions.');
            }
        } catch (Exception $e) {
            $db->rollBack();
            $error = $e->getMessage();
        }
    }
}
?>

<div class="content-wrapper">
    <div style="max-width: 800px; margin: 0 auto;">
        <div class="section-header" style="margin-bottom: 2rem; text-align: center;">
            <h2 style="font-weight: 800; letter-spacing: -0.02em;">Create New Project</h2>
            <p style="color: var(--text-muted);">Launch a new proofing project and invite your clients.</p>
        </div>

        <div class="glass-card" style="padding: 3rem;">
            <?php if ($error): ?>
                <div class="error-msg" style="background: #fee2e2; color: #ef4444; padding: 1.25rem; border-radius: 12px; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-msg" style="background: #dcfce7; color: #10b981; padding: 1.25rem; border-radius: 12px; margin-bottom: 2rem; display: flex; align-items: center; gap: 0.75rem;">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 700; font-size: 0.9rem;">Project Title *</label>
                        <input type="text" name="title" required placeholder="e.g. Summer Campaign Banner" style="width: 100%; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; transition: border-color 0.3s;" onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 700; font-size: 0.9rem;">Job Name / ID</label>
                        <input type="text" name="job_name" placeholder="e.g. JOB-2023-001" style="width: 100%; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; transition: border-color 0.3s;" onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 2rem;">
                    <label style="display: block; margin-bottom: 0.75rem; font-weight: 700; font-size: 0.9rem;">Client Name *</label>
                    <input type="text" name="client_name" required placeholder="e.g. Acme Corp" style="width: 100%; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; transition: border-color 0.3s;" onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='#e2e8f0'">
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 2rem; margin-bottom: 2rem;">
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 700; font-size: 0.9rem;">Job Size</label>
                        <input type="text" name="job_size" placeholder="e.g. 87mm x 62mm" style="width: 100%; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; transition: border-color 0.3s;" onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                    <div class="form-group">
                        <label style="display: block; margin-bottom: 0.75rem; font-weight: 700; font-size: 0.9rem;">Job Color</label>
                        <input type="text" name="job_color" placeholder="e.g. CMYK / 4 Color" style="width: 100%; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; transition: border-color 0.3s;" onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='#e2e8f0'">
                    </div>
                </div>

                <div class="form-group" style="margin-bottom: 2rem;">
                    <label style="display: block; margin-bottom: 0.75rem; font-weight: 700; font-size: 0.9rem;">Remark</label>
                    <textarea name="job_remark" rows="4" placeholder="Any special instructions for client approval..." style="width: 100%; padding: 1rem; border-radius: 12px; border: 1px solid #e2e8f0; outline: none; resize: vertical; font-family: inherit; transition: border-color 0.3s;" onfocus="this.style.borderColor='var(--primary-color)'" onblur="this.style.borderColor='#e2e8f0'"></textarea>
                </div>

                <div class="form-group" style="margin-bottom: 3rem;">
                    <label style="display: block; margin-bottom: 0.75rem; font-weight: 700; font-size: 0.9rem;">Upload Initial Artwork *</label>
                    <div style="border: 2px dashed #e2e8f0; padding: 3rem; border-radius: 20px; text-align: center; cursor: pointer; background: #f8fafc; transition: all 0.3s;" onmouseover="this.style.borderColor='var(--primary-color)'; this.style.background='#f0f4ff'" onmouseout="this.style.borderColor='#e2e8f0'; this.style.background='#f8fafc'" onclick="document.getElementById('artwork-input').click()">
                        <div style="width: 64px; height: 64px; background: white; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 1.5rem; box-shadow: var(--shadow-sm); color: var(--primary-color);">
                            <i class="fas fa-cloud-upload-alt" style="font-size: 1.75rem;"></i>
                        </div>
                        <h4 id="file-label" style="margin-bottom: 0.5rem; font-weight: 700;">Drag and drop artwork here</h4>
                        <p style="font-size: 0.85rem; color: var(--text-muted);">Supported: PDF, JPG, PNG, AI, CDR (Max 20MB)</p>
                        <input type="file" id="artwork-input" name="artwork" required style="display: none;" onchange="document.getElementById('file-label').innerText = 'Selected: ' + this.files[0].name">
                    </div>
                </div>

                <div style="display: flex; gap: 1.5rem;">
                    <button type="submit" class="btn-primary" style="flex: 1; justify-content: center; padding: 1rem;">
                        <i class="fas fa-rocket"></i> Launch Project
                    </button>
                    <button type="button" onclick="history.back()" style="padding: 1rem 2rem; border-radius: 12px; border: 1px solid #e2e8f0; background: white; cursor: pointer; font-weight: 600; color: var(--text-muted); transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9'; this.style.color='var(--text-main)'" onmouseout="this.style.background='white'; this.style.color='var(--text-muted)'">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
