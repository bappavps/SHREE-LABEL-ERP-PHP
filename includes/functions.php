<?php
// ============================================================
// ERP System — Helper Functions
// ============================================================

/**
 * Safely escape HTML output.
 */
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * HTTP redirect and exit.
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Store a flash message in session.
 */
function setFlash($type, $message) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Retrieve and clear the flash message.
 */
function getFlash() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Generate a CSRF token (stored in session).
 */
function generateCSRF() {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify a submitted CSRF token.
 */
function verifyCSRF($token) {
    if (session_status() === PHP_SESSION_NONE) session_start();
    return isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string)$token);
}

/**
 * Generate a document number like EST-2026-0001.
 * Uses the count of existing records with the same prefix+year.
 */
function generateDocNo($prefix, $table, $column) {
    $db   = getDB();
    $year = date('Y');
    $like = $prefix . '-' . $year . '-%';
    $stmt = $db->prepare("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE `{$column}` LIKE ?");
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $row  = $stmt->get_result()->fetch_assoc();
    $next = (int)($row['cnt'] ?? 0) + 1;
    return $prefix . '-' . $year . '-' . str_pad($next, 4, '0', STR_PAD_LEFT);
}

/**
 * Check if current session user is admin.
 */
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

/**
 * Check if current session user has one of the given roles.
 */
function hasRole(...$roles) {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}

/**
 * Format a date string to "12 Mar 2026" or "-".
 */
function formatDate($date) {
    if (!$date || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') return '-';
    return date('d M Y', strtotime($date));
}

/**
 * Calculate SQM from width (mm) and length (m).
 */
function calcSQM($width_mm, $length_mtr) {
    return ($width_mm / 1000) * $length_mtr;
}

/**
 * Return an HTML badge for a status string.
 */
function statusBadge($status) {
    $map = [
        // Paper stock
        'Main'           => 'main',
        'Stock'          => 'stock',
        'Slitting'       => 'slitting',
        'Job Assign'     => 'job-assign',
        'In Production'  => 'in-production',
        'Available'      => 'available',
        'Assigned'       => 'assigned',
        'Consumed'       => 'consumed',
        // Estimates
        'Draft'          => 'draft',
        'Sent'           => 'sent',
        'Approved'       => 'approved',
        'Rejected'       => 'rejected',
        'Converted'      => 'converted',
        // Sales orders
        'Pending'        => 'pending',
        'In Production'  => 'in-production',
        'Completed'      => 'completed',
        'Dispatched'     => 'dispatched',
        'Cancelled'      => 'cancelled',
        // Planning / jobs
        'Queued'         => 'queued',
        'In Progress'    => 'in-progress',
        'On Hold'        => 'on-hold',
        'Running'        => 'in-progress',
        'QC Passed'      => 'completed',
        'QC Failed'      => 'rejected',
    ];
    $cls = $map[$status] ?? 'draft';
    return '<span class="badge badge-' . $cls . '">' . e($status) . '</span>';
}

/**
 * Build a simple pagination bar.
 * Returns HTML string.
 */
function paginationBar($total, $per_page, $current_page, $url_pattern) {
    if ($total <= $per_page) return '';
    $pages = (int)ceil($total / $per_page);
    $html  = '<div class="pagination">';
    for ($i = 1; $i <= $pages; $i++) {
        $active = ($i === $current_page) ? ' active' : '';
        $href   = str_replace('{page}', $i, $url_pattern);
        $html  .= '<a href="' . e($href) . '" class="page-item' . $active . '">' . $i . '</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * Default application settings.
 */
function appSettingsDefaults() {
    return [
        'company_name' => APP_NAME,
        'company_tagline' => 'ERP Master System',
        'company_email' => '',
        'company_mobile' => '',
        'company_phone' => '',
        'company_currency' => 'INR',
        'company_address' => '',
        'company_gst' => '',
        'logo_path' => '',
        'flag_emoji' => '🇮🇳',
        'animated_flag_path' => '',
        'animated_flag_url' => '',
        'login_background_image' => '',
        'theme_mode' => 'light',
        'sidebar_button_color' => '#22c55e',
        'sidebar_hover_color' => 'rgba(255,255,255,.09)',
        'sidebar_active_bg' => 'rgba(34,197,94,.12)',
        'sidebar_active_text' => '#bbf7d0',
        'image_library' => [],
    ];
}

/**
 * Path to persistent app settings JSON.
 */
function getAppSettingsPath() {
    return __DIR__ . '/../data/app_settings.json';
}

/**
 * Read app settings from JSON and merge with defaults.
 */
function getAppSettings() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $defaults = appSettingsDefaults();
    $path = getAppSettingsPath();
    if (!is_file($path)) {
        $cache = $defaults;
        return $cache;
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        $cache = $defaults;
        return $cache;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cache = $defaults;
        return $cache;
    }

    $cache = array_merge($defaults, $decoded);
    if (trim((string)$cache['company_mobile']) === '' && trim((string)$cache['company_phone']) !== '') {
        $cache['company_mobile'] = (string)$cache['company_phone'];
    }
    if (!is_array($cache['image_library'])) {
        $cache['image_library'] = [];
    } else {
        foreach ($cache['image_library'] as $i => $img) {
            if (!is_array($img)) {
                $cache['image_library'][$i] = ['path' => '', 'name' => '', 'uploaded_at' => '', 'category' => 'misc'];
                continue;
            }
            if (empty($cache['image_library'][$i]['category'])) {
                $cache['image_library'][$i]['category'] = 'misc';
            }
        }
    }
    return $cache;
}

/**
 * Persist app settings to JSON.
 */
function saveAppSettings(array $settings) {
    $path = getAppSettingsPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0777, true);
    }
    $data = array_merge(appSettingsDefaults(), $settings);
    if (!isset($data['image_library']) || !is_array($data['image_library'])) {
        $data['image_library'] = [];
    }
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return $json !== false ? (@file_put_contents($path, $json) !== false) : false;
}

/**
 * Build ERP display name as "Company Name ERP".
 */
function getErpDisplayName($companyName = '') {
    $base = trim((string)$companyName);
    if ($base === '') {
        $app = trim((string)APP_NAME);
        if (preg_match('/\s+ERP$/i', $app)) {
            return $app;
        }
        return trim($app . ' ERP');
    }
    if (preg_match('/\s+ERP$/i', $base)) {
        return $base;
    }
    return trim($base . ' ERP');
}
