<?php
// ============================================================
// ERP System — Portable Database Configuration
// Works across local XAMPP and shared hosting when DB credentials
// are provided via runtime config or environment variables.
// ============================================================

function erp_env_value(array $names, $default = '') {
    foreach ($names as $name) {
        $value = getenv($name);
        if ($value !== false && $value !== null && trim((string)$value) !== '') {
            return trim((string)$value);
        }
        if (isset($_SERVER[$name]) && trim((string)$_SERVER[$name]) !== '') {
            return trim((string)$_SERVER[$name]);
        }
    }
    return $default;
}

function erp_is_local_request() {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $serverAddr = (string)($_SERVER['SERVER_ADDR'] ?? '');
    return $host === ''
        || strpos($host, 'localhost') !== false
        || strpos($host, '127.0.0.1') !== false
        || substr($host, -6) === '.local'
        || $serverAddr === '127.0.0.1'
        || $serverAddr === '::1'
        || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $host)
        || preg_match('/^(192\.168\.|10\.|172\.(1[6-9]|2[0-9]|3[01])\.)/', $serverAddr);
}

function erp_normalize_base_url($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    $value = str_replace('\\', '/', $value);
    if (preg_match('#^[A-Za-z]:/#', $value) || stripos($value, '/public_html/') !== false || stripos($value, '/htdocs/') !== false) {
        return '';
    }

    if (preg_match('#^(?:https?:)?//#i', $value)) {
        $path = (string)(parse_url($value, PHP_URL_PATH) ?? '');
        $value = $path;
    }

    $normalized = '/' . trim($value, '/');
    return $normalized === '/' ? '' : $normalized;
}

function erp_detect_base_url($configured = '') {
    $normalized = erp_normalize_base_url($configured);
    if ($normalized !== '' || trim((string)$configured) === '/') {
        return $normalized;
    }

    $documentRoot = str_replace('\\', '/', (string)(realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: ($_SERVER['DOCUMENT_ROOT'] ?? '')));
    $appRoot = str_replace('\\', '/', (string)(realpath(__DIR__ . '/..') ?: (__DIR__ . '/..')));

    if ($documentRoot !== '' && $appRoot !== '') {
        $documentRoot = rtrim($documentRoot, '/');
        if (stripos($appRoot, $documentRoot) === 0) {
            $relative = trim(substr($appRoot, strlen($documentRoot)), '/');
            return $relative === '' ? '' : '/' . $relative;
        }
    }

    return '';
}

function erp_normalize_request_host($host) {
    $host = strtolower(trim((string)$host));
    if ($host === '') {
        return '';
    }
    $host = preg_replace('/:\d+$/', '', $host);
    return $host ?? '';
}

function erp_normalize_request_path($path) {
    $path = trim((string)$path);
    if ($path === '') {
        return '/';
    }
    $path = str_replace('\\', '/', $path);
    $path = '/' . trim($path, '/');
    return $path === '//' ? '/' : $path;
}

function erp_load_tenant_registry() {
    $tenantFile = __DIR__ . '/tenants.php';
    if (!file_exists($tenantFile)) {
        return [
            'default_slug' => 'default',
            'tenants' => [
                'default' => [],
            ],
        ];
    }

    $registry = require $tenantFile;
    if (!is_array($registry)) {
        return [
            'default_slug' => 'default',
            'tenants' => [
                'default' => [],
            ],
        ];
    }

    if (!isset($registry['tenants']) || !is_array($registry['tenants']) || $registry['tenants'] === []) {
        $registry['tenants'] = ['default' => []];
    }

    $dynamicRegistryFile = __DIR__ . '/../data/tenants_registry.json';
    if (is_file($dynamicRegistryFile)) {
        $dynamicRaw = file_get_contents($dynamicRegistryFile);
        $dynamicDecoded = $dynamicRaw !== false ? json_decode($dynamicRaw, true) : null;
        if (is_array($dynamicDecoded) && isset($dynamicDecoded['tenants']) && is_array($dynamicDecoded['tenants'])) {
            foreach ($dynamicDecoded['tenants'] as $slug => $tenant) {
                if (!is_string($slug) || trim($slug) === '' || !is_array($tenant)) {
                    continue;
                }
                $registry['tenants'][$slug] = isset($registry['tenants'][$slug]) && is_array($registry['tenants'][$slug])
                    ? array_merge($registry['tenants'][$slug], $tenant)
                    : $tenant;
            }
            if (!empty($dynamicDecoded['default_slug']) && isset($registry['tenants'][$dynamicDecoded['default_slug']])) {
                $registry['default_slug'] = (string)$dynamicDecoded['default_slug'];
            }
        }
    }

    if (empty($registry['default_slug']) || !isset($registry['tenants'][$registry['default_slug']])) {
        $registry['default_slug'] = (string)array_key_first($registry['tenants']);
    }

    return $registry;
}

function erp_host_matches_tenant($requestHost, array $hosts) {
    $requestHost = erp_normalize_request_host($requestHost);
    if ($requestHost === '' || $hosts === []) {
        return false;
    }

    foreach ($hosts as $host) {
        $host = erp_normalize_request_host($host);
        if ($host === '') {
            continue;
        }
        if ($host === '*') {
            return true;
        }
        if ($requestHost === $host) {
            return true;
        }
        if (str_starts_with($host, '*.')) {
            $suffix = substr($host, 1);
            if ($suffix !== '' && str_ends_with($requestHost, $suffix)) {
                return true;
            }
        }
    }

    return false;
}

function erp_path_matches_tenant($requestPath, array $pathPrefixes) {
    $requestPath = erp_normalize_request_path($requestPath);
    foreach ($pathPrefixes as $prefix) {
        $prefix = erp_normalize_request_path($prefix);
        if ($prefix === '/' || $prefix === '') {
            return true;
        }
        if ($requestPath === $prefix || str_starts_with($requestPath, $prefix . '/')) {
            return true;
        }
    }
    return false;
}

function erp_build_tenant_payload($slug, array $tenant, $env, array $baseProfile) {
    $dbOverrides = [];
    if (isset($tenant['db'][$env]) && is_array($tenant['db'][$env])) {
        $dbOverrides = $tenant['db'][$env];
    }

    $profile = array_merge($baseProfile, $dbOverrides);
    if (!empty($tenant['base_url'])) {
        $profile['BASE_URL'] = $tenant['base_url'];
    }
    if (!empty($tenant['app_name'])) {
        $profile['APP_NAME'] = $tenant['app_name'];
    }

    return [
        'slug' => (string)$slug,
        'label' => trim((string)($tenant['label'] ?? $slug)),
        'active' => !isset($tenant['active']) || (bool)$tenant['active'],
        'settings_file' => trim((string)($tenant['settings_file'] ?? '')),
        'erp_display_name' => trim((string)($tenant['erp_display_name'] ?? '')),
        'profile' => $profile,
    ];
}

function erp_resolve_tenant(array $registry, $env, array $baseProfile) {
    $tenants = $registry['tenants'] ?? ['default' => []];
    $defaultSlug = (string)($registry['default_slug'] ?? array_key_first($tenants));
    $forcedTenant = trim((string)erp_env_value(['ERP_TENANT'], ''));

    if ($forcedTenant !== '' && isset($tenants[$forcedTenant])) {
        return erp_build_tenant_payload($forcedTenant, (array)$tenants[$forcedTenant], $env, $baseProfile);
    }

    $requestHost = erp_normalize_request_host($_SERVER['HTTP_HOST'] ?? '');
    $requestPath = erp_normalize_request_path((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/'));

    foreach ($tenants as $slug => $tenant) {
        $tenant = (array)$tenant;
        $hosts = isset($tenant['hosts']) && is_array($tenant['hosts']) ? $tenant['hosts'] : [];
        if (erp_host_matches_tenant($requestHost, $hosts)) {
            return erp_build_tenant_payload((string)$slug, $tenant, $env, $baseProfile);
        }
    }

    foreach ($tenants as $slug => $tenant) {
        $tenant = (array)$tenant;
        $pathPrefixes = isset($tenant['path_prefixes']) && is_array($tenant['path_prefixes']) ? $tenant['path_prefixes'] : [];
        if (erp_path_matches_tenant($requestPath, $pathPrefixes)) {
            return erp_build_tenant_payload((string)$slug, $tenant, $env, $baseProfile);
        }
    }

    $fallbackTenant = isset($tenants[$defaultSlug]) ? (array)$tenants[$defaultSlug] : (array)reset($tenants);
    return erp_build_tenant_payload($defaultSlug !== '' ? $defaultSlug : 'default', $fallbackTenant, $env, $baseProfile);
}

$profiles = [
    'local' => [
        'DB_HOST' => erp_env_value(['ERP_DB_HOST_LOCAL', 'ERP_DB_HOST', 'DB_HOST'], 'localhost'),
        'DB_PORT' => (int)erp_env_value(['ERP_DB_PORT_LOCAL', 'ERP_DB_PORT', 'DB_PORT'], '3306'),
        'DB_SOCKET' => erp_env_value(['ERP_DB_SOCKET_LOCAL', 'ERP_DB_SOCKET', 'DB_SOCKET'], ''),
        'DB_USER' => erp_env_value(['ERP_DB_USER_LOCAL', 'ERP_DB_USER', 'DB_USER'], 'root'),
        'DB_PASS' => erp_env_value(['ERP_DB_PASS_LOCAL', 'ERP_DB_PASS', 'DB_PASS'], ''),
        'DB_NAME' => erp_env_value(['ERP_DB_NAME_LOCAL', 'ERP_DB_NAME', 'DB_NAME'], 'shree_label_erp'),
        'BASE_URL' => erp_env_value(['ERP_BASE_URL_LOCAL', 'ERP_BASE_URL'], ''),
        'APP_NAME' => 'Enterprise ERP',
        'APP_VERSION' => '1.0',
    ],
    'live' => [
        'DB_HOST' => erp_env_value(['ERP_DB_HOST_LIVE', 'ERP_DB_HOST', 'DB_HOST'], 'localhost'),
        'DB_PORT' => (int)erp_env_value(['ERP_DB_PORT_LIVE', 'ERP_DB_PORT', 'DB_PORT'], '3306'),
        'DB_SOCKET' => erp_env_value(['ERP_DB_SOCKET_LIVE', 'ERP_DB_SOCKET', 'DB_SOCKET'], ''),
        'DB_USER' => erp_env_value(['ERP_DB_USER_LIVE', 'ERP_DB_USER', 'DB_USER'], ''),
        'DB_PASS' => erp_env_value(['ERP_DB_PASS_LIVE', 'ERP_DB_PASS', 'DB_PASS'], ''),
        'DB_NAME' => erp_env_value(['ERP_DB_NAME_LIVE', 'ERP_DB_NAME', 'DB_NAME'], ''),
        'BASE_URL' => erp_env_value(['ERP_BASE_URL_LIVE', 'ERP_BASE_URL'], ''),
        'APP_NAME' => 'Enterprise ERP',
        'APP_VERSION' => '1.0',
    ],
];

$runtimeConfigFile = __DIR__ . '/db.runtime.php';
if (file_exists($runtimeConfigFile)) {
    $runtime = require $runtimeConfigFile;
    if (is_array($runtime)) {
        if (isset($runtime['local']) && is_array($runtime['local'])) {
            $profiles['local'] = array_merge($profiles['local'], $runtime['local']);
        }
        if (isset($runtime['live']) && is_array($runtime['live'])) {
            $profiles['live'] = array_merge($profiles['live'], $runtime['live']);
        }
    }
}

$forcedEnv = getenv('ERP_ENV');
$env = '';
if ($forcedEnv === 'local' || $forcedEnv === 'live') {
    $env = $forcedEnv;
} else {
    $env = erp_is_local_request() ? 'local' : 'live';
}

$tenantRegistry = erp_load_tenant_registry();
$resolvedTenant = erp_resolve_tenant($tenantRegistry, $env, $profiles[$env]);
$active = $resolvedTenant['profile'];
$resolvedBaseUrl = erp_detect_base_url($active['BASE_URL'] ?? '');

define('DB_HOST', $active['DB_HOST']);
define('DB_PORT', (int)($active['DB_PORT'] ?? 3306));
define('DB_SOCKET', (string)($active['DB_SOCKET'] ?? ''));
define('DB_USER', $active['DB_USER']);
define('DB_PASS', $active['DB_PASS']);
define('DB_NAME', $active['DB_NAME']);
define('BASE_URL', $resolvedBaseUrl);
define('APP_NAME', $active['APP_NAME']);
define('APP_VERSION', $active['APP_VERSION']);
define('TENANT_SLUG', (string)($resolvedTenant['slug'] ?? 'default'));
define('TENANT_NAME', (string)($resolvedTenant['label'] ?? APP_NAME));
define('TENANT_ACTIVE', (bool)($resolvedTenant['active'] ?? true));
define('TENANT_SETTINGS_FILE', (string)($resolvedTenant['settings_file'] ?? ''));
define('TENANT_ERP_DISPLAY_NAME', (string)($resolvedTenant['erp_display_name'] ?? ''));

function getDB() {
    static $conn = null;
    if ($conn === null) {
        if (trim((string)DB_HOST) === '' || trim((string)DB_USER) === '' || trim((string)DB_NAME) === '') {
            die('<p style="font-family:sans-serif;color:red;padding:20px">Database settings are incomplete.'
                . '<br>Environment: ' . htmlspecialchars((string) (getenv('ERP_ENV') ?: 'auto'))
                . '<br>Please update config/db.runtime.php or provide ERP_DB_HOST, ERP_DB_USER, ERP_DB_PASS, ERP_DB_NAME environment variables.</p>');
        }
        $hostsToTry = [trim((string)DB_HOST)];
        if (erp_is_local_request()) {
            if (in_array(strtolower(trim((string)DB_HOST)), ['localhost', '127.0.0.1'], true)) {
                $hostsToTry = ['127.0.0.1', 'localhost'];
            }
        }

        $portsToTry = [DB_PORT > 0 ? DB_PORT : 3306];
        if (erp_is_local_request()) {
            // Common XAMPP mismatch: MySQL runs on 3307 while app expects 3306.
            foreach ([3306, 3307] as $candidatePort) {
                if (!in_array($candidatePort, $portsToTry, true)) {
                    $portsToTry[] = $candidatePort;
                }
            }
        }

        $lastError = 'Unknown connection error';
        foreach ($hostsToTry as $host) {
            foreach ($portsToTry as $port) {
                try {
                    $candidate = new mysqli($host, DB_USER, DB_PASS, DB_NAME, $port, DB_SOCKET !== '' ? DB_SOCKET : null);
                    if (!$candidate->connect_error) {
                        $conn = $candidate;
                        break 2;
                    }
                    $lastError = (string)$candidate->connect_error;
                } catch (Throwable $e) {
                    $lastError = (string)$e->getMessage();
                }
            }
        }

        if (!$conn) {
            die('<p style="font-family:sans-serif;color:red;padding:20px">Database connection failed: '
                . htmlspecialchars($lastError)
                . '<br>Environment: ' . htmlspecialchars((string) (getenv('ERP_ENV') ?: 'auto'))
                . '<br>Tenant: ' . htmlspecialchars(TENANT_SLUG)
                . '<br>Tried host(s): ' . htmlspecialchars(implode(', ', $hostsToTry))
                . '<br>Tried port(s): ' . htmlspecialchars(implode(', ', array_map('strval', $portsToTry)))
                . '<br>Set ERP_DB_HOST / ERP_DB_PORT (or create config/db.runtime.php) and ensure MySQL is running in XAMPP.</p>');
        }
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}
