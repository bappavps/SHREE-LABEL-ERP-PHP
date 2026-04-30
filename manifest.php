<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$settings = getAppSettings();
$companyName = trim((string)($settings['company_name'] ?? '')) ?: APP_NAME;
$erpLogoPath = trim((string)($settings['erp_logo_path'] ?? ''));
$logoPath = trim((string)($settings['logo_path'] ?? ''));
$iconPath = $erpLogoPath !== '' ? $erpLogoPath : $logoPath;
$iconSrc = $iconPath !== '' ? appUrl($iconPath) : appUrl('assets/img/logo.svg');
$pwaIcon192 = appUrl('pwa_icon.php?size=192');
$pwaIcon512 = appUrl('pwa_icon.php?size=512');
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
    'start_url' => appUrl('index.php'),
    'scope' => appUrl(''),
    'display' => 'standalone',
    'background_color' => '#ffffff',
    'theme_color' => $themeColor,
    'icons' => [
        [
            'src' => $pwaIcon192,
            'sizes' => '192x192',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $pwaIcon512,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'any'
        ],
        [
            'src' => $pwaIcon512,
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable'
        ]
    ]
];

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
