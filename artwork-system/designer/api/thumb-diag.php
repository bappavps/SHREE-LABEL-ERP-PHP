<?php
/**
 * Thumbnail Generation Diagnostic — no auth dependency
 * DELETE this file after diagnosis.
 */
// Simple IP-based guard (only accessible from your IP or remove if needed)
header('Content-Type: text/plain; charset=utf-8');

echo "=== Thumbnail Diagnostic ===\n\n";

// 1. PHP version
echo "PHP: " . PHP_VERSION . "\n";
echo "OS: " . PHP_OS . "\n";
echo "DIRECTORY_SEPARATOR: " . (DIRECTORY_SEPARATOR === '\\' ? 'Windows' : 'Linux/Mac') . "\n\n";

// 2. Path check
$erpRoot  = (string) realpath(__DIR__ . '/../../..');
$plateDir = $erpRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'plate-data';
echo "ERP Root  : $erpRoot\n";
echo "Plate Dir : $plateDir\n";
echo "Plate Dir exists: "   . (is_dir($plateDir)    ? 'YES' : 'NO — will try mkdir') . "\n";
if (!is_dir($plateDir)) {
    $made = @mkdir($plateDir, 0755, true);
    echo "mkdir result: " . ($made ? 'OK' : 'FAILED (permission problem!)') . "\n";
}
echo "Plate Dir writable: " . (is_writable($plateDir) ? 'YES' : 'NO (PROBLEM!)') . "\n\n";

// 3. Imagick
echo "--- Imagick ---\n";
if (extension_loaded('imagick')) {
    echo "Imagick loaded: YES\n";
    try {
        $ver = Imagick::getVersion();
        echo "Version: " . ($ver['versionString'] ?? 'unknown') . "\n";
        $im = new Imagick();
        $formats = $im->queryFormats('PDF');
        echo "PDF format supported: " . (!empty($formats) ? 'YES' : 'NO — policy blocked') . "\n";
        $im->destroy();
    } catch (Throwable $e) {
        echo "Imagick error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Imagick loaded: NO\n";
}

// 4. Ghostscript
echo "\n--- Ghostscript ---\n";
echo "exec() available: " . (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions')))) ? 'YES' : 'NO') . "\n";
if (DIRECTORY_SEPARATOR !== '\\') {
    $gs = trim((string)@shell_exec('which gs 2>/dev/null'));
    echo "GS path: " . ($gs !== '' ? $gs : 'NOT FOUND') . "\n";
    if ($gs !== '') {
        $gsVer = trim((string)@shell_exec($gs . ' --version 2>/dev/null'));
        echo "GS version: $gsVer\n";
    }
} else {
    $gsCandidates = glob('C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe') ?: [];
    $gs = !empty($gsCandidates) ? end($gsCandidates) : '';
    echo "GS path: " . ($gs !== '' ? $gs : 'NOT FOUND') . "\n";
}

// 5. disabled functions
echo "\ndisable_functions: " . ini_get('disable_functions') . "\n";

// 6. upload dir
$finalDir = $erpRoot . DIRECTORY_SEPARATOR . 'artwork-system' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'final';
echo "\nFinal upload dir: $finalDir\n";
echo "Final dir exists: " . (is_dir($finalDir) ? 'YES' : 'NO') . "\n";

echo "\n=== End ===\n";


echo "=== Thumbnail Diagnostic ===\n\n";

// 1. PHP version
echo "PHP: " . PHP_VERSION . "\n";
echo "OS: " . PHP_OS . "\n";
echo "DIRECTORY_SEPARATOR: " . DIRECTORY_SEPARATOR . "\n\n";

// 2. Path check
$erpRoot  = (string) realpath(__DIR__ . '/../../..');
$plateDir = $erpRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'library' . DIRECTORY_SEPARATOR . 'plate-data';
echo "ERP Root  : $erpRoot\n";
echo "Plate Dir : $plateDir\n";
echo "Plate Dir exists: " . (is_dir($plateDir) ? 'YES' : 'NO') . "\n";
echo "Plate Dir writable: " . (is_writable($plateDir) ? 'YES' : 'NO (PROBLEM!)') . "\n\n";

// 3. Imagick
echo "--- Imagick ---\n";
if (extension_loaded('imagick')) {
    echo "Imagick loaded: YES\n";
    $ver = Imagick::getVersion();
    echo "Version: " . ($ver['versionString'] ?? 'unknown') . "\n";

    // Check PDF policy
    try {
        $im = new Imagick();
        $formats = $im->queryFormats('PDF');
        echo "PDF format supported: " . (!empty($formats) ? 'YES' : 'NO') . "\n";
    } catch (Throwable $e) {
        echo "PDF policy check error: " . $e->getMessage() . "\n";
    }
} else {
    echo "Imagick loaded: NO\n";
}

// 4. Ghostscript
echo "\n--- Ghostscript ---\n";
if (DIRECTORY_SEPARATOR === '\\') {
    $gsCandidates = glob('C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe') ?: [];
    $gs = !empty($gsCandidates) ? end($gsCandidates) : 'NOT FOUND';
    echo "GS path: $gs\n";
    echo "GS exists: " . (file_exists($gs) ? 'YES' : 'NO') . "\n";
} else {
    $gs = trim((string)@shell_exec('which gs 2>/dev/null'));
    echo "GS path: " . ($gs !== '' ? $gs : 'NOT FOUND') . "\n";
    if ($gs !== '') {
        $gsVer = trim((string)@shell_exec($gs . ' --version 2>/dev/null'));
        echo "GS version: $gsVer\n";
    }
}

// 5. exec() available
echo "\nexec() available: " . (function_exists('exec') ? 'YES' : 'NO (disabled!)') . "\n";
echo "shell_exec() available: " . (function_exists('shell_exec') ? 'YES' : 'NO') . "\n";

// 6. ERP_BASE_URL
echo "\nERP_BASE_URL: " . (defined('ERP_BASE_URL') ? ERP_BASE_URL : 'NOT DEFINED') . "\n";

echo "\n=== End Diagnostic ===\n";
