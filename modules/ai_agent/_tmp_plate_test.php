<?php
// Test helper: run /plate and /paperstock queries through the AI API
// Mirrors _tmp_test_api.php approach (login + session cookie + query)
$base = 'http://localhost/calipot-erp/shree-label-php';
$cookieJar = __DIR__ . '/_tmp_cookies.txt';

// --- Auth bootstrap (creates a valid session cookie without touching ERP data) ---
$ch = curl_init($base . '/modules/ai_agent/_tmp_auth.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
curl_exec($ch);
curl_close($ch);

function askAgent($prompt) {
    global $base, $cookieJar;
    $ch = curl_init($base . '/modules/ai_agent/api.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, ['action' => 'query', 'prompt' => $prompt]);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode($result, true);
    if ($decoded === null) {
        return ['http' => $httpCode, 'error' => $err, 'raw' => substr($result, 0, 1500)];
    }
    return ['http' => $httpCode, 'answer' => $decoded['answer'] ?? '(no answer)', 'tool' => $decoded['tool_used'] ?? '', 'provider' => $decoded['provider'] ?? ''];
}

$tests = [
    // --- PLATE questions from PLATE_MANAGEMENT_KNOWLEDGE.md ---
    '/plate 1. Search by Plate Number',
    '/plate মোট কতগুলো প্লেট আছে?',
    '/plate P-101 এর সাইজ কত?',
    '/plate 104 T সিলিন্ডারের কয়টি প্লেট আছে?',
    '/plate কোন কোন জবে 80 সিলিন্ডার ব্যবহার হয়?',
    '/plate Chromo পেপারের জন্য কোন কোন প্লেট তৈরি আছে?',
    '/plate পেপার সাইজ 200mm এর প্লেটগুলো দেখাও',
    '/plate P-101 প্লেটে মোট কয়টি কালার ব্যবহার হয়েছে?',
    '/plate কোন প্লেটগুলোতে ৪টির বেশি কালার আছে?',
    '/plate 150mm রিপিটের প্লেটগুলো দেখাও',
    '/plate P-101 এর রিপিট 152.4mm, এর জন্য কত দাঁতের সিলিন্ডার লাগবে?',
    '/plate P-101 প্লেট দিয়ে 2000 মিটার রোলে কয়টি লেবেল প্রিন্ট হবে?',
    '/plate Amul লেবেলের ১ লাখ পিস প্রিন্ট করতে কত মিটার পেপার লাগবে?',
    '/plate সবচেয়ে নতুন প্লেটটি কী?',
    '/plate Flat Bed প্লেট দেখাও',
    '/plate Rotary প্লেট দেখাও',
    // --- Real-data tests (plate data actually exists for these) ---
    '/plate 1065 প্লেট দেখাও',
    '/plate Chiring 500ml দেখাও',
    '/plate 9 inch সিলিন্ডারের প্লেট দেখাও',
    '/plate 152.4 রিপিটের প্লেটগুলো দেখাও',
    '/plate Sun Cream Toast লেবেলের ১ লাখ পিস প্রিন্ট করতে কত মিটার পেপার লাগবে?',
    '/plate 152.4mm রিপিটের জন্য কত দাঁতের সিলিন্ডার লাগবে?',
    // --- Comparison with paper stock ---
    '/paperstock মোট কতগুলো পেপার রোল আছে?',
    '/paperstock Krishna কোম্পানির কত রোল আছে?',
    // --- Export tests (verify export buttons, not dashboard) ---
    '/plate give me pdf all plate details',
    '/plate all plates excel export',
    '/plate 1065 pdf report',
    '/plate Sun Cream Toast excel download',
];

foreach ($tests as $t) {
    echo "════════════════════════════════════════════════\n";
    echo "Q: {$t}\n";
    $r = askAgent($t);
    echo "HTTP: {$r['http']} | Tool: {$r['tool']} | Provider: {$r['provider']}\n";
    if (isset($r['answer'])) {
        echo "A: " . substr($r['answer'], 0, 900) . "\n";
    } else {
        echo "ERR: " . ($r['error'] ?? '') . "\nRAW: " . ($r['raw'] ?? '') . "\n";
    }
    echo "\n";
}
