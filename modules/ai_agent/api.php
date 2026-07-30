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
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated. Please log in.']);
    exit;
}

$db = getDB();
$config = getAiAgentConfig();
$action = trim($_REQUEST['action'] ?? '');
$prompt = trim($_REQUEST['prompt'] ?? '');

if ($action !== 'query') {
    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

if ($prompt === '') {
    echo json_encode(['ok' => false, 'error' => 'Empty prompt query']);
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
function format_records_table(array $data, string $type = 'paper_stock', string $lang = 'English', ?int $totalCount = null, int $jumboRolls = 0, int $slittedRolls = 0): string
{
    if (empty($data))
        return '';
    $count = count($data);
    $displayCount = $totalCount ?? $count;
    $hasJumbo = $jumboRolls > 0 || $slittedRolls > 0;
    $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

    if ($type === 'paper_stock') {
        // Summary totals
        $totalMtr = 0;
        $totalSqm = 0;
        foreach ($data as $r) {
            $totalMtr += (float) ($r['length_mtr'] ?? 0);
            $totalSqm += (float) ($r['sqm'] ?? 0);
        }

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

/**
 * Call external LLM API (Google Gemini, OpenAI, OpenRouter, OpenCode, Local LLM) with fallback support
 */
function call_llm_api(string $prompt, array $config): ?string
{
    // Try primary provider first
    $primaryProvider = strtolower($config['default_provider'] ?? 'openrouter');
    $result = call_llm_api_provider($prompt, $config, $primaryProvider);
    
    // If primary succeeded, return it
    if ($result !== null && strpos($result, '[API_ERROR]') !== 0) {
        return $result;
    }
    
    // Fallback: if enabled, loop through ALL active custom endpoints
    $fallbackEnabled = !empty($config['fallback_enabled']) && $config['fallback_enabled'] === 1;
    if (!$fallbackEnabled) {
        return $result;
    }
    
    $endpointsJson = $config['ai_custom_endpoints'] ?? '[]';
    $endpoints = json_decode($endpointsJson, true) ?: [];
    
    foreach ($endpoints as $ep) {
        if (empty($ep['active'])) continue;
        
        $label = $ep['label'] ?? '';
        $url = $ep['url'] ?? '';
        $apiKey = $ep['api_key'] ?? '';
        $epModel = $ep['model'] ?? 'gpt-4o-mini';
        
        if (empty($url)) continue;
        
        // Build a model string in custom format
        $customModelStr = 'custom:' . $label . ':' . $url . ':' . $epModel;
        
        // Temporarily override config for this endpoint
        $epConfig = $config;
        $epConfig['default_provider'] = 'custom';
        $epConfig['model_name'] = $customModelStr;
        
        $epResult = call_llm_api_provider($prompt, $epConfig, 'custom');
        
        if ($epResult !== null && strpos($epResult, '[API_ERROR]') !== 0) {
            return $epResult . "\n\n*Note: Response via fallback \"" . $label . '"*';
        }
    }
    
    // All fallbacks failed, return primary error
    return $result;
}

/**
 * Call external LLM API for a specific provider
 */
function call_llm_api_provider(string $prompt, array $config, string $provider): ?string
{
    $model = $config['model_name'] ?? 'openrouter/free';
    $temperature = (float) ($config['temperature'] ?? 0.2);
    $maxTokens = (int) ($config['max_tokens'] ?? 1500);
    $systemPrompt = $config['system_prompt'] ?? "You are a helpful ERP assistant for Shree Label ERP. Be concise and accurate.";
    $systemPrompt .= "\n\nCurrent System Time: " . date('l, d F Y, h:i A');

    // 1. Google Gemini Provider
    if ($provider === 'gemini_pro' || $provider === 'gemini') {
        $apiKey = $config['gemini_api_key'] ?? '';
        if (empty($apiKey)) return null;

        $targetModel = ($model && strpos($model, 'gemini') !== false) ? $model : 'gemini-2.0-flash';
        $url = "https://generativelanguage.googleapis.com/v1beta/models/" . urlencode($targetModel) . ":generateContent?key=" . urlencode($apiKey);

        $payload = [
            "systemInstruction" => [
                "parts" => [["text" => $systemPrompt]]
            ],
            "contents" => [
                [
                    "role" => "user",
                    "parts" => [["text" => $prompt]]
                ]
            ],
            "generationConfig" => [
                "temperature" => $temperature,
                "maxOutputTokens" => $maxTokens
            ]
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 90,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            return "[API_ERROR] Connection failed ($provider): " . ($error ?: 'empty response');
        }

        // Strip SSE streaming suffixes that some proxies append (e.g. "data: [DONE]")
        $response = preg_replace('/\R?data:\s*\[DONE\]\s*$/', '', $response);
        $response = preg_replace('/\R?data:\s*.+$/', '', $response);

        $result = json_decode($response, true);
        if (isset($result['error'])) {
            $msg = is_array($result['error']) ? ($result['error']['message'] ?? json_encode($result['error'])) : $result['error'];
            return "[API_ERROR] Gemini Error: " . $msg;
        }
        if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
            return trim($result['candidates'][0]['content']['parts'][0]['text']);
        }
        return "[API_ERROR] Unexpected API response format.";
    }

    // 2. OpenAI / OpenRouter / OpenCode / Local LLM (OpenAI-compatible)
    $url = '';
    $apiKey = '';
    $headers = ['Content-Type: application/json'];

    if ($provider === 'openai') {
        $apiKey = $config['openai_api_key'] ?? '';
        $url = !empty($config['openai_api_url']) ? $config['openai_api_url'] : 'https://api.openai.com/v1/chat/completions';
        if (empty($model) || strpos($model, 'gemini') !== false) $model = 'gpt-4o-mini';
    } elseif ($provider === 'openrouter') {
        $apiKey = !empty($config['openrouter_api_key']) ? $config['openrouter_api_key'] : ($config['openai_api_key'] ?? '');
        $url = !empty($config['openrouter_ai_url']) ? $config['openrouter_ai_url'] : 'https://openrouter.ai/api/v1/chat/completions';
        if (empty($model)) $model = 'openrouter/free';
        $headers[] = 'HTTP-Referer: http://localhost';
        $headers[] = 'X-Title: Shree Label ERP AI';
    } elseif ($provider === 'opencode') {
        $apiKey = !empty($config['opencode_api_key']) ? $config['opencode_api_key'] : ($config['openai_api_key'] ?? '');
        $url = !empty($config['local_api_endpoint']) ? $config['local_api_endpoint'] : 'https://api.opencode.ai/v1/chat/completions';
        if (empty($model) || strpos($model, 'gemini') !== false) $model = 'opencode-default';
    } elseif ($provider === 'local') {
        $url = !empty($config['local_api_endpoint']) ? $config['local_api_endpoint'] : 'http://localhost:11434/v1/chat/completions';
        $apiKey = $config['openai_api_key'] ?? '';
        if (empty($model) || strpos($model, 'gemini') !== false) $model = 'llama3';
    } elseif ($provider === 'custom') {
        // Custom API Endpoint — parse model string "custom:label:url:model"
        $apiKey = '';
        $customUrl = '';
        $customModel = '';
        if (preg_match('/^custom:(.+?):(.+?):(.+)$/', $model, $m)) {
            $customLabel = $m[1];
            $customUrl = $m[2];
            $customModel = $m[3];
            // Look up API key from saved endpoints
            $endpointsJson = $config['ai_custom_endpoints'] ?? '[]';
            $endpoints = json_decode($endpointsJson, true) ?: [];
            foreach ($endpoints as $ep) {
                if (($ep['label'] ?? '') === $customLabel) {
                    $apiKey = $ep['api_key'] ?? '';
                    break;
                }
            }
        }
        $url = $customUrl;
        $model = $customModel ?: 'gpt-4o-mini';
        if (empty($url)) return null;
    }

    if (empty($url)) return null;
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => $temperature,
        'max_tokens' => $maxTokens
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 90,
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || !$response) {
        return "[API_ERROR] Connection failed ($provider): " . ($error ?: 'empty response');
    }

    // Strip SSE streaming suffixes that some proxies append (e.g. "data: [DONE]")
    $response = preg_replace('/\R?data:\s*\[DONE\]\s*$/', '', $response);
    $response = preg_replace('/\R?data:\s*.+$/', '', $response);

    $result = json_decode($response, true);
    if (isset($result['error'])) {
        $msg = is_array($result['error']) ? ($result['error']['message'] ?? json_encode($result['error'])) : $result['error'];
        return "[API_ERROR] API Error: " . $msg;
    }
    if (isset($result['choices'][0]['message']['content'])) {
        $content = trim($result['choices'][0]['message']['content']);
        if ($content !== '') {
            return $content;
        }
        // NOTE: reasoning_content is the model's internal chain-of-thought
        // and MUST NOT be returned as the user-facing response.
        // When content is empty, return empty so caller handles it gracefully.
    }

    // Also check if response has choices but in a different format
    if (isset($result['choices'][0]['message'])) {
        $msg = $result['choices'][0]['message'];
        if (is_string($msg)) return trim($msg);
        // Prefer 'content' field; never return reasoning_content as user-facing text
        if (is_array($msg)) {
            if (isset($msg['content']) && is_string($msg['content']) && trim($msg['content']) !== '') {
                return trim($msg['content']);
            }
            $text = reset($msg);
            if (is_string($text) && trim($text) !== '') {
                // Guard against reasoning_content being the first array element
                if (key($msg) === 'reasoning_content') {
                    return "[API_ERROR] Model returned only reasoning content, no response.";
                }
                return trim($text);
            }
        }
    }

    return "[API_ERROR] Unexpected API response format.";
}

/**
 * Navigation Intent Matcher
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
    } elseif (strpos($p, 'dash') !== false || strpos($p, 'home') !== false) {
        return ['name' => 'Dashboard', 'url' => $baseUrl . '/modules/dashboard/index.php'];
    }

    return null;
}

// Navigation Response Check
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


// ─── Priority Command Router (/plate, /paperstock) ───
$pTrimmed = trim(mb_strtolower($prompt, 'UTF-8'));
$userLang = detect_language($prompt); // Define userLang for global scope
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

// /plate command
if (strpos($pTrimmed, '/plate') === 0 || $pTrimmed === 'plate' || $pTrimmed === 'প্লেট' || strpos($pTrimmed, 'प्लेट') !== false) {
    $_SESSION['ai_priority_mode'] = 'plate';
    $commandType = 'plate';
    $subQuery = preg_replace('/^\/plate\s*/iu', '', trim($prompt));
    $subQuery = preg_replace('/^প্লেট\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^प्लेट\s*/u', '', $subQuery);
    $subQuery = trim(trim($subQuery), '"');

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
    $prompt = $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}

// /paperstock or /paper command
if (strpos($pTrimmed, '/paperstock') === 0 || strpos($pTrimmed, '/paper stock') === 0 || $pTrimmed === 'paperstock' || $pTrimmed === 'paper stock' || $pTrimmed === 'paper' || strpos($pTrimmed, 'পেপার স্টক') !== false || strpos($pTrimmed, 'পেপার') !== false || strpos($pTrimmed, 'पेपर स्टॉक') !== false || strpos($pTrimmed, 'पेपर') !== false) {
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
    $subQuery = trim(trim($subQuery), '"');

    if ($subQuery === '') {
        $navUrl = $baseNavUrl . '/modules/paper_stock/index.php';
        if ($userLang === 'Bengali') {
            $answer = "🎯 **পেপার স্টক পেজে অগ্রাধিকার দেওয়া হচ্ছে!**\n\n👉 [পেপার স্টক খুলুন]($navUrl)\n\n**📌 এখন থেকে সব প্রশ্নে পেপার স্টক ডাটা আগে খুঁজবে।**\n\n**আপনি যা জিজ্ঞাসা করতে পারেন:**\n• মোট কতগুলো পেপার রোল আছে?\n• Chromo / PP White / Thermal / Maplitho — কোন টাইপের কত?\n• Krishna / Austin / Navkar / NRGI — কোন কোম্পানির কত?\n• মোট রানিং মিটার কত?\n• মোট SQM কত?\n• কোন রোল Slitting-এ আছে?\n• Job Assign স্ট্যাটাসে কতটা আছে?\n• 1500mm চওড়ার রোল দেখাও";
            $suggestions = ['মোট কতগুলো পেপার রোল?', 'Chromo পেপার কত?', 'Krishna কোম্পানির রোল দেখাও', 'মোট রানিং মিটার কত?', 'PP White স্টক কত?', 'Slitting স্ট্যাটাসে কতটা?', '1500mm চওড়ার রোল দেখাও'];
        } elseif ($userLang === 'Hindi') {
            $answer = "🎯 **पेपर स्टॉक पेज को प्राथमिकता दी जा रही है!**\n\n👉 [पेपर स्टॉक खोलें]($navUrl)\n\n**📌 अब से सभी सवालों में पेपर स्टॉक डेटा पहले खोजा जाएगा।**\n\n**आप ये पूछ सकते हैं:**\n• कुल कितने पेपर रोल हैं?\n• Chromo / PP White / Thermal / Maplitho — किस टाइप के कितने?\n• Krishna / Austin / Navkar / NRGI — किस कंपनी के कितने?\n• कुल रनिंग मीटर कितना?\n• कुल SQM कितना?\n• कौन से रोल Slitting में हैं?\n• Job Assign स्टेटस में कितने हैं?\n• 1500mm चौड़ाई वाले रोल देखाओ";
            $suggestions = ['कुल कितने पेपर रोल?', 'Chromo पेपर कितने?', 'Krishna कंपनी के रोल देखाओ', 'कुल रनिंग मीटर कितना?', 'PP White स्टॉक कितना?', 'Slitting स्टेटस में कितने?', '1500mm चौड़ाई वाले रोल देखाओ'];
        } else {
            $answer = "🎯 **Prioritizing Paper Stock Page!**\n\n👉 [Open Paper Stock]($navUrl)\n\n**📌 All your queries will now search Paper Stock data first.**\n\n**You can ask about:**\n• Total roll count in stock\n• Rolls by paper type (Chromo, PP White, Thermal, Maplitho)\n• Rolls by company (Krishna, Austin, Navkar, NRGI)\n• Total running meters / total SQM\n• Rolls currently in Slitting / Job Assign status\n• Rolls by width (e.g. 1500mm wide rolls)\n• Rolls received this month / this week\n• Purchase rate / cost analysis";
            $suggestions = ['Total roll count', 'Chromo paper rolls', 'Krishna company rolls', 'Total running meters', 'PP White stock count', 'Slitting status rolls', '1500mm width rolls'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'Paper Stock Priority Command', 'user_lang' => $userLang, 'nav_url' => $navUrl, 'suggestions' => $suggestions, 'command_type' => 'paperstock'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // /paperstock <query> → rewrite prompt, fall through to normal processing (priority mode searches paper_stock first)
    $prompt = $subQuery;
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
$erpOnlyMode = false;
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
if (preg_match('/^["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]$/u', trim($prompt), $qm)) {
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
    $sStmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, status FROM paper_stock WHERE roll_no LIKE ? OR paper_type LIKE ? OR company LIKE ? OR job_name LIKE ? OR remarks LIKE ? ORDER BY id DESC LIMIT 5");
    if ($sStmt) {
        $sLike = '%' . $searchTerm . '%';
        $sStmt->bind_param('sssss', $sLike, $sLike, $sLike, $sLike, $sLike);
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
        if (!empty($jobHits)) $results['jobs'] = $jobHits;

        $plateHits = [];
        $pStmt = $db->prepare("SELECT id, sl_no, name, plate, paper_type, cylinder, make_by, die FROM master_plate_data WHERE name LIKE ? OR plate LIKE ? OR sl_no LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? ORDER BY id DESC LIMIT 5");
        if ($pStmt) {
            $pLike = '%' . $splitTerm . '%';
            $pStmt->bind_param('ssssss', $pLike, $pLike, $pLike, $pLike, $pLike, $pLike);
            $pStmt->execute();
            $plateHits = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        }
        if (!empty($plateHits)) $results['plates'] = $plateHits;

        $stockHits = [];
        $sStmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, status FROM paper_stock WHERE roll_no LIKE ? OR paper_type LIKE ? OR company LIKE ? OR job_name LIKE ? OR remarks LIKE ? ORDER BY id DESC LIMIT 5");
        if ($sStmt) {
            $sLike = '%' . $splitTerm . '%';
            $sStmt->bind_param('sssss', $sLike, $sLike, $sLike, $sLike, $sLike);
            $sStmt->execute();
            $stockHits = $sStmt->get_result()->fetch_all(MYSQLI_ASSOC) ?? [];
        }
        if (!empty($stockHits)) $results['stock'] = $stockHits;

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

// ─── Inline Quoted Term Handler ("product name" in any prompt) ───
// If the prompt contains quoted text anywhere (e.g. "blue500" price), extract it as a
// product/item name, set ERP-only mode, and search Knowledge Base for that term first.
if (!$erpOnlyMode && preg_match('/["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]/u', $prompt, $iqm)) {
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

    $res = $db->query("SELECT * FROM ai_agent_knowledge WHERE is_active = 1 ORDER BY sort_order ASC, id DESC");
    if (!$res)
        return null;

    $promptLower = mb_strtolower(trim($prompt), 'UTF-8');
    preg_match_all('/[\x{0980}-\x{09FF}\x{0900}-\x{097F}\w]+/u', $promptLower, $promptMatches);
    $kbStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'show', 'details', 'this', 'the', 'a', 'an', 'what', 'where', 'how', 'when', 'who', 'list', 'get', 'for', 'about', 'with', 'from', 'ache', 'koto', 'kotogulo', 'ki', 'kon', 'jabe', 'hote', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'হে', 'আছে', 'কত', 'কতগুলো', 'কি', 'কী', 'কোন', 'দিয়ে', 'গিয়ে', 'নাম', 'বলো', 'কোথায়', 'কোনটি', 'এবং', 'বা', 'এর', 'সেরা', 'টি', 'গুলো', 'গুলা', 'দাও', 'করো', 'করবে', 'কে', 'কেন', 'কবে', 'থেকে', 'তে', 'যে', 'ও', 'আর', 'হলো', 'হল', 'নাকি', 'তাই', 'যেন', 'তবে', 'সুতরাং', 'খুব', 'সবচেয়ে', 'কয়েকটি', 'বৈশিষ্ট্য', 'পৃথিবীর'];

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
            if ($kw === '')
                continue;

            // Direct substring match only for phrases (multiple words) to prevent single generic word triggering
            $isPhrase = (mb_strpos(trim($kw), ' ') !== false);
            if ($isPhrase && mb_strpos($promptLower, $kw) !== false) {
                $matchScore += 3.0; // Strong match for exact phrases
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

    if ($bestMatch && $bestScore >= 2.0) {
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
$kbSkipPatterns = ['how many', 'কত', 'কতগুলো', 'কতটা', 'kitne', 'kitna', 'total count', 'total rolls', 'total paper', 'stock count', 'roll count', 'paper count', 'summary', 'সারসংক্ষেপ', 'সমষ্টি', 'सारांश', 'कुल गिनती', 'date', 'time', 'ajke', 'kon date', 'today', 'tarikh', 'তারিখ', 'আজকে', 'সময়', 'somoy', 'hello', 'hi ', 'kemon acho'];
$kbSkipEntities = ['krishna', 'austin', 'nrgi', 'navkar', 'abhinav', 'nitin', 'avery', 'chromo', 'thermal', 'pp white', 'pp-clear', 'maplitho', 'metallic', 'plastic', 'flexo', 'creative', 'pidilite', 'sfl'];
$skipKB = false;
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
if (!$skipKB && preg_match('/^(view|open|show|go\s*to)\s+(plate|paper\s*stock|paper|stock|job|dispatch|dashboard)\s*(management|page)?\s*(page)?$/i', trim($prompt), $navM)) {
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

$knowledgeMatch = $skipKB ? null : check_knowledge_base($db, $prompt);
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
$isSimpleArithmetic = false;
$arithmeticResult = null;
$cleanArith = trim(preg_replace('/[+\-*\/()%\s\d.,]/', '', $prompt));
if ($cleanArith === '' && preg_match('/^[\d+\-*\/()%\s.,]+$/', trim($prompt)) && preg_match('/[+\-*\/%]/', $prompt)) {
    // Must have at least one operator and look like a calculation
    $expr = str_replace(',', '', trim($prompt));
    // Validate: only digits, basic operators, parens, spaces, dots allowed
    if (preg_match('/^[\d+\-*\/()%. ]+$/', $expr) && preg_match('/\d/', $expr)) {
        try {
            $result = @eval("return $expr;");
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

$hasCompanyQuery = preg_match('/\b(krishna|austin|navkar|nrgi)\b/i', $prompt) || strpos($p, 'কৃষ্ণা') !== false || strpos($p, 'অস্টিন') !== false || strpos($p, 'নভকার') !== false || strpos($p, 'এনআরজিআই') !== false;
$hasDbQueryIntent = preg_match('/\b(die|dies|plate|plates|stock|inventory|search|find|any|is there|kono|ache)\b/i', $prompt);

$isMathIntent = !$hasCompanyQuery && !$hasDbQueryIntent && (
    preg_match('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/i', $prompt) ||
    strpos($p, 'running meter') !== false ||
    strpos($p, 'running mtr') !== false ||
    (strpos($p, 'ups') !== false && strpos($p, 'gap') !== false)
);

// ─── Simple mm² → Square Inch Area Conversion ───
$mmAreaMatch = [];
if (preg_match('/(\d+(?:\.\d+)?)\s*mm\s*[xX*]\s*(\d+(?:\.\d+)?)\s*mm/i', $prompt, $mmAreaMatch)) {
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

if ($isMathIntent) {
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
    $p = mb_strtolower($prompt, 'UTF-8');
    $data = [];
    $totalCount = 0;
    $totalMeters = 0;
    $filteredType = '';
    $isCompanyList = false;
    $toolName = 'ERP Knowledge Engine';
    $searchNums = extract_search_numbers($prompt);

    // ─── SESSION PRIORITY MODE: Search priority source FIRST ───
    $priorityMode = $_SESSION['ai_priority_mode'] ?? '';

    // PRIORITY: Paper Stock — search first if priority mode active
    if ($priorityMode === 'paperstock') {
        $isOtherModuleQuery = (strpos($p, 'live') !== false || strpos($p, 'floor') !== false || strpos($p, 'plate') !== false || strpos($p, 'plate') !== false || strpos($p, 'die') !== false || strpos($p, 'anilox') !== false || strpos($p, 'job') !== false || strpos($p, 'planning') !== false || strpos($p, 'dispatch') !== false || strpos($p, 'slit') !== false || strpos($p, 'finished') !== false || strpos($p, 'packing') !== false || strpos($p, 'লাইভ') !== false || strpos($p, 'ফ্লোর') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'ডাই') !== false || strpos($p, 'জব') !== false);
        if ($isOtherModuleQuery) {
            unset($_SESSION['ai_priority_mode']);
            $priorityMode = '';
        }
        $isPaperQuery = !$isOtherModuleQuery && (strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'slc/') !== false || strpos($p, 'chromo') !== false || strpos($p, 'thermal') !== false || strpos($p, 'stock') !== false || strpos($p, 'maplitho') !== false || strpos($p, 'pp') !== false || strpos($p, 'white') !== false || strpos($p, 'jumbo') !== false || strpos($p, 'avery') !== false || strpos($p, 'krishna') !== false || strpos($p, 'austin') !== false || strpos($p, 'navkar') !== false || strpos($p, 'nrgi') !== false || strpos($p, 'company') !== false || strpos($p, 'কোম্পানি') !== false || strpos($p, 'স্টক') !== false || strpos($p, 'রোল') !== false || strpos($p, 'কত') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'summary') !== false || strpos($p, 'breakdown') !== false || strpos($p, 'metro') !== false || strpos($p, 'sqm') !== false || strpos($p, 'running') !== false || strpos($p, 'status') !== false);
        if ($isPaperQuery) {
            $pToolName = 'Paper Stock Master Tool (Priority)';
            $pWhere = ["LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')"];
            $pParams = [];
            $pTypes = '';
            $pRollNo = null;
            $pCompany = null;
            $pType = null;
            $pWidth = null;
            if (preg_match('/(slc\/\d{4}\/\d+|\d{4})/i', $prompt, $m)) {
                $pRollNo = $m[1];
            }
            foreach (['nrgi', 'krishna', 'austin', 'navkar', 'abhinav', 'raj paper', 'mangalam', 'paper n more', 'narsing das', 'avery', 'nitin'] as $c) {
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
            if (preg_match('/(\d{3,4})\s*mm/i', $prompt, $m)) {
                $pWidth = (float) $m[1];
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
                } else {
                    $pWhere[] = "paper_type LIKE ?";
                    $pParams[] = '%' . $pType . '%';
                    $pTypes .= 's';
                }
            }
            if ($pWidth) {
                $pWhere[] = "width_mm = ?";
                $pParams[] = $pWidth;
                $pTypes .= 'd';
            }
            $pWhereSql = implode(' AND ', $pWhere);
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
                if ($pCompany && $pType) {
                    $pTitle = strtoupper($pCompany . ' ' . $pType);
                } elseif ($pCompany) {
                    $pTitle = strtoupper($pCompany) . ' Paper Stock';
                } elseif ($pType) {
                    $pTitle = strtoupper($pType) . ' Paper Stock';
                }
                $pDirectAnswer = "📜 **{$pTitle}** — Found **{$totalCount} rolls**:\n\n" . format_records_table($data, 'paper_stock', $userLang, $totalCount, $jumboRolls, $slittedRolls);
                return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => $totalMeters, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $pDirectAnswer, 'data' => []];
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
        $isPlateQuery = !$isOtherModuleQueryPlate && (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false || strpos($p, 'die') !== false || strpos($p, 'cylinder') !== false || strpos($p, 'ups') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'কত') !== false || strpos($p, 'কতগুলো') !== false || strpos($p, 'kitne') !== false);
        if ($isPlateQuery) {
            $pToolName = 'Printing Plates Master Tool (Priority)';
            $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
            $pTotalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
            if ($pTotalCount > 0) {
                $toolName = $pToolName;
                $totalCount = $pTotalCount;
                $pStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'plate', 'plates', 'list', 'show', 'details', 'detail', 'this', 'the', 'a', 'an', 'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does', 'repeat', 'gap', 'gaph', 'gapv', 'size', 'ups', 'cylinder', 'paper', 'die', 'core', 'rewinding', 'value', 'color', 'colors', 'spec', 'special', 'what', 'how', 'give', 'if', 'run', 'running', 'much', 'many', 'quantity', 'qty', 'meter', 'meters', 'mtr', 'will', 'be', 'produced', 'print', 'printing', 'require', 'required', 'need', 'needed', 'or', 'and', 'calculate', 'calculating', 'calc', 'length', 'roll', 'pcs', 'pieces', 'labels', 'koto', 'kotogulo', 'hobe', 'lagbe', 'korle', 'korte', 'asob', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'হবে', 'আছে', 'কত', 'কতগুলো', 'কি', 'কী', 'কোন'];
                $pWords = preg_split('/\s+/', strtolower($prompt));
                $pTerms = [];
                foreach ($pWords as $w) {
                    $wC = trim(preg_replace('/[^a-z0-9]/', '', $w));
                    if ($wC !== '' && !in_array($wC, $pStopwords, true) && strlen($wC) >= 2) {
                        if (is_numeric($wC) && (float) $wC >= 100 && !preg_match('/(ml|mm|cm|inc|inch)/i', $w)) {
                            continue;
                        }
                        $pTerms[] = $wC;
                    }
                }
                $pSearchTerm = implode('%', $pTerms);
                if (!empty($searchNums)) {
                    foreach ($searchNums as $num) {
                        $pStmt = $db->prepare("SELECT * FROM master_plate_data WHERE sl_no = ? OR id = ? OR plate = ? ORDER BY id DESC LIMIT 5");
                        $pStmt->bind_param('sss', $num, $num, $num);
                        $pStmt->execute();
                        $pData = $pStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                        if (!empty($pData)) {
                            $data = $pData;
                            break;
                        }
                    }
                }
                if (empty($data) && !empty($pTerms)) {
                    $pLike = '%' . $pSearchTerm . '%';
                    $pStmt2 = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? OR plate LIKE ? OR sl_no = ? OR id = ? ORDER BY id DESC LIMIT 10");
                    $pStmt2->bind_param('sssssss', $pLike, $pLike, $pLike, $pLike, $pLike, $pSearchTerm, $pSearchTerm);
                    $pStmt2->execute();
                    $data = $pStmt2->get_result()->fetch_all(MYSQLI_ASSOC);
                }
                if (empty($data)) {
                    $pFallback = $db->query("SELECT * FROM master_plate_data ORDER BY id DESC LIMIT 15");
                    $data = $pFallback ? $pFallback->fetch_all(MYSQLI_ASSOC) : [];
                }
                if (!empty($data)) {

                    $pDirectAnswer = "📐 **Printing Plates** — Found **{$totalCount} plates**:\n\n" . format_records_table($data, 'plate', $userLang);
                    return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $pDirectAnswer, 'data' => []];
                }
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
        if (strpos($p, 'কৃষ্ণা') !== false) $compName = 'KRISHNA';
        elseif (strpos($p, 'অস্টিন') !== false) $compName = 'AUSTIN';
        elseif (strpos($p, 'নভকার') !== false) $compName = 'NAVKAR';
        elseif (strpos($p, 'এনআরজিআই') !== false) $compName = 'NRGI';
        elseif (strpos($p, 'নিতিন') !== false) $compName = 'NITIN';
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

    if ((!empty($compName) || !empty($paperTypeFilter)) && !$hasToolIntent) {
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
    } elseif (strpos($p, 'dispatch') !== false || strpos($p, 'dispatched') !== false || strpos($p, 'ready queue') !== false || strpos($p, 'ready stock') !== false || strpos($p, 'challan') !== false || strpos($p, 'sales person') !== false || strpos($p, 'ডিস্পैচ') !== false || strpos($p, 'রেডি') !== false) {
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
    } elseif (strpos($p, 'mixed') !== false || strpos($p, 'extra pool') !== false || strpos($p, 'repack') !== false || strpos($p, 'মিক্সড') !== false || strpos($p, 'এক্সট্রা') !== false) {

        $toolName = 'Mixed Item Inventory Master Tool';

        $cntRes = $db->query("SELECT COUNT(*) as cnt, IFNULL(SUM(quantity),0) as total_extra FROM finished_goods_stock WHERE category IN ('pos_paper_roll','one_ply','two_ply','barcode','printing_roll','printing_label')");
        $sumRow = $cntRes ? $cntRes->fetch_assoc() : [];
        $totalItems = (int) ($sumRow['cnt'] ?? 0);
        $totalExtraQty = (float) ($sumRow['total_extra'] ?? 0);

        $assignRes = $db->query("SELECT COUNT(*) as cnt FROM mixed_item_assignments WHERE status = 'pending'");
        $pendingAssign = $assignRes ? (int) ($assignRes->fetch_assoc()['cnt'] ?? 0) : 0;

        $rows = $db->query("SELECT id, category, sub_type, item_name, item_code, size, quantity, unit, batch_no, date, remarks FROM finished_goods_stock WHERE category IN ('pos_paper_roll','one_ply','two_ply','barcode','printing_roll','printing_label') ORDER BY id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);


        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "🔀 **Mixed Item & Extra Production Pool — Live Inventory Summary:**\n\n"
                . "📊 **Pool Metrics Summary:**\n"
                . "  - Total Extra Pool Batches: **" . number_format($totalItems) . " Items**\n"
                . "  - Total Extra Stock Quantity: **" . number_format($totalExtraQty) . " PCS / Rolls**\n"
                . "  - Pending Handover Assignments: **" . number_format($pendingAssign) . " Assignments** (Target: Packing / Planning / Repack)\n\n"
                . "🔀 **Active Mixed Extra Items Grid:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **Extra Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}** | Category: **" . strtoupper($r['category']) . "**)\n"
                    . "  - 📐 **Size & Spec:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **Extra Quantity:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
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

            $answer .= "👉 [मिक्सड आइटम पेज खोलें]({$baseUrl}/modules/inventory/mixed-item/index.php)";
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

            $answer .= "👉 [মিক্সড আইটেম পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/inventory/mixed-item/index.php)";
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
            'data' => []
        ];
    }



    // 4. Live Production Floor Intent Matcher

    if (strpos($p, 'live') !== false || strpos($p, 'life') !== false || strpos($p, 'floor') !== false || strpos($p, 'stage') !== false || strpos($p, 'next department') !== false || strpos($p, 'journey') !== false || strpos($p, 'current job') !== false || strpos($p, 'running job') !== false || strpos($p, 'লাইভ') !== false || strpos($p, 'ফ্লোর') !== false) {
        $toolName = 'Live Production Floor Pipeline Tool';

        $liveStopwords = ['can', 'you', 'what', 'is', 'the', 'status', 'of', 'current', 'job', 'jobs', 'running', 'live', 'life', 'floor', 'show', 'tell', 'me', 'details', 'for', 'in', 'page', 'pages', 'summary', 'sumary', 'overview', 'list', 'all', 'production', 'pipeline', 'report', 'reports', 'board', 'kholo', 'khul', 'open', 'go', 'to', 'dekhao', 'dekaw', 'batao', 'give', 'bring', 'find', 'search', 'how', 'many', 'are', 'there', 'please', 'pls', 'lookup', 'display', 'and', 'or', 'about', 'with', 'this', 'that', 'get', 'fetch', 'on', 'at', 'from', 'a', 'an', 'ki', 'kya', 'hai', 'ami', 'tumi', 'do', 'we', 'have', 'any', 'my'];
        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9]/', '', $w));
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
    } elseif (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false || ((strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false || strpos($p, 'কত') !== false) && (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || preg_match('/\b(run|paper)\b/', $p)))) {

        $toolName = 'Printing Plates Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
        $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
        // Detect if query is asking for plate calculation (meters ↔ quantity)
        $isCalcQuery = (strpos($p, 'calculat') !== false || strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || preg_match('/\b(run|paper)\b/', $p));

        // --- Count-Only Intent Handler ---
        if (is_count_intent($prompt) && !$isCalcQuery) {

            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            // Fetch breakdown stats
            $dieBreak = $db->query("SELECT die, COUNT(*) as cnt FROM master_plate_data WHERE die IS NOT NULL AND die != '' GROUP BY die ORDER BY cnt DESC");
            $dieStats = $dieBreak ? $dieBreak->fetch_all(MYSQLI_ASSOC) : [];
            $makerBreak = $db->query("SELECT make_by, COUNT(*) as cnt FROM master_plate_data WHERE make_by IS NOT NULL AND make_by != '' GROUP BY make_by ORDER BY cnt DESC LIMIT 5");
            $makerStats = $makerBreak ? $makerBreak->fetch_all(MYSQLI_ASSOC) : [];
            $latestRes = $db->query("SELECT name, sl_no, date_received FROM master_plate_data ORDER BY id DESC LIMIT 1");
            $latest = $latestRes ? $latestRes->fetch_assoc() : null;

            if ($userLang === 'Hindi') {
                $directAnswer = "📊 **प्रिंटिंग प्लेट — स्टॉक डैशबोर्ड**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **कुल प्लेट:** **{$totalCount}**\n\n";
                if (!empty($dieStats)) {
                    $directAnswer .= "📐 **प्रकार के अनुसार (Die Type):**\n";
                    foreach ($dieStats as $ds) {
                        $directAnswer .= "   ▸ **" . ($ds['die'] ?: 'अन्य') . "** — {$ds['cnt']} प्लेट\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($makerStats)) {
                    $directAnswer .= "🏭 **निर्माता / सप्लायर:**\n";
                    foreach ($makerStats as $ms) {
                        $directAnswer .= "   ▸ **{$ms['make_by']}** — {$ms['cnt']} प्लेट\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "🆕 **सबसे हालिया प्लेट:** `{$latest['name']}` (SL: {$latest['sl_no']})";
                    if (!empty($latest['date_received']) && $latest['date_received'] !== 'NA') {
                        $directAnswer .= " — प्राप्त: {$latest['date_received']}";
                    }
                    $directAnswer .= "\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [प्लेट प्रबंधन पेज खोलें]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } elseif ($userLang === 'Bengali' || preg_match('/[\x{0980}-\x{09FF}]/u', $prompt) || preg_match('/\b(koto|kotogulo|ache)\b/i', $prompt)) {
                $directAnswer = "📊 **প্রিন্টিং প্লেট — স্টক ড্যাশবোর্ড**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **মোট প্লেট:** **{$totalCount}টি**\n\n";
                if (!empty($dieStats)) {
                    $directAnswer .= "📐 **ধরণ অনুযায়ী (Die Type):**\n";
                    foreach ($dieStats as $ds) {
                        $directAnswer .= "   ▸ **" . ($ds['die'] ?: 'অন্যান্য') . "** — {$ds['cnt']}টি প্লেট\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($makerStats)) {
                    $directAnswer .= "🏭 **নির্মাতা / সাপ্লায়ার:**\n";
                    foreach ($makerStats as $ms) {
                        $directAnswer .= "   ▸ **{$ms['make_by']}** — {$ms['cnt']}টি প্লেট\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "🆕 **সর্বশেষ প্লেট:** `{$latest['name']}` (SL: {$latest['sl_no']})";
                    if (!empty($latest['date_received']) && $latest['date_received'] !== 'NA') {
                        $directAnswer .= " — প্রাপ্ত: {$latest['date_received']}";
                    }
                    $directAnswer .= "\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [প্লেট ম্যানেজমেন্ট পেজ খুলুন]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } else {
                $directAnswer = "📊 **Printing Plates — Stock Dashboard**\n"
                    . "━━━━━━━━━━━━━━━━━━━━━━\n\n"
                    . "🔢 **Total Plates:** **{$totalCount}**\n\n";
                if (!empty($dieStats)) {
                    $directAnswer .= "📐 **By Die Type:**\n";
                    foreach ($dieStats as $ds) {
                        $directAnswer .= "   ▸ **" . ($ds['die'] ?: 'Other') . "** — {$ds['cnt']} plates\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($makerStats)) {
                    $directAnswer .= "🏭 **By Maker / Supplier:**\n";
                    foreach ($makerStats as $ms) {
                        $directAnswer .= "   ▸ **{$ms['make_by']}** — {$ms['cnt']} plates\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "🆕 **Latest Plate Added:** `{$latest['name']}` (SL: {$latest['sl_no']})";
                    if (!empty($latest['date_received']) && $latest['date_received'] !== 'NA') {
                        $directAnswer .= " — Received: {$latest['date_received']}";
                    }
                    $directAnswer .= "\n\n";
                }
                $directAnswer .= "━━━━━━━━━━━━━━━━━━━━━━\n"
                    . "👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
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


        // Extract text search words (filtering out attribute query words & calculation filler terms)
        $stopwords = [
            'to',
            'qnty',
            'can',
            'you',
            'tell',
            'me',
            'which',
            'is',
            'in',
            'my',
            'plate',
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
            'কোন'
        ];


        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9]/', '', $w));
            if ($wClean !== '' && !in_array($wClean, $stopwords, true) && strlen($wClean) >= 2) {
                // Skip standalone calculation numbers (e.g. 2000, 5000, 1500) from plate name search
                if (is_numeric($wClean) && (float) $wClean >= 100 && !preg_match('/(ml|mm|cm|inc|inch)/i', $w)) {
                    continue;
                }
                $terms[] = $wClean;
            }
        }
        $searchTerm = implode('%', $terms);
        $searchTermDisplay = implode(' ', $terms);

        // 1. Try search by explicit SL No / ID number first if numbers are present (e.g., Plate 1032)
        if (!empty($searchNums)) {
            foreach ($searchNums as $num) {
                $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE sl_no = ? OR id = ? OR plate = ? ORDER BY id DESC LIMIT 5");
                $stmt->bind_param('sss', $num, $num, $num);
                $stmt->execute();
                $resData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                if (!empty($resData)) {
                    $data = $resData;
                    break;
                }
            }
        }

        if (empty($data) && !empty($terms)) {
            // 2. Search across name, paper_type, make_by, and die
            $like = '%' . $searchTerm . '%';
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? OR plate LIKE ? OR sl_no = ? OR id = ? ORDER BY id DESC LIMIT 10");
            $stmt->bind_param('sssssss', $like, $like, $like, $like, $like, $searchTerm, $searchTerm);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // 3. If empty, try matching individual terms
            if (empty($data) && count($terms) > 1) {
                $likes = array_map(function ($t) {
                    return '%' . $t . '%';
                }, $terms);
                $whereParts = [];
                foreach ($likes as $l) {
                    $whereParts[] = "(name LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ?)";
                }
                $whereClause = implode(' AND ', $whereParts);
                $stmt2 = $db->prepare("SELECT * FROM master_plate_data WHERE {$whereClause} ORDER BY id DESC LIMIT 10");
                $flatParams = [];
                $types = '';
                foreach ($likes as $l) {
                    $flatParams[] = $l;
                    $flatParams[] = $l;
                    $flatParams[] = $l;
                    $flatParams[] = $l;
                    $types .= 'ssss';
                }
                $stmt2->bind_param($types, ...$flatParams);
                $stmt2->execute();
                $data = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }

        // Calculation Handler (Meters to Quantity OR Quantity to Meters)
        // $isCalcQuery is defined above

        if ($isCalcQuery && !empty($data)) {
            $plate = $data[0];
            $name = $plate['name'];
            $ups = max(1, (int) $plate['ups']);
            $repeatVal = (float) $plate['repeat_value'];

            if ($repeatVal <= 0) {
                if (preg_match('/x\s*([\d\.]+)/i', $plate['size'] ?? '', $m)) {
                    $height = (float) $m[1];
                    $gapV = (float) ($plate['gap_v'] ?? 0);
                    $repeatVal = $height + $gapV;
                } else {
                    $repeatVal = 100.0;
                }
            }

            $targetQty = null;
            $paperMeters = null;

            if (preg_match('/([\d,]+)\s*(meters|meter|mtr|m)\b/i', $prompt, $m)) {
                $paperMeters = (float) str_replace(',', '', $m[1]);
            } elseif (preg_match('/(run|length|roll|paper|quantity|qty|qnty|pcs|pices|pieces|labels|required)\s*(of|about|with)?\s*([\d,]+)/i', $prompt, $m)) {
                $targetQty = (float) str_replace(',', '', $m[3]);
            }

            if ($targetQty === null && $paperMeters === null && count($searchNums) >= 2) {
                // If two numbers present (e.g. Plate 1032 with 15000 qty)
                $numCandidate = (float) $searchNums[count($searchNums) - 1];
                if (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'run') !== false) {
                    $paperMeters = $numCandidate;
                } else {
                    $targetQty = $numCandidate;
                }
            } elseif ($targetQty === null && $paperMeters === null && !empty($searchNums)) {
                $num = (float) $searchNums[0];
                if ($num != (float) $plate['sl_no'] && $num != (float) $plate['id']) {
                    if (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'run') !== false) {
                        $paperMeters = $num;
                    } else {
                        $targetQty = $num;
                    }
                }
            }


            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            if ($userLang === 'English') {
                $answer = "📐 **Flexo Printing & Plate Production Calculator:**\n\n"
                    . "📋 **Job / Plate Name:** `{$name}` (SL No: **{$plate['sl_no']}** | ID: **{$plate['id']}**)\n"
                    . "⚙️ **Plate Specifications:** Ups: **{$ups}** | Repeat Value: **{$repeatVal}mm** | Size: **{$plate['size']}**\n\n";

                if ($targetQty !== null && $targetQty > 0) {
                    $revs = $targetQty / $ups;
                    $rawMeters = ($revs * $repeatVal) / 1000.0;
                    $wastageMeters = $rawMeters * 1.05;
                    $answer .= "🎯 **Target Quantity:** **" . number_format($targetQty) . " pcs**\n"
                        . "📏 **Net Paper Length Required:** **" . number_format($rawMeters, 2) . " meters**\n"
                        . "🛡️ **Total Paper (with 5% setup wastage):** **" . number_format($wastageMeters, 2) . " meters**\n\n";
                }

                if ($paperMeters !== null && $paperMeters > 0) {
                    $totalMM = $paperMeters * 1000.0;
                    $revs = $totalMM / $repeatVal;
                    $totalQty = floor($revs * $ups);
                    $answer .= "📜 **Paper Roll Run Length:** **" . number_format($paperMeters, 2) . " meters**\n"
                        . "📦 **Expected Label Production Output:** **" . number_format($totalQty) . " pcs / labels**\n\n";
                }

                $answer .= "👉 [Click here to open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } elseif ($userLang === 'Hindi') {
                $answer = "📐 **फ्लेक्सो प्रिंटिंग और प्लेट उत्पादन कैलकुलेटर:**\n\n"
                    . "📋 **जॉब / प्लेट का नाम:** `{$name}` (SL No: **{$plate['sl_no']}**)\n"
                    . "⚙️ **प्लेट विवरण:** Ups: **{$ups}** | Repeat Value: **{$repeatVal}mm** | Size: **{$plate['size']}**\n\n";

                if ($targetQty !== null && $targetQty > 0) {
                    $revs = $targetQty / $ups;
                    $rawMeters = ($revs * $repeatVal) / 1000.0;
                    $wastageMeters = $rawMeters * 1.05;
                    $answer .= "🎯 **लक्ष्य मात्रा (Target Quantity):** **" . number_format($targetQty) . " पीस**\n"
                        . "📏 **आवश्यक कागज (Net Paper Needed):** **" . number_format($rawMeters, 2) . " मीटर**\n"
                        . "🛡️ **कुल कागज (5% वेस्टेज सहित):** **" . number_format($wastageMeters, 2) . " मीटर**\n\n";
                }

                if ($paperMeters !== null && $paperMeters > 0) {
                    $totalMM = $paperMeters * 1000.0;
                    $revs = $totalMM / $repeatVal;
                    $totalQty = floor($revs * $ups);
                    $answer .= "📜 **पेपर रोल की लंबाई:** **" . number_format($paperMeters, 2) . " मीटर**\n"
                        . "📦 **कुल उत्पादन मात्रा:** **" . number_format($totalQty) . " पीस / लेबल**\n\n";
                }

                $answer .= "👉 [प्लेट प्रबंधन पेज खोलें]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } else {
                $answer = "📐 **ফ্লেক্সো প্রিন্টিং ও প্লেট উত্পাদন ক্যালকুলেটর:**\n\n"
                    . "📋 **জব / প্লেটের নাম:** `{$name}` (SL No: **{$plate['sl_no']}** | ID: **{$plate['id']}**)\n"
                    . "⚙️ **প্লেট বিবরণ:** আফস (Ups): **{$ups}** | রিপিট ভ্যালু: **{$repeatVal}mm** | সাইজ: **{$plate['size']}**\n\n";

                if ($targetQty !== null && $targetQty > 0) {
                    $revs = $targetQty / $ups;
                    $rawMeters = ($revs * $repeatVal) / 1000.0;
                    $wastageMeters = $rawMeters * 1.05;
                    $answer .= "🎯 **টার্গেট কোয়ান্টিটি (Target Quantity):** **" . number_format($targetQty) . "টি**\n"
                        . "📏 **আবশ্যক কাগজ (Net Paper Needed):** **" . number_format($rawMeters, 2) . " মিটার**\n"
                        . "🛡️ **কুল কাগজ (5% ওয়েস্টেজ সহিত):** **" . number_format($wastageMeters, 2) . " মিটার**\n\n";
                }

                if ($paperMeters !== null && $paperMeters > 0) {
                    $totalMM = $paperMeters * 1000.0;
                    $revs = $totalMM / $repeatVal;
                    $totalQty = floor($revs * $ups);
                    $answer .= "📜 **পেপার রোলের দৈর্ঘ্য (Paper Roll Length):** **" . number_format($paperMeters, 2) . " মিটার**\n"
                        . "📦 **কুল উত্পাদন মাত্রা:** **" . number_format($totalQty) . "টি লেবেল/পিস**\n\n";
                }

                $answer .= "👉 [প্লেট ম্যানেজমেন্ট পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            }

            $navUrl = null;
            if (strpos($p, 'open') !== false || strpos($p, 'page') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false) {
                $navUrl = $baseUrl . '/modules/plate-tools/plate-management/index.php';
            }

            return [
                'tool_used' => 'Flexo Label Production Calculator Tool',
                'total_count' => 1,
                'total_meters' => $paperMeters ?: 0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => $answer,
                'nav_url' => $navUrl,
                'data' => []
            ];
        }


        if (empty($data) && !empty($searchNums)) {
            $num = $searchNums[0];
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE sl_no = ? OR id = ? OR plate = ? ORDER BY id DESC LIMIT 5");
            $like = '%' . $num . '%';
            $stmt->bind_param('sss', $like, $num, $num);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        // If user searched for a specific term and 0 records were found
        if (empty($data) && !empty($terms)) {

            if ($userLang === 'English') {
                $directAnswer = "❌ **No Printing Plate Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Plate database, but no plate record matching **\"{$searchTermDisplay}\"** was found.\n\n"
                    . "💡 **Tip:** Please verify if the plate name or SL No is spelled correctly.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" नाम का कोई प्लेट नहीं मिला**\n\n"
                    . "आपके ईआरपी डेटाबेस में **\"{$searchTermDisplay}\"** का कोई रिकॉर्ड उपलब्ध नहीं है।";
            } else {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" নামে কোনো প্লেট পাওয়া যায়নি**\n\n"
                    . "আপনার ইআরপি মাস্টার ডাটাবেসে **\"{$searchTermDisplay}\"** নামে কোনো প্লেট রেকর্ড নিবন্ধিত নেই।";
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
            $res = $db->query("SELECT id, sl_no, name, size, ups, paper_type, die, date_received FROM master_plate_data ORDER BY id DESC LIMIT 15");
            $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
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

    } elseif (strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'slc/') !== false || strpos($p, 'chromo') !== false || strpos($p, 'thermal') !== false || strpos($p, 'stock') !== false || strpos($p, 'maplitho') !== false || strpos($p, 'pp') !== false || strpos($p, 'white') !== false || strpos($p, 'jumbo') !== false || strpos($p, 'slitting') !== false || strpos($p, 'avery') !== false || strpos($p, 'krishna') !== false || strpos($p, 'austin') !== false || strpos($p, 'navkar') !== false || strpos($p, 'nrgi') !== false) {

        $toolName = 'Paper Stock Master Tool';

        // 1. Roll No match
        $rollNoMatch = null;
        if (preg_match('/(slc\/\d{4}\/\d+|\d{4})/i', $prompt, $m)) {
            $rollNoMatch = $m[1];
        }

        // 2. Company match
        $companies = ['nrgi', 'krishna', 'austin', 'navkar', 'abhinav', 'raj paper', 'mangalam', 'paper n more', 'narsing das', 'avery', 'nitin'];
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

        // 4. Width match
        $widthMm = null;
        if (preg_match('/(\d{3,4})\s*mm/i', $prompt, $m)) {
            $widthMm = (float) $m[1];
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
            } else {
                $where[] = "paper_type LIKE ?";
                $params[] = '%' . $matchedType . '%';
                $typesStr .= 's';
            }
        }


        if ($widthMm) {
            $where[] = "width_mm = ?";
            $params[] = $widthMm;
            $typesStr .= 'd';
        }

        $whereSql = implode(' AND ', $where);

        $summarySql = "SELECT COUNT(*) as roll_count, IFNULL(SUM(length_mtr),0) as total_mtr, IFNULL(SUM((width_mm/1000.0)*length_mtr),0) as total_sqm FROM paper_stock WHERE {$whereSql}";
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
        if ($matchedCompany && $matchedType) {
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
    } elseif (preg_match('/\b(job|jobs|card|cards|order|orders|flx|lsl|jmb|pck|brc|status|progress|work)\b/i', $prompt)) {
        $toolName = 'ERP Jobs & Planning Tool';

        // Extract search term from prompt
        $jobStopwords = ['can', 'you', 'of', 'give', 'me', 'the', 'detail', 'details', 'about', 'job', 'jobs', 'name', 'named', 'by', 'how', 'many', 'we', 'have', 'is', 'are', 'in', 'show', 'tell', 'list', 'find', 'search', 'for', 'a', 'an', 'what', 'which', 'please', 'pls', 'lookup', 'display', 'and', 'or', 'about', 'with', 'this', 'that', 'get', 'fetch', 'on', 'at', 'from', 'a', 'an', 'ki', 'kya', 'hai', 'ami', 'tumi', 'do', 'we', 'have', 'any', 'my'];
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
        $unmatchedStopwords = ['can', 'you', 'give', 'me', 'the', 'details', 'about', 'please', 'show', 'tell', 'find', 'search', 'for', 'a', 'an', 'what', 'which', 'is', 'are', 'in', 'of', 'to', 'do', 'has', 'have'];
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
            $like = '%' . $searchTerm . '%';
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? ORDER BY id DESC LIMIT 5");
            $stmt->bind_param('s', $like);
            $stmt->execute();
            $plateData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            if (!empty($plateData)) {

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
$retrieved = fetch_erp_data_by_intent($db, $prompt, $userLang);
$toolUsed = $retrieved['tool_used'];
$totalCount = $retrieved['total_count'];
$totalMeters = $retrieved['total_meters'];
$filteredType = $retrieved['filtered_type'];
$isCompanyList = $retrieved['is_company_list'];
$dbData = $retrieved['data'];
$sampleCount = count($dbData);

if (!empty($retrieved['direct_answer'])) {
    $finalAnswer = $retrieved['direct_answer'];
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
    // ERP-Only Mode: skip external LLM, go straight to no-knowledge message
    if ($erpOnlyMode) {
        $llmAnswer = null;
    } else {
        $llmAnswer = call_llm_api($prompt, $config);
    }
    if ($llmAnswer !== null && strpos($llmAnswer, '[API_ERROR]') !== 0) {
        $finalAnswer = $llmAnswer;
        $toolUsed = '';
    } else {
        if ($userLang === 'English') {
            $finalAnswer = "🤔 **I don't have knowledge regarding this topic.**\n\n"
                . "I searched my trained knowledge base and ERP data, but couldn't find an answer to: **\"" . htmlspecialchars($prompt) . "\"**.\n\n"
                . (isAdmin()
                    ? "💡 **Admin Tip:** You can train me in **Settings → AI Agent → Knowledge Base** by clicking **\"+ Add New Entry\"** with keywords and an answer.\n\n"
                    : "")
                . "👨‍💼 **For help, please contact your ERP Administrator.**";
        } elseif ($userLang === 'Hindi') {
            $finalAnswer = "🤔 **मुझे इस विषय के बारे में कोई जानकारी नहीं है।**\n\n"
                . "मैंने अपने ट्रेन्ड नॉलेज बेस और ईआरपी डेटा में खोजा, लेकिन आपकी क्वेरी: **\"" . htmlspecialchars($prompt) . "\"** का उत्तर नहीं मिला।\n\n"
                . (isAdmin()
                    ? "💡 **एडमिन टिप:** आप **Settings → AI Agent → Knowledge Base** में जाकर **\"+ Add New Entry\"** से मुझे ट्रेन कर सकते हैं।\n\n"
                    : "")
                . "👨‍💼 **कृपया सहायता के लिए अपने ERP एडमिनिस्ट्रेटर से संपर्क करें।**";
        } else {
            $finalAnswer = "🤔 **এই বিষয়ে আমার কোনো জ্ঞান নেই।**\n\n"
                . "আমি আমার ট্রেইনড নলেজ বেস এবং ইআরপি ডেটাতে খুঁজেছি, কিন্তু আপনার প্রশ্ন: **\"" . htmlspecialchars($prompt) . "\"** এর উত্তর পাইনি।\n\n"
                . (isAdmin()
                    ? "💡 **এডমিন পরামর্শ:** আপনি **Settings → AI Agent → Knowledge Base** এ গিয়ে **\"+ Add New Entry\"** ক্লিক করে কীওয়ার্ড ও উত্তর যোগ করে আমাকে ট্রেইন করতে পারেন।\n\n"
                    : "")
                . "👨‍💼 **সাহায্যের জন্য আপনার ERP অ্যাডমিনিস্ট্রেটরের সাথে যোগাযোগ করুন।**";
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

echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);