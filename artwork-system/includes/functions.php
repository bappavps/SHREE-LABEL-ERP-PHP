<?php

function appSessionStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'cookie_samesite' => 'Lax',
        ]);
    }
}

function generateToken(int $length = 48): string {
    return bin2hex(random_bytes((int) ($length / 2)));
}

function sanitize(string $data): string {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

if (!function_exists('redirect')) {
    function redirect(string $url): void {
        header('Location: ' . $url);
        exit;
    }
}

function jsonResponse(string $status, string $message, array $data = []): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => $status, 'message' => $message, 'data' => $data]);
    exit;
}

function ensureDefaultAuthUsers(PDO $db): void {
    $defaults = [
        [
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'admin123',
            'role' => 'admin',
        ],
        [
            'name' => 'Designer One',
            'email' => 'designer@example.com',
            'password' => 'designer123',
            'role' => 'designer',
        ],
    ];

    foreach ($defaults as $user) {
        $stmt = $db->prepare('SELECT id FROM artwork_users WHERE email = ? LIMIT 1');
        $stmt->execute([$user['email']]);
        $existingId = (int) $stmt->fetchColumn();
        $hash = password_hash($user['password'], PASSWORD_DEFAULT);

        if ($existingId > 0) {
            $update = $db->prepare('UPDATE artwork_users SET name = ?, password = ?, role = ? WHERE id = ?');
            $update->execute([$user['name'], $hash, $user['role'], $existingId]);
            continue;
        }

        $insert = $db->prepare('INSERT INTO artwork_users (name, email, password, role) VALUES (?, ?, ?, ?)');
        $insert->execute([$user['name'], $user['email'], $hash, $user['role']]);
    }
}

function setAuthSession(array $user): void {
    appSessionStart();
    $_SESSION['artwork_user_id'] = (int) $user['id'];
    $_SESSION['artwork_user_name'] = (string) $user['name'];
    $_SESSION['artwork_user_role'] = (string) $user['role'];
}

function clearAuthSession(): void {
    appSessionStart();
    unset(
        $_SESSION['artwork_user_id'],
        $_SESSION['artwork_user_name'],
        $_SESSION['artwork_user_role']
    );
}

function getAuthUser(): ?array {
    appSessionStart();
    if (empty($_SESSION['artwork_user_id']) || empty($_SESSION['artwork_user_name'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['artwork_user_id'],
        'name' => (string) $_SESSION['artwork_user_name'],
        'role' => (string) ($_SESSION['artwork_user_role'] ?? 'designer'),
    ];
}

function requireAuthUser(array $allowedRoles = []): array {
    $user = getAuthUser();
    if (!$user) {
        redirect(ERP_ARTWORK_BRIDGE_URL);
    }

    if (!empty($allowedRoles) && !in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        exit('Forbidden');
    }

    return $user;
}

function authenticateUser(PDO $db, string $email, string $password): ?array {
    ensureDefaultAuthUsers($db);

    $stmt = $db->prepare('SELECT id, name, email, password, role FROM artwork_users WHERE email = ? LIMIT 1');
    $stmt->execute([trim(strtolower($email))]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    if (!password_verify($password, (string) $user['password'])) {
        return null;
    }

    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'role' => (string) $user['role'],
    ];
}

function getCurrentDesigner(PDO $db): array {
    ensureDefaultAuthUsers($db);
    return requireAuthUser(['admin', 'designer']);
}

function getFileIcon(string $type): string {
    $type = strtolower($type);
    if (in_array($type, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        return 'fa-file-image';
    }
    if ($type === 'pdf') {
        return 'fa-file-pdf';
    }
    if (in_array($type, ['ai', 'cdr', 'eps'], true)) {
        return 'fa-file-lines';
    }
    return 'fa-file';
}

function formatSize(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = $bytes;
    $index = 0;
    while ($value > 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return round($value, 2) . ' ' . $units[$index];
}

function normalizeExtension(string $filename): string {
    return strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
}

function validateUploadedFile(array $file, array $allowedExtensions): array {
    if (empty($file['name']) || !isset($file['tmp_name'])) {
        return ['valid' => false, 'message' => 'No file uploaded.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'message' => 'Invalid upload source.'];
    }
    if (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > MAX_UPLOAD_BYTES) {
        return ['valid' => false, 'message' => 'File size is invalid or exceeds 25MB.'];
    }

    $ext = normalizeExtension($file['name']);
    if (!in_array($ext, $allowedExtensions, true)) {
        return ['valid' => false, 'message' => 'Unsupported file format.'];
    }

    return ['valid' => true, 'ext' => $ext];
}

function parseIniSizeToBytes(string $value): int {
    $raw = trim($value);
    if ($raw === '' || $raw === '-1') {
        return -1;
    }

    $unit = strtolower(substr($raw, -1));
    $num = (float)$raw;

    switch ($unit) {
        case 'g':
            $num *= 1024;
            // no break
        case 'm':
            $num *= 1024;
            // no break
        case 'k':
            $num *= 1024;
            break;
    }

    return (int)round($num);
}

function getEffectiveFinalUploadLimitBytes(): int {
    $appLimit = (int)(defined('MAX_FINAL_UPLOAD_BYTES') ? MAX_FINAL_UPLOAD_BYTES : MAX_UPLOAD_BYTES);
    $phpUpload = parseIniSizeToBytes((string)ini_get('upload_max_filesize'));
    $phpPost = parseIniSizeToBytes((string)ini_get('post_max_size'));

    $limits = [$appLimit];
    if ($phpUpload > 0) {
        $limits[] = $phpUpload;
    }
    if ($phpPost > 0) {
        $limits[] = $phpPost;
    }

    return (int)min($limits);
}

function validateFinalPdfUpload(array $file): array {
    if (empty($file['name']) || !isset($file['tmp_name'])) {
        return ['valid' => false, 'message' => 'No file uploaded.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'message' => 'Invalid upload source.'];
    }

    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['valid' => false, 'message' => 'Uploaded file is empty.'];
    }

    $effectiveLimit = getEffectiveFinalUploadLimitBytes();
    if ($effectiveLimit > 0 && $size > $effectiveLimit) {
        return ['valid' => false, 'message' => 'File exceeds allowed upload size for this server profile.'];
    }

    $ext = normalizeExtension((string)$file['name']);
    if (!in_array($ext, ALLOWED_FINAL_EXTENSIONS, true)) {
        return ['valid' => false, 'message' => 'Only PDF files are allowed.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    if ($mime !== 'application/pdf' && $mime !== 'application/x-pdf') {
        return ['valid' => false, 'message' => 'Invalid PDF MIME type.'];
    }

    return [
        'valid' => true,
        'ext' => $ext,
        'mime' => $mime,
        'size' => $size,
    ];
}

function buildUploadName(string $token, int $version, string $ext): string {
    return sprintf('%s_v%s_%s.%s', $token, $version, date('YmdHis'), $ext);
}

function statusLabelClass(string $status): string {
    if ($status === 'approved') {
        return 'status-approved';
    }
    if ($status === 'changes') {
        return 'status-changes';
    }
    return 'status-pending';
}

function statusLabelText(string $status): string {
    if ($status === 'approved') {
        return 'Approved';
    }
    if ($status === 'changes') {
        return 'Change Request';
    }
    return 'Pending';
}

