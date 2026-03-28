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
    $displayStatus = (string)$status;
    if ($displayStatus === 'Slitting') {
        $displayStatus = 'Preparing Slitting';
    }

    $map = [
        // Paper stock
        'Main'           => 'main',
        'Stock'          => 'stock',
        'Slitting'       => 'slitting',
        'Preparing Slitting' => 'slitting',
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
    $cls = $map[$status] ?? ($map[$displayStatus] ?? 'draft');
    return '<span class="badge badge-' . $cls . '">' . e($displayStatus) . '</span>';
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
function getAppSettings($noCache = false) {
    static $cache = null;
    if ($noCache) $cache = null;
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
 * Get the product-type image URL for a given paper type from image library.
 * Returns empty string if no match found.
 */
function getProductTypeImage($paperType) {
    $paperType = strtolower(trim((string)$paperType));
    if ($paperType === '') return '';
    $settings = getAppSettings();
    foreach (($settings['image_library'] ?? []) as $img) {
        if (($img['category'] ?? '') !== 'product-type') continue;
        $imgType = strtolower(trim((string)($img['paper_type'] ?? '')));
        if ($imgType !== '' && $imgType === $paperType) {
            return $img['path'] ?? '';
        }
    }
    return '';
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
    $ok = $json !== false ? (@file_put_contents($path, $json) !== false) : false;
    if ($ok) {
        // Clear the cache so next getAppSettings() call reads fresh data
        getAppSettings(true);
    }
    return $ok;
}

/**
 * Default ID prefix settings and counters for all modules.
 */
function prefixSettingsDefaults() {
    return [
        'id_generation' => [
            'year_format' => 'YYYY',
            'separator' => '/',
            'padding' => 4,
            'global_job_counter' => 0,
            'modules' => [
                'roll' => ['prefix' => 'SLC', 'counter' => 0],
                'job' => ['prefix' => 'JOB', 'counter' => 0],
                'invoice' => ['prefix' => 'INV', 'counter' => 0],
                'estimate' => ['prefix' => 'EST', 'counter' => 0],
                'quotation' => ['prefix' => 'QTN', 'counter' => 0],
                'batch' => ['prefix' => 'BAT', 'counter' => 0],
                'sales_order' => ['prefix' => 'SO', 'counter' => 0],
                'planning' => ['prefix' => 'PLN', 'counter' => 0],
                'jumbo_job' => ['prefix' => 'JMB', 'counter' => 0],
                'printing_job' => ['prefix' => 'FLX', 'counter' => 0],
            ],
        ],
    ];
}

/**
 * Merge current app settings with prefix defaults.
 */
function getPrefixSettings() {
    $settings = getAppSettings();
    $defaults = prefixSettingsDefaults();

    if (!isset($settings['id_generation']) || !is_array($settings['id_generation'])) {
        $settings['id_generation'] = $defaults['id_generation'];
    }

    $idg = $settings['id_generation'];
    if (!isset($idg['year_format']) || !in_array($idg['year_format'], ['YY', 'YYYY'], true)) {
        $idg['year_format'] = $defaults['id_generation']['year_format'];
    }
    if (!isset($idg['separator']) || !is_string($idg['separator']) || $idg['separator'] === '') {
        $idg['separator'] = $defaults['id_generation']['separator'];
    }
    if (!isset($idg['padding']) || !is_numeric($idg['padding']) || (int)$idg['padding'] < 1) {
        $idg['padding'] = $defaults['id_generation']['padding'];
    } else {
        $idg['padding'] = (int)$idg['padding'];
    }

    if (!isset($idg['global_job_counter']) || !is_numeric($idg['global_job_counter'])) {
        $idg['global_job_counter'] = (int)($defaults['id_generation']['global_job_counter'] ?? 0);
    } else {
        $idg['global_job_counter'] = max(0, (int)$idg['global_job_counter']);
    }

    if (!isset($idg['modules']) || !is_array($idg['modules'])) {
        $idg['modules'] = [];
    }

    foreach ($defaults['id_generation']['modules'] as $type => $moduleDefaults) {
        if (!isset($idg['modules'][$type]) || !is_array($idg['modules'][$type])) {
            $idg['modules'][$type] = $moduleDefaults;
            continue;
        }

        if (!isset($idg['modules'][$type]['prefix']) || trim((string)$idg['modules'][$type]['prefix']) === '') {
            $idg['modules'][$type]['prefix'] = $moduleDefaults['prefix'];
        } else {
            $idg['modules'][$type]['prefix'] = trim((string)$idg['modules'][$type]['prefix']);
        }

        if (!isset($idg['modules'][$type]['counter']) || !is_numeric($idg['modules'][$type]['counter'])) {
            $idg['modules'][$type]['counter'] = $moduleDefaults['counter'];
        } else {
            $idg['modules'][$type]['counter'] = max(0, (int)$idg['modules'][$type]['counter']);
        }
    }

    return $idg;
}

/**
 * Build year token as configured in master settings.
 */
function buildIdYearToken($yearFormat) {
    return $yearFormat === 'YYYY' ? date('Y') : date('y');
}

/**
 * Build an ID in PREFIX/YY/001 format (separator and year format are configurable).
 */
function buildFormattedId($prefix, $yearToken, $sequence, $separator, $padding) {
    $seq = str_pad((string)$sequence, max(1, (int)$padding), '0', STR_PAD_LEFT);
    return strtoupper(trim((string)$prefix)) . $separator . $yearToken . $separator . $seq;
}

/**
 * Preview current ID format for a module without incrementing counter.
 */
function getIdPreview($type) {
    $type = trim((string)$type);
    $settings = getAppSettings();
    $idg = getPrefixSettings();

    if (!isset($idg['modules'][$type])) {
        return null;
    }

    $module = $idg['modules'][$type];
    $next = max(0, (int)$module['counter']) + 1;
    return buildFormattedId(
        $module['prefix'],
        buildIdYearToken($idg['year_format']),
        $next,
        $idg['separator'],
        $idg['padding']
    );
}

/**
 * Centralized reusable API for all module IDs.
 * Increments only the requested module counter and persists settings.
 */
function getNextId($type) {
    $type = trim((string)$type);
    $settings = getAppSettings();
    $idg = getPrefixSettings();

    if (!isset($idg['modules'][$type])) {
        return null;
    }

    $module = $idg['modules'][$type];
    $next = max(0, (int)$module['counter']) + 1;
    $newId = buildFormattedId(
        $module['prefix'],
        buildIdYearToken($idg['year_format']),
        $next,
        $idg['separator'],
        $idg['padding']
    );

    $settings['id_generation'] = $idg;
    $settings['id_generation']['modules'][$type]['counter'] = $next;
    saveAppSettings($settings);

    return $newId;
}

/**
 * Preview the next ID for a module without incrementing the stored counter.
 */
function previewNextId($type) {
    $type = trim((string)$type);
    $idg = getPrefixSettings();

    if (!isset($idg['modules'][$type])) {
        return null;
    }

    $module = $idg['modules'][$type];
    $next = max(0, (int)($module['counter'] ?? 0)) + 1;

    return buildFormattedId(
        $module['prefix'],
        buildIdYearToken($idg['year_format']),
        $next,
        $idg['separator'],
        $idg['padding']
    );
}

/**
 * Generate a job ID using the GLOBAL shared counter but department-specific prefix.
 * All departments share the same incrementing counter; only the prefix differs.
 * E.g. JMB/2026/1020 (jumbo_job), FLX/2026/1021 (printing_job)
 *
 * @param string $department  Module key: 'jumbo_job', 'printing_job', etc.
 * @return string|null  The formatted job ID, or null if department not found.
 */
function getNextJobId($department) {
    $department = trim((string)$department);
    $settings = getAppSettings();
    $idg = getPrefixSettings();

    if (!isset($idg['modules'][$department])) {
        return null;
    }

    $prefix = $idg['modules'][$department]['prefix'];
    $globalCounter = max(0, (int)($idg['global_job_counter'] ?? 0)) + 1;

    $newId = buildFormattedId(
        $prefix,
        buildIdYearToken($idg['year_format']),
        $globalCounter,
        $idg['separator'],
        $idg['padding']
    );

    // Persist the incremented global counter
    $settings['id_generation'] = $idg;
    $settings['id_generation']['global_job_counter'] = $globalCounter;
    saveAppSettings($settings);

    return $newId;
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
