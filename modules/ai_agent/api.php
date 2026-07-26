<?php
// ============================================================
// Standalone AI Agent Add-On Module — API Engine (Multilingual & Industrial Label Math)
// ERP Master System — 100% Isolated Add-On Module
// LOCAL USE ONLY — SAFE: Read-Only Data Connectors for ERP Tables
// ============================================================

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
function detect_language(string $prompt): string {
    $reqLang = trim($_REQUEST['user_lang'] ?? '');
    if ($reqLang === 'English' || $reqLang === 'Bengali' || $reqLang === 'Hindi') {
        return $reqLang;
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
    if (preg_match('/\b(koto|kotogulo|ache|kono|kivabe|korbo|bhai|bhalo|bolun|din|kon|lagbe|hobe|dam|seba|seta|amar|amake|dekhaw|bolte)\b/i', $p)) {
        return 'Bengali';
    }

    // 4. Hinglish Keywords Detection
    if (preg_match('/\b(kitne|kitna|hai|kaise|karo|dekho|batao|kya|mujhe|bataye)\b/i', $p)) {
        return 'Hindi';
    }

    // Default to English
    return 'English';
}


/**
 * Extract Numbers / Specific Identifiers from Prompt
 */
function extract_search_numbers(string $prompt): array {
    preg_match_all('/\b\d+\b/', $prompt, $matches);
    return $matches[0] ?? [];
}

/**
 * Navigation Intent Matcher
 */
function check_navigation_intent(string $prompt): ?array {
    $p = strtolower($prompt);
    $isDataQuery = strpos($p, 'check') !== false || strpos($p, 'stage') !== false || strpos($p, 'status') !== false || strpos($p, 'detail') !== false || strpos($p, 'time') !== false || strpos($p, 'summary') !== false || strpos($p, 'sumary') !== false || strpos($p, 'breakdown') !== false || strpos($p, 'report') !== false;
    
    if ($isDataQuery) return null;

    $isExplicitNav = (strpos($p, 'open') !== false || strpos($p, 'go to') !== false || strpos($p, 'khul') !== false || strpos($p, 'khol') !== false || strpos($p, 'navigate') !== false || strpos($p, 'page') !== false);
    if (!$isExplicitNav) return null;



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
    echo json_encode([
        'ok'        => true,
        'answer'    => "🚀 **Navigation Command Received:**\n\nOpening **" . htmlspecialchars($navTarget['name']) . "** page for you.\n\n👉 [Click here if page does not auto-redirect](" . htmlspecialchars($navTarget['url']) . ")",
        'provider'  => 'ERP AI Navigation Engine',
        'tool_used' => 'ERP Navigation Tool',
        'nav_url'   => $navTarget['url']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$p = mb_strtolower($prompt, 'UTF-8');

// ─── Greeting / Casual Chat Handler ───
$greetingWords = ['hi', 'hello', 'hey', 'hola', 'howdy', 'sup', 'good morning', 'good afternoon', 'good evening', 'good night',
    'নমস্কার', 'হ্যালো', 'হাই', 'শুভ সকাল', 'শুভ বিকেল', 'শুভ সন্ধ্যা', 'কেমন আছেন', 'কেমন আছো', 'ভালো',
    'नमस्ते', 'हेलो', 'हाय', 'नमस्कार', 'सुप्रभात', 'कैसे हो', 'क्या हाल',
    'namaste', 'namaskar', 'assalamu', 'salam', 'kemon', 'ki khobor', 'kaise ho', 'thank', 'thanks', 'dhonnobad', 'ধন্যবাদ', 'धन्यवाद', 'shukriya'];
$pTrimmed = trim(preg_replace('/[^a-z0-9\x{0900}-\x{09FF}\x{0980}-\x{09FF}\s]/u', '', $p));
$isGreeting = false;
foreach ($greetingWords as $gw) {
    if ($pTrimmed === $gw || strpos($pTrimmed, $gw) === 0 || (mb_strlen($pTrimmed) <= 25 && mb_strpos($pTrimmed, $gw) !== false)) {
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
        $hour = (int)date('G');
        $timeGreet = $hour < 12 ? ['Good Morning', 'শুভ সকাল', 'सुप्रभात'] : ($hour < 17 ? ['Good Afternoon', 'শুভ বিকেল', 'शुभ दोपहर'] : ['Good Evening', 'শুভ সন্ধ্যা', 'शुभ संध्या']);
        
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
                . "🧮 **लेबल कैलकुलेटर** — रनिंग मीटर, इम्प्रेशन और कॉस्टिंग गणना\n"
                . "📋 **जॉब प्लानिंग** — प्लानिंग बोर्ड और डिपार्टमेंट स्टेटस\n"
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
    
    echo json_encode([
        'ok'        => true,
        'answer'    => $greeting,
        'provider'  => 'ERP AI Assistant',
        'tool_used' => 'Greeting & Help',
        'user_lang' => $userLang
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// ─── Knowledge Base Lookup (Admin-Trained Custom Answers) ───
function check_knowledge_base(mysqli $db, string $prompt): ?array {
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
    if (!$res) return null;

    $promptLower = mb_strtolower(trim($prompt), 'UTF-8');
    preg_match_all('/[\x{0980}-\x{09FF}\x{0900}-\x{097F}\w]+/u', $promptLower, $promptMatches);
    $kbStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'show', 'details', 'this', 'the', 'a', 'an', 'what', 'where', 'how', 'when', 'who', 'list', 'get', 'for', 'about', 'with', 'from', 'ache', 'koto', 'kotogulo', 'ki', 'kon', 'jabe', 'hote', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'হবে', 'আছে', 'কত', 'কতগুলো', 'কি', 'কী', 'কোন', 'দিয়ে', 'গিয়ে'];

    $promptTokens = array_filter($promptMatches[0] ?? [], function($t) use ($kbStopwords) {
        return mb_strlen($t) >= 3 && !in_array($t, $kbStopwords, true);
    });

    $bestMatch = null;
    $bestScore = 0;

    while ($row = $res->fetch_assoc()) {
        $rawKeywords = array_map('trim', explode(',', mb_strtolower($row['keywords'], 'UTF-8')));
        $matchScore = 0;

        foreach ($rawKeywords as $kw) {
            if ($kw === '') continue;

            // Direct substring match
            if (mb_strpos($promptLower, $kw) !== false) {
                $matchScore += 2.0;
                continue;
            }

            // Token & Fuzzy Levenshtein match (e.g. "delevery" -> "delivery")
            preg_match_all('/[\x{0980}-\x{09FF}\x{0900}-\x{097F}\w]+/u', $kw, $kwMatches);
            $kwTokens = array_filter($kwMatches[0] ?? [], function($t) use ($kbStopwords) {
                return mb_strlen($t) >= 3 && !in_array($t, $kbStopwords, true);
            });

            foreach ($kwTokens as $kwToken) {
                foreach ($promptTokens as $pToken) {
                    if ($pToken === $kwToken) {
                        $matchScore += 2.0;
                    } else {
                        $pLen = mb_strlen($pToken);
                        $kLen = mb_strlen($kwToken);
                        if ($pLen >= 4 && $kLen >= 4) {
                            $lev = levenshtein($pToken, $kwToken);
                            // Strictly require lev <= 1 for short words (4-5 chars) to avoid false matches like "cross" vs "cost"
                            if (($pLen <= 5 && $lev <= 1) || ($pLen >= 6 && $lev <= 2)) {
                                $matchScore += 1.5;
                            }
                        }
                    }
                }
            }
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




$knowledgeMatch = check_knowledge_base($db, $prompt);
if ($knowledgeMatch !== null) {
    $userLang = detect_language($prompt);
    $kbAnswer = $knowledgeMatch['answer'];
    $kbCategory = $knowledgeMatch['category'];
    
    echo json_encode([
        'ok'          => true,
        'answer'      => "📚 " . $kbAnswer,
        'provider'    => 'ERP AI Knowledge Base',
        'tool_used'   => 'Admin Knowledge Base (' . $kbCategory . ')',
        'user_lang'   => $userLang,
        'kb_match_id' => (int)$knowledgeMatch['id']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// Direct SQM <-> SQ Inch Unit Conversion Handler
if (strpos($p, 'inch') !== false && (strpos($p, 'sqm') !== false || strpos($p, 'sq mtr') !== false || strpos($p, 'sqr mtr') !== false || strpos($p, 'square meter') !== false) && !preg_match('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/', $prompt)) {
    $userLang = detect_language($prompt);
    
    preg_match('/(\d+(\.\d+)?)\s*(\/|per|\s*)\s*(sq inch|sqr inch|inch)|(sq inch|sqr inch|inch)\s*(is|=|:|\s*)\s*(\d+(\.\d+)?)/i', $prompt, $inchMatch);
    $givenInchRate = 0;
    if (!empty($inchMatch[1])) {
        $givenInchRate = (float)$inchMatch[1];
    } elseif (!empty($inchMatch[7])) {
        $givenInchRate = (float)$inchMatch[7];
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
                . "• **হিসাবকৃত প্রতি স্কয়ার মিটার দাম:** **₹" . number_format($calcSqmRate, 2) . "** / SQM\n\n"
                . "💡 **গাণিতিক নিয়ম:** `Per SQM Rate = Per SQ Inch Rate × 1550.0031` (" . number_format($givenInchRate, 4) . " × 1550.0031 = ₹" . number_format($calcSqmRate, 2) . " / SQM)\n";
        }
    } else {
        preg_match('/(\d+(\.\d+)?)\s*(\/|per|\s*)\s*(sqm|sq mtr|sqr mtr|square meter)/i', $prompt, $sqmMatch);
        $sqmRate = !empty($sqmMatch[1]) ? (float)$sqmMatch[1] : 20.00;
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
                . "💡 **গাণিতিক নিয়ম:** `Per SQ Inch = Per SQM Rate / 1550.0031` (" . number_format($sqmRate, 2) . " ÷ 1550.0031 = ₹{$sqInchFormatted})\n";
        }
    }

    echo json_encode([
        'ok'        => true,
        'answer'    => $answer,
        'provider'  => 'ERP Industrial Unit Conversion Engine',
        'tool_used' => 'SQM & SQ Inch Unit Converter',
        'user_lang' => $userLang
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Industrial Label Mathematics Engine
 */
function calculate_label_costing_math(string $prompt): array {
    $p = mb_strtolower($prompt, 'UTF-8');
    
    // Size (e.g. 100mm x 50mm)
    preg_match('/(\d+(\.\d+)?)\s*mm\s*[xX*]\s*(\d+(\.\d+)?)\s*mm/i', $prompt, $sizeMatch);
    $widthMm = $sizeMatch ? (float)$sizeMatch[1] : 100;
    $lengthMm = $sizeMatch ? (float)$sizeMatch[3] : 50;

    // Ups
    preg_match('/(\d+)\s*ups/i', $prompt, $upsMatch);
    $ups = $upsMatch ? max(1, (int)$upsMatch[1]) : 2;

    // Repeat Gap
    preg_match('/(\d+(\.\d+)?)\s*mm\s*gap|gap\s*(is\s*)?(\d+(\.\d+)?)\s*mm|middle gap is\s*(\d+(\.\d+)?)\s*mm/i', $prompt, $gapMatch);
    $gapMm = 5;
    if (!empty($gapMatch[1])) $gapMm = (float)$gapMatch[1];
    elseif (!empty($gapMatch[4])) $gapMm = (float)$gapMatch[4];
    elseif (!empty($gapMatch[6])) $gapMm = (float)$gapMatch[6];

    // Quantity (e.g. 50000 pices or 15000 qty)
    $cleanPromptForQty = preg_replace('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/i', '', $prompt);
    preg_match('/(\d{3,9})\s*(qnt|qty|pcs|pices|pieces|labels|required)?/i', $cleanPromptForQty, $qtyMatch);
    $qty = $qtyMatch ? (float)$qtyMatch[1] : 50000;

    // Roll Width
    preg_match('/(\d+(\.\d+)?)\s*mm\s*roll|roll\s*(\d+(\.\d+)?)\s*mm/i', $prompt, $rollMatch);
    $hasRollWidth = !empty($rollMatch[1]) || !empty($rollMatch[3]);
    $parentWidthMm = 0;
    if (!empty($rollMatch[1])) $parentWidthMm = (float)$rollMatch[1];
    elseif (!empty($rollMatch[3])) $parentWidthMm = (float)$rollMatch[3];

    // Rate
    preg_match('/(\d+(\.\d+)?)\s*(\/|per|\s*)\s*(kg|sqm|sq mtr|sqr mtr|square meter|sq inch|sqr inch)/i', $prompt, $rateMatch);
    $hasRate = !empty($rateMatch);
    $ratePerKg = 0;
    $ratePerSqm = 0;
    if ($rateMatch) {
        $val = (float)$rateMatch[1];
        $unit = strtolower($rateMatch[4]);
        if ($unit === 'kg') $ratePerKg = $val;
        else $ratePerSqm = $val;
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

// Check Math Intent
$hasCompanyQuery = preg_match('/\b(krishna|austin|navkar|nrgi)\b/i', $prompt) || strpos($p, 'কৃষ্ণা') !== false || strpos($p, 'অস্টিন') !== false || strpos($p, 'নভকার') !== false || strpos($p, 'এনআরজিআই') !== false;

$isMathIntent = !$hasCompanyQuery && (
    preg_match('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/i', $prompt) ||
    strpos($p, 'running meter') !== false ||
    strpos($p, 'running mtr') !== false ||
    (strpos($p, 'ups') !== false && strpos($p, 'gap') !== false)
);

if ($isMathIntent) {
    $math = calculate_label_costing_math($prompt);
    $userLang = detect_language($prompt);

    if ($userLang === 'English') {
        $answer = "🧮 **Industrial Label Calculation Breakdown:**\n\n"
            . "• **Label Specification:** {$math['width_mm']}mm X {$math['length_mm']}mm | **{$math['ups']} Ups** | **Middle Gap:** {$math['gap_mm']}mm\n"
            . "• **Total Quantity Required:** " . number_format($math['qty']) . " Labels / Pieces\n"
            . "• **Repeat Pitch:** {$math['repeat_pitch_mm']}mm per impression\n"
            . "• **Total Impressions Required:** " . number_format($math['impressions']) . "\n"
            . "• **Net Used Width:** {$math['net_used_width_mm']}mm\n"
            . "• **Required Running Meters:** **" . number_format($math['running_meters'], 2) . " Meters**\n";

        if ($math['has_roll_width']) {
            $answer .= "\n📐 **Parent Roll & Wastage Analysis:**\n"
                . "• **Parent Roll Width:** {$math['parent_width_mm']}mm\n"
                . "• **Side Wastage Width:** {$math['side_waste_width_mm']}mm ({$math['side_waste_pct']}% Wastage)\n"
                . "• **Total Paper Area (SQM):** **{$math['total_paper_sqm']} SQM**\n"
                . "• **Total Weight (Est. 80 GSM):** **{$math['total_weight_kg']} KG**\n";
        }
        if ($math['has_rate']) {
            $answer .= "\n💰 **Costing Breakdown:**\n"
                . "• **Total Paper Cost:** **₹" . number_format($math['total_paper_cost'], 2) . "**\n"
                . "• **Cost per Label:** **₹{$math['cost_per_label']}** / label\n";
        }
        if (!$math['has_roll_width'] || !$math['has_rate']) {
            $answer .= "\n💡 **Need Total Cost, SQM & Wastage Calculation?**\n"
                . "Please reply with your missing inputs:\n"
                . (!$math['has_roll_width'] ? "1. 📏 **Parent Roll Width (mm):** (e.g. `on 250mm roll`)\n" : "")
                . (!$math['has_rate'] ? "2. 💰 **Paper Price (Rate):** (e.g. `at Rs 300/kg` or `Rs 20/sqm`)\n" : "");
        }
    } elseif ($userLang === 'Hindi') {
        $answer = "🧮 **औद्योगिक लेबल गणना विवरण:**\n\n"
            . "• **लेबल विनिर्देश:** {$math['width_mm']}mm X {$math['length_mm']}mm | **{$math['ups']} Ups** | **गैप:** {$math['gap_mm']}mm\n"
            . "• **कुल आवश्यक मात्रा:** " . number_format($math['qty']) . " पीस\n"
            . "• **रिपीट पिच:** {$math['repeat_pitch_mm']}mm प्रति इम्प्रैशन\n"
            . "• **आवश्यक इम्प्रैशन:** " . number_format($math['impressions']) . "\n"
            . "• **चौड़ाई (Net Width):** {$math['net_used_width_mm']}mm\n"
            . "• **आवश्यक रनिंग मीटर:** **" . number_format($math['running_meters'], 2) . " मीटर**\n";

        if ($math['has_roll_width']) {
            $answer .= "\n📐 **पेपर वेस्टेज:**\n"
                . "• **मदर रोल चौड़ाई:** {$math['parent_width_mm']}mm\n"
                . "• **साइट वेस्टेज:** {$math['side_waste_width_mm']}mm ({$math['side_waste_pct']}%)\n"
                . "• **कुल पेपर क्षेत्रफल:** **{$math['total_paper_sqm']} SQM**\n";
        }
        if (!$math['has_roll_width'] || !$math['has_rate']) {
            $answer .= "\n💡 **क्या आप कुल लागत और वेस्टेज की गणना चाहते हैं?**\n"
                . "कृपया शेष विवरण बताएं:\n"
                . (!$math['has_roll_width'] ? "1. 📏 **मदर रोल चौड़ाई (mm):** (उदा. `250mm roll`)\n" : "")
                . (!$math['has_rate'] ? "2. 💰 **पेपर की कीमत (Rate):** (उदा. `₹300/kg`)\n" : "");
        }
    } else {
        $answer = "🧮 **ইন্ডাস্ট্রিয়াল লেবেল গাণিতিক হিসাব:**\n\n"
            . "• **লেবেল সাইজ:** {$math['width_mm']}mm X {$math['length_mm']}mm | **{$math['ups']} Ups** | **মিডল গ্যাপ:** {$math['gap_mm']}mm\n"
            . "• **মোট কোয়ান্টিটি:** " . number_format($math['qty']) . " টি লেবেল / পিস\n"
            . "• **রিপিট পিচ:** {$math['repeat_pitch_mm']}mm প্রতি ইমপ্রেশনে\n"
            . "• **মোট ইমপ্রেশন প্রয়োজন:** " . number_format($math['impressions']) . " টি\n"
            . "• **ব্যবহৃত চওড়া (Net Width):** {$math['net_used_width_mm']}mm\n"
            . "• **প্রয়োজনীয় রানিং মিটার:** **" . number_format($math['running_meters'], 2) . " মিটার (Running Meters)**\n";

        if ($math['has_roll_width']) {
            $answer .= "\n📐 **মাদার রোল ও ওয়েস্টেজ হিসাব:**\n"
                . "• **মাদার রোল চওড়া:** {$math['parent_width_mm']}mm\n"
                . "• **সাইড ওয়েস্টেজ চওড়া:** {$math['side_waste_width_mm']}mm ({$math['side_waste_pct']}% ওয়েস্টেজ)\n"
                . "• **মোট পেপার ক্ষেত্রফল (SQM):** **{$math['total_paper_sqm']} SQM**\n"
                . "• **মোট ওজন (Est. 80 GSM):** **{$math['total_weight_kg']} KG**\n";
        }
        if (!$math['has_roll_width'] || !$math['has_rate']) {
            $answer .= "\n💡 **আপনি কি মোট পেপার খরচ এবং ওয়েস্টেজ হিসাব জানতে চান?**\n"
                . "তাহলে অনুগহ করে নিচের বাকি তথ্যগুলো টাইপ করুন:\n"
                . (!$math['has_roll_width'] ? "১. 📏 **মাদার পেপার রোলের চওড়া (mm):** (যেমন: `250mm roll`)\n" : "")
                . (!$math['has_rate'] ? "২. 💰 **কাগজের দাম (Rate):** (যেমন: `Rs 300/kg` বা `Rs 20/sqm`)\n" : "");
        }
    }

    echo json_encode([
        'ok'        => true,
        'answer'    => $answer,
        'provider'  => 'ERP Industrial Label Math Engine',
        'tool_used' => 'Industrial Label Calculator',
        'user_lang' => $userLang
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Regular DB Query Router
function fetch_erp_data_by_intent(mysqli $db, string $prompt): array {
    $p = mb_strtolower($prompt, 'UTF-8');
    $data = [];
    $totalCount = 0;
    $totalMeters = 0;
    $filteredType = '';
    $isCompanyList = false;
    $toolName = 'ERP Knowledge Engine';
    $searchNums = extract_search_numbers($prompt);

    // 1. Company / Paper Type Analytics (Krishna, Austin, Navkar, NRGI, Chromo, Thermal etc.)
    $compName = '';
    if (preg_match('/\b(krishna|austin|navkar|nrgi|flexo)\b/i', $prompt) || strpos($p, 'কৃষ্ণা') !== false || strpos($p, 'অস্টিন') !== false || strpos($p, 'নভকার') !== false || strpos($p, 'এনআরজিআই') !== false) {
        if (strpos($p, 'krishna') !== false || strpos($p, 'কৃষ্ণা') !== false) $compName = 'KRISHNA';
        elseif (strpos($p, 'austin') !== false || strpos($p, 'অস্টিন') !== false) $compName = 'AUSTIN';
        elseif (strpos($p, 'navkar') !== false || strpos($p, 'নভকার') !== false) $compName = 'NAVKAR';
        elseif (strpos($p, 'nrgi') !== false || strpos($p, 'এনআরজিআই') !== false) $compName = 'NRGI';
        elseif (strpos($p, 'flexo') !== false) $compName = 'FLEXO';
    }

    $paperTypeFilter = '';
    if (strpos($p, 'chromo') !== false || strpos($p, 'ক্রোম') !== false || strpos($p, 'ক্রোমো') !== false) {
        $paperTypeFilter = 'chromo';
    } elseif (strpos($p, 'thermal') !== false || strpos($p, 'থার্মাল') !== false) {
        $paperTypeFilter = 'thermal';
    }

    if (!empty($compName) || !empty($paperTypeFilter)) {
        $toolName = "Paper Stock Analytics Tool (" . trim($compName . ' ' . strtoupper($paperTypeFilter)) . ")";
        
        $likeComp = $compName ? ('%' . $compName . '%') : '%';
        $likeType = $paperTypeFilter ? ('%' . $paperTypeFilter . '%') : '%';

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

        $totalRolls = (int)($summaryRow['total_rolls'] ?? 0);
        $totalRunningMtr = round((float)($summaryRow['total_running_mtr'] ?? 0), 2);
        $totalSqm = round((float)($summaryRow['total_sqm'] ?? 0), 2);
        $jumboCount = (int)($summaryRow['jumbo_rolls_count'] ?? 0);
        $slittedCount = (int)($summaryRow['slitted_rolls_count'] ?? 0);

        $userLang = detect_language($prompt);
        $labelStr = trim($compName . ' ' . strtoupper($paperTypeFilter));

        if ($userLang === 'English') {
            $answer = "📊 **{$labelStr} Paper Stock Analytics Summary:**\n\n"
                . "• **Filter:** **{$labelStr}**\n"
                . "• **Total Paper Rolls:** **" . number_format($totalRolls) . " Rolls**\n"
                . "• **Total Running Meters:** **" . number_format($totalRunningMtr, 2) . " Meters**\n"
                . "• **Total Paper Area (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n"
                . "• **Jumbo Rolls (Width ≥ 1000mm):** **" . number_format($jumboCount) . " Jumbo Rolls** (1000mm or above width)\n"
                . "• **Slitted Stock Rolls (Width < 1000mm):** **" . number_format($slittedCount) . " Rolls**\n";
        } elseif ($userLang === 'Hindi') {
            $answer = "📊 **{$labelStr} पेपर स्टॉक विश्लेषण:**\n\n"
                . "• **फ़िल्टर:** **{$labelStr}**\n"
                . "• **कुल पेपर रोल:** **" . number_format($totalRolls) . " रोल**\n"
                . "• **कुल रनिंग मीटर:** **" . number_format($totalRunningMtr, 2) . " मीटर**\n"
                . "• **कुल पेपर क्षेत्रफल (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n"
                . "• **जंबो रोल (चौड़ाई ≥ 1000mm):** **" . number_format($jumboCount) . " जंबो रोल**\n"
                . "• **स्लिटेड रोल (चौड़ाई < 1000mm):** **" . number_format($slittedCount) . " रोल**\n";
        } else {
            $answer = "📊 **{$labelStr} পেপার স্টক বিশ্লেষণ সারসংক্ষেপ:**\n\n"
                . "• **ফিল্টার:** **{$labelStr}**\n"
                . "• **মোট পেপার রোল:** **" . number_format($totalRolls) . "টি রোল**\n"
                . "• **মোট রানিং মিটার:** **" . number_format($totalRunningMtr, 2) . " মিটার**\n"
                . "• **মোট পেপার ক্ষেত্রফল (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n"
                . "• **জাম্বো রোল (চওড়া ≥ ১০০০ মিমি):** **" . number_format($jumboCount) . "টি জাম্বো রোল** (হাজার বা হাজারের বেশি উইথ)\n"
                . "• **স্লিটেড স্টক রোল (চওড়া < ১০০০ মিমি):** **" . number_format($slittedCount) . "টি রোল**\n";
        }

        return [
            'tool_used' => $toolName,
            'total_count' => $totalRolls,
            'total_meters' => $totalRunningMtr,
            'filtered_type' => $labelStr,
            'is_company_list' => false,
            'direct_answer' => $answer,
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
        $paperCount = (int)($paperRow['cnt'] ?? 0);
        $paperMtr = round((float)($paperRow['total_mtr'] ?? 0), 2);

        $fgRes = $db->query("SELECT COUNT(*) as cnt, SUM(quantity) as total_qty FROM finished_goods_stock WHERE quantity > 0");
        $fgRow = $fgRes ? $fgRes->fetch_assoc() : [];
        $fgCount = (int)($fgRow['cnt'] ?? 0);
        $fgQty = (int)($fgRow['total_qty'] ?? 0);

        $userLang = detect_language($prompt);

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
                . "• **\"Paper stock roll\"** टाइप करें - मदर पेपर रोल के लिए\n"
                . "• **\"Finished goods stock\"** टाइप करें - पैक्ड फिनिश्ड रोल के लिए";
        } else {
            $answer = "📊 **মোট ইআরপি রোল স্টক সারসংক্ষেপ:**\n\n"
                . "আপনার ইআরপি ডাটাবেসে ২ ধরনের রোল স্টক প্রস্তুত রয়েছে:\n\n"
                . "১. 📜 **মাদার পেপার স্টক রোল (Paper Stock):** **" . number_format($paperCount) . "টি রোল** (সর্বমোট **" . number_format($paperMtr, 2) . " মিটার** স্টক)\n"
                . "২. 📦 **ফিনিশড গুডস প্যাকড রোল (Finished Goods):** **" . number_format($fgCount) . "টি ব্যাচ/প্যাকড রোল** (সর্বমোট **" . number_format($fgQty) . "টি** আইটেম)\n\n"
                . "❓ **আপনি কোন রোলের বিস্তারিত তালিকা দেখতে চান?**\n"
                . "• টাইপ করুন: **\"Paper stock roll dekhaw\"** (মাদার পেপার রোলের জন্য)\n"
                . "• টাইপ করুন: **\"Finished goods roll dekhaw\"** (প্যাকড লেবেল রোলের জন্য)";
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

    // 3. Live Production Floor Intent Matcher
    if (strpos($p, 'live') !== false || strpos($p, 'floor') !== false || strpos($p, 'stage') !== false || strpos($p, 'next department') !== false || strpos($p, 'journey') !== false) {
        $toolName = 'Live Production Floor Pipeline Tool';
        
        $sql = "SELECT p.id as planning_id, p.job_no as planning_no, p.job_name, p.status as planning_status, p.priority, p.created_at as planning_date,
                       j.id as job_id, j.job_no, j.job_type, j.department, j.status as job_status, j.created_at as job_date
                FROM planning p
                LEFT JOIN jobs j ON j.planning_id = p.id AND (j.deleted_at IS NULL OR j.deleted_at = '0000-00-00 00:00:00')
                WHERE p.deleted_at IS NULL
                ORDER BY p.id DESC, j.id ASC";

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
        $userLang = detect_language($prompt);
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

                $completed = [];
                $current = null;
                $upcoming = [];

                foreach ($depts as $d) {
                    $deptName = strtoupper(str_replace('_', ' ', $d['department']));
                    $st = strtolower($d['status']);
                    $timeStr = date('d M Y, h:i A', strtotime($d['created_at']));

                    if ($st === 'completed' || $st === 'finished production' || $st === 'packing done') {
                        $completed[] = "✅ **{$deptName}** (`{$d['job_no']}`): Finished at {$timeStr}";
                    } elseif ($current === null) {
                        $current = "⚡ **CURRENT DEPARTMENT:** `{$deptName}` (`{$d['job_no']}`) | Status: **{$d['status']}** (Entry Time: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "⏩ **NEXT:** `{$deptName}` (`{$d['job_no']}`) | Status: `{$d['status']}`";
                    }
                }

                $hasPacking = false;
                foreach ($depts as $d) {
                    if (strpos(strtolower($d['department']), 'pack') !== false) {
                        $hasPacking = true;
                        break;
                    }
                }
                if (!$hasPacking) {
                    $upcoming[] = "⏩ **NEXT:** `Packing & Packaging` (Pending release)";
                }
                $upcoming[] = "🏁 **FINAL:** `Finished Production Stock & Dispatch`";

                $answer .= "  - " . ($current ?: "⚡ **CURRENT DEPARTMENT:** `Department Assignment in Progress`") . "\n";

                if (!empty($completed)) {
                    $answer .= "  - 🟢 **Completed Stages:** " . implode(' ➔ ', array_slice($completed, 0, 3)) . "\n";
                }

                $answer .= "  - ⏩ **Next Pipeline:** " . implode(' ➔ ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - 📊 **Remaining Steps to Finished Production:** **`{$remCount} Departments / Stages left`**\n\n";
            }

            $answer .= "👉 [Click here to open full Live Production Floor page]({$baseUrl}/modules/live/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "🏭 **लाइव प्रोडक्शन फ्लोर — जॉब जर्नी और विभागीय स्थिति सारांश:**\n\n"
                . "कुल **{$totalCount} मास्टर जॉब्स** विभिन्न विभागों से होकर गुजर रहे हैं:\n\n";

            foreach ($grouped as $job) {
                $answer .= "📋 **मास्टर जॉब: `{$job['planning_no']}`** | **{$job['job_name']}** (प्राथमिकता: `{$job['priority']}`)\n";
                
                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - ⏳ **वर्तमान चरण:** `प्लानिंग स्टेज`\n"
                        . "  - 📊 **शेष विभाग (Remaining):** `4 विभाग बाकी हैं` (जंबो ➔ प्रिंटिंग ➔ स्लिटिंग ➔ पैकिंग ➔ फिनिश्ड)\n\n";
                    continue;
                }

                $current = null;
                $upcoming = [];

                foreach ($depts as $d) {
                    $deptName = strtoupper(str_replace('_', ' ', $d['department']));
                    $st = strtolower($d['status']);
                    $timeStr = date('d M Y, h:i A', strtotime($d['created_at']));

                    if ($current === null && !in_array($st, ['completed', 'finished production', 'packing done'], true)) {
                        $current = "⚡ **वर्तमान विभाग:** `{$deptName}` (`{$d['job_no']}`) | स्थिति: **{$d['status']}** (समय: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}`";
                    }
                }

                $upcoming[] = "`पैकिंग`";
                $upcoming[] = "`फिनिश्ड गुड्स स्टॉक`";

                $answer .= "  - " . ($current ?: "⚡ **वर्तमान विभाग:** `प्रक्रिया जारी`") . "\n";
                $answer .= "  - ⏩ **आगे का रास्ता (Next):** " . implode(' ➔ ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - 📊 **फिनिश्ड प्रोडक्शन तक शेष विभाग:** **`{$remCount} विभाग पार करने बाकी हैं`**\n\n";
            }

            $answer .= "👉 [लाइव प्रोडक्शन फ्लोर पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/live/index.php)";
        } else {
            $answer = "🏭 **লাইভ প্রডাকশন ফ্লোর — জব জার্নি ও ডিপার্টমেন্টাল সামারি:**\n\n"
                . "আপনার লাইভ প্রডাকশন ফ্লোরে মোট **{$totalCount}টি মাস্টার জব** বিভিন্ন ডিপার্টমেন্ট অতিক্রম করছে:\n\n";

            foreach ($grouped as $job) {
                $answer .= "📋 **মাস্টার জব: `{$job['planning_no']}`** | **{$job['job_name']}** (প্রায়োরিটি: `{$job['priority']}`)\n";
                
                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - ⏳ **বর্তমান স্টেজ:** `প্ল্যানিং স্টেজ` (ডিপার্টমেন্টে ছাড়ার অপেক্ষায়)\n"
                        . "  - ⏩ **পরবর্তী ডিপার্টমেন্টসমূহ:** জাম্বো স্লিটিং ➔ ফ্লেক্সো প্রিন্টিং ➔ লেবেল স্লিটিং ➔ প্যাকিং ➔ ফিনিশড স্টক\n"
                        . "  - 📊 **বাকি ডিপার্টমেন্ট:** `৪টি ডিপার্টমেন্ট ক্রস করতে হবে`\n\n";
                    continue;
                }

                $completed = [];
                $current = null;
                $upcoming = [];

                foreach ($depts as $d) {
                    $deptName = strtoupper(str_replace('_', ' ', $d['department']));
                    $st = strtolower($d['status']);
                    $timeStr = date('d M Y, h:i A', strtotime($d['created_at']));

                    if ($st === 'completed' || $st === 'finished production' || $st === 'packing done') {
                        $completed[] = "✅ **{$deptName}** (`{$d['job_no']}`): Finished at {$timeStr}";
                    } elseif ($current === null) {
                        $current = "⚡ **বর্তমান ডিপার্টমেন্ট (NOW):** `{$deptName}` (`{$d['job_no']}`) | স্ট্যাটাস: **{$d['status']}** (এন্ট্রি সময়: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}` (`{$d['job_no']}`)";
                    }
                }

                $hasPacking = false;
                foreach ($depts as $d) {
                    if (strpos(strtolower($d['department']), 'pack') !== false) {
                        $hasPacking = true;
                        break;
                    }
                }
                if (!$hasPacking) {
                    $upcoming[] = "`প্যাকিং ও প্যাকেজিং`";
                }
                $upcoming[] = "`ফিনিশড প্রডাকশন স্টক ও ডিসপ্যাচ`";

                $answer .= "  - " . ($current ?: "⚡ **বর্তমান ডিপার্টমেন্ট (NOW):** `ডিপার্টমেন্টে এন্ট্রি প্রসেসিং`") . "\n";

                if (!empty($completed)) {
                    $answer .= "  - 🟢 **সম্পন্ন ডিপার্টমেন্ট:** " . implode(' ➔ ', array_slice($completed, 0, 3)) . "\n";
                }

                $answer .= "  - ⏩ **পরবর্তী ডিপার্টমেন্টসমূহ (NEXT):** " . implode(' ➔ ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - 📊 **ফিনিশড প্রডাকশন হতে বাকি ডিপার্টমেন্ট:** **`আর {$remCount}টি ডিপার্টমেন্ট অতিক্রম করতে হবে`**\n\n";
            }

            $answer .= "👉 [লাইভ প্রডাকশন ফ্লোর পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/live/index.php)";
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
        $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

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
    } elseif (strpos($p, 'company') !== false || strpos($p, 'companies') !== false || strpos($p, 'brand') !== false || strpos($p, 'supplier') !== false) {
        $isCompanyList = true;
        $toolName = 'Paper Company Summary Tool';
        $res = $db->query("SELECT company, COUNT(*) as roll_count, SUM(length_mtr) as total_meters FROM paper_stock WHERE status IN ('Main','Stock','Job Assign') AND company != '' GROUP BY company ORDER BY roll_count DESC");
        $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $totalCount = count($data);
    } elseif (strpos($p, 'plate') !== false) {
        $toolName = 'Printing Plates Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
        $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
        
        // Extract text search words (filtering out attribute query words like repeat, gap, size, etc.)
        $stopwords = [
            'can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'plate', 'plates', 'list', 'show', 'details', 'detail', 'this', 'the', 'a', 'an',
            'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does',
            'repeat', 'gap', 'gaph', 'gapv', 'size', 'ups', 'cylinder', 'paper', 'die', 'core', 'rewinding', 'value', 'color', 'colors', 'spec', 'special', 'what', 'how', 'give'
        ];

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
            // 1. Try exact combined terms (e.g. '%blue%500ml%')
            $like = '%' . $searchTerm . '%';
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? OR sl_no = ? OR id = ? ORDER BY id DESC LIMIT 10");
            $stmt->bind_param('sss', $like, $searchTerm, $searchTerm);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // 2. If empty, try matching individual terms
            if (empty($data) && count($terms) > 1) {
                $likes = array_map(function($t) { return '%' . $t . '%'; }, $terms);
                $whereClause = implode(' AND ', array_fill(0, count($likes), "name LIKE ?"));
                $stmt2 = $db->prepare("SELECT * FROM master_plate_data WHERE {$whereClause} ORDER BY id DESC LIMIT 10");
                $types = str_repeat('s', count($likes));
                $stmt2->bind_param($types, ...$likes);
                $stmt2->execute();
                $data = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            }
        }

        if (empty($data) && !empty($searchNums)) {
            $num = $searchNums[0];
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE sl_no = ? OR id = ? ORDER BY id DESC LIMIT 5");
            $stmt->bind_param('ss', $num, $num);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        // If user searched for a specific term and 0 records were found
        if (empty($data) && !empty($terms)) {
            $userLang = detect_language($prompt);
            if ($userLang === 'English') {
                $directAnswer = "❌ **No Printing Plate Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Plate database, but no plate record matching **\"{$searchTermDisplay}\"** was found.\n\n"
                    . "💡 **Tip:** Please verify if the plate name or SL No is spelled correctly.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" नाम की कोई प्रिंटिंग प्लेट नहीं मिली**\n\n"
                    . "आपके ईआरपी डेटाबेस में **\"{$searchTermDisplay}\"** नाम की कोई प्लेट उपलब्ध नहीं है।";
            } else {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" নামে কোনো প্রিন্টিং প্লেট পাওয়া যায়নি**\n\n"
                    . "আপনার ইআরপি মাস্টার ডাটাবেসে **\"{$searchTermDisplay}\"** নামে কোনো প্লেটের রেকর্ড নিবন্ধিত নেই।";
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

    } elseif (strpos($p, 'die') !== false || strpos($p, 'tooling') !== false) {
        $toolName = 'Die Tooling Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_die_tooling");
        $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
        
        $stopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'die', 'tooling', 'list', 'show', 'details', 'this', 'the', 'a', 'an', 'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does'];
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
            $stmt = $db->prepare("SELECT * FROM master_die_tooling WHERE name LIKE ? OR id = ? ORDER BY id DESC LIMIT 10");
            $stmt->bind_param('ss', $like, $searchTerm);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        if (empty($data) && !empty($searchNums)) {
            $num = $searchNums[0];
            $stmt = $db->prepare("SELECT * FROM master_die_tooling WHERE id = ? OR name LIKE ? LIMIT 5");
            $like = '%' . $num . '%';
            $stmt->bind_param('ss', $num, $like);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        if (empty($data) && !empty($terms)) {
            $userLang = detect_language($prompt);
            if ($userLang === 'English') {
                $directAnswer = "❌ **No Die Tooling Record Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Die Tooling database, but no record matching **\"{$searchTermDisplay}\"** was found.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" नाम का कोई ডাই বা টূলিং नहीं मिला**\n\n"
                    . "आपके ईआरपी डेटाबेस में **\"{$searchTermDisplay}\"** का कोई रिकॉर्ड उपलब्ध नहीं है।";
            } else {
                $directAnswer = "❌ **\"{$searchTermDisplay}\" নামে কোনো ডাই টূলিং পাওয়া যায়নি**\n\n"
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

    } elseif (strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'slc/') !== false || strpos($p, 'chromo') !== false || strpos($p, 'thermal') !== false) {
        $toolName = 'Paper Stock Tool';
        $typeFilter = '';
        if (strpos($p, 'chromo') !== false) $typeFilter = 'chromo';
        elseif (strpos($p, 'thermal') !== false) $typeFilter = 'thermal';
        if ($typeFilter !== '') {
            $filteredType = strtoupper($typeFilter);
            $like = '%' . $typeFilter . '%';
            $cntStmt = $db->prepare("SELECT COUNT(*) as cnt, SUM(length_mtr) as total_mtr FROM paper_stock WHERE (paper_type LIKE ? OR company LIKE ?) AND status IN ('Main','Stock','Job Assign')");
            $cntStmt->bind_param('ss', $like, $like);
            $cntStmt->execute();
            $summaryRow = $cntStmt->get_result()->fetch_assoc();
            $totalCount = (int)($summaryRow['cnt'] ?? 0);
            $totalMeters = round((float)($summaryRow['total_mtr'] ?? 0), 2);
            $stmt = $db->prepare("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, status, job_no, date_received FROM paper_stock WHERE (paper_type LIKE ? OR company LIKE ?) AND status IN ('Main','Stock','Job Assign') ORDER BY id DESC LIMIT 15");
            $stmt->bind_param('ss', $like, $like);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        } else {
            $cntRes = $db->query("SELECT COUNT(*) as cnt FROM paper_stock WHERE status NOT IN ('Consumed','Dispatched')");
            $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
            $res = $db->query("SELECT id, roll_no, paper_type, company, width_mm, length_mtr, status, job_no, date_received FROM paper_stock WHERE status NOT IN ('Consumed','Dispatched') ORDER BY id DESC LIMIT 15");
            $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
    } elseif (preg_match('/\b(job|jobs|card|cards|order|orders|flx|lsl|jmb|pck|brc|status|progress|work)\b/i', $prompt)) {
        $toolName = 'ERP Jobs & Planning Tool';

        // Extract search term from prompt
        $jobStopwords = ['give', 'me', 'the', 'detail', 'details', 'about', 'job', 'jobs', 'name', 'named', 'by', 'how', 'many', 'we', 'have', 'is', 'are', 'in', 'show', 'tell', 'list', 'find', 'search', 'for', 'a', 'an', 'what', 'which'];
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
                $userLang = detect_language($prompt);
                if ($userLang === 'English') {
                    $directAnswer = "❌ **No Job Found Matching \"{$jobSearchTerm}\"**\n\n"
                        . "I searched your ERP Planning Board and Job Cards database, but no active job matching **\"{$jobSearchTerm}\"** was found.\n\n"
                        . "💡 **Tip:** Please check if the job name or Job Card number is spelled correctly.";
                } elseif ($userLang === 'Hindi') {
                    $directAnswer = "❌ **\"{$jobSearchTerm}\" नाम का कोई जॉब नहीं मिला**\n\n"
                        . "आपके ईआरपी डेटाबेस में **\"{$jobSearchTerm}\"** नाम का कोई जॉब या जॉब कार्ड दर्ज नहीं है।\n\n"
                        . "💡 **टिप:** कृपया जांचें कि जॉब का नाम या जॉब नंबर सही है या नहीं।";
                } else {
                    $directAnswer = "❌ **\"{$jobSearchTerm}\" নামে কোনো জব পাওয়া যায়নি**\n\n"
                        . "আপনার ইআরপি ডাটাবেসে **\"{$jobSearchTerm}\"** নামে কোনো সচল জব বা জব কার্ড নিবন্ধিত নেই।\n\n"
                        . "💡 **পরামর্শ:** অনুগ্রহ করে জব এর নাম বা জব কার্ড নম্বরটি সঠিক রয়েছে কিনা চেক করুন।";
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
            $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
            $res = $db->query("SELECT id, job_no, planning_id, job_type, department, status, created_at FROM jobs ORDER BY id DESC LIMIT 10");
            $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        }
    } else {
        $toolName = 'Unmatched Query Assistant';
        $totalCount = 0;
        $data = [];
    }

    return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => $totalMeters, 'filtered_type' => $filteredType, 'is_company_list' => $isCompanyList, 'data' => $data];
}



$userLang = detect_language($prompt);
$retrieved = fetch_erp_data_by_intent($db, $prompt);
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
            $finalAnswer .= "• **जॉब " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | प्राथमिकता: **{$item['priority']}** | बोर्ड स्थिति: **{$item['status']}**\n";
            if (!empty($item['departments'])) {
                $finalAnswer .= "  - 🏭 **विभागीय स्थिति (Department Progress):**\n";
                foreach ($item['departments'] as $d) {
                    $finalAnswer .= "    ▸ **" . strtoupper(str_replace('_', ' ', $d['department'])) . "** (जॉब कार्ड: `{$d['job_no']}`): **{$d['status']}**\n";
                }
            }
            $finalAnswer .= "\n";
        }
    } else {
        // Bengali
        $finalAnswer = "📊 **প্ল্যানিং বোর্ড এবং ডিপার্টমেন্টভিত্তিক জব স্ট্যাটাস:**\n\nআপনার ইআরপি প্ল্যানিং বোর্ডে মোট **{$totalCount}টি জব** প্রস্তুত রয়েছে:\n\n";
        foreach ($dbData as $idx => $item) {
            $finalAnswer .= "• **জব " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | প্রায়োরিটি: **{$item['priority']}** | বোর্ড স্ট্যাটাস: **{$item['status']}**\n";
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
    if ($userLang === 'English') {
        $finalAnswer = "ℹ️ **Information Not Found in Trained Knowledge Base**\n\n"
            . "I couldn't find a trained match for your query: **\"" . htmlspecialchars($prompt) . "\"**.\n\n"
            . "💡 **Admin Tip:** You can train the AI to answer this question in **Settings → AI Agent → Knowledge Base** by clicking **\"+ Add New Entry\"** and entering keywords for this question.";
    } elseif ($userLang === 'Hindi') {
        $finalAnswer = "ℹ️ **ज्ञान आधार (Knowledge Base) में जानकारी नहीं मिली**\n\n"
            . "आपकी क्वेरी **\"" . htmlspecialchars($prompt) . "\"** का उत्तर अभी सेव नहीं है।\n\n"
            . "💡 **एडमिन टिप:** एडमिन **Settings → AI Agent → Knowledge Base** में जाकर **\"+ Add New Entry\"** पर क्लिक करके उत्तर जोड़ सकते हैं!";
    } else {
        $finalAnswer = "ℹ️ **ট্রেইনড নলেজ বেসে উত্তরটি পাওয়া যায়নি**\n\n"
            . "আপনার প্রশ্ন **\"" . htmlspecialchars($prompt) . "\"** এর উত্তর এখনো সেভ করা হয়নি।\n\n"
            . "💡 **এডমিন পরামর্শ:** এডমিন **Settings → AI Agent → Knowledge Base** এ গিয়ে **\"+ Add New Entry\"** এ উত্তরটি যুক্ত করতে পারেন!";
    }
} else {

    if ($userLang === 'English') {
        $finalAnswer = "📊 **{$toolUsed} Results:**\n\n";
        if ($isCompanyList) {
            $finalAnswer .= "Found **{$totalCount} Paper Companies** supplying stock in your ERP database:\n\n";
            foreach ($dbData as $idx => $row) {
                $finalAnswer .= ($idx + 1) . ". **{$row['company']}**: {$row['roll_count']} rolls (" . number_format((float)$row['total_meters'], 2) . " meters)\n";
            }
        } elseif ($sampleCount === 1) {
            $finalAnswer .= "Found exact record in ERP database:\n\n";
            foreach ($dbData[0] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $finalAnswer .= "• **" . str_replace('_', ' ', strtoupper($k)) . ":** {$v}\n";
                }
            }
        } elseif ($toolUsed === 'Printing Plates Master Tool') {
            if ($userLang === 'English') {
                $finalAnswer = "📊 **Printing Plates Master Tool — Technical Specifications:**\n\nFound **{$sampleCount} matching plate record(s)** in your ERP database:\n\n";
                foreach ($dbData as $idx => $row) {
                    $finalAnswer .= "• **Plate " . ($idx + 1) . ": `{$row['name']}`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 📏 **Repeat Value:** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                        . "  - 📐 **Gap (Horizontal / Vertical):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                        . "  - 📐 **Plate Size:** **" . ($row['size'] ?: 'N/A') . "** | **Ups:** **" . ($row['ups'] ?: '1') . "**\n"
                        . "  - 📄 **Paper Type & Size:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                        . "  - ⚙️ **Die & Cylinder:** **" . ($row['die'] ?: 'N/A') . "** | Cylinder: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - 🏭 **Make By:** **" . ($row['make_by'] ?: 'N/A') . "** | Date Received: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                }
            } else {
                $finalAnswer = "📊 **প্রিন্টিং প্লেটের বিস্তারিত টেকনিক্যাল স্পেসিফিকেশন:**\n\nআপনার ইআরপি ডাটাবেসে **{$sampleCount}টি ম্যাচিং প্লেট** পাওয়া গেছে:\n\n";
                foreach ($dbData as $idx => $row) {
                    $finalAnswer .= "• **প্লেট " . ($idx + 1) . ": `{$row['name']}`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 📏 **রিপিট ভ্যালু (Repeat Value):** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                        . "  - 📐 **গ্যাপ (Gap H / Gap V):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                        . "  - 📐 **প্লেট সাইজ:** **" . ($row['size'] ?: 'N/A') . "** | **আফস (Ups):** **" . ($row['ups'] ?: '1') . "**\n"
                        . "  - 📄 **কাগজের টাইপ ও সাইজ:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                        . "  - ⚙️ **ডাই ও সিলিন্ডার:** **" . ($row['die'] ?: 'N/A') . "** | সিলিন্ডার: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - 🏭 **মেকার:** **" . ($row['make_by'] ?: 'N/A') . "** | এন্ট্রি তারিখ: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                }
            }
        } else {
            $finalAnswer .= "Total **{$totalCount} records** registered in your ERP database.\n";
            if ($sampleCount > 0) {
                $finalAnswer .= "Showing matching sample records below:\n\n";
                foreach ($dbData as $idx => $row) {
                    $details = [];
                    foreach ($row as $k => $v) {
                        if ($v !== null && $v !== '') {
                            $details[] = "**{$k}:** {$v}";
                        }
                    }
                    $finalAnswer .= "• **Record " . ($idx + 1) . ":** " . implode(' | ', array_slice($details, 0, 5)) . "\n";
                }
            }
        }
    } else {

        $finalAnswer = "📊 **{$toolUsed} ফলাফল:**\n\n";
        if ($isCompanyList) {
            $finalAnswer .= "আপনার ইআরপি স্টকে মোট **{$totalCount}টি পেপার কোম্পানি/সাপ্লায়ারের** কাগজ রয়েছে:\n\n";
            foreach ($dbData as $idx => $row) {
                $finalAnswer .= ($idx + 1) . ". **{$row['company']}**: {$row['roll_count']}টি রোল (" . number_format((float)$row['total_meters'], 2) . " মিটার)\n";
            }
        } elseif ($sampleCount === 1) {
            $finalAnswer .= "ইআরপি ডাটাবেসে নির্দিষ্ট সচল রেকর্ডটি পাওয়া গেছে:\n\n";
            foreach ($dbData[0] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $finalAnswer .= "• **" . str_replace('_', ' ', strtoupper($k)) . ":** {$v}\n";
                }
            }
        } else {
            $finalAnswer .= "আপনার ইআরপি ডাটাবেসে মোট **{$totalCount}টি রেকর্ড** নিবন্ধিত রয়েছে।\n";
            if ($sampleCount > 0) {
                $finalAnswer .= "নিচে ম্যাচিং রেকর্ডগুলো দেওয়া হলো:\n\n";
                foreach ($dbData as $idx => $row) {
                    $details = [];
                    foreach ($row as $k => $v) {
                        if ($v !== null && $v !== '') {
                            $details[] = "**{$k}:** {$v}";
                        }
                    }
                    $finalAnswer .= "• **রেকর্ড " . ($idx + 1) . ":** " . implode(' | ', array_slice($details, 0, 5)) . "\n";
                }
            }
        }
    }
}

$responsePayload = [
    'ok'          => true,
    'answer'      => $finalAnswer,
    'provider'    => ($config['gemini_api_key'] !== '' ? 'Gemini Pro API' : 'ERP Smart RAG Engine'),
    'tool_used'   => $toolUsed,
    'user_lang'   => $userLang,
    'total_count' => $totalCount
];

if (!empty($retrieved['nav_url'])) {
    $responsePayload['nav_url'] = $retrieved['nav_url'];
}

echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

