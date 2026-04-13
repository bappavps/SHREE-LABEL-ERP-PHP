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

require_once __DIR__ . '/status_module.php';

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

function erp_parse_multi_value_list($value) {
    if (is_array($value)) {
        $parts = $value;
    } else {
        $text = trim((string)$value);
        if ($text === '') {
            return [];
        }
        $parts = preg_split('/\s*,\s*|\r\n|\r|\n/', $text);
    }

    $items = [];
    foreach ($parts as $part) {
        $text = trim((string)$part);
        if ($text === '') {
            continue;
        }
        $items[] = $text;
    }
    return $items;
}

function erp_default_department_selection() {
    return ['Packaging', 'Dispatch'];
}

function erp_configured_departments(): array {
    return [
        'Jumbo Slitting',
        'Printing',
        'Die-Cutting',
        'BarCode',
        'PaperRoll',
        'Label Slitting',
        'Batch Printing',
        'Packaging',
        'Dispatch',
    ];
}

function erp_normalize_department_key(string $value): string {
    return strtolower((string)preg_replace('/[^a-z0-9]+/', '', trim($value)));
}

function erp_department_alias_map(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $definitions = [
        'Jumbo Slitting' => ['jumbo', 'jumbo slitting', 'jumbo_slitting', 'jumbo-slitting'],
        'Printing' => ['printing', 'label printing', 'label-printing', 'label_printing', 'printing label', 'flexo', 'flexo printing', 'flexo_printing', 'flexo-printing', 'markandy', 'mark andy', '8 color printing', '8.color printing'],
        'Die-Cutting' => ['die cutting', 'die-cutting', 'die_cutting', 'flatbed', 'flat bed'],
        'BarCode' => ['barcode', 'bar code', 'bar-code', 'bar_code'],
        'PaperRoll' => ['paperroll', 'paper roll', 'paper-roll', 'paper_roll'],
        'Label Slitting' => ['label slitting', 'label-slitting', 'label_slitting', 'slitting'],
        'Batch Printing' => ['batch printing', 'batch-printing', 'batch_printing', 'one ply', 'oneply', 'one_ply', 'one-ply', 'two ply', 'twoply', 'two_ply', 'two-ply', 'pos', 'pos roll', 'pos_roll', 'pos-roll', 'posroll', 'rotery', 'rotery die', 'rotary die', 'rotary'],
        'Packaging' => ['packing', 'packaging', 'packing slip'],
        'Dispatch' => ['dispatch', 'despatch'],
    ];

    $map = [];
    foreach ($definitions as $label => $aliases) {
        $allAliases = array_merge([$label], $aliases);
        foreach ($allAliases as $alias) {
            $key = erp_normalize_department_key((string)$alias);
            if ($key !== '') {
                $map[$key] = $label;
            }
        }
    }

    return $map;
}

function erp_get_job_card_departments(?array $defaults = null): array {
    static $cache = [];

    if ($defaults === null) {
        $defaults = [];
    }

    $cacheKey = implode('|', $defaults);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $departments = [];
    foreach ($defaults as $default) {
        $label = erp_canonical_department_label((string)$default);
        if ($label !== '') {
            $departments[$label] = true;
        }
    }

    foreach (erp_configured_departments() as $department) {
        $label = erp_canonical_department_label($department);
        if ($label !== '') {
            $departments[$label] = true;
        }
    }

    $ordered = array_keys($departments);
    natcasesort($ordered);

    $cache[$cacheKey] = array_values($ordered);
    return $cache[$cacheKey];
}

function erp_canonical_department_label(string $value, ?array $choices = null): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $aliasMap = erp_department_alias_map();
    $key = erp_normalize_department_key($value);
    $label = $key !== '' && isset($aliasMap[$key]) ? $aliasMap[$key] : $value;

    if ($choices === null) {
        return $label;
    }

    foreach ($choices as $choice) {
        $choiceLabel = trim((string)$choice);
        if ($choiceLabel === '') {
            continue;
        }
        if (strcasecmp($choiceLabel, $label) === 0 || erp_normalize_department_key($choiceLabel) === $key) {
            return $choiceLabel;
        }
    }

    return $label;
}

function erp_get_machine_departments(mysqli $db = null, ?array $defaults = null) {
    static $cache = [];

    if ($db === null) {
        $db = getDB();
    }

    if ($defaults === null) {
        $defaults = erp_configured_departments();
    }

    $cacheKey = spl_object_hash($db) . '|' . implode(',', $defaults);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $departments = [];
    foreach ($defaults as $default) {
        $label = erp_canonical_department_label((string)$default);
        if ($label !== '') {
            $departments[$label] = true;
        }
    }

    foreach (erp_get_job_card_departments() as $jobDepartment) {
        $label = erp_canonical_department_label($jobDepartment);
        if ($label !== '') {
            $departments[$label] = true;
        }
    }

    $labels = array_keys($departments);
    natcasesort($labels);

    $ordered = [];
    foreach ($defaults as $default) {
        foreach ($labels as $idx => $label) {
            if (strcasecmp($label, (string)$default) === 0) {
                $ordered[] = $label;
                unset($labels[$idx]);
            }
        }
    }
    foreach ($labels as $label) {
        $ordered[] = $label;
    }

    $cache[$cacheKey] = array_values(array_unique($ordered));
    return $cache[$cacheKey];
}

function erp_normalize_department_selection($value, ?array $choices = null, ?array $defaults = null) {
    if ($defaults === null) {
        $defaults = erp_default_department_selection();
    }

    if ($choices === null) {
        $choices = $defaults;
    }

    $choiceMap = [];
    foreach ($choices as $choice) {
        $label = trim((string)$choice);
        if ($label === '') {
            continue;
        }
        $choiceMap[erp_normalize_department_key($label)] = $label;
    }

    foreach (erp_department_alias_map() as $aliasKey => $aliasLabel) {
        if ($aliasKey === '') {
            continue;
        }
        foreach ($choices as $choice) {
            $choiceLabel = trim((string)$choice);
            if ($choiceLabel === '') {
                continue;
            }
            if (strcasecmp($choiceLabel, $aliasLabel) === 0) {
                $choiceMap[$aliasKey] = $choiceLabel;
                break;
            }
        }
    }

    $selected = [];
    $extras = [];
    foreach (erp_parse_multi_value_list($value) as $item) {
        $norm = erp_normalize_department_key($item);
        if ($norm === '') {
            continue;
        }
        if (isset($choiceMap[$norm])) {
            $selected[$choiceMap[$norm]] = true;
            continue;
        }
        $matched = false;
        foreach ($choiceMap as $key => $label) {
            if ($key === $norm || strpos($key, $norm) !== false || strpos($norm, $key) !== false) {
                $selected[$label] = true;
                $matched = true;
                break;
            }
        }
        if (!$matched) {
            $extras[$item] = true;
        }
    }

    if (empty($selected) && empty($extras)) {
        foreach ($defaults as $default) {
            $label = erp_canonical_department_label((string)$default, $choices);
            if ($label === '') {
                continue;
            }
            $norm = erp_normalize_department_key($label);
            if ($norm !== '' && isset($choiceMap[$norm])) {
                $selected[$choiceMap[$norm]] = true;
            }
        }
    }

    $ordered = [];
    foreach ($choices as $choice) {
        $label = trim((string)$choice);
        if ($label !== '' && isset($selected[$label])) {
            $ordered[] = $label;
        }
    }
    foreach (array_keys($extras) as $extra) {
        $ordered[] = $extra;
    }

    return implode(', ', array_values(array_unique($ordered)));
}

function erp_department_selection_list($value, ?array $choices = null, ?array $defaults = null): array {
    return erp_parse_multi_value_list(erp_normalize_department_selection($value, $choices, $defaults));
}

function erp_department_selection_contains($value, string $target, ?array $choices = null, ?array $defaults = null): bool {
    $targetKey = erp_normalize_department_key(erp_canonical_department_label($target, $choices));
    if ($targetKey === '') {
        return false;
    }

    foreach (erp_department_selection_list($value, $choices, $defaults) as $item) {
        if (erp_normalize_department_key($item) === $targetKey) {
            return true;
        }
    }

    return false;
}

function planningNotificationTargets($planningDepartment = '') {
    $department = strtolower(trim((string)$planningDepartment));
    $targets = ['planning'];

    if (in_array($department, ['printing', 'flexo_printing', 'flexo-printing'], true)) {
        $targets[] = 'flexo_printing';
    } elseif (in_array($department, ['slitting', 'jumbo_slitting', 'jumbo-slitting'], true)) {
        $targets[] = 'jumbo_slitting';
    } elseif (!in_array($department, ['packing', 'dispatch'], true)) {
        $targets[] = 'flexo_printing';
        $targets[] = 'jumbo_slitting';
    }

    return array_values(array_unique(array_filter($targets)));
}

function planningNotificationRouteInfo($planningDepartment = '') {
    $department = strtolower(trim((string)$planningDepartment));

    if (in_array($department, ['label', 'label-printing', 'label_printing'], true)) {
        return ['label' => 'label printing', 'path' => '/modules/planning/label/index.php'];
    }
    if (in_array($department, ['slitting', 'jumbo_slitting', 'jumbo-slitting'], true)) {
        return ['label' => 'jumbo slitting', 'path' => '/modules/planning/slitting/index.php'];
    }
    if (in_array($department, ['printing', 'flexo_printing', 'flexo-printing'], true)) {
        return ['label' => 'printing', 'path' => '/modules/planning/printing/index.php'];
    }
    if (in_array($department, ['flatbed', 'die-cutting', 'die_cutting'], true)) {
        return ['label' => 'die-cutting', 'path' => '/modules/planning/flatbed/index.php'];
    }
    if (in_array($department, ['barcode', 'rotery', 'rotary'], true)) {
        return ['label' => 'barcode', 'path' => '/modules/planning/barcode/index.php'];
    }
    if (in_array($department, ['paperroll', 'paper-roll', 'paper_roll'], true)) {
        return ['label' => 'paperroll', 'path' => '/modules/planning/paperroll/index.php'];
    }
    if (in_array($department, ['label_slitting', 'label-slitting', 'label slitting'], true)) {
        return ['label' => 'label slitting', 'path' => '/modules/planning/label-slitting/index.php'];
    }
    if (in_array($department, ['batch', 'batch_printing', 'batch-printing'], true)) {
        return ['label' => 'batch printing', 'path' => '/modules/planning/batch/index.php'];
    }
    if (in_array($department, ['packing', 'packaging'], true)) {
        return ['label' => 'packaging', 'path' => '/modules/planning/packing/index.php'];
    }
    if ($department === 'dispatch') {
        return ['label' => 'dispatch', 'path' => '/modules/planning/dispatch/index.php'];
    }

    return ['label' => 'planning', 'path' => '/modules/planning/index.php'];
}

function createDepartmentNotifications(mysqli $db, array $departments, $jobId, $message, $type = 'info') {
    $jobId = (int)$jobId;
    $message = trim((string)$message);
    $type = trim((string)$type);
    if ($message === '') return;
    if (!in_array($type, ['info', 'warning', 'success', 'error'], true)) {
        $type = 'info';
    }

    $departments = array_values(array_unique(array_filter(array_map(static function ($dept) {
        return trim((string)$dept);
    }, $departments))));
    if (empty($departments)) return;

    $stmt = $db->prepare("INSERT INTO job_notifications (job_id, department, message, type) VALUES (?, ?, ?, ?)");
    if (!$stmt) return;

    foreach ($departments as $department) {
        $stmt->bind_param('isss', $jobId, $department, $message, $type);
        $stmt->execute();
    }
}

function erpNotificationAdminChannel(string $scope): string {
    $scope = strtolower(trim($scope));
    $scope = preg_replace('/[^a-z0-9_]+/', '_', $scope);
    $scope = trim((string)$scope, '_');
    return $scope === '' ? '' : $scope . '_admin';
}

function erpNotificationUserChannel(string $scope, $userId): string {
    $scope = strtolower(trim($scope));
    $scope = preg_replace('/[^a-z0-9_]+/', '_', $scope);
    $scope = trim((string)$scope, '_');
    $userId = (int)$userId;
    if ($scope === '' || $userId <= 0) {
        return '';
    }
    return $scope . '_user_' . $userId;
}

function planningFabricationDepartmentFromDie($dieRaw = '') {
    $die = strtolower(trim((string)$dieRaw));
    if ($die === '') return 'flatbed';
    if (strpos($die, 'rotary') !== false || strpos($die, 'rotery') !== false) return 'rotery';
    if (strpos($die, 'label') !== false && strpos($die, 'slit') !== false) return 'label_slitting';
    if (strpos($die, 'flat') !== false) return 'flatbed';
    return 'flatbed';
}

function jobsAdvanceNotificationTargets($jobDepartment = '', array $planningExtra = []) {
    $department = strtolower(trim((string)$jobDepartment));
    switch ($department) {
        case 'jumbo_slitting':
            return ['planning'];
        case 'flexo_printing':
            return ['planning', planningFabricationDepartmentFromDie((string)($planningExtra['die'] ?? ''))];
        case 'flatbed':
        case 'rotery':
        case 'label_slitting':
            return ['planning', 'packing'];
        case 'packing':
            return ['planning', 'dispatch'];
        default:
            return [];
    }
}

function planningCreateNotifications(mysqli $db, $jobNo, $jobName, $planningDepartment = '', $eventLabel = 'added') {
    $jobNo = trim((string)$jobNo);
    $jobName = trim((string)$jobName);
    $eventLabel = trim((string)$eventLabel);
    if ($eventLabel === '') $eventLabel = 'updated';
    if ($jobNo === '' && $jobName === '') return;

    $routeInfo = planningNotificationRouteInfo($planningDepartment);
    $planningLabel = strtolower(trim((string)($routeInfo['label'] ?? 'planning')));
    $planningSuffix = $planningLabel === '' || $planningLabel === 'planning'
        ? 'planning'
        : ($planningLabel . ' planning');

    if ($jobNo !== '' && $jobName !== '') {
        $message = $jobNo . ' - ' . $jobName . ' ' . $eventLabel . ' to ' . $planningSuffix;
    } elseif ($jobNo !== '') {
        $message = $jobNo . ' ' . $eventLabel . ' to ' . $planningSuffix;
    } else {
        $message = $jobName . ' ' . $eventLabel . ' to ' . $planningSuffix;
    }

    createDepartmentNotifications($db, planningNotificationTargets($planningDepartment), 0, $message, 'info');
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

    $tableExists = function(string $tableName) use ($db): bool {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $schema = DB_NAME;
        $stmt->bind_param('ss', $schema, $tableName);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    $columnExists = function(string $tableName, string $columnName) use ($db): bool {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $schema = DB_NAME;
        $stmt->bind_param('sss', $schema, $tableName, $columnName);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    $indexExists = function(string $tableName, string $indexName) use ($db): bool {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $schema = DB_NAME;
        $stmt->bind_param('sss', $schema, $tableName, $indexName);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

    $constraintExists = function(string $tableName, string $constraintName) use ($db): bool {
        $stmt = $db->prepare("SELECT 1 FROM information_schema.table_constraints WHERE table_schema = ? AND table_name = ? AND constraint_name = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $schema = DB_NAME;
        $stmt->bind_param('sss', $schema, $tableName, $constraintName);
        $stmt->execute();
        $exists = (bool)$stmt->get_result()->fetch_row();
        $stmt->close();
        return $exists;
    };

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
    if (!$columnExists('users', 'group_id')) {
        $safeSchemaQuery("ALTER TABLE users ADD COLUMN group_id INT NULL AFTER role");
    }
    if (!$indexExists('users', 'idx_users_group_id')) {
        $safeSchemaQuery("ALTER TABLE users ADD INDEX idx_users_group_id (group_id)");
    }

    // Group master.
    if (!$tableExists('user_groups')) {
        $safeSchemaQuery("CREATE TABLE IF NOT EXISTS user_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL UNIQUE,
            description VARCHAR(255) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Group to page permission mapping.
    if (!$tableExists('group_page_permissions')) {
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
    } elseif (!$constraintExists('group_page_permissions', 'fk_gpp_group')) {
        $safeSchemaQuery("ALTER TABLE group_page_permissions ADD CONSTRAINT fk_gpp_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE");
    }

    // Granular action permissions for pages (add/edit/delete).
    if (!$columnExists('group_page_permissions', 'can_add')) {
        $safeSchemaQuery("ALTER TABLE group_page_permissions ADD COLUMN can_add TINYINT(1) NOT NULL DEFAULT 0 AFTER can_view");
    }
    if (!$columnExists('group_page_permissions', 'can_edit')) {
        $safeSchemaQuery("ALTER TABLE group_page_permissions ADD COLUMN can_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER can_add");
    }
    if (!$columnExists('group_page_permissions', 'can_delete')) {
        $safeSchemaQuery("ALTER TABLE group_page_permissions ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER can_edit");
    }
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
        '/modules/planning/flatbed/index.php' => 'Planning - Die-Cutting',
        '/modules/planning/barcode/index.php' => 'Planning - Barcode',
        '/modules/planning/rotery/index.php' => 'Planning - Barcode (Legacy URL)',
        '/modules/planning/paperroll/index.php' => 'Planning - PaperRoll',
        '/modules/planning/label-slitting/index.php' => 'Planning - Label Slitting',
        '/modules/planning/batch/index.php' => 'Planning - Batch Printing',
        '/modules/planning/packing/index.php' => 'Planning - Packaging',
        '/modules/planning/dispatch/index.php' => 'Planning - Dispatch',

        '/modules/operators/jumbo/index.php' => 'Machine Operator - Jumbo',
        '/modules/operators/pos/index.php' => 'Machine Operator - POS Roll',
        '/modules/operators/oneply/index.php' => 'Machine Operator - One Ply',
        '/modules/operators/twoply/index.php' => 'Machine Operator - Two Ply',
        '/modules/operators/printing/index.php' => 'Machine Operator - Flexo Printing',
        '/modules/operators/flatbed/index.php' => 'Machine Operator - Die-Cutting',
        '/modules/operators/barcode/index.php' => 'Machine Operator - Barcode',
        '/modules/operators/rotery/index.php' => 'Machine Operator - Rotery Die',
        '/modules/operators/label-slitting/index.php' => 'Machine Operator - Label Slitting',
        '/modules/operators/packing/index.php' => 'Machine Operator - Packing',

        '/modules/paper_stock/index.php' => 'Paper Stock',
        '/modules/audit/index.php' => 'Audit Hub',
        '/modules/scan/index.php' => 'Physical Stock Scan Terminal',
        '/modules/inventory/slitting/index.php' => 'Inventory - Slitting',
        '/modules/inventory/finished/index.php' => 'Inventory - Finished Good',
        '/modules/die-tooling/index.php' => 'Barcode Die',
        '/modules/design/barcode-die.php' => 'Design - Barcode Die',
        '/modules/master/barcode-die.php' => 'Master - Barcode Die',
        '/modules/design/plate-data.php' => 'Design - Plate Data',
        '/modules/master/plate-data.php' => 'Master - Plate Data',
        '/modules/design/cylinder-data.php' => 'Design - Cylinder Data',
        '/modules/master/cylinder-data.php' => 'Master - Cylinder Data',
        '/modules/plate-tools/plate-management/index.php' => 'Plate Data & Tools - Plate Management',
        '/modules/plate-tools/anilox-management/index.php' => 'Plate Data & Tools - Anilox Management',
        '/modules/plate-tools/die-management/printing/flatbed-printing-die.php' => 'Plate Data & Tools - Flatbed Printing Die',
        '/modules/plate-tools/die-management/printing/rotary-printing-die.php' => 'Plate Data & Tools - Rotary Printing Die',
        '/modules/plate-tools/die-management/barcode/index.php' => 'Plate Data & Tools - Barcode Die',
        '/modules/plate-tools/die-management/barcode/flatbed-barcode-die.php' => 'Plate Data & Tools - Flatbed Barcode Die',
        '/modules/plate-tools/die-management/barcode/rotary-barcode-die.php' => 'Plate Data & Tools - Rotary Barcode Die',
        '/modules/plate-tools/cylinder-management/printing/magnetic-printing-cylinder.php' => 'Plate Data & Tools - Magnetic Printing Cylinder',
        '/modules/plate-tools/cylinder-management/printing/sheeter-printing-cylinder.php' => 'Plate Data & Tools - Sheeter Printing Cylinder',
        '/modules/plate-tools/cylinder-management/barcode/magnetic-barcode-cylinder.php' => 'Plate Data & Tools - Magnetic Barcode Cylinder',

        '/modules/jobs/jumbo/index.php' => 'Job Card - Jumbo',
        '/modules/jobs/pos/index.php' => 'Job Card - POS Roll',
        '/modules/jobs/oneply/index.php' => 'Job Card - One Ply',
        '/modules/jobs/twoply/index.php' => 'Job Card - Two Ply',
        '/modules/jobs/printing/index.php' => 'Job Card - Flexo Printing',
        '/modules/jobs/flatbed/index.php' => 'Job Card - Die-Cutting',
        '/modules/jobs/barcode/index.php' => 'Job Card - Barcode',
        '/modules/jobs/rotery/index.php' => 'Job Card - Rotery Die',
        '/modules/jobs/label-slitting/index.php' => 'Job Card - Label Slitting',
        '/modules/jobs/packing/index.php' => 'Job Card - Packing Slip',
        '/modules/bom/index.php' => 'BOM Master',
        '/modules/live/index.php' => 'Live Floor',
        '/modules/production-manager/index.php' => 'Production Summary',

        '/modules/requisition-management/index.php' => 'Requisition Management',
        '/modules/requisition-management/api.php' => 'Requisition Management API',
        '/modules/purchase/index.php' => 'Purchase Order',

        '/modules/leave-management/index.php' => 'Leave Management',
        '/modules/leave-management/api.php' => 'Leave Management API',

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
    $aliases = [];
    if ($path === '/modules/planning/barcode/index.php') $aliases[] = '/modules/planning/rotery/index.php';
    if ($path === '/modules/planning/rotery/index.php') $aliases[] = '/modules/planning/barcode/index.php';
    if ($path === '/modules/jobs/barcode/index.php') $aliases[] = '/modules/jobs/rotery/index.php';
    if ($path === '/modules/jobs/rotery/index.php') $aliases[] = '/modules/jobs/barcode/index.php';
    if ($path === '/modules/operators/barcode/index.php') $aliases[] = '/modules/operators/rotery/index.php';
    if ($path === '/modules/operators/rotery/index.php') $aliases[] = '/modules/operators/barcode/index.php';
    if ($path === '/modules/plate-tools/die-management/barcode/index.php') {
        $aliases[] = '/modules/plate-tools/die-management/barcode/flatbed-barcode-die.php';
        $aliases[] = '/modules/plate-tools/die-management/barcode/rotary-barcode-die.php';
    }
    if ($path === '/modules/plate-tools/die-management/barcode/flatbed-barcode-die.php' || $path === '/modules/plate-tools/die-management/barcode/rotary-barcode-die.php') {
        $aliases[] = '/modules/plate-tools/die-management/barcode/index.php';
        $aliases[] = '/modules/plate-tools/die-management/barcode/flatbed-barcode-die.php';
        $aliases[] = '/modules/plate-tools/die-management/barcode/rotary-barcode-die.php';
    }

    // Public or always-allowed authenticated routes.
    if ($path === '/auth/logout.php') return true;
    if ($path === '/modules/dashboard/index.php') return true;

    if (isAdmin()) return true;

    // Slitting API should follow the same page permission as slitting index.
    if ($path === '/modules/inventory/slitting/api.php') {
        return canAccessPath('/modules/inventory/slitting/index.php');
    }

    // Multi Job Slitting page follows the same permission as slitting index.
    if ($path === '/modules/inventory/slitting/multi-job.php') {
        return canAccessPath('/modules/inventory/slitting/index.php');
    }

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

    // Requisition API follows same permission as requisition module page.
    if ($path === '/modules/requisition-management/api.php') {
        return canAccessPath('/modules/requisition-management/index.php');
    }

    // Leave API follows same permission as leave module page.
    if ($path === '/modules/leave-management/api.php') {
        return canAccessPath('/modules/leave-management/index.php');
    }

    $catalog = rbacPageCatalog();
    if (!isset($catalog[$path])) {
        // If page is not cataloged, deny by default for non-admin.
        return false;
    }

    $allowed = rbacUserAllowedPaths();
    if (in_array($path, $allowed, true)) return true;
    foreach ($aliases as $alias) {
        if (in_array($alias, $allowed, true)) return true;
    }
    return false;
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

    $pathsToCheck = [$path];
    if ($path === '/modules/planning/barcode/index.php') $pathsToCheck[] = '/modules/planning/rotery/index.php';
    if ($path === '/modules/planning/rotery/index.php') $pathsToCheck[] = '/modules/planning/barcode/index.php';
    if ($path === '/modules/jobs/barcode/index.php') $pathsToCheck[] = '/modules/jobs/rotery/index.php';
    if ($path === '/modules/jobs/rotery/index.php') $pathsToCheck[] = '/modules/jobs/barcode/index.php';
    if ($path === '/modules/operators/barcode/index.php') $pathsToCheck[] = '/modules/operators/rotery/index.php';
    if ($path === '/modules/operators/rotery/index.php') $pathsToCheck[] = '/modules/operators/barcode/index.php';
    if ($path === '/modules/plate-tools/die-management/barcode/index.php') {
        $pathsToCheck[] = '/modules/plate-tools/die-management/barcode/flatbed-barcode-die.php';
        $pathsToCheck[] = '/modules/plate-tools/die-management/barcode/rotary-barcode-die.php';
    }
    if ($path === '/modules/plate-tools/die-management/barcode/flatbed-barcode-die.php' || $path === '/modules/plate-tools/die-management/barcode/rotary-barcode-die.php') {
        $pathsToCheck[] = '/modules/plate-tools/die-management/barcode/index.php';
        $pathsToCheck[] = '/modules/plate-tools/die-management/barcode/flatbed-barcode-die.php';
        $pathsToCheck[] = '/modules/plate-tools/die-management/barcode/rotary-barcode-die.php';
    }

    $db = getDB();
    $granted = false;
    foreach ($pathsToCheck as $pathOption) {
        $q = $db->prepare("SELECT gpp.{$column}
            FROM users u
            INNER JOIN user_groups ug ON ug.id = u.group_id AND ug.is_active = 1
            INNER JOIN group_page_permissions gpp ON gpp.group_id = ug.id AND gpp.page_path = ?
            WHERE u.id = ? LIMIT 1");
        $q->bind_param('si', $pathOption, $userId);
        $q->execute();
        $row = $q->get_result()->fetch_assoc();
        if ($row && (bool)(int)$row[$column]) {
            $granted = true;
            break;
        }
    }
    $cache[$cacheKey] = $granted;
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
    $displayStatus = erp_status_display_label($status);
    $cls = erp_status_badge_class($status);
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
        'company_legal_name' => '',
        'contact_person' => '',
        'erp_display_name' => defined('TENANT_ERP_DISPLAY_NAME') ? TENANT_ERP_DISPLAY_NAME : '',
        'company_tagline' => 'ERP Master System',
        'company_email' => '',
        'company_mobile' => '',
        'company_phone' => '',
        'company_currency' => 'INR',
        'company_address' => '',
        'company_gst' => '',
        'erp_email' => '',
        'erp_phone' => '',
        'erp_address' => '',
        'erp_gst' => '',
        'logo_path' => '',
        'erp_logo_path' => '',
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
    $configuredPath = defined('TENANT_SETTINGS_FILE') ? trim((string)TENANT_SETTINGS_FILE) : '';
    if ($configuredPath !== '') {
        if (preg_match('#^(?:[A-Za-z]:)?[\\/]#', $configuredPath)) {
            return $configuredPath;
        }
        return __DIR__ . '/../' . ltrim(str_replace('\\', '/', $configuredPath), '/');
    }

    $tenantSlug = defined('TENANT_SLUG') ? trim((string)TENANT_SLUG) : 'default';
    $safeTenantSlug = preg_replace('/[^a-z0-9._-]+/i', '-', $tenantSlug);
    $tenantPath = __DIR__ . '/../data/tenants/' . $safeTenantSlug . '/app_settings.json';
    $legacyPath = __DIR__ . '/../data/app_settings.json';

    if (is_file($tenantPath)) {
        return $tenantPath;
    }
    if ($safeTenantSlug === 'default' && is_file($legacyPath)) {
        return $legacyPath;
    }
    if (!is_file($tenantPath) && !is_file($legacyPath)) {
        return $tenantPath;
    }
    return $legacyPath;
}

/**
 * Read app settings from JSON and merge with defaults.
 */
function getAppSettings($noCache = false) {
    static $cache = [];
    $path = getAppSettingsPath();
    if ($noCache) {
        unset($cache[$path]);
    }
    if (array_key_exists($path, $cache)) {
        return $cache[$path];
    }

    $defaults = appSettingsDefaults();
    if (defined('TENANT_NAME') && trim((string)TENANT_NAME) !== '' && trim((string)$defaults['company_name']) === trim((string)APP_NAME)) {
        $defaults['company_name'] = (string)TENANT_NAME;
    }
    if (!is_file($path)) {
        $cache[$path] = $defaults;
        return $cache[$path];
    }

    $raw = @file_get_contents($path);
    if ($raw === false) {
        $cache[$path] = $defaults;
        return $cache[$path];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        $cache[$path] = $defaults;
        return $cache[$path];
    }

    $cache[$path] = array_merge($defaults, $decoded);
    if (trim((string)$cache[$path]['company_mobile']) === '' && trim((string)$cache[$path]['company_phone']) !== '') {
        $cache[$path]['company_mobile'] = (string)$cache[$path]['company_phone'];
    }
    if (!is_array($cache[$path]['image_library'])) {
        $cache[$path]['image_library'] = [];
    } else {
        foreach ($cache[$path]['image_library'] as $i => $img) {
            if (!is_array($img)) {
                $cache[$path]['image_library'][$i] = ['path' => '', 'name' => '', 'uploaded_at' => '', 'category' => 'misc'];
                continue;
            }
            if (empty($cache[$path]['image_library'][$i]['category'])) {
                $cache[$path]['image_library'][$i]['category'] = 'misc';
            }
        }
    }
    return $cache[$path];
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
                'planning_barcode' => ['prefix' => 'PLN-BAR', 'counter' => 0],
                'planning_paperroll' => ['prefix' => 'PLN-PRL', 'counter' => 0],
                'jumbo_job' => ['prefix' => 'SLT', 'counter' => 0],
                'printing_job' => ['prefix' => 'FLX', 'counter' => 0],
                'die_cutting_job' => ['prefix' => 'DCT', 'counter' => 0],
                'label_slitting_job' => ['prefix' => 'LSL', 'counter' => 0],
                'barcode_job' => ['prefix' => 'BRC-BAR', 'counter' => 0],
                'paperroll_job' => ['prefix' => 'PRL', 'counter' => 0],
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
 * Get the max used numeric sequence from DB for a given module type.
 * Currently needed for roll IDs to recover from stale/reset JSON counters.
 */
function getExistingMaxSequenceForType($type, array $idg) {
    $type = trim((string)$type);
    if ($type !== 'roll') {
        return 0;
    }

    if (!isset($idg['modules'][$type])) {
        return 0;
    }

    $prefix = strtoupper(trim((string)($idg['modules'][$type]['prefix'] ?? '')));
    if ($prefix === '') {
        return 0;
    }

    $separator = (string)($idg['separator'] ?? '/');
    $yearToken = buildIdYearToken((string)($idg['year_format'] ?? 'YY'));
    $prefixExpr = $prefix . $separator . $yearToken . $separator;
    $likeExpr = $prefixExpr . '%';

    $db = getDB();
    $stmt = $db->prepare('SELECT roll_no FROM paper_stock WHERE UPPER(roll_no) LIKE ?');
    if (!$stmt) {
        return 0;
    }
    $stmt->bind_param('s', $likeExpr);
    if (!$stmt->execute()) {
        return 0;
    }

    $res = $stmt->get_result();
    $maxSeq = 0;
    while ($res && ($row = $res->fetch_assoc())) {
        $rollNo = (string)($row['roll_no'] ?? '');
        if (stripos($rollNo, $prefixExpr) !== 0) {
            continue;
        }
        $seqPart = substr($rollNo, strlen($prefixExpr));
        if ($seqPart !== '' && ctype_digit($seqPart)) {
            $maxSeq = max($maxSeq, (int)$seqPart);
        }
    }

    return $maxSeq;
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
    $storedCounter = max(0, (int)$module['counter']);
    $dbCounter = getExistingMaxSequenceForType($type, $idg);
    $next = max($storedCounter, $dbCounter) + 1;
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
    $storedCounter = max(0, (int)$module['counter']);
    $dbCounter = getExistingMaxSequenceForType($type, $idg);
    $next = max($storedCounter, $dbCounter) + 1;
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
    $storedCounter = max(0, (int)($module['counter'] ?? 0));
    $dbCounter = getExistingMaxSequenceForType($type, $idg);
    $next = max($storedCounter, $dbCounter) + 1;

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
 * E.g. SLT/2026/1020 (jumbo_job), FLX/2026/1021 (printing_job)
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
    $settings = getAppSettings();
    $explicitDisplayName = trim((string)($settings['erp_display_name'] ?? (defined('TENANT_ERP_DISPLAY_NAME') ? TENANT_ERP_DISPLAY_NAME : '')));
    if ($explicitDisplayName !== '') {
        return $explicitDisplayName;
    }

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
