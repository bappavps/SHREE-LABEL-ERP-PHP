<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$settings = getAppSettings();
$companyName = trim((string)($settings['company_name'] ?? '')) ?: APP_NAME;
$logoPath = trim((string)($settings['logo_path'] ?? ''));
$iconSrc = $logoPath !== '' ? (BASE_URL . '/' . ltrim($logoPath, '/')) : (BASE_URL . '/assets/img/logo.svg');
$themeColor = trim((string)($settings['sidebar_button_color'] ?? '#22c55e')) ?: '#22c55e';
$iconExt = strtolower(pathinfo($iconSrc, PATHINFO_EXTENSION));
$mimeMap = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml'
];
$iconType = $mimeMap[$iconExt] ?? 'image/png';

$manifest = [
    'name' => $companyName,
    'short_name' => substr($companyName, 0, 12),
    'description' => $companyName . ' ERP',
    'start_url' => BASE_URL . '/index.php',
    'scope' => BASE_URL . '/',
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => $themeColor,
    'icons' => [
        [
            'src' => $iconSrc,
            'sizes' => '192x192',
            'type' => $iconType,
            'purpose' => 'any maskable'
        ],
        [
            'src' => $iconSrc,
            'sizes' => '512x512',
            'type' => $iconType,
            'purpose' => 'any maskable'
        ]
    ]
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
