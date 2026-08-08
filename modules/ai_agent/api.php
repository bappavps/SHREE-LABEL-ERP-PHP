<?php
// ============================================================
// Standalone AI Agent Add-On Module — API Engine (Multilingual & Industrial Label Math)
// ERP Master System — 100% Isolated Add-On Module
// LOCAL USE ONLY — SAFE: Read-Only Data Connectors for ERP Tables
// ============================================================

// Force invalidate OPcache for this file on change
if (function_exists('opcache_invalidate')) {
    opcache_invalidate(__FILE__, true);
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/functions.php';

// Include Modular AI Services
require_once __DIR__ . '/services/Logger.php';
require_once __DIR__ . '/services/FeatureFlags.php';
require_once __DIR__ . '/services/MemoryManager.php';
require_once __DIR__ . '/services/ToolRouter.php';
require_once __DIR__ . '/services/CalculationEngine.php';
require_once __DIR__ . '/services/PromptBuilder.php';
require_once __DIR__ . '/services/ProviderManager.php';
require_once __DIR__ . '/services/LLMClient.php';
require_once __DIR__ . '/services/RetrievalEngine.php';
require_once __DIR__ . '/services/AliasResolver.php';
require_once __DIR__ . '/PaperStockAnalyzer.php';
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

ob_start(); // Buffer any accidental output (notices, blanks)

set_exception_handler(function ($e) {
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'answer' => "⚠️ **Error Details:** " . $e->getMessage() . " in `" . basename($e->getFile()) . "` (Line " . $e->getLine() . ")\n\nPlease try again or rephrase your command.",
        'provider' => 'ERP System',
        'tool_used' => 'Exception Handler'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    if (error_reporting() === 0)
        return false;
    if (in_array($errno, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_WARNING, E_USER_WARNING])) {
        return true;
    }
    if (ob_get_level() > 0) ob_end_clean();
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'answer' => "⚠️ **Internal Error Details:** " . $errstr . " in `" . basename($errfile) . "` (Line " . $errline . ")",
        'provider' => 'ERP System',
        'tool_used' => 'Error Handler'
    ], JSON_UNESCAPED_UNICODE);
    exit;
});
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (isset($_COOKIE['erp_user_id'])) {
    $_SESSION['user_id'] = $_COOKIE['erp_user_id'];
    $_SESSION['user_role'] = $_COOKIE['erp_user_role'];
} else {
    // echo json_encode(['ok' => false, 'error' => 'Not authenticated. Please log in.']);
    // exit;
}
;

$db = getDB();
$config = getAiAgentConfig();
$action = trim($_REQUEST['action'] ?? '');
$prompt = trim($_REQUEST['prompt'] ?? '');

// ─── Alias Resolution — MUST run first, before any handler or $p derivation ───
// Resolves e.g. "mriganka" → "Mriganka Bhusan Debnath" so every subsequent
// handler (greeting check, KB lookup, RetrievalEngine) sees the canonical name.
if (!in_array($action, ['autocomplete', 'get_quick_actions', 'save_quick_action', 'delete_quick_action'])) {
    $aliasResolver = new AliasResolver($db);
    $prompt = $aliasResolver->resolve($prompt);
}


if (!in_array($action, ['query', 'autocomplete', 'get_quick_actions', 'save_quick_action', 'delete_quick_action'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

// ─── Quick Actions CRUD (Server-Side Favorites) ───
if ($action === 'get_quick_actions') {
    $result = $db->query("SELECT `id`, `prompt`, `created_at` FROM `ai_agent_quick_actions` ORDER BY `created_at` DESC");
    $items = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }
        $result->free();
    }
    echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_quick_action') {
    $qaPrompt = trim($_REQUEST['prompt'] ?? '');
    if ($qaPrompt === '') {
        echo json_encode(['ok' => false, 'error' => 'Empty prompt']);
        exit;
    }
    // Upsert — ignore if duplicate
    $stmt = $db->prepare("INSERT IGNORE INTO `ai_agent_quick_actions` (`prompt`) VALUES (?)");
    $stmt->bind_param('s', $qaPrompt);
    $stmt->execute();
    $newId = $stmt->insert_id;
    $stmt->close();
    echo json_encode(['ok' => true, 'id' => (int)$newId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'delete_quick_action') {
    $qaId = intval($_REQUEST['id'] ?? 0);
    if ($qaId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid id']);
        exit;
    }
    $stmt = $db->prepare("DELETE FROM `ai_agent_quick_actions` WHERE `id` = ? LIMIT 1");
    $stmt->bind_param('i', $qaId);
    $stmt->execute();
    $stmt->close();
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// An empty prompt is only rejected for regular queries — for the autocomplete
// action an empty prompt means "browse all" (return every job/plate).
if ($prompt === '' && $action !== 'autocomplete') {
    echo json_encode(['ok' => false, 'error' => 'Empty prompt query']);
    exit;
}

if ($action === 'autocomplete') {
    // Autocomplete for master_plate_data
    $searchTerm = trim($prompt);
    if ($searchTerm === '') {
        // Browse-all mode: every job/plate, ordered exactly like Plate Management
        $allStmt = $db->query("SELECT `name`, `size` FROM `master_plate_data` ORDER BY CASE WHEN TRIM(COALESCE(sl_no, '')) REGEXP '^[0-9]+$' THEN CAST(TRIM(sl_no) AS UNSIGNED) ELSE 2147483647 END ASC, id ASC");
        $allSuggestions = [];
        if ($allStmt instanceof mysqli_result) {
            while ($row = $allStmt->fetch_assoc()) {
                $allSuggestions[] = ['name' => $row['name'], 'size' => $row['size']];
            }
        }
        echo json_encode(['ok' => true, 'suggestions' => $allSuggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Convert multiple spaces to a wildcard for the LIKE search
    $plateSearch = preg_replace('/\s+/', '%', $searchTerm);

    // Prefix search first for better relevancy, then a broad 'contains' fallback.
    // No LIMIT cap — a typed term shows ALL matching jobs (scrollable), exactly
    // like the empty-quote browse-all mode.
    $prefixSearch = $plateSearch . '%';
    $broadSearch = '%' . $plateSearch . '%';

    $results = [];
    $stmt = $db->prepare("SELECT `name`, `size` FROM `master_plate_data` WHERE `name` LIKE ? ORDER BY `name` ASC");
    if ($stmt) {
        $stmt->bind_param("s", $prefixSearch);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $results[] = $row;
        }
    }

    // If not enough prefix results, add broad 'contains' matches
    if (count($results) < 5) {
        $stmt2 = $db->prepare("SELECT `name`, `size` FROM `master_plate_data` WHERE `name` LIKE ? AND `name` NOT LIKE ? ORDER BY `name` ASC");
        if ($stmt2) {
            $stmt2->bind_param("ss", $broadSearch, $prefixSearch);
            $stmt2->execute();
            $res2 = $stmt2->get_result();
            while ($row = $res2->fetch_assoc()) {
                $results[] = $row;
            }
        }
    }

    $suggestions = [];
    foreach ($results as $r) {
        $suggestions[] = [
            'name' => $r['name'],
            'size' => $r['size']
        ];
    }

    echo json_encode(['ok' => true, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Detect Language (English / Bengali / Hindi)
 */
function detect_language(string $prompt): string
{
    $reqLang = strtolower(trim($_REQUEST['user_lang'] ?? ''));
    if ($reqLang === 'hindi' || $reqLang === 'hi-in' || $reqLang === 'hi') {
        return 'Hindi';
    }
    if ($reqLang === 'bengali' || $reqLang === 'bn-in' || $reqLang === 'bn') {
        return 'Bengali';
    }
    if ($reqLang === 'english' || $reqLang === 'en-us' || $reqLang === 'en') {
        return 'English';
    }

    $p = mb_strtolower($prompt, 'UTF-8');

    // 1. Bengali Script Detection
    if (preg_match('/[\x{0980}-\x{09FF}]/u', $prompt)) {
        return 'Bengali';
    }

    // 2. Hindi Script Detection
    if (preg_match('/[\x{0900}-\x{097F}]/u', $prompt)) {
        return 'Hindi';
    }

    // 3. Banglish Keywords Detection
    if (preg_match('/\b(koto|kotogulo|ache|kono|kivabe|korbo|bhai|bhalo|bolun|din|kon|lagbe|hobe|dam|seba|seta|amar|amake|dekhaw|bolte|kholo|khul)\b/i', $p)) {
        return 'Bengali';
    }

    // 4. Hinglish Keywords Detection
    if (preg_match('/\b(kitne|kitna|hai|kaise|karo|dekho|batao|kya|mujhe|bataye|dikhao)\b/i', $p)) {
        return 'Hindi';
    }

    // Default to English
    return 'English';
}


/**
 * Extract Numbers / Specific Identifiers from Prompt
 */
function extract_search_numbers(string $prompt): array
{
    preg_match_all('/\b\d+\b/', $prompt, $matches);
    return $matches[0] ?? [];
}

/**
 * Format database records into a clean markdown table
 */
function format_records_table(array $data, string $type = 'paper_stock', string $lang = 'English', ?int $totalCount = null, int $jumboRolls = 0, int $slittedRolls = 0, ?float $totalMeters = null, ?float $totalSqmOverride = null): string
{
    if (empty($data))
        return '';
    $count = count($data);
    $displayCount = $totalCount ?? $count;
    $hasJumbo = $jumboRolls > 0 || $slittedRolls > 0;
    $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

    if ($type === 'paper_stock') {
        // Summary totals — use the full filtered-set totals when provided,
        // otherwise fall back to summing the sample rows.
        $totalMtr = 0;
        $totalSqm = 0;
        foreach ($data as $r) {
            $totalMtr += (float) ($r['length_mtr'] ?? 0);
            $totalSqm += (float) ($r['sqm'] ?? 0);
        }
        if ($totalMeters !== null)
            $totalMtr = (float) $totalMeters;
        if ($totalSqmOverride !== null)
            $totalSqm = (float) $totalSqmOverride;

        if ($lang === 'English') {
            $tbl = "| # | Roll No | Type | Company | Width | Length | SQM | Status |\n";
            $tbl .= "|--:|---------|------|---------|------:|-------:|----:|--------|\n";
            foreach ($data as $i => $r) {
                $tbl .= "| " . ($i + 1)
                    . " | `" . ($r['roll_no'] ?: 'ID-' . $r['id']) . "`"
                    . " | " . ($r['paper_type'] ?? '-')
                    . " | " . ($r['company'] ?? '-')
                    . " | " . ($r['width_mm'] ? number_format((float) $r['width_mm'], 0) . 'mm' : '-')
                    . " | " . ($r['length_mtr'] ? number_format((float) $r['length_mtr'], 1) . 'm' : '-')
                    . " | " . ($r['sqm'] ? number_format((float) $r['sqm'], 1) : '-')
                    . " | " . ($r['status'] ?? '-') . " |\n";
            }
            $tbl .= "\n**Total: {$displayCount} rolls** | **" . number_format($totalMtr, 1) . " meters** | **" . number_format($totalSqm, 1) . " SQM**\n";
            if ($hasJumbo) {
                $tbl .= "\n🏭 **Jumbo Parent Rolls (≥1000mm):** {$jumboRolls} | **Slitted Stock (<1000mm):** {$slittedRolls}\n";
            }
            $tbl .= "\n👉 [Open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
        } elseif ($lang === 'Hindi') {
            $tbl = "| # | रोल नं. | टाइप | कंपनी | चौड़ाई | लंबाई | SQM | स्थिति |\n";
            $tbl .= "|--:|--------|------|--------|------:|-------:|----:|--------|\n";
            foreach ($data as $i => $r) {
                $tbl .= "| " . ($i + 1)
                    . " | `" . ($r['roll_no'] ?: 'ID-' . $r['id']) . "`"
                    . " | " . ($r['paper_type'] ?? '-')
                    . " | " . ($r['company'] ?? '-')
                    . " | " . ($r['width_mm'] ? number_format((float) $r['width_mm'], 0) . 'mm' : '-')
                    . " | " . ($r['length_mtr'] ? number_format((float) $r['length_mtr'], 1) . 'm' : '-')
                    . " | " . ($r['sqm'] ? number_format((float) $r['sqm'], 1) : '-')
                    . " | " . ($r['status'] ?? '-') . " |\n";
            }
            $tbl .= "\n**कुल: {$displayCount} रोल** | **" . number_format($totalMtr, 1) . " मीटर** | **" . number_format($totalSqm, 1) . " SQM**\n";
            if ($hasJumbo) {
                $tbl .= "\n🏭 **जंबो पैरेंट (≥1000mm):** {$jumboRolls} | **स्लिटेड स्टॉक (<1000mm):** {$slittedRolls}\n";
            }
            $tbl .= "\n👉 [पेपर स्टक पेज खोलें]({$baseUrl}/modules/paper_stock/index.php)";
        } else {
            $tbl = "| # | রোল নং | টাইপ | কোম্পানি | চওড়া | দৈর্ঘ্য | SQM | স্ট্যাটাস |\n";
            $tbl .= "|--:|--------|------|----------|------:|-------:|----:|----------|\n";
            foreach ($data as $i => $r) {
                $tbl .= "| " . ($i + 1)
                    . " | `" . ($r['roll_no'] ?: 'ID-' . $r['id']) . "`"
                    . " | " . ($r['paper_type'] ?? '-')
                    . " | " . ($r['company'] ?? '-')
                    . " | " . ($r['width_mm'] ? number_format((float) $r['width_mm'], 0) . 'mm' : '-')
                    . " | " . ($r['length_mtr'] ? number_format((float) $r['length_mtr'], 1) . 'm' : '-')
                    . " | " . ($r['sqm'] ? number_format((float) $r['sqm'], 1) : '-')
                    . " | " . ($r['status'] ?? '-') . " |\n";
            }
            $tbl .= "\n**মোট: {$displayCount}টি রোল** | **" . number_format($totalMtr, 1) . " মিটার** | **" . number_format($totalSqm, 1) . " SQM**\n";
            if ($hasJumbo) {
                $tbl .= "\n🏭 **জাম্বো প্যারেন্ট (≥1000mm):** {$jumboRolls} | **স্লিটেড স্টক (<1000mm):** {$slittedRolls}\n";
            }
            $tbl .= "\n👉 [পেপার স্টক পেজ খুলুন]({$baseUrl}/modules/paper_stock/index.php)";
        }
        return $tbl;
    }

    if ($type === 'plate') {
        if ($lang === 'English') {
            $tbl = "| # | Plate Name | SL No | Repeat | Gap H | Gap V | Size | Ups | Paper Type | Die |\n";
            $tbl .= "|--:|------------|-------|-------:|------:|------:|------|----:|------------|-----|\n";
            foreach ($data as $i => $r) {
                $tbl .= "| " . ($i + 1)
                    . " | `" . ($r['name'] ?? '-') . "`"
                    . " | " . ($r['sl_no'] ?? '-')
                    . " | " . ($r['repeat_value'] ?? '-')
                    . " | " . ($r['gap_h'] ?? '0')
                    . " | " . ($r['gap_v'] ?? '0')
                    . " | " . ($r['size'] ?? '-')
                    . " | " . ($r['ups'] ?? '1')
                    . " | " . ($r['paper_type'] ?? '-')
                    . " | " . ($r['die'] ?? '-') . " |\n";
            }
            $tbl .= "\n**Total: {$count} plates**\n";
        } else {
            $tbl = "| # | প্লেট নাম | SL No | রিপিট | Gap H | Gap V | সাইজ | আফস | কাগজ | ডাই |\n";
            $tbl .= "|--:|----------|-------|------:|------:|------:|------|----:|------|-----|\n";
            foreach ($data as $i => $r) {
                $tbl .= "| " . ($i + 1)
                    . " | `" . ($r['name'] ?? '-') . "`"
                    . " | " . ($r['sl_no'] ?? '-')
                    . " | " . ($r['repeat_value'] ?? '-')
                    . " | " . ($r['gap_h'] ?? '0')
                    . " | " . ($r['gap_v'] ?? '0')
                    . " | " . ($r['size'] ?? '-')
                    . " | " . ($r['ups'] ?? '1')
                    . " | " . ($r['paper_type'] ?? '-')
                    . " | " . ($r['die'] ?? '-') . " |\n";
            }
            $tbl .= "\n**কুল: {$count} প্লেট**\n";
        }
        return $tbl;
    }

    // Generic fallback table
    $keys = array_keys($data[0]);
    $showKeys = array_slice($keys, 0, 7);
    if ($lang === 'English') {
        $header = '| # | ' . implode(' | ', array_map(function ($k) {
            return ucwords(str_replace('_', ' ', $k));
        }, $showKeys)) . " |\n";
        $sep = '|--:|' . implode('|', array_fill(0, count($showKeys), '---')) . "|\n";
        $tbl = $header . $sep;
        foreach ($data as $i => $r) {
            $cols = [];
            foreach ($showKeys as $k) {
                $cols[] = $r[$k] ?? '-';
            }
            $tbl .= '| ' . ($i + 1) . ' | ' . implode(' | ', $cols) . " |\n";
        }
    } else {
        $header = '| # | ' . implode(' | ', array_map(function ($k) {
            return ucwords(str_replace('_', ' ', $k));
        }, $showKeys)) . " |\n";
        $sep = '|--:|' . implode('|', array_fill(0, count($showKeys), '---')) . "|\n";
        $tbl = $header . $sep;
        foreach ($data as $i => $r) {
            $cols = [];
            foreach ($showKeys as $k) {
                $cols[] = $r[$k] ?? '-';
            }
            $tbl .= '| ' . ($i + 1) . ' | ' . implode(' | ', $cols) . " |\n";
        }
    }
    return $tbl;
}

/**
 * Detect if user is asking a count/total question (e.g. "how many plates?", "total dies?", "kitne anilox hai?")
 */
function is_count_intent(string $prompt): bool
{
    $p = mb_strtolower($prompt, 'UTF-8');
    return (bool) preg_match('/\b(how many|how much|total|count|kitne|kitna|koto|kotogulo|কত|কতগুলো|कितने|कितना)\b/i', $p);
}

/**
 * Generate 5 contextual suggestions based on the user's question and the ERP/KB answer.
 * Extracts topic keywords and builds relevant follow-up questions.
 */
function generate_erp_suggestions(string $prompt, string $answer, string $toolUsed, string $userLang = 'English'): array
{
    $p = mb_strtolower($prompt, 'UTF-8');
    $a = mb_strtolower($answer, 'UTF-8');
    $suggestions = [];

    // Detect key entities from prompt and answer
    $hasPlate = preg_match('/(plate|প্লেট|प्लेट)/iu', $p . ' ' . $a);
    $hasPaper = preg_match('/(paper|roll|stock|রোল|পেপার|पेपर|रोल)/iu', $p . ' ' . $a);
    $hasJob = preg_match('/(job|জব|जॉब)/iu', $p . ' ' . $a);
    $hasDispatch = preg_match('/(dispatch|ডিস্পৈচ|डिस्पैच)/iu', $p . ' ' . $a);
    $hasCompany = preg_match('/(krishna|austin|navkar|nrgi|pidilite|sfl|alpha|flex)/iu', $p . ' ' . $a);
    $hasCount = preg_match('/(how many|total|count|কত|कितने|कितना)/iu', $p);
    $hasCalculation = preg_match('/(sqm|sqr? mtr|square|weight|gsm|label|mm\s*[xX*]\s*mm)/iu', $p);
    $hasKnowledge = strpos($toolUsed, 'Knowledge Base') !== false;
    $hasCalculator = strpos($toolUsed, 'Calculator') !== false || strpos($toolUsed, 'Converter') !== false;

    // Extract a key term for targeted follow-ups
    $keyTerm = '';
    if (preg_match('/["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/u', $prompt, $qm)) {
        $keyTerm = trim($qm[1]);
    } elseif ($hasCompany && preg_match('/\b(krishna|austin|navkar|nrgi|pidilite|sfl|alpha|flex)\b/i', $p, $cm)) {
        $keyTerm = ucfirst(strtolower($cm[1]));
    }

    if ($hasKnowledge) {
        // Knowledge Base — suggest related FAQs
        if ($userLang === 'Bengali') {
            $suggestions = ['এই বিষয়ে আরও বিস্তারিত বলুন', 'আরেকটি FAQ দেখুন', 'প্লেট সম্পর্কে জানতে চাই', 'পেপার স্টক সম্পর্কে জানতে চাই', 'ERP হেল্প দেখুন'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['इस विषय पर और बताएं', 'कोई अन्य FAQ देखें', 'प्लेट के बारे में जानें', 'पेपर स्टॉक के बारे में जानें', 'ERP सहायता देखें'];
        } else {
            $suggestions = ['Tell me more about this topic', 'Show another FAQ', 'Learn about plates', 'Learn about paper stock', 'View ERP help'];
        }
    } elseif ($hasCalculator) {
        // Calculator — suggest related calculations
        if ($userLang === 'Bengali') {
            $suggestions = ['ভিন্ন সাইজে ক্যালকুলেশন করুন', '/cal 200mm x 300mm', 'SQM রেট ক্যালকুলেট করুন', 'ওজন ক্যালকুলেট করুন', 'ইঞ্চিতে এরিয়া বের করুন'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['अलग साइज़ में गणना करें', '/cal 200mm x 300mm', 'SQM रेट गणना करें', 'वज़न गणना करें', 'इंच में एरिया निकालें'];
        } else {
            $suggestions = ['Calculate with different size', '/cal 200mm x 300mm', 'Calculate SQM rate', 'Calculate weight', 'Find area in inches'];
        }
    } elseif ($hasPlate) {
        if ($userLang === 'Bengali') {
            $suggestions = ['মোট কতগুলো প্লেট আছে?', $hasCompany ? "$keyTerm কোম্পানির প্লেট দেখাও" : 'Flat Bed প্লেট দেখাও', 'Rotary প্লেট দেখাও', 'সবচেয়ে নতুন প্লেটটি কী?', 'প্লেট ম্যানেজমেন্ট পেজ খোলো'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['कुल कितनी प्लेट हैं?', $hasCompany ? "$keyTerm कंपनी की प्लेट दिखाओ" : 'Flat Bed प्लेट दिखाओ', 'Rotary प्लेट दिखाओ', 'सबसे नई प्लेट कौन सी है?', 'प्लेट पेज खोलो'];
        } else {
            $suggestions = ['Total number of plates', $hasCompany ? "Show $keyTerm company plates" : 'Show Flat Bed plates', 'Show Rotary plates', 'Latest plate added', 'Open Plate Management page'];
        }
    } elseif ($hasPaper) {
        if ($userLang === 'Bengali') {
            $suggestions = ['মোট কতগুলো পেপার রোল আছে?', $hasCompany ? "$keyTerm কোম্পানির রোল দেখাও" : 'Chromo পেপার দেখাও', 'PP White স্টক দেখাও', 'মোট রানিং মিটার কত?', 'পেপার স্টক পেজ খোলো'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['कुल कितने पेपर रोल हैं?', $hasCompany ? "$keyTerm कंपनी के रोल दिखाओ" : 'Chromo पेपर दिखाओ', 'PP White स्टॉक दिखाओ', 'कुल रनिंग मीटर कितना?', 'पेपर स्टॉक पेज खोलो'];
        } else {
            $suggestions = ['Total paper rolls in stock', $hasCompany ? "Show $keyTerm company rolls" : 'Show Chromo paper rolls', 'Show PP White stock', 'Total running meters', 'Open Paper Stock page'];
        }
    } elseif ($hasJob) {
        if ($userLang === 'Bengali') {
            $suggestions = ['পেন্ডিং জব দেখাও', 'লাইভ ফ্লোর স্ট্যাটাস দেখাও', 'আজকের ডিস্পৈচ দেখাও', 'জব প্ল্যানিং পেজ খোলো', 'প্রোডাকশন সামারি দেখাও'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['पेंडिंग जॉब दिखाओ', 'लाइव फ्लोर स्टेटस दिखाओ', 'आज का डिस्पैच दिखाओ', 'जॉब प्लानिंग पेज खोलो', 'प्रोडक्शन समरी दिखाओ'];
        } else {
            $suggestions = ['Show pending jobs', 'Show live floor status', 'Show today\'s dispatch', 'Open Job Planning page', 'Show production summary'];
        }
    } elseif ($hasDispatch) {
        if ($userLang === 'Bengali') {
            $suggestions = ['আজকের ডিস্পৈচ দেখাও', 'পেন্ডিং ডিস্পৈচ দেখাও', 'প্যাকিং স্লিপ দেখাও', 'ডিস্পৈচ পেজ খোলো', 'আজকের প্রোডাকশন দেখাও'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['आज का डिस्पैच दिखाओ', 'पेंडिंग डिस्पैच दिखाओ', 'पैकिंग स्लिप दिखाओ', 'डिस्पैच पेज खोलो', 'आज का प्रोडक्शन दिखाओ'];
        } else {
            $suggestions = ['Show today\'s dispatch', 'Show pending dispatches', 'Show packing slip', 'Open Dispatch page', 'Show today\'s production'];
        }
    } else {
        // Generic ERP suggestions
        if ($userLang === 'Bengali') {
            $suggestions = ['মোট কতগুলো প্লেট আছে?', 'মোট কতগুলো পেপার রোল?', 'পেন্ডিং জব দেখাও', 'আজকের ডিস্পৈচ দেখাও', 'প্রোডাকশন সামারি দেখাও'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['कुल कितनी प्लेट हैं?', 'कुल कितने पेपर रोल?', 'पेंडिंग जॉब दिखाओ', 'आज का डिस्पैच दिखाओ', 'प्रोडक्शन समरी दिखाओ'];
        } else {
            $suggestions = ['Total number of plates', 'Total paper rolls in stock', 'Show pending jobs', 'Show today\'s dispatch', 'Show production summary'];
        }
    }

    return $suggestions;
}

/**
 * Get context-aware follow-up suggestion questions based on the tool used and query context.
 * Returns an array of suggestion strings the user can click to ask next.
 */
function get_follow_up_suggestions(string $toolUsed, string $prompt, string $userLang = 'English'): array
{
    $p = mb_strtolower($prompt, 'UTF-8');
    $suggestions = [];

    if (strpos($toolUsed, 'Plate') !== false || strpos($toolUsed, 'Flexo') !== false) {
        if (is_count_intent($prompt)) {
            // After count query, suggest detail/filter queries
            if ($userLang === 'Hindi') {
                $suggestions = [
                    'Flat Bed प्लेट कितनी हैं?',
                    'Rotary प्लेट कितनी हैं?',
                    'Alpha Flex की प्लेट दिखाओ',
                    'सबसे नई प्लेट कौन सी है?',
                    'प्लेट पेज खोलो',
                ];
            } elseif ($userLang === 'Bengali') {
                $suggestions = [
                    'Flat Bed প্লেট কতগুলো?',
                    'Rotary প্লেট কতগুলো?',
                    'Alpha Flex এর প্লেট দেখাও',
                    'সর্বশেষ প্লেটটি কী?',
                    'প্লেট পেজ খুলুন',
                ];
            } else {
                $suggestions = [
                    'Show all Flat Bed plates',
                    'Show all Rotary plates',
                    'Show Alpha Flex plates',
                    'What is the latest plate added?',
                    'Open Plate Management page',
                ];
            }
        } else {
            // After detail/search query, suggest related actions
            if ($userLang === 'Hindi') {
                $suggestions = [
                    'कुल कितनी प्लेट हैं?',
                    'इस प्लेट का मीटर कैलकुलेट करो',
                    'Chromo पेपर वाली प्लेट दिखाओ',
                    '9 inch सिलेंडर प्लेट दिखाओ',
                    'प्लेट पेज खोलो',
                ];
            } elseif ($userLang === 'Bengali') {
                $suggestions = [
                    'মোট কতগুলো প্লেট আছে?',
                    'ইস প্লেটের মিটার ক্যালকুলেট করো',
                    'Chromo পেপারের প্লেট দেখাও',
                    '9 inch সিলিন্ডার প্লেট দেখাও',
                    'প্লেট পেজ খুলুন',
                ];
            } else {
                $suggestions = [
                    'How many plates do we have?',
                    'Calculate meters for this plate',
                    'Show Chromo paper plates',
                    'Show 9 inch cylinder plates',
                    'Open Plate Management page',
                ];
            }
        }
    } elseif (strpos($toolUsed, 'Die') !== false) {
        if ($userLang === 'English') {
            $suggestions = [
                'How many dies do we have?',
                'Show Rotary dies',
                'Show Flat Bed dies',
                'Open Die Management page',
            ];
        } elseif ($userLang === 'Hindi') {
            $suggestions = [
                'कुल कितनी डाई हैं?',
                'Rotary डाई दिखाओ',
                'Flat Bed डाई दिखाओ',
                'डाई पेज खोलो',
            ];
        } else {
            $suggestions = [
                'মোট কতগুলো ডাই আছে?',
                'Rotary ডাই দেখাও',
                'Flat Bed ডাই দেখাও',
                'ডাই পেজ খুলুন',
            ];
        }
    } elseif (strpos($toolUsed, 'Anilox') !== false) {
        if ($userLang === 'English') {
            $suggestions = [
                'How many anilox rolls do we have?',
                'Show 400 LPI anilox',
                'Which anilox are out of stock?',
                'Open Anilox Management page',
            ];
        } elseif ($userLang === 'Hindi') {
            $suggestions = [
                'कुल कितने एनिलॉक्स हैं?',
                '400 LPI एनिलॉक्स दिखाओ',
                'कौन सा एनिलॉक्स स्टॉक में नहीं?',
                'एनिलॉक्स पेज खोलो',
            ];
        } else {
            $suggestions = [
                'মোট কতগুলো এনিলক্স আছে?',
                '400 LPI এনিলক্স দেখাও',
                'কোন এনিলক্স স্টকে নেই?',
                'এনিলক্স পেজ খুলুন',
            ];
        }
    } elseif (strpos($toolUsed, 'Paper') !== false || strpos($toolUsed, 'Roll') !== false) {
        if ($userLang === 'English') {
            $suggestions = [
                'How many paper rolls in stock?',
                'Show Chromo paper rolls',
                'Show PP White paper stock',
                'Open Paper Stock page',
            ];
        } elseif ($userLang === 'Hindi') {
            $suggestions = [
                'कुल कितने पेपर रोल हैं?',
                'Chromo पेपर दिखाओ',
                'PP White स्टॉक दिखाओ',
                'पेपर स्टक पेज खोलो',
            ];
        } else {
            $suggestions = [
                'মোট কতগুলো পেপার রোল আছে?',
                'Chromo পেপার দেখাও',
                'PP White স্টক দেখাও',
                'পেপার স্টক পেজ খুলুন',
            ];
        }
    } elseif (strpos($toolUsed, 'Dispatch') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show today\'s dispatch', 'Show pending dispatches', 'Open Dispatch page'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['आज का डिस्पैच दिखाओ', 'पेंडिंग डिस्पैच दिखाओ', 'डिस्पैच पेज खोलो'];
        } else {
            $suggestions = ['আজকের ডিস্পৈচ দেখাও', 'পেন্ডিং ডিস্পৈচ দেখাও', 'ডিস্পৈচ পেজ খোলুন'];
        }
    } elseif (strpos($toolUsed, 'Dashboard') !== false || strpos($toolUsed, 'KPI') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show production summary', 'Show today\'s dispatch', 'Show pending jobs'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['प्रोडक्शन समरी दिखाओ', 'आज का डिस्पैच दिखाओ', 'पेंडिंग जॉब दिखाओ'];
        } else {
            $suggestions = ['প্রোডাকশন সামারি দেখাও', 'আজকের ডিস্পৈচ দেখাও', 'পেন্ডিং জব দেখাও'];
        }
    } elseif (strpos($toolUsed, 'Job') !== false || strpos($toolUsed, 'Planning') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show pending jobs', 'Show live floor status', 'Open Job Planning page'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['पेंडिंग जॉब दिखाओ', 'लाइव फ्लोर स्टेटस दिखाओ', 'जॉब प्लानिंग पेज खोलो'];
        } else {
            $suggestions = ['পেন্ডিং জব দেখাও', 'লাইভ ফ্লোর স্ট্যাটাস দেখাও', 'জব প্ল্যানিং পেজ খোলো'];
        }
    }

    return $suggestions;
}

// Live Summary Handler
function getLiveSummary(): string
{
    $db = function_exists('getDB') ? getDB() : null;
    if (!$db) {
        return 'Database connection failed.';
    }

    $sql = "SELECT department, status FROM jobs WHERE deleted_at IS NULL OR deleted_at = '0000-00-00 00:00:00'";
    $res = $db->query($sql);

    $totalActive = 0;
    $totalRunning = 0;

    // Departments
    $jumbo = 0;
    $flexo = 0;
    $die = 0;
    $packing = 0;

    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $s = strtolower(trim($row['status'] ?? ''));
            $dept = strtolower(trim($row['department'] ?? ''));

            // Except finished tab jobs
            $finishedStatuses = ['completed', 'dispatched', 'delivered', 'packing done', 'packed', 'finished production', 'finished barcode', 'finished label'];
            if (in_array($s, $finishedStatuses, true)) {
                continue;
            }

            $totalActive++;
            if ($s === 'running') {
                $totalRunning++;
            }

            if (strpos($dept, 'jumbo') !== false || strpos($dept, 'slit') !== false) {
                $jumbo++;
            } elseif (strpos($dept, 'print') !== false || strpos($dept, 'flexo') !== false) {
                $flexo++;
            } elseif (strpos($dept, 'die') !== false || strpos($dept, 'flat') !== false || strpos($dept, 'rotary') !== false || strpos($dept, 'barcode') !== false) {
                $die++;
            } elseif (strpos($dept, 'pack') !== false || strpos($dept, 'pos') !== false || strpos($dept, 'paperroll') !== false || strpos($dept, 'dispatch') !== false) {
                $packing++;
            } else {
                // If it is 'planning' or something else, we can decide where to put it or just count it in total
            }
        }
    }

    return sprintf(
        "📊 **Live Production Floor Summary**\n" .
        "Total Active Jobs: **%d** (Running: %d)\n\n" .
        "**Machine / Department Status:**\n" .
        "🔹 Jumbo Slitting: **%d** jobs\n" .
        "🔹 Flexo Printing: **%d** jobs\n" .
        "🔹 Die Cutting / Barcode: **%d** jobs\n" .
        "🔹 Packing / POS Roll: **%d** jobs",
        $totalActive,
        $totalRunning,
        $jumbo,
        $flexo,
        $die,
        $packing
    );
}

/**
 * Call external LLM API (Google Gemini, OpenAI, OpenRouter, OpenCode, Local LLM) with fallback support
 */
function check_navigation_intent(string $prompt): ?array
{
    $p = strtolower($prompt);
    $isDataQuery = strpos($p, 'check') !== false || strpos($p, 'stage') !== false || strpos($p, 'status') !== false || strpos($p, 'detail') !== false || strpos($p, 'time') !== false || strpos($p, 'summary') !== false || strpos($p, 'sumary') !== false || strpos($p, 'breakdown') !== false || strpos($p, 'report') !== false;

    if ($isDataQuery)
        return null;

    $isExplicitNav = (strpos($p, 'open') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false || strpos($p, 'navigate') !== false || strpos($p, 'page') !== false);
    if (!$isExplicitNav)
        return null;



    $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

    if (strpos($p, 'live') !== false || strpos($p, 'floor') !== false) {
        return ['name' => 'Live Production Floor', 'url' => $baseUrl . '/modules/live/index.php'];
    } elseif (strpos($p, 'slit') !== false) {
        return ['name' => 'Slitting Terminal', 'url' => $baseUrl . '/modules/inventory/slitting/index.php'];
    } elseif (strpos($p, 'dispatch') !== false) {
        return ['name' => 'Dispatch Module', 'url' => $baseUrl . '/modules/dispatch/index.php'];
    } elseif (strpos($p, 'paper') !== false || strpos($p, 'roll stock') !== false) {
        return ['name' => 'Paper Stock', 'url' => $baseUrl . '/modules/paper_stock/index.php'];
    } elseif (strpos($p, 'finished') !== false || strpos($p, 'packing stock') !== false || strpos($p, 'inventory') !== false) {
        return ['name' => 'Finished Goods Stock', 'url' => $baseUrl . '/modules/inventory/finished/index.php'];
    } elseif (strpos($p, 'plan') !== false) {
        return ['name' => 'Job Planning', 'url' => $baseUrl . '/modules/planning/index.php'];
    } elseif (strpos($p, 'pack') !== false) {
        return ['name' => 'Packing Operator', 'url' => $baseUrl . '/modules/operators/packing/index.php'];
    } elseif (strpos($p, 'report') !== false) {
        return ['name' => 'Job Reports', 'url' => $baseUrl . '/modules/reports/jobs.php'];
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $fullBaseUrl = rtrim($protocol . $host . $baseUrl, '/');

    if (strpos($p, 'login') !== false || strpos($p, 'logout') !== false) {
        return ['name' => 'ERP Login', 'url' => $fullBaseUrl . '/auth/login.php'];
    } elseif (strpos($p, 'dash') !== false || strpos($p, 'home') !== false) {
        return ['name' => 'Dashboard', 'url' => $fullBaseUrl . '/modules/dashboard/index.php'];
    }

    return null;
}

// Navigation Response Check
$userLang = detect_language($prompt);
$navTarget = check_navigation_intent($prompt);
if ($navTarget !== null) {
    $navAnswer = "🚀 **Navigation Command Received:**\n\nOpening **" . htmlspecialchars($navTarget['name']) . "** page for you.\n\n👉 [Click here if page does not auto-redirect](" . htmlspecialchars($navTarget['url']) . ")";
    $suggestions = generate_erp_suggestions($prompt, $navAnswer, 'ERP Navigation Tool', $userLang);
    echo json_encode([
        'ok' => true,
        'answer' => $navAnswer,
        'provider' => 'ERP AI Navigation Engine',
        'tool_used' => 'ERP Navigation Tool',
        'nav_url' => $navTarget['url'],
        'suggestions' => $suggestions
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$p = mb_strtolower($prompt, 'UTF-8');

// ─── Greeting / Casual Chat Handler ───
$greetingWords = [
    'hi',
    'hello',
    'hey',
    'hola',
    'howdy',
    'sup',
    'good morning',
    'good afternoon',
    'good evening',
    'good night',
    'নমস্কার',
    'হ্যালো',
    'হাই',
    'শুভ সকাল',
    'শুভ বিকেল',
    'শুভ সন্ধ্যা',
    'কেমন আছেন',
    'কেমন আছো',
    'ভালো',
    'नमस्ते',
    'हेलो',
    'हाय',
    'नमस्कार',
    'सुप्रभात',
    'कैसे हो',
    'क्या हाल',
    'namaste',
    'namaskar',
    'assalamu',
    'salam',
    'kemon',
    'ki khobor',
    'kaise ho',
    'thank',
    'thanks',
    'dhonnobad',
    'ধন্যবাদ',
    'धन्यवाद',
    'shukriya'
];
$pTrimmed = trim(preg_replace('/[^a-z0-9\x{0900}-\x{09FF}\x{0980}-\x{09FF}\s]/u', '', $p));
$isGreeting = false;
foreach ($greetingWords as $gw) {
    if ($pTrimmed === $gw || strpos($pTrimmed, $gw) === 0 || preg_match('/\b' . preg_quote($gw, '/') . '\b/u', $pTrimmed)) {
        $isGreeting = true;
        break;
    }
}

if ($isGreeting) {
    $userLang = detect_language($prompt);



    $userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';

    // Check for thank you
    $isThanks = (strpos($p, 'thank') !== false || strpos($p, 'ধন্যবাদ') !== false || strpos($p, 'dhonnobad') !== false || strpos($p, 'धन्यवाद') !== false || strpos($p, 'shukriya') !== false);

    if ($isThanks) {
        if ($userLang === 'Bengali') {
            $greeting = "😊 আপনাকেও অনেক ধন্যবাদ, **{$userName}**! যেকোনো সময় আমাকে জিজ্ঞেস করতে পারেন। আমি সবসময় আপনার সাহায্যে প্রস্তুত! 🙏";
        } elseif ($userLang === 'Hindi') {
            $greeting = "😊 आपका भी बहुत-बहुत धन्यवाद, **{$userName}**! कभी भी कुछ पूछना हो तो बेझिझक पूछिए। मैं हमेशा आपकी सेवा में हूँ! 🙏";
        } else {
            $greeting = "😊 You're welcome, **{$userName}**! Feel free to ask me anything anytime. I'm always here to help! 🙏";
        }
    } else {
        $hour = (int) date('G');
        $timeGreet = $hour < 12 ? ['Good Morning', 'শুভ সকাল', 'সুপ্রভাত'] : ($hour < 17 ? ['Good Afternoon', 'শুভ বিকেল', 'शुभ दोपहर'] : ['Good Evening', 'শুভ সন্ধ্যা', 'शुभ संध्या']);

        if ($userLang === 'Bengali') {
            $greeting = "👋 **{$timeGreet[1]}, {$userName}!**\n\n"
                . "আমি আপনার **ইআরপি AI এসিস্ট্যান্ট**। আমি আপনাকে যেসব বিষয়ে সাহায্য করতে পারি:\n\n"
                . "📦 **পেপার স্টক** — যেকোনো কোম্পানির রোল, রানিং মিটার, SQM জানুন\n"
                . "🧮 **লেবেল ক্যালকুলেটর** — রানিং মিটার, ইমপ্রেশন ও কস্টিং হিসাব করুন\n"
                . "📋 **জব প্ল্যানিং** — প্ল্যানিং বোর্ড ও ডিপার্টমেন্ট স্ট্যাটাস দেখুন\n"
                . "📐 **ইউনিট কনভার্সন** — SQM ↔ SQ Inch রেট রূপান্তর করুন\n\n"
                . "💡 নিচের **Quick Action চিপস** থেকে ক্লিক করুন অথবা আপনার প্রশ্ন টাইপ/ভয়েসে বলুন!";
        } elseif ($userLang === 'Hindi') {
            $greeting = "👋 **{$timeGreet[2]}, {$userName}!**\n\n"
                . "मैं आपका **ERP AI असिस्टेंट** हूँ। मैं इन विषयों में आपकी मदद कर सकता हूँ:\n\n"
                . "📦 **पेपर स्टॉक** — किसी भी कंपनी के रोल, रनिंग मीटर, SQM जानें\n"
                . "🧮 **लेबल कैलकुलेटर** — रनिंग मीटर, इम्प्रेशन ओ लागत गणना\n"
                . "📋 **जॉब प्लानिंग** — प्लानिंग बोर्ड ओ डिपार्टमेंट स्टेटस\n"
                . "📐 **यूनिट कनवर्ज़न** — SQM ↔ SQ Inch रेट बदलें\n\n"
                . "💡 नीचे **Quick Action चिप्स** पर क्लिक करें या अपना सवाल टाइप करें!";
        } else {
            $greeting = "👋 **{$timeGreet[0]}, {$userName}!**\n\n"
                . "I'm your **ERP AI Assistant**. Here's what I can help you with:\n\n"
                . "📦 **Paper Stock** — Check rolls, running meters, SQM for any company\n"
                . "🧮 **Label Calculator** — Running meters, impressions & costing math\n"
                . "📋 **Job Planning** — Planning board & department status tracking\n"
                . "📐 **Unit Conversion** — SQM ↔ SQ Inch rate conversions\n\n"
                . "💡 Click a **Quick Action chip** below or type your question!";
        }
    }

    $suggestions = generate_erp_suggestions($prompt, $greeting, 'Greeting & Help', $userLang);
    echo json_encode([
        'ok' => true,
        'answer' => $greeting,
        'provider' => 'ERP AI Assistant',
        'tool_used' => 'Greeting & Help',
        'user_lang' => $userLang,
        'suggestions' => $suggestions
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}



// (Alias resolution already done above at the top of the pipeline)

// ─── Global Plate / Job Calculation Interceptor ───────────────────────────────
$isCalcCommand = false;
$rawCalcPrompt = trim($prompt);
if (preg_match('/^\/(plate|job|jobcard|planning|cal|calc)\b/iu', $rawCalcPrompt, $cmdMatch)) {
    $rawCalcPrompt = preg_replace('/^\/(plate|job|jobcard|planning|cal|calc)\s*/iu', '', $rawCalcPrompt);
    $isCalcCommand = true;
} elseif (preg_match('/^(plate|job|প্লেট|प्लेट)\b/iu', $rawCalcPrompt)) {
    $isCalcCommand = true;
}

if ($isCalcCommand) {
    $rawCalcLower = mb_strtolower($rawCalcPrompt, 'UTF-8');
    // Only proceed if it looks like there are numbers or quotes, indicating a specific calculation
    if (preg_match('/\d/', $rawCalcLower) || strpos($rawCalcPrompt, '"') !== false) {
        $promptNorm = html_entity_decode(stripslashes($rawCalcPrompt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $promptNorm = preg_replace('/[\x{201C}\x{201D}\x{201E}\x{201F}\x{00AB}\x{00BB}\x{2033}\x{2036}]/u', '"', $promptNorm);

        $params = [];
        
        // ─── Step 1: Extract Entities ──────────────────────────────────────────
        // 1. Plate Name
        if (preg_match('/"([^"]+)"/', $promptNorm, $qm)) {
            $params['plate_name'] = trim($qm[1]);
        }
        
        // 2. Dimensions (Width x Height)
        if (preg_match('/([\d.]+)\s*(?:mm)?\s*[xX×]\s*([\d.]+)\s*(?:mm)?/iu', $rawCalcLower, $dm)) {
            $params['width_mm'] = (float)$dm[1];
            $params['height_mm'] = (float)$dm[2];
        }
        
        // 3. Gap (Horizontal and Vertical)
        if (preg_match('/gap\s*(?:horizontal|h)?\s*([\d.]+)\s*(?:mm)?\s*(?:gap\s*(?:vertical|v)|[xX×])\s*([\d.]+)\s*(?:mm)?/iu', $rawCalcLower, $gm)) {
            $params['gap_h'] = (float)$gm[1];
            $params['gap_v'] = (float)$gm[2];
        } elseif (preg_match('/(?:gap|গ্যাপ)\s*([\d.]+)\s*(?:mm)?/iu', $rawCalcLower, $gm)) {
            $params['gap_h'] = (float)$gm[1];
            $params['gap_v'] = (float)$gm[1];
        }
        
        // 4. Rate / Price
        if (preg_match('/([\d.]+)\s*(?:taka|tk|rs|rupee|₹|paisa|paise|pice|টাকা|পয়সা)?\s*(?:per|\/|@|at|rate|price|cost|রেট|দাম|মূল্য)?\s*(?:sqr?|square|sq)?\s*(?:inche?|ইঞ্চি)/iu', $rawCalcLower, $pm) ||
            preg_match('/(?:rate|price|cost|per|@|রেট|দাম|মূল্য|পয়সা)\s*(?:sqr?|square|sq)?\s*(?:inche?|ইঞ্চি)?\s*(?:is|=|of|হলো|at)?\s*(?:taka|tk|rs|₹|টাকা)?\s*([\d.]+)/iu', $rawCalcLower, $pm) ||
            preg_match('/([\d.]+)\s*(?:paisa|paise|pice|পয়সা)/iu', $rawCalcLower, $pm)) {
            $params['rate'] = (float) $pm[1];
        }
        
        // 5. Budget / Amount
        if (preg_match('/(?:budget|total|amount|moq|order|job|print|বাজেট|মোট)\s*(?:is|=)?\s*(?:taka\w*|tk|rs\b|rupee|inr|₹|টাকা\w*)?\s*([\d,]+(?:\.[\d]+)?)/iu', $rawCalcLower, $am) ||
            preg_match('/([\d,]+(?:\.[\d]+)?)\s*(?:taka\w*|tk|rs\b|rupee|inr|₹|টাকা\w*)/iu', $rawCalcLower, $am)) {
            $val = (float) str_replace(',', '', $am[1]);
            if (!isset($params['rate']) || $val !== $params['rate']) {
                $params['budget'] = $val;
            }
        }
        
        // 6. Running Meter
        if (preg_match('/([\d,]+(?:\.[\d]+)?)\s*(?:running\s*meters?|meters?|mtr|মিটার|m\b)/iu', $rawCalcLower, $mm)) {
            $params['running_meter'] = (float) str_replace(',', '', $mm[1]);
        }
        
        // 7. Labels / Pcs
        if (preg_match('/([\d,]+)\s*(?:labels?|pcs|pieces?|pice|pis|ta|ti|টি|টা|পিস|লেবেল)/iu', $rawCalcLower, $lm)) {
            $params['labels'] = (int) str_replace(',', '', $lm[1]);
        }

        // ─── Step 2: Determine Intent & Confidence ──────────────────────────────
        $questionWordsPattern = '/\b(?:how\s+many|how\s+much|required|need|calculate|estimate|print|produce|generate|লাগবে|হবে|কত|কতগুলো|প্রিন্ট|উৎপাদন|চাই|ज़रूरत|कितना|कितने|प्रिंट)\b/iu';
        $hasQuestionWord = preg_match($questionWordsPattern, $rawCalcLower);
        $hasMeterWord = preg_match('/\b(?:meters?|mtr|মিটার|m)\b/iu', $rawCalcLower);
        
        $scores = [
            'calculate_required_running_meter' => 0,
            'calculate_labels' => 0,
            'calculate_moq' => 0,
            'calculate_new_job' => 0,
            'calculate_required_paper' => 0
        ];
        
        if (isset($params['labels'])) $scores['calculate_required_running_meter'] += 40;
        if ($hasMeterWord) $scores['calculate_required_running_meter'] += 40;
        if ($hasQuestionWord) $scores['calculate_required_running_meter'] += 20;

        if (isset($params['running_meter'])) $scores['calculate_labels'] += 50;
        if (!isset($params['labels'])) $scores['calculate_labels'] += 30;
        if ($hasQuestionWord) $scores['calculate_labels'] += 20;

        if (isset($params['rate'])) $scores['calculate_moq'] += 40;
        if (isset($params['budget'])) $scores['calculate_moq'] += 40;
        if ($hasQuestionWord) $scores['calculate_moq'] += 20;

        if (isset($params['width_mm']) && isset($params['height_mm'])) {
            $scores['calculate_new_job'] += 40;
            $scores['calculate_required_paper'] += 40;
        }
        if (isset($params['gap_h']) || isset($params['gap_v'])) $scores['calculate_new_job'] += 40;
        if (!isset($params['labels'])) $scores['calculate_new_job'] += 20;

        if (isset($params['labels'])) $scores['calculate_required_paper'] += 40;
        if (!isset($params['running_meter'])) $scores['calculate_required_paper'] += 20;

        arsort($scores);
        $topIntents = array_keys($scores);
        $topScore = $scores[$topIntents[0]];
        $runnerUpScore = $scores[$topIntents[1]];
        
        $intent = 'unknown';
        if ($topScore >= 50) {
            if (($topScore - $runnerUpScore) <= 10 && $runnerUpScore >= 50) {
                // Ambiguous
                echo json_encode([
                    'ok' => true,
                    'answer' => "🤖 **Clarification Needed:**\n\nI detected multiple possible calculations. Did you want to:\n\n"
                        . "• **" . ucwords(str_replace('_', ' ', $topIntents[0])) . "**\n"
                        . "• **" . ucwords(str_replace('_', ' ', $topIntents[1])) . "**\n\n"
                        . "Please clarify your request.",
                    'provider' => 'ERP AI Parser',
                    'tool_used' => 'Intent Disambiguation',
                    'suggestions' => [ucwords(str_replace('_', ' ', $topIntents[0])), ucwords(str_replace('_', ' ', $topIntents[1]))]
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            } else {
                $intent = $topIntents[0];
            }
        }
        
        // Create Normalized Intent Object
        $normalizedIntent = [
            'intent' => $intent,
            'entities' => $params,
            'confidence' => $topScore
        ];
        
        // Pass intent to params for backwards compatibility
        $params['intent'] = $normalizedIntent['intent'];

        $hasCalcEntity = isset($params['width_mm']) || isset($params['height_mm']) || isset($params['gap_h']) || isset($params['gap_v']) || isset($params['rate']) || isset($params['budget']) || isset($params['running_meter']) || isset($params['labels']);

        if ($hasCalcEntity) {
            $calcEngine = new CalculationEngine();
            $result = $calcEngine->calculateJob($db, $params, $prompt);

            if ($result) {
                echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            } else {
                echo json_encode([
                    'ok' => true,
                    'answer' => "⚠️ **Calculation Error:** Could not perform the calculation. Please ensure all required values (e.g. rate, budget, or labels) are provided.",
                    'provider' => 'ERP AI Parser',
                    'tool_used' => 'Calculation Engine'
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
        } elseif (isset($params['plate_name'])) {
            // No calculation entities found, but a plate name is present.
            // Let it fall through to Plate Details routing.
        }
    }
}

// ─── Priority Command Router (/plate, /paperstock) ───
$pTrimmed = trim(mb_strtolower($prompt, 'UTF-8'));
$userLang = detect_language($prompt);

if (strpos($p, 'live summary') !== false || strpos($p, 'dashboard live summary') !== false || strpos($p, 'live floor page summary') !== false) {
    $summary = getLiveSummary();
    $answer = "📊 **Live Production Floor Summary**\n" . $summary;
    $suggestions = generate_erp_suggestions($prompt, $answer, 'Live Summary Tool', $userLang);
    echo json_encode([
        'ok' => true,
        'answer' => $answer,
        'provider' => 'Live Summary Engine',
        'tool_used' => 'Live Summary Tool',
        'suggestions' => $suggestions
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// Define userLang for global scope
$baseNavUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
$commandType = null; // Tracks special command: 'plate', 'paperstock', 'quoted'

// ??? Slash Commands Helper (/) � show all available commands ???
$pTrimmedSingle = trim(mb_strtolower($prompt, 'UTF-8'));
if ($pTrimmedSingle === '/' || $pTrimmedSingle === '/help' || $pTrimmedSingle === 'help' || $pTrimmedSingle === 'commands') {
    if ($userLang === 'Bengali') {
        $answer = "🔣 **স্ল্যাশ কমান্ড সমূহ:**\n\n"
            . "🧮 **`/cal <query>`** — যেকোনো বাহ্যিক গণনা (লেবেল, অংক, ইউনিট রূপান্তর)\n"
            . "🔒 **`/erp <query>`** — শুধু ERP ডাটাবেস ও নলেজ বেস থেকে উত্তর\n"
            . "🖼️ **`/plate <query>`** — প্লেট ডাটা প্রাধান্য দিয়ে খুঁজুন\n"
            . "📦 **`/paper <query>`** — পেপার স্টক প্রাধান্য দিয়ে খুঁজুন\n"
            . "✅ **`/clear`** — প্রায়োরিটি মোড রিসেট\n\n"
            . "**উদাহরণ:**\n"
            . "🧮 `/cal 100mm x 150mm`\n"
            . "🔒 `/erp মোট কতগুলো প্লেট আছে?`\n"
            . "🖼️ `/plate Chromo কোম্পানির প্লেট`\n"
            . "📦 `/paper Krishna কোম্পানির রোল`\n\n"
            . "👉 কপি করে ব্যবহার করুন!";
        $suggestions = ['/cal 100mm x 150mm', '/erp মোট কতগুলো প্লেট আছে?', '/plate Chromo প্লেট', '/paper Krishna রোল', '/clear'];
    } elseif ($userLang === 'Hindi') {
        $answer = "🔣 **स्लैश कमांड्स:**\n\n"
            . "🧮 **`/cal <query>`** — कोई भी बाह्य गणना (लेबल, गणित, यूनिट रूपांतरण)\n"
            . "🔒 **`/erp <query>`** — केवल ERP डेटाबेस और नॉलेज बेस से उत्तर\n"
            . "🖼️ **`/plate <query>`** — प्लेट डेटा प्राथमिकता से खोजें\n"
            . "📦 **`/paper <query>`** — पेपर स्टॉक प्राथमिकता से खोजें\n"
            . "✅ **`/clear`** — प्राथमिकता मोड रीसेट\n\n"
            . "**उदाहरण:**\n"
            . "🧮 `/cal 100mm x 150mm`\n"
            . "🔒 `/erp कुल कितनी प्लेट हैं?`\n"
            . "🖼️ `/plate Chromo पेपर प्लेट`\n"
            . "📦 `/paper Krishna कंपनी के रोल`\n\n"
            . "👉 कॉपी करके उपयोग करें!";
        $suggestions = ['/cal 100mm x 150mm', '/erp कुल कितनी प्लेट हैं?', '/plate Chromo प्लेट', '/paper Krishna रोल', '/clear'];
    } else {
        $answer = "🔣 **Available Slash Commands:**\n\n"
            . "🧮 **`/cal <query>`** — Any external calculation (label, math, unit conversion)\n"
            . "🔒 **`/erp <query>`** — Answer only from ERP database & Knowledge Base\n"
            . "🖼️ **`/plate <query>`** — Search with Plate data priority\n"
            . "📦 **`/paper <query>`** — Search with Paper Stock data priority\n"
            . "✅ **`/clear`** — Reset priority mode\n\n"
            . "**Examples:**\n"
            . "🧮 `/cal 100mm x 150mm`\n"
            . "🔒 `/erp Total number of plates`\n"
            . "🖼️ `/plate Chromo paper plates`\n"
            . "📦 `/paper Krishna company rolls`\n\n"
            . "👉 Feel free to copy and use any command above!";
        $suggestions = ['/cal 100mm x 150mm', '/erp Total number of plates', '/plate Chromo plates', '/paper Krishna rolls', '/clear'];
    }
    echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'Slash Commands Help', 'user_lang' => $userLang, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// /erp command — Master Executive 360° ERP Overview
if (strpos($pTrimmed, '/erp') === 0 || $pTrimmed === 'erp' || $pTrimmed === 'ইআরপি' || $pTrimmed === 'ईआरपी') {
    $subQuery = preg_replace('/^\/erp\s*/iu', '', trim($prompt));
    $subQuery = preg_replace('/^ইআরপি\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^ईआरपी\s*/u', '', $subQuery);
    $subQuery = trim($subQuery);

    if ($subQuery === '') {
        $paperStmt = $db->query("SELECT COUNT(*) as total_rolls, IFNULL(SUM(length_mtr),0) as total_mtr, IFNULL(SUM((width_mm/1000)*length_mtr),0) as total_sqm, IFNULL(SUM(weight_kg),0) as total_wt FROM paper_stock");
        $paperRow = $paperStmt ? $paperStmt->fetch_assoc() : [];
        $totalRolls = (int)($paperRow['total_rolls'] ?? 0);
        $totalMtr = (float)($paperRow['total_mtr'] ?? 0);
        $totalSqm = (float)($paperRow['total_sqm'] ?? 0);
        $totalWt = (float)($paperRow['total_wt'] ?? 0);

        $plateStmt = $db->query("SELECT COUNT(*) as total_plates FROM master_plate_data");
        $totalPlates = $plateStmt ? (int)($plateStmt->fetch_assoc()['total_plates'] ?? 0) : 0;

        if (!defined('MI_HELPER_ONLY')) define('MI_HELPER_ONLY', true);
        require_once __DIR__ . '/../inventory/mixed-item/api/mixed_item_api.php';
        $mixedRows = function_exists('mi_fetch_rows') ? mi_fetch_rows($db, '') : [];
        $totalMixedItems = count($mixedRows);
        $totalMixedQty = 0;
        foreach ($mixedRows as $mr) { $totalMixedQty += (float)($mr['extra_qty'] ?? 0); }

        $jobStmt = $db->query("SELECT COUNT(*) as cnt FROM jobs WHERE status NOT IN ('Completed','Cancelled')");
        $activeJobsCount = $jobStmt ? (int)($jobStmt->fetch_assoc()['cnt'] ?? 0) : 0;

        if ($userLang === 'Bengali') {
            $answer = "🌐 **Shree Label ERP — এক্সিকিউটিভ ৩৬০° মাস্টার সিস্টেম ও ইনভেন্টরি ওভারভিউ:**\n\n"
                . "📦 **১. পেপার স্টক ইনভেন্টরি (`/paper`):**\n"
                . "  - 🔵 মোট রোল সংখ্যা: **" . number_format($totalRolls) . "টি রোল**\n"
                . "  - ⚡ সর্বমোট রানিং লেন্থ: **" . number_format($totalMtr, 0) . " মিটার (MTR)**\n"
                . "  - 📐 সর্বমোট সারফেস এরিয়া: **" . number_format($totalSqm, 0) . " SQM**\n"
                . "  - ⚖️ মোট গ্রস ওজন: **" . number_format($totalWt, 0) . " KG**\n\n"
                . "🏷️ **২. মাস্টার প্লেট ইনভেন্টরি (`/plate`):**\n"
                . "  - 📁 রেজিষ্টার্ড মোট মাস্টার প্লেট: **" . number_format($totalPlates) . "টি প্লেট**\n"
                . "  - ⚙️ সিলিন্ডার সাপোর্ট: **9 inch থেকে 16 inch & Flat Bed**\n\n"
                . "🔀 **৩. মিক্সড আইটেম ও এক্সট্রা প্রোডাকশন পুল (`/stock`):**\n"
                . "  - 🔀 অ্যাক্টিভ এক্সট্রা পুল ব্যাচ: **" . number_format($totalMixedItems) . "টি আইটেম** (মোট লুজ স্টক: **" . number_format($totalMixedQty) . " PCS**)\n\n"
                . "⚙️ **৪. লাইভ প্রোডাকশন ফ্লোর ও জবস (`/job`):**\n"
                . "  - 📋 প্রোডাকশনে রানিং মোট জব: **" . number_format($activeJobsCount) . "টি জব**\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . "👉 **নির্দিষ্ট মডিউলের বিস্তারিত জানতে নিচে ক্লিক করুন বা টাইপ করুন:**\n"
                . "• **`/paper`** ➔ পেপার স্টক, রানিং মিটার ও সাপ্লায়ার বিশ্লেষণ\n"
                . "• **`/plate`** ➔ প্লেট সার্চ, রিক্যালকুলেশন, আপস ও সিলিন্ডার সাইজ\n"
                . "• **`/stock`** ➔ মিক্সড এক্সট্রা স্টক ও প্যাকিং হ্যান্ডওভার";
            $suggestions = ['/paper', '/plate', '/stock', '/cal 100mm x 150mm'];
        } elseif ($userLang === 'Hindi') {
            $answer = "🌐 **Shree Label ERP — एग्जीक्यूटिव 360° मास्टर सिस्टम और इन्वेंटरी सारांश:**\n\n"
                . "📦 **1. पेपर स्टॉक इन्वेंटरी (`/paper`):**\n"
                . "  - 🔵 कुल रोल संख्या: **" . number_format($totalRolls) . " रोल**\n"
                . "  - ⚡ कुल रनिंग मीटर: **" . number_format($totalMtr, 0) . " मीटर (MTR)**\n"
                . "  - 📐 कुल सरफेस एरिया: **" . number_format($totalSqm, 0) . " SQM**\n"
                . "  - ⚖️ कुल वजन: **" . number_format($totalWt, 0) . " KG**\n\n"
                . "🏷️ **2. मास्टर प्लेट इन्वेंटरी (`/plate`):**\n"
                . "  - 📁 पंजीकृत कुल मास्टर प्लेट: **" . number_format($totalPlates) . " प्लेट**\n\n"
                . "🔀 **3. मिक्सड और एक्स्ट्रा पूल (`/stock`):**\n"
                . "  - 🔀 एक्टिव एक्स्ट्रा पूल बैच: **" . number_format($totalMixedItems) . " आइटम** (" . number_format($totalMixedQty) . " PCS)\n\n"
                . "⚙️ **4. लाइव प्रोडक्शन और जॉब्स (`/job`):**\n"
                . "  - 📋 एक्टिव प्रोडक्शन जॉब्स: **" . number_format($activeJobsCount) . " जॉब**\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . "👉 **विस्तृत रिपोर्ट के लिए चुनें:**\n"
                . "• **`/paper`** ➔ पेपर स्टॉक और सप्लायर रिपोर्ट\n"
                . "• **`/plate`** ➔ प्लेट सर्च और सिलेंडर साइज\n"
                . "• **`/stock`** ➔ मिक्सड आइटम और रीपैकिंग";
            $suggestions = ['/paper', '/plate', '/stock', '/cal 100mm x 150mm'];
        } else {
            $answer = "🌐 **Shree Label ERP — Executive 360° Master System & Inventory Overview:**\n\n"
                . "📦 **1. Paper Stock Inventory (`/paper`):**\n"
                . "  - 🔵 Total Rolls in Inventory: **" . number_format($totalRolls) . " Rolls**\n"
                . "  - ⚡ Total Running Length: **" . number_format($totalMtr, 0) . " MTR**\n"
                . "  - 📐 Total Surface Area: **" . number_format($totalSqm, 0) . " SQM**\n"
                . "  - ⚖️ Gross Inventory Weight: **" . number_format($totalWt, 0) . " KG**\n\n"
                . "🏷️ **2. Master Plate Inventory (`/plate`):**\n"
                . "  - 📁 Total Registered Master Plates: **" . number_format($totalPlates) . " Plates**\n"
                . "  - ⚙️ Cylinder Support: **9 inch to 16 inch & Flat Bed**\n\n"
                . "🔀 **3. Mixed & Extra Production Pool (`/stock`):**\n"
                . "  - 🔀 Active Extra Pool Batches: **" . number_format($totalMixedItems) . " Items** (Total Loose Stock: **" . number_format($totalMixedQty) . " PCS**)\n\n"
                . "⚙️ **4. Active Production & Live Floor (`/job`):**\n"
                . "  - 📋 Active Jobs in Production: **" . number_format($activeJobsCount) . " Jobs**\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n"
                . "👉 **Need deeper module analytics?** Click or type:\n"
                . "• **`/paper`** ➔ For deep Paper Stock & Material breakdown\n"
                . "• **`/plate`** ➔ For Plate Lookup, Ups & Paper Meter Calculations\n"
                . "• **`/stock`** ➔ For Mixed Item Extra Pool & Repacking Handover";
            $suggestions = ['/paper', '/plate', '/stock', '/cal 100mm x 150mm'];
        }

        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP Executive 360 Engine', 'tool_used' => 'Master ERP Overview Tool', 'user_lang' => $userLang, 'suggestions' => $suggestions, 'command_type' => 'erp'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } else {
        $subLower = mb_strtolower($subQuery, 'UTF-8');
        if (strpos($subLower, 'floor') !== false || strpos($subLower, 'live') !== false || strpos($subLower, 'production') !== false || strpos($subLower, 'ফ্লোর') !== false || strpos($subLower, 'প্রোডাকশন') !== false || strpos($subLower, 'জব') !== false) {
            $jobRes = $db->query("SELECT department, status, COUNT(*) as cnt FROM jobs GROUP BY department, status");
            $deptStats = [];
            if ($jobRes) {
                while ($r = $jobRes->fetch_assoc()) {
                    $d = $r['department'] ?: 'Planning';
                    $st = $r['status'] ?: 'Pending';
                    $deptStats[$d][$st] = (int)$r['cnt'];
                }
            }

            $planRes = $db->query("SELECT id, job_no, job_name, status, department FROM planning ORDER BY id DESC LIMIT 5");
            $recentPlans = $planRes ? $planRes->fetch_all(MYSQLI_ASSOC) : [];

            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            if ($userLang === 'Bengali') {
                $answer = "📊 **Shree Label ERP — লাইভ ফ্লোর রিয়েল-টাইম প্রোডাকশন সামারি:**\n\n"
                    . "⚙️ **স্টেজ-বাই-স্টেজ লাইভ ফ্লোর স্ট্যাটাস:**\n"
                    . "  - 📝 **Planning Stage:** " . (count($recentPlans)) . "টি অ্যাক্টিভ জব কার্ড\n"
                    . "  - ✂️ **Jumbo Slitting:** " . ($deptStats['jumbo_slitting']['In Progress'] ?? $deptStats['jumbo_slitting']['Pending'] ?? 0) . "টি জব প্রক্রিয়াধীন\n"
                    . "  - 🖨️ **Flexo Printing:** " . ($deptStats['flexo_printing']['In Progress'] ?? $deptStats['flexo_printing']['Pending'] ?? 0) . "টি জব প্রিন্টিংয়ে\n"
                    . "  - 🔪 **Die Cutting / Flatbed:** " . ($deptStats['flatbed']['In Progress'] ?? $deptStats['flatbed']['Pending'] ?? 0) . "টি জব ডাই-কাটিংয়ে\n"
                    . "  - 🏷️ **Label Slitting:** " . ($deptStats['label_slitting']['In Progress'] ?? $deptStats['label_slitting']['Queued'] ?? 0) . "টি জব স্লিটিংয়ে\n"
                    . "  - 📦 **Packing & Dispatch:** " . ($deptStats['packing']['Completed'] ?? 0) . "টি জব প্রস্তুত\n\n"
                    . "📋 **সাম্প্রতিক রানিং জবস:**\n";
                foreach ($recentPlans as $pRow) {
                    $answer .= "• **`" . ($pRow['job_no'] ?: 'PLN-JOB') . "`** — `" . ($pRow['job_name'] ?: 'Production Job') . "` (স্ট্যাটাস: **" . ($pRow['status'] ?: 'In Production') . "**)\n";
                }
                $answer .= "\n👉 [লাইভ ফ্লোর ড্যাশবোর্ড খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/live_floor/index.php)";
                $suggestions = ['/paper', '/plate', '/stock', '/erp'];
            } else {
                $answer = "📊 **Shree Label ERP — Live Floor Real-Time Production Summary:**\n\n"
                    . "⚙️ **Stage-by-Stage Live Floor Status:**\n"
                    . "  - 📝 **Planning Stage:** " . (count($recentPlans)) . " Active Jobs\n"
                    . "  - ✂️ **Jumbo Slitting:** " . ($deptStats['jumbo_slitting']['In Progress'] ?? $deptStats['jumbo_slitting']['Pending'] ?? 0) . " Jobs In-Progress\n"
                    . "  - 🖨️ **Flexo Printing:** " . ($deptStats['flexo_printing']['In Progress'] ?? $deptStats['flexo_printing']['Pending'] ?? 0) . " Jobs Printing\n"
                    . "  - 🔪 **Die Cutting / Flatbed:** " . ($deptStats['flatbed']['In Progress'] ?? $deptStats['flatbed']['Pending'] ?? 0) . " Jobs Die-Cutting\n"
                    . "  - 🏷️ **Label Slitting:** " . ($deptStats['label_slitting']['In Progress'] ?? $deptStats['label_slitting']['Queued'] ?? 0) . " Jobs Slitting\n"
                    . "  - 📦 **Packing & Dispatch:** " . ($deptStats['packing']['Completed'] ?? 0) . " Jobs Ready\n\n"
                    . "📋 **Recent Active Jobs:**\n";
                foreach ($recentPlans as $pRow) {
                    $answer .= "• **`" . ($pRow['job_no'] ?: 'PLN-JOB') . "`** — `" . ($pRow['job_name'] ?: 'Production Job') . "` (Status: **" . ($pRow['status'] ?: 'In Production') . "**)\n";
                }
                $answer .= "\n👉 [Click here to open Live Floor Dashboard]({$baseUrl}/modules/live_floor/index.php)";
                $suggestions = ['/paper', '/plate', '/stock', '/erp'];
            }

            echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP Live Floor Engine', 'tool_used' => 'Live Floor Real-Time Tool', 'user_lang' => $userLang, 'suggestions' => $suggestions, 'command_type' => 'erp'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $erpOnlyMode = true;
        $prompt = $subQuery;
        $p = mb_strtolower($prompt, 'UTF-8');
    }
}

// /plate command
if (strpos($pTrimmed, '/plate') === 0 || $pTrimmed === 'plate' || $pTrimmed === 'প্লেট' || strpos($pTrimmed, 'प्लेट') !== false) {
    $_SESSION['ai_priority_mode'] = 'plate';
    $commandType = 'plate';
    $subQuery = preg_replace('/^\/plate\s*/iu', '', trim($prompt));
    $subQuery = preg_replace('/^প্লেট\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^प्लेट\s*/u', '', $subQuery);
    $subQuery = trim($subQuery);

    if ($subQuery === '') {
        $navUrl = $baseNavUrl . '/modules/plate-tools/plate-management/index.php';
        if ($userLang === 'Bengali') {
            $answer = "🎯 **প্লেট ম্যানেজমেন্ট পেজে অগ্রাধিকার দেওয়া হচ্ছে!**\n\n👉 [প্লেট ম্যানেজমেন্ট খুলুন]($navUrl)\n\n**📌 এখন থেকে সব প্রশ্নে প্লেট ডাটা আগে খুঁজবে।**\n\n**আপনি যা জিজ্ঞাসা করতে পারেন:**\n• মোট কতগুলো প্লেট আছে?\n• Chromo পেপারের প্লেট কতগুলো?\n• Flat Bed বা Rotary প্লেট কত?\n• Alpha Flex / SFL / Pidilite — কোন কোম্পানির প্লেট?\n• 9 inch সিলিন্ডার প্লেট দেখাও\n• সবচেয়ে নতুন প্লেটটি কী?\n• CMYK কালার স্পেসিফিকেশন দেখাও";
            $suggestions = ['মোট কতগুলো প্লেট আছে?', 'Chromo পেপারের প্লেট কত?', 'Flat Bed প্লেট দেখাও', 'Rotary প্লেট দেখাও', 'Alpha Flex এর প্লেট দেখাও', '9 inch সিলিন্ডার প্লেট দেখাও', 'সবচেয়ে নতুন প্লেটটি কী?'];
        } elseif ($userLang === 'Hindi') {
            $answer = "🎯 **प्लेट मैनेजमेंट पेज को प्राथमिकता दी जा रही है!**\n\n👉 [प्लेट मैनेजमेंट खोलें]($navUrl)\n\n**📌 अब से सभी सवालों में प्लेट डेटा पहले खोजा जाएगा।**\n\n**आप ये पूछ सकते हैं:**\n• कुल कितनी प्लेट हैं?\n• Chromo पेपर की प्लेट कितनी?\n• Flat Bed बा Rotary प्लेट कितनी?\n• Alpha Flex / SFL / Pidilite — किस कंपनी की प्लेट?\n• 9 inch सिलेंडर प्लेट दिखाओ\n• सबसे नई प्लेट कौन सी है?\n• CMYK कलर स्पेसिफिकेशन दिखाओ";
            $suggestions = ['कुल कितनी प्लेट हैं?', 'Chromo पेपर की प्लेट कितनी?', 'Flat Bed प्लेट देखाओ', 'Rotary प्लेट देखाओ', 'Alpha Flex की प्लेट देखाओ', '9 inch सिलेंडर प्लेट देखाओ', 'सबसे नई प्लेट कौन सी है?'];
        } else {
            $answer = "🎯 **Prioritizing Plate Management Page!**\n\n👉 [Open Plate Management]($navUrl)\n\n**📌 All your queries will now search Plate data first.**\n\n**You can ask about:**\n• Total number of plates\n• Plates by paper type (Chromo, Thermal, etc.)\n• Flat Bed vs Rotary plate count\n• Plates by company (Alpha Flex, SFL, Pidilite)\n• Cylinder size filter (9 inch, 12 inch, etc.)\n• Latest / newest plate added\n• CMYK color specifications for any plate";
            $suggestions = ['Total number of plates', 'Chromo paper plates count', 'Show Flat Bed plates', 'Show Rotary plates', 'Alpha Flex company plates', '9 inch cylinder plates', 'Latest plate added'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'Plate Priority Command', 'user_lang' => $userLang, 'nav_url' => $navUrl, 'suggestions' => $suggestions, 'command_type' => 'plate'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // /plate <query> → rewrite prompt, fall through to normal processing (priority mode searches plate first)
    $erpOnlyMode = true;
    $prompt = $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}

// /paperstock or /paper command — only if a /plate command did not already claim this query
if ($commandType === null && (strpos($pTrimmed, '/paper') === 0 || strpos($pTrimmed, '/paperstock') === 0 || strpos($pTrimmed, '/paper stock') === 0 || $pTrimmed === 'paperstock' || $pTrimmed === 'paper stock' || $pTrimmed === 'paper' || strpos($pTrimmed, 'পেপার স্টক') !== false || strpos($pTrimmed, 'পেপার') !== false || strpos($pTrimmed, 'पेपर स्टॉक') !== false || strpos($pTrimmed, 'पेपर') !== false)) {
    $_SESSION['ai_priority_mode'] = 'paperstock';
    $commandType = 'paperstock';
    $subQuery = preg_replace('/^\/paper\s*stock\s*/iu', '', trim($prompt));
    $subQuery = preg_replace('/^\/paper\s*/i', '', $subQuery);
    $subQuery = preg_replace('/^paper\s*stock\s*/i', '', $subQuery);
    $subQuery = preg_replace('/^paper\s*/i', '', $subQuery);
    $subQuery = preg_replace('/^পেপার\s*স্টক\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^পেপার\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^पेपर\s*स्टॉक\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^पेपर\s*/u', '', $subQuery);
    $subQuery = trim($subQuery);

    if ($subQuery === '') {
        // No specific query → show full stock summary
        $erpOnlyMode = true;
        $p = 'total paper rolls';
        $prompt = 'Show total paper rolls';
    } else {
        // /paperstock <query> → rewrite prompt, fall through to normal processing
        $erpOnlyMode = true;
        $prompt = $subQuery;
        $p = mb_strtolower($prompt, 'UTF-8');
    }
}
// /dispatch or /packing command
if ($commandType === null && preg_match('/^\/(dispatch|packing)\b/i', $pTrimmed)) {
    $_SESSION['ai_priority_mode'] = 'dispatch';
    $commandType = 'dispatch';
    $subQuery = preg_replace('/^\/(dispatch|packing)\s*/i', '', trim($prompt));
    if ($subQuery === '') {
        $erpOnlyMode = true;
        $prompt = 'Show today\'s dispatch';
        $p = mb_strtolower($prompt, 'UTF-8');
    } else {
        $erpOnlyMode = true;
        $prompt = $subQuery;
        $p = mb_strtolower($prompt, 'UTF-8');
    }
}


// /job, /jobcard, /planning command
if ($commandType === null && preg_match('/^\/(job|jobcard|planning)\b/i', $pTrimmed)) {
    $_SESSION['ai_priority_mode'] = 'job';
    $commandType = 'job';
    $subQuery = preg_replace('/^\/(job|jobcard|planning)\s*/i', '', trim($prompt));
    if ($subQuery === '') {
        $erpOnlyMode = true;
        $prompt = 'Show job planning board';
        $p = mb_strtolower($prompt, 'UTF-8');
    } else {
        $erpOnlyMode = true;
        $prompt = $subQuery;
        $p = mb_strtolower($prompt, 'UTF-8');
    }
}

// /setting or /settings command
if ($commandType === null && preg_match('/^\/(setting|settings)\b/i', $pTrimmed)) {
    $_SESSION['ai_priority_mode'] = 'setting';
    $commandType = 'setting';
    $subQuery = preg_replace('/^\/(setting|settings)\s*/i', '', trim($prompt));
    $erpOnlyMode = true;
    $prompt = $subQuery === '' ? 'open settings' : $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}

// /cal command — External Calculation Mode: force calculation engine
if (preg_match('/^\/cal\s*/iu', $pTrimmed)) {
    $subQuery = preg_replace('/^\/cal\s*/iu', '', $prompt);
    $subQuery = trim($subQuery);
    if ($subQuery === '') {
        if ($userLang === 'Bengali') {
            $answer = "🧮 **/cal — বাহ্যিক গণনা মোড**\n\n"
                . "`/cal` কমান্ড দিয়ে আপনি যেকোনো বাহ্যিক গণনা করতে পারবেন:\n\n"
                . "• **লেবেল ক্যালকুলেশন:** যেমন `/cal 100mm x 150mm`\n"
                . "• **সরল অংক:** যেমন `/cal 500*3` বা `/cal (2500+500)/2`\n"
                . "• **ইউনিট রূপান্তর:** যেমন `/cal 100 sqm to sq inch`\n"
                . "• **ক্ষেত্রফল:** যেমন `/cal 100mm x 150mm to sq inch`\n\n"
                . "👉 **যা খুশি টাইপ করুন — আমিই বুঝে নেব!**";
        } elseif ($userLang === 'Hindi') {
            $answer = "🧮 **/cal — बाह्य गणना मोड**\n\n"
                . "`/cal` कमांड से आप कोई भी बाह्य गणना कर सकते हैं:\n\n"
                . "• **लेबल कैलकुलेशन:** जैसे `/cal 100mm x 150mm`\n"
                . "• **सरल गणित:** जैसे `/cal 500*3` या `/cal (2500+500)/2`\n"
                . "• **यूनिट रूपांतरण:** जैसे `/cal 100 sqm to sq inch`\n"
                . "• **क्षेत्रफल:** जैसे `/cal 100mm x 150mm to sq inch`\n\n"
                . "👉 **जो चाहे टाइप करें — मैं समझ लूंगा!**";
        } else {
            $answer = "🧮 **/cal — External Calculation Mode**\n\n"
                . "Use `/cal` command to perform any external calculation:\n\n"
                . "• **Label Calculation:** e.g. `/cal 100mm x 150mm`\n"
                . "• **Simple Math:** e.g. `/cal 500*3` or `/cal (2500+500)/2`\n"
                . "• **Unit Conversion:** e.g. `/cal 100 sqm to sq inch`\n"
                . "• **Area:** e.g. `/cal 100mm x 150mm to sq inch`\n\n"
                . "👉 **Just type what you need — I'll figure it out!**";
        }
        $calSuggestions = generate_erp_suggestions($prompt, $answer, '/cal Command', $userLang);
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI', 'tool_used' => '/cal Command', 'user_lang' => $userLang, 'suggestions' => $calSuggestions, 'command_type' => 'cal'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    // /cal <query> — strip prefix, use the remaining query for calculation
    $prompt = $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}
// /erp command — ERP-only mode: force KB + ERP data only, skip external LLM
// Slash commands (/plate, /paperstock, /dispatch, /job, /setting) already set
// $erpOnlyMode = true above. Do NOT reset it here — otherwise later handlers
// (e.g. the Inline Quoted Term Handler) would override the slash command and
// hijack e.g. `/plate "Blue 500" ...` into a KB "Quoted Product Lookup".
if ($commandType === null) {
    $erpOnlyMode = false;
}
$skipNormalDb = false; // TRUE = normal query (no / prefix) → skip KB+DB, go directly to LLM
if (preg_match('/^\/erp\s*/iu', $pTrimmed)) {
    $subQuery = preg_replace('/^\/erp\s*/iu', '', $prompt);
    $subQuery = trim($subQuery);
    if ($subQuery === '') {
        if ($userLang === 'Bengali') {
            $answer = "🔒 **ERP-অনলি মোড**\n\n"
                . "আপনি এখন /erp **ERP-অনলি মোডে** আছেন। আমি **শুধু ERP ডাটাবেস এবং নলেজ বেস** থেকে উত্তর দেব।\n\n"
                . "**আপনি যা জিজ্ঞাসা করতে পারেন:**\n"
                . "• 📦 পেপার স্টক — রোল, কোম্পানি, টাইপ\n"
                . "• 🏭 প্রোডাকশন — জব, প্ল্যানিং, মেশিন\n"
                . "• 📋 প্লেট — প্লেট ডাটা, কোম্পানি, টাইপ\n"
                . "• 📄 ERP নলেজ বেস — ট্রেইনড প্রশ্ন\n\n"
                . "👉 যেমন: **\"/erp মোট কতগুলো প্লেট আছে?\"**\n"
                . "👉 বা: **\"/erp Chromo পেপারের কত রোল?\"**";
            $suggestions = ['/erp মোট কতগুলো প্লেট আছে?', '/erp Chromo পেপারের রোল', '/erp Krishna কোম্পানির রোল'];
        } elseif ($userLang === 'Hindi') {
            $answer = "🔒 **ERP-ओनली मोड**\n\n"
                . "आप /erp **ERP-ओनली मोड** में हैं। मैं **केवल ERP डेटाबेस और नॉलेज बेस** से उत्तर दूंगा।\n\n"
                . "**आप ये पूछ सकते हैं:**\n"
                . "• 📦 पेपर स्टॉक — रोल, कंपनी, टाइप\n"
                . "• 🏭 प्रोडक्शन — जॉब, प्लानिंग, मशीन\n"
                . "• 📋 प्लेट — प्लेट डेटा, कंपनी, टाइप\n"
                . "• 📄 ERP नॉलेज बेस — ट्रेंड प्रश्न\n\n"
                . "👉 जैसे: **\"/erp कुल कितनी प्लेट हैं?\"**\n"
                . "👉 या: **\"/erp Chromo पेपर के कितने रोल?\"**";
            $suggestions = ['/erp कुल कितनी प्लेट हैं?', '/erp Chromo पेपर के रोल', '/erp Krishna कंपनी के रोल'];
        } else {
            $answer = "🔒 **ERP-Only Mode**\n\n"
                . "You are in /erp **ERP-Only Mode**. I will answer **only from ERP database and Knowledge Base**.\n\n"
                . "**You can ask about:**\n"
                . "• 📦 Paper Stock — rolls, companies, types\n"
                . "• 🏭 Production — jobs, planning, machines\n"
                . "• 📋 Plates — plate data, companies, types\n"
                . "• 📄 ERP Knowledge Base — trained Q&A\n\n"
                . "👉 Example: **\"/erp Total number of plates\"**\n"
                . "👉 Or: **\"/erp Chromo paper roll count\"**";
            $suggestions = ['/erp Total number of plates', '/erp Chromo paper rolls', '/erp Krishna company rolls'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'ERP-Only Mode', 'user_lang' => $userLang, 'suggestions' => $suggestions, 'command_type' => 'erp'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    // /erp <query> — strip prefix, set ERP-only mode, fall through
    $erpOnlyMode = true;
    $prompt = $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}

// Clear priority mode
if (strpos($pTrimmed, '/clear') === 0 || $pTrimmed === 'clear priority' || $pTrimmed === 'reset' || $pTrimmed === 'normal' || strpos($pTrimmed, 'নরমাল') !== false || strpos($pTrimmed, 'सामान्य') !== false) {
    unset($_SESSION['ai_priority_mode']);

    $clearAnswer = $userLang === 'Bengali' ? "✅ **প্রায়োরিটি মোড রিসেট করা হয়েছে।** এখন সব ডাটা সমান অগ্রাধিকার পাবে।"
        : ($userLang === 'Hindi' ? "✅ **प्राथमिकता मोड रीसेट कर दिया गया है।** अब सभी डेटा समान प्राथमिकता पर है।"
            : "✅ **Priority mode reset.** All data sources now have equal priority.");
    $clearSuggestions = generate_erp_suggestions($prompt, $clearAnswer, 'Priority Reset', $userLang);
    echo json_encode([
        'ok' => true,
        'answer' => $clearAnswer,
        'provider' => 'ERP AI Priority Command',
        'tool_used' => 'Priority Reset',
        'suggestions' => $clearSuggestions
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// ─── Bare Quoted Query Handler ("blue 500" etc.) ───
// If user types just a quoted string like "blue 500" without /plate or /paperstock prefix,
// search broadly (jobs, plates, paper stock) and ask user what they want to see.
// NOTE: Without / prefix, quoted terms are treated as normal queries (skip to LLM).
if (strpos($pTrimmed, '/') === 0 && preg_match('/^["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]\s*(?:details?|detail|info|information|status|data|bistarito|bisarito|বিস্তারিত|विवरण|dekhao|show|give|about)?\s*$/iu', trim($prompt), $qm)) {
    $commandType = 'quoted';
    $searchTerm = trim($qm[1]);
    $searchLower = mb_strtolower($searchTerm, 'UTF-8');
    $results = [];

    // 1. Search Planning / Running Jobs
    $jobHits = [];
    $jStmt = $db->prepare("SELECT id, job_no, job_name, status, machine, priority FROM planning WHERE job_name LIKE ? OR job_no LIKE ? ORDER BY id DESC LIMIT 5");
    if ($jStmt) {
        $jLike = '%' . $searchTerm . '%';
        $jStmt->bind_param('ss', $jLike, $jLike);
        $jStmt->execute();
        $jobHits = $jStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
    }
    if (!empty($jobHits)) {
        $results['jobs'] = $jobHits;
    }

    // 2. Search Plate Data
    $plateHits = [];
    $pStmt = $db->prepare("SELECT id, sl_no, name, plate, paper_type, cylinder, make_by, die FROM master_plate_data WHERE name LIKE ? OR plate LIKE ? OR sl_no LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? ORDER BY id DESC LIMIT 5");
    if ($pStmt) {
        $pLike = '%' . $searchTerm . '%';
        $pStmt->bind_param('ssssss', $pLike, $pLike, $pLike, $pLike, $pLike, $pLike);
        $pStmt->execute();
        $plateHits = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
    }
    if (!empty($plateHits)) {
        $results['plates'] = $plateHits;
    }

    // 3. Search Paper Stock
    $stockHits = [];
    $sStmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, status FROM paper_stock WHERE roll_no LIKE ? OR paper_type LIKE ? OR company LIKE ? OR job_name LIKE ? OR remarks LIKE ? OR lot_batch_no LIKE ? OR company_roll_no LIKE ? ORDER BY id DESC LIMIT 5");
    if ($sStmt) {
        $sLike = '%' . $searchTerm . '%';
        $sStmt->bind_param('sssssss', $sLike, $sLike, $sLike, $sLike, $sLike, $sLike, $sLike);
        $sStmt->execute();
        $stockHits = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
    }
    if (!empty($stockHits)) {
        $results['stock'] = $stockHits;
    }

    $foundAreas = array_keys($results);

    // If nothing found, try splitting numbers from letters (e.g. "blue500" -> "blue 500")
    if (empty($foundAreas) && preg_match('/[a-zA-Z]\d|\d[a-zA-Z]/', $searchTerm)) {
        $splitTerm = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $searchTerm);
        $splitTerm = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $splitTerm);
        $splitLower = mb_strtolower($splitTerm, 'UTF-8');

        // Re-run searches with split term
        $jobHits = [];
        $jStmt = $db->prepare("SELECT id, job_no, job_name, status, machine, priority FROM planning WHERE job_name LIKE ? OR job_no LIKE ? ORDER BY id DESC LIMIT 5");
        if ($jStmt) {
            $jLike = '%' . $splitTerm . '%';
            $jStmt->bind_param('ss', $jLike, $jLike);
            $jStmt->execute();
            $jobHits = $jStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        }
        if (!empty($jobHits))
            $results['jobs'] = $jobHits;

        $plateHits = [];
        $pStmt = $db->prepare("SELECT id, sl_no, name, plate, paper_type, cylinder, make_by, die FROM master_plate_data WHERE name LIKE ? OR plate LIKE ? OR sl_no LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? ORDER BY id DESC LIMIT 5");
        if ($pStmt) {
            $pLike = '%' . $splitTerm . '%';
            $pStmt->bind_param('ssssss', $pLike, $pLike, $pLike, $pLike, $pLike, $pLike);
            $pStmt->execute();
            $plateHits = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        }
        if (!empty($plateHits))
            $results['plates'] = $plateHits;

        $stockHits = [];
        $sStmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, status FROM paper_stock WHERE roll_no LIKE ? OR paper_type LIKE ? OR company LIKE ? OR job_name LIKE ? OR remarks LIKE ? OR lot_batch_no LIKE ? OR company_roll_no LIKE ? ORDER BY id DESC LIMIT 5");
        if ($sStmt) {
            $sLike = '%' . $splitTerm . '%';
            $sStmt->bind_param('sssssss', $sLike, $sLike, $sLike, $sLike, $sLike, $sLike, $sLike);
            $sStmt->execute();
            $stockHits = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        }
        if (!empty($stockHits))
            $results['stock'] = $stockHits;

        $foundAreas = array_keys($results);
        if (!empty($foundAreas)) {
            $searchTerm = $splitTerm;
        }
    }

    if (empty($foundAreas)) {
        // No ERP data found — don't block, fall through to normal processing
        // (KB lookup, calculator, LLM, etc.)
        $prompt = $searchTerm;
        $p = mb_strtolower($prompt, 'UTF-8');
        $commandType = null;
    }

    if (count($foundAreas) === 1) {
        $area = $foundAreas[0];
        if ($area === 'jobs') {
            $_SESSION['ai_priority_mode'] = 'job';
            $prompt = $searchTerm;
            $p = mb_strtolower($prompt, 'UTF-8');
        } elseif ($area === 'plates') {
            $_SESSION['ai_priority_mode'] = 'plate';
            $prompt = $searchTerm;
            $p = mb_strtolower($prompt, 'UTF-8');
        } elseif ($area === 'stock') {
            $_SESSION['ai_priority_mode'] = 'paperstock';
            $prompt = $searchTerm;
            $p = mb_strtolower($prompt, 'UTF-8');
        }
    } else {
        $areaLabels = [];
        if (isset($results['jobs'])) {
            $areaLabels['jobs'] = ['bn' => "📋 **জব প্ল্যানিং / রানিং জব** (" . count($results['jobs']) . " টি)", 'hi' => "📋 **जॉब प्लानिंग / रनिंग जॉब** (" . count($results['jobs']) . " टि)", 'en' => "📋 **Job Planning / Running Jobs** (" . count($results['jobs']) . ")"];
        }
        if (isset($results['plates'])) {
            $areaLabels['plates'] = ['bn' => "🖼️ **প্লেট ডাটা** (" . count($results['plates']) . " টি)", 'hi' => "🖼️ **प्लेट डेटा** (" . count($results['plates']) . " टि)", 'en' => "🖼️ **Plate Data** (" . count($results['plates']) . ")"];
        }
        if (isset($results['stock'])) {
            $areaLabels['stock'] = ['bn' => "📦 **পেপার স্টক** (" . count($results['stock']) . " টি)", 'hi' => "📦 **पेपर स्टॉक** (" . count($results['stock']) . " टि)", 'en' => "📦 **Paper Stock** (" . count($results['stock']) . ")"];
        }
        $langCode = ($userLang === 'Bengali') ? 'bn' : (($userLang === 'Hindi') ? 'hi' : 'en');
        $areaLines = array_map(fn($a) => $a[$langCode], $areaLabels);
        $areaStr = implode("\n", $areaLines);

        if ($userLang === 'Bengali') {
            $answer = "🔍 **\"$searchTerm\" — একাধিক জায়গায় পাওয়া গেছে:**\n\n$areaStr\n\n**আপনি কোনটি দেখতে চান?**\n• জব/প্রোডাকশন বিস্তারিত — বলুন \"জব\"\n• প্লেট বিস্তারিত — বলুন \"প্লেট\"\n• পেপার স্টক বিস্তারিত — বলুন \"পেপার\"";
            $suggestions = ['জব বিস্তারিত দেখাও', 'প্লেট বিস্তারিত দেখাও', 'পেপার স্টক বিস্তারিত দেখাও'];
        } elseif ($userLang === 'Hindi') {
            $answer = "🔍 **\"$searchTerm\" — कई जगह मिला:**\n\n$areaStr\n\n**आप कौन सा देखना चाहते हैं?**\n• जॉब/प्रोडक्शन विवरण — बोलें \"जॉब\"\n• प्लेट विवरण — बोलें \"प्लेट\"\n• पेपर स्टॉक विवरण — बोलें \"पेपर\"";
            $suggestions = ['जॉब विवरण दिखाओ', 'प्लेट विवरण दिखाओ', 'पेपर स्टॉक विवरण दिखाओ'];
        } else {
            $answer = "🔍 **\"$searchTerm\" — found in multiple areas:**\n\n$areaStr\n\n**What would you like to see?**\n• Job / Production details — say \"job\"\n• Plate details — say \"plate\"\n• Paper Stock details — say \"paper\"";
            $suggestions = ['Show Job details', 'Show Plate details', 'Show Paper Stock details'];
        }
        // Save search term in session so follow-up area selector (e.g. "plate") can use it
        $_SESSION['ai_ambiguous_search'] = ['term' => $searchTerm, 'areas' => $foundAreas];
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI', 'tool_used' => 'Ambiguous Quoted Search', 'user_lang' => $userLang, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// ─── Ambiguous Search Follow-up Handler ───
// When user previously got multi-area results and now sends a simple area selector
// like "plate", "job", "paper", or "Show Plate details", redirect to the saved search term.
if (isset($_SESSION['ai_ambiguous_search']) && !empty($foundAreas ?? [])) {
    // User clicked a suggestion chip or typed an area name — clear the stored context
    unset($_SESSION['ai_ambiguous_search']);
} elseif (isset($_SESSION['ai_ambiguous_search']) && preg_match('/^(show\s+)?(job|plate|paper|stock|paper\s*stock|জব|প্লেট|পেপার|प्लेट|जॉब|पेपर)\b/i', trim($prompt), $areaMatch)) {
    $savedSearch = $_SESSION['ai_ambiguous_search'];
    unset($_SESSION['ai_ambiguous_search']);
    $areaKey = strtolower($areaMatch[2]);
    // Map area keyword to priority mode
    if ($areaKey === 'plate' || $areaKey === 'প্লেট' || $areaKey === 'प्लेट') {
        $_SESSION['ai_priority_mode'] = 'plate';
    } elseif ($areaKey === 'job' || $areaKey === 'জব' || $areaKey === 'जॉब') {
        $_SESSION['ai_priority_mode'] = 'job';
    } elseif ($areaKey === 'paper' || $areaKey === 'stock' || $areaKey === 'paper stock' || $areaKey === 'পেপার' || $areaKey === 'पेपर') {
        $_SESSION['ai_priority_mode'] = 'paperstock';
    }
    // Override prompt with saved search term so the correct handler runs
    $prompt = $savedSearch['term'];
    $p = mb_strtolower($prompt, 'UTF-8');
}

// ─── Normal Mode Detection: No /command prefix → skip KB+DB, go directly to LLM ───
// If user typed a query without any / prefix, it is a "normal" query that should
// bypass the Knowledge Base and ERP database entirely and go straight to the external LLM.
if (!$commandType && !$erpOnlyMode && strpos($pTrimmed, '/') !== 0) {
    if (
        preg_match('/\b(p-\d+|plate-\d+|sl-\d+)\b/i', $prompt) ||
        preg_match('/\b(plate|die|paper|cylinder|live|floor|stock|roll|job|plan|planning|dashboard)\b/i', $prompt) ||
        strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false ||
        strpos($p, 'পেপার') !== false || strpos($p, 'ডাই') !== false ||
        strpos($p, 'সিলিন্ডার') !== false || strpos($p, 'রোল') !== false ||
        strpos($p, 'জব') !== false || strpos($p, 'লাইভ') !== false
    ) {
        $skipNormalDb = false;
    } else {
        $skipNormalDb = true;
    }
}

// ─── Inline Quoted Term Handler ("product name" in any prompt) ───
// If the prompt contains quoted text anywhere (e.g. "blue500" price), extract it as a
// product/item name, set ERP-only mode, and search Knowledge Base for that term first.
// NOTE: In normal mode ($skipNormalDb), quoted terms also skip KB+DB and go to LLM.
if (!$skipNormalDb && !$erpOnlyMode && !preg_match('/^\/plate\b/i', $prompt) && strpos($p, 'plate') === false && strpos($p, 'প্লেট') === false && preg_match('/["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/u', $prompt, $iqm)) {
    $quotedTerm = trim($iqm[1]);
    if ($quotedTerm !== '') {
        // Set ERP-only mode — only search KB + ERP database, skip external LLM
        $erpOnlyMode = true;

        // Direct KB lookup for the quoted term
        $kbResult = check_knowledge_base($db, $quotedTerm);
        if ($kbResult !== null) {
            $kbAnswer = $kbResult['answer'];
            $kbCategory = $kbResult['category'];
            echo json_encode([
                'ok' => true,
                'answer' => "🏷️ **Product/Item: \"{$quotedTerm}\"**\n\n{$kbAnswer}",
                'provider' => 'ERP AI (Product KB)',
                'tool_used' => 'Quoted Product Lookup',
                'user_lang' => $userLang,
                'kb_match_id' => (int) $kbResult['id']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // Also try searching the KB with the original prompt (user may have added context words)
        $kbResultWithContext = check_knowledge_base($db, $prompt);
        if ($kbResultWithContext !== null) {
            $kbAnswer = $kbResultWithContext['answer'];
            $kbCategory = $kbResultWithContext['category'];
            echo json_encode([
                'ok' => true,
                'answer' => "🏷️ **Product/Item: \"{$quotedTerm}\"**\n\n{$kbAnswer}",
                'provider' => 'ERP AI (Product KB)',
                'tool_used' => 'Quoted Product Lookup',
                'user_lang' => $userLang,
                'kb_match_id' => (int) $kbResultWithContext['id']
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        // If KB has no match, fall through to normal ERP-only processing
        // The $erpOnlyMode flag will skip external LLM later
    }
}

// ─── Knowledge Base Lookup (Admin-Trained Custom Answers) ───
function check_knowledge_base(mysqli $db, string $prompt): ?array
{
    // Ensure table exists (safe, uses IF NOT EXISTS)
    $db->query("CREATE TABLE IF NOT EXISTS `ai_agent_knowledge` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `category` ENUM('FAQ','Business Rule','Terminology','Quick Chip') NOT NULL DEFAULT 'FAQ',
      `keywords` TEXT NOT NULL,
      `question` VARCHAR(500) NULL,
      `answer` TEXT NOT NULL,
      `is_active` TINYINT(1) NOT NULL DEFAULT 1,
      `sort_order` INT NOT NULL DEFAULT 0,
      `created_by` INT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    $res = $db->query("SELECT * FROM ai_agent_knowledge WHERE is_active = 1 AND category != 'Intent' ORDER BY sort_order ASC, id DESC");
    if (!$res)
        return null;

    $promptLower = mb_strtolower(trim($prompt), 'UTF-8');
    preg_match_all('/[\x{0980}-\x{09FF}\x{0900}-\x{097F}\w]+/u', $promptLower, $promptMatches);
    $kbStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'show', 'details', 'detail', 'this', 'the', 'a', 'an', 'what', 'where', 'how', 'when', 'who', 'list', 'get', 'for', 'about', 'with', 'from', 'name', 'original', 'info', 'type', 'number', 'data', 'search', 'find', 'value', 'total', 'count', 'ache', 'koto', 'kotogulo', 'ki', 'kon', 'jabe', 'hote', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'হে', 'আছে', 'কত', 'কতগুলো', 'কি', 'কী', 'কোন', 'দিয়ে', 'গিয়ে', 'নাম', 'বলো', 'কোথায়', 'কোনটি', 'এবং', 'বা', 'এর', 'সেরা', 'টি', 'গুলো', 'গুলা', 'দাও', 'করো', 'করবে', 'কে', 'কেন', 'কবে', 'থেকে', 'তে', 'যে', 'ও', 'আর', 'হলো', 'হল', 'নাকি', 'তাই', 'যেন', 'তবে', 'সুতরাং', 'খুব', 'সবচেয়ে', 'কয়েকটি', 'বৈশিষ্ট্য', 'পৃথিবীর'];

    $promptTokens = array_filter($promptMatches[0] ?? [], function ($t) use ($kbStopwords) {
        return mb_strlen($t) >= 3 && !in_array($t, $kbStopwords, true);
    });

    $bestMatch = null;
    $bestScore = 0;

    while ($row = $res->fetch_assoc()) {
        $rawKeywords = array_map('trim', explode(',', mb_strtolower($row['keywords'], 'UTF-8')));
        $matchScore = 0;
        $matchedPairs = [];   // Track unique kwToken↔pToken pairs to prevent double-counting
        $hasExactMatch = false;
        $fuzzyPromptTokensMatched = []; // Track which distinct prompt tokens contributed fuzzy matches

        foreach ($rawKeywords as $kw) {
            $kwTrimmed = trim($kw);
            if ($kwTrimmed === '')
                continue;

            // Direct match when prompt equals keyword or contains keyword/phrase (e.g. "sitani", "aditya", "mriganka", "mrigank debnath")
            $kwLower = mb_strtolower($kwTrimmed, 'UTF-8');
            if ($kwLower === $promptLower || mb_strpos($promptLower, $kwLower) !== false) {
                $matchScore += 3.0; // Strong match for keyword/alias or phrase match
                $hasExactMatch = true;
            }

            // Token & Fuzzy Levenshtein match (e.g. "delevery" -> "delivery")
            preg_match_all('/[\x{0980}-\x{09FF}\x{0900}-\x{097F}\w]+/u', $kw, $kwMatches);
            $kwTokens = array_filter($kwMatches[0] ?? [], function ($t) use ($kbStopwords) {
                return mb_strlen($t) >= 3 && !in_array($t, $kbStopwords, true);
            });

            foreach ($kwTokens as $kwToken) {
                foreach ($promptTokens as $pToken) {
                    $pairKey = $pToken . '|' . $kwToken;
                    if (isset($matchedPairs[$pairKey]))
                        continue; // Skip already-counted pairs

                    if ($pToken === $kwToken) {
                        $matchScore += 2.0;
                        $hasExactMatch = true;
                        $matchedPairs[$pairKey] = true;
                    } else {
                        $pLen = mb_strlen($pToken);
                        $kLen = mb_strlen($kwToken);
                        if ($pLen >= 4 && $kLen >= 4) {
                            $lev = levenshtein($pToken, $kwToken);
                            // Strictly require lev <= 1 for short words (4-5 chars) to avoid false matches like "cross" vs "cost"
                            if (($pLen <= 5 && $lev <= 1) || ($pLen >= 6 && $lev <= 2)) {
                                $matchScore += 1.5;
                                $matchedPairs[$pairKey] = true;
                                $fuzzyPromptTokensMatched[$pToken] = true;
                            }
                        }
                    }
                }
            }
        }

        // If ONLY fuzzy matches contributed (no exact or phrase matches), require at least
        // 2 distinct prompt tokens to have matched — a single fuzzy token is too weak.
        if (!$hasExactMatch && count($fuzzyPromptTokensMatched) < 2) {
            $matchScore = min($matchScore, 1.9); // Clamp below threshold
        }

        if ($matchScore > $bestScore) {
            $bestScore = $matchScore;
            $bestMatch = $row;
        }
    }

    if ($bestMatch && $bestScore >= 2.5) {
        return $bestMatch;
    }
    return null;
}


// ─── Direct SQM <-> SQ Inch Unit Conversion Handler ───
if (strpos($p, 'inch') !== false && (strpos($p, 'sqm') !== false || strpos($p, 'sq mtr') !== false || strpos($p, 'sqr mtr') !== false || strpos($p, 'square meter') !== false) && !preg_match('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/', $prompt)) {

    preg_match('/(\d+(\.\d+)?)\s*(\/|per|\s*)\s*(sq inch|sqr inch|inch)|(sq inch|sqr inch|inch)\s*(is|=|:|\s*)\s*(\d+(\.\d+)?)/i', $prompt, $inchMatch);
    $givenInchRate = 0;
    if (!empty($inchMatch[1])) {
        $givenInchRate = (float) $inchMatch[1];
    } elseif (!empty($inchMatch[7])) {
        $givenInchRate = (float) $inchMatch[7];
    }

    if ($givenInchRate > 0) {
        $calcSqmRate = round($givenInchRate * 1550.0031, 2);

        if ($userLang === 'English') {
            $answer = "📐 **SQ Inch to SQM Paper Rate Conversion:**\n\n"
                . "• **Per SQ Inch Rate:** ₹" . number_format($givenInchRate, 4) . " / SQ Inch\n"
                . "• **1 SQM =** 1,550.0031 SQ Inches\n"
                . "• **Calculated Rate per SQM:** **₹" . number_format($calcSqmRate, 2) . "** / SQM\n\n"
                . "💡 **Formula:** `Per SQM Rate = Per SQ Inch Rate × 1550.0031` (" . number_format($givenInchRate, 4) . " × 1550.0031 = ₹" . number_format($calcSqmRate, 2) . " / SQM)\n";
        } elseif ($userLang === 'Hindi') {
            $answer = "📐 **SQ Inch से SQM पेपर रेट गणना:**\n\n"
                . "• **प्रति वर्ग इंच दर:** ₹" . number_format($givenInchRate, 4) . " / SQ Inch\n"
                . "• **1 वर्ग मीटर =** 1,550.0031 वर्ग इंच\n"
                . "• **गणना की गई प्रति वर्ग मीटर दर:** **₹" . number_format($calcSqmRate, 2) . "** / SQM\n\n"
                . "💡 **सूत्र:** `Per SQM Rate = Per SQ Inch Rate × 1550.0031` (" . number_format($givenInchRate, 4) . " × 1550.0031 = ₹" . number_format($calcSqmRate, 2) . " / SQM)\n";
        } else {
            $answer = "📐 **SQ Inch থেকে SQM পেপার রেট হিসাব:**\n\n"
                . "• **প্রতি স্কয়ার ইঞ্চি দাম:** ₹" . number_format($givenInchRate, 4) . " / SQ Inch\n"
                . "• **১ স্কয়ার মিটার =** 1,550.0031 স্কয়ার ইঞ্চি\n"
                . "• **গণনা কী প্রতি স্কয়ার মিটার দাম:** **₹" . number_format($calcSqmRate, 2) . "** / SQM\n\n"
                . "💡 **গাণিতিক নিয়ম:** `Per SQM Rate = Per SQ Inch Rate × 1550.0031` (" . number_format($givenInchRate, 4) . " × 1550.0031 = ₹" . number_format($calcSqmRate, 2) . " / SQM)\n";
        }
    } else {
        preg_match('/(\d+(\.\d+)?)\s*(\/|per|\s*)\s*(sqm|sq mtr|sqr mtr|square meter)/i', $prompt, $sqmMatch);
        $sqmRate = !empty($sqmMatch[1]) ? (float) $sqmMatch[1] : 20.00;
        $sqInchRate = round($sqmRate / 1550.0031, 6);
        $sqInchFormatted = number_format($sqInchRate, 4);
        $sqInchPaise = round($sqInchRate * 100, 2);

        if ($userLang === 'English') {
            $answer = "📐 **SQM to SQ Inch Paper Rate Conversion:**\n\n"
                . "• **Per SQM Rate:** ₹" . number_format($sqmRate, 2) . " / SQM\n"
                . "• **1 SQM =** 1,550.0031 SQ Inches\n"
                . "• **Calculated Cost per SQ Inch:** **₹{$sqInchFormatted}** / SQ Inch (or **{$sqInchPaise} Paise** / SQ Inch)\n\n"
                . "💡 **Formula:** `Per SQ Inch Cost = Per SQM Rate / 1550.0031` (" . number_format($sqmRate, 2) . " ÷ 1550.0031 = ₹{$sqInchFormatted})\n";
        } elseif ($userLang === 'Hindi') {
            $answer = "📐 **SQM से SQ Inch पेपर रेट गणना:**\n\n"
                . "• **प्रति वर्ग मीटर दर:** ₹" . number_format($sqmRate, 2) . " / SQM\n"
                . "• **1 वर्ग मीटर =** 1,550.0031 वर्ग इंच\n"
                . "• **गणना की गई प्रति वर्ग इंच लागत:** **₹{$sqInchFormatted}** / SQ Inch (या **{$sqInchPaise} पैसे** / वर्ग इंच)\n\n"
                . "💡 **सूत्र:** `Per SQ Inch Cost = Per SQM Rate / 1550.0031` (" . number_format($sqmRate, 2) . " ÷ 1550.0031 = ₹{$sqInchFormatted})\n";
        } else {
            $answer = "📐 **SQM থেকে SQ Inch পেপার রেট হিসাব:**\n\n"
                . "• **স্কয়ার মিটার দাম (Per SQM Rate):** ₹" . number_format($sqmRate, 2) . " / SQM\n"
                . "• **১ স্কয়ার মিটার (1 SQM):** 1,550.0031 স্কয়ার ইঞ্চি (SQ Inches)\n"
                . "• **প্রতি স্কয়ার ইঞ্চি দাম (Per SQ Inch Cost):** **₹{$sqInchFormatted}** / SQ Inch (বা **{$sqInchPaise} পয়সা** / স্কয়ার ইঞ্চি)\n\n"
                . "💡 **গাণিতিক নিয়ম:** `Per SQ Inch = Per SQM Rate / 1550.0031` (" . number_format($sqmRate, 2) . " ÷ 1550.0031 = ₹{$sqInchFormatted})\n";
        }
    }

    $sqmSuggestions = generate_erp_suggestions($prompt, $answer, 'SQM & SQ Inch Unit Converter', $userLang);
    echo json_encode([
        'ok' => true,
        'answer' => $answer,
        'provider' => 'ERP Industrial Unit Conversion Engine',
        'tool_used' => 'SQM & SQ Inch Unit Converter',
        'user_lang' => $userLang,
        'suggestions' => $sqmSuggestions
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// ─── KB Skip-Logic: Queries with specific data entities bypass KB, go straight to ERP search ───
$kbSkipPatterns = ['how many', 'কত', 'কতগুলো', 'কতটা', 'kitne', 'kitna', 'total count', 'total rolls', 'total paper', 'stock count', 'roll count', 'paper count', 'summary', 'সারসংক্ষেপ', 'সমষ্টি', 'सारांश', 'कुल गिनती', 'date', 'time', 'ajke', 'kon date', 'today', 'tarikh', 'তারিখ', 'আজকে', 'সময়', 'somoy', 'hello', 'hi ', 'kemon acho', 'purchase rate', 'দাম', 'দামি', 'রেট', 'মূল্য', 'rate', 'price', 'costly', 'expensive', 'দাম কত', 'সবচেয়ে দামি', 'গড় rate', 'avg rate', 'दाम', 'रेट', 'कीमत', 'lot batch', 'lot no', 'batch no', 'লট', 'ব্যাচ', 'company roll', 'pdf', 'excel', 'csv', 'export', 'report', 'print', 'প্রিন্ট', 'এক্সপোর্ট', 'রিপোর্ট', 'download', 'ডাউনলোড', 'plate', 'cylinder', 'ups', 'repeat', 'cmyk', 'die', 'প্লেট', 'সিলিন্ডার', 'प्लेट', 'lakh', 'লাখ', 'mixed', 'মিক্সড', 'এক্সট্রা', 'extra pool', 'repack', 'mixed item', 'finished', 'ফিনিশড', 'প্যাকড', 'packed'];
$kbSkipEntities = ['krishna', 'austin', 'nrgi', 'navkar', 'abhinav', 'nitin', 'avery', 'chromo', 'thermal', 'pp white', 'pp-clear', 'maplitho', 'metallic', 'plastic', 'flexo', 'creative', 'pidilite', 'sfl'];
$strictModes = ['plate', 'dispatch', 'job', 'paperstock'];
$skipKB = (in_array(($commandType ?? ''), $strictModes) || in_array(($_SESSION['ai_priority_mode'] ?? ''), $strictModes));
foreach ($kbSkipPatterns as $pat) {
    if (mb_strpos($p, $pat) !== false) {
        $skipKB = true;
        break;
    }
}
if (!$skipKB) {
    foreach ($kbSkipEntities as $ent) {
        if (mb_strpos($p, $ent) !== false) {
            $skipKB = true;
            break;
        }
    }
}
// Skip KB when prompt has plate/roll/job keyword + a specific number (user wants to look up a record, not read FAQ)
if (!$skipKB && preg_match('/\b\d{2,}\b/', $prompt) && preg_match('/(plate|প্লেট|प्लेट|roll|রোল|रोल|job|জব|जॉब)/iu', $p)) {
    $skipKB = true;
}
// Skip KB when query is mm dimension → square inch area conversion
if (!$skipKB && preg_match('/\d+(?:\.\d+)?\s*mm\s*[xX*]\s*\d+(?:\.\d+)?\s*mm/i', $prompt) && preg_match('/sqr?\s*inch(es)?|sq\.?\s*in|square\s*inch(es)?/i', $prompt)) {
    $skipKB = true;
}

// ─── Module Page Navigation Handler ("View Plate Management", "Open Paper Stock page", etc.) ───
if (!$skipKB && preg_match('/^(view|open|show|go\s*to)\s+(plate|paper\s*stock|paper|stock|job|dispatch|dashboard|setting|settings)\s*(management|page)?\s*(page)?$/i', trim($prompt), $navM)) {
    $navTarget = strtolower(trim($navM[2]));
    $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
    $navUrl = '';
    $pageTitle = '';

    if ($navTarget === 'plate' || strpos($navTarget, 'plate') !== false) {
        $navUrl = $baseUrl . '/modules/plate-tools/plate-management/index.php';
        $pageTitle = 'Plate Management';
    } elseif ($navTarget === 'paper stock' || $navTarget === 'paper' || $navTarget === 'stock') {
        $navUrl = $baseUrl . '/modules/paper_stock/index.php';
        $pageTitle = 'Paper Stock';
    } elseif ($navTarget === 'job') {
        $navUrl = $baseUrl . '/modules/planning/index.php';
        $pageTitle = 'Job Planning';
    } elseif ($navTarget === 'dispatch') {
        $navUrl = $baseUrl . '/modules/dispatch/index.php';
        $pageTitle = 'Dispatch';
    } elseif ($navTarget === 'dashboard') {
        $navUrl = $baseUrl . '/modules/dashboard/index.php';
        $pageTitle = 'Dashboard';
    } elseif ($navTarget === 'setting' || $navTarget === 'settings') {
        $navUrl = $baseUrl . '/modules/settings/index.php';
        $pageTitle = 'Settings';
    }

    if ($navUrl) {
        if ($userLang === 'Bengali') {
            $answer = "📂 **$pageTitle পেজে রিডিরেক্ট করা হচ্ছে...**\n\n👉 [$pageTitle খুলুন]($navUrl)";
            $suggestions = ['প্লেট ম্যানেজমেন্ট', 'পেপার স্টক দেখুন', 'ড্যাশবোর্ডে যান'];
        } elseif ($userLang === 'Hindi') {
            $answer = "📂 **$pageTitle पेज पर रीडायरेक्ट हो रहा है...**\n\n👉 [$pageTitle खोलें]($navUrl)";
            $suggestions = ['प्लेट मैनेजमेंट', 'पेपर स्टॉक देखें', 'डैशबोर्ड पर जाएं'];
        } else {
            $answer = "📂 **Redirecting to $pageTitle page...**\n\n👉 [Open $pageTitle]($navUrl)";
            $suggestions = ['Show Plate Management', 'View Paper Stock', 'Go to Dashboard'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI', 'tool_used' => "Navigate to $pageTitle", 'user_lang' => $userLang, 'nav_url' => $navUrl, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

$isPaperStockQueryForKB = preg_match('/\b(paper|stock|roll|slitting)\b/i', $prompt);
$knowledgeMatch = ($skipKB || $isPaperStockQueryForKB) ? null : check_knowledge_base($db, $prompt);
if ($knowledgeMatch !== null) {

    $kbAnswer = $knowledgeMatch['answer'];
    $kbCategory = $knowledgeMatch['category'];

    $kbFinalAnswer = "📚 " . $kbAnswer;
    $kbSuggestions = generate_erp_suggestions($prompt, $kbFinalAnswer, 'Admin Knowledge Base (' . $kbCategory . ')', $userLang);
    echo json_encode([
        'ok' => true,
        'answer' => $kbFinalAnswer,
        'provider' => 'ERP AI Knowledge Base',
        'tool_used' => 'Admin Knowledge Base (' . $kbCategory . ')',
        'user_lang' => $userLang,
        'kb_match_id' => (int) $knowledgeMatch['id'],
        'suggestions' => $kbSuggestions
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}




/**
 * Industrial Label Mathematics Engine
 */
function calculate_label_costing_math(string $prompt): array
{
    $p = mb_strtolower($prompt, 'UTF-8');

    // Size (e.g. 100mm x 50mm)
    preg_match('/(\d+(\.\d+)?)\s*mm\s*[xX*]\s*(\d+(\.\d+)?)\s*mm/i', $prompt, $sizeMatch);
    $widthMm = $sizeMatch ? (float) $sizeMatch[1] : 100;
    $lengthMm = $sizeMatch ? (float) $sizeMatch[3] : 50;

    // Ups
    preg_match('/(\d+)\s*ups/i', $prompt, $upsMatch);
    $ups = $upsMatch ? max(1, (int) $upsMatch[1]) : 2;

    // Repeat Gap
    preg_match('/(\d+(\.\d+)?)\s*mm\s*gap|gap\s*(is\s*)?(\d+(\.\d+)?)\s*mm|middle gap is\s*(\d+(\.\d+)?)\s*mm/i', $prompt, $gapMatch);
    $gapMm = 5;
    if (!empty($gapMatch[1]))
        $gapMm = (float) $gapMatch[1];
    elseif (!empty($gapMatch[4]))
        $gapMm = (float) $gapMatch[4];
    elseif (!empty($gapMatch[6]))
        $gapMm = (float) $gapMatch[6];

    // Quantity (e.g. 50000 pices or 15000 qty)
    $cleanPromptForQty = preg_replace('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/i', '', $prompt);
    preg_match('/(\d{3,9})\s*(qnt|qty|pcs|pices|pieces|labels|required)?/i', $cleanPromptForQty, $qtyMatch);
    $qty = $qtyMatch ? (float) $qtyMatch[1] : 50000;

    // Roll Width
    preg_match('/(\d+(\.\d+)?)\s*mm\s*roll|roll\s*(\d+(\.\d+)?)\s*mm/i', $prompt, $rollMatch);
    $hasRollWidth = !empty($rollMatch[1]) || !empty($rollMatch[3]);
    $parentWidthMm = 0;
    if (!empty($rollMatch[1]))
        $parentWidthMm = (float) $rollMatch[1];
    elseif (!empty($rollMatch[3]))
        $parentWidthMm = (float) $rollMatch[3];

    // Rate
    preg_match('/(\d+(\.\d+)?)\s*(\/|per|\s*)\s*(kg|sqm|sq mtr|sqr mtr|square meter|sq inch|sqr inch)/i', $prompt, $rateMatch);
    $hasRate = !empty($rateMatch);
    $ratePerKg = 0;
    $ratePerSqm = 0;
    if ($rateMatch) {
        $val = (float) $rateMatch[1];
        $unit = strtolower($rateMatch[4]);
        if ($unit === 'kg')
            $ratePerKg = $val;
        else
            $ratePerSqm = $val;
    }

    $gsm = 80;

    // Calculations
    $repeatPitchMm = $lengthMm + $gapMm;
    $impressions = ceil($qty / $ups);
    $runningMeters = round(($impressions * $repeatPitchMm) / 1000, 2);

    $netUsedWidthMm = $widthMm * $ups;
    $sideWasteWidthMm = $parentWidthMm > 0 ? max(0, $parentWidthMm - $netUsedWidthMm) : 0;
    $totalPaperSqm = $parentWidthMm > 0 ? round(($parentWidthMm / 1000) * $runningMeters, 4) : round(($netUsedWidthMm / 1000) * $runningMeters, 4);
    $netLabelSqm = round(($netUsedWidthMm / 1000) * $runningMeters, 4);
    $wasteSqm = $parentWidthMm > 0 ? round(($sideWasteWidthMm / 1000) * $runningMeters, 4) : 0;
    $sideWastePct = $parentWidthMm > 0 ? round(($sideWasteWidthMm / $parentWidthMm) * 100, 2) : 0;

    $totalWeightKg = round(($totalPaperSqm * $gsm) / 1000, 2);
    $wasteWeightKg = round(($wasteSqm * $gsm) / 1000, 2);

    $totalPaperCost = 0;
    $wasteCost = 0;
    if ($ratePerKg > 0) {
        $totalPaperCost = round($totalWeightKg * $ratePerKg, 2);
        $wasteCost = round($wasteWeightKg * $ratePerKg, 2);
    } elseif ($ratePerSqm > 0) {
        $totalPaperCost = round($totalPaperSqm * $ratePerSqm, 2);
        $wasteCost = round($wasteSqm * $ratePerSqm, 2);
    }

    $costPerLabel = $qty > 0 ? round($totalPaperCost / $qty, 4) : 0;
    $pricePerSqInch = $ratePerSqm > 0 ? round($ratePerSqm / 1550.0031, 4) : ($ratePerKg > 0 ? round(($totalPaperCost / max(1, $totalPaperSqm)) / 1550.0031, 4) : 0);

    return [
        'width_mm' => $widthMm,
        'length_mm' => $lengthMm,
        'ups' => $ups,
        'gap_mm' => $gapMm,
        'repeat_pitch_mm' => $repeatPitchMm,
        'qty' => $qty,
        'impressions' => $impressions,
        'running_meters' => $runningMeters,
        'has_roll_width' => $hasRollWidth,
        'has_rate' => $hasRate,
        'parent_width_mm' => $parentWidthMm,
        'net_used_width_mm' => $netUsedWidthMm,
        'side_waste_width_mm' => $sideWasteWidthMm,
        'side_waste_pct' => $sideWastePct,
        'total_paper_sqm' => $totalPaperSqm,
        'net_label_sqm' => $netLabelSqm,
        'waste_sqm' => $wasteSqm,
        'gsm' => $gsm,
        'total_weight_kg' => $totalWeightKg,
        'waste_weight_kg' => $wasteWeightKg,
        'rate_per_kg' => $ratePerKg,
        'rate_per_sqm' => $ratePerSqm,
        'total_paper_cost' => $totalPaperCost,
        'waste_cost' => $wasteCost,
        'cost_per_label' => $costPerLabel,
        'price_per_sq_inch' => $pricePerSqInch
    ];
}

// ─── Simple Arithmetic Expression Evaluator ───
// Handles expressions like "2+2", "500*3", "(2500+500)/2", "15000*2"
// NOTE: In normal mode ($skipNormalDb), arithmetic is handled by the external LLM instead.
$isSimpleArithmetic = false;
$arithmeticResult = null;
if (!$skipNormalDb) {
    $cleanArith = trim(preg_replace('/[+\-*\/()%\s\d.,]/', '', $prompt));
    if ($cleanArith === '' && preg_match('/^[\d+\-*\/()%\s.,]+$/', trim($prompt)) && preg_match('/[+\-*\/%]/', $prompt)) {
        // Must have at least one operator and look like a calculation
        $expr = str_replace(',', '', trim($prompt));
        // Validate: only digits, basic operators, parens, spaces, dots allowed
        if (preg_match('/^[\d+\-*\/()%. ]+$/', $expr) && preg_match('/\d/', $expr)) {
            try {
                $result = @eval ("return $expr;");
                if ($result !== false && !is_nan($result) && is_finite($result)) {
                    $isSimpleArithmetic = true;
                    $arithmeticResult = $result;
                }
            } catch (\Throwable $e) {
                // Not a valid arithmetic expression, ignore
            }
        }
    }

    if ($isSimpleArithmetic) {
        $resultFormatted = is_float($arithmeticResult) ? rtrim(rtrim(number_format($arithmeticResult, 6), '0'), '.') : $arithmeticResult;
        if ($userLang === 'English') {
            $answer = "🧮 **Calculation Result:**\n\n"
                . "• **Expression:** `{$prompt}`\n"
                . "• **Result:** **{$resultFormatted}**\n";
        } elseif ($userLang === 'Hindi') {
            $answer = "🧮 **गणना परिणाम:**\n\n"
                . "• **व्यंजक:** `{$prompt}`\n"
                . "• **परिणाम:** **{$resultFormatted}**\n";
        } else {
            $answer = "🧮 **গণনার ফলাফল:**\n\n"
                . "• **রাশি:** `{$prompt}`\n"
                . "• **ফলাফল:** **{$resultFormatted}**\n";
        }
        $calciSuggestions = generate_erp_suggestions($prompt, $answer, 'Simple Calculator', $userLang);
        echo json_encode([
            'ok' => true,
            'answer' => $answer,
            'provider' => 'ERP AI Arithmetic Engine',
            'tool_used' => 'Simple Calculator',
            'user_lang' => $userLang,
            'suggestions' => $calciSuggestions
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

} // end of !$skipNormalDb guard for arithmetic evaluator

$hasCompanyQuery = preg_match('/\b(krishna|austin|navkar|nrgi)\b/i', $prompt) || strpos($p, 'কৃষ্ণা') !== false || strpos($p, 'অস্টিন') !== false || strpos($p, 'নভকার') !== false || strpos($p, 'এনআরজিআই') !== false;
$hasDbQueryIntent = preg_match('/\b(die|dies|plate|plates|stock|inventory|search|find|any|is there|kono|ache)\b/i', $prompt);

$isMathIntent = !$hasCompanyQuery && !$hasDbQueryIntent && (
    preg_match('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/i', $prompt) ||
    strpos($p, 'running meter') !== false ||
    strpos($p, 'running mtr') !== false ||
    (strpos($p, 'ups') !== false && strpos($p, 'gap') !== false)
);

// ─── Simple mm² → Square Inch Area Conversion ───
// NOTE: In normal mode ($skipNormalDb), unit conversion is handled by the external LLM instead.
$mmAreaMatch = [];
if (!$skipNormalDb && preg_match('/(\d+(?:\.\d+)?)\s*mm\s*[xX*]\s*(\d+(?:\.\d+)?)\s*mm/i', $prompt, $mmAreaMatch)) {
    $askSqInch = preg_match('/sqr?\s*inch(es)?|sq\.?\s*in|square\s*inch(es)?/i', $prompt);
    if ($askSqInch) {
        $mmW = (float) $mmAreaMatch[1];
        $mmL = (float) $mmAreaMatch[2];
        $mm2 = $mmW * $mmL;
        $sqInches = round($mm2 / 645.16, 4);
        $mmWInch = round($mmW / 25.4, 3);
        $mmLInch = round($mmL / 25.4, 3);

        if ($userLang === 'English') {
            $answer = "📐 **Millimeter to Square Inch Area Conversion:**\n\n"
                . "• **Dimensions:** {$mmW}mm × {$mmL}mm\n"
                . "• **In Inches:** {$mmWInch}″ × {$mmLInch}″\n"
                . "• **Area:** **{$mm2} mm²** = **{$sqInches} sq in**\n\n"
                . "💡 **Formula:** `({$mmW} × {$mmL}) ÷ 645.16 = {$sqInches} sq in`\n"
                . "*(1 sq in = 25.4mm × 25.4mm = 645.16 mm²)*";
        } elseif ($userLang === 'Hindi') {
            $answer = "📐 **मिलीमीटर से वर्ग इंच क्षेत्रफल रूपांतरण:**\n\n"
                . "• **आयाम:** {$mmW}mm × {$mmL}mm\n"
                . "• **इंच में:** {$mmWInch}″ × {$mmLInch}″\n"
                . "• **क्षेत्रफल:** **{$mm2} mm²** = **{$sqInches} वर्ग इंच**\n\n"
                . "💡 **सूत्र:** `({$mmW} × {$mmL}) ÷ 645.16 = {$sqInches} वर्ग इंच`\n"
                . "*(1 वर्ग इंच = 25.4mm × 25.4mm = 645.16 mm²)*";
        } else {
            $answer = "📐 **মিলিমিটার থেকে স্কয়ার ইঞ্চি এলাকা রূপান্তর:**\n\n"
                . "• **মাপ:** {$mmW}mm × {$mmL}mm\n"
                . "• **ইঞ্চিতে:** {$mmWInch}″ × {$mmLInch}″\n"
                . "• **এলাকা:** **{$mm2} mm²** = **{$sqInches} বর্গ ইঞ্চি**\n\n"
                . "💡 **সূত্র:** `({$mmW} × {$mmL}) ÷ 645.16 = {$sqInches} বর্গ ইঞ্চি`\n"
                . "*(1 বর্গ ইঞ্চি = 25.4mm × 25.4mm = 645.16 mm²)*";
        }

        $mmSugg = generate_erp_suggestions($prompt, $answer, 'mm² to Square Inch Converter', $userLang);
        echo json_encode([
            'ok' => true,
            'answer' => $answer,
            'provider' => 'ERP Industrial Unit Conversion Engine',
            'tool_used' => 'mm² to Square Inch Converter',
            'user_lang' => $userLang,
            'suggestions' => $mmSugg
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($isMathIntent && !$skipNormalDb) {
    $math = calculate_label_costing_math($prompt);


    // ─── Build area/weight output ───
    if ($math['has_roll_width']) {
        $sqmBlock = "• **Total Paper Area:** **{$math['total_paper_sqm']} SQM** (Roll {$math['parent_width_mm']}mm)\n"
            . "• **Net Label Area:** **{$math['net_label_sqm']} SQM** (Used {$math['net_used_width_mm']}mm)\n"
            . "• **Side Waste:** {$math['side_waste_width_mm']}mm ({$math['side_waste_pct']}%) — **{$math['waste_sqm']} SQM**\n"
            . "• **Est. Weight (80 GSM):** **{$math['total_weight_kg']} KG** (Waste: {$math['waste_weight_kg']} KG)";
    } else {
        $sqmBlock = "• **Net Label Area:** **{$math['net_label_sqm']} SQM** (Used width {$math['net_used_width_mm']}mm)\n"
            . "• **Est. Weight (80 GSM):** **{$math['total_weight_kg']} KG**\n"
            . "📏 *Add parent roll width (e.g. `on 250mm roll`) for waste & total SQM*";
    }

    // ─── Build cost output ───
    $costBlock = '';
    if ($math['has_rate']) {
        $costBlock = "• **Total Paper Cost:** **₹" . number_format($math['total_paper_cost'], 2) . "**\n"
            . "• **Cost per Label:** **₹" . number_format($math['cost_per_label'], 4) . "**\n"
            . "• **Rate per SQ Inch:** **₹{$math['price_per_sq_inch']}** / sq in\n";
        if ($math['has_roll_width']) {
            $costBlock .= "• **Waste Cost:** **₹" . number_format($math['waste_cost'], 2) . "**\n";
        }
    }

    if ($userLang === 'English') {
        $answer = "🧮 **Industrial Label — Full Calculation**\n\n"
            . "**📋 Job Specs:**\n"
            . "• **Label:** {$math['width_mm']}mm × {$math['length_mm']}mm | **{$math['ups']} Up** | Gap: {$math['gap_mm']}mm\n"
            . "• **Qty:** " . number_format($math['qty']) . " pcs | **Impressions:** " . number_format($math['impressions']) . "\n"
            . "• **Repeat Pitch:** {$math['repeat_pitch_mm']}mm | **Running Meters:** **" . number_format($math['running_meters'], 2) . " m**\n"
            . "\n**📐 Area & Weight:**\n{$sqmBlock}\n";
        if ($costBlock) {
            $answer .= "\n**💰 Costing:**\n{$costBlock}";
        }
        if (!$math['has_roll_width']) {
            $answer .= "\n💡 *For waste & total SQM, tell roll width (e.g. `on 250mm roll`)*\n";
        }
        if (!$math['has_rate']) {
            $answer .= "💡 *For pricing, tell paper rate (e.g. `at Rs 300/kg`)*\n";
        }
    } elseif ($userLang === 'Hindi') {
        $answer = "🧮 **औद्योगिक लेबल — पूर्ण गणना**\n\n"
            . "**📋 जॉब विवरण:**\n"
            . "• **लेबल:** {$math['width_mm']}mm × {$math['length_mm']}mm | **{$math['ups']} Up** | गैप: {$math['gap_mm']}mm\n"
            . "• **मात्रा:** " . number_format($math['qty']) . " पीस | **इम्प्रेशन:** " . number_format($math['impressions']) . "\n"
            . "• **रिपीट पिच:** {$math['repeat_pitch_mm']}mm | **रनिंग मीटर:** **" . number_format($math['running_meters'], 2) . " मीटर**\n"
            . "\n**📐 क्षेत्रफल & वजन:**\n{$sqmBlock}\n";
        if ($costBlock) {
            $answer .= "\n**💰 लागत:**\n{$costBlock}";
        }
        if (!$math['has_roll_width']) {
            $answer .= "\n💡 *वेस्टेज और कुल SQM के लिए रोल चौड़ाई बताएं (जैसे `250mm roll`)*\n";
        }
        if (!$math['has_rate']) {
            $answer .= "💡 *मूल्य के लिए पेपर दर बताएं (जैसे `Rs 300/kg`)*\n";
        }
    } else {
        $answer = "🧮 **ইন্ডাস্ট্রিয়াল লেবেল — পূর্ণ গণনা**\n\n"
            . "**📋 জব স্পেসিফিকেশন:**\n"
            . "• **লেবেল:** {$math['width_mm']}mm × {$math['length_mm']}mm | **{$math['ups']} Up** | গ্যাপ: {$math['gap_mm']}mm\n"
            . "• **পরিমাণ:** " . number_format($math['qty']) . " পিস | **ইম্প্রেশন:** " . number_format($math['impressions']) . "\n"
            . "• **রিপিট পিচ:** {$math['repeat_pitch_mm']}mm | **রানিং মিটার:** **" . number_format($math['running_meters'], 2) . " মিটার**\n"
            . "\n**📐 এলাকা ও ওজন:**\n{$sqmBlock}\n";
        if ($costBlock) {
            $answer .= "\n**💰 খরচ:**\n{$costBlock}";
        }
        if (!$math['has_roll_width']) {
            $answer .= "\n💡 *ওয়েস্টেজ ও মোট SQM-এর জন্য রোল চওড়া দিন (যেমন: `250mm roll`)*\n";
        }
        if (!$math['has_rate']) {
            $answer .= "💡 *দামের জন্য পেপার রেট দিন (যেমন: `Rs 300/kg`)*\n";
        }
    }

    $labelSugg = generate_erp_suggestions($prompt, $answer, 'Industrial Label Calculator', $userLang);
    echo json_encode([
        'ok' => true,
        'answer' => $answer,
        'provider' => 'ERP Industrial Label Math Engine',
        'tool_used' => 'Industrial Label Calculator',
        'user_lang' => $userLang,
        'suggestions' => $labelSugg
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Regular DB Query Router
function fetch_erp_data_by_intent(mysqli $db, string $prompt, string $userLang): array
{
    global $commandType;
    $p = mb_strtolower($prompt, 'UTF-8'); // $commandType set at top-level by router; needed inside this function
    $data = [];
    $totalCount = 0;
    $totalMeters = 0;
    $filteredType = '';
    $isCompanyList = false;
    $toolName = 'ERP Knowledge Engine';
    $searchNums = extract_search_numbers($prompt);

    // ─── STRICT MODE ENFORCEMENT ───
    if ($commandType === 'paperstock' || $commandType === 'plate') {
        $pClean = trim(mb_strtolower($prompt, 'UTF-8'));
        $pCleanHtml = htmlspecialchars($pClean);

        if ($commandType === 'paperstock') {
            $hasPaperKeywords = (strpos($pClean, 'paper') !== false || strpos($pClean, 'roll') !== false || strpos($pClean, 'slc/') !== false || strpos($pClean, 'stock') !== false || preg_match('/(chromo|thermal|maplitho|pp white|pp clear|jumbo|avery|krishna|austin|navkar|nrgi)/i', $pClean));
            $hasOtherKeywords = preg_match('/(operator|job|plate|die|dispatch|packing|invoice|planning|production summary)/i', $pClean);

            if (!$hasPaperKeywords && $hasOtherKeywords) {
                return [
                    'tool_used' => 'Context Validator',
                    'type' => 'strict_mode_violation',
                    'title' => '❌ Invalid Query Context',
                    'total_count' => 0,
                    'total_meters' => 0,
                    'filtered_type' => '',
                    'is_company_list' => false,
                    'direct_answer' => "⚠️ You are in **Paper Stock Mode**, but your question seems to be about something else.\n\n👉 **To ask about this, please use `/erp {$pCleanHtml}` instead.**",
                    'nav_url' => '',
                    'data' => []
                ];
            }
        } else if ($commandType === 'plate') {
            $hasPlateKeywords = (strpos($pClean, 'plate') !== false || strpos($pClean, 'cylinder') !== false || strpos($pClean, 'die') !== false);
            $hasOtherKeywords = preg_match('/(operator|dispatch|packing|invoice|planning|paper|roll|chromo|thermal)/i', $pClean);
            if (!$hasPlateKeywords && $hasOtherKeywords) {
                return [
                    'tool_used' => 'Context Validator',
                    'type' => 'strict_mode_violation',
                    'title' => '❌ Invalid Query Context',
                    'total_count' => 0,
                    'total_meters' => 0,
                    'filtered_type' => '',
                    'is_company_list' => false,
                    'direct_answer' => "⚠️ You are in **Plate Mode**, but your question seems to be about something else.\n\n👉 **To ask about this, please use `/erp {$pCleanHtml}` instead.**",
                    'nav_url' => '',
                    'data' => []
                ];
            }
        }
    } else if ($commandType === 'dispatch' || $commandType === 'job') {
        $pClean = trim(mb_strtolower($prompt, 'UTF-8'));
        $pCleanHtml = htmlspecialchars($pClean);

        if ($commandType === 'dispatch') {
            $hasDispatchKeywords = preg_match('/(dispatch|packing|ready|slip|delivery|challan|sales person|ডিস্পैচ|রেডি)/i', $pClean);
            $hasOtherKeywords = preg_match('/(plate|paper|roll|job|operator|planning|invoice)/i', $pClean);

            if (!$hasDispatchKeywords && $hasOtherKeywords) {
                return [
                    'tool_used' => 'Context Validator',
                    'type' => 'strict_mode_violation',
                    'title' => '❌ Invalid Query Context',
                    'total_count' => 0,
                    'total_meters' => 0,
                    'filtered_type' => '',
                    'is_company_list' => false,
                    'direct_answer' => "⚠️ You are in **Dispatch & Packing Mode**, but your question seems to be about something else.\n\n👉 **To ask about this, please use `/erp {$pCleanHtml}` instead.**",
                    'nav_url' => '',
                    'data' => []
                ];
            }
        } else if ($commandType === 'job') {
            $hasJobKeywords = preg_match('/(job|planning|floor|production|card|brc|jmb|pck|work|status)/i', $pClean);
            $hasOtherKeywords = preg_match('/(plate|paper|roll|dispatch|invoice|packing)/i', $pClean);

            if (!$hasJobKeywords && $hasOtherKeywords) {
                return [
                    'tool_used' => 'Context Validator',
                    'type' => 'strict_mode_violation',
                    'title' => '❌ Invalid Query Context',
                    'total_count' => 0,
                    'total_meters' => 0,
                    'filtered_type' => '',
                    'is_company_list' => false,
                    'direct_answer' => "⚠️ You are in **Job & Planning Mode**, but your question seems to be about something else.\n\n👉 **To ask about this, please use `/erp {$pCleanHtml}` instead.**",
                    'nav_url' => '',
                    'data' => []
                ];
            }
        }
    }    // ─── SESSION PRIORITY MODE: Search priority source FIRST ───
    $priorityMode = $_SESSION['ai_priority_mode'] ?? '';

    if (strpos($p, 'plate') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false || strpos($p, 'cylinder') !== false || strpos($p, 'সিলিন্ডার') !== false || strpos($p, 'die') !== false) {
        if (($_SESSION['ai_priority_mode'] ?? '') === 'paperstock') {
            unset($_SESSION['ai_priority_mode']);
            $priorityMode = '';
        }
    }
    // PRIORITY: Paper Stock — search first if priority mode active
    if ($priorityMode === 'paperstock') {
        $isOtherModuleQuery = (strpos($p, 'live') !== false || strpos($p, 'floor') !== false || strpos($p, 'plate') !== false || strpos($p, 'plate') !== false || strpos($p, 'die') !== false || strpos($p, 'anilox') !== false || strpos($p, 'job') !== false || strpos($p, 'planning') !== false || strpos($p, 'dispatch') !== false || strpos($p, 'slit') !== false || strpos($p, 'finished') !== false || strpos($p, 'packing') !== false || strpos($p, 'লাইভ') !== false || strpos($p, 'ফ্লোর') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'ডাই') !== false || strpos($p, 'জব') !== false);
        if ($isOtherModuleQuery) {
            unset($_SESSION['ai_priority_mode']);
            $priorityMode = '';
        }
        $isPaperQuery = !$isOtherModuleQuery && ($commandType === 'paperstock' || strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'slc/') !== false || strpos($p, 'chromo') !== false || strpos($p, 'thermal') !== false || strpos($p, 'stock') !== false || strpos($p, 'maplitho') !== false || strpos($p, 'pp') !== false || strpos($p, 'white') !== false || strpos($p, 'jumbo') !== false || strpos($p, 'avery') !== false || strpos($p, 'krishna') !== false || strpos($p, 'austin') !== false || strpos($p, 'navkar') !== false || strpos($p, 'nrgi') !== false || strpos($p, 'company') !== false || strpos($p, 'কোম্পানি') !== false || strpos($p, 'স্টক') !== false || strpos($p, 'রোল') !== false || strpos($p, 'কত') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'summary') !== false || strpos($p, 'breakdown') !== false || strpos($p, 'metro') !== false || strpos($p, 'sqm') !== false || strpos($p, 'running') !== false || strpos($p, 'status') !== false || strpos($p, 'lot') !== false || strpos($p, 'batch') !== false || strpos($p, 'লট') !== false || strpos($p, 'ব্যাচ') !== false || strpos($p, 'rate') !== false || strpos($p, 'price') !== false || strpos($p, 'দাম') !== false || strpos($p, 'দামি') !== false || strpos($p, 'costly') !== false || strpos($p, 'expensive') !== false || strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || preg_match('/\bprint\b/i', $p) || strpos($p, 'প্রিন্ট') !== false || strpos($p, 'এক্সপোর্ট') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'download') !== false || strpos($p, 'ডাউনলোড') !== false);
        if ($isPaperQuery) {
            $pToolName = 'Paper Stock Master Tool (Priority)';
            $pWhere = ["LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')"];
            $pParams = [];
            $pTypes = '';
            $pRollNo = null;
            $pCompany = null;
            $pType = null;
            $pWidth = null;
            $pWidthOp = '='; // '=', '<' (below), '>' (above)
            $pLotBatch = null;
            $pCompanyRollNo = null;
            if (preg_match('/(slc\/\d{4}\/\d+|\d{4})/i', $prompt, $m)) {
                $pRollNo = $m[1];
            }
            foreach (['nrgi', 'krishna', 'austin', 'navkar', 'abhinav', 'raj paper', 'mangalam', 'paper n more', 'narsing das', 'avery', 'nitin', 'flexo'] as $c) {
                if (strpos($p, $c) !== false) {
                    $pCompany = $c;
                    break;
                }
            }
            foreach (['pp white', 'pp-white', 'pp clear', 'pp-clear', 'chromo', 'thermal paper', 'thermal board', 'maplitho', 'metallic', 'plastic'] as $t) {
                if (strpos($p, $t) !== false) {
                    $pType = $t;
                    break;
                }
            }
            // 4. Width match — exact (500mm), below (< 500mm) or above (> 500mm).
            //    e.g. "below size of 500 mm width", "less than 500mm", "500 mm ar niche" → <
            $pWidth = null;
            $pWidth = null;
            if (preg_match('/(below|under|less\s*than|lesser|ar\s*niche|er\s*niche|niche|ar\s*kom|er\s*kom|ke\s*kom|এর\s*নিচে|নিচে|চেয়ে\s*কম|কম|chhoto|ছোট|<)\s*(?:size\s*(?:of)?\s*)?(\d{3,4})\s*(?:mm|width|w|চওড়া)?/i', $prompt, $m)) {
                $pWidth = (float) $m[2];
                $pWidthOp = '<';
            } elseif (preg_match('/(above|more\s*than|over|ar\s*upore|er\s*upore|upore|ar\s*beshi|er\s*beshi|beshi|এর\s*বেশি|বেশি|উপরে|চেয়ে\s*বেশি|boro|বড়|>)\s*(?:size\s*(?:of)?\s*)?(\d{3,4})\s*(?:mm|width|w|চওড়া)?/i', $prompt, $m)) {
                $pWidth = (float) $m[2];
                $pWidthOp = '>';
            } elseif (preg_match('/(\d{3,4})\s*(?:mm|width|w|চওড়া)\s*(below|under|less\s*than|lesser|ar\s*niche|er\s*niche|niche|ar\s*kom|er\s*kom|ke\s*kom|এর\s*নিচে|নিচে|চেয়ে\s*কম|কম|<|chhoto|ছোট)/i', $prompt, $m)) {
                $pWidth = (float) $m[1];
                $pWidthOp = '<';
            } elseif (preg_match('/(\d{3,4})\s*(?:mm|width|w|চওড়া)\s*(above|more\s*than|over|ar\s*upore|er\s*upore|upore|ar\s*beshi|er\s*beshi|beshi|এর\s*বেশি|বেশি|উপরে|>|boro|বড়)/i', $prompt, $m)) {
                $pWidth = (float) $m[1];
                $pWidthOp = '>';
            } elseif (preg_match('/(\d{3,4})\s*(?:mm|width|w|চওড়া)\b/i', $prompt, $m)) {
                $pWidth = (float) $m[1];
                $pWidthOp = '=';
            }
            $pGsm = null;
            if (preg_match('/(\d{2,3})\s*(?:gsm|জিএসএম)/i', $prompt, $m)) {
                $pGsm = (float) $m[1];
            }
            // 5. Lot/Batch No match (e.g. "LB-2025-001", "lot 123", "batch ABC")
            if (preg_match('/(?:lot\s*(?:batch)?\s*(?:no\.?)?\s*|batch\s*(?:no\.?)?\s*|লট\s*(?:ব্যাচ)?\s*(?:নম্বর|নং)?\s*)([A-Za-z0-9\-\/]+)/iu', $prompt, $m)) {
                $pLotBatch = trim($m[1]);
            } elseif (preg_match('/\b(LB-[A-Za-z0-9\-]+)\b/i', $prompt, $m)) {
                $pLotBatch = trim($m[1]);
            }
            // 6. Company Roll No match (e.g. "CR-12345", "company roll no CR-12345")
            if (preg_match('/(?:company\s*roll\s*(?:no\.?)?\s*|কোম্পানি\s*রোল\s*(?:নম্বর|নং)?\s*)([A-Za-z0-9\-\/]+)/iu', $prompt, $m)) {
                $pCompanyRollNo = trim($m[1]);
            } elseif (preg_match('/\b(CR-[A-Za-z0-9\-]+)\b/i', $prompt, $m)) {
                $pCompanyRollNo = trim($m[1]);
            }
            // 7. SQM Area Filter match (e.g. "sqr mtr 1000 ar niche", "1000 sqm ar upore")
            $pSqmFilter = null;
            $pSqmOp = null;
            if (
                preg_match('/(?:sqr?\s*mtr|sqm|square\s*meter)\s*(\d+(?:\.\d+)?)\s*(ar niche|er niche|niche|ar kom|er kom|kom|below|under|less|<|ar upore|er upore|upore|ar beshi|er beshi|beshi|above|more|>)/i', $prompt, $m) ||
                preg_match('/(\d+(?:\.\d+)?)\s*(?:sqr?\s*mtr|sqm|square\s*meter)\s*(ar niche|er niche|niche|ar kom|er kom|kom|below|under|less|<|ar upore|er upore|upore|ar beshi|er beshi|beshi|above|more|>)/i', $prompt, $m)
            ) {
                $val = (float) $m[1];
                $opStr = strtolower(trim($m[2]));
                $pSqmOp = (preg_match('/niche|kom|below|under|less|</i', $opStr)) ? '<=' : '>=';
                $pSqmFilter = $val;
            }
            if ($pRollNo) {
                $pWhere[] = "(roll_no LIKE ? OR id = ?)";
                $pParams[] = '%' . $pRollNo . '%';
                $pParams[] = $pRollNo;
                $pTypes .= 'ss';
            }
            if ($pCompany) {
                $pWhere[] = "company LIKE ?";
                $pParams[] = '%' . $pCompany . '%';
                $pTypes .= 's';
            }
            if ($pType) {
                if ($pType === 'pp white' || $pType === 'pp-white') {
                    $pWhere[] = "(paper_type LIKE '%pp-white%' OR paper_type LIKE '%pp white%' OR paper_type LIKE '%pp_white%')";
                } elseif ($pType === 'pp clear' || $pType === 'pp-clear') {
                    $pWhere[] = "(paper_type LIKE '%pp-clear%' OR paper_type LIKE '%pp clear%' OR paper_type LIKE '%pp_clear%')";
                } elseif ($pType === 'thermal paper') {
                    // "thermal paper" is used loosely for ALL thermal substrates
                    // (THERMAL PAPER + THERMAL BOARD + THERMAL LABEL + TOP COATED),
                    // consistent with the aggregate Analytics Dashboard count.
                    $pWhere[] = "paper_type LIKE '%thermal%'";
                } else {
                    $pWhere[] = "paper_type LIKE ?";
                    $pParams[] = '%' . $pType . '%';
                    $pTypes .= 's';
                }
            }
            if ($pWidth) {
                $pWhere[] = "width_mm {$pWidthOp} ?";
                $pParams[] = $pWidth;
                $pTypes .= 'd';
            }
            if ($pGsm !== null) {
                $pWhere[] = "gsm = ?";
                $pParams[] = $pGsm;
                $pTypes .= 'd';
            }
            if ($pLotBatch) {
                $pWhere[] = "lot_batch_no LIKE ?";
                $pParams[] = '%' . $pLotBatch . '%';
                $pTypes .= 's';
            }
            if ($pCompanyRollNo) {
                $pWhere[] = "company_roll_no LIKE ?";
                $pParams[] = '%' . $pCompanyRollNo . '%';
                $pTypes .= 's';
            }
            if ($pSqmFilter !== null) {
                $pWhere[] = "ROUND((width_mm/1000.0)*length_mtr, 2) {$pSqmOp} ?";
                $pParams[] = $pSqmFilter;
                $pTypes .= 'd';
            }
            $pWhereSql = implode(' AND ', $pWhere);

            // ─── Analysis Sub-Handler ───
            $isAnalysisQuery = (strpos($p, 'analysis') !== false || strpos($p, 'details') !== false || strpos($p, 'inventory') !== false || strpos($p, 'summary') !== false || strpos($p, 'report') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'stock') !== false || strpos($p, 'স্টক') !== false);
            if ($isAnalysisQuery) {
                $analyzerFilters = [
                    'company' => $pCompany,
                    'paper_type' => $pType,
                    'width' => $pWidth,
                    'gsm' => $pGsm
                ];
                $intent = (strpos($p, 'analysis') !== false || strpos($p, 'inventory') !== false || strpos($p, 'details') !== false || strpos($p, 'report') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'stock') !== false || strpos($p, 'স্টক') !== false) ? 'paper_stock_analysis' : 'paper_stock_summary';
                $ret = PaperStockAnalyzer::generateReport($db, $analyzerFilters, $intent, $userLang);
                if (!empty($ret['direct_answer'])) {
                    $ret['tool_used'] = 'Paper Stock Master Tool (Priority)';
                    return $ret;
                }
            }

            // ─── Export Sub-Handler ───
            $isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'download') !== false || strpos($p, 'ডাউনলোড') !== false || ((preg_match('/\bprint\b/i', $p) || strpos($p, 'প্রিন্ট') !== false) && !strpos($p, 'koto') && !strpos($p, 'কত') && !strpos($p, 'required') && !strpos($p, 'need') && !strpos($p, 'how many')));
            if ($isExportQuery) {
                $fTitle = 'Paper Stock Report';
                if ($pLotBatch)
                    $fTitle = 'Lot/Batch: ' . strtoupper($pLotBatch) . ' Report';
                elseif ($pCompanyRollNo)
                    $fTitle = 'Company Roll: ' . strtoupper($pCompanyRollNo) . ' Report';
                elseif ($pCompany && $pType)
                    $fTitle = strtoupper($pCompany . ' ' . $pType) . ' Report';
                elseif ($pCompany)
                    $fTitle = strtoupper($pCompany) . ' Paper Report';
                elseif ($pType)
                    $fTitle = strtoupper($pType) . ' Paper Report';

                $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
                $queryParams = [];
                if ($pCompany)
                    $queryParams[] = 'company=' . urlencode($pCompany);
                if ($pType) {
                    if ($pType === 'pp white' || $pType === 'pp-white')
                        $queryParams[] = 'type=PP-WHITE';
                    elseif ($pType === 'pp clear' || $pType === 'pp-clear')
                        $queryParams[] = 'type=PP-CLEAR';
                    else
                        $queryParams[] = 'type=' . urlencode($pType);
                }
                if ($pLotBatch)
                    $queryParams[] = 'lot_no=' . urlencode($pLotBatch);
                $queryStr = !empty($queryParams) ? '&' . implode('&', $queryParams) : '';

                $pdfUrl = "{$baseUrl}/modules/paper_stock/export.php?format=pdf{$queryStr}";
                $csvUrl = "{$baseUrl}/modules/paper_stock/export.php?format=csv{$queryStr}";

                $answer = "📊 **{$fTitle} — Export Ready:**\n\n";
                if ($userLang === 'Bengali') {
                    $answer .= "আপনার রিপোর্ট তৈরি হয়ে গেছে। নিচের রঙিন বাটনগুলোতে ক্লিক করে PDF বা Excel (CSV) ফাইল ডাউনলোড করুন:\n\n";
                } elseif ($userLang === 'Hindi') {
                    $answer .= "आपकी रिपोर्ट तैयार है। PDF या Excel (CSV) फाइल डाउनलोड करने के लिए नीचे दिए गए बटन पर क्लिक करें:\n\n";
                } else {
                    $answer .= "Your report is ready. Click the buttons below to download the PDF or Excel (CSV) file:\n\n";
                }

                $answer .= '<div style="display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap;">' . "\n";
                $answer .= '    <a href="' . $pdfUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.4); border: 1px solid #b91c1c; font-size: 14px; min-width: 200px;">' . "\n";
                $answer .= '        📄 Download PDF Report' . "\n";
                $answer .= '    </a>' . "\n";
                $answer .= '    <a href="' . $csvUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(34, 197, 94, 0.4); border: 1px solid #15803d; font-size: 14px; min-width: 200px;">' . "\n";
                $answer .= '        📊 Download Excel (CSV)' . "\n";
                $answer .= '    </a>' . "\n";
                $answer .= '</div>';

                return ['tool_used' => 'Paper Stock Export Tool (Priority)', 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            }

            // ─── Financial Sub-Handler: purchase rate / price / দাম queries ───
            $isFinancialQuery = (strpos($p, 'rate') !== false || strpos($p, 'price') !== false || strpos($p, 'দাম') !== false || strpos($p, 'দামি') !== false || strpos($p, 'costly') !== false || strpos($p, 'expensive') !== false || strpos($p, 'মূল্য') !== false || strpos($p, 'রেট') !== false || strpos($p, 'दाम') !== false || strpos($p, 'रेट') !== false || strpos($p, 'कीमत') !== false || strpos($p, 'avg') !== false || strpos($p, 'average') !== false || strpos($p, 'গড়') !== false);
            if ($isFinancialQuery) {
                // Determine if asking for most expensive
                $isMostExpensive = (strpos($p, 'সবচেয়ে দামি') !== false || strpos($p, 'সবচেয়ে বেশি') !== false || strpos($p, 'most expensive') !== false || strpos($p, 'highest rate') !== false || strpos($p, 'costly') !== false || strpos($p, 'max price') !== false || strpos($p, 'সর্বোচ্চ') !== false);
                $isAverage = (strpos($p, 'avg') !== false || strpos($p, 'average') !== false || strpos($p, 'গড়') !== false || strpos($p, 'औसत') !== false);

                // Build filter for financial query (reuse company/type filters)
                $fWhere = ["purchase_rate IS NOT NULL", "purchase_rate > 0"];
                $fParams = [];
                $fTypes = '';
                if ($pCompany) {
                    $fWhere[] = "company LIKE ?";
                    $fParams[] = '%' . $pCompany . '%';
                    $fTypes .= 's';
                }
                if ($pType) {
                    if ($pType === 'pp white' || $pType === 'pp-white') {
                        $fWhere[] = "(paper_type LIKE '%pp-white%' OR paper_type LIKE '%pp white%' OR paper_type LIKE '%pp_white%')";
                    } elseif ($pType === 'pp clear' || $pType === 'pp-clear') {
                        $fWhere[] = "(paper_type LIKE '%pp-clear%' OR paper_type LIKE '%pp clear%' OR paper_type LIKE '%pp_clear%')";
                    } else {
                        $fWhere[] = "paper_type LIKE ?";
                        $fParams[] = '%' . $pType . '%';
                        $fTypes .= 's';
                    }
                }
                $fWhereSql = implode(' AND ', $fWhere);

                // Get latest roll's purchase rate
                $latestSql = "SELECT id, roll_no, company, paper_type, width_mm, length_mtr, purchase_rate, date_received, status FROM paper_stock WHERE {$fWhereSql} ORDER BY id DESC LIMIT 1";
                $latestStmt = $db->prepare($latestSql);
                if (!empty($fParams))
                    $latestStmt->bind_param($fTypes, ...$fParams);
                $latestStmt->execute();
                $latestRoll = $latestStmt->get_result()->fetch_assoc();

                // Get aggregate stats
                $aggSql = "SELECT COUNT(*) as cnt, ROUND(AVG(purchase_rate),2) as avg_rate, ROUND(MIN(purchase_rate),2) as min_rate, ROUND(MAX(purchase_rate),2) as max_rate FROM paper_stock WHERE {$fWhereSql}";
                $aggStmt = $db->prepare($aggSql);
                if (!empty($fParams))
                    $aggStmt->bind_param($fTypes, ...$fParams);
                $aggStmt->execute();
                $aggData = $aggStmt->get_result()->fetch_assoc();

                // Get most expensive roll if asked
                $expensiveRoll = null;
                if ($isMostExpensive) {
                    $expSql = "SELECT id, roll_no, company, paper_type, width_mm, length_mtr, purchase_rate, date_received, status FROM paper_stock WHERE {$fWhereSql} ORDER BY purchase_rate DESC LIMIT 1";
                    $expStmt = $db->prepare($expSql);
                    if (!empty($fParams))
                        $expStmt->bind_param($fTypes, ...$fParams);
                    $expStmt->execute();
                    $expensiveRoll = $expStmt->get_result()->fetch_assoc();
                }

                // Get top 5 recent rolls with rates
                $recentSql = "SELECT id, roll_no, company, paper_type, width_mm, purchase_rate, date_received FROM paper_stock WHERE {$fWhereSql} ORDER BY id DESC LIMIT 5";
                $recentStmt = $db->prepare($recentSql);
                if (!empty($fParams))
                    $recentStmt->bind_param($fTypes, ...$fParams);
                $recentStmt->execute();
                $recentRolls = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

                if ($latestRoll || ($aggData && (int) $aggData['cnt'] > 0)) {
                    $fTitle = 'Paper Stock';
                    if ($pCompany && $pType)
                        $fTitle = strtoupper($pCompany . ' ' . $pType);
                    elseif ($pCompany)
                        $fTitle = strtoupper($pCompany);
                    elseif ($pType)
                        $fTitle = strtoupper($pType);

                    $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

                    if ($userLang === 'Bengali') {
                        $fAnswer = "💰 **{$fTitle} — Purchase Rate তথ্য:**\n\n";
                        if ($latestRoll) {
                            $fAnswer .= "📦 **সর্বশেষ রোলের Purchase Rate:**\n"
                                . "  - রোল নং: `{$latestRoll['roll_no']}`\n"
                                . "  - কোম্পানি: **" . ($latestRoll['company'] ?: 'N/A') . "**\n"
                                . "  - পেপার টাইপ: **" . ($latestRoll['paper_type'] ?: 'N/A') . "**\n"
                                . "  - প্রস্থ: **" . ($latestRoll['width_mm'] ?: '0') . "mm**\n"
                                . "  - 💵 **Purchase Rate: ₹" . number_format((float) $latestRoll['purchase_rate'], 2) . "**\n"
                                . "  - তারিখ: `" . ($latestRoll['date_received'] ?: 'N/A') . "`\n\n";
                        }
                        if ($aggData && (int) $aggData['cnt'] > 0) {
                            $fAnswer .= "📊 **Rate সামারি** (মোট " . number_format((int) $aggData['cnt']) . " রোল):\n"
                                . "  - গড় Rate: **₹" . number_format((float) $aggData['avg_rate'], 2) . "**\n"
                                . "  - সর্বনিম্ন Rate: **₹" . number_format((float) $aggData['min_rate'], 2) . "**\n"
                                . "  - সর্বোচ্চ Rate: **₹" . number_format((float) $aggData['max_rate'], 2) . "**\n\n";
                        }
                        if ($expensiveRoll) {
                            $fAnswer .= "🏆 **সবচেয়ে দামি রোল:**\n"
                                . "  - রোল নং: `{$expensiveRoll['roll_no']}` | 💵 **₹" . number_format((float) $expensiveRoll['purchase_rate'], 2) . "**\n"
                                . "  - কোম্পানি: **" . ($expensiveRoll['company'] ?: 'N/A') . "** | টাইপ: **" . ($expensiveRoll['paper_type'] ?: 'N/A') . "**\n\n";
                        }
                        if (!empty($recentRolls)) {
                            $fAnswer .= "📋 **সাম্প্রতিক রোলের Rate:**\n";
                            $fAnswer .= "| # | রোল নং | কোম্পানি | টাইপ | Rate |\n";
                            $fAnswer .= "|--:|--------|----------|------|------|\n";
                            foreach ($recentRolls as $ri => $rr) {
                                $fAnswer .= "| " . ($ri + 1) . " | `" . ($rr['roll_no'] ?: '-') . "` | " . ($rr['company'] ?: '-') . " | " . ($rr['paper_type'] ?: '-') . " | ₹" . number_format((float) $rr['purchase_rate'], 2) . " |\n";
                            }
                            $fAnswer .= "\n";
                        }
                        $fAnswer .= "👉 [পেপার স্টক পেজ খুলুন]({$baseUrl}/modules/paper_stock/index.php)";
                    } elseif ($userLang === 'Hindi') {
                        $fAnswer = "💰 **{$fTitle} — Purchase Rate जानकारी:**\n\n";
                        if ($latestRoll) {
                            $fAnswer .= "📦 **नवीनतम रोल का Purchase Rate:**\n"
                                . "  - रोल नं.: `{$latestRoll['roll_no']}`\n"
                                . "  - कंपनी: **" . ($latestRoll['company'] ?: 'N/A') . "**\n"
                                . "  - पेपर टाइप: **" . ($latestRoll['paper_type'] ?: 'N/A') . "**\n"
                                . "  - चौड़ाई: **" . ($latestRoll['width_mm'] ?: '0') . "mm**\n"
                                . "  - 💵 **Purchase Rate: ₹" . number_format((float) $latestRoll['purchase_rate'], 2) . "**\n"
                                . "  - तारीख: `" . ($latestRoll['date_received'] ?: 'N/A') . "`\n\n";
                        }
                        if ($aggData && (int) $aggData['cnt'] > 0) {
                            $fAnswer .= "📊 **Rate सारांश** (कुल " . number_format((int) $aggData['cnt']) . " रोल):\n"
                                . "  - औसत Rate: **₹" . number_format((float) $aggData['avg_rate'], 2) . "**\n"
                                . "  - न्यूनतम Rate: **₹" . number_format((float) $aggData['min_rate'], 2) . "**\n"
                                . "  - अधिकतम Rate: **₹" . number_format((float) $aggData['max_rate'], 2) . "**\n\n";
                        }
                        $fAnswer .= "👉 [पेपर स्टक पेज खोलें]({$baseUrl}/modules/paper_stock/index.php)";
                    } else {
                        $fAnswer = "💰 **{$fTitle} — Purchase Rate Information:**\n\n";
                        if ($latestRoll) {
                            $fAnswer .= "📦 **Latest Roll Purchase Rate:**\n"
                                . "  - Roll No: `{$latestRoll['roll_no']}`\n"
                                . "  - Company: **" . ($latestRoll['company'] ?: 'N/A') . "**\n"
                                . "  - Paper Type: **" . ($latestRoll['paper_type'] ?: 'N/A') . "**\n"
                                . "  - Width: **" . ($latestRoll['width_mm'] ?: '0') . "mm**\n"
                                . "  - 💵 **Purchase Rate: ₹" . number_format((float) $latestRoll['purchase_rate'], 2) . "**\n"
                                . "  - Date: `" . ($latestRoll['date_received'] ?: 'N/A') . "`\n\n";
                        }
                        if ($aggData && (int) $aggData['cnt'] > 0) {
                            $fAnswer .= "📊 **Rate Summary** (" . number_format((int) $aggData['cnt']) . " rolls with rate):\n"
                                . "  - Average Rate: **₹" . number_format((float) $aggData['avg_rate'], 2) . "**\n"
                                . "  - Minimum Rate: **₹" . number_format((float) $aggData['min_rate'], 2) . "**\n"
                                . "  - Maximum Rate: **₹" . number_format((float) $aggData['max_rate'], 2) . "**\n\n";
                        }
                        if ($expensiveRoll) {
                            $fAnswer .= "🏆 **Most Expensive Roll:**\n"
                                . "  - Roll No: `{$expensiveRoll['roll_no']}` | 💵 **₹" . number_format((float) $expensiveRoll['purchase_rate'], 2) . "**\n"
                                . "  - Company: **" . ($expensiveRoll['company'] ?: 'N/A') . "** | Type: **" . ($expensiveRoll['paper_type'] ?: 'N/A') . "**\n\n";
                        }
                        if (!empty($recentRolls)) {
                            $fAnswer .= "📋 **Recent Rolls with Rates:**\n";
                            $fAnswer .= "| # | Roll No | Company | Type | Rate |\n";
                            $fAnswer .= "|--:|---------|---------|------|------|\n";
                            foreach ($recentRolls as $ri => $rr) {
                                $fAnswer .= "| " . ($ri + 1) . " | `" . ($rr['roll_no'] ?: '-') . "` | " . ($rr['company'] ?: '-') . " | " . ($rr['paper_type'] ?: '-') . " | ₹" . number_format((float) $rr['purchase_rate'], 2) . " |\n";
                            }
                            $fAnswer .= "\n";
                        }
                        $fAnswer .= "👉 [Open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
                    }

                    return ['tool_used' => 'Paper Stock Financial Tool (Priority)', 'total_count' => (int) ($aggData['cnt'] ?? 0), 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $fAnswer, 'data' => []];
                }
            }

            $pSum = $db->prepare("SELECT COUNT(*) as rc, IFNULL(SUM(length_mtr),0) as tm, IFNULL(SUM((width_mm/1000.0)*length_mtr),0) as tsq FROM paper_stock WHERE {$pWhereSql}");
            if (!empty($pParams)) {
                $pSum->bind_param($pTypes, ...$pParams);
            }
            $pSum->execute();
            $pSummary = $pSum->get_result()->fetch_assoc();
            $pTotalCount = (int) ($pSummary['rc'] ?? 0);
            if ($pTotalCount > 0) {
                $toolName = $pToolName;
                $totalCount = $pTotalCount;
                $totalMeters = round((float) ($pSummary['tm'] ?? 0), 2);
                $totalSqm = round((float) ($pSummary['tsq'] ?? 0), 2);
                $pDetail = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, ROUND((width_mm/1000.0)*length_mtr, 2) as sqm, status, job_no, date_received FROM paper_stock WHERE {$pWhereSql} ORDER BY id DESC LIMIT 15");
                if (!empty($pParams)) {
                    $pDetail->bind_param($pTypes, ...$pParams);
                }
                $pDetail->execute();
                $data = $pDetail->get_result()->fetch_all(MYSQLI_ASSOC);

                // Jumbo vs slitting breakdown
                $pDeep = $db->prepare("SELECT SUM(CASE WHEN width_mm >= 1000 THEN 1 ELSE 0 END) as jumbo_rolls, SUM(CASE WHEN width_mm < 1000 THEN 1 ELSE 0 END) as slitted_rolls FROM paper_stock WHERE {$pWhereSql}");
                if (!empty($pParams)) {
                    $pDeep->bind_param($pTypes, ...$pParams);
                }
                $pDeep->execute();
                $pDeepData = $pDeep->get_result()->fetch_assoc();
                $jumboRolls = (int) ($pDeepData['jumbo_rolls'] ?? 0);
                $slittedRolls = (int) ($pDeepData['slitted_rolls'] ?? 0);

                $pTitle = 'Paper Stock';
                if ($pLotBatch) {
                    $pTitle = 'Lot/Batch: ' . strtoupper($pLotBatch);
                } elseif ($pCompanyRollNo) {
                    $pTitle = 'Company Roll: ' . strtoupper($pCompanyRollNo);
                } elseif ($pCompany && $pType) {
                    $pTitle = strtoupper($pCompany . ' ' . $pType);
                } elseif ($pCompany) {
                    $pTitle = strtoupper($pCompany) . ' Paper Stock';
                } elseif ($pType) {
                    $pTitle = strtoupper($pType) . ' Paper Stock';
                }
                $pDirectAnswer = "📜 **{$pTitle}** — Found **{$totalCount} rolls**:\n\n" . format_records_table($data, 'paper_stock', $userLang, $totalCount, $jumboRolls, $slittedRolls, $totalMeters, $totalSqm);
                return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => $totalMeters, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $pDirectAnswer, 'data' => []];
            } elseif ($pRollNo || $pCompany || $pType || $pWidth || $pLotBatch || $pCompanyRollNo || $pSqmFilter) {
                // No rolls matched the specific filters — return a clear "no match"
                // message instead of falling through to the aggregate Analytics
                // Dashboard (which ignores these filters and shows unrelated totals).
                $pTitle = 'Paper Stock';
                if ($pLotBatch)
                    $pTitle = 'Lot/Batch: ' . strtoupper($pLotBatch);
                elseif ($pCompanyRollNo)
                    $pTitle = 'Company Roll: ' . strtoupper($pCompanyRollNo);
                elseif ($pCompany && $pType)
                    $pTitle = strtoupper($pCompany . ' ' . $pType);
                elseif ($pCompany)
                    $pTitle = strtoupper($pCompany) . ' Paper Stock';
                elseif ($pType)
                    $pTitle = strtoupper($pType) . ' Paper Stock';
                $pBase = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
                $pDirectAnswer = "📜 **{$pTitle}** — No matching rolls found for the given filter.\n\n👉 [Open Paper Stock Page]({$pBase}/modules/paper_stock/index.php)";
                return ['tool_used' => $pToolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $pDirectAnswer, 'data' => []];
            }
        }
    }

    // PRIORITY: Printing Plates — search first if priority mode active
    if ($priorityMode === 'plate') {
        $isOtherModuleQueryPlate = (strpos($p, 'live') !== false || strpos($p, 'floor') !== false || strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'stock') !== false || strpos($p, 'anilox') !== false || strpos($p, 'dispatch') !== false || strpos($p, 'finished') !== false || strpos($p, 'packing') !== false || strpos($p, 'ライブ') !== false || strpos($p, '플로어') !== false || strpos($p, '페이퍼') !== false || strpos($p, '롤') !== false);
        if ($isOtherModuleQueryPlate) {
            unset($_SESSION['ai_priority_mode']);
            $priorityMode = '';
        }
        $isPlateQuery = !$isOtherModuleQueryPlate && ($commandType === 'plate' || strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false || strpos($p, 'die') !== false || strpos($p, 'cylinder') !== false || strpos($p, 'সিলিন্ডার') !== false || strpos($p, 'রিপিট') !== false || strpos($p, 'দাঁত') !== false || strpos($p, 'teeth') !== false || strpos($p, 'ups') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'কত') !== false || strpos($p, 'কতগুলো') !== false || strpos($p, 'kitne') !== false || ((strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false || strpos($p, 'কত') !== false || strpos($p, 'কয়টি') !== false || strpos($p, 'পাবো') !== false || strpos($p, 'কী') !== false || strpos($p, 'কি') !== false) && (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'মিটার') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || strpos($p, 'label') !== false || strpos($p, 'লেবেল') !== false || preg_match('/\b(run|paper)\b/', $p))));
        if ($isPlateQuery) {
            $prompt = html_entity_decode(stripslashes($prompt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            // Normalize Bengali numerals (০-৯ → 0-9) so plate numbers, sizes & math extract correctly
            $prompt = str_replace(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $prompt);
            $p = mb_strtolower($prompt, 'UTF-8'); // keep $p in sync with the normalized prompt
            $toolName = 'Printing Plates Master Tool';

            // Check if it's a count/dashboard request
            $isCountIntent = (strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'কতগুলো') !== false || strpos($p, 'kitne') !== false || strpos($p, 'summary') !== false || strpos($p, 'dashboard') !== false || strpos($p, 'কত') !== false || strpos($p, 'koto') !== false || strpos($p, 'সবগুলো') !== false || preg_match('/(^|\s)সব(\s|$)/u', $p) === 1 || strpos($p, 'সকল') !== false || strpos($p, 'মোট') !== false || strpos($p, 'all') !== false) && !preg_match('/\b(run|paper|meter|mtr|qty|quantity|pcs)\b/i', $p);

            // Extract Plate No FLEXIBLY & STRICTLY:
            // 1. P-101, PLATE-101, SL-101
            // 2. plate 925, plate no 925, plate number 925, sl 925, sl no 925
            // 3. 925 number plate, 925 no plate, 925 plate, 925 নম্বর প্লেট
            // 4. /plate 925
            $plateNoMatch = null;
            if (preg_match('/\b(P-\d+|PLATE-\d+|SL-\d+)\b/i', $prompt, $m)) {
                $plateNoMatch = trim($m[1]);
            } elseif (preg_match('/(?:plate|প্লেট|প্লট|sl|এসএল)\s*(?:no|number|numb|nber|num|নম্বর|নং|আইডি|id)?\s*#?\s*(\d+)/i', $prompt, $m)) {
                $plateNoMatch = trim($m[1]);
            } elseif (preg_match('/(\d+)\s*(?:no|number|numb|nber|num|নম্বর|নং|আইডি|id)?\s*#?\s*(?:plate|প্লেট|প্লট)\b/i', $prompt, $m)) {
                $plateNoMatch = trim($m[1]);
            } elseif (preg_match('/^\/plate\s+(\d+)\b/i', trim($prompt), $m)) {
                $plateNoMatch = trim($m[1]);
            }

            // Extract Cylinder — supports "104 T", "9 inch", "9 ইঞ্চি", "104 সিলিন্ডার"
            $cylinderMatch = null;
            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:inch|ইঞ্চি|in)?\s*(?:cylinder|সিলিন্ডার)\b/i', $prompt, $m)) {
                $cylinderMatch = trim($m[1]);
            } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:cylinder|সিলিন্ডার|teeth|teth|t)\b/i', $prompt, $m)) {
                $cylinderMatch = trim($m[1]);
            }

            // Extract Repeat
            $repeatMatch = null;
            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mm\s*)?(?:repeat|রিপিট)/i', $prompt, $m) || preg_match('/(?:repeat|রিপিট)\s*(\d+(?:\.\d+)?)/i', $prompt, $m)) {
                $repeatMatch = trim($m[1]);
            }

            // Extract Target Meters & Labels for Math
            $targetMeters = null;
            if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:running\s+)?(?:meter|meters|mtr|m(?!m)|\bমিটার\b|\bমিটারে\b)/i', $prompt, $m)) {
                $targetMeters = (float) str_replace(',', '', $m[1]);
            }

            $targetLabels = null;
            if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(lakh|লাখ|k|thousand|হাজার)?\s*(?:label|labels|pcs|piece|pieces|piss|pic|pics|পিস|লেবেল)/i', $prompt, $m) || preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:lakh|k|thousand)/i', $prompt, $m)) {
                $val = (float) str_replace(',', '', $m[1]);
                $mult = strtolower(trim($m[2] ?? ''));
                if ($mult === 'lakh' || $mult === 'লাখ')
                    $val *= 100000;
                elseif ($mult === 'k' || $mult === 'thousand' || $mult === 'হাজার')
                    $val *= 1000;
                $targetLabels = $val;
            }

            // Extract Paper Size (paper_size / size columns) — "পেপার সাইজ 200mm", "200mm পেপার", "paper size 200mm"
            $paperSizeMatch = null;
            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mm)?\s*(?:paper\s*size|পেপার\s*সাইজ|paper|পেপার)/i', $prompt, $m) || preg_match('/(?:paper\s*size|পেপার\s*সাইজ)\s*(\d+(?:\.\d+)?)\s*mm?/i', $prompt, $m)) {
                $paperSizeMatch = trim($m[1]);
            }

            // Extract Die type (Flat Bed / Rotary / Slitting)
            $dieMatch = null;
            if (preg_match('/\b(flat\s?bed|flatbed|fat\s?bed|rotary|sliting|slitting)\b/i', $prompt, $m)) {
                $dieMatch = strtolower(trim($m[1]));
                $dieMatch = preg_replace('/\s+/', ' ', $dieMatch);
                if ($dieMatch === 'flatbed' || $dieMatch === 'fatbed' || $dieMatch === 'fat bed')
                    $dieMatch = 'flat bed';
            }

            // Teeth / gearing intent
            $teethIntent = (strpos($p, 'দাঁত') !== false || strpos($p, 'teeth') !== false);

            // Color intents
            $moreThanFourColors = (preg_match('/more than 4|>4|4\+|4\s*টির বেশি|4\s*এর বেশি|৪\s*টির বেশি|৪\s*এর বেশি/i', $p) !== 0);

            // Latest / newest plate intent
            $latestPlateIntent = (
                strpos($p, 'latest') !== false ||
                strpos($p, 'newest') !== false ||
                strpos($p, 'last added') !== false ||
                strpos($p, 'latest added') !== false ||
                strpos($p, 'recently added') !== false ||
                strpos($p, 'recent plate') !== false ||
                strpos($p, 'last plate') !== false ||
                strpos($p, 'new plate') !== false ||
                strpos($p, 'নতুন প্লেট') !== false ||
                strpos($p, 'সবচেয়ে নতুন') !== false ||
                strpos($p, 'সর্বশেষ') !== false ||
                strpos($p, 'নতুন যোগ') !== false ||
                strpos($p, 'नई प्लेट') !== false ||
                strpos($p, 'नवीनतम') !== false
            );

            // Export query
            $isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || strpos($p, 'এক্সপোর্ট') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'download') !== false || strpos($p, 'ডাউনলোড') !== false || ((strpos($p, 'print') !== false || strpos($p, 'প্রিন্ট') !== false) && !strpos($p, 'koto') && !strpos($p, 'কত') && !strpos($p, 'required') && !strpos($p, 'need') && !strpos($p, 'how many')));

            // Find search terms loosely
            $pStopwords = [
                'can',
                'you',
                'tell',
                'me',
                'which',
                'is',
                'in',
                'my',
                'plate',
                'want',
                'to',
                'printing',
                'pitnting',
                'print',
                'plates',
                'list',
                'show',
                'details',
                'detail',
                'this',
                'the',
                'a',
                'an',
                'job',
                'jobs',
                'for',
                'about',
                'get',
                'search',
                'find',
                'there',
                'any',
                'named',
                'by',
                'name',
                'of',
                'with',
                'are',
                'do',
                'have',
                'exist',
                'does',
                'repeat',
                'gap',
                'gaph',
                'gapv',
                'size',
                'ups',
                'cylinder',
                'paper',
                'die',
                'core',
                'rewinding',
                'value',
                'color',
                'colors',
                'spec',
                'special',
                'what',
                'how',
                'give',
                'if',
                'run',
                'running',
                'much',
                'many',
                'quantity',
                'qty',
                'meter',
                'meters',
                'mtr',
                'will',
                'be',
                'produced',
                'print',
                'printing',
                'require',
                'required',
                'need',
                'needed',
                'or',
                'and',
                'calculate',
                'calculating',
                'calc',
                'length',
                'roll',
                'pcs',
                'pieces',
                'labels',
                'koto',
                'kotogulo',
                'hobe',
                'lagbe',
                'korle',
                'korte',
                'korbo',
                'korbe',
                'kori',
                'asob',
                'ar',
                'er',
                'diye',
                'giye',
                'ache',
                'hobe',
                'হবে',
                'আছে',
                'কত',
                'কতগুলো',
                'কি',
                'কী',
                'কোন',
                'pdf',
                'excel',
                'export',
                'download',
                'sir',
                'amra',
                'ai',
                'ta',
                'korechi',
                'kore',
                'chi',
                'hoyechhe',
                'kobe',
                'last',
                'naki',
                'ache',
                'এর',
                'ডিটেলস',
                'দেখাও',
                'dikhao',
                'dekhaw',
                'konta',
                'কোনটা',
                'dekhaben',
                'janaben',
                'jante',
                'chai',
                'লেবেলের',
                'লেবেল',
                'label',
                'labels',
                'প্লেট',
                'প্লেটের',
                'প্লট',
                'প্লটের',
                'qnty',
                'qnt',
                'quantity',
                'quantities',
                'piss',
                'piece',
                'pieces',
                'pic',
                'pics',
                'running',
                'run',
                'পেপারে',
                'পেপার',
                'মিটার',
                'মিটারে',
                'প্রিন্টিং',
                'প্রিন্ট',
                'total',
                'count',
                'summary',
                'dashboard',
                'all',
                'সব',
                'সকল',
                'din',
                'দিন',
                'দাও',
                'dao',
                'do',
                'deu',
                'dene',
                'dena',
                'dikhabe',
                'send',
                'পাঠাও',
                'pathao',
                'pathaben',
                'no',
                'number',
                'num',
                'nber',
                'numb',
                'id',
                'নম্বর',
                'নং',
                'আইডি',
                // Added for plate-specific query handling
                'মোট',
                'সর্বমোট',
                'সিলিন্ডার',
                'সিলিন্ডারের',
                'সিলিন্ডারটা',
                'রিপিট',
                'রিপিটের',
                'কালার',
                'কালারের',
                'কয়টি',
                'কয়',
                'দাঁত',
                'দাঁতের',
                'জবে',
                'জবের',
                'নতুন',
                'সবচেয়ে',
                'সর্বশেষ',
                'লেটেস্ট',
                'তৈরি',
                'নামে',
                'জন্য',
                'সাইজ',
                'পেপারসাইজ',
                'পেপারের',
                'কোনো',
                'inch',
                'ইঞ্চি',
                'ব্যবহার',
                'হয়',
                'লাগে',
                'প্লেটগুলো',
                'প্লেটগুলোতে',
                'প্লেটটি',
                'বেশি',
                'লাখ',
                'পিস',
                'হাজার',
                'হাজারে',
                'করে',
                'করা',
                'করার',
                'করতে',
                'থেকে',
                'এটা',
                'এটি',
                'ডাই',
                'টাইপ',
                'type',
                'যে',
                'যেগুলো',
                'যেগুলোতে',
                'খুঁজে',
                'দিয়েছি',
                'দিয়ে',
                'এরকম',
                'তোমার',
                'আমার',
                'konsa',
                'konsi',
                'kaun',
                'jis',
                'jinke',
                'লাগবে',
                'প্রয়োজন',
                'প্রয়োজনীয়',
                'চাই',
                'চাও',
                'চান',
                'we',
                'our',
                'us',
                'are',
                'were',
                'was',
                'has',
                'had',
                'have',
                'do',
                'does',
                'did',
                'will',
                'would',
                'can',
                'could',
                'should',
                'shall',
                'may',
                'might',
                'must',
                'shall',
                'আমি',
                'তুমি',
                'আপনি',
                'সে',
                'আমরা',
                'আপনরা',
                'তোমরা',
                'সবাই'
            ];

            $jobSearchExplicit = null;
            if (preg_match('/["“”]([^"“”]+)["“”]|\'([^\']+)\'/u', $prompt, $m)) {
                $jobSearchExplicit = trim($m[1] ?: $m[2]);
                if (preg_match('/[a-zA-Z]\d|\d[a-zA-Z]/', $jobSearchExplicit)) {
                    $jobSearchExplicit = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $jobSearchExplicit);
                    $jobSearchExplicit = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $jobSearchExplicit);
                }
            }

            $pWords = preg_split('/\s+/', $p);
            $pTerms = [];
            foreach ($pWords as $w) {
                $wC = trim(preg_replace('/[^\p{L}\p{N}\p{M}]/u', '', $w));
                if ($wC !== '' && !in_array($wC, $pStopwords, true) && mb_strlen($wC, 'UTF-8') >= 2) {
                    if (is_numeric($wC)) {
                        if ((string) $wC === (string) $repeatMatch || (string) $wC === (string) $cylinderMatch || (string) $wC === (string) $targetMeters || (string) $wC === (string) $targetLabels || (string) $wC === (string) $plateNoMatch || (string) $wC === (string) $paperSizeMatch) {
                            continue;
                        }
                    }
                    // Also skip words that contain a matched numeric value (e.g. "1524mm" from "152.4mm", or "1524" from "152.4")
                    $skip = false;
                    foreach ([$repeatMatch, $cylinderMatch, $targetMeters, $targetLabels, $plateNoMatch, $paperSizeMatch] as $mv) {
                        if ($mv !== null && $mv !== '') {
                            if (mb_strpos($wC, (string) $mv) !== false) {
                                $skip = true;
                                break;
                            }
                            // Handle dot-stripped numbers: "1524" should match repeatMatch "152.4"
                            if (is_numeric($wC) && is_numeric($mv) && (float) $wC === (float) $mv) {
                                $skip = true;
                                break;
                            }
                            // Handle dot-stripped: "1524" should match "152.4" (dot removed by preg_replace)
                            $mvNoDot = str_replace('.', '', (string) $mv);
                            if (is_numeric($wC) && is_numeric($mvNoDot) && (float) $wC === (float) $mvNoDot) {
                                $skip = true;
                                break;
                            }
                            // Handle unit-suffixed numbers: "1524mm" should match repeatMatch "152.4"
                            $wNum = preg_replace('/[^\d.]/', '', $wC);
                            if ($wNum !== '' && is_numeric($wNum) && is_numeric($mv) && (float) $wNum === (float) $mv) {
                                $skip = true;
                                break;
                            }
                            if ($wNum !== '' && is_numeric($wNum) && is_numeric($mvNoDot) && (float) $wNum === (float) $mvNoDot) {
                                $skip = true;
                                break;
                            }
                        }
                    }
                    if ($skip)
                        continue;
                    $pTerms[] = $wC;
                }
            }

            if (!$jobSearchExplicit) {
                $newPTerms = [];
                foreach ($pTerms as $term) {
                    if (preg_match('/[a-zA-Z]\d|\d[a-zA-Z]/', $term)) {
                        $splitTerm = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $term);
                        $splitTerm = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $splitTerm);
                        $newPTerms = array_merge($newPTerms, explode(' ', $splitTerm));
                    } else {
                        $newPTerms[] = $term;
                    }
                }
                $pTerms = $newPTerms;
            }
            $jobSearchRaw = $jobSearchExplicit ?: implode(' ', $pTerms);
            $jobSearchTerm = trim($jobSearchRaw);

            // Shared plate renderer (details + math)
            $renderPlate = function ($row, $idx) use ($userLang, $targetMeters, $targetLabels) {
                $rep = (float) ($row['repeat_value'] ?? 0);
                $ups = (float) ($row['ups'] ?? 1);
                if ($ups <= 0)
                    $ups = 1;
                $repFmt = $rep > 0 ? rtrim(rtrim(number_format($rep, 3, '.', ''), '0'), '.') : '—';

                $colors = [];
                foreach (['c', 'm', 'y', 'k', 'special_1', 'special_2', 'special_3', 'special_4', 'special_5'] as $c) {
                    $cv = trim((string) ($row[$c] ?? ''));
                    if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-')
                        $colors[] = $cv;
                }
                $colorCount = count($colors);
                $colorDisp = $colorCount > 0 ? implode(', ', $colors) : '—';

                $clean = function ($v) {
                    $v = trim((string) $v);
                    return ($v === '' || mb_strtolower($v, 'UTF-8') === 'na' || mb_strtolower($v, 'UTF-8') === 'n/a' || $v === '-') ? '—' : $v;
                };

                $gapH = trim((string) ($row['gap_h'] ?? ''));
                $gapV = trim((string) ($row['gap_v'] ?? ''));
                if ($gapH === '' || mb_strtolower($gapH, 'UTF-8') === 'na')
                    $gapH = '—';
                elseif (!preg_match('/mm$/i', $gapH))
                    $gapH .= 'mm';
                if ($gapV === '' || mb_strtolower($gapV, 'UTF-8') === 'na')
                    $gapV = '—';
                elseif (!preg_match('/mm$/i', $gapV))
                    $gapV .= 'mm';

                $out = "• **Plate " . $idx . ": `" . ($row['name'] ?: 'Job Name') . "`** (Plate: **" . $clean($row['plate']) . "** | SL No: **" . $clean($row['sl_no']) . "** | ID: **{$row['id']}**)\n"
                    . "  - 📏 **Repeat:** **{$repFmt}mm** | **Ups:** **{$ups}** | ⚙️ **Cylinder:** **" . $clean($row['cylinder']) . "**\n"
                    . "  - 📐 **Gaps:** **Gap H:** **{$gapH}** | **Gap V:** **{$gapV}**\n"
                    . "  - 📄 **Paper Type:** **" . $clean($row['paper_type']) . "** | 🏭 **Make By:** **" . $clean($row['make_by']) . "**\n"
                    . "  - 📏 **Size:** **" . $clean($row['size']) . "** | 📄 **Paper Size:** **" . $clean($row['paper_size']) . "** | ✂️ **Die:** **" . $clean($row['die']) . "**\n"
                    . "  - 🎨 **Colors ({$colorCount}):** {$colorDisp}\n"
                    . "  - 📅 **Date Received / Last Job:** **" . $clean($row['date_received']) . "**\n";

                if ($targetMeters > 0 && $rep > 0) {
                    $calcLabels = floor(($targetMeters * 1000) / $rep * $ups);
                    if ($userLang === 'Bengali')
                        $out .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " মিটার** পেপারে **" . number_format($calcLabels) . " টি লেবেল** প্রিন্ট হবে।\n";
                    elseif ($userLang === 'Hindi')
                        $out .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " मीटर** पेपर में **" . number_format($calcLabels) . " लेबेल** प्रिंट होंगे।\n";
                    else
                        $out .= "  - 🧮 **Calculation:** A **" . number_format($targetMeters) . "m** roll will yield **" . number_format($calcLabels) . " labels**.\n";
                }
                if ($targetLabels > 0 && $rep > 0) {
                    $reqMeters = ceil(($targetLabels / $ups) * $rep / 1000);
                    if ($userLang === 'Bengali')
                        $out .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " টি লেবেল** প্রিন্ট করতে **" . number_format($reqMeters) . " মিটার** পেপার লাগবে।\n";
                    elseif ($userLang === 'Hindi')
                        $out .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " लेबेल** प्रिंट करने के लिए **" . number_format($reqMeters) . " मीटर** पेपर लगेगा।\n";
                    else
                        $out .= "  - 🧮 **Calculation:** To print **" . number_format($targetLabels) . " labels**, you need **" . number_format($reqMeters) . " meters** of paper.\n";
                }
                if ($targetMeters == null && $targetLabels == null && $rep > 0) {
                    $teeth = round($rep / 3.175, 2);
                    $out .= "  - ⚙️ **Gearing (1/8 CP):** ~**{$teeth} Teeth** Cylinder\n";
                }
                $out .= "\n";
                return $out;
            };

            // CHECK MEMORY FOR MATH INTENT
            $isMathIntentOnly = ($targetMeters > 0 || $targetLabels > 0) && !$plateNoMatch && !$jobSearchTerm && !$cylinderMatch && !$repeatMatch && !$paperSizeMatch && !$dieMatch;

            if ($isMathIntentOnly && isset($_SESSION['ai_last_plate_id'])) {
                $plateNoMatch = (string) $_SESSION['ai_last_plate_id'];
            }

            $where = ["1=1"];
            $params = [];
            $typesStr = '';

            if ($plateNoMatch) {
                $where[] = "(plate = ? OR sl_no = ? OR id = ?)";
                $params[] = $plateNoMatch;
                $params[] = $plateNoMatch;
                $params[] = $plateNoMatch;
                $typesStr .= 'sss';
            }

            if ($cylinderMatch) {
                $where[] = "cylinder LIKE ?";
                $params[] = '%' . $cylinderMatch . '%';
                $typesStr .= 's';
            }

            if ($repeatMatch) {
                $where[] = "repeat_value LIKE ?";
                $params[] = '%' . $repeatMatch . '%';
                $typesStr .= 's';
            }

            if ($paperSizeMatch) {
                $where[] = "(paper_size LIKE ? OR size LIKE ?)";
                $params[] = '%' . $paperSizeMatch . '%';
                $params[] = '%' . $paperSizeMatch . '%';
                $typesStr .= 'ss';
            }

            if ($dieMatch) {
                $where[] = "die LIKE ?";
                $params[] = '%' . $dieMatch . '%';
                $typesStr .= 's';
            }

            // Add job search terms (LOOSE AND MATCHING across words if multiple words)
            $searchCols = "name LIKE ? OR plate LIKE ? OR sl_no LIKE ? OR size LIKE ? OR paper_size LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? OR cylinder LIKE ? OR core LIKE ? OR qty_roll LIKE ? OR rewinding LIKE ?";
            if ($jobSearchTerm && !$plateNoMatch && !$moreThanFourColors) {
                if ($jobSearchExplicit) {
                    $expWords = array_filter(preg_split('/\s+|-/', $jobSearchExplicit));
                    if (count($expWords) > 1) {
                        foreach ($expWords as $ew) {
                            $where[] = "(" . $searchCols . ")";
                            $l = '%' . $ew . '%';
                            for ($i = 0; $i < 12; $i++)
                                $params[] = $l;
                            $typesStr .= 'ssssssssssss';
                        }
                    } else {
                        $where[] = "(" . $searchCols . ")";
                        $l = '%' . $jobSearchExplicit . '%';
                        for ($i = 0; $i < 12; $i++)
                            $params[] = $l;
                        $typesStr .= 'ssssssssssss';
                    }
                } else {
                    foreach ($pTerms as $term) {
                        $where[] = "(" . $searchCols . ")";
                        $l = '%' . $term . '%';
                        for ($i = 0; $i < 12; $i++)
                            $params[] = $l;
                        $typesStr .= 'ssssssssssss';
                    }
                }
            }

            $whereSql = implode(' AND ', $where);
            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            if ($isCountIntent && !$isExportQuery && !$plateNoMatch && !$jobSearchTerm && !$cylinderMatch && !$repeatMatch && !$paperSizeMatch && !$dieMatch) {
                // Dashboard mode
                $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
                $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
                $dieBreak = $db->query("SELECT die, COUNT(*) as cnt FROM master_plate_data WHERE die IS NOT NULL AND die != '' AND LOWER(TRIM(die)) != 'na' GROUP BY die ORDER BY cnt DESC LIMIT 5");
                $dieStats = $dieBreak ? $dieBreak->fetch_all(MYSQLI_ASSOC) : [];
                $makerBreak = $db->query("SELECT make_by, COUNT(*) as cnt FROM master_plate_data WHERE make_by IS NOT NULL AND make_by != '' AND LOWER(TRIM(make_by)) != 'na' GROUP BY make_by ORDER BY cnt DESC LIMIT 5");
                $makerStats = $makerBreak ? $makerBreak->fetch_all(MYSQLI_ASSOC) : [];
                $paperBreak = $db->query("SELECT paper_type, COUNT(*) as cnt FROM master_plate_data WHERE paper_type IS NOT NULL AND paper_type != '' AND LOWER(TRIM(paper_type)) != 'na' GROUP BY paper_type ORDER BY cnt DESC LIMIT 5");
                $paperStats = $paperBreak ? $paperBreak->fetch_all(MYSQLI_ASSOC) : [];

                if ($userLang === 'Bengali') {
                    $answer = "📊 **প্রিন্টিং প্লেট ড্যাশবোর্ড**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **মোট প্লেট:** **{$totalCount}টি**\n\n";
                    if (!empty($dieStats)) {
                        $answer .= "📐 **ধরণ (Die Type):**\n";
                        foreach ($dieStats as $ds)
                            $answer .= " ▸ **" . ($ds['die'] ?: 'অন্যান্য') . "** — {$ds['cnt']}টি\n";
                    }
                    if (!empty($paperStats)) {
                        $answer .= "\n📄 **পেপার টাইপ:**\n";
                        foreach ($paperStats as $ps)
                            $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']}টি\n";
                    }
                    if (!empty($makerStats)) {
                        $answer .= "\n🏭 **নির্মাতা (Maker):**\n";
                        foreach ($makerStats as $ms)
                            $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']}টি\n";
                    }
                } elseif ($userLang === 'Hindi') {
                    $answer = "📊 **प्रिंटिंग प्लेट डैशबोर्ड**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **कुल प्लेट:** **{$totalCount}**\n\n";
                    if (!empty($dieStats)) {
                        $answer .= "📐 **प्रकार (Die Type):**\n";
                        foreach ($dieStats as $ds)
                            $answer .= " ▸ **" . ($ds['die'] ?: 'अन्य') . "** — {$ds['cnt']}\n";
                    }
                    if (!empty($paperStats)) {
                        $answer .= "\n📄 **पेपर प्रकार:**\n";
                        foreach ($paperStats as $ps)
                            $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']}\n";
                    }
                    if (!empty($makerStats)) {
                        $answer .= "\n🏭 **निर्माता (Maker):**\n";
                        foreach ($makerStats as $ms)
                            $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']}\n";
                    }
                } else {
                    $answer = "📊 **Printing Plates Dashboard**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **Total Plates:** **{$totalCount}**\n\n";
                    if (!empty($dieStats)) {
                        $answer .= "📐 **By Die Type:**\n";
                        foreach ($dieStats as $ds)
                            $answer .= " ▸ **" . ($ds['die'] ?: 'Other') . "** — {$ds['cnt']} plates\n";
                    }
                    if (!empty($paperStats)) {
                        $answer .= "\n📄 **By Paper Type:**\n";
                        foreach ($paperStats as $ps)
                            $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']} plates\n";
                    }
                    if (!empty($makerStats)) {
                        $answer .= "\n🏭 **By Maker:**\n";
                        foreach ($makerStats as $ms)
                            $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']} plates\n";
                    }
                }
                $answer .= "\n👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
                return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            }

            // Latest / newest plate
            if ($latestPlateIntent && !$plateNoMatch && !$jobSearchExplicit && !$cylinderMatch && !$repeatMatch && !$paperSizeMatch && !$dieMatch) {
                $stmt = $db->prepare("SELECT * FROM master_plate_data ORDER BY id DESC LIMIT 1");
                $stmt->execute();
                $latestRow = $stmt->get_result()->fetch_assoc();
                if ($latestRow) {
                    if ($userLang === 'Bengali') {
                        $answer = "🆕 **সর্বশেষ যোগ করা প্লেট:**\n\n";
                    } elseif ($userLang === 'Hindi') {
                        $answer = "🆕 **सबसे नई प्लेट:**\n\n";
                    } else {
                        $answer = "🆕 **Latest Added Plate:**\n\n";
                    }
                    $answer .= $renderPlate($latestRow, 1);
                    $answer .= "👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
                    return ['tool_used' => $toolName, 'total_count' => 1, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
                }
            }

            // Teeth / gear calculation from a repeat value directly (no DB match needed)
            if ($teethIntent && $repeatMatch && (float) $repeatMatch > 0 && !$plateNoMatch && !$cylinderMatch && !$paperSizeMatch && !$dieMatch && !$jobSearchTerm) {
                $repVal = (float) $repeatMatch;
                $teeth = round($repVal / 3.175, 2);
                if ($userLang === 'Bengali') {
                    $answer = "⚙️ **গিয়ার / দাঁত ক্যালকুলেশন:**\n\n**{$repVal}mm** রিপিটের জন্য প্রায় **{$teeth} দাঁতের (Teeth)** সিলিন্ডার লাগবে (1/8 CP)।\n\n📐 সূত্র: রিপিট ÷ 3.175 = {$repVal} ÷ 3.175 = **{$teeth} Teeth**";
                } elseif ($userLang === 'Hindi') {
                    $answer = "⚙️ **गियर / दांत गणना:**\n\n**{$repVal}mm** रिपीट के लिए लगभग **{$teeth} दांत (Teeth)** सिलेंडर चाहिए (1/8 CP)।\n\n📐 सूत्र: रिपीट ÷ 3.175 = {$repVal} ÷ 3.175 = **{$teeth} Teeth**";
                } else {
                    $answer = "⚙️ **Gear / Teeth Calculation:**\n\nFor a **{$repVal}mm** repeat, you need approximately a **{$teeth}-teeth** cylinder (1/8 CP).\n\n📐 Formula: Repeat ÷ 3.175 = {$repVal} ÷ 3.175 = **{$teeth} Teeth**";
                }
                return ['tool_used' => 'Plate Gearing Calculator', 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            }

            if ($isExportQuery) {
                $fTitle = 'Plate Management Report';
                $exportQueryStr = 'mode=design';
                if ($plateNoMatch) {
                    $fTitle = 'Plate: ' . strtoupper($plateNoMatch) . ' Report';
                    $exportQueryStr .= '&search=' . urlencode($plateNoMatch);
                } elseif ($jobSearchTerm) {
                    $fTitle = 'Job: ' . strtoupper(implode(' ', $pTerms)) . ' Report';
                    $exportQueryStr .= '&search=' . urlencode($jobSearchTerm);
                } elseif ($cylinderMatch) {
                    $fTitle = 'Cylinder: ' . $cylinderMatch . ' Report';
                    $exportQueryStr .= '&search=' . urlencode($cylinderMatch);
                }

                $pdfUrl = "{$baseUrl}/modules/plate-data/export.php?{$exportQueryStr}&format=pdf";
                $csvUrl = "{$baseUrl}/modules/plate-data/export.php?{$exportQueryStr}&format=excel";

                $answer = "📊 **{$fTitle} — Export Ready:**\n\n";
                if ($userLang === 'Bengali') {
                    $answer .= "আপনার প্লেট রিপোর্ট তৈরি হয়ে গেছে। নিচের রঙিন বাটনগুলোতে ক্লিক করে PDF বা Excel ফাইল ডাউনলোড করুন:\n\n";
                } elseif ($userLang === 'Hindi') {
                    $answer .= "आपकी प्लेट रिपोर्ट तैयार है। PDF या Excel फाइल डाउनलोड करने के लिए नीचे दिए गए बटन पर क्लिक करें:\n\n";
                } else {
                    $answer .= "Your plate report is ready. Click the buttons below to download the PDF or Excel file:\n\n";
                }

                $answer .= '<div style="display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap;">' . "\n";
                $answer .= '    <a href="' . $pdfUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.4); border: 1px solid #b91c1c; font-size: 14px; min-width: 200px;">' . "\n";
                $answer .= '        📄 Download PDF Report' . "\n";
                $answer .= '    </a>' . "\n";
                $answer .= '    <a href="' . $csvUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(34, 197, 94, 0.4); border: 1px solid #15803d; font-size: 14px; min-width: 200px;">' . "\n";
                $answer .= '        📊 Download Excel' . "\n";
                $answer .= '    </a>' . "\n";
                $answer .= '</div>';

                return ['tool_used' => 'Printing Plates Export Tool', 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            }

            $limitRows = $moreThanFourColors ? 60 : 5;
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE {$whereSql} ORDER BY id DESC LIMIT {$limitRows}");
            if (!empty($params))
                $stmt->bind_param($typesStr, ...$params);
            $stmt->execute();
            $plateData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Filter: plates with more than 4 colors (exclude empty / NA)
            if ($moreThanFourColors && !empty($plateData)) {
                $filtered = [];
                foreach ($plateData as $row) {
                    $cnt = 0;
                    foreach (['c', 'm', 'y', 'k', 'special_1', 'special_2', 'special_3', 'special_4', 'special_5'] as $c) {
                        $cv = trim((string) ($row[$c] ?? ''));
                        if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-')
                            $cnt++;
                    }
                    if ($cnt > 4)
                        $filtered[] = $row;
                }
                $plateData = $filtered;
            }

            if (empty($plateData) && ($plateNoMatch || $cylinderMatch || $repeatMatch || $paperSizeMatch || $dieMatch)) {
                // Tailored "not found" based on what was actually searched
                $targetDesc = '';
                if ($plateNoMatch) {
                    $targetDesc = $userLang === 'Bengali' ? "প্লেট নম্বর {$plateNoMatch}" : ($userLang === 'Hindi' ? "प्लेट नंबर {$plateNoMatch}" : "plate number {$plateNoMatch}");
                } elseif ($dieMatch) {
                    $targetDesc = $userLang === 'Bengali' ? ucfirst($dieMatch) . " ধরনের ডাই" : ($userLang === 'Hindi' ? ucfirst($dieMatch) . " प्रकार की डाई" : ucfirst($dieMatch) . " die type");
                } elseif ($cylinderMatch) {
                    $targetDesc = $userLang === 'Bengali' ? "{$cylinderMatch} সিলিন্ডার" : ($userLang === 'Hindi' ? "{$cylinderMatch} सिलेंडर" : "{$cylinderMatch} cylinder");
                } elseif ($paperSizeMatch) {
                    $targetDesc = $userLang === 'Bengali' ? "{$paperSizeMatch}mm পেপার সাইজ" : ($userLang === 'Hindi' ? "{$paperSizeMatch}mm पेपर साइज़" : "{$paperSizeMatch}mm paper size");
                } elseif ($repeatMatch) {
                    $targetDesc = $userLang === 'Bengali' ? "{$repeatMatch}mm রিপিট" : ($userLang === 'Hindi' ? "{$repeatMatch}mm रिपीट" : "{$repeatMatch}mm repeat");
                }
                if ($userLang === 'Bengali') {
                    $answer = "❌ **{$targetDesc} এর জন্য কোনো প্লেট পাওয়া যায়নি।**\n\nঅন্য প্লেট নম্বর, সাইজ, সিলিন্ডার বা জবের নাম দিয়ে আবার চেষ্টা করুন।";
                } elseif ($userLang === 'Hindi') {
                    $answer = "❌ **{$targetDesc} के लिए कोई प्लेट नहीं मिली।**\n\nकृपया दूसरा प्लेट नंबर, साइज़, सिलेंडर या जॉब का नाम आज़माएँ।";
                } else {
                    $answer = "❌ **No plates found for {$targetDesc}.**\n\nTry a different plate number, size, cylinder, or job name.";
                }
                return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            } elseif (empty($plateData) && $moreThanFourColors) {
                if ($userLang === 'Bengali') {
                    $answer = "❌ **৪টির বেশি কালারের কোনো প্লেট পাওয়া যায়নি।**";
                } elseif ($userLang === 'Hindi') {
                    $answer = "❌ **4 से अधिक रंगों वाली कोई प्लेट नहीं मिली।**";
                } else {
                    $answer = "❌ **No plates with more than 4 colors found.**";
                }
                return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            } elseif (empty($plateData) && $jobSearchTerm) {
                $displayJob = $jobSearchTerm;
                if ($userLang === 'Bengali') {
                    $answer = "❌ **না স্যার, আমরা \"{$displayJob}\" নামে কোনো প্লেট বা জব রেকর্ড পাইনি।**\n\nসম্ভবত এই জবটি আগে কখনো প্রিন্ট হয়নি, বা এটি অন্য কোনো নামে সেভ করা আছে।";
                } elseif ($userLang === 'Hindi') {
                    $answer = "❌ **नहीं सर, हमें \"{$displayJob}\" नाम की कोई प्लेट या जॉब नहीं मिली।**\n\nशायद यह जॉब पहले कभी प्रिंट नहीं हुई है, या किसी अन्य नाम से सेव है।";
                } else {
                    $answer = "❌ **No sir, we haven't found any plate for \"{$displayJob}\".**\n\nIt seems this job hasn't been printed before, or it's saved under a different name.";
                }
                return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            } elseif (empty($plateData)) {
                if ($userLang === 'Bengali') {
                    $answer = "❌ **কোনো প্লেট পাওয়া যায়নি।**\nদয়া করে সঠিক প্লেট নম্বর বা জবের নাম দিন।";
                } elseif ($userLang === 'Hindi') {
                    $answer = "❌ **कोई प्लेट नहीं मिली।**\nकृपया सही प्लेट नंबर या जॉब का नाम दें।";
                } else {
                    $answer = "❌ **No plates found.**\nPlease provide a valid plate number or job name.";
                }
                return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            }

            if (!empty($plateData)) {
                // Save context
                $_SESSION['ai_last_plate_id'] = $plateData[0]['id'];

                if ($jobSearchExplicit && count($plateData) > 1) {
                    $normStr = function($s) {
                        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', (string)$s), 'UTF-8');
                    };
                    $targetNorm = $normStr($jobSearchExplicit);
                    
                    usort($plateData, function($a, $b) use ($normStr, $targetNorm) {
                        $normA = $normStr($a['name'] ?? '');
                        $normB = $normStr($b['name'] ?? '');
                        if ($normA === $targetNorm) return -1;
                        if ($normB === $targetNorm) return 1;
                        if ($targetNorm !== '' && strpos($normA, $targetNorm) !== false && strpos($normB, $targetNorm) === false) return -1;
                        if ($targetNorm !== '' && strpos($normB, $targetNorm) !== false && strpos($normA, $targetNorm) === false) return 1;
                        return 0;
                    });
                }

                $isColorQuery = (preg_match('/\b(color|colors|colour|colours|কালার|কয়টি কালার|কত কালার|রং|कितने कलर)\b/i', $prompt) !== 0);
                $directAnswerHeader = "";
                if ($isColorQuery && !empty($plateData)) {
                    $targetRow = $plateData[0];
                    $targetName = trim($targetRow['name'] ?? 'Target Plate');
                    $targetPlateNo = trim($targetRow['plate'] ?? '');
                    $targetPlateStr = $targetPlateNo ? " (Plate: {$targetPlateNo})" : "";
                    $tColors = [];
                    foreach(['c','m','y','k','special_1','special_2','special_3','special_4','special_5'] as $cField) {
                        $cv = trim((string)($targetRow[$cField] ?? ''));
                        if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-') {
                            $tColors[] = $cv;
                        }
                    }
                    $tColorCount = count($tColors);
                    $tColorStr = $tColorCount > 0 ? implode(', ', $tColors) : 'None';
                    
                    if ($userLang === 'Bengali') {
                        $directAnswerHeader = '<div style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%); border: 1.5px solid #3b82f6; border-left: 5px solid #2563eb; border-radius: 10px; padding: 12px 16px; margin: 10px 0 14px 0; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.08);">' . "\n"
                            . '  <div style="font-weight: 800; color: #1e40af; font-size: 0.95rem; margin-bottom: 6px;">🎯 <b>সরাসরি উত্তর - "' . htmlspecialchars($targetName) . '"</b>' . htmlspecialchars($targetPlateStr) . '</div>' . "\n"
                            . '  <div style="font-size: 0.9rem; color: #1e293b; line-height: 1.6;">' . "\n"
                            . '    ▸ <b>মোট কালার:</b> <span style="background: #2563eb; color: #ffffff; padding: 2px 8px; border-radius: 12px; font-size: 0.82rem; font-weight: 700;">' . $tColorCount . 'টি</span><br>' . "\n"
                            . '    ▸ <b>কালার লিস্ট:</b> <code style="background: #ffffff; color: #0f172a; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 600; font-family: monospace;">' . htmlspecialchars($tColorStr) . '</code>' . "\n"
                            . '  </div>' . "\n"
                            . '</div>' . "\n\n";
                    } elseif ($userLang === 'Hindi') {
                        $directAnswerHeader = '<div style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%); border: 1.5px solid #3b82f6; border-left: 5px solid #2563eb; border-radius: 10px; padding: 12px 16px; margin: 10px 0 14px 0; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.08);">' . "\n"
                            . '  <div style="font-weight: 800; color: #1e40af; font-size: 0.95rem; margin-bottom: 6px;">🎯 <b>सीधा उत्तर - "' . htmlspecialchars($targetName) . '"</b>' . htmlspecialchars($targetPlateStr) . '</div>' . "\n"
                            . '  <div style="font-size: 0.9rem; color: #1e293b; line-height: 1.6;">' . "\n"
                            . '    ▸ <b>कुल कलर:</b> <span style="background: #2563eb; color: #ffffff; padding: 2px 8px; border-radius: 12px; font-size: 0.82rem; font-weight: 700;">' . $tColorCount . '</span><br>' . "\n"
                            . '    ▸ <b>कलर लिस्ट:</b> <code style="background: #ffffff; color: #0f172a; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 600; font-family: monospace;">' . htmlspecialchars($tColorStr) . '</code>' . "\n"
                            . '  </div>' . "\n"
                            . '</div>' . "\n\n";
                    } else {
                        $directAnswerHeader = '<div style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%); border: 1.5px solid #3b82f6; border-left: 5px solid #2563eb; border-radius: 10px; padding: 12px 16px; margin: 10px 0 14px 0; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.08);">' . "\n"
                            . '  <div style="font-weight: 800; color: #1e40af; font-size: 0.95rem; margin-bottom: 6px;">🎯 <b>Direct Answer for "' . htmlspecialchars($targetName) . '"</b>' . htmlspecialchars($targetPlateStr) . '</div>' . "\n"
                            . '  <div style="font-size: 0.9rem; color: #1e293b; line-height: 1.6;">' . "\n"
                            . '    ▸ <b>Total Colors Used:</b> <span style="background: #2563eb; color: #ffffff; padding: 2px 8px; border-radius: 12px; font-size: 0.82rem; font-weight: 700;">' . $tColorCount . ' Colors</span><br>' . "\n"
                            . '    ▸ <b>Color Breakdown:</b> <code style="background: #ffffff; color: #0f172a; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 600; font-family: monospace;">' . htmlspecialchars($tColorStr) . '</code>' . "\n"
                            . '  </div>' . "\n"
                            . '</div>' . "\n\n";
                    }
                }

                $sampleCount = count($plateData);

                if ($userLang === 'Bengali') {
                    $answer = "📐 **Plate Management & Math Engine:**\n\n" . $directAnswerHeader . "আপনার ডাটাবেসে **{$sampleCount}টি ম্যাচিং প্লেট** পাওয়া গেছে:\n\n";
                } elseif ($userLang === 'Hindi') {
                    $answer = "📐 **Plate Management & Math Engine:**\n\n" . $directAnswerHeader . "आपके डेटाबेस में **{$sampleCount} मैचिंग प्लेट** मिले हैं:\n\n";
                } else {
                    $answer = "📐 **Plate Management & Math Engine:**\n\n" . $directAnswerHeader . "Found **{$sampleCount} matching plate(s)** in your database:\n\n";
                }

                foreach ($plateData as $idx => $row) {
                    $answer .= $renderPlate($row, $idx + 1);
                }

                $answer .= "👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";

                return ['tool_used' => $toolName, 'total_count' => count($plateData), 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            }
        }
    }
    // ─── END PRIORITY MODE ───

    // 1. Company / Paper Type Analytics (Krishna, Austin, Navkar, NRGI, Chromo, Thermal etc.)
    $compName = '';
    $companies = ['nrgi', 'krishna', 'austin', 'navkar', 'abhinav', 'raj paper', 'mangalam', 'paper n more', 'narsing das', 'avery', 'nitin', 'flexo'];
    foreach ($companies as $comp) {
        if (strpos($p, $comp) !== false) {
            $compName = strtoupper($comp);
            break;
        }
    }
    // Bengali fallback for few common ones
    if (empty($compName)) {
        if (strpos($p, 'কৃষ্ণা') !== false)
            $compName = 'KRISHNA';
        elseif (strpos($p, 'অস্টিন') !== false)
            $compName = 'AUSTIN';
        elseif (strpos($p, 'নভকার') !== false)
            $compName = 'NAVKAR';
        elseif (strpos($p, 'এনআরজিআই') !== false)
            $compName = 'NRGI';
        elseif (strpos($p, 'নিতিন') !== false)
            $compName = 'NITIN';
    }

    $paperTypeFilter = '';
    if (strpos($p, 'chromo') !== false || strpos($p, 'ক্রোম') !== false || strpos($p, 'ক্রোমো') !== false) {
        $paperTypeFilter = 'chromo';
    } elseif (strpos($p, 'thermal') !== false || strpos($p, 'থার্মাল') !== false) {
        $paperTypeFilter = 'thermal';
    } elseif (strpos($p, 'pp white') !== false || strpos($p, 'pp-white') !== false || strpos($p, 'পিপি হোয়াইট') !== false) {
        $paperTypeFilter = 'pp-white';
    } elseif (strpos($p, 'pp clear') !== false || strpos($p, 'pp-clear') !== false || strpos($p, 'পিপি ক্লিয়ার') !== false) {
        $paperTypeFilter = 'pp-clear';
    } elseif (strpos($p, 'maplitho') !== false || strpos($p, 'ম্যাপলিথো') !== false) {
        $paperTypeFilter = 'maplitho';
    }

    $hasToolIntent = (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false || ((strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false || strpos($p, 'কত') !== false) && (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || preg_match('/\b(run|paper)\b/', $p))));

    // If the user explicitly asked for a downloadable file (PDF / Excel / CSV / report / print),
    // skip the analytics dashboard so the filtered export handler below can generate the file.
    $isExportIntent = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || strpos($p, 'এক্সপোর্ট') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'download') !== false || strpos($p, 'ডাউনলোড') !== false || ((preg_match('/\bprint\b/i', $p) || strpos($p, 'প্রিন্ট') !== false) && !strpos($p, 'koto') && !strpos($p, 'কত') && !strpos($p, 'required') && !strpos($p, 'need') && !strpos($p, 'how many')));

    // If the user asked for a width threshold ("below 500mm", "500mm er niche", "above 1000mm"...),
    // skip the aggregate analytics dashboard — the detailed master-tool table below should
    // apply the width filter instead of showing unrelated totals.
    $hasWidthThreshold = (bool) preg_match('/(below|under|less\s*than|lesser|ar\s*niche|er\s*niche|niche|ar\s*kom|er\s*kom|ke\s*kom|এর\s*নিচে|নিচে|চেয়ে\s*কম|কম|chhoto|ছোট|above|more\s*than|over|ar\s*upore|er\s*upore|upore|ar\s*beshi|er\s*beshi|beshi|এর\s*বেশি|বেশি|উপরে|চেয়ে\s*বেশি|boro|বড়|<|>)\s*(?:size\s*(?:of)?\s*)?\d{3,4}\s*mm/i', $p)
        || (bool) preg_match('/\d{3,4}\s*mm\s*(below|under|less\s*than|lesser|ar\s*niche|er\s*niche|niche|ar\s*kom|er\s*kom|ke\s*kom|এর\s*নিচে|নিচে|চেয়ে\s*কম|কম|<|above|more\s*than|over|ar\s*upore|er\s*upore|upore|ar\s*beshi|er\s*beshi|beshi|এর\s*বেশি|বেশি|উপরে|>|boro|বড়)/i', $p);

    if ((!empty($compName) || !empty($paperTypeFilter)) && !$hasToolIntent && !$isExportIntent && !$hasWidthThreshold) {
        $toolName = "Paper Stock Analytics Tool (" . trim($compName . ' ' . strtoupper($paperTypeFilter)) . ")";

        $likeComp = $compName ? ('%' . $compName . '%') : '%';

        $typeOp = 'LIKE';
        if ($paperTypeFilter === 'pp-white' || $paperTypeFilter === 'pp-clear') {
            $likeType = $paperTypeFilter; // exact match
        } else {
            $likeType = $paperTypeFilter ? ('%' . $paperTypeFilter . '%') : '%';
        }

        $stmtSum = $db->prepare("
            SELECT 
                COUNT(*) as total_rolls,
                SUM(length_mtr) as total_running_mtr,
                SUM((width_mm / 1000.0) * length_mtr) as total_sqm,
                SUM(CASE WHEN width_mm >= 1000 THEN 1 ELSE 0 END) as jumbo_rolls_count,
                SUM(CASE WHEN width_mm < 1000 THEN 1 ELSE 0 END) as slitted_rolls_count
            FROM paper_stock 
            WHERE company LIKE ? AND paper_type LIKE ? AND status IN ('Main','Stock','Job Assign')
        ");
        $stmtSum->bind_param('ss', $likeComp, $likeType);
        $stmtSum->execute();
        $summaryRow = $stmtSum->get_result()->fetch_assoc();

        $totalRolls = (int) ($summaryRow['total_rolls'] ?? 0);
        $totalRunningMtr = round((float) ($summaryRow['total_running_mtr'] ?? 0), 2);
        $totalSqm = round((float) ($summaryRow['total_sqm'] ?? 0), 2);
        $jumboCount = (int) ($summaryRow['jumbo_rolls_count'] ?? 0);
        $slittedCount = (int) ($summaryRow['slitted_rolls_count'] ?? 0);


        $labelStr = trim($compName . ' ' . strtoupper($paperTypeFilter));
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "📊 **{$labelStr} Paper Stock Analytics Dashboard:**\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                . "🔢 **Total Paper Rolls:** **" . number_format($totalRolls) . " Rolls**\n"
                . "📏 **Total Running Length:** **" . number_format($totalRunningMtr, 2) . " meters**\n"
                . "📐 **Total Paper Area (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n\n"
                . "📜 **Jumbo Parent Rolls (≥1000mm):** **" . number_format($jumboCount) . " Rolls** (1000mm or above width)\n"
                . "✂️ **Slitted Stock Rolls (<1000mm):** **" . number_format($slittedCount) . " Rolls**\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n"
                . "👉 [Open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "📊 **{$labelStr} पेपर स्टॉक डैशबोर्ड:**\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                . "🔢 **कुल पेपर रोल:** **" . number_format($totalRolls) . " रोल**\n"
                . "📏 **कुल रनिंग मीटर:** **" . number_format($totalRunningMtr, 2) . " मीटर**\n"
                . "📐 **कुल क्षेत्रफल (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n\n"
                . "📜 **जंबो रोल (चौड़ाई ≥ 1000mm):** **" . number_format($jumboCount) . " जंबो रोल**\n"
                . "✂️ **स्लिटेड रोल (चौड़ाई < 1000mm):** **" . number_format($slittedCount) . " रोल**\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n"
                . "👉 [पेपर स्टॉक पेज खोलें]({$baseUrl}/modules/paper_stock/index.php)";
        } else {
            $answer = "📊 **{$labelStr} পেপার স্টক ড্যাশবোর্ড:**\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                . "🔢 **মোট পেপার রোল:** **" . number_format($totalRolls) . "টি রোল**\n"
                . "📏 **মোট রানিং মিটার:** **" . number_format($totalRunningMtr, 2) . " মিটার**\n"
                . "📐 **মোট ক্ষেত্রফল (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n\n"
                . "📜 **জাম্বো রোল (চওড়াई ≥ ১০০০ মিমি):** **" . number_format($jumboCount) . "টি জাম্বো রোল**\n"
                . "✂️ **স্লিটেড রোল (চওড়াई < ১০০০ মিমি):** **" . number_format($slittedCount) . "টি রোল**\n\n"
                . "━━━━━━━━━━━━━━━━━━━━━━\n"
                . "👉 [পেপার স্টক পেজ খুলুন]({$baseUrl}/modules/paper_stock/index.php)";
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $totalRolls,
            'total_meters' => $totalRunningMtr,
            'filtered_type' => $labelStr,
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $baseUrl . '/modules/paper_stock/index.php',
            'data' => []
        ];
    }

    // 2. Ambiguous Roll Count Clarification Router
    $isTotalRollQuery = (
        (strpos($p, 'roll') !== false || strpos($p, 'রোল') !== false) &&
        (strpos($p, 'total') !== false || strpos($p, 'koto') !== false || strpos($p, 'কত') !== false || strpos($p, 'how many') !== false) &&
        strpos($p, 'chromo') === false && strpos($p, 'thermal') === false && strpos($p, 'slc/') === false
    );

    if ($isTotalRollQuery) {
        $toolName = 'ERP Roll Inventory Clarification Tool';

        $pRes = $db->query("SELECT COUNT(*) as cnt, SUM(length_mtr) as total_mtr FROM paper_stock WHERE status IN ('Main','Stock','Job Assign')");
        $paperRow = $pRes ? $pRes->fetch_assoc() : [];
        $paperCount = (int) ($paperRow['cnt'] ?? 0);
        $paperMtr = round((float) ($paperRow['total_mtr'] ?? 0), 2);

        $fgRes = $db->query("SELECT COUNT(*) as cnt, SUM(quantity) as total_qty FROM finished_goods_stock WHERE quantity > 0");
        $fgRow = $fgRes ? $fgRes->fetch_assoc() : [];
        $fgCount = (int) ($fgRow['cnt'] ?? 0);
        $fgQty = (int) ($fgRow['total_qty'] ?? 0);



        if ($userLang === 'English') {
            $answer = "📊 **Total ERP Roll Summary Breakdown:**\n\n"
                . "Your ERP database contains 2 categories of roll inventory:\n\n"
                . "1. 📜 **Parent Paper Stock Rolls:** **" . number_format($paperCount) . " Rolls** (Total **" . number_format($paperMtr, 2) . " meters** in stock)\n"
                . "2. 📦 **Finished Goods Packed Rolls:** **" . number_format($fgCount) . " Batches / Rolls** (Total **" . number_format($fgQty) . " items**)\n\n"
                . "❓ **Which specific roll details would you like to view?**\n"
                . "• Reply **\"Show paper stock rolls\"** for Parent Jumbo Paper Rolls\n"
                . "• Reply **\"Show finished goods stock\"** for Packed Finished Label Rolls";
        } elseif ($userLang === 'Hindi') {
            $answer = "📊 **कुल ईआरपी रोल सारांश:**\n\n"
                . "आपके ईआरपी डेटाबेस में 2 प्रकार के रोल उपलब्ध हैं:\n\n"
                . "1. 📜 **मदर पेपर स्टॉक रोल:** **" . number_format($paperCount) . " रोल** (कुल **" . number_format($paperMtr, 2) . " मीटर**)\n"
                . "2. 📦 **फिनिश्ड गुड्स पैक्ड रोल:** **" . number_format($fgCount) . " बैच / रोल** (कुल **" . number_format($fgQty) . " पीस**)\n\n"
                . "❓ **आप किस रोल का विवरण देखना चाहते हैं?**\n"
                . "• **\"Paper stock roll dekhaw\"** टाइप करें - मदर पेपर रोल के लिए\n"
                . "• **\"Finished goods stock dekhaw\"** टाइप करें - पैक्ड फिनिश्ड रोल के लिए";
        } else {
            $answer = "📊 **মোট ইআরপি রোল স্টক সারাংশ:**\n\n"
                . "আপনার ইআরপি ডাটাবেসে ২ ধরনের রোল উপলব্ধ রয়েছে:\n\n"
                . "১. 📜 **মাদার পেপার স্টক রোল:** **" . number_format($paperCount) . "টি রোল** (সর্বমোট **" . number_format($paperMtr, 2) . " মিটার**)\n"
                . "২. 📦 **ফিনিশ্ড গুডস প্যাকড রোল:** **" . number_format($fgCount) . "টি ব্যাচ/প্যাকড রোল** (সর্বমোট **" . number_format($fgQty) . "টি**)\n\n"
                . "❓ **আপনি কোন রোলের বিস্তারিত তালিকা দেখতে চান?**\n"
                . "• টাইপ করুন: **\"Paper stock roll dekhaw\"** (মদর পেপার রোলের জন্য)\n"
                . "• টাইপ করুন: **\"Finished goods stock dekhaw\"** (প্যাকড ফিনিশ্ড রোলের জন্য)";
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $paperCount + $fgCount,
            'total_meters' => $paperMtr,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'data' => []
        ];
    }

    // 3. ERP Executive Dashboard & KPI Overview Intent Matcher
    if (strpos($p, 'dash') !== false || strpos($p, 'kpi') !== false || strpos($p, 'overview') !== false || strpos($p, 'metric') !== false || strpos($p, 'analytic') !== false || preg_match('/\b(stat|stats|statistics)\b/i', $prompt) || strpos($p, 'executive') !== false || strpos($p, 'ড্যাশবোর্ড') !== false) {

        $toolName = 'ERP Executive Dashboard & KPI Tool';

        $stockCount = (int) ($db->query("SELECT COUNT(*) as c FROM paper_stock WHERE LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')")->fetch_assoc()['c'] ?? 0);
        $stockMtr = round((float) ($db->query("SELECT IFNULL(SUM(length_mtr),0) as total FROM paper_stock WHERE LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')")->fetch_assoc()['total'] ?? 0), 2);
        $lowStock = (int) ($db->query("SELECT COUNT(*) as c FROM paper_stock WHERE status='Available' AND length_mtr < 500")->fetch_assoc()['c'] ?? 0);

        $estimatesActive = (int) ($db->query("SELECT COUNT(*) as c FROM estimates WHERE LOWER(COALESCE(status,'')) NOT IN ('rejected','converted','cancelled')")->fetch_assoc()['c'] ?? 0);
        $estimatesVal = round((float) ($db->query("SELECT IFNULL(SUM(selling_price),0) as total FROM estimates WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetch_assoc()['total'] ?? 0), 2);

        $ordersActive = (int) ($db->query("SELECT COUNT(*) as c FROM sales_orders WHERE LOWER(COALESCE(status,'')) NOT IN ('completed','dispatched','cancelled','closed')")->fetch_assoc()['c'] ?? 0);

        $jobsActive = (int) ($db->query("SELECT COUNT(*) as c FROM planning WHERE LOWER(COALESCE(status,'')) NOT IN ('completed','closed','finalized','cancelled','done')")->fetch_assoc()['c'] ?? 0);
        $jobsRunning = (int) ($db->query("SELECT COUNT(*) as c FROM jobs WHERE LOWER(status) = 'running'")->fetch_assoc()['c'] ?? 0);
        $jobsPending = (int) ($db->query("SELECT COUNT(*) as c FROM jobs WHERE LOWER(status) IN ('pending','queued')")->fetch_assoc()['c'] ?? 0);
        $jobsCompletedMonth = (int) ($db->query("SELECT COUNT(*) as c FROM jobs WHERE LOWER(status) IN ('closed','finalized','completed','qc passed') AND completed_at IS NOT NULL AND DATE_FORMAT(completed_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetch_assoc()['c'] ?? 0);


        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "📊 **ERP Executive Dashboard & Live System KPIs:**\n\n"
                . "Here is the real-time operational summary from your ERP Dashboard:\n\n"
                . "📜 **Paper Roll Inventory:**\n"
                . "  - Total Available Rolls: **" . number_format($stockCount) . " Rolls** (" . number_format($stockMtr, 2) . " meters)\n"
                . "  - Low Stock Alert (<500m): **" . number_format($lowStock) . " Rolls**\n\n"
                . "🏭 **Production & Live Floor:**\n"
                . "  - Active Master Jobs: **" . number_format($jobsActive) . " Jobs**\n"
                . "  - Currently Running Jobs: **" . number_format($jobsRunning) . " Jobs**\n"
                . "  - Pending / Queued Department Jobs: **" . number_format($jobsPending) . " Job Cards**\n"
                . "  - Completed Jobs This Month: **" . number_format($jobsCompletedMonth) . " Jobs**\n\n"
                . "💼 **Sales & Estimates:**\n"
                . "  - Active Running Sales Orders: **" . number_format($ordersActive) . " Orders**\n"
                . "  - Active Cost Estimates: **" . number_format($estimatesActive) . " Estimates** (This Month Value: **₹" . number_format($estimatesVal, 2) . "**)\n\n"
                . "👉 [Click here to open Executive Dashboard Page]({$baseUrl}/modules/dashboard/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "📊 **ईआरपी एक्जीक्यूटिव डैशबोर्ड और लाइव केपीआई सारांश:**\n\n"
                . "आपके ईआरपी डैशबोर्ड से वास्तविक समय का डेटा सारांश:\n\n"
                . "📜 **पेपर रोल इन्वेंटरी:**\n"
                . "  - कुल उपलब्ध रोल: **" . number_format($stockCount) . " रोल** (" . number_format($stockMtr, 2) . " मीटर)\n"
                . "  - कम स्टॉक अलर्ट (<500m): **" . number_format($lowStock) . " रोल**\n\n"
                . "🏭 **उत्पादन और लाइव फ्लोर:**\n"
                . "  - सक्रिय मास्टर जॉब्स: **" . number_format($jobsActive) . " जॉब्स**\n"
                . "  - वर्तमान में चल रहे जॉब्स: **" . number_format($jobsRunning) . " जॉब्स**\n"
                . "  - पेंडिंग / किउड डिपार्टमेंट जॉब्स: **" . number_format($jobsPending) . " जब कार्ड**\n"
                . "  - इस महीने पूर्ण जॉब्स: **" . number_format($jobsCompletedMonth) . " जॉब्स**\n\n"
                . "💼 **बिक्री और अनुमान:**\n"
                . "  - सक्रिय बिक्री के आदेश: **" . number_format($ordersActive) . " ऑर्डर**\n"
                . "  - सक्रिय लागत अनुमान: **" . number_format($estimatesActive) . " अनुमान** (इस महीने का मूल्य: **₹" . number_format($estimatesVal, 2) . "**)\n\n"
                . "👉 [एक्जीक्यूटिव डैशबोर्ड पेज खोलें]({$baseUrl}/modules/dashboard/index.php)";
        } else {
            $answer = "📊 **ইআরপি এক্সিকিউটিভ ড্যাশবোর্ড ও লাইভ কেপিআই ওভারভিউ:**\n\n"
                . "আপনার ইআরপি ড্যাশবোর্ড থেকে রিয়েল-টাইম রানিং ডাটা সম্বলিত ওভারভিউ:\n\n"
                . "📜 **পেপার রোল ইনভেন্টরি:**\n"
                . "  - কুল উপলব্ধ রোল: **" . number_format($stockCount) . " রোল** (" . number_format($stockMtr, 2) . " মিটার)\n"
                . "  - কম স্টক অলর্ট (<৫০০মি.): **" . number_format($lowStock) . " রোল**\n\n"
                . "🏭 **উত্পাদন ও লাইভ ফ্লোর:**\n"
                . "  - সক্রিয় মাস্টার জব: **" . number_format($jobsActive) . " জব**\n"
                . "  - বর্তমানে চল রহে জব: **" . number_format($jobsRunning) . " জব**\n"
                . "  - পেন্ডিং / কিউড ডিপার্টমেন্ট জব: **" . number_format($jobsPending) . " জব কার্ড**\n"
                . "  - চলতি মাসে সম্পন্ন জব: **" . number_format($jobsCompletedMonth) . " জব**\n\n"
                . "💼 **বিক্রী ও অনুমান:**\n"
                . "  - সক্রিয় বিক্রী আদেশ: **" . number_format($ordersActive) . " টি অর্ডার**\n"
                . "  - সক্রিয় লাগত অনুমান: **" . number_format($estimatesActive) . " টি এস্টিমেট** (চলতি মাসের মোট ভ্যালু: **₹" . number_format($estimatesVal, 2) . "**)\n\n"
                . "👉 [ড্যাশবোর্ড পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/dashboard/index.php)";
        }

        $navUrl = null;
        if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
            $navUrl = $baseUrl . '/modules/dashboard/index.php';
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $jobsActive + $stockCount,
            'total_meters' => $stockMtr,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'data' => []
        ];
    } elseif ($commandType === 'dispatch' || strpos($p, 'dispatch') !== false || strpos($p, 'dispatched') !== false || strpos($p, 'ready queue') !== false || strpos($p, 'ready stock') !== false || strpos($p, 'challan') !== false || strpos($p, 'sales person') !== false || strpos($p, 'ডিস্পैচ') !== false || strpos($p, 'রেডি') !== false) {
        $toolName = 'Dispatch & Ready Queue Master Tool';

        // Ready Queue (Finished Goods ready to ship)
        $readyRows = $db->query("SELECT id, category, item_name, item_code, size, quantity, dispatch_qty_total, COALESCE(closing_stock, quantity - dispatch_qty_total) as ready_qty, unit, batch_no FROM finished_goods_stock WHERE COALESCE(closing_stock, quantity - dispatch_qty_total) > 0 ORDER BY id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);

        $totalReadyItems = count($readyRows);
        $totalReadyQty = 0;
        foreach ($readyRows as $rr) {
            $totalReadyQty += (float) $rr['ready_qty'];
        }

        // Dispatches Stats
        $dStatRes = $db->query("
            SELECT 
                COUNT(*) as total_dispatches,
                IFNULL(SUM(dispatch_qty),0) as total_dispatched_qty,
                IFNULL(SUM(transport_cost),0) as total_transport_cost,
                SUM(CASE WHEN LOWER(COALESCE(delivery_status,'')) IN ('pending','in transit','in_transit') THEN 1 ELSE 0 END) as pending_transit,
                SUM(CASE WHEN LOWER(COALESCE(delivery_status,'')) = 'delivered' THEN 1 ELSE 0 END) as delivered_cnt
            FROM dispatch_entries
        ")->fetch_assoc();

        $totalDispatches = (int) ($dStatRes['total_dispatches'] ?? 0);
        $totalDispatchedQty = (float) ($dStatRes['total_dispatched_qty'] ?? 0);
        $totalTransportCost = (float) ($dStatRes['total_transport_cost'] ?? 0);
        $pendingTransit = (int) ($dStatRes['pending_transit'] ?? 0);
        $deliveredCnt = (int) ($dStatRes['delivered_cnt'] ?? 0);


        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "🚚 **Dispatch & Ready Queue 3-Tab Operational Summary:**\n\n"
                . "📦 **1. Ready Queue (Awaiting Dispatch — For Sales & Dispatch Operators):**\n"
                . "  - Ready Finished Stock Items: **" . number_format($totalReadyItems) . " Items / Batches**\n"
                . "  - Total Quantity Available to Ship: **" . number_format($totalReadyQty) . " PCS / Labels**\n\n"
                . "🚚 **2. Live Dispatch Operations Summary:**\n"
                . "  - Total Shipment Records: **" . number_format($totalDispatches) . " Dispatches**\n"
                . "  - Total Dispatched Quantity: **" . number_format($totalDispatchedQty) . " PCS**\n"
                . "  - Pending Delivery / In Transit: **" . number_format($pendingTransit) . " Shipments**\n"
                . "  - Successfully Delivered: **" . number_format($deliveredCnt) . " Shipments**\n"
                . "  - Total Transport Logistics Spend: **₹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "📋 **Itemized Ready Stock List (Awaiting Dispatch Handover):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "• **Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}** | Batch: `" . ($r['batch_no'] ?: 'N/A') . "`)\n"
                    . "  - 📐 **Size:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 📦 **Ready Stock to Dispatch:** **" . number_format((float) $r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "👉 [Click here to open Dispatch Workspace]({$baseUrl}/modules/dispatch/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "🚚 **डिस्पैच और रेडी क्यू 3-टैब संचालन सारांश:**\n\n"
                . "📦 **1. रेडी क्यू (डिस्पैच के लिए तैयार — सेल टीम के लिए):**\n"
                . "  - रेडी फिनिश्ड स्टॉक आइटम: **" . number_format($totalReadyItems) . " आइटम**\n"
                . "  - तैयार उपलब्ध मात्रा: **" . number_format($totalReadyQty) . " पीस**\n\n"
                . "🚚 **2. लाइव डिस्पैच अप्रियान्त्रिक सारांश:**\n"
                . "  - कुल डिस्पैच रिकॉर्ड: **" . number_format($totalDispatches) . " डिस्पैच**\n"
                . "  - कुल डिस्पैचड मात्रा: **" . number_format($totalDispatchedQty) . " पीस**\n"
                . "  - पेंडिंग डेलिभरी / इन-ट्रांजिट: **" . number_format($pendingTransit) . " शिपमेंट**\n"
                . "  - सफल डेलिभरड: **" . number_format($deliveredCnt) . " शिपमेंट**\n"
                . "  - कुल ट्रांसपोर्ट लागत: **₹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "📋 **रेडी स्टॉक सूची (डिस्पैच के लिए तैयार):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "• **आइटम " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}**)\n"
                    . "  - 📐 **साइज़:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 📦 **रेडी डिस्पैच स्टॉक:** **" . number_format((float) $r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "👉 [डिस्पैच पेज खोलें]({$baseUrl}/modules/dispatch/index.php)";
        } else {
            $answer = "🚚 **ডিস্পৈচ ও রেডি কিউ ৩-ট্যাব অপারেশনাল সামারি:**\n\n"
                . "📦 **১. রেডি কিউ (ডিস্পৈচের জন্য প্রস্তুত স্টক - সেলস টিম ও অপারেটরদের জন্য):**\n"
                . "  - রেডি ফিনিশ্ড স্টক আইটেম: **" . number_format($totalReadyItems) . " আইটেম**\n"
                . "  - তৈয়ার উপলব্ধ মাত্রা: **" . number_format($totalReadyQty) . " পিস**\n\n"
                . "🚚 **২. লাইভ ডিস্পৈচ অপারেশন সামারি:**\n"
                . "  - কুল ডিস্পৈচ রিকোর্ড: **" . number_format($totalDispatches) . " ডিস্পৈচ**\n"
                . "  - কুল ডিস্পৈচড মাত্রা: **" . number_format($totalDispatchedQty) . " পিস**\n"
                . "  - পেন্ডিং ডেলিভারি / ইন-ট্রানজিট: **" . number_format($pendingTransit) . " শিপমেন্ট**\n"
                . "  - সফল ডেলিভারড: **" . number_format($deliveredCnt) . " শিপমেন্ট**\n"
                . "  - কুল ট্রান্সপোর্ট লাগত: **₹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "📋 **রেডি স্টক আইটেম তালিকা (ডিস্পৈচের জন্য প্রস্তুত):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "• **আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}** | ব্যাচ: `" . ($r['batch_no'] ?: 'N/A') . "`)\n"
                    . "  - 📐 **সাইজ:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 📦 **রেডি ডিস্পৈচ স্টক:** **" . number_format((float) $r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "👉 [ডিস্পৈচ পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/dispatch/index.php)";
        }

        $navUrl = null;
        if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
            $navUrl = $baseUrl . '/modules/dispatch/index.php';
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $totalReadyItems + $totalDispatches,
            'total_meters' => 0,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'data' => []
        ];
    } elseif (strpos($p, 'finished') !== false || strpos($p, 'fg stock') !== false || strpos($p, 'fg') !== false || strpos($p, 'packed label') !== false || strpos($p, 'packed stock') !== false || strpos($p, 'ফিনিশড') !== false || strpos($p, 'প্যাকড') !== false) {

        $toolName = 'Finished Goods Stock Master Tool';

        $sum = $db->query("
            SELECT 
                COUNT(*) as total_items,
                IFNULL(SUM(quantity),0) as total_qty,
                IFNULL(SUM(dispatch_qty_total),0) as total_dispatch,
                IFNULL(SUM(COALESCE(closing_stock, quantity - dispatch_qty_total)),0) as total_closing
            FROM finished_goods_stock
        ")->fetch_assoc();

        $totalItems = (int) ($sum['total_items'] ?? 0);
        $totalQty = (float) ($sum['total_qty'] ?? 0);
        $totalDispatch = (float) ($sum['total_dispatch'] ?? 0);
        $totalClosing = (float) ($sum['total_closing'] ?? 0);

        $where = ["1=1"];
        if (strpos($p, 'barcode') !== false) {
            $where[] = "category = 'barcode'";
        } elseif (strpos($p, 'print') !== false) {
            $where[] = "category = 'printing_label'";
        } elseif (strpos($p, 'pos') !== false) {
            $where[] = "category = 'pos_paper_roll'";
        } elseif (strpos($p, 'carton') !== false) {
            $where[] = "category = 'carton'";
        }

        $whereSql = implode(' AND ', $where);
        $rows = $db->query("SELECT id, category, item_name, item_code, size, quantity, dispatch_qty_total, COALESCE(closing_stock, quantity - dispatch_qty_total) as available_closing, unit, location, batch_no, date FROM finished_goods_stock WHERE {$whereSql} ORDER BY id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);


        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "📦 **Finished Goods & Packed Label Stock — Live Inventory Summary:**\n\n"
                . "📊 **Inventory Summary Metrics:**\n"
                . "  - Total Finished Products/Batches: **" . number_format($totalItems) . " Items**\n"
                . "  - Total Quantity Packed: **" . number_format($totalQty) . " PCS / Labels**\n"
                . "  - Total Dispatched Quantity: **" . number_format($totalDispatch) . " PCS**\n"
                . "  - Total Available Closing Stock: **" . number_format($totalClosing) . " PCS**\n\n"
                . "📦 **Master Finished Stock Grid:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | Category: **" . strtoupper($r['category']) . "**)\n"
                    . "  - 📐 **Size & Spec:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **Packed Quantity:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🚚 **Dispatched:** **" . number_format((float) $r['dispatch_qty_total']) . " PCS** | Available Closing: **" . number_format((float) $r['available_closing']) . " PCS**\n"
                    . "  - 🏷️ **Batch / Job No:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [Click here to open Finished Goods Page]({$baseUrl}/modules/inventory/finished/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "📦 **फिनिश्ड गुड्स और पैक्ड लेबल स्टॉक — लाइव इन्वेंटरी सारांश:**\n\n"
                . "📊 **इन्वेंटरी सारांश:**\n"
                . "  - कुल फिनिश्ड उत्पाद / बैच: **" . number_format($totalItems) . " आइटम**\n"
                . "  - कुल पैक्ड मात्रा: **" . number_format($totalQty) . " पीस**\n"
                . "  - कुल डिस्पैच मात्रा: **" . number_format($totalDispatch) . " पीस**\n"
                . "  - कुल उपलब्ध क्लोजिंग स्टॉक: **" . number_format($totalClosing) . " पीस**\n\n"
                . "📦 **फिनिश्ड स्टॉक ग्रिड:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **आइटम " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}**)\n"
                    . "  - 📐 **साइज़:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **पैक्ड मात्रा:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🚚 **डिस्पैचड:** **" . number_format((float) $r['dispatch_qty_total']) . " PCS** | उपलब्ध क्लोजिंग स्टॉक: **" . number_format((float) $r['available_closing']) . " PCS**\n"
                    . "  - 🏷️ **बैच / जब नंबर:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [फिनिश्ड गुड्स पेज खोलें]({$baseUrl}/modules/inventory/finished/index.php)";
        } else {
            $answer = "📦 **ফিনিশড গুডস ও প্যাকড লেবেল স্টক — লাইভ ইনভেন্টরি সামারি:**\n\n"
                . "📊 **ইনভেন্টরি সামারি ম্যাট্রিক্স:**\n"
                . "  - সর্বমোট ফিনিশড প্রোডাক্ট/ব্যাচ: **" . number_format($totalItems) . "টি আইটেম**\n"
                . "  - সর্বমোট প্যাকড কোয়ান্টিটি: **" . number_format($totalQty) . "টি পিস/লেবেল**\n"
                . "  - সর্বমোট ডিস্পৈচড কোয়ান্টিটি: **" . number_format($totalDispatch) . "টি পিস**\n"
                . "  - সর্বমোট উপলব্ধ ক্লোজিং স্টক: **" . number_format($totalClosing) . "টি পিস**\n\n"
                . "📦 **মাস্টার ফিনিশড স্টক গ্রিড:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | ক্যাটাগরি: **" . strtoupper($r['category']) . "**)\n"
                    . "  - 📐 **সাইজ ও স্পেক:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **প্যাকড কোয়ান্টিটি:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🚚 **ডিস্পৈচড:** **" . number_format((float) $r['dispatch_qty_total']) . "টি পিস** | উপলব্ধ ক্লোজিং স্টক: **" . number_format((float) $r['available_closing']) . "টি পিস**\n"
                    . "  - 🏷️ **ব্যাচ / জব নম্বর:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [ফিনিশড গুডস পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/inventory/finished/index.php)";
        }

        $navUrl = null;
        if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
            $navUrl = $baseUrl . '/modules/inventory/finished/index.php';
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $totalItems,
            'total_meters' => 0,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'data' => []
        ];
    } elseif (strpos($p, 'login') !== false || strpos($p, 'logged') !== false || strpos($p, 'who am i') !== false || strpos($p, 'user id') !== false || strpos($p, 'active user') !== false || strpos($p, 'লগইন') !== false || strpos($p, 'ইউজার') !== false) {
        $toolName = 'User & Session Master Tool';

        $sessionUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;

        $currRes = $db->query("SELECT id, name, email, role, is_active, updated_at FROM users WHERE id = {$sessionUserId}");
        $currUser = $currRes ? $currRes->fetch_assoc() : null;

        $allRes = $db->query("SELECT id, name, email, role, is_active, updated_at FROM users WHERE is_active = 1 ORDER BY id ASC");
        $allUsers = $allRes ? $allRes->fetch_all(MYSQLI_ASSOC) : [];


        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "👤 **System User & Active Session Overview:**\n\n";

            if ($currUser) {
                $answer .= "🔑 **Currently Active Logged In Session:**\n"
                    . "  - 🆔 **User ID:** **`#" . $currUser['id'] . "`**\n"
                    . "  - 👤 **Name:** **" . $currUser['name'] . "**\n"
                    . "  - 🛡️ **Role:** **" . strtoupper($currUser['role']) . "**\n"
                    . "  - 📧 **Email:** `" . $currUser['email'] . "`\n"
                    . "  - ⏰ **Last Session Activity:** `" . $currUser['updated_at'] . "`\n\n";
            }

            $answer .= "👥 **Registered Active System Users (" . count($allUsers) . " Users):**\n\n";

            foreach ($allUsers as $u) {
                $timeStr = date('d M Y, h:i A', strtotime($u['updated_at']));
                $answer .= "• **User ID `#" . $u['id'] . "`: " . $u['name'] . "** (Role: `" . strtoupper($u['role']) . "`)\n"
                    . "  - ⏰ **Last Activity / Login:** `" . $timeStr . "`\n\n";
            }

            $answer .= "👉 [Click here to open User Management Page]({$baseUrl}/modules/hr_management/users/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "👤 **सिस्टम उपयोगकर्ता और सक्रिय सत्र विवरण:**\n\n";

            if ($currUser) {
                $answer .= "🔑 **वर्तमान लॉग-इन सत्र:**\n"
                    . "  - 🆔 **उपयोगकर्ता आईडी (User ID):** **`#" . $currUser['id'] . "`**\n"
                    . "  - 👤 **नाम:** **" . $currUser['name'] . "**\n"
                    . "  - 🛡️ **रोल:** **" . strtoupper($currUser['role']) . "**\n"
                    . "  - 📧 **ईमेल:** `" . $currUser['email'] . "`\n"
                    . "  - ⏰ **अंतिम गतिविधि समय:** `" . $currUser['updated_at'] . "`\n\n";
            }

            $answer .= "👥 **पंजीकृत सक्रिय उपयोगकर्ता सूची (" . count($allUsers) . " उपयोगकर्ता):**\n\n";

            foreach ($allUsers as $u) {
                $answer .= "• **यूजर आईडी `#" . $u['id'] . "`: " . $u['name'] . "** (रोल: `" . strtoupper($u['role']) . "`)\n"
                    . "  - ⏰ **अंतिम गतिविधि:** `" . $u['updated_at'] . "`\n\n";
            }

            $answer .= "👉 [उपयोगकर्ता प्रबंधन पेज खोलें]({$baseUrl}/modules/hr_management/users/index.php)";
        } else {
            $answer = "👤 **সিস্টেম ইউজার ও অ্যাক্টিভ লগইন সেশন সামারি:**\n\n";

            if ($currUser) {
                $answer .= "🔑 **বর্তমানে সক্রিয় লগইন সেশন ইউজার:**\n"
                    . "  - 🆔 **ইউজার আইডেন্টিটি (User ID):** **`#" . $currUser['id'] . "`**\n"
                    . "  - 👤 **নাম (Name):** **" . $currUser['name'] . "**\n"
                    . "  - 🛡️ **রোল (Role):** **" . strtoupper($currUser['role']) . "**\n"
                    . "  - 📧 **ইমেইল (Email):** `" . $currUser['email'] . "`\n"
                    . "  - ⏰ **সর্বশেষ অ্যাক্টিভিটি / লগইন সময়:** `" . $currUser['updated_at'] . "`\n\n";
            }

            $answer .= "👥 **সিস্টেমে নিবন্ধিত অ্যাক্টিভ ইউজারদের তালিকা (" . count($allUsers) . "জন ইউজার):**\n\n";

            foreach ($allUsers as $u) {
                $timeStr = date('d M Y, h:i A', strtotime($u['updated_at']));
                $answer .= "• **ইউজার আইডি `#" . $u['id'] . "`: " . $u['name'] . "** (রোল: `" . strtoupper($u['role']) . "`)\n"
                    . "  - ⏰ **সর্বশেষ অ্যাক্টিভিটি / লগইন:** `" . $timeStr . "`\n\n";
            }

            $answer .= "👉 [ইউজার ম্যানেজমেন্ট পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/hr_management/users/index.php)";
        }

        $navUrl = null;
        if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
            $navUrl = $baseUrl . '/modules/hr_management/users/index.php';
        }

        return [
            'tool_used' => $toolName,
            'total_count' => count($allUsers),
            'total_meters' => 0,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'data' => []
        ];
    } elseif (strpos($p, 'finished') !== false || strpos($p, 'fg stock') !== false || strpos($p, 'fg') !== false || strpos($p, 'packed label') !== false || strpos($p, 'packed stock') !== false || strpos($p, 'ফিনিশড') !== false || strpos($p, 'প্যাকড') !== false) {

        $toolName = 'Finished Goods Stock Master Tool';

        // ---- "Which tab?" category detection (drill-down from the tab menu) ----
        $finishedCat = null;
        $finishedCatLabel = '';
        if (strpos($p, 'barcode') !== false) {
            $finishedCat = 'barcode';
            $finishedCatLabel = 'Barcode';
        } elseif (strpos($p, 'print') !== false) {
            $finishedCat = 'printing_label';
            $finishedCatLabel = 'Printing Label';
        } elseif (strpos($p, 'pos') !== false) {
            $finishedCat = 'pos_paper_roll';
            $finishedCatLabel = 'POS & Paper Roll';
        } elseif (strpos($p, 'carton') !== false) {
            $finishedCat = 'carton';
            $finishedCatLabel = 'Carton';
        }

        $finishedCats = "'barcode','printing_label','pos_paper_roll','carton'";
        $catWhere = $finishedCat ? " AND category = '" . $db->real_escape_string($finishedCat) . "'" : '';

        $cntRes = $db->query("SELECT COUNT(*) as cnt, IFNULL(SUM(quantity),0) as total_qty FROM finished_goods_stock WHERE quantity > 0 {$catWhere}");
        $sumRow = $cntRes ? $cntRes->fetch_assoc() : [];
        $totalItems = (int) ($sumRow['cnt'] ?? 0);
        $totalQty = (float) ($sumRow['total_qty'] ?? 0);

        $rows = $db->query("SELECT id, category, item_name, item_code, size, quantity, unit, batch_no, date, remarks FROM finished_goods_stock WHERE quantity > 0 {$catWhere} ORDER BY id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);

        // Tab options rendered as clickable suggestion chips ("Which tab's report?")
        $tabOptions = [
            'Show finished goods: Barcode',
            'Show finished goods: Printing Label',
            'Show finished goods: POS & Paper Roll',
            'Show finished goods: Carton',
        ];

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        // Category-specific tab report (a tab was picked from the menu)
        if ($finishedCat) {
            if ($userLang === 'English') {
                $answer = "📦 **Finished Goods — {$finishedCatLabel} Tab Report:**\n\n";
                if ($totalItems === 0) {
                    $answer .= "No **{$finishedCatLabel}** finished goods are currently in stock.\n\n";
                } else {
                    $answer .= "📊 **{$finishedCatLabel} Stock Metrics:**\n"
                        . "  - Total {$finishedCatLabel} Items: **" . number_format($totalItems) . " Items**\n"
                        . "  - Total Quantity: **" . number_format($totalQty) . " PCS**\n\n"
                        . "📋 **{$finishedCatLabel} Stock Grid:**\n\n";
                    foreach ($rows as $idx => $r) {
                        $answer .= "• **Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | Category: **" . strtoupper($r['category']) . "**)\n"
                            . "  - 📐 **Size & Spec:** **" . ($r['size'] ?: 'N/A') . "**\n"
                            . "  - 🔢 **Quantity:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                            . "  - 🏷️ **Batch / Job No:** `" . ($r['batch_no'] ?: 'N/A') . "`\n"
                            . "  - 📅 **Date:** `" . ($r['date'] ?: 'N/A') . "`\n\n";
                    }
                }
            } elseif ($userLang === 'Hindi') {
                $answer = "📦 **फिनिश्ड गुड्स — {$finishedCatLabel} टैब रिपोर्ट:**\n\n";
                if ($totalItems === 0) {
                    $answer .= "कोई **{$finishedCatLabel}** फिनिश्ड गुड्स स्टॉक में उपलब्ध नहीं है।\n\n";
                } else {
                    $answer .= "📊 **{$finishedCatLabel} स्टॉक मेट्रिक्स:**\n"
                        . "  - कुल {$finishedCatLabel} आइटम: **" . number_format($totalItems) . " आइटम**\n"
                        . "  - कुल मात्रा: **" . number_format($totalQty) . " पीस**\n\n"
                        . "📋 **{$finishedCatLabel} स्टॉक ग्रिड:**\n\n";
                    foreach ($rows as $idx => $r) {
                        $answer .= "• **आइटम " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | Category: **" . strtoupper($r['category']) . "**)\n"
                            . "  - 📐 **आकार & विशेषता:** **" . ($r['size'] ?: 'N/A') . "**\n"
                            . "  - 🔢 **मात्रा:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                            . "  - 🏷️ **बैच / जॉब नंबर:** `" . ($r['batch_no'] ?: 'N/A') . "`\n"
                            . "  - 📅 **तारीख:** `" . ($r['date'] ?: 'N/A') . "`\n\n";
                    }
                }
            } else {
                $answer = "📦 **ফিনিশ্ড গুডস — {$finishedCatLabel} ট্যাব রিপোর্ট:**\n\n";
                if ($totalItems === 0) {
                    $answer .= "কোন **{$finishedCatLabel}** ফিনিশ্ড গুডস স্টকে উপলব্ধ নেই।\n\n";
                } else {
                    $answer .= "📊 **{$finishedCatLabel} স্টক মেট্রিক্স:**\n"
                        . "  - মোট {$finishedCatLabel} আইটেম: **" . number_format($totalItems) . " আইটেম**\n"
                        . "  - মোট পরিমাণ: **" . number_format($totalQty) . " পিস**\n\n"
                        . "📋 **{$finishedCatLabel} স্টক গ্রিড:**\n\n";
                    foreach ($rows as $idx => $r) {
                        $answer .= "• **আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | Category: **" . strtoupper($r['category']) . "**)\n"
                            . "  - 📐 **আকার & স্পেক:** **" . ($r['size'] ?: 'N/A') . "**\n"
                            . "  - 🔢 **পরিমাণ:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                            . "  - 🏷️ **ব্যাচ / জব নম্বর:** `" . ($r['batch_no'] ?: 'N/A') . "`\n"
                            . "  - 📅 **তারিখ:** `" . ($r['date'] ?: 'N/A') . "`\n\n";
                    }
                }
            }

            $suggestions = generate_erp_suggestions($prompt, $answer, 'Finished Goods Stock Master Tool', $userLang);
            echo json_encode([
                'ok' => true,
                'answer' => $answer,
                'provider' => 'Finished Goods Stock Engine',
                'tool_used' => 'Finished Goods Stock Master Tool',
                'suggestions' => $suggestions
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        } else {
            // No specific tab requested, show general summary and ask for tab
            if ($userLang === 'English') {
                $answer = "📦 **Finished Goods Stock — Overall Summary:**\n\n"
                    . "📊 **Total Finished Goods in Stock:**\n"
                    . "  - Total Items: **" . number_format($totalItems) . " Items**\n"
                    . "  - Total Quantity: **" . number_format($totalQty) . " PCS**\n\n"
                    . "📋 **Please select a tab to view detailed stock:**\n\n";
            } elseif ($userLang === 'Hindi') {
                $answer = "📦 **फिनिश्ड गुड्स स्टॉक — समग्र सारांश:**\n\n"
                    . "📊 **स्टॉक में कुल फिनिश्ड गुड्स:**\n"
                    . "  - कुल आइटम: **" . number_format($totalItems) . " आइटम**\n"
                    . "  - कुल मात्रा: **" . number_format($totalQty) . " पीस**\n\n"
                    . "📋 **कृपया विस्तृत स्टॉक देखने के लिए एक टैब चुनें:**\n\n";
            } else {
                $answer = "📦 **ফিনিশ্ড গুডস স্টক — সামগ্রিক সারাংশ:**\n\n"
                    . "📊 **স্টকে মোট ফিনিশ্ড গুডস:**\n"
                    . "  - মোট আইটেম: **" . number_format($totalItems) . " আইটেম**\n"
                    . "  - মোট পরিমাণ: **" . number_format($totalQty) . " পিস**\n\n"
                    . "📋 **দ্রষ্টব্য স্টক দেখতে একটি ট্যাব বেছে নিন:**\n\n";
            }

            $suggestions = $tabOptions;
            echo json_encode([
                'ok' => true,
                'answer' => $answer,
                'provider' => 'Finished Goods Stock Engine',
                'tool_used' => 'Finished Goods Stock Master Tool',
                'suggestions' => $suggestions
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

    } elseif (strpos($p, 'mixed') !== false || strpos($p, 'extra') !== false || strpos($p, 'extra pool') !== false || strpos($p, 'repack') !== false || strpos($p, 'মিক্সড') !== false || strpos($p, 'এক্সট্রা') !== false) {

        $toolName = 'Mixed Item Inventory Master Tool';

        // ---- "Which tab?" category detection (drill-down from the tab menu) ----
        $mixedCat = null;
        $mixedCatLabel = '';
        if (strpos($p, 'pos') !== false) {
            $mixedCat = 'pos_paper_roll';
            $mixedCatLabel = 'POS & Paper Roll';
        } elseif (strpos($p, '1 ply') !== false || strpos($p, 'one ply') !== false) {
            $mixedCat = 'one_ply';
            $mixedCatLabel = '1 Ply';
        } elseif (strpos($p, '2 ply') !== false || strpos($p, 'two ply') !== false) {
            $mixedCat = 'two_ply';
            $mixedCatLabel = '2 Ply';
        } elseif (strpos($p, 'printing label') !== false) {
            $mixedCat = 'printing_label';
            $mixedCatLabel = 'Printing Label';
        } elseif (strpos($p, 'printing roll') !== false) {
            $mixedCat = 'printing_roll';
            $mixedCatLabel = 'Printing Roll';
        } elseif (strpos($p, 'barcode') !== false) {
            $mixedCat = 'barcode';
            $mixedCatLabel = 'Barcode';
        }

        if (!defined('MI_HELPER_ONLY')) {
            define('MI_HELPER_ONLY', true);
        }
        require_once __DIR__ . '/../inventory/mixed-item/api/mixed_item_api.php';

        $rows = mi_fetch_rows($db, $mixedCat ?: '');
        $totalItems = count($rows);
        $totalExtraQty = 0;
        foreach ($rows as $mr) {
            $totalExtraQty += (float) ($mr['extra_qty'] ?? 0);
        }

        $assignRes = $db->query("SELECT COUNT(*) as cnt FROM mixed_item_assignments WHERE status = 'pending'");
        $pendingAssign = $assignRes ? (int) ($assignRes->fetch_assoc()['cnt'] ?? 0) : 0;

        // Tab options rendered as clickable suggestion chips ("Which tab's report?")
        $tabOptions = [
            'Show mixed items: POS & Paper Roll',
            'Show mixed items: 1 Ply',
            'Show mixed items: 2 Ply',
            'Show mixed items: Barcode',
            'Show mixed items: Printing Roll',
            'Show mixed items: Printing Label',
        ];

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        // Category-specific tab report (a tab was picked from the menu)
        if ($mixedCat) {
            if ($userLang === 'English') {
                $answer = "🔀 **Mixed Item — {$mixedCatLabel} Tab Report:**\n\n";
                if ($totalItems === 0) {
                    $answer .= "No **{$mixedCatLabel}** mixed/extra items are currently in the pool.\n\n";
                } else {
                    $answer .= "📊 **{$mixedCatLabel} Pool Metrics:**\n"
                        . "  - Total {$mixedCatLabel} Items: **" . number_format($totalItems) . " Items**\n"
                        . "  - Total Extra Quantity: **" . number_format($totalExtraQty) . " PCS / Rolls**\n\n"
                        . "📋 **{$mixedCatLabel} Extra Items Grid:**\n\n";
                    foreach ($rows as $idx => $r) {
                        $qtyStr = number_format((float) ($r['extra_qty'] ?? $r['quantity'] ?? 0)) . " " . ($r['unit_type'] ?? 'PCS');
                        $answer .= "• **Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **" . ($r['source_id'] ?? $r['id']) . "** | Category: **" . strtoupper($r['category']) . "**)\n"
                            . "  - 📐 **Size & Spec:** **" . ($r['size'] ?: 'N/A') . "**\n"
                            . "  - 🔢 **Extra Quantity:** **" . $qtyStr . "**\n"
                            . "  - 🏷️ **Batch / Job No:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
                    }
                }
                $answer .= "👉 [Open Mixed Item Inventory Page]({$baseUrl}/modules/inventory/mixed-item/index.php)\n\n"
                    . "🔀 **Need another tab?** Pick one:";
            } elseif ($userLang === 'Hindi') {
                $answer = "🔀 **मिक्सड आइटम — {$mixedCatLabel} टैब रिपोर्ट:**\n\n";
                if ($totalItems === 0) {
                    $answer .= "इस श्रेणी में फिलहाल कोई मिक्सड/एक्स्ट्रा आइटम नहीं है।\n\n";
                } else {
                    $answer .= "📊 **{$mixedCatLabel} पूल मेट्रिक्स:**\n"
                        . "  - कुल आइटम: **" . number_format($totalItems) . " आइटम**\n"
                        . "  - कुल एक्स्ट्रा मात्रा: **" . number_format($totalExtraQty) . " पीस**\n\n"
                        . "📋 **{$mixedCatLabel} एक्स्ट्रा आइटम ग्रिड:**\n\n";
                    foreach ($rows as $idx => $r) {
                        $qtyStr = number_format((float) ($r['extra_qty'] ?? $r['quantity'] ?? 0)) . " " . ($r['unit_type'] ?? 'PCS');
                        $answer .= "• **आइटम " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **" . ($r['source_id'] ?? $r['id']) . "**)\n"
                            . "  - 📐 **साइज़:** **" . ($r['size'] ?: 'N/A') . "**\n"
                            . "  - 🔢 **एक्स्ट्रा मात्रा:** **" . $qtyStr . "**\n"
                            . "  - 🏷️ **बैच नंबर:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
                    }
                }
                $answer .= "👉 [मिक्सड आइटम पेज खोलें]({$baseUrl}/modules/inventory/mixed-item/index.php)\n\n"
                    . "🔀 **किसी और टैब की रिपोर्ट चाहिए?** चुनें:";
            } else {
                $answer = "🔀 **মিক্সড আইটেম — {$mixedCatLabel} ট্যাব রিপোর্ট:**\n\n";
                if ($totalItems === 0) {
                    $answer .= "এই ক্যাটাগরিতে বর্তমানে কোনো মিক্সড/এক্সট্রা আইটেম নেই।\n\n";
                } else {
                    $answer .= "📊 **{$mixedCatLabel} পুল ম্যাট্রিক্স:**\n"
                        . "  - সর্বমোট {$mixedCatLabel} আইটেম: **" . number_format($totalItems) . "টি আইটেম**\n"
                        . "  - সর্বমোট এক্সট্রা কোয়ান্টিটি: **" . number_format($totalExtraQty) . "টি পিস/লেবেল**\n\n"
                        . "📋 **{$mixedCatLabel} এক্সট্রা আইটেম গ্রিড:**\n\n";
                    foreach ($rows as $idx => $r) {
                        $qtyStr = number_format((float) ($r['extra_qty'] ?? $r['quantity'] ?? 0)) . " " . ($r['unit_type'] ?? 'PCS');
                        $answer .= "• **এক্সট্রা আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **" . ($r['source_id'] ?? $r['id']) . "** | ক্যাটাগরি: **" . strtoupper($r['category']) . "**)\n"
                            . "  - 📐 **সাইজ ও স্পেক:** **" . ($r['size'] ?: 'N/A') . "**\n"
                            . "  - 🔢 **এক্সট্রা কোয়ান্টিটি:** **" . $qtyStr . "**\n"
                            . "  - 🏷️ **ব্যাচ / জব নম্বর:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
                    }
                }
                $answer .= "👉 [মিক্সড আইটেম পেজটি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/inventory/mixed-item/index.php)\n\n"
                    . "🔀 **অন্য কোনো ট্যাবের রিপোর্ট লাগবে?** একটি বেছে নিন:";
            }
        } elseif ($userLang === 'English') {
            $answer = "🔀 **Mixed Item & Extra Production Pool — Live Inventory Summary:**\n\n"
                . "📊 **Pool Metrics Summary:**\n"
                . "  - Total Extra Pool Batches: **" . number_format($totalItems) . " Items**\n"
                . "  - Total Extra Stock Quantity: **" . number_format($totalExtraQty) . " PCS / Rolls**\n"
                . "  - Pending Handover Assignments: **" . number_format($pendingAssign) . " Assignments** (Target: Packing / Planning / Repack)\n\n"
                . "🔀 **Active Mixed Extra Items Grid:**\n\n";

            foreach ($rows as $idx => $r) {
                $qtyStr = number_format((float) ($r['extra_qty'] ?? $r['quantity'] ?? 0)) . " " . ($r['unit_type'] ?? 'PCS');
                $answer .= "• **Extra Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **" . ($r['source_id'] ?? $r['id']) . "** | Category: **" . strtoupper($r['category']) . "**)\n"
                    . "  - 📐 **Size & Spec:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **Extra Quantity:** **" . $qtyStr . "**\n"
                    . "  - 🏷️ **Batch / Job No:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [Click here to open Mixed Item Inventory Page]({$baseUrl}/modules/inventory/mixed-item/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "🔀 **मिक्सड आइटम और एक्स्ट्रा प्रोडक्शन पूल — लाइव इन्वेंटरी सारांश:**\n\n"
                . "📊 **पूल मेट्रिक्स सारांश:**\n"
                . "  - कुल एक्स्ट्रा पूल बैच: **" . number_format($totalItems) . " आइटम**\n"
                . "  - कुल एक्स्ट्रा स्टॉक मात्रा: **" . number_format($totalExtraQty) . " पीस**\n"
                . "  - पेंडिंग हैंडओवर असाइनमेंट: **" . number_format($pendingAssign) . " असाइनमेंट**\n\n"
                . "🔀 **एक्टिव मिक्सड आइटम ग्रिड:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **आइटम " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}**)\n"
                    . "  - 📐 **साइज़:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **एक्स्ट्रा मात्रा:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🏷️ **बैच नंबर:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "�️ **किस टैब की रिपोर्ट चाहिए?** नीचे से चुनें:\n\n"
                . "  - 🔘 **POS & Paper Roll** — `Show mixed items: POS & Paper Roll`\n"
                . "  - 🔘 **1 Ply** — `Show mixed items: 1 Ply`\n"
                . "  - 🔘 **2 Ply** — `Show mixed items: 2 Ply`\n"
                . "  - 🔘 **Barcode** — `Show mixed items: Barcode`\n"
                . "  - 🔘 **Printing Roll** — `Show mixed items: Printing Roll`\n"
                . "  - 🔘 **Printing Label** — `Show mixed items: Printing Label`\n\n";

            $answer .= "�👉 [मिक्सड आइटम पेज खोलें]({$baseUrl}/modules/inventory/mixed-item/index.php)";
        } else {
            $answer = "🔀 **মিক্সড আইটেম ও এক্স্ট্রা প্রোডাকশন পুল — লাইভ ইনভেন্টরি সামারি:**\n\n"
                . "📊 **পূল ম্যাট্রিক্স সারাংশ:**\n"
                . "  - সর্বমোট এক্স্ট্রা পুল ব্যাচ: **" . number_format($totalItems) . "টি আইটেম**\n"
                . "  - সর্বমোট এক্স্ট্রা স্টক কোয়ান্টিটি: **" . number_format($totalExtraQty) . "টি পিস/লেবেল**\n"
                . "  - পেন্ডিং হৈন্ডওভার অসাইনমেন্ট: **" . number_format($pendingAssign) . "টি অসাইনমেন্ট**\n\n"
                . "🔀 **অ্যাক্টিভ মিক্সড এক্স্ট্রা আইটেম গ্রিড:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **এক্সট্রা আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}** | ক্যাটাগরি: **" . strtoupper($r['category']) . "**)\n"
                    . "  - 📐 **সাইজ ও স্পেক:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **এক্স্ট্রা কোয়ান্টিটি:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🏷️ **ব্যাচ / জব নম্বর:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "�️ **কোন ট্যাবের রিপোর্ট লাগবে?** নিচ থেকে একটি বেছে নিন:\n\n"
                . "  - 🔘 **POS & Paper Roll** — `Show mixed items: POS & Paper Roll`\n"
                . "  - 🔘 **1 Ply** — `Show mixed items: 1 Ply`\n"
                . "  - 🔘 **2 Ply** — `Show mixed items: 2 Ply`\n"
                . "  - 🔘 **Barcode** — `Show mixed items: Barcode`\n"
                . "  - 🔘 **Printing Roll** — `Show mixed items: Printing Roll`\n"
                . "  - 🔘 **Printing Label** — `Show mixed items: Printing Label`\n\n";

            $answer .= "�👉 [মিক্সড আইটেম পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/inventory/mixed-item/index.php)";
        }

        $navUrl = null;
        if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
            $navUrl = $baseUrl . '/modules/inventory/mixed-item/index.php';
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $totalItems,
            'total_meters' => 0,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'suggestions' => $tabOptions,
            'data' => []
        ];
    }



    // 3.5 Operator Workload Intent Matcher
    if (mb_strpos($p, 'operator') !== false || mb_strpos($p, 'machine man') !== false || mb_strpos($p, 'assignment') !== false || mb_strpos($p, 'অপারেটর') !== false || mb_strpos($p, 'মেশিনম্যান') !== false) {
        $toolName = 'Operator Workload Tool';

        $sql = "SELECT j.id as job_id, j.job_no, j.job_type, j.department, j.status as job_status, 
                       p.job_name, p.priority, 
                       COALESCE(u.name, UPPER(REPLACE(j.department, '_', ' '))) as operator_name 
                FROM jobs j 
                JOIN planning p ON j.planning_id = p.id 
                LEFT JOIN users u ON j.operator_id = u.id 
                WHERE j.deleted_at IS NULL AND p.deleted_at IS NULL 
                  AND j.status IN ('Pending', 'Queued', 'Running', 'Hold', 'Paused', 'In Progress')
                ORDER BY j.department ASC, p.priority DESC, j.id ASC";

        $res = $db->query($sql);
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

        $totalCount = count($rows);
        $answer = "🧑‍🔧 **Department-Wise Job Workload (Operator & Machine Man):**\n\n";

        if ($totalCount === 0) {
            $answer .= "No pending or running jobs are currently active on the floor.\n";
        } else {
            $grouped = [];
            foreach ($rows as $r) {
                $grouped[$r['operator_name']][] = $r;
            }

            foreach ($grouped as $opName => $jobs) {
                $answer .= "🏭 **{$opName}** — `" . count($jobs) . " Jobs Active`\n";
                foreach ($jobs as $j) {
                    $statusIcon = ($j['job_status'] === 'Running') ? '🟢' : '🟡';
                    $answer .= "  - {$statusIcon} {$j['job_type']} ({$j['department']}): **{$j['job_no']}** — {$j['job_name']}\n";
                }
                $answer .= "\n";
            }
        }

        $navUrl = (defined('BASE_URL') ? BASE_URL : '/shree-label-php') . '/modules/operators/';
        $answer .= "👉 [Click here to open Operator Modules]({$navUrl})";

        return [
            'tool_used' => $toolName,
            'total_count' => $totalCount,
            'total_meters' => 0,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'data' => $rows
        ];
    }

    // 4. Live Production Floor Intent Matcher

    if ((($commandType ?? '') === 'job' && mb_strpos($p, 'plan') === false && mb_strpos($p, 'operator') === false && mb_strpos($p, 'machine man') === false && mb_strpos($p, 'assignment') === false) || mb_strpos($p, 'live') !== false || mb_strpos($p, 'life') !== false || mb_strpos($p, 'floor') !== false || mb_strpos($p, 'stage') !== false || mb_strpos($p, 'next department') !== false || mb_strpos($p, 'journey') !== false || mb_strpos($p, 'current job') !== false || mb_strpos($p, 'running job') !== false || mb_strpos($p, 'লাইভ') !== false || mb_strpos($p, 'ফ্লোর') !== false) {
        $toolName = 'Live Production Floor Pipeline Tool';

        $liveStopwords = ['can', 'you', 'what', 'is', 'the', 'status', 'of', 'current', 'job', 'jobs', 'running', 'live', 'life', 'floor', 'show', 'tell', 'me', 'details', 'for', 'in', 'page', 'pages', 'summary', 'sumary', 'overview', 'list', 'all', 'production', 'pipeline', 'report', 'reports', 'board', 'kholo', 'khul', 'open', 'go', 'to', 'dekhao', 'dekaw', 'batao', 'give', 'bring', 'find', 'search', 'how', 'many', 'are', 'there', 'please', 'pls', 'lookup', 'display', 'and', 'or', 'about', 'with', 'this', 'that', 'get', 'fetch', 'on', 'at', 'from', 'a', 'an', 'ki', 'kya', 'hai', 'ami', 'tumi', 'do', 'we', 'have', 'any', 'my'];
        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9\-\.]/', '', $w));
            if ($wClean !== '' && !in_array($wClean, $liveStopwords, true) && strlen($wClean) >= 2) {
                $terms[] = $wClean;
            }
        }
        $searchKey = implode(' ', $terms);

        $sql = "SELECT p.id as planning_id, p.job_no as planning_no, p.job_name, p.status as planning_status, p.priority, p.created_at as planning_date,
                       j.id as job_id, j.job_no, j.job_type, j.department, j.status as job_status, j.created_at as job_date
                FROM planning p
                LEFT JOIN jobs j ON j.planning_id = p.id AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
                WHERE p.deleted_at IS NULL";

        if ($searchKey !== '') {
            $sql .= " AND (p.job_name LIKE '%" . $db->real_escape_string($searchKey) . "%' OR p.job_no LIKE '%" . $db->real_escape_string($searchKey) . "%' OR j.job_no LIKE '%" . $db->real_escape_string($searchKey) . "%')";
        }

        $sql .= " ORDER BY p.id DESC, j.id ASC LIMIT 25";

        $res = $db->query($sql);
        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];


        $grouped = [];
        foreach ($rows as $r) {
            $pid = $r['planning_id'];
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = [
                    'planning_id' => $pid,
                    'planning_no' => $r['planning_no'],
                    'job_name' => $r['job_name'],
                    'priority' => $r['priority'] ?: 'Normal',
                    'planning_status' => $r['planning_status'] ?: 'Planning Stage',
                    'planning_date' => $r['planning_date'],
                    'departments' => []
                ];
            }
            if (!empty($r['job_id'])) {
                $grouped[$pid]['departments'][] = [
                    'job_id' => $r['job_id'],
                    'job_no' => $r['job_no'],
                    'job_type' => $r['job_type'],
                    'department' => $r['department'],
                    'status' => $r['job_status'] ?: 'Queued',
                    'created_at' => $r['job_date']
                ];
            }
        }

        $totalCount = count($grouped);

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "🏭 **Live Production Floor — Job Journey & Multi-Department Pipeline Summary:**\n\n"
                . "Found **{$totalCount} Master Jobs** moving across production departments:\n\n";

            foreach ($grouped as $job) {
                $answer .= "📋 **Master Job: `{$job['planning_no']}`** | **{$job['job_name']}** (Priority: `{$job['priority']}`)\n";

                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - ⏳ **Current Stage:** `Planning Stage` (Queued for departmental assignment)\n"
                        . "  - ⏩ **Next Pipeline:** Jumbo Slitting ➔ Flexo Printing ➔ Label Slitting ➔ Packing ➔ Finished Goods\n"
                        . "  - 📊 **Remaining Departments to Cross:** `4 Departments left`\n\n";
                    continue;
                }

                $current = null;
                $upcoming = [];

                foreach ($depts as $d) {
                    $deptName = strtoupper(str_replace('_', ' ', $d['department']));
                    $st = strtolower($d['status']);
                    $timeTs = (!empty($d['created_at']) && $d['created_at'] !== '0000-00-00 00:00:00') ? strtotime($d['created_at']) : false;
                    $timeStr = ($timeTs && $timeTs > 0) ? date('d M Y, h:i A', $timeTs) : 'Recent';

                    if ($current === null && !in_array($st, ['completed', 'finished production', 'packing done'], true)) {
                        $current = "⚡ **CURRENT DEPARTMENT:** `{$deptName}` (`{$d['job_no']}`) | Status: **{$d['status']}** (Entry Time: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}`";
                    }
                }

                $upcoming[] = "`पैकिंग`";
                $upcoming[] = "`फिनिश्ड गुड्स स्टॉक`";

                $answer .= "  - " . ($current ?: "⚡ **CURRENT DEPARTMENT:** `Department Assignment in Progress`") . "\n";
                $answer .= "  - ⏩ **Next Pipeline:** " . implode(' ➔ ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - 📊 **Remaining Steps to Finished Production:** **`{$remCount} Departments / Stages left`**\n\n";
            }

            $answer .= "👉 [Click here to open full Live Production Floor page]({$baseUrl}/modules/live/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "🏭 **लाइव प्रोडक्शन फ्लोर — जॉब जर्नी ओ विभागीय स्थिति सारांश:**\n\n"
                . "कुल **{$totalCount} मास्टर जॉब्स** विभिन्न विभागों से होकर गुजर रहे हैं:\n\n";

            foreach ($grouped as $job) {
                $answer .= "📋 **मास्टर जॉब: `{$job['planning_no']}`** | **{$job['job_name']}** (प्राथमिकता: `{$job['priority']}`)\n";

                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - ⏳ **वर्तमान चरण:** `प्लानिंग स्टेज`\n"
                        . "  - 📊 **शेष विभाग (Remaining):** `4 विभाग बाकी हैं` (जंबो ➔ प्रिंटिंग ➔ स्लिटिंग ➔ पैकिंग ➔ फिनिश्ड)\n"
                        . "  - 📊 **बाकी डिपार्टमेंट:** `4 विभाग बाकी हैं`\n\n";
                    continue;
                }

                $current = null;
                $upcoming = [];

                foreach ($depts as $d) {
                    $deptName = strtoupper(str_replace('_', ' ', $d['department']));
                    $st = strtolower($d['status']);
                    $timeTs = (!empty($d['created_at']) && $d['created_at'] !== '0000-00-00 00:00:00') ? strtotime($d['created_at']) : false;
                    $timeStr = ($timeTs && $timeTs > 0) ? date('d M Y, h:i A', $timeTs) : 'Recent';

                    if ($current === null && !in_array($st, ['completed', 'finished production', 'packing done'], true)) {
                        $current = "⚡ **वर्तमान विभाग:** `{$deptName}` (`{$d['job_no']}`) | स्थिति: **{$d['status']}** (एन्ट्री समय: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}`";
                    }
                }

                $upcoming[] = "`पैकिंग`";
                $upcoming[] = "`फिनिश्ड गुड्स स्टॉक`";

                $answer .= "  - " . ($current ?: "⚡ **वर्तमान विभाग:** `Department Assignment in Progress`") . "\n";
                $answer .= "  - ⏩ **Next Pipeline:** " . implode(' ➔ ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - 📊 **फिनिश्ड प्रोडक्शन तक शेष डिपार्टमेंट:** **`{$remCount} विभाग बाकी हैं`**\n\n";
            }

            $answer .= "👉 [लाइव प्रोडक्शन फ्लोर पेज खोलें]({$baseUrl}/modules/live/index.php)";
        } else {
            $answer = "🏭 **Live Production Floor — Job Journey & Multi-Department Pipeline Summary:**\n\n"
                . "Found **{$totalCount} Master Jobs** moving across production departments:\n\n";

            foreach ($grouped as $job) {
                $answer .= "📋 **Master Job: `{$job['planning_no']}`** | **{$job['job_name']}** (Priority: `{$job['priority']}`)\n";

                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - ⏳ **Current Stage:** `Planning Stage` (Queued for departmental assignment)\n"
                        . "  - ⏩ **Next Pipeline:** Jumbo Slitting ➔ Flexo Printing ➔ Label Slitting ➔ Packing ➔ Finished Goods\n"
                        . "  - 📊 **Remaining Departments to Cross:** `4 Departments left`\n\n";
                    continue;
                }

                $current = null;
                $upcoming = [];

                foreach ($depts as $d) {
                    $deptName = strtoupper(str_replace('_', ' ', $d['department']));
                    $st = strtolower($d['status']);
                    $timeTs = (!empty($d['created_at']) && $d['created_at'] !== '0000-00-00 00:00:00') ? strtotime($d['created_at']) : false;
                    $timeStr = ($timeTs && $timeTs > 0) ? date('d M Y, h:i A', $timeTs) : 'Recent';

                    if ($current === null && !in_array($st, ['completed', 'finished production', 'packing done'], true)) {
                        $current = "⚡ **CURRENT DEPARTMENT:** `{$deptName}` (`{$d['job_no']}`) | Status: **{$d['status']}** (Entry Time: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}`";
                    }
                }

                $upcoming[] = "`पैकिंग`";
                $upcoming[] = "`फिनिश्ड गुड्स स्टॉक`";

                $answer .= "  - " . ($current ?: "⚡ **CURRENT DEPARTMENT:** `Department Assignment in Progress`") . "\n";
                $answer .= "  - ⏩ **Next Pipeline:** " . implode(' ➔ ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - 📊 **Remaining Steps to Finished Production:** **`{$remCount} Departments / Stages left`**\n\n";
            }

            $answer .= "👉 [Click here to open full Live Production Floor page]({$baseUrl}/modules/live/index.php)";
        }

        $navUrl = null;
        if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
            $navUrl = $baseUrl . '/modules/live/index.php';
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $totalCount,
            'total_meters' => 0,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'data' => []
        ];
    }



    if (strpos($p, 'plan') !== false || strpos($p, 'planning') !== false) {

        $toolName = 'Job Planning Board Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM planning WHERE deleted_at IS NULL");
        $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

        $res = $db->query("
            SELECT 
                p.id as planning_id, 
                p.job_no as planning_job_no, 
                p.job_name, 
                p.status as planning_status, 
                p.priority,
                j.job_no as dept_job_no, 
                j.department, 
                j.job_type, 
                j.status as dept_status
            FROM planning p
            LEFT JOIN jobs j ON j.planning_id = p.id
            WHERE p.deleted_at IS NULL
            ORDER BY p.id DESC, j.id ASC
        ");

        $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $grouped = [];
        foreach ($rows as $r) {
            $pid = $r['planning_id'];
            if (!isset($grouped[$pid])) {
                $grouped[$pid] = [
                    'planning_id' => $pid,
                    'job_no' => $r['planning_job_no'],
                    'job_name' => $r['job_name'],
                    'status' => $r['planning_status'] ?: 'Planning Stage',
                    'priority' => $r['priority'],
                    'departments' => []
                ];
            }
            if (!empty($r['department'])) {
                $grouped[$pid]['departments'][] = [
                    'department' => $r['department'],
                    'job_type' => $r['job_type'],
                    'job_no' => $r['dept_job_no'],
                    'status' => $r['dept_status'] ?: 'Queued'
                ];
            }
        }
        $data = array_values($grouped);
    } elseif ((strpos($p, 'company') !== false || strpos($p, 'companies') !== false || strpos($p, 'brand') !== false || strpos($p, 'supplier') !== false) && strpos($p, 'pp') === false && strpos($p, 'white') === false && strpos($p, 'chromo') === false && strpos($p, 'thermal') === false && strpos($p, 'jumbo') === false && strpos($p, 'slitting') === false) {

        $isCompanyList = true;
        $toolName = 'Paper Company Summary Tool';
        $res = $db->query("SELECT company, COUNT(*) as roll_count, SUM(length_mtr) as total_meters FROM paper_stock WHERE status IN ('Main','Stock','Job Assign') AND company != '' GROUP BY company ORDER BY roll_count DESC");
        $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $totalCount = count($data);
    } elseif ($commandType === 'plate' || (!preg_match('/\b(paper|stock|roll|finished|slitting)\b/i', $p) && (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false || strpos($p, 'die') !== false || strpos($p, 'cylinder') !== false || strpos($p, 'সিলিন্ডার') !== false || strpos($p, 'রিপিট') !== false || strpos($p, 'দাঁত') !== false || strpos($p, 'teeth') !== false || strpos($p, 'ups') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'কত') !== false || strpos($p, 'কতগুলো') !== false || strpos($p, 'kitne') !== false || ((strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false || strpos($p, 'কত') !== false || strpos($p, 'কয়টি') !== false || strpos($p, 'পাবো') !== false || strpos($p, 'কী') !== false || strpos($p, 'কি') !== false) && (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'মিটার') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || strpos($p, 'label') !== false || strpos($p, 'লেবেল') !== false || preg_match('/\b(run)\b/', $p)))))) {
        $prompt = html_entity_decode(stripslashes($prompt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Normalize Bengali numerals (০-৯ → 0-9) so plate numbers, sizes & math extract correctly
        $prompt = str_replace(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], $prompt);
        $p = mb_strtolower($prompt, 'UTF-8'); // keep $p in sync with the normalized prompt
        $toolName = 'Printing Plates Master Tool';

        // Check if it's a count/dashboard request
        $isCountIntent = (strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'কতগুলো') !== false || strpos($p, 'kitne') !== false || strpos($p, 'summary') !== false || strpos($p, 'dashboard') !== false || strpos($p, 'কত') !== false || strpos($p, 'koto') !== false || strpos($p, 'সবগুলো') !== false || preg_match('/(^|\s)সব(\s|$)/u', $p) === 1 || strpos($p, 'সকল') !== false || strpos($p, 'মোট') !== false || strpos($p, 'all') !== false) && !preg_match('/\b(run|paper|meter|mtr|qty|quantity|pcs)\b/i', $p);

        // Extract Plate No FLEXIBLY & STRICTLY:
        // 1. P-101, PLATE-101, SL-101
        // 2. plate 925, plate no 925, plate number 925, sl 925, sl no 925
        // 3. 925 number plate, 925 no plate, 925 plate, 925 নম্বর প্লেট
        // 4. /plate 925
        $plateNoMatch = null;
        if (preg_match('/\b(P-\d+|PLATE-\d+|SL-\d+)\b/i', $prompt, $m)) {
            $plateNoMatch = trim($m[1]);
        } elseif (preg_match('/(?:plate|প্লেট|প্লট|sl|এসএল)\s*(?:no|number|numb|nber|num|নম্বর|নং|আইডি|id)?\s*#?\s*(\d+)/i', $prompt, $m)) {
            $plateNoMatch = trim($m[1]);
        } elseif (preg_match('/(\d+)\s*(?:no|number|numb|nber|num|নম্বর|নং|আইডি|id)?\s*#?\s*(?:plate|প্লেট|প্লট)\b/i', $prompt, $m)) {
            $plateNoMatch = trim($m[1]);
        } elseif (preg_match('/^\/plate\s+(\d+)\b/i', trim($prompt), $m)) {
            $plateNoMatch = trim($m[1]);
        }

        // Extract Cylinder — supports "104 T", "9 inch", "9 ইঞ্চি", "104 সিলিন্ডার"
        $cylinderMatch = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:inch|ইঞ্চি|in)?\s*(?:cylinder|সিলিন্ডার)\b/i', $prompt, $m)) {
            $cylinderMatch = trim($m[1]);
        } elseif (preg_match('/(\d+(?:\.\d+)?)\s*(?:cylinder|সিলিন্ডার|teeth|teth|t)\b/i', $prompt, $m)) {
            $cylinderMatch = trim($m[1]);
        }

        // Extract Repeat
        $repeatMatch = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mm\s*)?(?:repeat|রিপিট)/i', $prompt, $m) || preg_match('/(?:repeat|রিপিট)\s*(\d+(?:\.\d+)?)/i', $prompt, $m)) {
            $repeatMatch = trim($m[1]);
        }

        // Extract Target Meters & Labels for Math
        $targetMeters = null;
        if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:running\s+)?(?:meter|meters|mtr|m(?!m)|\bমিটার\b|\bমিটারে\b)/i', $prompt, $m)) {
            $targetMeters = (float) str_replace(',', '', $m[1]);
        }

        $targetLabels = null;
        if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(lakh|লাখ|k|thousand|হাজার)?\s*(?:label|labels|pcs|piece|pieces|piss|pic|pics|পিস|লেবেল)/i', $prompt, $m) || preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:lakh|k|thousand)/i', $prompt, $m)) {
            $val = (float) str_replace(',', '', $m[1]);
            $mult = strtolower(trim($m[2] ?? ''));
            if ($mult === 'lakh' || $mult === 'লাখ')
                $val *= 100000;
            elseif ($mult === 'k' || $mult === 'thousand' || $mult === 'হাজার')
                $val *= 1000;
            $targetLabels = $val;
        }

        // Extract Paper Size (paper_size / size columns) — "পেপার সাইজ 200mm", "200mm পেপার", "paper size 200mm"
        $paperSizeMatch = null;
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mm)?\s*(?:paper\s*size|পেপার\s*সাইজ|paper|পেপার)/i', $prompt, $m) || preg_match('/(?:paper\s*size|পেপার\s*সাইজ)\s*(\d+(?:\.\d+)?)\s*mm?/i', $prompt, $m)) {
            $paperSizeMatch = trim($m[1]);
        }

        // Extract Die type (Flat Bed / Rotary / Slitting)
        $dieMatch = null;
        if (preg_match('/\b(flat\s?bed|flatbed|fat\s?bed|rotary|sliting|slitting)\b/i', $prompt, $m)) {
            $dieMatch = strtolower(trim($m[1]));
            $dieMatch = preg_replace('/\s+/', ' ', $dieMatch);
            if ($dieMatch === 'flatbed' || $dieMatch === 'fatbed' || $dieMatch === 'fat bed')
                $dieMatch = 'flat bed';
        }

        // Teeth / gearing intent
        $teethIntent = (strpos($p, 'দাঁত') !== false || strpos($p, 'teeth') !== false);

        // Color intents
        $moreThanFourColors = (preg_match('/more than 4|>4|4\+|4\s*টির বেশি|4\s*এর বেশি|৪\s*টির বেশি|৪\s*এর বেশি/i', $p) !== 0);

        // Latest / newest plate intent
        $latestPlateIntent = (
            strpos($p, 'latest') !== false ||
            strpos($p, 'newest') !== false ||
            strpos($p, 'last added') !== false ||
            strpos($p, 'latest added') !== false ||
            strpos($p, 'recently added') !== false ||
            strpos($p, 'recent plate') !== false ||
            strpos($p, 'last plate') !== false ||
            strpos($p, 'new plate') !== false ||
            strpos($p, 'নতুন প্লেট') !== false ||
            strpos($p, 'সবচেয়ে নতুন') !== false ||
            strpos($p, 'সর্বশেষ') !== false ||
            strpos($p, 'নতুন যোগ') !== false ||
            strpos($p, 'नई प्लेट') !== false ||
            strpos($p, 'नवीनतम') !== false
        );

        // Export query
        $isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || strpos($p, 'এক্সপোর্ট') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'download') !== false || strpos($p, 'ডাউনলোড') !== false || ((strpos($p, 'print') !== false || strpos($p, 'প্রিন্ট') !== false) && !strpos($p, 'koto') && !strpos($p, 'কত') && !strpos($p, 'required') && !strpos($p, 'need') && !strpos($p, 'how many')));

        // Find search terms loosely
        $pStopwords = [
            'can',
            'you',
            'tell',
            'me',
            'which',
            'is',
            'in',
            'my',
            'plate',
            'want',
            'to',
            'printing',
            'pitnting',
            'print',
            'plates',
            'list',
            'show',
            'details',
            'detail',
            'this',
            'the',
            'a',
            'an',
            'job',
            'jobs',
            'for',
            'about',
            'get',
            'search',
            'find',
            'there',
            'any',
            'named',
            'by',
            'name',
            'of',
            'with',
            'are',
            'do',
            'have',
            'exist',
            'does',
            'repeat',
            'gap',
            'gaph',
            'gapv',
            'size',
            'ups',
            'cylinder',
            'paper',
            'die',
            'core',
            'rewinding',
            'value',
            'color',
            'colors',
            'spec',
            'special',
            'what',
            'how',
            'give',
            'if',
            'run',
            'running',
            'much',
            'many',
            'quantity',
            'qty',
            'meter',
            'meters',
            'mtr',
            'will',
            'be',
            'produced',
            'print',
            'printing',
            'require',
            'required',
            'need',
            'needed',
            'or',
            'and',
            'calculate',
            'calculating',
            'calc',
            'length',
            'roll',
            'pcs',
            'pieces',
            'labels',
            'koto',
            'kotogulo',
            'hobe',
            'lagbe',
            'korle',
            'korte',
            'korbo',
            'korbe',
            'kori',
            'asob',
            'ar',
            'er',
            'diye',
            'giye',
            'ache',
            'hobe',
            'হবে',
            'আছে',
            'কত',
            'কতগুলো',
            'কি',
            'কী',
            'কোন',
            'pdf',
            'excel',
            'export',
            'download',
            'sir',
            'amra',
            'ai',
            'ta',
            'korechi',
            'kore',
            'chi',
            'hoyechhe',
            'kobe',
            'last',
            'naki',
            'ache',
            'এর',
            'ডিটেলস',
            'দেখাও',
            'dikhao',
            'dekhaw',
            'konta',
            'কোনটা',
            'dekhaben',
            'janaben',
            'jante',
            'chai',
            'লেবেলের',
            'লেবেল',
            'label',
            'labels',
            'প্লেট',
            'প্লেটের',
            'প্লট',
            'প্লটের',
            'qnty',
            'qnt',
            'quantity',
            'quantities',
            'piss',
            'piece',
            'pieces',
            'pic',
            'pics',
            'running',
            'run',
            'পেপারে',
            'পেপার',
            'মিটার',
            'মিটারে',
            'প্রিন্টিং',
            'প্রিন্ট',
            'total',
            'count',
            'summary',
            'dashboard',
            'all',
            'সব',
            'সকল',
            'din',
            'দিন',
            'দাও',
            'dao',
            'do',
            'deu',
            'dene',
            'dena',
            'dikhabe',
            'send',
            'পাঠাও',
            'pathao',
            'pathaben',
            'no',
            'number',
            'num',
            'nber',
            'numb',
            'id',
            'নম্বর',
            'নং',
            'আইডি',
            // Added for plate-specific query handling
            'মোট',
            'সর্বমোট',
            'সিলিন্ডার',
            'সিলিন্ডারের',
            'সিলিন্ডারটা',
            'রিপিট',
            'রিপিটের',
            'কালার',
            'কালারের',
            'কয়টি',
            'কয়',
            'দাঁত',
            'দাঁতের',
            'জবে',
            'জবের',
            'নতুন',
            'সবচেয়ে',
            'সর্বশেষ',
            'লেটেস্ট',
            'তৈরি',
            'নামে',
            'জন্য',
            'সাইজ',
            'পেপারসাইজ',
            'পেপারের',
            'কোনো',
            'inch',
            'ইঞ্চি',
            'ব্যবহার',
            'হয়',
            'লাগে',
            'প্লেটগুলো',
            'প্লেটগুলোতে',
            'প্লেটটি',
            'বেশি',
            'লাখ',
            'পিস',
            'হাজার',
            'হাজারে',
            'করে',
            'করা',
            'করার',
            'করতে',
            'থেকে',
            'এটা',
            'এটি',
            'ডাই',
            'টাইপ',
            'type',
            'যে',
            'যেগুলো',
            'যেগুলোতে',
            'খুঁজে',
            'দিয়েছি',
            'দিয়ে',
            'এরকম',
            'তোমার',
            'আমার',
            'konsa',
            'konsi',
            'kaun',
            'jis',
            'jinke',
            'লাগবে',
            'প্রয়োজন',
            'প্রয়োজনীয়',
            'চাই',
            'চাও',
            'চান',
            'we',
            'our',
            'us',
            'are',
            'were',
            'was',
            'has',
            'had',
            'have',
            'do',
            'does',
            'did',
            'will',
            'would',
            'can',
            'could',
            'should',
            'shall',
            'may',
            'might',
            'must',
            'shall',
            'আমি',
            'তুমি',
            'আপনি',
            'সে',
            'আমরা',
            'আপনরা',
            'তোমরা',
            'সবাই'
        ];

        $jobSearchExplicit = null;
        if (preg_match('/["“”]([^"“”]+)["“”]|\'([^\']+)\'/u', $prompt, $m)) {
            $jobSearchExplicit = trim($m[1] ?: $m[2]);
            if (preg_match('/[a-zA-Z]\d|\d[a-zA-Z]/', $jobSearchExplicit)) {
                $jobSearchExplicit = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $jobSearchExplicit);
                $jobSearchExplicit = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $jobSearchExplicit);
            }
        }

        $pWords = preg_split('/\s+/', $p);
        $pTerms = [];
        foreach ($pWords as $w) {
            $wC = trim(preg_replace('/[^\p{L}\p{N}\p{M}]/u', '', $w));
            if ($wC !== '' && !in_array($wC, $pStopwords, true) && mb_strlen($wC, 'UTF-8') >= 2) {
                if (is_numeric($wC)) {
                    if ((string) $wC === (string) $repeatMatch || (string) $wC === (string) $cylinderMatch || (string) $wC === (string) $targetMeters || (string) $wC === (string) $targetLabels || (string) $wC === (string) $plateNoMatch || (string) $wC === (string) $paperSizeMatch) {
                        continue;
                    }
                }
                // Also skip words that contain a matched numeric value (e.g. "1524mm" from "152.4mm", or "1524" from "152.4")
                $skip = false;
                foreach ([$repeatMatch, $cylinderMatch, $targetMeters, $targetLabels, $plateNoMatch, $paperSizeMatch] as $mv) {
                    if ($mv !== null && $mv !== '') {
                        if (mb_strpos($wC, (string) $mv) !== false) {
                            $skip = true;
                            break;
                        }
                        // Handle dot-stripped numbers: "1524" should match repeatMatch "152.4"
                        if (is_numeric($wC) && is_numeric($mv) && (float) $wC === (float) $mv) {
                            $skip = true;
                            break;
                        }
                        // Handle dot-stripped: "1524" should match "152.4" (dot removed by preg_replace)
                        $mvNoDot = str_replace('.', '', (string) $mv);
                        if (is_numeric($wC) && is_numeric($mvNoDot) && (float) $wC === (float) $mvNoDot) {
                            $skip = true;
                            break;
                        }
                        // Handle unit-suffixed numbers: "1524mm" should match repeatMatch "152.4"
                        $wNum = preg_replace('/[^\d.]/', '', $wC);
                        if ($wNum !== '' && is_numeric($wNum) && is_numeric($mv) && (float) $wNum === (float) $mv) {
                            $skip = true;
                            break;
                        }
                        if ($wNum !== '' && is_numeric($wNum) && is_numeric($mvNoDot) && (float) $wNum === (float) $mvNoDot) {
                            $skip = true;
                            break;
                        }
                    }
                }
                if ($skip)
                    continue;
                $pTerms[] = $wC;
            }
        }

        if (!$jobSearchExplicit) {
            $newPTerms = [];
            foreach ($pTerms as $term) {
                if (preg_match('/[a-zA-Z]\d|\d[a-zA-Z]/', $term)) {
                    $splitTerm = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $term);
                    $splitTerm = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $splitTerm);
                    $newPTerms = array_merge($newPTerms, explode(' ', $splitTerm));
                } else {
                    $newPTerms[] = $term;
                }
            }
            $pTerms = $newPTerms;
        }
        $jobSearchRaw = $jobSearchExplicit ?: implode(' ', $pTerms);
        $jobSearchTerm = trim($jobSearchRaw);

        // Shared plate renderer (details + math)
        $renderPlate = function ($row, $idx) use ($userLang, $targetMeters, $targetLabels) {
            $rep = (float) ($row['repeat_value'] ?? 0);
            $ups = (float) ($row['ups'] ?? 1);
            if ($ups <= 0)
                $ups = 1;
            $repFmt = $rep > 0 ? rtrim(rtrim(number_format($rep, 3, '.', ''), '0'), '.') : '—';

            $colors = [];
            foreach (['c', 'm', 'y', 'k', 'special_1', 'special_2', 'special_3', 'special_4', 'special_5'] as $c) {
                $cv = trim((string) ($row[$c] ?? ''));
                if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-')
                    $colors[] = $cv;
            }
            $colorCount = count($colors);
            $colorDisp = $colorCount > 0 ? implode(', ', $colors) : '—';

            $clean = function ($v) {
                $v = trim((string) $v);
                return ($v === '' || mb_strtolower($v, 'UTF-8') === 'na' || mb_strtolower($v, 'UTF-8') === 'n/a' || $v === '-') ? '—' : $v;
            };

            $gapH = trim((string) ($row['gap_h'] ?? ''));
            $gapV = trim((string) ($row['gap_v'] ?? ''));
            if ($gapH === '' || mb_strtolower($gapH, 'UTF-8') === 'na')
                $gapH = '—';
            elseif (!preg_match('/mm$/i', $gapH))
                $gapH .= 'mm';
            if ($gapV === '' || mb_strtolower($gapV, 'UTF-8') === 'na')
                $gapV = '—';
            elseif (!preg_match('/mm$/i', $gapV))
                $gapV .= 'mm';

            $out = "• **Plate " . $idx . ": `" . ($row['name'] ?: 'Job Name') . "`** (Plate: **" . $clean($row['plate']) . "** | SL No: **" . $clean($row['sl_no']) . "** | ID: **{$row['id']}**)\n"
                . "  - 📏 **Repeat:** **{$repFmt}mm** | **Ups:** **{$ups}** | ⚙️ **Cylinder:** **" . $clean($row['cylinder']) . "**\n"
                . "  - 📐 **Gaps:** **Gap H:** **{$gapH}** | **Gap V:** **{$gapV}**\n"
                . "  - 📄 **Paper Type:** **" . $clean($row['paper_type']) . "** | 🏭 **Make By:** **" . $clean($row['make_by']) . "**\n"
                . "  - 📏 **Size:** **" . $clean($row['size']) . "** | 📄 **Paper Size:** **" . $clean($row['paper_size']) . "** | ✂️ **Die:** **" . $clean($row['die']) . "**\n"
                . "  - 🎨 **Colors ({$colorCount}):** {$colorDisp}\n"
                . "  - 📅 **Date Received / Last Job:** **" . $clean($row['date_received']) . "**\n";

            if ($targetMeters > 0 && $rep > 0) {
                $calcLabels = floor(($targetMeters * 1000) / $rep * $ups);
                if ($userLang === 'Bengali')
                    $out .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " মিটার** পেপারে **" . number_format($calcLabels) . " টি লেবেল** প্রিন্ট হবে।\n";
                elseif ($userLang === 'Hindi')
                    $out .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " मीटर** पेपर में **" . number_format($calcLabels) . " लेबेल** प्रिंट होंगे।\n";
                else
                    $out .= "  - 🧮 **Calculation:** A **" . number_format($targetMeters) . "m** roll will yield **" . number_format($calcLabels) . " labels**.\n";
            }
            if ($targetLabels > 0 && $rep > 0) {
                $reqMeters = ceil(($targetLabels / $ups) * $rep / 1000);
                if ($userLang === 'Bengali')
                    $out .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " টি লেবেল** প্রিন্ট করতে **" . number_format($reqMeters) . " মিটার** পেপার লাগবে।\n";
                elseif ($userLang === 'Hindi')
                    $out .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " लेबेल** प्रिंट करने के लिए **" . number_format($reqMeters) . " मीटर** पेपर लगेगा।\n";
                else
                    $out .= "  - 🧮 **Calculation:** To print **" . number_format($targetLabels) . " labels**, you need **" . number_format($reqMeters) . " meters** of paper.\n";
            }
            if ($targetMeters == null && $targetLabels == null && $rep > 0) {
                $teeth = round($rep / 3.175, 2);
                $out .= "  - ⚙️ **Gearing (1/8 CP):** ~**{$teeth} Teeth** Cylinder\n";
            }
            $out .= "\n";
            return $out;
        };

        // CHECK MEMORY FOR MATH INTENT
        $isMathIntentOnly = ($targetMeters > 0 || $targetLabels > 0) && !$plateNoMatch && !$jobSearchTerm && !$cylinderMatch && !$repeatMatch && !$paperSizeMatch && !$dieMatch;

        if ($isMathIntentOnly && isset($_SESSION['ai_last_plate_id'])) {
            $plateNoMatch = (string) $_SESSION['ai_last_plate_id'];
        }

        $where = ["1=1"];
        $params = [];
        $typesStr = '';

        if ($plateNoMatch) {
            $where[] = "(plate = ? OR sl_no = ? OR id = ?)";
            $params[] = $plateNoMatch;
            $params[] = $plateNoMatch;
            $params[] = $plateNoMatch;
            $typesStr .= 'sss';
        }

        if ($cylinderMatch) {
            $where[] = "cylinder LIKE ?";
            $params[] = '%' . $cylinderMatch . '%';
            $typesStr .= 's';
        }

        if ($repeatMatch) {
            $where[] = "repeat_value LIKE ?";
            $params[] = '%' . $repeatMatch . '%';
            $typesStr .= 's';
        }

        if ($paperSizeMatch) {
            $where[] = "(paper_size LIKE ? OR size LIKE ?)";
            $params[] = '%' . $paperSizeMatch . '%';
            $params[] = '%' . $paperSizeMatch . '%';
            $typesStr .= 'ss';
        }

        if ($dieMatch) {
            $where[] = "die LIKE ?";
            $params[] = '%' . $dieMatch . '%';
            $typesStr .= 's';
        }

        // Add job search terms (LOOSE AND MATCHING across words if multiple words)
        $searchCols = "name LIKE ? OR plate LIKE ? OR sl_no LIKE ? OR size LIKE ? OR paper_size LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? OR cylinder LIKE ? OR core LIKE ? OR qty_roll LIKE ? OR rewinding LIKE ?";
        if ($jobSearchTerm && !$plateNoMatch && !$moreThanFourColors) {
            if ($jobSearchExplicit) {
                $expWords = array_filter(preg_split('/\s+|-/', $jobSearchExplicit));
                if (count($expWords) > 1) {
                    foreach ($expWords as $ew) {
                        $where[] = "(" . $searchCols . ")";
                        $l = '%' . $ew . '%';
                        for ($i = 0; $i < 12; $i++)
                            $params[] = $l;
                        $typesStr .= 'ssssssssssss';
                    }
                } else {
                    $where[] = "(" . $searchCols . ")";
                    $l = '%' . $jobSearchExplicit . '%';
                    for ($i = 0; $i < 12; $i++)
                        $params[] = $l;
                    $typesStr .= 'ssssssssssss';
                }
            } else {
                foreach ($pTerms as $term) {
                    $where[] = "(" . $searchCols . ")";
                    $l = '%' . $term . '%';
                    for ($i = 0; $i < 12; $i++)
                        $params[] = $l;
                    $typesStr .= 'ssssssssssss';
                }
            }
        }

        $whereSql = implode(' AND ', $where);
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($isCountIntent && !$isExportQuery && !$plateNoMatch && !$jobSearchTerm && !$cylinderMatch && !$repeatMatch && !$paperSizeMatch && !$dieMatch) {
            // Dashboard mode
            $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
            $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
            $dieBreak = $db->query("SELECT die, COUNT(*) as cnt FROM master_plate_data WHERE die IS NOT NULL AND die != '' AND LOWER(TRIM(die)) != 'na' GROUP BY die ORDER BY cnt DESC LIMIT 5");
            $dieStats = $dieBreak ? $dieBreak->fetch_all(MYSQLI_ASSOC) : [];
            $makerBreak = $db->query("SELECT make_by, COUNT(*) as cnt FROM master_plate_data WHERE make_by IS NOT NULL AND make_by != '' AND LOWER(TRIM(make_by)) != 'na' GROUP BY make_by ORDER BY cnt DESC LIMIT 5");
            $makerStats = $makerBreak ? $makerBreak->fetch_all(MYSQLI_ASSOC) : [];
            $paperBreak = $db->query("SELECT paper_type, COUNT(*) as cnt FROM master_plate_data WHERE paper_type IS NOT NULL AND paper_type != '' AND LOWER(TRIM(paper_type)) != 'na' GROUP BY paper_type ORDER BY cnt DESC LIMIT 5");
            $paperStats = $paperBreak ? $paperBreak->fetch_all(MYSQLI_ASSOC) : [];

            if ($userLang === 'Bengali') {
                $answer = "📊 **প্রিন্টিং প্লেট ড্যাশবোর্ড**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **মোট প্লেট:** **{$totalCount}টি**\n\n";
                if (!empty($dieStats)) {
                    $answer .= "📐 **ধরণ (Die Type):**\n";
                    foreach ($dieStats as $ds)
                        $answer .= " ▸ **" . ($ds['die'] ?: 'অন্যান্য') . "** — {$ds['cnt']}টি\n";
                }
                if (!empty($paperStats)) {
                    $answer .= "\n📄 **পেপার টাইপ:**\n";
                    foreach ($paperStats as $ps)
                        $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']}টি\n";
                }
                if (!empty($makerStats)) {
                    $answer .= "\n🏭 **নির্মাতা (Maker):**\n";
                    foreach ($makerStats as $ms)
                        $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']}টি\n";
                }
            } elseif ($userLang === 'Hindi') {
                $answer = "📊 **प्रिंटिंग प्लेट डैशबोर्ड**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **कुल प्लेट:** **{$totalCount}**\n\n";
                if (!empty($dieStats)) {
                    $answer .= "📐 **प्रकार (Die Type):**\n";
                    foreach ($dieStats as $ds)
                        $answer .= " ▸ **" . ($ds['die'] ?: 'अन्य') . "** — {$ds['cnt']}\n";
                }
                if (!empty($paperStats)) {
                    $answer .= "\n📄 **पेपर प्रकार:**\n";
                    foreach ($paperStats as $ps)
                        $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']}\n";
                }
                if (!empty($makerStats)) {
                    $answer .= "\n🏭 **निर्माता (Maker):**\n";
                    foreach ($makerStats as $ms)
                        $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']}\n";
                }
            } else {
                $answer = "📊 **Printing Plates Dashboard**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **Total Plates:** **{$totalCount}**\n\n";
                if (!empty($dieStats)) {
                    $answer .= "📐 **By Die Type:**\n";
                    foreach ($dieStats as $ds)
                        $answer .= " ▸ **" . ($ds['die'] ?: 'Other') . "** — {$ds['cnt']} plates\n";
                }
                if (!empty($paperStats)) {
                    $answer .= "\n📄 **By Paper Type:**\n";
                    foreach ($paperStats as $ps)
                        $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']} plates\n";
                }
                if (!empty($makerStats)) {
                    $answer .= "\n🏭 **By Maker:**\n";
                    foreach ($makerStats as $ms)
                        $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']} plates\n";
                }
            }
            $answer .= "\n👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        }

        // Latest / newest plate
        if ($latestPlateIntent && !$plateNoMatch && !$jobSearchExplicit && !$cylinderMatch && !$repeatMatch && !$paperSizeMatch && !$dieMatch) {
            $stmt = $db->prepare("SELECT * FROM master_plate_data ORDER BY id DESC LIMIT 1");
            $stmt->execute();
            $latestRow = $stmt->get_result()->fetch_assoc();
            if ($latestRow) {
                if ($userLang === 'Bengali') {
                    $answer = "🆕 **সর্বশেষ যোগ করা প্লেট:**\n\n";
                } elseif ($userLang === 'Hindi') {
                    $answer = "🆕 **सबसे नई प्लेट:**\n\n";
                } else {
                    $answer = "🆕 **Latest Added Plate:**\n\n";
                }
                $answer .= $renderPlate($latestRow, 1);
                $answer .= "👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
                return ['tool_used' => $toolName, 'total_count' => 1, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
            }
        }

        // Teeth / gear calculation from a repeat value directly (no DB match needed)
        if ($teethIntent && $repeatMatch && (float) $repeatMatch > 0 && !$plateNoMatch && !$cylinderMatch && !$paperSizeMatch && !$dieMatch && !$jobSearchTerm) {
            $repVal = (float) $repeatMatch;
            $teeth = round($repVal / 3.175, 2);
            if ($userLang === 'Bengali') {
                $answer = "⚙️ **গিয়ার / দাঁত ক্যালকুলেশন:**\n\n**{$repVal}mm** রিপিটের জন্য প্রায় **{$teeth} দাঁতের (Teeth)** সিলিন্ডার লাগবে (1/8 CP)।\n\n📐 সূত্র: রিপিট ÷ 3.175 = {$repVal} ÷ 3.175 = **{$teeth} Teeth**";
            } elseif ($userLang === 'Hindi') {
                $answer = "⚙️ **गियर / दांत गणना:**\n\n**{$repVal}mm** रिपीट के लिए लगभग **{$teeth} दांत (Teeth)** सिलेंडर चाहिए (1/8 CP)।\n\n📐 सूत्र: रिपीट ÷ 3.175 = {$repVal} ÷ 3.175 = **{$teeth} Teeth**";
            } else {
                $answer = "⚙️ **Gear / Teeth Calculation:**\n\nFor a **{$repVal}mm** repeat, you need approximately a **{$teeth}-teeth** cylinder (1/8 CP).\n\n📐 Formula: Repeat ÷ 3.175 = {$repVal} ÷ 3.175 = **{$teeth} Teeth**";
            }
            return ['tool_used' => 'Plate Gearing Calculator', 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        }

        if ($isExportQuery) {
            $fTitle = 'Plate Management Report';
            $exportQueryStr = 'mode=design';
            if ($plateNoMatch) {
                $fTitle = 'Plate: ' . strtoupper($plateNoMatch) . ' Report';
                $exportQueryStr .= '&search=' . urlencode($plateNoMatch);
            } elseif ($jobSearchTerm) {
                $fTitle = 'Job: ' . strtoupper(implode(' ', $pTerms)) . ' Report';
                $exportQueryStr .= '&search=' . urlencode($jobSearchTerm);
            } elseif ($cylinderMatch) {
                $fTitle = 'Cylinder: ' . $cylinderMatch . ' Report';
                $exportQueryStr .= '&search=' . urlencode($cylinderMatch);
            }

            $pdfUrl = "{$baseUrl}/modules/plate-data/export.php?{$exportQueryStr}&format=pdf";
            $csvUrl = "{$baseUrl}/modules/plate-data/export.php?{$exportQueryStr}&format=excel";

            $answer = "📊 **{$fTitle} — Export Ready:**\n\n";
            if ($userLang === 'Bengali') {
                $answer .= "আপনার প্লেট রিপোর্ট তৈরি হয়ে গেছে। নিচের রঙিন বাটনগুলোতে ক্লিক করে PDF বা Excel ফাইল ডাউনলোড করুন:\n\n";
            } elseif ($userLang === 'Hindi') {
                $answer .= "आपकी प्लेट रिपोर्ट तैयार है। PDF या Excel फाइल डाउनलोड करने के लिए नीचे दिए गए बटन पर क्लिक करें:\n\n";
            } else {
                $answer .= "Your plate report is ready. Click the buttons below to download the PDF or Excel file:\n\n";
            }

            $answer .= '<div style="display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap;">' . "\n";
            $answer .= '    <a href="' . $pdfUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.4); border: 1px solid #b91c1c; font-size: 14px; min-width: 200px;">' . "\n";
            $answer .= '        📄 Download PDF Report' . "\n";
            $answer .= '    </a>' . "\n";
            $answer .= '    <a href="' . $csvUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(34, 197, 94, 0.4); border: 1px solid #15803d; font-size: 14px; min-width: 200px;">' . "\n";
            $answer .= '        📊 Download Excel' . "\n";
            $answer .= '    </a>' . "\n";
            $answer .= '</div>';

            return ['tool_used' => 'Printing Plates Export Tool', 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        }

        $limitRows = $moreThanFourColors ? 60 : 5;
        $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE {$whereSql} ORDER BY id DESC LIMIT {$limitRows}");
        if (!empty($params))
            $stmt->bind_param($typesStr, ...$params);
        $stmt->execute();
        $plateData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // Filter: plates with more than 4 colors (exclude empty / NA)
        if ($moreThanFourColors && !empty($plateData)) {
            $filtered = [];
            foreach ($plateData as $row) {
                $cnt = 0;
                foreach (['c', 'm', 'y', 'k', 'special_1', 'special_2', 'special_3', 'special_4', 'special_5'] as $c) {
                    $cv = trim((string) ($row[$c] ?? ''));
                    if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-')
                        $cnt++;
                }
                if ($cnt > 4)
                    $filtered[] = $row;
            }
            $plateData = $filtered;
        }

        if (empty($plateData) && ($plateNoMatch || $cylinderMatch || $repeatMatch || $paperSizeMatch || $dieMatch)) {
            // Tailored "not found" based on what was actually searched
            $targetDesc = '';
            if ($plateNoMatch) {
                $targetDesc = $userLang === 'Bengali' ? "প্লেট নম্বর {$plateNoMatch}" : ($userLang === 'Hindi' ? "प्लेट नंबर {$plateNoMatch}" : "plate number {$plateNoMatch}");
            } elseif ($dieMatch) {
                $targetDesc = $userLang === 'Bengali' ? ucfirst($dieMatch) . " ধরনের ডাই" : ($userLang === 'Hindi' ? ucfirst($dieMatch) . " प्रकार की डाई" : ucfirst($dieMatch) . " die type");
            } elseif ($cylinderMatch) {
                $targetDesc = $userLang === 'Bengali' ? "{$cylinderMatch} সিলিন্ডার" : ($userLang === 'Hindi' ? "{$cylinderMatch} सिलेंडर" : "{$cylinderMatch} cylinder");
            } elseif ($paperSizeMatch) {
                $targetDesc = $userLang === 'Bengali' ? "{$paperSizeMatch}mm পেপার সাইজ" : ($userLang === 'Hindi' ? "{$paperSizeMatch}mm पेपर साइज़" : "{$paperSizeMatch}mm paper size");
            } elseif ($repeatMatch) {
                $targetDesc = $userLang === 'Bengali' ? "{$repeatMatch}mm রিপিট" : ($userLang === 'Hindi' ? "{$repeatMatch}mm रिपीट" : "{$repeatMatch}mm repeat");
            }
            if ($userLang === 'Bengali') {
                $answer = "❌ **{$targetDesc} এর জন্য কোনো প্লেট পাওয়া যায়নি।**\n\nঅন্য প্লেট নম্বর, সাইজ, সিলিন্ডার বা জবের নাম দিয়ে আবার চেষ্টা করুন।";
            } elseif ($userLang === 'Hindi') {
                $answer = "❌ **{$targetDesc} के लिए कोई प्लेट नहीं मिली।**\n\nकृपया दूसरा प्लेट नंबर, साइज़, सिलेंडर या जॉब का नाम आज़माएँ।";
            } else {
                $answer = "❌ **No plates found for {$targetDesc}.**\n\nTry a different plate number, size, cylinder, or job name.";
            }
            return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        } elseif (empty($plateData) && $moreThanFourColors) {
            if ($userLang === 'Bengali') {
                $answer = "❌ **৪টির বেশি কালারের কোনো প্লেট পাওয়া যায়নি।**";
            } elseif ($userLang === 'Hindi') {
                $answer = "❌ **4 से अधिक रंगों वाली कोई प्लेट नहीं मिली।**";
            } else {
                $answer = "❌ **No plates with more than 4 colors found.**";
            }
            return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        } elseif (empty($plateData) && $jobSearchTerm) {
            $displayJob = $jobSearchTerm;
            if ($userLang === 'Bengali') {
                $answer = "❌ **না স্যার, আমরা \"{$displayJob}\" নামে কোনো প্লেট বা জব রেকর্ড পাইনি।**\n\nসম্ভবত এই জবটি আগে কখনো প্রিন্ট হয়নি, বা এটি অন্য কোনো নামে সেভ করা আছে।";
            } elseif ($userLang === 'Hindi') {
                $answer = "❌ **नहीं सर, हमें \"{$displayJob}\" नाम की कोई प्लेट या जॉब नहीं मिली।**\n\nशायद यह जॉब पहले कभी प्रिंट नहीं हुई है, या किसी अन्य नाम से सेव है।";
            } else {
                $answer = "❌ **No sir, we haven't found any plate for \"{$displayJob}\".**\n\nIt seems this job hasn't been printed before, or it's saved under a different name.";
            }
            return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        } elseif (empty($plateData)) {
            if ($userLang === 'Bengali') {
                $answer = "❌ **কোনো প্লেট পাওয়া যায়নি।**\nদয়া করে সঠিক প্লেট নম্বর বা জবের নাম দিন।";
            } elseif ($userLang === 'Hindi') {
                $answer = "❌ **कोई प्लेट नहीं मिली।**\nकृपया सही प्लेट नंबर या जॉब का नाम दें।";
            } else {
                $answer = "❌ **No plates found.**\nPlease provide a valid plate number or job name.";
            }
            return ['tool_used' => $toolName, 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        }

        if (!empty($plateData)) {
            // Save context
            $_SESSION['ai_last_plate_id'] = $plateData[0]['id'];

            if ($jobSearchExplicit && count($plateData) > 1) {
                $normStr = function($s) {
                    return mb_strtolower(preg_replace('/[^\p{L}\p{N}]/u', '', (string)$s), 'UTF-8');
                };
                $targetNorm = $normStr($jobSearchExplicit);
                
                usort($plateData, function($a, $b) use ($normStr, $targetNorm) {
                    $normA = $normStr($a['name'] ?? '');
                    $normB = $normStr($b['name'] ?? '');
                    if ($normA === $targetNorm) return -1;
                    if ($normB === $targetNorm) return 1;
                    if ($targetNorm !== '' && strpos($normA, $targetNorm) !== false && strpos($normB, $targetNorm) === false) return -1;
                    if ($targetNorm !== '' && strpos($normB, $targetNorm) !== false && strpos($normA, $targetNorm) === false) return 1;
                    return 0;
                });
            }

            $isColorQuery = (preg_match('/\b(color|colors|colour|colours|কালার|কয়টি কালার|কত কালার|রং|कितने कलर)\b/i', $prompt) !== 0);
            $directAnswerHeader = "";
            if ($isColorQuery && !empty($plateData)) {
                $targetRow = $plateData[0];
                $targetName = trim($targetRow['name'] ?? 'Target Plate');
                $targetPlateNo = trim($targetRow['plate'] ?? '');
                $targetPlateStr = $targetPlateNo ? " (Plate: {$targetPlateNo})" : "";
                $tColors = [];
                foreach(['c','m','y','k','special_1','special_2','special_3','special_4','special_5'] as $cField) {
                    $cv = trim((string)($targetRow[$cField] ?? ''));
                    if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-') {
                        $tColors[] = $cv;
                    }
                }
                $tColorCount = count($tColors);
                $tColorStr = $tColorCount > 0 ? implode(', ', $tColors) : 'None';
                
                if ($userLang === 'Bengali') {
                    $directAnswerHeader = '<div style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%); border: 1.5px solid #3b82f6; border-left: 5px solid #2563eb; border-radius: 10px; padding: 12px 16px; margin: 10px 0 14px 0; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.08);">' . "\n"
                        . '  <div style="font-weight: 800; color: #1e40af; font-size: 0.95rem; margin-bottom: 6px;">🎯 <b>সরাসরি উত্তর - "' . htmlspecialchars($targetName) . '"</b>' . htmlspecialchars($targetPlateStr) . '</div>' . "\n"
                        . '  <div style="font-size: 0.9rem; color: #1e293b; line-height: 1.6;">' . "\n"
                        . '    ▸ <b>মোট কালার:</b> <span style="background: #2563eb; color: #ffffff; padding: 2px 8px; border-radius: 12px; font-size: 0.82rem; font-weight: 700;">' . $tColorCount . 'টি</span><br>' . "\n"
                        . '    ▸ <b>কালার লিস্ট:</b> <code style="background: #ffffff; color: #0f172a; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 600; font-family: monospace;">' . htmlspecialchars($tColorStr) . '</code>' . "\n"
                        . '  </div>' . "\n"
                        . '</div>' . "\n\n";
                } elseif ($userLang === 'Hindi') {
                    $directAnswerHeader = '<div style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%); border: 1.5px solid #3b82f6; border-left: 5px solid #2563eb; border-radius: 10px; padding: 12px 16px; margin: 10px 0 14px 0; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.08);">' . "\n"
                        . '  <div style="font-weight: 800; color: #1e40af; font-size: 0.95rem; margin-bottom: 6px;">🎯 <b>सीधा उत्तर - "' . htmlspecialchars($targetName) . '"</b>' . htmlspecialchars($targetPlateStr) . '</div>' . "\n"
                        . '  <div style="font-size: 0.9rem; color: #1e293b; line-height: 1.6;">' . "\n"
                        . '    ▸ <b>कुल कलर:</b> <span style="background: #2563eb; color: #ffffff; padding: 2px 8px; border-radius: 12px; font-size: 0.82rem; font-weight: 700;">' . $tColorCount . '</span><br>' . "\n"
                        . '    ▸ <b>कलर लिस्ट:</b> <code style="background: #ffffff; color: #0f172a; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 600; font-family: monospace;">' . htmlspecialchars($tColorStr) . '</code>' . "\n"
                        . '  </div>' . "\n"
                        . '</div>' . "\n\n";
                } else {
                    $directAnswerHeader = '<div style="background: linear-gradient(135deg, #eff6ff 0%, #e0f2fe 100%); border: 1.5px solid #3b82f6; border-left: 5px solid #2563eb; border-radius: 10px; padding: 12px 16px; margin: 10px 0 14px 0; box-shadow: 0 2px 8px rgba(37, 99, 235, 0.08);">' . "\n"
                        . '  <div style="font-weight: 800; color: #1e40af; font-size: 0.95rem; margin-bottom: 6px;">🎯 <b>Direct Answer for "' . htmlspecialchars($targetName) . '"</b>' . htmlspecialchars($targetPlateStr) . '</div>' . "\n"
                        . '  <div style="font-size: 0.9rem; color: #1e293b; line-height: 1.6;">' . "\n"
                        . '    ▸ <b>Total Colors Used:</b> <span style="background: #2563eb; color: #ffffff; padding: 2px 8px; border-radius: 12px; font-size: 0.82rem; font-weight: 700;">' . $tColorCount . ' Colors</span><br>' . "\n"
                        . '    ▸ <b>Color Breakdown:</b> <code style="background: #ffffff; color: #0f172a; padding: 3px 8px; border-radius: 6px; border: 1px solid #cbd5e1; font-weight: 600; font-family: monospace;">' . htmlspecialchars($tColorStr) . '</code>' . "\n"
                        . '  </div>' . "\n"
                        . '</div>' . "\n\n";
                }
            }

            $sampleCount = count($plateData);

            if ($userLang === 'Bengali') {
                $answer = "📐 **Plate Management & Math Engine:**\n\n" . $directAnswerHeader . "আপনার ডাটাবেসে **{$sampleCount}টি ম্যাচিং প্লেট** পাওয়া গেছে:\n\n";
            } elseif ($userLang === 'Hindi') {
                $answer = "📐 **Plate Management & Math Engine:**\n\n" . $directAnswerHeader . "आपके डेटाबेस में **{$sampleCount} मैचिंग प्लेट** मिले हैं:\n\n";
            } else {
                $answer = "📐 **Plate Management & Math Engine:**\n\n" . $directAnswerHeader . "Found **{$sampleCount} matching plate(s)** in your database:\n\n";
            }

            foreach ($plateData as $idx => $row) {
                $answer .= $renderPlate($row, $idx + 1);
            }

            $answer .= "👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";

            return ['tool_used' => $toolName, 'total_count' => count($plateData), 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        }
    } elseif (strpos($p, 'die') !== false || strpos($p, 'tooling') !== false || strpos($p, 'barcode') !== false) {
        $toolName = 'Die Tooling & Barcode Die Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_die_tooling");
        $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

        // --- Count-Only Intent Handler ---
        if (is_count_intent($prompt)) {

            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            // Fetch breakdown stats
            $typeBreak = $db->query("SELECT die_type, COUNT(*) as cnt FROM master_die_tooling WHERE die_type IS NOT NULL AND die_type != '' GROUP BY die_type ORDER BY cnt DESC");
            $typeStats = $typeBreak ? $typeBreak->fetch_all(MYSQLI_ASSOC) : [];
            $catBreak = $db->query("SELECT used_for, COUNT(*) as cnt FROM master_die_tooling WHERE used_for IS NOT NULL AND used_for != '' GROUP BY used_for ORDER BY cnt DESC LIMIT 5");
            $catStats = $catBreak ? $catBreak->fetch_all(MYSQLI_ASSOC) : [];
            $latestRes = $db->query("SELECT barcode_size, sl_no, used_for FROM master_die_tooling ORDER BY id DESC LIMIT 1");
            $latest = $latestRes ? $latestRes->fetch_assoc() : null;

            if ($userLang === 'Hindi') {
                $directAnswer = "📏 **बारकोड डाई टूलिंग — स्टॉक डैशबोर्ड**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **कुल डाई:** **{$totalCount}**\n\n";
                if (!empty($typeStats)) {
                    $directAnswer .= "⚙️ **डाई प्रकार:**\n";
                    foreach ($typeStats as $ts) {
                        $directAnswer .= "   ▸ **" . ($ts['die_type'] ?: 'अन्य') . "** — {$ts['cnt']} डाई\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($catStats)) {
                    $directAnswer .= "📦 **श्रेणी (Category):**\n";
                    foreach ($catStats as $cs) {
                        $directAnswer .= "   ▸ **{$cs['used_for']}** — {$cs['cnt']} डाई\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "🆕 **सबसे हालिया डाई:** `{$latest['barcode_size']}` (SL: {$latest['sl_no']}) — श्रेणी: {$latest['used_for']}\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [बारकोड डाई प्रबंधन पेज खोलें]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } elseif ($userLang === 'Bengali' || preg_match('/[\x{0980}-\x{09FF}]/u', $prompt) || preg_match('/\b(koto|kotogulo|ache)\b/i', $prompt)) {
                $directAnswer = "📏 **বারকোড ডাই টূলিং — স্টক ড্যাশবোর্ড**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **মোট ডাই:** **{$totalCount}টি**\n\n";
                if (!empty($typeStats)) {
                    $directAnswer .= "⚙️ **ডাই ধরণ (Die Type):**\n";
                    foreach ($typeStats as $ts) {
                        $directAnswer .= "   ▸ **" . ($ts['die_type'] ?: 'অন্যান্য') . "** — {$ts['cnt']}টি ডাই\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($catStats)) {
                    $directAnswer .= "📦 **ক্যাটাগরি (Category):**\n";
                    foreach ($catStats as $cs) {
                        $directAnswer .= "   ▸ **{$cs['used_for']}** — {$cs['cnt']}টি ডাই\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "🆕 **সর্বশেষ ডাই:** `{$latest['barcode_size']}` (SL: {$latest['sl_no']}) — শ্রেণী: {$latest['used_for']}\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [বারকোড ডাই ম্যানেজমেন্ট পেজ খুলুন]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } else {
                $directAnswer = "📏 **Barcode Die Tooling — Stock Dashboard**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **Total Dies:** **{$totalCount}**\n\n";
                if (!empty($typeStats)) {
                    $directAnswer .= "⚙️ **By Die Type:**\n";
                    foreach ($typeStats as $ts) {
                        $directAnswer .= "   ▸ **" . ($ts['die_type'] ?: 'Other') . "** — {$ts['cnt']} dies\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($catStats)) {
                    $directAnswer .= "📦 **By Category:**\n";
                    foreach ($catStats as $cs) {
                        $directAnswer .= "   ▸ **{$cs['used_for']}** — {$cs['cnt']} dies\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "🆕 **Latest Die Added:** `{$latest['barcode_size']}` (SL: {$latest['sl_no']}) — Category: {$latest['used_for']}\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [Open Barcode Die Management Page]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            }
            return [
                'tool_used' => $toolName,
                'total_count' => $totalCount,
                'total_meters' => 0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => $directAnswer,
                'data' => []
            ];
        }

        $stopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'die', 'tooling', 'barcode', 'list', 'show', 'details', 'this', 'the', 'a', 'an', 'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does', 'kholo', 'khul', 'open', 'page', 'go', 'to'];

        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9]/', '', $w));
            if ($wClean !== '' && !in_array($wClean, $stopwords, true) && strlen($wClean) >= 2) {
                $terms[] = $wClean;
            }
        }
        $searchTerm = implode('%', $terms);
        $searchTermDisplay = implode(' ', $terms);

        if (!empty($terms)) {
            $like = '%' . $searchTerm . '%';
            $stmt = $db->prepare("SELECT * FROM master_die_tooling WHERE barcode_size LIKE ? OR used_for LIKE ? OR die_type LIKE ? OR sl_no = ? OR id = ? ORDER BY id DESC LIMIT 10");
            $stmt->bind_param('sssss', $like, $like, $like, $searchTerm, $searchTerm);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        if (empty($data) && !empty($searchNums)) {
            $num = $searchNums[0];
            $stmt = $db->prepare("SELECT * FROM master_die_tooling WHERE barcode_size LIKE ? OR sl_no = ? OR id = ? ORDER BY id DESC LIMIT 5");
            $like = '%' . $num . '%';
            $stmt->bind_param('sss', $like, $num, $num);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        if (empty($data) && !empty($terms)) {

            if ($userLang === 'English') {
                $directAnswer = "❌ **No Barcode Die Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Die Tooling database, but no record matching **\"{$searchTermDisplay}\"** was found.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" नाम का कोई बारकोड डाई रिकॉर्ड नहीं मिला**\n\n"
                    . "आपके ईआरपी डेटाबेस में **\"{$searchTermDisplay}\"** का कोई रिकॉर्ड उपलब्ध नहीं है।";
            } else {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" নামে কোনো বারকোড ডাই টূলিং পাওয়া যায়নি**\n\n"
                    . "আপনার ইআরপি মাস্টার ডাটাবেসে **\"{$searchTermDisplay}\"** নামে কোনো ডাই রেকর্ড নিবন্ধিত নেই।";
            }

            return [
                'tool_used' => $toolName,
                'total_count' => 0,
                'total_meters' => 0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => $directAnswer,
                'data' => []
            ];
        }

        if (empty($data)) {
            $res = $db->query("SELECT * FROM master_die_tooling ORDER BY id DESC LIMIT 15");
            $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }

        if (!empty($data)) {

            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            if ($userLang === 'English') {
                $answer = "📏 **Barcode Die Management & Tooling Master — Specifications:**\n\nFound **" . count($data) . " matching die record(s)** in your ERP database:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **Die " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 📐 **Barcode Size:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - 🔢 **Ups (Roll / Die):** Roll Ups: **" . ($row['ups_in_roll'] ?: '1') . "** | Die Ups: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - 📏 **Repeat Size:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **Label Gap:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - ⚙️ **Die Type & Cylinder:** **" . ($row['die_type'] ?: 'Rotary') . "** | Cylinder: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - 📄 **Paper Size & Core:** Paper Size: **" . ($row['paper_size'] ?: 'N/A') . "** | Core: **" . ($row['core'] ?: 'N/A') . "**\n"
                        . "  - 📦 **Pieces per Roll:** **" . ($row['pices_per_roll'] ?: 'N/A') . "** | Category: **" . ($row['used_for'] ?: 'Barcode') . "**\n\n";
                }
                $answer .= "👉 [Click here to open Barcode Die Management Page]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } elseif ($userLang === 'Hindi') {
                $answer = "📏 **बारकोड डाई प्रबंधन और टूलिंग मास्टर विवरण:**\n\nआपके ईआरपी डेटाबेस में **" . count($data) . " मैचिंग डाई रिकॉर्ड** मिले हैं:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **डाई " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}**)\n"
                        . "  - 📐 **बारकोड आकार:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - 🔢 **अप्स (Roll / Die):** रोल अप्स: **" . ($row['ups_in_roll'] ?: '1') . "** | डाई अप्स: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - 📏 **रिपीट साइज:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **लेबल गैप:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - ⚙️ **डाई प्रकार ओ सिलेंडर:** **" . ($row['die_type'] ?: 'Rotary') . "** | सिलेंडर: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - 📄 **पेपर साइज ओ कोर:** पेपर साइज: **" . ($row['paper_size'] ?: 'N/A') . "** | कोर: **" . ($row['core'] ?: 'N/A') . "**\n"
                        . "  - 📦 **पिस प्रति रोल:** **" . ($row['pices_per_roll'] ?: 'N/A') . "** | क्याटागरी: **" . ($row['used_for'] ?: 'Barcode') . "**\n\n";
                }
                $answer .= "👉 [बारकोड डाई प्रबंधन पेज खोलें]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } else {
                $answer = "📏 **বারকোড ডাই ম্যানেজমেন্ট ও টূলিং মাস্টার স্পেসিফিকেশন:**\n\nআপনার ইআরপি ডাটাবেসে **" . count($data) . "টি ম্যাচিং ডাই রেকর্ড** পাওয়া গেছে:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **ডাই " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 📐 **বারকোড আকার:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - 🔢 **আফস (Roll / Die):** রোল আফস: **" . ($row['ups_in_roll'] ?: '1') . "** | ডাই আফস: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - 📏 **রিপিট সাইজ:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **লেবেল গ্যাপ:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - ⚙️ **ডাই টাইপ ও সিলিন্ডার:** **" . ($row['die_type'] ?: 'Rotary') . "** | সিলিন্ডার: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - 📄 **পেপার সাইজ ও কোর:** পেপার সাইজ: **" . ($row['paper_size'] ?: 'N/A') . "** | কোর: **" . ($row['core'] ?: 'N/A') . "**\n"
                        . "  - 📦 **পিস প্রতি রোল:** **" . ($row['pices_per_roll'] ?: 'N/A') . "** | ক্যাটাগরি: **" . ($row['used_for'] ?: 'Barcode') . "**\n\n";
                }
                $answer .= "👉 [বারকোড ডাই ম্যানেজমেন্ট পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            }

            $navUrl = null;
            if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
                $navUrl = $baseUrl . '/modules/plate-tools/die-management/barcode/index.php';
            }

            return [
                'tool_used' => $toolName,
                'total_count' => count($data),
                'total_meters' => 0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => $answer,
                'nav_url' => $navUrl,
                'data' => []
            ];
        }

    } elseif (strpos($p, 'anilox') !== false || strpos($p, 'lpi') !== false || strpos($p, 'bcm') !== false || strpos($p, 'bmc') !== false || strpos($p, 'এনিলক্স') !== false) {
        $toolName = 'Anilox Roll Stock Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_anilox_data");
        $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

        // --- Count-Only Intent Handler ---
        if (is_count_intent($prompt)) {

            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            // Fetch breakdown stats
            $totalStockRes = $db->query("SELECT SUM(CAST(stock_qty AS UNSIGNED)) as total_stock FROM master_anilox_data WHERE stock_qty IS NOT NULL");
            $totalStock = $totalStockRes ? (int) ($totalStockRes->fetch_assoc()['total_stock'] ?? 0) : 0;
            $lpiRange = $db->query("SELECT MIN(CAST(anilox_lpi AS UNSIGNED)) as min_lpi, MAX(CAST(anilox_lpi AS UNSIGNED)) as max_lpi FROM master_anilox_data WHERE anilox_lpi IS NOT NULL AND anilox_lpi != ''");
            $lpiR = $lpiRange ? $lpiRange->fetch_assoc() : null;
            $inStockRes = $db->query("SELECT COUNT(*) as cnt FROM master_anilox_data WHERE CAST(stock_qty AS UNSIGNED) > 0");
            $inStockCount = $inStockRes ? (int) ($inStockRes->fetch_assoc()['cnt'] ?? 0) : 0;
            $outOfStock = $totalCount - $inStockCount;
            $latestRes = $db->query("SELECT anilox_lpi, anilox_bmc, sl_no, stock_qty FROM master_anilox_data ORDER BY id DESC LIMIT 1");
            $latest = $latestRes ? $latestRes->fetch_assoc() : null;

            $lpiRangeStr = ($lpiR && $lpiR['min_lpi']) ? "{$lpiR['min_lpi']} — {$lpiR['max_lpi']} LPI" : 'N/A';

            if ($userLang === 'Hindi') {
                $directAnswer = "🌀 **एनिलॉक्स रोल — स्टॉक डैशबोर्ड**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **कुल एनिलॉक्स प्रकार:** **{$totalCount}**\n"
                    . "📦 **कुल उपलब्ध स्टॉक:** **{$totalStock} रोल**\n\n"
                    . "📐 **LPI रेंज:** {$lpiRangeStr}\n"
                    . "✅ **स्टॉक में:** {$inStockCount} प्रकार | ❌ **स्टॉक से बाहर:** {$outOfStock} प्रकार\n\n";
                if ($latest) {
                    $directAnswer .= "🆕 **नवीनतम:** `{$latest['anilox_lpi']} LPI` / `{$latest['anilox_bmc']} BCM` (SL: {$latest['sl_no']}) — स्टॉक: {$latest['stock_qty']} रोल\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [एनिलॉक्स प्रबंधन पेज खोलें]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } elseif ($userLang === 'Bengali' || preg_match('/[\x{0980}-\x{09FF}]/u', $prompt) || preg_match('/\b(koto|kotogulo|ache)\b/i', $prompt)) {
                $directAnswer = "🌀 **এনিলক্স রোল — স্টক ড্যাশবোর্ড**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **মোট এনিলক্স ধরণ:** **{$totalCount}টি**\n"
                    . "📦 **মোট উপলব্ধ স্টক:** **{$totalStock}টি রোল**\n\n"
                    . "📐 **LPI রেঞ্জ:** {$lpiRangeStr}\n"
                    . "✅ **স্টকে আছে:** {$inStockCount}টি ধরণ | ❌ **স্টকে নেই:** {$outOfStock}টি ধরণ\n\n";
                if ($latest) {
                    $directAnswer .= "🆕 **সর্বশেষ:** `{$latest['anilox_lpi']} LPI` / `{$latest['anilox_bmc']} BCM` (SL: {$latest['sl_no']}) — স্টক: {$latest['stock_qty']}টি রোল\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [এনিলক্স ম্যানেজমেন্ট পেজ খুলুন]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } else {
                $directAnswer = "🌀 **Anilox Roll — Stock Dashboard**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **Total Anilox Types:** **{$totalCount}**\n"
                    . "📦 **Total Available Stock:** **{$totalStock} Rolls**\n\n"
                    . "📐 **LPI Range:** {$lpiRangeStr}\n"
                    . "✅ **In Stock:** {$inStockCount} types | ❌ **Out of Stock:** {$outOfStock} types\n\n";
                if ($latest) {
                    $directAnswer .= "🆕 **Latest Entry:** `{$latest['anilox_lpi']} LPI` / `{$latest['anilox_bmc']} BCM` (SL: {$latest['sl_no']}) — Stock: {$latest['stock_qty']} Rolls\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [Open Anilox Management Page]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            }
            return [
                'tool_used' => $toolName,
                'total_count' => $totalCount,
                'total_meters' => 0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => $directAnswer,
                'data' => []
            ];
        }

        $stopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'anilox', 'roll', 'rolls', 'stock', 'lpi', 'bcm', 'bmc', 'management', 'master', 'design', 'list', 'show', 'details', 'this', 'the', 'a', 'an', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does', 'kholo', 'khul', 'open', 'page', 'go', 'to'];

        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9]/', '', $w));
            if ($wClean !== '' && !in_array($wClean, $stopwords, true) && strlen($wClean) >= 2) {
                $terms[] = $wClean;
            }
        }
        $searchTerm = implode('%', $terms);
        $searchTermDisplay = implode(' ', $terms);

        if (!empty($terms)) {
            $like = '%' . $searchTerm . '%';
            $stmt = $db->prepare("SELECT * FROM master_anilox_data WHERE anilox_lpi LIKE ? OR anilox_bmc LIKE ? OR sl_no = ? OR id = ? ORDER BY CAST(anilox_lpi AS UNSIGNED) ASC LIMIT 15");
            $stmt->bind_param('ssss', $like, $like, $searchTerm, $searchTerm);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        if (empty($data) && !empty($searchNums)) {
            $num = $searchNums[0];
            $stmt = $db->prepare("SELECT * FROM master_anilox_data WHERE anilox_lpi LIKE ? OR anilox_bmc LIKE ? OR sl_no = ? OR id = ? ORDER BY CAST(anilox_lpi AS UNSIGNED) ASC LIMIT 10");
            $like = '%' . $num . '%';
            $stmt->bind_param('ssss', $like, $like, $num, $num);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        if (empty($data) && !empty($terms)) {

            if ($userLang === 'English') {
                $directAnswer = "❌ **No Anilox Roll Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Anilox Stock database, but no record matching **\"{$searchTermDisplay}\"** was found.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" नाम का कोई एनिलॉक्स रोल नहीं मिला**\n\n"
                    . "आपके ईआरपी डेटाबेस में **\"{$searchTermDisplay}\"** का कोई रिकॉर्ड उपलब्ध नहीं है।";
            } else {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" নামে কোনো এনিলক্স রোল পাওয়া যায়নি**\n\n"
                    . "আপনার ইআরপি মাস্টার ডাটাবেসে **\"{$searchTermDisplay}\"** নামে কোনো এনিলক্স রোল রেকর্ড নিবন্ধিত নেই।";
            }

            return [
                'tool_used' => $toolName,
                'total_count' => 0,
                'total_meters' => 0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => $directAnswer,
                'data' => []
            ];
        }

        if (empty($data)) {
            $res = $db->query("SELECT * FROM master_anilox_data ORDER BY CAST(anilox_lpi AS UNSIGNED) ASC LIMIT 20");
            $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }

        if (!empty($data)) {

            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            if ($userLang === 'English') {
                $answer = "🌀 **Anilox Management & Inventory Stock — Live Specifications:**\n\nFound **" . count($data) . " matching Anilox Roll record(s)** in your ERP database:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **Anilox " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 🌀 **Anilox LPI (Lines Per Inch):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - 🧪 **Anilox Volume (BCM / BMC):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - 📦 **Available Stock Quantity:** **" . ($row['stock_qty'] ?: '0') . " Roll(s)**\n\n";
                }
                $answer .= "👉 [Click here to open Anilox Management Page]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } elseif ($userLang === 'Hindi') {
                $answer = "🌀 **एनिलॉक्स रोल — स्टॉक डैशबोर्ड**\n\nआपके ईआरपी डेटाबेस में **" . count($data) . " मैचिंग एनिलॉक्स रोल रिकॉर्ड** मिले हैं:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **एनिलॉक्स " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}**)\n"
                        . "  - 🌀 **एनिलॉक्स एलपीआई (LPI):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - 🧪 **एनिलॉक्स वॉल्यूम (BCM / BMC):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - 📦 **उपलब्ध स्टॉक मात्रा:** **" . ($row['stock_qty'] ?: '0') . " रोल**\n\n";
                }
                $answer .= "👉 [एनिलॉक्स प्रबंधन पेज खोलें]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } else {
                $answer = "🌀 **এনিলক্স রোল — স্টক ড্যাশবোর্ড**\n\nআপনার ইআরপি ডাটাবেসে **" . count($data) . "টি ম্যাচিং এনিলক্স রোল রেকর্ড** পাওয়া গেছে:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **এনিলক্স " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 🌀 **এনিলক্স এলপিআই (LPI):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - 🧪 **এনিলক্স ভলিউম (BCM / BMC):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - 📦 **উপলব্ধ স্টক মাত্রা:** **" . ($row['stock_qty'] ?: '0') . " রোল**\n\n";
                }
                $answer .= "👉 [এনিলক্স ম্যানেজমেন্ট পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            }

            $navUrl = null;
            if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
                $navUrl = $baseUrl . '/modules/plate-tools/anilox-management/index.php';
            }

            return [
                'tool_used' => $toolName,
                'total_count' => count($data),
                'total_meters' => 0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => $answer,
                'nav_url' => $navUrl,
                'data' => []
            ];
        }

    } elseif ($commandType === 'paperstock' || strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'slc/') !== false || strpos($p, 'chromo') !== false || strpos($p, 'thermal') !== false || strpos($p, 'stock') !== false || strpos($p, 'maplitho') !== false || strpos($p, 'pp') !== false || strpos($p, 'white') !== false || strpos($p, 'jumbo') !== false || strpos($p, 'slitting') !== false || strpos($p, 'avery') !== false || strpos($p, 'krishna') !== false || strpos($p, 'austin') !== false || strpos($p, 'navkar') !== false || strpos($p, 'nrgi') !== false || strpos($p, 'lot') !== false || strpos($p, 'batch') !== false || strpos($p, 'লট') !== false || strpos($p, 'ব্যাচ') !== false || strpos($p, 'rate') !== false || strpos($p, 'price') !== false || strpos($p, 'দাম') !== false || strpos($p, 'দামি') !== false || strpos($p, 'costly') !== false || strpos($p, 'expensive') !== false || strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || strpos($p, 'print') !== false || strpos($p, 'প্রিন্ট') !== false || strpos($p, 'এক্সপোর্ট') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'download') !== false || strpos($p, 'ডাউনলোড') !== false) {

        $toolName = 'Paper Stock Master Tool';

        // 1. Roll No match
        $rollNoMatch = null;
        if (preg_match('/(slc\/\d{4}\/\d+|\d{4})/i', $prompt, $m)) {
            $rollNoMatch = $m[1];
        }

        // 2. Company match
        $companies = ['nrgi', 'krishna', 'austin', 'navkar', 'abhinav', 'raj paper', 'mangalam', 'paper n more', 'narsing das', 'avery', 'nitin', 'flexo'];
        $matchedCompany = null;
        foreach ($companies as $comp) {
            if (strpos($p, $comp) !== false) {
                $matchedCompany = $comp;
                break;
            }
        }

        // 3. Substrate / Paper Type match
        $types = ['pp white', 'pp-white', 'pp clear', 'pp-clear', 'chromo', 'thermal paper', 'thermal board', 'maplitho', 'metallic', 'plastic'];

        $matchedType = null;
        foreach ($types as $t) {
            if (strpos($p, $t) !== false) {
                $matchedType = $t;
                break;
            }
        }

        // 4. Width match — exact, below (<) or above (>) with size threshold.
        $widthMm = null;
        $widthOp = '='; // '=', '<' (below), '>' (above)
        if (preg_match('/(below|under|less\s*than|lesser|ar\s*niche|er\s*niche|niche|ar\s*kom|er\s*kom|ke\s*kom|এর\s*নিচে|নিচে|চেয়ে\s*কম|কম|chhoto|ছোট|<)\s*(?:size\s*(?:of)?\s*)?(\d{3,4})\s*(?:mm|width|w|চওড়া)?/i', $prompt, $m)) {
            $widthMm = (float) $m[2];
            $widthOp = '<';
        } elseif (preg_match('/(above|more\s*than|over|ar\s*upore|er\s*upore|upore|ar\s*beshi|er\s*beshi|beshi|এর\s*বেশি|বেশি|উপরে|চেয়ে\s*বেশি|boro|বড়|>)\s*(?:size\s*(?:of)?\s*)?(\d{3,4})\s*(?:mm|width|w|চওড়া)?/i', $prompt, $m)) {
            $widthMm = (float) $m[2];
            $widthOp = '>';
        } elseif (preg_match('/(\d{3,4})\s*(?:mm|width|w|চওড়া)\s*(below|under|less\s*than|lesser|ar\s*niche|er\s*niche|niche|ar\s*kom|er\s*kom|ke\s*kom|এর\s*নিচে|নিচে|চেয়ে\s*কম|কম|<|chhoto|ছোট)/i', $prompt, $m)) {
            $widthMm = (float) $m[1];
            $widthOp = '<';
        } elseif (preg_match('/(\d{3,4})\s*(?:mm|width|w|চওড়া)\s*(above|more\s*than|over|ar\s*upore|er\s*upore|upore|ar\s*beshi|er\s*beshi|beshi|এর\s*বেশি|বেশি|উপরে|>|boro|বড়)/i', $prompt, $m)) {
            $widthMm = (float) $m[1];
            $widthOp = '>';
        } elseif (preg_match('/(\d{3,4})\s*(?:mm|width|w|চওড়া)\b/i', $prompt, $m)) {
            $widthMm = (float) $m[1];
            $widthOp = '=';
        }

        // 4.5 GSM Match
        $gsmMatch = null;
        if (preg_match('/(\d{2,3})\s*(?:gsm|জিএসএম)/i', $prompt, $m)) {
            $gsmMatch = (float) $m[1];
        }

        $where = ["LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')"];
        $params = [];
        $typesStr = '';

        if ($rollNoMatch) {
            $where[] = "(roll_no LIKE ? OR id = ?)";
            $params[] = '%' . $rollNoMatch . '%';
            $params[] = $rollNoMatch;
            $typesStr .= 'ss';
        }

        if ($matchedCompany) {
            $where[] = "company LIKE ?";
            $params[] = '%' . $matchedCompany . '%';
            $typesStr .= 's';
        }

        if ($matchedType) {
            if ($matchedType === 'pp white' || $matchedType === 'pp-white') {
                $where[] = "paper_type = 'PP-WHITE'";
            } elseif ($matchedType === 'pp clear' || $matchedType === 'pp-clear') {
                $where[] = "paper_type = 'PP-CLEAR'";
            } elseif ($matchedType === 'thermal paper') {
                // "thermal paper" is used loosely for ALL thermal substrates
                // (THERMAL PAPER + THERMAL BOARD + THERMAL LABEL + TOP COATED),
                // consistent with the aggregate Analytics Dashboard count.
                $where[] = "paper_type LIKE '%thermal%'";
            } else {
                $where[] = "paper_type LIKE ?";
                $params[] = '%' . $matchedType . '%';
                $typesStr .= 's';
            }
        }


        if ($widthMm) {
            $where[] = "width_mm {$widthOp} ?";
            $params[] = $widthMm;
            $typesStr .= 'd';
        }

        // 5. Lot/Batch No match (e.g. "LB-2025-001", "lot 123", "batch ABC")
        $lotBatchMatch = null;
        if (preg_match('/(?:lot\s*(?:batch)?\s*(?:no\.?)?\s*|batch\s*(?:no\.?)?\s*|লট\s*(?:ব্যাচ)?\s*(?:নম্বর|নং)?\s*)([A-Za-z0-9\-\/]+)/iu', $prompt, $m)) {
            $lotBatchMatch = trim($m[1]);
        } elseif (preg_match('/\b(LB-[A-Za-z0-9\-]+)\b/i', $prompt, $m)) {
            $lotBatchMatch = trim($m[1]);
        }
        if ($lotBatchMatch) {
            $where[] = "lot_batch_no LIKE ?";
            $params[] = '%' . $lotBatchMatch . '%';
            $typesStr .= 's';
        }

        // 6. Company Roll No match (e.g. "CR-12345", "company roll no CR-12345")
        $companyRollMatch = null;
        if (preg_match('/(?:company\s*roll\s*(?:no\.?)?\s*|কোম্পানি\s*রোল\s*(?:নম্বর|নং)?\s*)([A-Za-z0-9\-\/]+)/iu', $prompt, $m)) {
            $companyRollMatch = trim($m[1]);
        } elseif (preg_match('/\b(CR-[A-Za-z0-9\-]+)\b/i', $prompt, $m)) {
            $companyRollMatch = trim($m[1]);
        }

        // 7. SQM Area Filter match
        $sqmFilter = null;
        $sqmOp = null;
        if (
            preg_match('/(?:sqr?\s*mtr|sqm|square\s*meter)\s*(\d+(?:\.\d+)?)\s*(ar niche|er niche|niche|ar kom|er kom|kom|below|under|less|<|ar upore|er upore|upore|ar beshi|er beshi|beshi|above|more|>)/i', $prompt, $m) ||
            preg_match('/(\d+(?:\.\d+)?)\s*(?:sqr?\s*mtr|sqm|square\s*meter)\s*(ar niche|er niche|niche|ar kom|er kom|kom|below|under|less|<|ar upore|er upore|upore|ar beshi|er beshi|beshi|above|more|>)/i', $prompt, $m)
        ) {
            $val = (float) $m[1];
            $opStr = strtolower(trim($m[2]));
            $sqmOp = (preg_match('/niche|kom|below|under|less|</i', $opStr)) ? '<=' : '>=';
            $sqmFilter = $val;
        }
        if ($companyRollMatch) {
            $where[] = "company_roll_no LIKE ?";
            $params[] = '%' . $companyRollMatch . '%';
            $typesStr .= 's';
        }

        if ($sqmFilter !== null) {
            $where[] = "ROUND((width_mm/1000.0)*length_mtr, 2) {$sqmOp} ?";
            $params[] = $sqmFilter;
            $typesStr .= 'd';
        }

        if ($gsmMatch !== null) {
            $where[] = "gsm = ?";
            $params[] = $gsmMatch;
            $typesStr .= 'd';
        }

        $whereSql = implode(' AND ', $where);

        // ─── Analysis Sub-Handler ───
        $isAnalysisQuery = (strpos($p, 'analysis') !== false || strpos($p, 'details') !== false || strpos($p, 'inventory') !== false || strpos($p, 'summary') !== false || strpos($p, 'report') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'stock') !== false || strpos($p, 'স্টক') !== false);
        if ($isAnalysisQuery) {
            $analyzerFilters = [
                'company' => $matchedCompany,
                'paper_type' => $matchedType,
                'width' => $widthMm,
                'gsm' => $gsmMatch
            ];
            $intent = (strpos($p, 'analysis') !== false || strpos($p, 'inventory') !== false || strpos($p, 'details') !== false || strpos($p, 'report') !== false || strpos($p, 'রিপোর্ট') !== false || strpos($p, 'stock') !== false || strpos($p, 'স্টক') !== false) ? 'paper_stock_analysis' : 'paper_stock_summary';
            $ret = PaperStockAnalyzer::generateReport($db, $analyzerFilters, $intent, $userLang);
            if (!empty($ret['direct_answer'])) {
                $ret['tool_used'] = 'Paper Stock Master Tool';
                return $ret;
            }
        }

        // ─── Export Sub-Handler ───
        $isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'download') !== false || strpos($p, 'ডাউনলোড') !== false || ((preg_match('/\bprint\b/i', $p) || strpos($p, 'প্রিন্ট') !== false) && !strpos($p, 'koto') && !strpos($p, 'কত') && !strpos($p, 'required') && !strpos($p, 'need') && !strpos($p, 'how many')));
        if ($isExportQuery) {
            $fTitle = 'Paper Stock Report';
            if ($lotBatchMatch)
                $fTitle = 'Lot/Batch: ' . strtoupper($lotBatchMatch) . ' Report';
            elseif ($companyRollMatch)
                $fTitle = 'Company Roll: ' . strtoupper($companyRollMatch) . ' Report';
            elseif ($matchedCompany && $matchedType)
                $fTitle = strtoupper($matchedCompany . ' ' . $matchedType) . ' Report';
            elseif ($matchedCompany)
                $fTitle = strtoupper($matchedCompany) . ' Paper Report';
            elseif ($matchedType)
                $fTitle = strtoupper($matchedType) . ' Paper Report';
            elseif ($rollNoMatch)
                $fTitle = 'Roll ' . strtoupper($rollNoMatch) . ' Report';

            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
            $queryParams = [];
            if ($matchedCompany)
                $queryParams[] = 'company=' . urlencode($matchedCompany);
            if ($matchedType) {
                if ($matchedType === 'pp white' || $matchedType === 'pp-white')
                    $queryParams[] = 'type=PP-WHITE';
                elseif ($matchedType === 'pp clear' || $matchedType === 'pp-clear')
                    $queryParams[] = 'type=PP-CLEAR';
                else
                    $queryParams[] = 'type=' . urlencode($matchedType);
            }
            if ($lotBatchMatch)
                $queryParams[] = 'lot_no=' . urlencode($lotBatchMatch);
            if ($rollNoMatch)
                $queryParams[] = 'roll_no=' . urlencode($rollNoMatch);
            $queryStr = !empty($queryParams) ? '&' . implode('&', $queryParams) : '';

            $pdfUrl = "{$baseUrl}/modules/paper_stock/export.php?format=pdf{$queryStr}";
            $csvUrl = "{$baseUrl}/modules/paper_stock/export.php?format=csv{$queryStr}";

            $answer = "📊 **{$fTitle} — Export Ready:**\n\n";
            if ($userLang === 'Bengali') {
                $answer .= "আপনার রিপোর্ট তৈরি হয়ে গেছে। নিচের রঙিন বাটনগুলোতে ক্লিক করে PDF বা Excel (CSV) ফাইল ডাউনলোড করুন:\n\n";
            } elseif ($userLang === 'Hindi') {
                $answer .= "आपकी रिपोर्ट तैयार है। PDF या Excel (CSV) फाइल डाउनलोड करने के लिए नीचे दिए गए बटन पर क्लिक करें:\n\n";
            } else {
                $answer .= "Your report is ready. Click the buttons below to download the PDF or Excel (CSV) file:\n\n";
            }

            $answer .= '<div style="display: flex; gap: 15px; margin-top: 15px; flex-wrap: wrap;">' . "\n";
            $answer .= '    <a href="' . $pdfUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #ef4444, #dc2626); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(239, 68, 68, 0.4); border: 1px solid #b91c1c; font-size: 14px; min-width: 200px;">' . "\n";
            $answer .= '        📄 Download PDF Report' . "\n";
            $answer .= '    </a>' . "\n";
            $answer .= '    <a href="' . $csvUrl . '" target="_blank" style="text-decoration: none; display: flex; align-items: center; justify-content: center; gap: 8px; background: linear-gradient(135deg, #22c55e, #16a34a); color: white; padding: 12px 24px; border-radius: 8px; font-weight: bold; box-shadow: 0 4px 6px rgba(34, 197, 94, 0.4); border: 1px solid #15803d; font-size: 14px; min-width: 200px;">' . "\n";
            $answer .= '        📊 Download Excel (CSV)' . "\n";
            $answer .= '    </a>' . "\n";
            $answer .= '</div>';

            return ['tool_used' => 'Paper Stock Export Tool', 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
        }

        // ─── Financial Sub-Handler: purchase rate / price / দাম queries ───
        $isFinancialQuery = (strpos($p, 'rate') !== false || strpos($p, 'price') !== false || strpos($p, 'দাম') !== false || strpos($p, 'দামি') !== false || strpos($p, 'costly') !== false || strpos($p, 'expensive') !== false || strpos($p, 'মূল্য') !== false || strpos($p, 'রেট') !== false || strpos($p, 'दाम') !== false || strpos($p, 'रेट') !== false || strpos($p, 'कीमत') !== false || strpos($p, 'avg') !== false || strpos($p, 'average') !== false || strpos($p, 'গড়') !== false);
        if ($isFinancialQuery) {
            $isMostExpensive = (strpos($p, 'সবচেয়ে দামি') !== false || strpos($p, 'সবচেয়ে বেশি') !== false || strpos($p, 'most expensive') !== false || strpos($p, 'highest rate') !== false || strpos($p, 'costly') !== false || strpos($p, 'max price') !== false || strpos($p, 'সর্বোচ্চ') !== false);

            $fWhere = ["purchase_rate IS NOT NULL", "purchase_rate > 0"];
            $fParams = [];
            $fTypes = '';
            if ($matchedCompany) {
                $fWhere[] = "company LIKE ?";
                $fParams[] = '%' . $matchedCompany . '%';
                $fTypes .= 's';
            }
            if ($matchedType) {
                if ($matchedType === 'pp white' || $matchedType === 'pp-white') {
                    $fWhere[] = "(paper_type LIKE '%pp-white%' OR paper_type LIKE '%pp white%' OR paper_type LIKE '%pp_white%')";
                } elseif ($matchedType === 'pp clear' || $matchedType === 'pp-clear') {
                    $fWhere[] = "(paper_type LIKE '%pp-clear%' OR paper_type LIKE '%pp clear%' OR paper_type LIKE '%pp_clear%')";
                } else {
                    $fWhere[] = "paper_type LIKE ?";
                    $fParams[] = '%' . $matchedType . '%';
                    $fTypes .= 's';
                }
            }
            $fWhereSql = implode(' AND ', $fWhere);

            $latestSql = "SELECT id, roll_no, company, paper_type, width_mm, length_mtr, purchase_rate, date_received, status FROM paper_stock WHERE {$fWhereSql} ORDER BY id DESC LIMIT 1";
            $latestStmt = $db->prepare($latestSql);
            if (!empty($fParams))
                $latestStmt->bind_param($fTypes, ...$fParams);
            $latestStmt->execute();
            $latestRoll = $latestStmt->get_result()->fetch_assoc();

            $aggSql = "SELECT COUNT(*) as cnt, ROUND(AVG(purchase_rate),2) as avg_rate, ROUND(MIN(purchase_rate),2) as min_rate, ROUND(MAX(purchase_rate),2) as max_rate FROM paper_stock WHERE {$fWhereSql}";
            $aggStmt = $db->prepare($aggSql);
            if (!empty($fParams))
                $aggStmt->bind_param($fTypes, ...$fParams);
            $aggStmt->execute();
            $aggData = $aggStmt->get_result()->fetch_assoc();

            $expensiveRoll = null;
            if ($isMostExpensive) {
                $expSql = "SELECT id, roll_no, company, paper_type, width_mm, length_mtr, purchase_rate, date_received, status FROM paper_stock WHERE {$fWhereSql} ORDER BY purchase_rate DESC LIMIT 1";
                $expStmt = $db->prepare($expSql);
                if (!empty($fParams))
                    $expStmt->bind_param($fTypes, ...$fParams);
                $expStmt->execute();
                $expensiveRoll = $expStmt->get_result()->fetch_assoc();
            }

            $recentSql = "SELECT id, roll_no, company, paper_type, width_mm, purchase_rate, date_received FROM paper_stock WHERE {$fWhereSql} ORDER BY id DESC LIMIT 5";
            $recentStmt = $db->prepare($recentSql);
            if (!empty($fParams))
                $recentStmt->bind_param($fTypes, ...$fParams);
            $recentStmt->execute();
            $recentRolls = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            if ($latestRoll || ($aggData && (int) $aggData['cnt'] > 0)) {
                $fTitle = 'Paper Stock';
                if ($matchedCompany && $matchedType)
                    $fTitle = strtoupper($matchedCompany . ' ' . $matchedType);
                elseif ($matchedCompany)
                    $fTitle = strtoupper($matchedCompany);
                elseif ($matchedType)
                    $fTitle = strtoupper($matchedType);

                $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

                if ($userLang === 'Bengali') {
                    $fAnswer = "💰 **{$fTitle} — Purchase Rate তথ্য:**\n\n";
                    if ($latestRoll) {
                        $fAnswer .= "📦 **সর্বশেষ রোলের Purchase Rate:**\n"
                            . "  - রোল নং: `{$latestRoll['roll_no']}`\n"
                            . "  - কোম্পানি: **" . ($latestRoll['company'] ?: 'N/A') . "**\n"
                            . "  - পেপার টাইপ: **" . ($latestRoll['paper_type'] ?: 'N/A') . "**\n"
                            . "  - প্রস্থ: **" . ($latestRoll['width_mm'] ?: '0') . "mm**\n"
                            . "  - 💵 **Purchase Rate: ₹" . number_format((float) $latestRoll['purchase_rate'], 2) . "**\n"
                            . "  - তারিখ: `" . ($latestRoll['date_received'] ?: 'N/A') . "`\n\n";
                    }
                    if ($aggData && (int) $aggData['cnt'] > 0) {
                        $fAnswer .= "📊 **Rate সামারি** (মোট " . number_format((int) $aggData['cnt']) . " রোল):\n"
                            . "  - গড় Rate: **₹" . number_format((float) $aggData['avg_rate'], 2) . "**\n"
                            . "  - সর্বনিম্ন Rate: **₹" . number_format((float) $aggData['min_rate'], 2) . "**\n"
                            . "  - সর্বোচ্চ Rate: **₹" . number_format((float) $aggData['max_rate'], 2) . "**\n\n";
                    }
                    if ($expensiveRoll) {
                        $fAnswer .= "🏆 **সবচেয়ে দামি রোল:**\n"
                            . "  - রোল নং: `{$expensiveRoll['roll_no']}` | 💵 **₹" . number_format((float) $expensiveRoll['purchase_rate'], 2) . "**\n"
                            . "  - কোম্পানি: **" . ($expensiveRoll['company'] ?: 'N/A') . "** | টাইপ: **" . ($expensiveRoll['paper_type'] ?: 'N/A') . "**\n\n";
                    }
                    if (!empty($recentRolls)) {
                        $fAnswer .= "📋 **সাম্প্রতিক রোলের Rate:**\n";
                        $fAnswer .= "| # | রোল নং | কোম্পানি | টাইপ | Rate |\n";
                        $fAnswer .= "|--:|--------|----------|------|------|\n";
                        foreach ($recentRolls as $ri => $rr) {
                            $fAnswer .= "| " . ($ri + 1) . " | `" . ($rr['roll_no'] ?: '-') . "` | " . ($rr['company'] ?: '-') . " | " . ($rr['paper_type'] ?: '-') . " | ₹" . number_format((float) $rr['purchase_rate'], 2) . " |\n";
                        }
                        $fAnswer .= "\n";
                    }
                    $fAnswer .= "👉 [পেপার স্টক পেজ খুলুন]({$baseUrl}/modules/paper_stock/index.php)";
                } elseif ($userLang === 'Hindi') {
                    $fAnswer = "💰 **{$fTitle} — Purchase Rate जानकारी:**\n\n";
                    if ($latestRoll) {
                        $fAnswer .= "📦 **नवीनतम रोल का Purchase Rate:**\n"
                            . "  - रोल नं.: `{$latestRoll['roll_no']}`\n"
                            . "  - कंपनी: **" . ($latestRoll['company'] ?: 'N/A') . "**\n"
                            . "  - पेपर टाइप: **" . ($latestRoll['paper_type'] ?: 'N/A') . "**\n"
                            . "  - चौड़ाई: **" . ($latestRoll['width_mm'] ?: '0') . "mm**\n"
                            . "  - 💵 **Purchase Rate: ₹" . number_format((float) $latestRoll['purchase_rate'], 2) . "**\n"
                            . "  - तारीख: `" . ($latestRoll['date_received'] ?: 'N/A') . "`\n\n";
                    }
                    if ($aggData && (int) $aggData['cnt'] > 0) {
                        $fAnswer .= "📊 **Rate सारांश** (कुल " . number_format((int) $aggData['cnt']) . " रोल):\n"
                            . "  - औसत Rate: **₹" . number_format((float) $aggData['avg_rate'], 2) . "**\n"
                            . "  - न्यूनतम Rate: **₹" . number_format((float) $aggData['min_rate'], 2) . "**\n"
                            . "  - अधिकतम Rate: **₹" . number_format((float) $aggData['max_rate'], 2) . "**\n\n";
                    }
                    $fAnswer .= "👉 [पेपर स्टक पेज खोलें]({$baseUrl}/modules/paper_stock/index.php)";
                } else {
                    $fAnswer = "💰 **{$fTitle} — Purchase Rate Information:**\n\n";
                    if ($latestRoll) {
                        $fAnswer .= "📦 **Latest Roll Purchase Rate:**\n"
                            . "  - Roll No: `{$latestRoll['roll_no']}`\n"
                            . "  - Company: **" . ($latestRoll['company'] ?: 'N/A') . "**\n"
                            . "  - Paper Type: **" . ($latestRoll['paper_type'] ?: 'N/A') . "**\n"
                            . "  - Width: **" . ($latestRoll['width_mm'] ?: '0') . "mm**\n"
                            . "  - 💵 **Purchase Rate: ₹" . number_format((float) $latestRoll['purchase_rate'], 2) . "**\n"
                            . "  - Date: `" . ($latestRoll['date_received'] ?: 'N/A') . "`\n\n";
                    }
                    if ($aggData && (int) $aggData['cnt'] > 0) {
                        $fAnswer .= "📊 **Rate Summary** (" . number_format((int) $aggData['cnt']) . " rolls with rate):\n"
                            . "  - Average Rate: **₹" . number_format((float) $aggData['avg_rate'], 2) . "**\n"
                            . "  - Minimum Rate: **₹" . number_format((float) $aggData['min_rate'], 2) . "**\n"
                            . "  - Maximum Rate: **₹" . number_format((float) $aggData['max_rate'], 2) . "**\n\n";
                    }
                    if ($expensiveRoll) {
                        $fAnswer .= "🏆 **Most Expensive Roll:**\n"
                            . "  - Roll No: `{$expensiveRoll['roll_no']}` | 💵 **₹" . number_format((float) $expensiveRoll['purchase_rate'], 2) . "**\n"
                            . "  - Company: **" . ($expensiveRoll['company'] ?: 'N/A') . "** | Type: **" . ($expensiveRoll['paper_type'] ?: 'N/A') . "**\n\n";
                    }
                    if (!empty($recentRolls)) {
                        $fAnswer .= "📋 **Recent Rolls with Rates:**\n";
                        $fAnswer .= "| # | Roll No | Company | Type | Rate |\n";
                        $fAnswer .= "|--:|---------|---------|------|------|\n";
                        foreach ($recentRolls as $ri => $rr) {
                            $fAnswer .= "| " . ($ri + 1) . " | `" . ($rr['roll_no'] ?: '-') . "` | " . ($rr['company'] ?: '-') . " | " . ($rr['paper_type'] ?: '-') . " | ₹" . number_format((float) $rr['purchase_rate'], 2) . " |\n";
                        }
                        $fAnswer .= "\n";
                    }
                    $fAnswer .= "👉 [Open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
                }

                $navUrl = null;
                if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
                    $navUrl = $baseUrl . '/modules/paper_stock/index.php';
                }
                return [
                    'tool_used' => 'Paper Stock Financial Tool',
                    'total_count' => (int) ($aggData['cnt'] ?? 0),
                    'total_meters' => 0,
                    'filtered_type' => '',
                    'is_company_list' => false,
                    'direct_answer' => $fAnswer,
                    'nav_url' => $navUrl,
                    'data' => []
                ];
            }
        }

        $summarySql = "SELECT COUNT(*) as roll_count, IFNULL(SUM(length_mtr),0) as total_mtr, IFNULL(SUM((width_mm/1000.0)*length_mtr),0) as total_sqm FROM paper_stock WHERE {$whereSql}";
        file_put_contents('debug.txt', "Prompt: $prompt | GSM: " . var_export($gsmMatch, true) . " | SQL: $summarySql");
        $stmt = $db->prepare($summarySql);
        if (!empty($params)) {
            $stmt->bind_param($typesStr, ...$params);
        }
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();

        $totalCount = (int) ($summary['roll_count'] ?? 0);
        $totalMeters = round((float) ($summary['total_mtr'] ?? 0), 2);
        $totalSqm = round((float) ($summary['total_sqm'] ?? 0), 2);

        $dataSql = "SELECT id, roll_no, paper_type, company, width_mm, length_mtr, ROUND((width_mm/1000.0)*length_mtr, 2) as sqm, status, job_no, date_received FROM paper_stock WHERE {$whereSql} ORDER BY id DESC LIMIT 15";
        $stmt2 = $db->prepare($dataSql);
        if (!empty($params)) {
            $stmt2->bind_param($typesStr, ...$params);
        }
        $stmt2->execute();
        $data = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);

        $deepSql = "SELECT 
            SUM(CASE WHEN width_mm >= 1000 THEN 1 ELSE 0 END) as jumbo_rolls,
            IFNULL(SUM(CASE WHEN width_mm >= 1000 THEN length_mtr ELSE 0 END),0) as jumbo_mtr,
            SUM(CASE WHEN width_mm < 1000 THEN 1 ELSE 0 END) as slitted_rolls,
            IFNULL(SUM(CASE WHEN width_mm < 1000 THEN length_mtr ELSE 0 END),0) as slitted_mtr,
            MIN(NULLIF(date_received,'0000-00-00')) as min_date,
            MAX(date_received) as max_date
            FROM paper_stock WHERE {$whereSql}";
        $stmtDeep = $db->prepare($deepSql);
        if (!empty($params)) {
            $stmtDeep->bind_param($typesStr, ...$params);
        }
        $stmtDeep->execute();
        $deepData = $stmtDeep->get_result()->fetch_assoc();

        $jumboRolls = (int) ($deepData['jumbo_rolls'] ?? 0);
        $jumboMtr = round((float) ($deepData['jumbo_mtr'] ?? 0), 2);
        $slittedRolls = (int) ($deepData['slitted_rolls'] ?? 0);
        $slittedMtr = round((float) ($deepData['slitted_mtr'] ?? 0), 2);
        $latestEntryDate = $deepData['max_date'] ?: 'N/A';

        // Company Breakdown List
        $compSql = "SELECT company, COUNT(*) as rolls, SUM(length_mtr) as total_mtr FROM paper_stock WHERE {$whereSql} AND company != '' GROUP BY company ORDER BY rolls DESC LIMIT 8";
        $stmtComp = $db->prepare($compSql);
        if (!empty($params)) {
            $stmtComp->bind_param($typesStr, ...$params);
        }
        $stmtComp->execute();
        $companyList = $stmtComp->get_result()->fetch_all(MYSQLI_ASSOC);


        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        $titleHeading = 'Paper Roll Stock Summary';
        if ($lotBatchMatch) {
            $titleHeading = 'Lot/Batch: ' . strtoupper($lotBatchMatch);
        } elseif ($companyRollMatch) {
            $titleHeading = 'Company Roll: ' . strtoupper($companyRollMatch);
        } elseif ($matchedCompany && $matchedType) {
            $titleHeading = strtoupper($matchedCompany . ' ' . $matchedType);
        } elseif ($matchedCompany) {
            $titleHeading = strtoupper($matchedCompany) . ' Paper Stock';
        } elseif ($matchedType) {
            $titleHeading = strtoupper($matchedType) . ' Paper Stock';
        } elseif ($rollNoMatch) {
            $titleHeading = 'Roll ' . strtoupper($rollNoMatch);
        }

        if ($userLang === 'English') {
            $answer = "📜 **{$titleHeading} — Complete Technical Inventory Summary:**\n\n"
                . "📊 **Total Stock Metrics:**\n"
                . "  - Total Paper Rolls: **" . number_format($totalCount) . " Rolls**\n"
                . "  - Total Running Length: **" . number_format($totalMeters, 2) . " meters**\n"
                . "  - Total Surface Area: **" . number_format($totalSqm, 2) . " SQM**\n"
                . "  - Latest Stock Entry Date: `" . $latestEntryDate . "`\n\n"
                . "🏭 **Jumbo Roll vs Slitting Breakdown:**\n"
                . "  - 📜 **Jumbo Parent Rolls (≥1000mm):** **" . number_format($jumboRolls) . " Rolls** (" . number_format($jumboMtr, 2) . " meters)\n"
                . "  - ✂️ **Slitted Stock Rolls (<1000mm):** **" . number_format($slittedRolls) . " Rolls** (" . number_format($slittedMtr, 2) . " meters)\n\n";

            if (!empty($companyList) && count($companyList) > 1) {
                $answer .= "🏢 **Available Companies / Brands Breakdown:**\n";
                foreach ($companyList as $cb) {
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . " Rolls** (" . number_format((float) $cb['total_mtr'], 2) . " meters)\n";
                }
                $answer .= "\n";
            }

            $answer .= "📦 **Master Roll Grid (Sample " . count($data) . " Rolls):**\n\n";

            foreach ($data as $idx => $r) {
                $answer .= "• **Roll " . ($idx + 1) . ": `" . ($r['roll_no'] ?: 'Roll #' . $r['id']) . "`** (ID: **{$r['id']}** | Status: **{$r['status']}**)\n"
                    . "  - 🏷️ **Brand & Type:** **" . ($r['company'] ?: 'General') . " " . ($r['paper_type'] ?: 'Substrate') . "**\n"
                    . "  - 📐 **Dimensions:** Width: **" . ($r['width_mm'] ?: '0') . "mm** | Length: **" . number_format((float) $r['length_mtr'], 2) . "m**\n"
                    . "  - 📐 **Surface Area:** **" . number_format((float) $r['sqm'], 2) . " SQM**\n"
                    . "  - 📅 **Date Received:** `" . ($r['date_received'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [Click here to open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "📜 **{$titleHeading} — पूर्ण तकनीकी इन्वेंटरी सारांश:**\n\n"
                . "📊 **कुल स्टॉक मेट्रिक्स:**\n"
                . "  - कुल पेपर रोल: **" . number_format($totalCount) . " रोल**\n"
                . "  - कुल रनिंग लंबाई: **" . number_format($totalMeters, 2) . " मीटर**\n"
                . "  - कुल सतह क्षेत्रफल: **" . number_format($totalSqm, 2) . " SQM**\n"
                . "  - नवीनतम प्रविष्टि तिथि: `" . $latestEntryDate . "`\n\n"
                . "🏭 **जंबो रोल बनाम स्लिटिंग विवरण:**\n"
                . "  - 📜 **जंबो पैरेंट रोल (≥1000mm):** **" . number_format($jumboRolls) . " रोल** (" . number_format($jumboMtr, 2) . " मीटर)\n"
                . "  - ✂️ **स्लिटेड स्टॉक रोल (<1000mm):** **" . number_format($slittedRolls) . " रोल** (" . number_format($slittedMtr, 2) . " मीटर)\n\n";

            if (!empty($companyList) && count($companyList) > 1) {
                $answer .= "🏢 **उपलब्ध कंपनियां / ब्रांड्स:**\n";
                foreach ($companyList as $cb) {
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . " रोल** (" . number_format((float) $cb['total_mtr'], 2) . " मीटर)\n";
                }
                $answer .= "\n";
            }

            $answer .= "👉 [पेपर स्टक पेज खोलें]({$baseUrl}/modules/paper_stock/index.php)";
        } else {
            $answer = "📜 **{$titleHeading} — সম্পূর্ণ টেকনিক্যাল ইনভেন্টরি সামারি:**\n\n"
                . "📊 **সর্বমোট ইনভেন্টরি ম্যাট্রিক্স:**\n"
                . "  - সর্বমোট রেডি পেপার রোল: **" . number_format($totalCount) . "টি রোল**\n"
                . "  - সর্বমোট রানিং দৈর্ঘ্য: **" . number_format($totalMeters, 2) . " মিটার**\n"
                . "  - সর্বমোট সারফেস এরিয়া (SQM): **" . number_format($totalSqm, 2) . " SQM**\n"
                . "  - সর্বশেষ এন্ট্রি তারিখ: `" . $latestEntryDate . "`\n\n"
                . "🏭 **জম্বো রোল বনাম স্লিটিং ব্রেকডাউন:**\n"
                . "  - 📜 **জম্বো পৈরেন্ট রোল (≥১০০০মিমি):** **" . number_format($jumboRolls) . "টি রোল** (" . number_format($jumboMtr, 2) . " মিটার)\n"
                . "  - ✂️ **স্লিটেড স্টক রোল (<১০০০মিমি):** **" . number_format($slittedRolls) . "টি রোল** (" . number_format($slittedMtr, 2) . " মিটার)\n\n";

            if (!empty($companyList) && count($companyList) > 1) {
                $answer .= "🏢 **উপলব্ধ কোম্পানি / ব্র্যান্ড তালিকা:**\n";
                foreach ($companyList as $cb) {
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . "টি রোল** (" . number_format((float) $cb['total_mtr'], 2) . " মিটার)\n";
                }
                $answer .= "\n";
            }

            $answer .= "📦 **মাস্টার রোল গ্রিড (স্যাম্পল " . count($data) . " রোল):**\n\n";

            foreach ($data as $idx => $r) {
                $answer .= "• **রোল " . ($idx + 1) . ": `" . ($r['roll_no'] ?: 'Roll #' . $r['id']) . "`** (ID: **{$r['id']}** | স্ট্যাটাস: **{$r['status']}**)\n"
                    . "  - 🏷️ **ব্র্যান্ড ও পেপার টাইপ:** **" . ($r['company'] ?: 'General') . " " . ($r['paper_type'] ?: 'Substrate') . "**\n"
                    . "  - 📐 **সাইজ ও দৈর্ঘ্য:** প্রস্থ: **" . ($r['width_mm'] ?: '0') . "mm** | দৈর্ঘ্য: **" . number_format((float) $r['length_mtr'], 2) . "m**\n"
                    . "  - 📐 **সারফেস এরিয়া (SQM):** **" . number_format((float) $r['sqm'], 2) . " SQM**\n"
                    . "  - 📅 **গ্রহণের তারিখ:** `" . ($r['date_received'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [পেপার স্টক পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/paper_stock/index.php)";
        }


        $navUrl = null;
        if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
            $navUrl = $baseUrl . '/modules/paper_stock/index.php';
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $totalCount,
            'total_meters' => $totalMeters,
            'filtered_type' => '',
            'is_company_list' => false,
            'direct_answer' => $answer,
            'nav_url' => $navUrl,
            'data' => []
        ];
    } elseif ($commandType === 'job' || preg_match('/\b(job|jobs|card|cards|order|orders|flx|lsl|jmb|pck|brc|status|progress|work)\b/i', $prompt)) {
        $toolName = 'ERP Jobs & Planning Tool';

        // Extract search term from prompt
        $jobStopwords = ['can', 'you', 'of', 'give', 'me', 'the', 'detail', 'details', 'about', 'job', 'want', 'to', 'printing', 'pitnting', 'print', 'jobs', 'name', 'named', 'by', 'how', 'many', 'we', 'have', 'is', 'are', 'in', 'show', 'tell', 'list', 'find', 'search', 'for', 'a', 'an', 'what', 'which', 'please', 'pls', 'lookup', 'display', 'and', 'or', 'about', 'with', 'this', 'that', 'get', 'fetch', 'on', 'at', 'from', 'a', 'an', 'ki', 'kya', 'hai', 'ami', 'tumi', 'do', 'we', 'have', 'any', 'my'];
        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9]/', '', $w));
            if ($wClean !== '' && !in_array($wClean, $jobStopwords, true) && strlen($wClean) >= 2) {
                $terms[] = $wClean;
            }
        }
        $jobSearchTerm = implode(' ', $terms);

        if ($jobSearchTerm !== '') {
            $like = '%' . $jobSearchTerm . '%';
            $stmt = $db->prepare("
                SELECT j.id, j.job_no, j.job_type, j.department, j.status, j.created_at,
                       p.job_no as planning_job_no, p.job_name, p.status as planning_status, p.priority
                FROM planning p
                LEFT JOIN jobs j ON j.planning_id = p.id AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
                WHERE p.deleted_at IS NULL AND (p.job_name LIKE ? OR p.job_no LIKE ? OR j.job_no LIKE ?)
                ORDER BY p.id DESC LIMIT 10
            ");
            $stmt->bind_param('sss', $like, $like, $like);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $totalCount = count($data);

            if ($totalCount === 0) {
                // Fallback: search master_plate_data
                $like = '%' . $jobSearchTerm . '%';
                $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? ORDER BY id DESC LIMIT 5");
                $stmt->bind_param('s', $like);
                $stmt->execute();
                $plateData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

                if (!empty($plateData)) {
                    // Save context for math engine
                    $_SESSION['ai_last_plate_id'] = $plateData[0]['id'];

                    $sampleCount = count($plateData);
                    if ($userLang === 'English') {
                        $answer = "📊 **Printing Plates Master Tool — Technical Specifications:**\n\nFound **{$sampleCount} matching plate record(s)** in your ERP database:\n\n";
                        foreach ($plateData as $idx => $row) {
                            $answer .= "• **Plate " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                                . "  - 📏 **Repeat Value:** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                                . "  - 📐 **Gap (Horizontal / Vertical):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                                . "  - 📐 **Plate Size:** **" . ($row['size'] ?: 'N/A') . "** | **Ups:** **" . ($row['ups'] ?: '1') . "**\n"
                                . "  - 📄 **Paper Type & Size:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                                . "  - ⚙️ **Die & Cylinder:** **" . ($row['die'] ?: 'N/A') . "** | Cylinder: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                                . "  - 🏭 **Make By:** **" . ($row['make_by'] ?: 'N/A') . "** | Date Received: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                        }
                    } else {
                        $answer = "📊 **প্রিন্টিং প্লেটের বিস্তারিত টেকনিক্যাল স্পেসিফিকেশন:**\n\nআপনার ইআরপি ডাটাবেসে **{$sampleCount}টি ম্যাচিং প্লেট** পাওয়া গেছে:\n\n";
                        foreach ($plateData as $idx => $row) {
                            $answer .= "• **প্লেট " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                                . "  - 📏 **রিপিট ভ্যালু (Repeat Value):** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                                . "  - 📐 **গ্যাপ (Gap H / Gap V):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                                . "  - 📐 **প্লেট সাইজ:** **" . ($row['size'] ?: 'N/A') . "** | **আফস (Ups):** **" . ($row['ups'] ?: '1') . "**\n"
                                . "  - 📄 **কাগজের টাইপ ও সাইজ:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                                . "  - ⚙️ **ডাই ও সিলিন্ডার:** **" . ($row['die'] ?: 'N/A') . "** | সিলিন্ডার: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                                . "  - 🏭 **মেকার:** **" . ($row['make_by'] ?: 'N/A') . "** | এন্ট্রি তারিখ: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                        }
                    }
                    return [
                        'tool_used' => 'Printing Plates Master Tool',
                        'total_count' => $sampleCount,
                        'total_meters' => 0,
                        'filtered_type' => '',
                        'is_company_list' => false,
                        'direct_answer' => $answer,
                        'data' => []
                    ];
                }


                if ($userLang === 'English') {
                    $directAnswer = "❌ **No Job Found Matching \"{$jobSearchTerm}\"**\n\n"
                        . "I searched your ERP Planning Board and Job Cards database, but no active job matching **\"{$jobSearchTerm}\"** was found.\n\n"
                        . "💡 **Tip:** Please check if the job name or Job Card number is spelled correctly.";
                } elseif ($userLang === 'Hindi') {
                    $directAnswer = "❌ **\"{$jobSearchTerm}\" नाम का कोई जॉब नहीं मिला**\n\n"
                        . "आपके ईआरपी डेटाबेस में **\"{$jobSearchTerm}\"** का कोई जॉब या जॉब कार्ड दर्ज नहीं है।\n\n"
                        . "💡 **टिप:** कृपया जांचें कि जॉब का नाम या जॉब कार्ड नंबर सही है या नहीं।";
                } else {
                    $directAnswer = "❌ **\"{$jobSearchTerm}\" নামে কোনো জব পাওয়া যায়নি**\n\n"
                        . "আপনার ইআরপি ডাটাবেসে **\"{$jobSearchTerm}\"** নামে কোনো সচল জব বা জব কার্ড নিবন্ধিত নেই।\n\n"
                        . "💡 **পরামর্শ:** অনুগ্রহ করে জব এর নাম বা জব কার্ড নম্বরটি সঠিক রয়েছে কিনা চেক করুন।";
                }

                return [
                    'tool_used' => $toolName,
                    'total_count' => 0,
                    'total_meters' => 0,
                    'filtered_type' => '',
                    'is_company_list' => false,
                    'direct_answer' => $directAnswer,
                    'data' => []
                ];
            }
        } else {
            $cntRes = $db->query("SELECT COUNT(*) as cnt FROM jobs");
            $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
            $res = $db->query("SELECT id, job_no, planning_id, job_type, department, status, created_at FROM jobs ORDER BY id DESC LIMIT 10");
            $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
    } else {
        // Fallback: search master_plate_data before giving up
        $searchTerm = '';
        if (preg_match('/"([^"]+)"/', $prompt, $m) || preg_match("/'([^']+)'/", $prompt, $m)) {
            $searchTerm = trim($m[1]);
        } else {
            $unmatchedStopwords = ['can', 'you', 'give', 'me', 'the', 'details', 'about', 'please', 'show', 'want', 'to', 'printing', 'pitnting', 'print', 'tell', 'find', 'search', 'for', 'a', 'an', 'what', 'which', 'is', 'are', 'in', 'of', 'to', 'do', 'has', 'have'];
            $pWords = preg_split('/\s+/', strtolower($prompt));
            $terms = [];
            foreach ($pWords as $w) {
                $wClean = trim(preg_replace('/[^a-z0-9]/', '', $w));
                if ($wClean !== '' && !in_array($wClean, $unmatchedStopwords, true) && strlen($wClean) >= 2) {
                    $terms[] = $wClean;
                }
            }
            if (!empty($terms)) {
                $searchTerm = implode(' ', $terms);
            }
        }

        if ($searchTerm !== '') {
            $like = '%' . $searchTerm . '%';
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? ORDER BY id DESC LIMIT 5");
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $plateData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!empty($plateData)) {

                // Save context for math engine
                $_SESSION['ai_last_plate_id'] = $plateData[0]['id'];

                $sampleCount = count($plateData);
                if ($userLang === 'English') {
                    $answer = "📊 **Printing Plates Master Tool — Technical Specifications:**\n\nFound **{$sampleCount} matching plate record(s)** in your ERP database:\n\n";
                    foreach ($plateData as $idx => $row) {
                        $answer .= "• **Plate " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                            . "  - 📏 **Repeat Value:** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                            . "  - 📐 **Gap (Horizontal / Vertical):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                            . "  - 📐 **Plate Size:** **" . ($row['size'] ?: 'N/A') . "** | **Ups:** **" . ($row['ups'] ?: '1') . "**\n"
                            . "  - 📄 **Paper Type & Size:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                            . "  - ⚙️ **Die & Cylinder:** **" . ($row['die'] ?: 'N/A') . "** | Cylinder: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                            . "  - 🏭 **Make By:** **" . ($row['make_by'] ?: 'N/A') . "** | Date Received: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                    }
                } else {
                    $answer = "📊 **প্রিন্টিং প্লেটের বিস্তারিত টেকনিক্যাল স্পেসিফিকেশন:**\n\nআপনার ইআরপি ডাটাবেসে **{$sampleCount}টি ম্যাচিং প্লেট** পাওয়া গেছে:\n\n";
                    foreach ($plateData as $idx => $row) {
                        $answer .= "• **প্লেট " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                            . "  - 📏 **রিপিট ভ্যালু (Repeat Value):** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                            . "  - 📐 **গ্যাপ (Gap H / Gap V):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                            . "  - 📐 **প্লেট সাইজ:** **" . ($row['size'] ?: 'N/A') . "** | **আফস (Ups):** **" . ($row['ups'] ?: '1') . "**\n"
                            . "  - 📄 **কাগজের টাইপ ও সাইজ:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                            . "  - ⚙️ **ডাই ও সিলিন্ডার:** **" . ($row['die'] ?: 'N/A') . "** | সিলিন্ডার: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                            . "  - 🏭 **মেকার:** **" . ($row['make_by'] ?: 'N/A') . "** | এন্ট্রি তারিখ: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                    }
                }
                return [
                    'tool_used' => 'Printing Plates Master Tool',
                    'total_count' => $sampleCount,
                    'total_meters' => 0,
                    'filtered_type' => '',
                    'is_company_list' => false,
                    'direct_answer' => $answer,
                    'data' => $plateData
                ];
            }
        }
        $toolName = 'Unmatched Query Assistant';
        $totalCount = 0;
        $totalMeters = 0;
        $filteredType = '';
        $isCompanyList = false;
        $data = [];
    }

    return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => $totalMeters, 'filtered_type' => $filteredType, 'is_company_list' => $isCompanyList, 'data' => $data];
}



$userLang = detect_language($prompt);



// Normal mode: skip ERP database entirely, go straight to LLM
if ($skipNormalDb) {
    $retrieved = ['tool_used' => 'Unmatched Query Assistant', 'total_count' => 0, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'data' => []];
} else {
    $retrieved = fetch_erp_data_by_intent($db, $prompt, $userLang);
}
$toolUsed = $retrieved['tool_used'];
$totalCount = $retrieved['total_count'];
$totalMeters = $retrieved['total_meters'];
$filteredType = $retrieved['filtered_type'];
$isCompanyList = $retrieved['is_company_list'];
$dbData = $retrieved['data'];
$sampleCount = count($dbData);

$hasNumbers = preg_match('/\d+/', $prompt);
$isComplexCalcQuery = $hasNumbers && preg_match('/(koto|কত|how|calculate|calc|taka|cost|টাকা|হিসাব|hisab|amount|price|paisa|label|berobe|বেরোবে|printing|প্রিন্টিং|total|sqr|sqm|meter|gauge|\+|\*|\/|\-)/i', mb_strtolower($prompt, 'UTF-8'));

if (!empty($retrieved['direct_answer'])) {
    if ($isComplexCalcQuery && !empty($dbData) && $toolUsed === 'Printing Plates Master Tool') {
        $llmPrompt = "User Query: " . $prompt . "\n\nERP Database Technical Output:\n" . json_encode($dbData, JSON_UNESCAPED_UNICODE) . "\n\nPlease calculate or answer the user's query mathematically step-by-step using the provided ERP data.";
        $llmAnswer = call_llm_api($llmPrompt, $config);
        if ($llmAnswer !== null && strpos($llmAnswer, '[API_ERROR]') !== 0) {
            $finalAnswer = $llmAnswer;
            $toolUsed = 'AI Mathematics Engine';
        } else {
            $finalAnswer = $retrieved['direct_answer'];
        }
    } else {
        $finalAnswer = $retrieved['direct_answer'];
    }
} elseif ($toolUsed === 'Job Planning Board Tool') {
    if ($userLang === 'English') {
        $finalAnswer = "📊 **Job Planning Board & Department Statuses:**\n\nFound **{$totalCount} Active Jobs** on the Planning Board:\n\n";
        foreach ($dbData as $idx => $item) {
            $finalAnswer .= "• **Job " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | Priority: **{$item['priority']}** | Board Status: **{$item['status']}**\n";
            if (!empty($item['departments'])) {
                $finalAnswer .= "  - 🏭 **Departmental Progress:**\n";
                foreach ($item['departments'] as $d) {
                    $finalAnswer .= "    ▸ **" . strtoupper(str_replace('_', ' ', $d['department'])) . "** (Job Card: `{$d['job_no']}`): **{$d['status']}**\n";
                }
            } else {
                $finalAnswer .= "  - ℹ️ *No departmental job cards assigned yet.*\n";
            }
            $finalAnswer .= "\n";
        }
    } elseif ($userLang === 'Hindi') {
        $finalAnswer = "📊 **जॉब प्लानिंग बोर्ड और विभाग स्थिति:**\n\nप्लानिंग बोर्ड पर कुल **{$totalCount} सक्रिय जॉब** उपलब्ध हैं:\n\n";
        foreach ($dbData as $idx => $item) {
            $finalAnswer .= "• **Job " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | प्राथमिकता: **{$item['priority']}** | बोर्ड स्थिति: **{$item['status']}**\n";
            if (!empty($item['departments'])) {
                $finalAnswer .= "  - 🏭 **विभागीय स्थिति (Department Progress):**\n";
                foreach ($item['departments'] as $d) {
                    $finalAnswer .= "    ▸ **" . strtoupper(str_replace('_', ' ', $d['department'])) . "** (Job Card: `{$d['job_no']}`): **{$d['status']}**\n";
                }
            }
            $finalAnswer .= "\n";
        }
    } else {
        // Bengali
        $finalAnswer = "📊 **প্ল্যানিং বোর্ড এবং ডিপার্টমেন্টভিত্তিক জব স্ট্যাটাস:**\n\nআপনার ইআরপি প্ল্যানিং বোর্ডে মোট **{$totalCount}টি জব** প্রস্তুত রয়েছে:\n\n";
        foreach ($dbData as $idx => $item) {
            $finalAnswer .= "• **জব " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | প্রাথমিকতা: **{$item['priority']}** | বোর্ড স্ট্যাটাস: **{$item['status']}**\n";
            if (!empty($item['departments'])) {
                $finalAnswer .= "  - 🏭 **ডিপার্টমেন্টভিত্তিক প্রোগ্রেস (Department Status):**\n";
                foreach ($item['departments'] as $d) {
                    $finalAnswer .= "    ▸ **" . strtoupper(str_replace('_', ' ', $d['department'])) . "** (জব কার্ড: `{$d['job_no']}`): **{$d['status']}**\n";
                }
            } else {
                $finalAnswer .= "  - ℹ️ *এখনো ডিপার্টমেন্টে প্রসেস শুরু হয়নি।*\n";
            }
            $finalAnswer .= "\n";
        }
    }
} elseif ($toolUsed === 'Unmatched Query Assistant') {
    // Normal Mode: query external LLM (KB and ERP database were not touched)
    // ERP-Only Mode: skip external LLM, go straight to no-knowledge message
    if ($erpOnlyMode) {
        $llmAnswer = null;
    } else {
        require_once __DIR__ . '/services/RetrievalEngine.php';
        require_once __DIR__ . '/services/ContextBuilder.php';

        $retrievalEngine = new RetrievalEngine($db, $aliasResolver ?? null);

        // Initialize Services
        $logger = new Logger();
        $features = new FeatureFlags($config);
        $memory = new MemoryManager($features);
        $toolsRouter = new ToolRouter($features, $logger);
        $promptBuilder = new PromptBuilder($memory, $retrievalEngine);
        $providerManager = new ProviderManager($config);
        $llmClient = new LLMClient($config, $logger, $providerManager);

        $systemPrompt = $config['system_prompt'] ?? "You are a helpful ERP assistant for Shree Label ERP. Be concise and accurate.";
        $systemPrompt .= "\n\nCurrent System Time: " . date('l, d F Y, h:i A');

        $messages = $promptBuilder->buildMessages($prompt, $systemPrompt);
        $toolDefinitions = $toolsRouter->getToolDefinitions();

        $llmAnswer = $llmClient->callWithFallback($prompt, $messages, $toolDefinitions);

        // Intercept Tool Calls
        $interceptedTool = $toolsRouter->interceptToolCall($llmAnswer ?? '');
        if ($interceptedTool) {
            if ($interceptedTool['function']['name'] === 'calculate_erp_job') {
                $llmAnswer = $toolsRouter->executeMathTool($interceptedTool, $db, $prompt);
                $toolUsed = 'AI Agentic Tool (Math)';
            } elseif ($interceptedTool['function']['name'] === 'erp_query_tool') {
                $args = json_decode($interceptedTool['function']['arguments'], true) ?: [];
                $module = $args['module'] ?? '';
                $intent = $args['intent'] ?? '';
                $filters = $args['filters'] ?? [];
                
                if ($module === 'paper_stock' && in_array($intent, ['paper_stock_summary', 'paper_stock_details', 'paper_stock_analysis'])) {
                    $ret = PaperStockAnalyzer::generateReport($db, $filters, $intent, $userLang);
                    if (!empty($ret['direct_answer'])) {
                        $llmAnswer = $ret['direct_answer'];
                        $toolUsed = $ret['tool_used'];
                    } else {
                        $llmAnswer = "⚠️ **Paper Stock Analyzer Encountered an Error.**\n\nCould not fetch ERP data.";
                        $toolUsed = 'Paper Stock Analyzer';
                    }
                } else {
                    // Synthesize a prompt for the legacy pipeline
                    $synthParts = [];
                    if (is_array($filters)) {
                        foreach ($filters as $k => $v) {
                            if (is_scalar($v)) {
                                $synthParts[] = (string)$v;
                            }
                        }
                    }
                    
                    $synthPrompt = implode(' ', $synthParts);
                    if ($module === 'paper_stock' && stripos($synthPrompt, 'paper') === false) {
                        $synthPrompt .= ' paper stock';
                    } elseif ($module === 'plate' && stripos($synthPrompt, 'plate') === false) {
                        $synthPrompt .= ' plate';
                    }
                    
                    // Fallback safe defaults if empty
                    if (trim($synthPrompt) === '') {
                        $synthPrompt = str_replace('_', ' ', $module);
                    }
                    
                    $ret = fetch_erp_data_by_intent($db, $synthPrompt, $userLang);
                    if (!empty($ret['direct_answer'])) {
                        $llmAnswer = "🤖 **AI Entity Extractor (Delegated to " . ucfirst(str_replace('_', ' ', $module)) . " Engine):**\n\n" . $ret['direct_answer'];
                        $toolUsed = $ret['tool_used'] ?: 'Generic ERP Tool';
                    } else {
                        $llmAnswer = "⚠️ **AI Tool Encountered an Error.**\n\nCould not fetch ERP data for module `{$module}` with the extracted filters.";
                        $toolUsed = 'Unmatched Query Assistant';
                    }
                }
            }
        }
    }
    if ($llmAnswer !== null && strpos($llmAnswer, '[API_ERROR]') !== 0) {
        $finalAnswer = $llmAnswer;
        if (empty($toolUsed) || $toolUsed === 'Unmatched Query Assistant') {
            $toolUsed = 'LLM Query Tool';
        }
    } else {
        if ($userLang === 'English') {
            $finalAnswer = "🤔 **I don't have knowledge regarding this topic.**\n\n"
                . ($skipNormalDb
                    ? "I checked my external knowledge source but couldn't find an answer to: **\"" . htmlspecialchars($prompt) . "\"**."
                    : "I searched my trained knowledge base and ERP data, but couldn't find an answer to: **\"" . htmlspecialchars($prompt) . "\"**.")
                . ($skipNormalDb ? '' : "\n\n"
                    . (isAdmin()
                        ? "💡 **Admin Tip:** You can train me in **Settings → AI Agent → Knowledge Base** by clicking **\"+ Add New Entry\"** with keywords and an answer.\n\n"
                        : "")
                    . "👨‍💼 **For help, please contact your ERP Administrator.**");
        } elseif ($userLang === 'Hindi') {
            $finalAnswer = "🤔 **मुझे इस विषय के बारे में कोई जानकारी नहीं है।**\n\n"
                . ($skipNormalDb
                    ? "मैंने अपने बाहरी ज्ञान स्रोत की जाँच की लेकिन आपकी क्वेरी: **\"" . htmlspecialchars($prompt) . "\"** का उत्तर नहीं मिला।"
                    : "मैंने अपने ट्रेन्ड नॉलेज बेस और ईआरपी डेटा में खोजा, लेकिन आपकी क्वेरी: **\"" . htmlspecialchars($prompt) . "\"** का उत्तर नहीं मिला।")
                . ($skipNormalDb ? '' : "\n\n"
                    . (isAdmin()
                        ? "💡 **एडमिन टिप:** आप **Settings → AI Agent → Knowledge Base** में जाकर **\"+ Add New Entry\"** से मुझे ट्रेन कर सकते हैं।\n\n"
                        : "")
                    . "👨‍💼 **कृपया सहायता के लिए अपने ERP एडमिनिस्ट्रेटर से संपर्क करें।**");
        } else {
            $finalAnswer = "🤔 **এই বিষয়ে আমার কোনো জ্ঞান নেই।**\n\n"
                . ($skipNormalDb
                    ? "আমি আমার বাহ্যিক জ্ঞান উৎস চেক করেছি কিন্তু আপনার প্রশ্ন: **\"" . htmlspecialchars($prompt) . "\"** এর উত্তর পাইনি।"
                    : "আমি আমার ট্রেইনড নলেজ বেস এবং ইআরপি ডেটাতে খুঁজেছি, কিন্তু আপনার প্রশ্ন: **\"" . htmlspecialchars($prompt) . "\"** এর উত্তর পাইনি।")
                . ($skipNormalDb ? '' : "\n\n"
                    . (isAdmin()
                        ? "💡 **এডমিন পরামর্শ:** আপনি **Settings → AI Agent → Knowledge Base** এ গিয়ে **\"+ Add New Entry\"** ক্লিক করে কীওয়ার্ড ও উত্তর যোগ করে আমাকে ট্রেইন করতে পারেন।\n\n"
                        : "")
                    . "👨‍💼 **সাহায্যের জন্য আপনার ERP অ্যাডমিনিস্ট্রেটরের সাথে যোগাযোগ করুন।**");
        }
    }
} else {

    if ($userLang === 'English') {
        $finalAnswer = "📊 **{$toolUsed} Results:**\n\n";
        $commandType = 'OPEN_MODULE';
        $command = "open shree-label-php/modules/ai_agent/index.php";
        if ($isCompanyList) {
            $finalAnswer .= "Found **{$totalCount} Paper Companies** supplying stock in your ERP database:\n\n";
            foreach ($dbData as $idx => $row) {
                $finalAnswer .= ($idx + 1) . ". **{$row['company']}**: {$row['roll_count']} rolls (" . number_format((float) $row['total_meters'], 2) . " meters)\n";
            }
        } elseif ($sampleCount === 1) {
            $finalAnswer .= "Found exact record in ERP database:\n\n";
            foreach ($dbData[0] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $finalAnswer .= "• **" . str_replace('_', ' ', strtoupper($k)) . ":** {$v}\n";
                }
            }
        } elseif ($toolUsed === 'Printing Plates Master Tool') {
            $finalAnswer = "📊 **Printing Plates — Found {$sampleCount} plates:**\n\n" . format_records_table($dbData, 'plate', $userLang);
        } else {
            $finalAnswer .= "Total **{$totalCount} records** in your ERP database.\n\n";
            if ($sampleCount > 0) {
                $finalAnswer .= format_records_table($dbData, 'generic', 'English');
            }
        }
    } else {

        $finalAnswer = "📊 **{$toolUsed} ফলাফল:**\n\n";
        if ($isCompanyList) {
            $finalAnswer .= "আপনার ইআরপি স্টকে মোট **{$totalCount}টি পেপার কোম্পানি/সাপ্লায়ারের** কাগজ রয়েছে:\n\n";
            foreach ($dbData as $idx => $row) {
                $finalAnswer .= ($idx + 1) . ". **{$row['company']}**: {$row['roll_count']}টি রোল (" . number_format((float) $row['total_meters'], 2) . " মিটার)\n";
            }
        } elseif ($sampleCount === 1) {
            $finalAnswer .= "ইআরপি ডাটাবেসে নির্দিষ্ট সচল রেকর্ডটি পাওয়া গেছে:\n\n";
            foreach ($dbData[0] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $finalAnswer .= "• **" . str_replace('_', ' ', strtoupper($k)) . ":** {$v}\n";
                }
            }
        } else {
            $finalAnswer .= "আপনার ইআরপি ডাটাবেসে মোট **{$totalCount}টি রেকর্ড** নিবন্ধিত রয়েছে।\n\n";
            if ($sampleCount > 0) {
                $finalAnswer .= format_records_table($dbData, 'generic', 'Bengali');
            }
        }
    }
}

// Update chat history using MemoryManager
if (isset($memory) && !empty($prompt) && !empty($finalAnswer)) {
    $memory->saveInteraction($prompt, $finalAnswer);
}

// Generate follow-up suggestion chips
$suggestions = !empty($retrieved['suggestions'])
    ? $retrieved['suggestions']
    : get_follow_up_suggestions($toolUsed, $prompt, $userLang);

$responsePayload = [
    'ok' => true,
    'answer' => $finalAnswer,
    'provider' => ($config['gemini_api_key'] !== '' ? 'Gemini Pro API' : 'ERP Smart RAG Engine'),
    'tool_used' => $toolUsed,
    'user_lang' => $userLang,
    'total_count' => $totalCount,
    'suggestions' => $suggestions
];

if ($commandType !== null) {
    $responsePayload['command_type'] = $commandType;
}

if (!empty($retrieved['nav_url'])) {
    $responsePayload['nav_url'] = $retrieved['nav_url'];
}

ob_end_clean(); // Ensure nothing else was printed
echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);