<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

$sizeRaw = isset($_GET['size']) ? (int)$_GET['size'] : 192;
$allowed = [96, 128, 144, 152, 192, 384, 512];
$size = in_array($sizeRaw, $allowed, true) ? $sizeRaw : 192;

$settings = getAppSettings();
$erpLogoPath = trim((string)($settings['erp_logo_path'] ?? ''));
$logoPath = trim((string)($settings['logo_path'] ?? ''));
$iconPath = $erpLogoPath !== '' ? $erpLogoPath : $logoPath;

if ($iconPath === '') {
    http_response_code(302);
    header('Location: ' . appUrl('assets/img/logo.svg'));
    exit;
}

$iconFsPath = __DIR__ . '/' . ltrim(str_replace('\\', '/', $iconPath), '/');
if (!is_file($iconFsPath)) {
    http_response_code(302);
    header('Location: ' . appUrl('assets/img/logo.svg'));
    exit;
}

if (!function_exists('imagecreatetruecolor')) {
    http_response_code(302);
    header('Location: ' . appUrl($iconPath));
    exit;
}

$ext = strtolower(pathinfo($iconFsPath, PATHINFO_EXTENSION));
$srcImg = null;
switch ($ext) {
    case 'png':
        $srcImg = @imagecreatefrompng($iconFsPath);
        break;
    case 'jpg':
    case 'jpeg':
        $srcImg = @imagecreatefromjpeg($iconFsPath);
        break;
    case 'webp':
        if (function_exists('imagecreatefromwebp')) {
            $srcImg = @imagecreatefromwebp($iconFsPath);
        }
        break;
    case 'gif':
        $srcImg = @imagecreatefromgif($iconFsPath);
        break;
}

if (!$srcImg) {
    http_response_code(302);
    header('Location: ' . appUrl($iconPath));
    exit;
}

$srcW = imagesx($srcImg);
$srcH = imagesy($srcImg);
if ($srcW <= 0 || $srcH <= 0) {
    imagedestroy($srcImg);
    http_response_code(302);
    header('Location: ' . appUrl($iconPath));
    exit;
}

$canvas = imagecreatetruecolor($size, $size);
imagesavealpha($canvas, true);

$bg = imagecolorallocate($canvas, 255, 255, 255);
imagefill($canvas, 0, 0, $bg);

$scale = min($size / $srcW, $size / $srcH);
$dstW = max(1, (int)floor($srcW * $scale));
$dstH = max(1, (int)floor($srcH * $scale));
$dstX = (int)floor(($size - $dstW) / 2);
$dstY = (int)floor(($size - $dstH) / 2);

imagecopyresampled($canvas, $srcImg, $dstX, $dstY, 0, 0, $dstW, $dstH, $srcW, $srcH);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
imagepng($canvas);

imagedestroy($srcImg);
imagedestroy($canvas);
