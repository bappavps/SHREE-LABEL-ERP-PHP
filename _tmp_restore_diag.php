<?php
// TEMPORARY DIAGNOSTIC — DELETE AFTER USE
// Upload to erp.shreelabel.com root, open in browser
define('ERP_ENTRY', true);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');

$tenantSlug = defined('TENANT_SLUG') ? TENANT_SLUG : 'default';
$projectRoot = str_replace('\\', '/', realpath(__DIR__) ?: __DIR__);
$isTenantCtx = ($tenantSlug !== 'default' && $tenantSlug !== '');

echo "=== RESTORE DIAGNOSTIC ===\n\n";
echo "Tenant Slug    : $tenantSlug\n";
echo "Is Tenant Ctx  : " . ($isTenantCtx ? 'YES' : 'NO (default)') . "\n";
echo "Project Root   : $projectRoot\n\n";

// Settings path
$settingsPath = $isTenantCtx
    ? $projectRoot . '/data/tenants/' . preg_replace('/[^a-z0-9._-]+/i', '-', $tenantSlug) . '/app_settings.json'
    : $projectRoot . '/data/app_settings.json';

echo "Settings File  : $settingsPath\n";
echo "Settings Exists: " . (file_exists($settingsPath) ? 'YES' : 'NO') . "\n\n";

if (file_exists($settingsPath)) {
    $json = json_decode(file_get_contents($settingsPath), true);
    $library = $json['image_library'] ?? [];
    echo "image_library count: " . count($library) . "\n\n";
    foreach ($library as $i => $img) {
        $path = $img['path'] ?? '';
        $fullPath = $projectRoot . '/' . $path;
        $exists = file_exists($fullPath);
        echo "  [$i] path   : $path\n";
        echo "  [$i] file   : " . ($exists ? 'EXISTS' : 'MISSING') . " ($fullPath)\n\n";
    }
} else {
    echo "NO SETTINGS FILE FOUND.\n";
    echo "Looking for any app_settings.json in data/...\n";
    $found = glob($projectRoot . '/data/*/app_settings.json') ?: [];
    $found2 = glob($projectRoot . '/data/*/*/app_settings.json') ?: [];
    $allFound = array_merge(
        file_exists($projectRoot . '/data/app_settings.json') ? [$projectRoot . '/data/app_settings.json'] : [],
        $found, $found2
    );
    foreach ($allFound as $f) {
        echo "  Found: $f\n";
    }
}

echo "\n=== UPLOADS DIRECTORY ===\n";
$libDir = $projectRoot . '/uploads/uploads/library/';
echo "uploads/uploads/library/ exists: " . (is_dir($libDir) ? 'YES' : 'NO') . "\n";
if (is_dir($libDir)) {
    $dirs = glob($libDir . '*', GLOB_ONLYDIR) ?: [];
    foreach ($dirs as $d) {
        $files = glob($d . '/*') ?: [];
        echo "  " . basename($d) . "/ — " . count($files) . " files\n";
    }
}
