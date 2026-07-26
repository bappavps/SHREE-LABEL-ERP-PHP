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
function extract_search_numbers(string $prompt): array {
    preg_match_all('/\b\d+\b/', $prompt, $matches);
    return $matches[0] ?? [];
}

/**
 * Detect if user is asking a count/total question (e.g. "how many plates?", "total dies?", "kitne anilox hai?")
 */
function is_count_intent(string $prompt): bool {
    $p = mb_strtolower($prompt, 'UTF-8');
    return (bool) preg_match('/\b(how many|how much|total|count|kitne|kitna|koto|kotogulo|কত|কতগুলো|कितने|कितना)\b/i', $p);
}

/**
 * Get context-aware follow-up suggestion questions based on the tool used and query context.
 * Returns an array of suggestion strings the user can click to ask next.
 */
function get_follow_up_suggestions(string $toolUsed, string $prompt, string $userLang = 'English'): array {
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
                    'প্লেট পেজ খোলো',
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
                    'এই প্লেটের মিটার ক্যালকুলেট করো',
                    'Chromo পেপারের প্লেট দেখাও',
                    '9 inch সিলিন্ডার প্লেট দেখাও',
                    'প্লেট পেজ খোলো',
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
                'ডাই পেজ খোলো',
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
                'এনিলক্স পেজ খোলো',
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
                'पेपर स्टॉक पेज खोलो',
            ];
        } else {
            $suggestions = [
                'মোট কতগুলো পেপার রোল আছে?',
                'Chromo পেপার দেখাও',
                'PP White স্টক দেখাও',
                'পেপার স্টক পেজ খোলো',
            ];
        }
    } elseif (strpos($toolUsed, 'Dispatch') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show today\'s dispatch', 'Show pending dispatches', 'Open Dispatch page'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['आज का डिस्पैच दिखाओ', 'पेंडिंग डिस्पैच दिखाओ', 'डिस्पैच पेज खोलो'];
        } else {
            $suggestions = ['আজকের ডিসপ্যাচ দেখাও', 'পেন্ডিং ডিসপ্যাচ দেখাও', 'ডিসপ্যাচ পেজ খোলো'];
        }
    } elseif (strpos($toolUsed, 'Dashboard') !== false || strpos($toolUsed, 'KPI') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show production summary', 'Show today\'s dispatch', 'Show pending jobs'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['प्रोडक्शन समरी दिखाओ', 'आज का डिस्पैच दिखाओ', 'पेंडिंग जॉब दिखाओ'];
        } else {
            $suggestions = ['প্রোডাকশন সামারি দেখাও', 'আজকের ডিসপ্যাচ দেখাও', 'পেন্ডিং জব দেখাও'];
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

$hasCompanyQuery = preg_match('/\b(krishna|austin|navkar|nrgi)\b/i', $prompt) || strpos($p, 'কৃষ্ণা') !== false || strpos($p, 'অস্টিন') !== false || strpos($p, 'নভকার') !== false || strpos($p, 'এনআরজিআই') !== false;
$hasDbQueryIntent = preg_match('/\b(die|dies|plate|plates|stock|inventory|search|find|any|is there|kono|ache)\b/i', $prompt);

$isMathIntent = !$hasCompanyQuery && !$hasDbQueryIntent && (
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

    $hasToolIntent = (strpos($p, 'plate') !== false || strpos($p, 'plates') !== false || strpos($p, 'die') !== false || strpos($p, 'anilox') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'ডাই') !== false || strpos($p, 'এনিলক্স') !== false || strpos($p, 'प्लेट') !== false);

    if ((!empty($compName) || !empty($paperTypeFilter)) && !$hasToolIntent) {
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
                . "📜 **জাম্বো রোল (চওড়া ≥ ১০০০ মিমি):** **" . number_format($jumboCount) . "টি জাম্বো রোল**\n"
                . "✂️ **স্লিটেড স্টক রোল (চওড়া < ১০০০ মিমি):** **" . number_format($slittedCount) . "টি রোল**\n\n"
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

    // 3. ERP Executive Dashboard & KPI Overview Intent Matcher
    if (strpos($p, 'dash') !== false || strpos($p, 'kpi') !== false || strpos($p, 'overview') !== false || strpos($p, 'metric') !== false || strpos($p, 'analytic') !== false || preg_match('/\b(stat|stats|statistics)\b/i', $prompt) || strpos($p, 'executive') !== false || strpos($p, 'ড্যাশবোর্ড') !== false) {

        $toolName = 'ERP Executive Dashboard & KPI Tool';
        
        $stockCount = (int)($db->query("SELECT COUNT(*) as c FROM paper_stock WHERE LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')")->fetch_assoc()['c'] ?? 0);
        $stockMtr = round((float)($db->query("SELECT IFNULL(SUM(length_mtr),0) as total FROM paper_stock WHERE LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')")->fetch_assoc()['total'] ?? 0), 2);
        $lowStock = (int)($db->query("SELECT COUNT(*) as c FROM paper_stock WHERE status='Available' AND length_mtr < 500")->fetch_assoc()['c'] ?? 0);

        $estimatesActive = (int)($db->query("SELECT COUNT(*) as c FROM estimates WHERE LOWER(COALESCE(status,'')) NOT IN ('rejected','converted','cancelled')")->fetch_assoc()['c'] ?? 0);
        $estimatesVal = round((float)($db->query("SELECT IFNULL(SUM(selling_price),0) as total FROM estimates WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetch_assoc()['total'] ?? 0), 2);

        $ordersActive = (int)($db->query("SELECT COUNT(*) as c FROM sales_orders WHERE LOWER(COALESCE(status,'')) NOT IN ('completed','dispatched','cancelled','closed')")->fetch_assoc()['c'] ?? 0);

        $jobsActive = (int)($db->query("SELECT COUNT(*) as c FROM planning WHERE LOWER(COALESCE(status,'')) NOT IN ('completed','closed','finalized','cancelled','done')")->fetch_assoc()['c'] ?? 0);
        $jobsRunning = (int)($db->query("SELECT COUNT(*) as c FROM jobs WHERE LOWER(status) = 'running'")->fetch_assoc()['c'] ?? 0);
        $jobsPending = (int)($db->query("SELECT COUNT(*) as c FROM jobs WHERE LOWER(status) IN ('pending','queued')")->fetch_assoc()['c'] ?? 0);
        $jobsCompletedMonth = (int)($db->query("SELECT COUNT(*) as c FROM jobs WHERE LOWER(status) IN ('closed','finalized','completed','qc passed') AND completed_at IS NOT NULL AND DATE_FORMAT(completed_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')")->fetch_assoc()['c'] ?? 0);

        $userLang = detect_language($prompt);
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
                . "  - लंबित / कतारबद्ध जॉब्स: **" . number_format($jobsPending) . " जॉब कार्ड**\n"
                . "  - इस महीने पूर्ण जॉब्स: **" . number_format($jobsCompletedMonth) . " जॉब्स**\n\n"
                . "💼 **बिक्री और अनुमान:**\n"
                . "  - सक्रिय बिक्री के आदेश: **" . number_format($ordersActive) . " ऑर्डर**\n"
                . "  - सक्रिय लागत अनुमान: **" . number_format($estimatesActive) . " अनुमान** (इस महीने का मूल्य: **₹" . number_format($estimatesVal, 2) . "**)\n\n"
                . "👉 [एक्जीक्यूटिव डैशबोर्ड पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/dashboard/index.php)";
        } else {
            $answer = "📊 **ইআরপি এক্সিকিউটিভ ড্যাশবোর্ড ও লাইভ কেপিআই ওভারভিউ:**\n\n"
                . "আপনার ইআরপি ড্যাশবোর্ড থেকে রিয়েল-টাইম রানিং ডাটা সম্বলিত ওভারভিউ:\n\n"
                . "📜 **পেপার রোল ইনভেন্টরি স্টক:**\n"
                . "  - সর্বমোট রেডি পেপার রোল: **" . number_format($stockCount) . "টি রোল** (" . number_format($stockMtr, 2) . " মিটার স্টক)\n"
                . "  - লো স্টক অ্যালার্ট (<৫০০মি.): **" . number_format($lowStock) . "টি রোল**\n\n"
                . "🏭 **প্রডাকশন ও লাইভ ফ্লোর প্রোগ্রেস:**\n"
                . "  - সচল মাস্টার প্রডাকশন জব: **" . number_format($jobsActive) . "টি জব**\n"
                . "  - বর্তমানে রানিং প্রডাকশন জব: **" . number_format($jobsRunning) . "টি জব**\n"
                . "  - পেন্ডিং / কিউড ডিপার্টমেন্ট জব: **" . number_format($jobsPending) . "টি জব কার্ড**\n"
                . "  - চলতি মাসে সম্পন্ন প্রডাকশন জব: **" . number_format($jobsCompletedMonth) . "টি জব**\n\n"
                . "💼 **সেলস অর্ডার ও কস্টিং এস্টিমেট:**\n"
                . "  - সচল রানিং সেলস অর্ডার: **" . number_format($ordersActive) . "টি অর্ডার**\n"
                . "  - সচল কস্টিং এস্টিমেট: **" . number_format($estimatesActive) . "টি এস্টিমেট** (চলতি মাসের মোট ভ্যালু: **₹" . number_format($estimatesVal, 2) . "**)\n\n"
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
    } elseif (strpos($p, 'dispatch') !== false || strpos($p, 'dispatched') !== false || strpos($p, 'ready queue') !== false || strpos($p, 'ready stock') !== false || strpos($p, 'challan') !== false || strpos($p, 'sales person') !== false || strpos($p, 'ডিসপ্যাচ') !== false || strpos($p, 'রেডি') !== false) {
        $toolName = 'Dispatch & Ready Queue Master Tool';

        // Ready Queue (Finished Goods ready to ship)
        $readyRows = $db->query("SELECT id, category, item_name, item_code, size, quantity, dispatch_qty_total, COALESCE(closing_stock, quantity - dispatch_qty_total) as ready_qty, unit, batch_no FROM finished_goods_stock WHERE COALESCE(closing_stock, quantity - dispatch_qty_total) > 0 ORDER BY id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);

        $totalReadyItems = count($readyRows);
        $totalReadyQty = 0;
        foreach ($readyRows as $rr) {
            $totalReadyQty += (float)$rr['ready_qty'];
        }

        // Dispatches Stats
        $dStatRes = $db->query("SELECT 
            COUNT(*) as total_dispatches,
            IFNULL(SUM(dispatch_qty),0) as total_dispatched_qty,
            IFNULL(SUM(transport_cost),0) as total_transport_cost,
            SUM(CASE WHEN LOWER(COALESCE(delivery_status,'')) IN ('pending','in transit','in_transit') THEN 1 ELSE 0 END) as pending_transit,
            SUM(CASE WHEN LOWER(COALESCE(delivery_status,'')) = 'delivered' THEN 1 ELSE 0 END) as delivered_cnt
            FROM dispatch_entries
        ")->fetch_assoc();

        $totalDispatches = (int)($dStatRes['total_dispatches'] ?? 0);
        $totalDispatchedQty = (float)($dStatRes['total_dispatched_qty'] ?? 0);
        $totalTransportCost = (float)($dStatRes['total_transport_cost'] ?? 0);
        $pendingTransit = (int)($dStatRes['pending_transit'] ?? 0);
        $deliveredCnt = (int)($dStatRes['delivered_cnt'] ?? 0);

        $userLang = detect_language($prompt);
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
                    . "  - 📦 **Ready Stock to Dispatch:** **" . number_format((float)$r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "👉 [Click here to open Dispatch Workspace]({$baseUrl}/modules/dispatch/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "🚚 **डिस्पैच और रेडी क्यू 3-टैब संचालन सारांश:**\n\n"
                . "📦 **1. रेडी क्यू (डिस्पैच के लिए तैयार - सेल टीम के लिए):**\n"
                . "  - रेडी स्टॉक आइटम: **" . number_format($totalReadyItems) . " आइटम**\n"
                . "  - भेजने के लिए कुल उपलब्ध मात्रा: **" . number_format($totalReadyQty) . " पीस**\n\n"
                . "🚚 **2. लाइव डिस्पैच विवरण:**\n"
                . "  - कुल डिस्पैच रिकॉर्ड: **" . number_format($totalDispatches) . " डिस्पैच**\n"
                . "  - कुल भेजी गई मात्रा: **" . number_format($totalDispatchedQty) . " पीस**\n"
                . "  - पेंडिंग / इन-ट्रांजिट: **" . number_format($pendingTransit) . " शिपमेंट**\n"
                . "  - कुल ट्रांसपोर्ट लागत: **₹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "📋 **रेडी स्टॉक सूची (डिस्पैच के लिए तैयार):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "• **आइटम " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}**)\n"
                    . "  - 📐 **साइज़:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 📦 **रेडी डिस्पैच स्टॉक:** **" . number_format((float)$r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "👉 [डिस्पैच पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/dispatch/index.php)";
        } else {
            $answer = "🚚 **ডিসপ্যাচ ও রেডি কিউ ৩-ট্যাব অপারেশনাল সামারি:**\n\n"
                . "📦 **১. রেডি কিউ (ডিসপ্যাচের জন্য প্রস্তুত স্টক - সেলস টিম ও অপারেটরদের জন্য):**\n"
                . "  - ওয়্যারহাউসে রেডি স্টক আইটেম: **" . number_format($totalReadyItems) . "টি ব্যাচ/আইটেম**\n"
                . "  - ডিসপ্যাচ বা ডেলিভারি দেওয়ার মতো মোট কোয়ান্টিটি: **" . number_format($totalReadyQty) . "টি পিস/লেবেল**\n\n"
                . "🚚 **২. লাইভ ডিসপ্যাচ অপারেশন সামারি:**\n"
                . "  - সর্বমোট ডিসপ্যাচ রেকর্ড: **" . number_format($totalDispatches) . "টি শিপমেন্ট**\n"
                . "  - সর্বমোট ডিসপ্যাচড কোয়ান্টিটি: **" . number_format($totalDispatchedQty) . "টি পিস**\n"
                . "  - পেন্ডিং ডেলিভারি / ইন-ট্রানজিট: **" . number_format($pendingTransit) . "টি শিপমেন্ট**\n"
                . "  - সফলভাবে ডেলিভারড: **" . number_format($deliveredCnt) . "টি শিপমেন্ট**\n"
                . "  - সর্বমোট ট্রান্সপোর্ট খরচ: **₹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "📋 **রেডি স্টক আইটেম তালিকা (ডেলিভারির জন্য প্রস্তুত):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "• **আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}** | ব্যাচ: `" . ($r['batch_no'] ?: 'N/A') . "`)\n"
                    . "  - 📐 **সাইজ ও স্পেক:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 📦 **রেডি ডিসপ্যাচ কোয়ান্টিটি:** **" . number_format((float)$r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "👉 [ডিসপ্যাচ পেজটি সরাসরি খুলতে এখানে ক্লিক করুন]({$baseUrl}/modules/dispatch/index.php)";
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

        $sum = $db->query("SELECT 
            COUNT(*) as total_items,
            IFNULL(SUM(quantity),0) as total_qty,
            IFNULL(SUM(dispatch_qty_total),0) as total_dispatch,
            IFNULL(SUM(COALESCE(closing_stock, quantity - dispatch_qty_total)),0) as total_closing
            FROM finished_goods_stock
        ")->fetch_assoc();

        $totalItems = (int)($sum['total_items'] ?? 0);
        $totalQty = (float)($sum['total_qty'] ?? 0);
        $totalDispatch = (float)($sum['total_dispatch'] ?? 0);
        $totalClosing = (float)($sum['total_closing'] ?? 0);

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

        $userLang = detect_language($prompt);
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
                    . "  - 🔢 **Packed Quantity:** **" . number_format((float)$r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🚚 **Dispatched:** **" . number_format((float)$r['dispatch_qty_total']) . " PCS** | Available Closing: **" . number_format((float)$r['available_closing']) . " PCS**\n"
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
                    . "  - 🔢 **पैक्ड मात्रा:** **" . number_format((float)$r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🚚 **उपलब्ध स्टॉक:** **" . number_format((float)$r['available_closing']) . " पीस**\n"
                    . "  - 🏷️ **बैच नंबर:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [फिनिश्ड गुड्स पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/inventory/finished/index.php)";
        } else {
            $answer = "📦 **ফিনিশড গুডস ও প্যাকড লেবেল স্টক — লাইভ ইনভентরি সামারি:**\n\n"
                . "📊 **ইনভেন্টরি সামারি ম্যাট্রিক্স:**\n"
                . "  - সর্বমোট ফিনিশড প্রোডাক্ট/ব্যাচ: **" . number_format($totalItems) . "টি আইটেম**\n"
                . "  - সর্বমোট প্যাকড কোয়ান্টিটি: **" . number_format($totalQty) . "টি পিস/লেবেল**\n"
                . "  - সর্বমোট ডিসপ্যাচড কোয়ান্টিটি: **" . number_format($totalDispatch) . "টি পিস**\n"
                . "  - সর্বমোট উপলব্ধ ক্লোজিং স্টক: **" . number_format($totalClosing) . "টি পিস**\n\n"
                . "📦 **মাস্টার ফিনিশড স্টক গ্রিড:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | ক্যাটাগরি: **" . strtoupper($r['category']) . "**)\n"
                    . "  - 📐 **সাইজ ও স্পেক:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **প্যাকড কোয়ান্টিটি:** **" . number_format((float)$r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🚚 **ডিসপ্যাচড:** **" . number_format((float)$r['dispatch_qty_total']) . "টি পিস** | উপলব্ধ ক্লোজিং স্টক: **" . number_format((float)$r['available_closing']) . "টি পিস**\n"
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

        $sessionUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 1;

        $currRes = $db->query("SELECT id, name, email, role, is_active, updated_at FROM users WHERE id = {$sessionUserId}");
        $currUser = $currRes ? $currRes->fetch_assoc() : null;

        $allRes = $db->query("SELECT id, name, email, role, is_active, updated_at FROM users WHERE is_active = 1 ORDER BY id ASC");
        $allUsers = $allRes ? $allRes->fetch_all(MYSQLI_ASSOC) : [];

        $userLang = detect_language($prompt);
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
                    . "  - ⏰ **अंतिम गतिविधि समय:** `" . $currUser['updated_at'] . "`\n\n";
            }

            $answer .= "👥 **पंजीकृत सक्रिय उपयोगकर्ता सूची (" . count($allUsers) . " उपयोगकर्ता):**\n\n";

            foreach ($allUsers as $u) {
                $answer .= "• **यूजर आईडी `#" . $u['id'] . "`: " . $u['name'] . "** (रोल: `" . strtoupper($u['role']) . "`)\n"
                    . "  - ⏰ **अंतिम गतिविधि:** `" . $u['updated_at'] . "`\n\n";
            }

            $answer .= "👉 [उपयोगकर्ता प्रबंधन पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/hr_management/users/index.php)";
        } else {
            $answer = "👤 **সিস্টেম ইউজার ও অ্যাক্টিভ লগইন সেশন সামারি:**\n\n";

            if ($currUser) {
                $answer .= "🔑 **বর্তমানে সক্রিয় লগইন সেশন ইউজার:**\n"
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
        $totalItems = (int)($sumRow['cnt'] ?? 0);
        $totalExtraQty = (float)($sumRow['total_extra'] ?? 0);

        $assignRes = $db->query("SELECT COUNT(*) as cnt FROM mixed_item_assignments WHERE status = 'pending'");
        $pendingAssign = $assignRes ? (int)($assignRes->fetch_assoc()['cnt'] ?? 0) : 0;

        $rows = $db->query("SELECT id, category, sub_type, item_name, item_code, size, quantity, unit, batch_no, date, remarks FROM finished_goods_stock WHERE category IN ('pos_paper_roll','one_ply','two_ply','barcode','printing_roll','printing_label') ORDER BY id DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);

        $userLang = detect_language($prompt);
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
                    . "  - 🔢 **Extra Quantity:** **" . number_format((float)$r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🏷️ **Batch / Job No:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [Click here to open Mixed Item Inventory Page]({$baseUrl}/modules/inventory/mixed-item/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "🔀 **मिक्सड आइटम और एक्स्ट्रा प्रोडक्शन पूल — लाइव इन्वेंटरी सारांश:**\n\n"
                . "📊 **पूल सारांश:**\n"
                . "  - कुल एक्स्ट्रा पूल बैच: **" . number_format($totalItems) . " आइटम**\n"
                . "  - कुल एक्स्ट्रा स्टॉक मात्रा: **" . number_format($totalExtraQty) . " पीस**\n"
                . "  - पेंडिंग हैंडओवर असाइनमेंट: **" . number_format($pendingAssign) . " असाइनमेंट**\n\n"
                . "🔀 **एक्टिव मिक्सड आइटम ग्रिड:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **आइटम " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}**)\n"
                    . "  - 📐 **साइज़:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **एक्स्ट्रा मात्रा:** **" . number_format((float)$r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - 🏷️ **बैच नंबर:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "👉 [मिक्सड आइटम पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/inventory/mixed-item/index.php)";
        } else {
            $answer = "🔀 **মিক্সড আইটেম ও এক্সট্রা প্রোডাকশন পুল — লাইভ ইনভেন্টরি সামারি:**\n\n"
                . "📊 **পূল ম্যাট্রিক্স সামারি:**\n"
                . "  - সর্বমোট এক্সট্রা পুল ব্যাচ: **" . number_format($totalItems) . "টি আইটেম**\n"
                . "  - সর্বমোট এক্সট্রা স্টক কোয়ান্টিটি: **" . number_format($totalExtraQty) . "টি পিস/লেবেল**\n"
                . "  - পেন্ডিং হ্যান্ডওভার অ্যাসাইনমেন্ট: **" . number_format($pendingAssign) . "টি অ্যাসাইনমেন্ট** (টার্গেট: প্যাকিং / প্ল্যানিং / রিপ্যাক)\n\n"
                . "🔀 **অ্যাক্টিভ মিক্সড এক্সট্রা আইটেম গ্রিড:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "• **এক্সট্রা আইটেম " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}** | ক্যাটাগরি: **" . strtoupper($r['category']) . "**)\n"
                    . "  - 📐 **সাইজ ও স্পেক:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - 🔢 **এক্সট্রা কোয়ান্টিটি:** **" . number_format((float)$r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
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

    if (strpos($p, 'live') !== false || strpos($p, 'floor') !== false || strpos($p, 'stage') !== false || strpos($p, 'next department') !== false || strpos($p, 'journey') !== false || strpos($p, 'current job') !== false || strpos($p, 'running job') !== false || strpos($p, 'লাইভ') !== false || strpos($p, 'ফ্লোর') !== false) {
        $toolName = 'Live Production Floor Pipeline Tool';
        
        $liveStopwords = ['can', 'you', 'what', 'is', 'the', 'status', 'of', 'current', 'job', 'jobs', 'running', 'live', 'floor', 'show', 'tell', 'me', 'details', 'for', 'in', 'page', 'kholo', 'khul', 'open', 'go', 'to', 'dekhao', 'dekaw', 'batao'];
        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9\/]/', '', $w));
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
    } elseif ((strpos($p, 'company') !== false || strpos($p, 'companies') !== false || strpos($p, 'brand') !== false || strpos($p, 'supplier') !== false) && strpos($p, 'pp') === false && strpos($p, 'white') === false && strpos($p, 'chromo') === false && strpos($p, 'thermal') === false && strpos($p, 'jumbo') === false && strpos($p, 'slitting') === false) {

        $isCompanyList = true;
        $toolName = 'Paper Company Summary Tool';
        $res = $db->query("SELECT company, COUNT(*) as roll_count, SUM(length_mtr) as total_meters FROM paper_stock WHERE status IN ('Main','Stock','Job Assign') AND company != '' GROUP BY company ORDER BY roll_count DESC");
        $data = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
        $totalCount = count($data);
    } elseif (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'প্লেট') !== false || strpos($p, 'प्लेट') !== false) {

        $toolName = 'Printing Plates Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
        $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

        // --- Count-Only Intent Handler ---
        if (is_count_intent($prompt)) {
            $userLang = detect_language($prompt);
            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            // Fetch breakdown stats for richer response
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
                    $directAnswer .= "🆕 **সর্বশেষ যুক্ত প্লেট:** `{$latest['name']}` (SL: {$latest['sl_no']})";
                    if (!empty($latest['date_received']) && $latest['date_received'] !== 'NA') {
                        $directAnswer .= " — তারিখ: {$latest['date_received']}";
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
            'can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'plate', 'plates', 'list', 'show', 'details', 'detail', 'this', 'the', 'a', 'an',
            'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does',
            'repeat', 'gap', 'gaph', 'gapv', 'size', 'ups', 'cylinder', 'paper', 'die', 'core', 'rewinding', 'value', 'color', 'colors', 'spec', 'special', 'what', 'how', 'give',
            'if', 'run', 'running', 'much', 'many', 'quantity', 'qty', 'meter', 'meters', 'mtr', 'will', 'be', 'produced', 'print', 'printing', 'require', 'required', 'need', 'needed', 'or', 'and',
            'calculate', 'calculating', 'calc', 'length', 'roll', 'pcs', 'pieces', 'labels',

            'koto', 'kotogulo', 'hobe', 'lagbe', 'korle', 'korte', 'asob', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'হবে', 'আছে', 'কত', 'কতগুলো', 'কি', 'কী', 'কোন'
        ];


        $pWords = preg_split('/\s+/', strtolower($prompt));
        $terms = [];
        foreach ($pWords as $w) {
            $wClean = trim(preg_replace('/[^a-z0-9]/', '', $w));
            if ($wClean !== '' && !in_array($wClean, $stopwords, true) && strlen($wClean) >= 2) {
                // Skip standalone calculation numbers (e.g. 2000, 5000, 1500) from plate name search
                if (is_numeric($wClean) && (float)$wClean >= 100 && !preg_match('/(ml|mm|cm|inc|inch)/i', $w)) {
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
                $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE sl_no = ? OR id = ? ORDER BY id DESC LIMIT 5");
                $stmt->bind_param('ss', $num, $num);
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
            $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ? OR sl_no = ? OR id = ? ORDER BY id DESC LIMIT 10");
            $stmt->bind_param('ssssss', $like, $like, $like, $like, $searchTerm, $searchTerm);
            $stmt->execute();
            $data = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // 3. If empty, try matching individual terms
            if (empty($data) && count($terms) > 1) {
                $likes = array_map(function($t) { return '%' . $t . '%'; }, $terms);
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
        $isCalcQuery = (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'pcs') !== false || strpos($p, 'run') !== false || strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false);

        if ($isCalcQuery && !empty($data)) {
            $plate = $data[0];
            $name = $plate['name'];
            $ups = max(1, (int)$plate['ups']);
            $repeatVal = (float)$plate['repeat_value'];
            
            if ($repeatVal <= 0) {
                if (preg_match('/x\s*([\d\.]+)/i', $plate['size'] ?? '', $m)) {
                    $height = (float)$m[1];
                    $gapV = (float)($plate['gap_v'] ?? 0);
                    $repeatVal = $height + $gapV;
                } else {
                    $repeatVal = 100.0;
                }
            }

            $targetQty = null;
            $paperMeters = null;

            if (preg_match('/([\d,]+)\s*(meters|meter|mtr|m)\b/i', $prompt, $m)) {
                $paperMeters = (float)str_replace(',', '', $m[1]);
            } elseif (preg_match('/(run|length|roll|paper)\s*(of|about|with)?\s*([\d,]+)/i', $prompt, $m)) {
                $paperMeters = (float)str_replace(',', '', $m[3]);
            }

            if (preg_match('/([\d,]+)\s*(qty|quantity|pcs|pieces|labels)\b/i', $prompt, $m)) {
                $targetQty = (float)str_replace(',', '', $m[1]);
            } elseif (preg_match('/(print|producing|make|quantity|qty)\s*(of|about)?\s*([\d,]+)/i', $prompt, $m)) {
                $targetQty = (float)str_replace(',', '', $m[3]);
            }

            if ($targetQty === null && $paperMeters === null && count($searchNums) >= 2) {
                // If two numbers present (e.g. Plate 1032 with 15000 qty)
                $numCandidate = (float)$searchNums[count($searchNums) - 1];
                if (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'run') !== false) {
                    $paperMeters = $numCandidate;
                } else {
                    $targetQty = $numCandidate;
                }
            } elseif ($targetQty === null && $paperMeters === null && !empty($searchNums)) {
                $num = (float)$searchNums[0];
                if ($num != (float)$plate['sl_no'] && $num != (float)$plate['id']) {
                    if (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'run') !== false) {
                        $paperMeters = $num;
                    } else {
                        $targetQty = $num;
                    }
                }
            }

            $userLang = detect_language($prompt);
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

                $answer .= "👉 [प्लेट प्रबंधन पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } else {
                $answer = "📐 **ফ্লেক্সো প্রিন্টিং ও প্লেট প্রডাকশন ক্যালকুলেটর:**\n\n"
                    . "📋 **জব / প্লেটের নাম:** `{$name}` (SL No: **{$plate['sl_no']}** | ID: **{$plate['id']}**)\n"
                    . "⚙️ **প্লেট স্পেসিফিকেশন:** আফস (Ups): **{$ups}** | রিপিট ভ্যালু: **{$repeatVal}mm** | সাইজ: **{$plate['size']}**\n\n";

                if ($targetQty !== null && $targetQty > 0) {
                    $revs = $targetQty / $ups;
                    $rawMeters = ($revs * $repeatVal) / 1000.0;
                    $wastageMeters = $rawMeters * 1.05;
                    $answer .= "🎯 **টার্গেট কোয়ান্টিটি (Target Quantity):** **" . number_format($targetQty) . "টি**\n"
                        . "📏 **প্রয়োজনীয় নেট পেপার:** **" . number_format($rawMeters, 2) . " মিটার**\n"
                        . "🛡️ **সর্বমোট পেপার (৫% সেটআপ ওয়েস্টেজসহ):** **" . number_format($wastageMeters, 2) . " মিটার**\n\n";
                }

                if ($paperMeters !== null && $paperMeters > 0) {
                    $totalMM = $paperMeters * 1000.0;
                    $revs = $totalMM / $repeatVal;
                    $totalQty = floor($revs * $ups);
                    $answer .= "📜 **পেপার রোলের দৈর্ঘ্য (Paper Roll Length):** **" . number_format($paperMeters, 2) . " মিটার**\n"
                        . "📦 **প্রত্যাশিত মোট প্রডাকশন কোয়ান্টিটি:** **" . number_format($totalQty) . "টি লেবেল/পিস**\n\n";
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

    } elseif (strpos($p, 'die') !== false || strpos($p, 'tooling') !== false || strpos($p, 'barcode') !== false) {
        $toolName = 'Die Tooling & Barcode Die Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_die_tooling");
        $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

        // --- Count-Only Intent Handler ---
        if (is_count_intent($prompt)) {
            $userLang = detect_language($prompt);
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
                        $directAnswer .= "   ▸ **{$ts['die_type']}** — {$ts['cnt']} डाई\n";
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
                        $directAnswer .= "   ▸ **{$ts['die_type']}** — {$ts['cnt']}টি ডাই\n";
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
                    $directAnswer .= "🆕 **সর্বশেষ ডাই:** `{$latest['barcode_size']}` (SL: {$latest['sl_no']}) — ক্যাটাগরি: {$latest['used_for']}\n\n";
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
                        $directAnswer .= "   ▸ **{$ts['die_type']}** — {$ts['cnt']} dies\n";
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
            $userLang = detect_language($prompt);
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
            $userLang = detect_language($prompt);
            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            if ($userLang === 'English') {
                $answer = "📏 **Barcode Die Management & Tooling Master — Specifications:**\n\nFound **" . count($data) . " matching die record(s)** in your ERP database:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **Die " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 📐 **Barcode Size:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - 🔢 **Ups (Roll / Die):** Roll Ups: **" . ($row['ups_in_roll'] ?: '1') . "** | Die Ups: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - 📏 **Repeat Size:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **Label Gap:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - ⚙️ **Die Type & Cylinder:** **" . ($row['die_type'] ?: 'Rotary') . "** | Cylinder: **" . ($row['cylender'] ?: 'N/A') . "**\n"
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
                        . "  - ⚙️ **डाई प्रकार व सिलेंडर:** **" . ($row['die_type'] ?: 'Rotary') . "** | सिलेंडर: **" . ($row['cylender'] ?: 'N/A') . "**\n\n";
                }
                $answer .= "👉 [बारकोड डाई प्रबंधन पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } else {
                $answer = "📏 **বারকোড ডাই ম্যানেজমেন্ট ও টূলিং মাস্টার স্পেসিফিকেশন:**\n\nআপনার ইআরপি ডাটাবেসে **" . count($data) . "টি ম্যাচিং ডাই রেকর্ড** পাওয়া গেছে:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **ডাই " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 📐 **বারকোড সাইজ:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - 🔢 **আফস (Roll / Die):** রোল আফস: **" . ($row['ups_in_roll'] ?: '1') . "** | ডাই আফস: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - 📏 **রিপিট সাইজ:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **লেবেল গ্যাপ:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - ⚙️ **ডাই টাইপ ও সিলিন্ডার:** **" . ($row['die_type'] ?: 'Rotary') . "** | সিলিন্ডার: **" . ($row['cylender'] ?: 'N/A') . "**\n"
                        . "  - 📄 **পেপার সাইজ ও কোর:** পেপার সাইজ: **" . ($row['paper_size'] ?: 'N/A') . "** | কোর: **" . ($row['core'] ?: 'N/A') . "**\n"
                        . "  - 📦 **পিস পার রোল:** **" . ($row['pices_per_roll'] ?: 'N/A') . "** | ক্যাটাগরি: **" . ($row['used_for'] ?: 'Barcode') . "**\n\n";
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
        $totalCount = $cntRes ? (int)($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

        // --- Count-Only Intent Handler ---
        if (is_count_intent($prompt)) {
            $userLang = detect_language($prompt);
            $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

            // Fetch breakdown stats
            $totalStockRes = $db->query("SELECT SUM(CAST(stock_qty AS UNSIGNED)) as total_stock FROM master_anilox_data WHERE stock_qty IS NOT NULL");
            $totalStock = $totalStockRes ? (int)($totalStockRes->fetch_assoc()['total_stock'] ?? 0) : 0;
            $lpiRange = $db->query("SELECT MIN(CAST(anilox_lpi AS UNSIGNED)) as min_lpi, MAX(CAST(anilox_lpi AS UNSIGNED)) as max_lpi FROM master_anilox_data WHERE anilox_lpi IS NOT NULL AND anilox_lpi != ''");
            $lpiR = $lpiRange ? $lpiRange->fetch_assoc() : null;
            $inStockRes = $db->query("SELECT COUNT(*) as cnt FROM master_anilox_data WHERE CAST(stock_qty AS UNSIGNED) > 0");
            $inStockCount = $inStockRes ? (int)($inStockRes->fetch_assoc()['cnt'] ?? 0) : 0;
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
            if ($wClean !== '' && !in_array($wClean, $stopwords, true)) {
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
            $userLang = detect_language($prompt);
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
            $userLang = detect_language($prompt);
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
                $answer = "🌀 **एनिलॉक्स प्रबंधन और इन्वेंटरी स्टॉक विवरण:**\n\nआपके ईआरपी डेटाबेस में **" . count($data) . " मैचिंग एनिलॉक्स रोल रिकॉर्ड** मिले हैं:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **एनिलॉक्स " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}**)\n"
                        . "  - 🌀 **एनिलॉक्स एलपीआई (LPI):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - 🧪 **एनिलॉक्स वॉल्यूम (BCM):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - 📦 **उपलब्ध स्टॉक मात्रा:** **" . ($row['stock_qty'] ?: '0') . " रोल**\n\n";
                }
                $answer .= "👉 [एनिलॉक्स प्रबंधन पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } else {
                $answer = "🌀 **এনিলক্স ম্যানেজমেন্ট ও ইনভেন্টরি স্টক স্পেসিফিকেশন:**\n\nআপনার ইআরপি ডাটাবেসে **" . count($data) . "টি ম্যাচিং এনিলক্স রোল রেকর্ড** পাওয়া গেছে:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "• **এনিলক্স " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - 🌀 **এনিলক্স এলপিআই (LPI):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - 🧪 **এনিলক্স ভলিউম (BCM / BMC):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - 📦 **উপলব্ধ স্টক কোয়ান্টিটি:** **" . ($row['stock_qty'] ?: '0') . "টি রোল**\n\n";
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
            $widthMm = (float)$m[1];
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
                $where[] = "(paper_type LIKE '%pp-white%' OR paper_type LIKE '%pp white%' OR paper_type LIKE '%pp_white%')";
            } elseif ($matchedType === 'pp clear' || $matchedType === 'pp-clear') {
                $where[] = "(paper_type LIKE '%pp-clear%' OR paper_type LIKE '%pp clear%' OR paper_type LIKE '%pp_clear%')";
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

        $totalCount = (int)($summary['roll_count'] ?? 0);
        $totalMeters = round((float)($summary['total_mtr'] ?? 0), 2);
        $totalSqm = round((float)($summary['total_sqm'] ?? 0), 2);

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

        $jumboRolls = (int)($deepData['jumbo_rolls'] ?? 0);
        $jumboMtr = round((float)($deepData['jumbo_mtr'] ?? 0), 2);
        $slittedRolls = (int)($deepData['slitted_rolls'] ?? 0);
        $slittedMtr = round((float)($deepData['slitted_mtr'] ?? 0), 2);
        $latestEntryDate = $deepData['max_date'] ?: 'N/A';

        // Company Breakdown List
        $compSql = "SELECT company, COUNT(*) as rolls, SUM(length_mtr) as total_mtr FROM paper_stock WHERE {$whereSql} AND company != '' GROUP BY company ORDER BY rolls DESC LIMIT 8";
        $stmtComp = $db->prepare($compSql);
        if (!empty($params)) {
            $stmtComp->bind_param($typesStr, ...$params);
        }
        $stmtComp->execute();
        $companyList = $stmtComp->get_result()->fetch_all(MYSQLI_ASSOC);

        $userLang = detect_language($prompt);
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
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . " Rolls** (" . number_format((float)$cb['total_mtr'], 2) . " meters)\n";
                }
                $answer .= "\n";
            }

            $answer .= "📦 **Master Roll Grid (Sample " . count($data) . " Rolls):**\n\n";

            foreach ($data as $idx => $r) {
                $answer .= "• **Roll " . ($idx + 1) . ": `" . ($r['roll_no'] ?: 'Roll #' . $r['id']) . "`** (ID: **{$r['id']}** | Status: **{$r['status']}**)\n"
                    . "  - 🏷️ **Brand & Type:** **" . ($r['company'] ?: 'General') . " " . ($r['paper_type'] ?: 'Substrate') . "**\n"
                    . "  - 📐 **Dimensions:** Width: **" . ($r['width_mm'] ?: '0') . "mm** | Length: **" . number_format((float)$r['length_mtr'], 2) . "m**\n"
                    . "  - 📐 **Surface Area:** **" . number_format((float)$r['sqm'], 2) . " SQM**\n"
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
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . " रोल** (" . number_format((float)$cb['total_mtr'], 2) . " मीटर)\n";
                }
                $answer .= "\n";
            }

            $answer .= "👉 [पेपर स्टॉक पेज खोलने के लिए यहाँ क्लिक करें]({$baseUrl}/modules/paper_stock/index.php)";
        } else {
            $answer = "📜 **{$titleHeading} — সম্পূর্ণ টেকনিক্যাল ইনভেন্টরি সামারি:**\n\n"
                . "📊 **সর্বমোট ইনভেন্টরি ম্যাট্রিক্স:**\n"
                . "  - সর্বমোট রেডি পেপার রোল: **" . number_format($totalCount) . "টি রোল**\n"
                . "  - সর্বমোট রানিং দৈর্ঘ্য: **" . number_format($totalMeters, 2) . " মিটার**\n"
                . "  - সর্বমোট সারফেস এরিয়া (SQM): **" . number_format($totalSqm, 2) . " SQM**\n"
                . "  - সর্বশেষ এন্ট্রি তারিখ: `" . $latestEntryDate . "`\n\n"
                . "🏭 **জম্বো রোল বনাম স্লিসিং ব্রেকডাউন:**\n"
                . "  - 📜 **জম্বো প্যারেন্ট রোল (≥১০০০মিমি):** **" . number_format($jumboRolls) . "টি রোল** (" . number_format($jumboMtr, 2) . " মিটার)\n"
                . "  - ✂️ **স্লিটেড স্টক রোল (<১০০০মিমি):** **" . number_format($slittedRolls) . "টি রোল** (" . number_format($slittedMtr, 2) . " মিটার)\n\n";

            if (!empty($companyList) && count($companyList) > 1) {
                $answer .= "🏢 **উপলব্ধ কোম্পানি / ব্র্যান্ড তালিকা:**\n";
                foreach ($companyList as $cb) {
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . "টি রোল** (" . number_format((float)$cb['total_mtr'], 2) . " মিটার)\n";
                }
                $answer .= "\n";
            }

            $answer .= "📦 **মাস্টার রোল গ্রিড (স্যাম্পল " . count($data) . "টি রোল):**\n\n";

            foreach ($data as $idx => $r) {
                $answer .= "• **রোল " . ($idx + 1) . ": `" . ($r['roll_no'] ?: 'Roll #' . $r['id']) . "`** (ID: **{$r['id']}** | স্ট্যাটাস: **{$r['status']}**)\n"
                    . "  - 🏷️ **ব্র্যান্ড ও পেপার টাইপ:** **" . ($r['company'] ?: 'General') . " " . ($r['paper_type'] ?: 'Substrate') . "**\n"
                    . "  - 📐 **সাইজ ও দৈর্ঘ্য:** প্রস্থ: **" . ($r['width_mm'] ?: '0') . "mm** | দৈর্ঘ্য: **" . number_format((float)$r['length_mtr'], 2) . "m**\n"
                    . "  - 📐 **সারফেস এরিয়া (SQM):** **" . number_format((float)$r['sqm'], 2) . " SQM**\n"
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
    }
 elseif (preg_match('/\b(job|jobs|card|cards|order|orders|flx|lsl|jmb|pck|brc|status|progress|work)\b/i', $prompt)) {
        $toolName = 'ERP Jobs & Planning Tool';

        // Extract search term from prompt
        $jobStopwords = ['can', 'you', 'of', 'give', 'me', 'the', 'detail', 'details', 'about', 'job', 'jobs', 'name', 'named', 'by', 'how', 'many', 'we', 'have', 'is', 'are', 'in', 'show', 'tell', 'list', 'find', 'search', 'for', 'a', 'an', 'what', 'which', 'please'];
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
                    $userLang = detect_language($prompt);
                    $sampleCount = count($plateData);
                    if ($userLang === 'English') {
                        $answer = "📊 **Printing Plates Master Tool — Technical Specifications:**\n\nFound **{$sampleCount} matching plate record(s)** in your ERP database:\n\n";
                        foreach ($plateData as $idx => $row) {
                            $answer .= "• **Plate " . ($idx + 1) . ": `{$row['name']}`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
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
                            $answer .= "• **প্লেট " . ($idx + 1) . ": `{$row['name']}`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
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
                $userLang = detect_language($prompt);
                $sampleCount = count($plateData);
                if ($userLang === 'English') {
                    $answer = "📊 **Printing Plates Master Tool — Technical Specifications:**\n\nFound **{$sampleCount} matching plate record(s)** in your ERP database:\n\n";
                    foreach ($plateData as $idx => $row) {
                        $answer .= "• **Plate " . ($idx + 1) . ": `{$row['name']}`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
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
                        $answer .= "• **প্লেট " . ($idx + 1) . ": `{$row['name']}`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
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

// Generate follow-up suggestion chips
$suggestions = !empty($retrieved['suggestions'])
    ? $retrieved['suggestions']
    : get_follow_up_suggestions($toolUsed, $prompt, $userLang);

$responsePayload = [
    'ok'          => true,
    'answer'      => $finalAnswer,
    'provider'    => ($config['gemini_api_key'] !== '' ? 'Gemini Pro API' : 'ERP Smart RAG Engine'),
    'tool_used'   => $toolUsed,
    'user_lang'   => $userLang,
    'total_count' => $totalCount,
    'suggestions' => $suggestions
];

if (!empty($retrieved['nav_url'])) {
    $responsePayload['nav_url'] = $retrieved['nav_url'];
}

echo json_encode($responsePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

