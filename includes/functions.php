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
 * Resolve the application base URL.
 * Falls back to detecting the app subfolder from DOCUMENT_ROOT when BASE_URL is blank.
 */
function appBaseUrl() {
    static $baseUrl = null;
    if ($baseUrl !== null) {
        return $baseUrl;
    }

    $configured = defined('BASE_URL') ? trim((string)BASE_URL) : '';
    if ($configured !== '') {
        $normalized = '/' . trim(str_replace('\\', '/', $configured), '/');
        $baseUrl = $normalized === '/' ? '' : $normalized;
        return $baseUrl;
    }

    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $appRoot = realpath(__DIR__ . '/..') ?: (__DIR__ . '/..');

    $documentRoot = str_replace('\\', '/', (string)(realpath($documentRoot) ?: $documentRoot));
    $appRoot = str_replace('\\', '/', (string)$appRoot);

    if ($documentRoot !== '' && $appRoot !== '') {
        $documentRoot = rtrim($documentRoot, '/');
        if (stripos($appRoot, $documentRoot) === 0) {
            $relative = trim(substr($appRoot, strlen($documentRoot)), '/');
            $baseUrl = $relative === '' ? '' : '/' . $relative;
            return $baseUrl;
        }
    }

    $baseUrl = '';
    return $baseUrl;
}

/**
 * Build a public URL for an app-relative asset or route.
 */
function appUrl($path = '') {
    $path = trim((string)$path);
    if ($path === '') {
        return appBaseUrl();
    }

    if (preg_match('#^(?:[a-z][a-z0-9+.-]*:)?//#i', $path) || strpos($path, 'data:') === 0) {
        return $path;
    }

    $normalizedPath = '/' . ltrim(str_replace('\\', '/', $path), '/');
    $baseUrl = appBaseUrl();

    if ($baseUrl !== '' && ($normalizedPath === $baseUrl || strpos($normalizedPath, $baseUrl . '/') === 0)) {
        return $normalizedPath;
    }

    return $baseUrl . $normalizedPath;
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
 * Ensure RBAC schema exists for group-based access control.
 */
function ensureRbacSchema() {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = getDB();

    $safeSchemaQuery = function($sql) use ($db) {
        try {
            $db->query($sql);
        } catch (Throwable $e) {
            $msg = strtolower((string)$e->getMessage());
            $knownSafe = [
                'duplicate column name',
                'duplicate key name',
                'already exists',
                'errno: 1060',
                'errno: 1061',
                'errno: 1050',
            ];
            foreach ($knownSafe as $needle) {
                if (strpos($msg, $needle) !== false) {
                    return;
                }
            }
            error_log('RBAC schema bootstrap warning: ' . $e->getMessage());
        }
    };

    // Users can be assigned to one group.
    $safeSchemaQuery("ALTER TABLE users ADD COLUMN group_id INT NULL AFTER role");
    $safeSchemaQuery("ALTER TABLE users ADD INDEX idx_users_group_id (group_id)");

    // Group master.
    $safeSchemaQuery("CREATE TABLE IF NOT EXISTS user_groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        description VARCHAR(255) NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Group to page permission mapping.
    $safeSchemaQuery("CREATE TABLE IF NOT EXISTS group_page_permissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        page_path VARCHAR(190) NOT NULL,
        can_view TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_group_page (group_id, page_path),
        KEY idx_perm_group (group_id),
        CONSTRAINT fk_gpp_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Granular action permissions for pages (add/edit/delete).
    $safeSchemaQuery("ALTER TABLE group_page_permissions ADD COLUMN can_add TINYINT(1) NOT NULL DEFAULT 0 AFTER can_view");
    $safeSchemaQuery("ALTER TABLE group_page_permissions ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_add");
    $safeSchemaQuery("ALTER TABLE group_page_permissions ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit");
}

/**
 * Ensure admin-managed paper master tables exist.
 */
function ensurePaperMasterSchema() {
    static $done = false;
    if ($done) return;
    $done = true;

    $db = getDB();
    $queries = [
        "CREATE TABLE IF NOT EXISTS master_paper_companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        "CREATE TABLE IF NOT EXISTS master_paper_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(150) NOT NULL UNIQUE,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];

    foreach ($queries as $sql) {
        try {
            $db->query($sql);
        } catch (Throwable $e) {
            error_log('Paper master schema bootstrap warning: ' . $e->getMessage());
        }
    }
}

/**
 * Return admin-managed paper company names.
 */
function getMasterPaperCompanies($activeOnly = true) {
    ensurePaperMasterSchema();
    $db = getDB();
    $sql = "SELECT name FROM master_paper_companies";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name ASC";

    $result = $db->query($sql);
    $items = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') $items[] = $name;
        }
    }
    return $items;
}

/**
 * Return admin-managed paper type names.
 */
function getMasterPaperTypes($activeOnly = true) {
    ensurePaperMasterSchema();
    $db = getDB();
    $sql = "SELECT name FROM master_paper_types";
    if ($activeOnly) {
        $sql .= " WHERE is_active = 1";
    }
    $sql .= " ORDER BY name ASC";

    $result = $db->query($sql);
    $items = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name !== '') $items[] = $name;
        }
    }
    return $items;
}

/**
 * List of assignable application pages for RBAC.
 */
function rbacPageCatalog() {
    $base = [
        '/modules/dashboard/index.php' => 'Dashboard',

        '/modules/estimate/index.php' => 'Calculator',
        '/modules/estimates/index.php' => 'Estimates',
        '/modules/quotations/index.php' => 'Quotations',
        '/modules/sales_order/index.php' => 'Sales Orders',

        '/modules/artwork/index.php' => 'Artwork Gallery',
        '/modules/planning/label/index.php' => 'Planning - Label Printing',
        '/modules/planning/slitting/index.php' => 'Planning - Jumbo Slitting',
        '/modules/planning/printing/index.php' => 'Planning - Printing',
        '/modules/planning/flatbed/index.php' => 'Planning - Flatbed',
        '/modules/planning/rotery/index.php' => 'Planning - Rotery Die',
        '/modules/planning/label-slitting/index.php' => 'Planning - Label Slitting',
        '/modules/planning/batch/index.php' => 'Planning - Batch Printing',
        '/modules/planning/packing/index.php' => 'Planning - Packaging',
        '/modules/planning/dispatch/index.php' => 'Planning - Dispatch',

        '/modules/operators/jumbo/index.php' => 'Machine Operator - Jumbo',
        '/modules/operators/pos/index.php' => 'Machine Operator - POS Roll',
        '/modules/operators/oneply/index.php' => 'Machine Operator - One Ply',
        '/modules/operators/printing/index.php' => 'Machine Operator - Flexo Printing',
        '/modules/operators/flatbed/index.php' => 'Machine Operator - Flat Bed',
        '/modules/operators/rotery/index.php' => 'Machine Operator - Rotery Die',
        '/modules/operators/label-slitting/index.php' => 'Machine Operator - Label Slitting',
        '/modules/operators/packing/index.php' => 'Machine Operator - Packing',

        '/modules/paper_stock/index.php' => 'Paper Stock',
        '/modules/audit/index.php' => 'Audit Hub',
        '/modules/scan/index.php' => 'Physical Stock Scan Terminal',
        '/modules/inventory/slitting/index.php' => 'Inventory - Slitting',
        '/modules/inventory/finished/index.php' => 'Inventory - Finished Good',
        '/modules/inventory/die/index.php' => 'Inventory - Die Tooling',

        '/modules/jobs/jumbo/index.php' => 'Job Card - Jumbo',
        '/modules/jobs/pos/index.php' => 'Job Card - POS Roll',
        '/modules/jobs/oneply/index.php' => 'Job Card - One Ply',
        '/modules/jobs/printing/index.php' => 'Job Card - Flexo Printing',
        '/modules/jobs/flatbed/index.php' => 'Job Card - Flat Bed',
        '/modules/jobs/rotery/index.php' => 'Job Card - Rotery Die',
        '/modules/jobs/label-slitting/index.php' => 'Job Card - Label Slitting',
        '/modules/jobs/packing/index.php' => 'Job Card - Packing Slip',
        '/modules/bom/index.php' => 'BOM Master',
        '/modules/live/index.php' => 'Live Floor',

        '/modules/purchase/index.php' => 'Purchase Order',

        '/modules/qc/index.php' => 'QC Report',
        '/modules/dispatch/index.php' => 'Dispatch',
        '/modules/billing/index.php' => 'Billing',

        '/modules/performance/index.php' => 'Performance',
        '/modules/reports/index.php' => 'Reports',
        '/modules/reports/jobs.php' => 'Job Reports',

        '/modules/approval/index.php' => 'Job Approval',

        '/modules/master/index.php' => 'Master Data',
        '/modules/stock-import/index.php' => 'Stock Import and Export',
        '/modules/users/index.php' => 'User Management',
        '/modules/users/groups.php' => 'User Groups & Permissions',
        '/modules/print/index.php' => 'Print Studio',
        '/modules/pricing/index.php' => 'Pricing Login',
        '/modules/settings/index.php' => 'Settings',
    ];

    // Auto-discover page-level functions under each cataloged module path
    // (e.g. add.php, edit.php, delete.php, export.php) for granular assignment.
    $catalog = $base;
    $actionMap = [
        'add.php' => 'Add',
        'edit.php' => 'Edit',
        'delete.php' => 'Delete',
        'view.php' => 'View',
        'export.php' => 'Export',
        'import.php' => 'Import',
        'batch_delete.php' => 'Batch Delete',
        'label.php' => 'Print Label',
    ];

    foreach ($base as $path => $label) {
        $dir = str_replace('\\', '/', dirname($path));
        if ($dir === '.' || $dir === '/') continue;

        $absDir = realpath(__DIR__ . '/..' . $dir);
        if ($absDir === false || !is_dir($absDir)) continue;

        $files = glob($absDir . DIRECTORY_SEPARATOR . '*.php');
        if (!is_array($files)) continue;

        foreach ($files as $absFile) {
            $file = basename((string)$absFile);
            if ($file === 'index.php') continue;
            if (strpos($file, '_') === 0) continue;
            if ($file === 'api.php') continue;

            $route = rbacNormalizePath($dir . '/' . $file);
            if (isset($catalog[$route])) continue;

            $actionLabel = $actionMap[$file] ?? ucwords(str_replace(['-', '_'], ' ', pathinfo($file, PATHINFO_FILENAME)));
            $catalog[$route] = $label . ' - ' . $actionLabel;
        }
    }

    ksort($catalog);
    return $catalog;
}

/**
 * Normalize an app path for RBAC checks.
 */
function rbacNormalizePath($path) {
    $path = (string)$path;
    if ($path === '') return '/';

    $onlyPath = parse_url($path, PHP_URL_PATH);
    if (!is_string($onlyPath) || $onlyPath === '') {
        $onlyPath = $path;
    }
    $onlyPath = str_replace('\\', '/', $onlyPath);

    $base = appBaseUrl();
    if ($base !== '' && strpos($onlyPath, $base . '/') === 0) {
        $onlyPath = substr($onlyPath, strlen($base));
    } elseif ($base !== '' && $onlyPath === $base) {
        $onlyPath = '/';
    }

    if ($onlyPath === '') $onlyPath = '/';
    if ($onlyPath[0] !== '/') $onlyPath = '/' . $onlyPath;
    return $onlyPath;
}

/**
 * Current executing page path, normalized for RBAC.
 */
function rbacCurrentPath() {
    $self = (string)($_SERVER['PHP_SELF'] ?? '/');
    return rbacNormalizePath($self);
}

/**
 * Return all page paths the current user can access by group.
 */
function rbacUserAllowedPaths($userId = null) {
    ensureRbacSchema();

    if ($userId === null) {
        $userId = (int)($_SESSION['user_id'] ?? 0);
    }
    if ($userId <= 0) return [];

    if (isAdmin()) {
        return array_keys(rbacPageCatalog());
    }

    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];

    $db = getDB();
    $q = $db->prepare("SELECT gpp.page_path
        FROM users u
        INNER JOIN user_groups ug ON ug.id = u.group_id AND ug.is_active = 1
        INNER JOIN group_page_permissions gpp ON gpp.group_id = ug.id AND gpp.can_view = 1
        WHERE u.id = ?");
    $q->bind_param('i', $userId);
    $q->execute();
    $rows = $q->get_result()->fetch_all(MYSQLI_ASSOC);

    $allowed = [];
    foreach ($rows as $r) {
        $p = rbacNormalizePath((string)($r['page_path'] ?? ''));
        if ($p !== '') $allowed[$p] = true;
    }
    $cache[$userId] = array_keys($allowed);
    return $cache[$userId];
}

/**
 * Check whether current logged-in user can access a path.
 */
function canAccessPath($path) {
    $path = rbacNormalizePath($path);

    // Public or always-allowed authenticated routes.
    if ($path === '/auth/logout.php') return true;
    if ($path === '/modules/dashboard/index.php') return true;

    if (isAdmin()) return true;

    // Jobs API is used by Live Floor / Job Card pages for data fetch.
    if ($path === '/modules/jobs/api.php') {
        $allowed = rbacUserAllowedPaths();
        if (in_array('/modules/live/index.php', $allowed, true)) return true;
        foreach ($allowed as $p) {
            if (strpos($p, '/modules/jobs/') === 0 || strpos($p, '/modules/operators/') === 0) {
                return true;
            }
        }
        return false;
    }

    $catalog = rbacPageCatalog();
    if (!isset($catalog[$path])) {
        // If page is not cataloged, deny by default for non-admin.
        return false;
    }

    $allowed = rbacUserAllowedPaths();
    return in_array($path, $allowed, true);
}

/**
 * Check permission for the current page.
 */
function canAccessCurrentPage() {
    $path = rbacCurrentPath();
    return canAccessPath($path);
}

/**
 * Check granular action permission for a page path.
 * Actions: 'add', 'edit', 'delete'. Admins always get true.
 */
function hasPageAction($path, $action) {
    if (isAdmin()) return true;

    $path = rbacNormalizePath($path);
    $action = strtolower(trim((string)$action));
    $column = 'can_' . $action;
    if (!in_array($column, ['can_add', 'can_edit', 'can_delete'], true)) return false;

    static $cache = [];
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) return false;
    $cacheKey = $userId . ':' . $path . ':' . $column;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $db = getDB();
    $q = $db->prepare("SELECT gpp.{$column}
        FROM users u
        INNER JOIN user_groups ug ON ug.id = u.group_id AND ug.is_active = 1
        INNER JOIN group_page_permissions gpp ON gpp.group_id = ug.id AND gpp.page_path = ?
        WHERE u.id = ? LIMIT 1");
    $q->bind_param('si', $path, $userId);
    $q->execute();
    $row = $q->get_result()->fetch_assoc();
    $cache[$cacheKey] = $row ? (bool)(int)$row[$column] : false;
    return $cache[$cacheKey];
}

/**
 * Check granular action permission for the current page.
 */
function currentPageAction($action) {
    return hasPageAction(rbacCurrentPath(), $action);
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
