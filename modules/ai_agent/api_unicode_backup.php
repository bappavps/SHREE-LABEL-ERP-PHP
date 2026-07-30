<?php
// ============================================================
// Standalone AI Agent Add-On Module â€” API Engine (Multilingual & Industrial Label Math)
// ERP Master System â€” 100% Isolated Add-On Module
// LOCAL USE ONLY â€” SAFE: Read-Only Data Connectors for ERP Tables
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
function format_records_table(array $data, string $type = 'paper_stock', string $lang = 'English'): string
{
    if (empty($data))
        return '';
    $count = count($data);
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
            $tbl .= "\n**Total: {$count} rolls** | **" . number_format($totalMtr, 1) . " meters** | **" . number_format($totalSqm, 1) . " SQM**\n";
            $tbl .= "\nðŸ‘‰ [Open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
        } elseif ($lang === 'Hindi') {
            $tbl = "| # | à¤°à¥‹à¤² à¤¨à¤‚. | à¤Ÿà¤¾à¤‡à¤ª | à¤•à¤‚à¤ªà¤¨à¥€ | à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ | à¤²à¤‚à¤¬à¤¾à¤ˆ | SQM | à¤¸à¥à¤¥à¤¿à¤¤à¤¿ |\n";
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
            $tbl .= "\n**à¤•à¥à¤²: {$count} à¤°à¥‹à¤²** | **" . number_format($totalMtr, 1) . " à¤®à¥€à¤Ÿà¤°** | **" . number_format($totalSqm, 1) . " SQM**\n";
            $tbl .= "\nðŸ‘‰ [à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¤• à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/paper_stock/index.php)";
        } else {
            $tbl = "| # | à¦°à§‹à¦² à¦¨à¦‚ | à¦Ÿà¦¾à¦‡à¦ª | à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿ | à¦šà¦“à¦¡à¦¼à¦¾ | à¦¦à§ˆà¦°à§à¦˜à§à¦¯ | SQM | à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸ |\n";
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
            $tbl .= "\n**à¦®à§‹à¦Ÿ: {$count}à¦Ÿà¦¿ à¦°à§‹à¦²** | **" . number_format($totalMtr, 1) . " à¦®à¦¿à¦Ÿà¦¾à¦°** | **" . number_format($totalSqm, 1) . " SQM**\n";
            $tbl .= "\nðŸ‘‰ [à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨]({$baseUrl}/modules/paper_stock/index.php)";
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
            $tbl = "| # | à¦ªà§à¦²à§‡à¦Ÿ à¦¨à¦¾à¦® | SL No | à¦°à¦¿à¦ªà¦¿à¦Ÿ | Gap H | Gap V | à¦¸à¦¾à¦‡à¦œ | à¦†à¦«à¦¸ | à¦•à¦¾à¦—à¦œ | à¦¡à¦¾à¦‡ |\n";
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
            $tbl .= "\n**à¦•à§à¦²: {$count} à¦ªà§à¦²à§‡à¦Ÿ**\n";
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
    return (bool) preg_match('/\b(how many|how much|total|count|kitne|kitna|koto|kotogulo|à¦•à¦¤|à¦•à¦¤à¦—à§à¦²à§‹|à¤•à¤¿à¤¤à¤¨à¥‡|à¤•à¤¿à¤¤à¤¨à¤¾)\b/i', $p);
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
                    'Flat Bed à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¤¿à¤¤à¤¨à¥€ à¤¹à¥ˆà¤‚?',
                    'Rotary à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¤¿à¤¤à¤¨à¥€ à¤¹à¥ˆà¤‚?',
                    'Alpha Flex à¤•à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¤¿à¤–à¤¾à¤“',
                    'à¤¸à¤¬à¤¸à¥‡ à¤¨à¤ˆ à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¥Œà¤¨ à¤¸à¥€ à¤¹à¥ˆ?',
                    'à¤ªà¥à¤²à¥‡à¤Ÿ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‹',
                ];
            } elseif ($userLang === 'Bengali') {
                $suggestions = [
                    'Flat Bed à¦ªà§à¦²à§‡à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹?',
                    'Rotary à¦ªà§à¦²à§‡à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹?',
                    'Alpha Flex à¦à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“',
                    'à¦¸à¦°à§à¦¬à¦¶à§‡à¦· à¦ªà§à¦²à§‡à¦Ÿà¦Ÿà¦¿ à¦•à§€?',
                    'à¦ªà§à¦²à§‡à¦Ÿ à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨',
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
                    'à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¹à¥ˆà¤‚?',
                    'à¤‡à¤¸ à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¤¾ à¤®à¥€à¤Ÿà¤° à¤•à¥ˆà¤²à¤•à¥à¤²à¥‡à¤Ÿ à¤•à¤°à¥‹',
                    'Chromo à¤ªà¥‡à¤ªà¤° à¤µà¤¾à¤²à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¤¿à¤–à¤¾à¤“',
                    '9 inch à¤¸à¤¿à¤²à¥‡à¤‚à¤¡à¤° à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¤¿à¤–à¤¾à¤“',
                    'à¤ªà¥à¤²à¥‡à¤Ÿ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‹',
                ];
            } elseif ($userLang === 'Bengali') {
                $suggestions = [
                    'à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§à¦²à§‡à¦Ÿ à¦†à¦›à§‡?',
                    'à¦‡à¦¸ à¦ªà§à¦²à§‡à¦Ÿà§‡à¦° à¦®à¦¿à¦Ÿà¦¾à¦° à¦•à§à¦¯à¦¾à¦²à¦•à§à¦²à§‡à¦Ÿ à¦•à¦°à§‹',
                    'Chromo à¦ªà§‡à¦ªà¦¾à¦°à§‡à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“',
                    '9 inch à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“',
                    'à¦ªà§à¦²à§‡à¦Ÿ à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨',
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
                'à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥€ à¤¡à¤¾à¤ˆ à¤¹à¥ˆà¤‚?',
                'Rotary à¤¡à¤¾à¤ˆ à¤¦à¤¿à¤–à¤¾à¤“',
                'Flat Bed à¤¡à¤¾à¤ˆ à¤¦à¤¿à¤–à¤¾à¤“',
                'à¤¡à¤¾à¤ˆ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‹',
            ];
        } else {
            $suggestions = [
                'à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦¡à¦¾à¦‡ à¦†à¦›à§‡?',
                'Rotary à¦¡à¦¾à¦‡ à¦¦à§‡à¦–à¦¾à¦“',
                'Flat Bed à¦¡à¦¾à¦‡ à¦¦à§‡à¦–à¦¾à¦“',
                'à¦¡à¦¾à¦‡ à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨',
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
                'à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥‡ à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤¹à¥ˆà¤‚?',
                '400 LPI à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤¦à¤¿à¤–à¤¾à¤“',
                'à¤•à¥Œà¤¨ à¤¸à¤¾ à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤¸à¥à¤Ÿà¥‰à¤• à¤®à¥‡à¤‚ à¤¨à¤¹à¥€à¤‚?',
                'à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‹',
            ];
        } else {
            $suggestions = [
                'à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦†à¦›à§‡?',
                '400 LPI à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦¦à§‡à¦–à¦¾à¦“',
                'à¦•à§‹à¦¨ à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦¸à§à¦Ÿà¦•à§‡ à¦¨à§‡à¦‡?',
                'à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨',
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
                'à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥‡ à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤² à¤¹à¥ˆà¤‚?',
                'Chromo à¤ªà¥‡à¤ªà¤° à¤¦à¤¿à¤–à¤¾à¤“',
                'PP White à¤¸à¥à¤Ÿà¥‰à¤• à¤¦à¤¿à¤–à¤¾à¤“',
                'à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¤• à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‹',
            ];
        } else {
            $suggestions = [
                'à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦² à¦†à¦›à§‡?',
                'Chromo à¦ªà§‡à¦ªà¦¾à¦° à¦¦à§‡à¦–à¦¾à¦“',
                'PP White à¦¸à§à¦Ÿà¦• à¦¦à§‡à¦–à¦¾à¦“',
                'à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨',
            ];
        }
    } elseif (strpos($toolUsed, 'Dispatch') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show today\'s dispatch', 'Show pending dispatches', 'Open Dispatch page'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['à¤†à¤œ à¤•à¤¾ à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤¦à¤¿à¤–à¤¾à¤“', 'à¤ªà¥‡à¤‚à¤¡à¤¿à¤‚à¤— à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤¦à¤¿à¤–à¤¾à¤“', 'à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‹'];
        } else {
            $suggestions = ['à¦†à¦œà¦•à§‡à¦° à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦¦à§‡à¦–à¦¾à¦“', 'à¦ªà§‡à¦¨à§à¦¡à¦¿à¦‚ à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦¦à§‡à¦–à¦¾à¦“', 'à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦ªà§‡à¦œ à¦–à§‹à¦²à§à¦¨'];
        }
    } elseif (strpos($toolUsed, 'Dashboard') !== false || strpos($toolUsed, 'KPI') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show production summary', 'Show today\'s dispatch', 'Show pending jobs'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['à¤ªà¥à¤°à¥‹à¤¡à¤•à¥à¤¶à¤¨ à¤¸à¤®à¤°à¥€ à¤¦à¤¿à¤–à¤¾à¤“', 'à¤†à¤œ à¤•à¤¾ à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤¦à¤¿à¤–à¤¾à¤“', 'à¤ªà¥‡à¤‚à¤¡à¤¿à¤‚à¤— à¤œà¥‰à¤¬ à¤¦à¤¿à¤–à¤¾à¤“'];
        } else {
            $suggestions = ['à¦ªà§à¦°à§‹à¦¡à¦¾à¦•à¦¶à¦¨ à¦¸à¦¾à¦®à¦¾à¦°à¦¿ à¦¦à§‡à¦–à¦¾à¦“', 'à¦†à¦œà¦•à§‡à¦° à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦¦à§‡à¦–à¦¾à¦“', 'à¦ªà§‡à¦¨à§à¦¡à¦¿à¦‚ à¦œà¦¬ à¦¦à§‡à¦–à¦¾à¦“'];
        }
    } elseif (strpos($toolUsed, 'Job') !== false || strpos($toolUsed, 'Planning') !== false) {
        if ($userLang === 'English') {
            $suggestions = ['Show pending jobs', 'Show live floor status', 'Open Job Planning page'];
        } elseif ($userLang === 'Hindi') {
            $suggestions = ['à¤ªà¥‡à¤‚à¤¡à¤¿à¤‚à¤— à¤œà¥‰à¤¬ à¤¦à¤¿à¤–à¤¾à¤“', 'à¤²à¤¾à¤‡à¤µ à¤«à¥à¤²à¥‹à¤° à¤¸à¥à¤Ÿà¥‡à¤Ÿà¤¸ à¤¦à¤¿à¤–à¤¾à¤“', 'à¤œà¥‰à¤¬ à¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤— à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‹'];
        } else {
            $suggestions = ['à¦ªà§‡à¦¨à§à¦¡à¦¿à¦‚ à¦œà¦¬ à¦¦à§‡à¦–à¦¾à¦“', 'à¦²à¦¾à¦‡à¦­ à¦«à§à¦²à§‹à¦° à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸ à¦¦à§‡à¦–à¦¾à¦“', 'à¦œà¦¬ à¦ªà§à¦²à§à¦¯à¦¾à¦¨à¦¿à¦‚ à¦ªà§‡à¦œ à¦–à§‹à¦²à§‹'];
        }
    }

    return $suggestions;
}

/**
 * Call external LLM API (Google Gemini, OpenAI, OpenRouter, OpenCode, Local LLM)
 */
function call_llm_api(string $prompt, array $config): ?string
{
    $provider = strtolower($config['default_provider'] ?? 'openrouter');
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
        // Custom API Endpoint â€” parse model string "custom:label:url:model"
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
        // Some models (e.g. DeepSeek) put content in reasoning_content
        if (!empty($result['choices'][0]['message']['reasoning_content'])) {
            return trim($result['choices'][0]['message']['reasoning_content']);
        }
    }

    // Also check if response has choices but in a different format
    if (isset($result['choices'][0]['message'])) {
        $msg = $result['choices'][0]['message'];
        if (is_string($msg)) return trim($msg);
        if (is_array($msg) && !empty($msg)) {
            $text = reset($msg);
            if (is_string($text) && trim($text) !== '') return trim($text);
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
    echo json_encode([
        'ok' => true,
        'answer' => "ðŸš€ **Navigation Command Received:**\n\nOpening **" . htmlspecialchars($navTarget['name']) . "** page for you.\n\nðŸ‘‰ [Click here if page does not auto-redirect](" . htmlspecialchars($navTarget['url']) . ")",
        'provider' => 'ERP AI Navigation Engine',
        'tool_used' => 'ERP Navigation Tool',
        'nav_url' => $navTarget['url']
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$p = mb_strtolower($prompt, 'UTF-8');

// â”€â”€â”€ Greeting / Casual Chat Handler â”€â”€â”€
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
    'à¦¨à¦®à¦¸à§à¦•à¦¾à¦°',
    'à¦¹à§à¦¯à¦¾à¦²à§‹',
    'à¦¹à¦¾à¦‡',
    'à¦¶à§à¦­ à¦¸à¦•à¦¾à¦²',
    'à¦¶à§à¦­ à¦¬à¦¿à¦•à§‡à¦²',
    'à¦¶à§à¦­ à¦¸à¦¨à§à¦§à§à¦¯à¦¾',
    'à¦•à§‡à¦®à¦¨ à¦†à¦›à§‡à¦¨',
    'à¦•à§‡à¦®à¦¨ à¦†à¦›à§‹',
    'à¦­à¦¾à¦²à§‹',
    'à¤¨à¤®à¤¸à¥à¤¤à¥‡',
    'à¤¹à¥‡à¤²à¥‹',
    'à¤¹à¤¾à¤¯',
    'à¤¨à¤®à¤¸à¥à¤•à¤¾à¤°',
    'à¤¸à¥à¤ªà¥à¤°à¤­à¤¾à¤¤',
    'à¤•à¥ˆà¤¸à¥‡ à¤¹à¥‹',
    'à¤•à¥à¤¯à¤¾ à¤¹à¤¾à¤²',
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
    'à¦§à¦¨à§à¦¯à¦¬à¦¾à¦¦',
    'à¤§à¤¨à¥à¤¯à¤µà¤¾à¤¦',
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
    $isThanks = (strpos($p, 'thank') !== false || strpos($p, 'à¦§à¦¨à§à¦¯à¦¬à¦¾à¦¦') !== false || strpos($p, 'dhonnobad') !== false || strpos($p, 'à¤§à¤¨à¥à¤¯à¤µà¤¾à¤¦') !== false || strpos($p, 'shukriya') !== false);

    if ($isThanks) {
        if ($userLang === 'Bengali') {
            $greeting = "ðŸ˜Š à¦†à¦ªà¦¨à¦¾à¦•à§‡à¦“ à¦…à¦¨à§‡à¦• à¦§à¦¨à§à¦¯à¦¬à¦¾à¦¦, **{$userName}**! à¦¯à§‡à¦•à§‹à¦¨à§‹ à¦¸à¦®à¦¯à¦¼ à¦†à¦®à¦¾à¦•à§‡ à¦œà¦¿à¦œà§à¦žà§‡à¦¸ à¦•à¦°à¦¤à§‡ à¦ªà¦¾à¦°à§‡à¦¨à¥¤ à¦†à¦®à¦¿ à¦¸à¦¬à¦¸à¦®à¦¯à¦¼ à¦†à¦ªà¦¨à¦¾à¦° à¦¸à¦¾à¦¹à¦¾à¦¯à§à¦¯à§‡ à¦ªà§à¦°à¦¸à§à¦¤à§à¦¤! ðŸ™";
        } elseif ($userLang === 'Hindi') {
            $greeting = "ðŸ˜Š à¤†à¤ªà¤•à¤¾ à¤­à¥€ à¤¬à¤¹à¥à¤¤-à¤¬à¤¹à¥à¤¤ à¤§à¤¨à¥à¤¯à¤µà¤¾à¤¦, **{$userName}**! à¤•à¤­à¥€ à¤­à¥€ à¤•à¥à¤› à¤ªà¥‚à¤›à¤¨à¤¾ à¤¹à¥‹ à¤¤à¥‹ à¤¬à¥‡à¤à¤¿à¤à¤• à¤ªà¥‚à¤›à¤¿à¤à¥¤ à¤®à¥ˆà¤‚ à¤¹à¤®à¥‡à¤¶à¤¾ à¤†à¤ªà¤•à¥€ à¤¸à¥‡à¤µà¤¾ à¤®à¥‡à¤‚ à¤¹à¥‚à¤! ðŸ™";
        } else {
            $greeting = "ðŸ˜Š You're welcome, **{$userName}**! Feel free to ask me anything anytime. I'm always here to help! ðŸ™";
        }
    } else {
        $hour = (int) date('G');
        $timeGreet = $hour < 12 ? ['Good Morning', 'à¦¶à§à¦­ à¦¸à¦•à¦¾à¦²', 'à¦¸à§à¦ªà§à¦°à¦­à¦¾à¦¤'] : ($hour < 17 ? ['Good Afternoon', 'à¦¶à§à¦­ à¦¬à¦¿à¦•à§‡à¦²', 'à¤¶à¥à¤­ à¤¦à¥‹à¤ªà¤¹à¤°'] : ['Good Evening', 'à¦¶à§à¦­ à¦¸à¦¨à§à¦§à§à¦¯à¦¾', 'à¤¶à¥à¤­ à¤¸à¤‚à¤§à¥à¤¯à¤¾']);

        if ($userLang === 'Bengali') {
            $greeting = "ðŸ‘‹ **{$timeGreet[1]}, {$userName}!**\n\n"
                . "à¦†à¦®à¦¿ à¦†à¦ªà¦¨à¦¾à¦° **à¦‡à¦†à¦°à¦ªà¦¿ AI à¦à¦¸à¦¿à¦¸à§à¦Ÿà§à¦¯à¦¾à¦¨à§à¦Ÿ**à¥¤ à¦†à¦®à¦¿ à¦†à¦ªà¦¨à¦¾à¦•à§‡ à¦¯à§‡à¦¸à¦¬ à¦¬à¦¿à¦·à¦¯à¦¼à§‡ à¦¸à¦¾à¦¹à¦¾à¦¯à§à¦¯ à¦•à¦°à¦¤à§‡ à¦ªà¦¾à¦°à¦¿:\n\n"
                . "ðŸ“¦ **à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦•** â€” à¦¯à§‡à¦•à§‹à¦¨à§‹ à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿à¦° à¦°à§‹à¦², à¦°à¦¾à¦¨à¦¿à¦‚ à¦®à¦¿à¦Ÿà¦¾à¦°, SQM à¦œà¦¾à¦¨à§à¦¨\n"
                . "ðŸ§® **à¦²à§‡à¦¬à§‡à¦² à¦•à§à¦¯à¦¾à¦²à¦•à§à¦²à§‡à¦Ÿà¦°** â€” à¦°à¦¾à¦¨à¦¿à¦‚ à¦®à¦¿à¦Ÿà¦¾à¦°, à¦‡à¦®à¦ªà§à¦°à§‡à¦¶à¦¨ à¦“ à¦•à¦¸à§à¦Ÿà¦¿à¦‚ à¦¹à¦¿à¦¸à¦¾à¦¬ à¦•à¦°à§à¦¨\n"
                . "ðŸ“‹ **à¦œà¦¬ à¦ªà§à¦²à§à¦¯à¦¾à¦¨à¦¿à¦‚** â€” à¦ªà§à¦²à§à¦¯à¦¾à¦¨à¦¿à¦‚ à¦¬à§‹à¦°à§à¦¡ à¦“ à¦¡à¦¿à¦ªà¦¾à¦°à§à¦Ÿà¦®à§‡à¦¨à§à¦Ÿ à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸ à¦¦à§‡à¦–à§à¦¨\n"
                . "ðŸ“ **à¦‡à¦‰à¦¨à¦¿à¦Ÿ à¦•à¦¨à¦­à¦¾à¦°à§à¦¸à¦¨** â€” SQM â†” SQ Inch à¦°à§‡à¦Ÿ à¦°à§‚à¦ªà¦¾à¦¨à§à¦¤à¦° à¦•à¦°à§à¦¨\n\n"
                . "ðŸ’¡ à¦¨à¦¿à¦šà§‡à¦° **Quick Action à¦šà¦¿à¦ªà¦¸** à¦¥à§‡à¦•à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨ à¦…à¦¥à¦¬à¦¾ à¦†à¦ªà¦¨à¦¾à¦° à¦ªà§à¦°à¦¶à§à¦¨ à¦Ÿà¦¾à¦‡à¦ª/à¦­à¦¯à¦¼à§‡à¦¸à§‡ à¦¬à¦²à§à¦¨!";
        } elseif ($userLang === 'Hindi') {
            $greeting = "ðŸ‘‹ **{$timeGreet[2]}, {$userName}!**\n\n"
                . "à¤®à¥ˆà¤‚ à¤†à¤ªà¤•à¤¾ **ERP AI à¤…à¤¸à¤¿à¤¸à¥à¤Ÿà¥‡à¤‚à¤Ÿ** à¤¹à¥‚à¤à¥¤ à¤®à¥ˆà¤‚ à¤‡à¤¨ à¤µà¤¿à¤·à¤¯à¥‹à¤‚ à¤®à¥‡à¤‚ à¤†à¤ªà¤•à¥€ à¤®à¤¦à¤¦ à¤•à¤° à¤¸à¤•à¤¤à¤¾ à¤¹à¥‚à¤:\n\n"
                . "ðŸ“¦ **à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤•** â€” à¤•à¤¿à¤¸à¥€ à¤­à¥€ à¤•à¤‚à¤ªà¤¨à¥€ à¤•à¥‡ à¤°à¥‹à¤², à¤°à¤¨à¤¿à¤‚à¤— à¤®à¥€à¤Ÿà¤°, SQM à¤œà¤¾à¤¨à¥‡à¤‚\n"
                . "ðŸ§® **à¤²à¥‡à¤¬à¤² à¤•à¥ˆà¤²à¤•à¥à¤²à¥‡à¤Ÿà¤°** â€” à¤°à¤¨à¤¿à¤‚à¤— à¤®à¥€à¤Ÿà¤°, à¤‡à¤®à¥à¤ªà¥à¤°à¥‡à¤¶à¤¨ à¤“ à¤²à¤¾à¤—à¤¤ à¤—à¤£à¤¨à¤¾\n"
                . "ðŸ“‹ **à¤œà¥‰à¤¬ à¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤—** â€” à¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤— à¤¬à¥‹à¤°à¥à¤¡ à¤“ à¤¡à¤¿à¤ªà¤¾à¤°à¥à¤Ÿà¤®à¥‡à¤‚à¤Ÿ à¤¸à¥à¤Ÿà¥‡à¤Ÿà¤¸\n"
                . "ðŸ“ **à¤¯à¥‚à¤¨à¤¿à¤Ÿ à¤•à¤¨à¤µà¤°à¥à¤œà¤¼à¤¨** â€” SQM â†” SQ Inch à¤°à¥‡à¤Ÿ à¤¬à¤¦à¤²à¥‡à¤‚\n\n"
                . "ðŸ’¡ à¤¨à¥€à¤šà¥‡ **Quick Action à¤šà¤¿à¤ªà¥à¤¸** à¤ªà¤° à¤•à¥à¤²à¤¿à¤• à¤•à¤°à¥‡à¤‚ à¤¯à¤¾ à¤…à¤ªà¤¨à¤¾ à¤¸à¤µà¤¾à¤² à¤Ÿà¤¾à¤‡à¤ª à¤•à¤°à¥‡à¤‚!";
        } else {
            $greeting = "ðŸ‘‹ **{$timeGreet[0]}, {$userName}!**\n\n"
                . "I'm your **ERP AI Assistant**. Here's what I can help you with:\n\n"
                . "ðŸ“¦ **Paper Stock** â€” Check rolls, running meters, SQM for any company\n"
                . "ðŸ§® **Label Calculator** â€” Running meters, impressions & costing math\n"
                . "ðŸ“‹ **Job Planning** â€” Planning board & department status tracking\n"
                . "ðŸ“ **Unit Conversion** â€” SQM â†” SQ Inch rate conversions\n\n"
                . "ðŸ’¡ Click a **Quick Action chip** below or type your question!";
        }
    }

    echo json_encode([
        'ok' => true,
        'answer' => $greeting,
        'provider' => 'ERP AI Assistant',
        'tool_used' => 'Greeting & Help',
        'user_lang' => $userLang
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// â”€â”€â”€ Priority Command Router (/plate, /paperstock) â”€â”€â”€
$pTrimmed = trim(mb_strtolower($prompt, 'UTF-8'));
$userLang = detect_language($prompt); // Define userLang for global scope
$baseNavUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
$commandType = null; // Tracks special command: 'plate', 'paperstock', 'quoted'

// ─── Slash Commands Helper (/) — show all available commands ───
$pTrimmedSingle = trim(mb_strtolower($prompt, 'UTF-8'));
if ($pTrimmedSingle === '/' || $pTrimmedSingle === '/help' || $pTrimmedSingle === 'help' || $pTrimmedSingle === 'commands') {
    if ($userLang === 'Bengali') {
        $answer = "🔣 **উপলব্ধ স্ল্যাশ কমান্ড:**\n\n"
            . "• **`/erp <প্রশ্ন>`** — শুধুমাত্র ERP ডাটাবেস ও নলেজ বেস থেকে উত্তর দেয় (কোনো LLM নয়)\n"
            . "• **`/plate <প্রশ্ন>`** — প্লেট ডাটা প্রাধান্য দিয়ে খোঁজে\n"
            . "• **`/paper <প্রশ্ন>`** — পেপার স্টক ডাটা প্রাধান্য দিয়ে খোঁজে\n"
            . "• **`/clear`** — প্রায়োরিটি মোড রিসেট করে\n\n"
            . "**উদাহরণ:**\n"
            . "• `/erp মোট কতগুলো প্লেট আছে?`\n"
            . "• `/plate Chromo পেপারের প্লেট`\n"
            . "• `/paper Krishna কোম্পানির রোল`\n\n"
            . "👉 আপনি উপরের যেকোনো কমান্ড কপি করে ব্যবহার করতে পারেন!";
        $suggestions = ['/erp মোট কতগুলো প্লেট আছে?', '/plate Chromo প্লেট', '/paper Krishna রোল', '/clear'];
    } elseif ($userLang === 'Hindi') {
        $answer = "🔣 **उपलब्ध स्लैश कमांड:**\n\n"
            . "• **`/erp <प्रश्न>`** — केवल ERP डेटाबेस और नॉलेज बेस से उत्तर देता है (कोई LLM नहीं)\n"
            . "• **`/plate <प्रश्न>`** — प्लेट डेटा प्राथमिकता से खोजता है\n"
            . "• **`/paper <प्रश्न>`** — पेपर स्टॉक डेटा प्राथमिकता से खोजता है\n"
            . "• **`/clear`** — प्राथमिकता मोड रीसेट करता है\n\n"
            . "**उदाहरण:**\n"
            . "• `/erp कुल कितनी प्लेट हैं?`\n"
            . "• `/plate Chromo पेपर की प्लेट`\n"
            . "• `/paper Krishna कंपनी के रोल`\n\n"
            . "👉 आप ऊपर के किसी भी कमांड को कॉपी करके उपयोग कर सकते हैं!";
        $suggestions = ['/erp कुल कितनी प्लेट हैं?', '/plate Chromo प्लेट', '/paper Krishna रोल', '/clear'];
    } else {
        $answer = "🔣 **Available Slash Commands:**\n\n"
            . "• **`/erp <query>`** — Answer only from ERP database & Knowledge Base (no external LLM)\n"
            . "• **`/plate <query>`** — Search with Plate data priority\n"
            . "• **`/paper <query>`** — Search with Paper Stock data priority\n"
            . "• **`/clear`** — Reset priority mode\n\n"
            . "**Examples:**\n"
            . "• `/erp Total number of plates`\n"
            . "• `/plate Chromo paper plates`\n"
            . "• `/paper Krishna company rolls`\n\n"
            . "👉 Feel free to copy and use any command above!";
        $suggestions = ['/erp Total number of plates', '/plate Chromo plates', '/paper Krishna rolls', '/clear'];
    }
    echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'Slash Commands Help', 'user_lang' => $userLang, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// /plate command
if (strpos($pTrimmed, '/plate') === 0 || $pTrimmed === 'plate' || $pTrimmed === 'à¦ªà§à¦²à§‡à¦Ÿ' || strpos($pTrimmed, 'à¤ªà¥à¤²à¥‡à¤Ÿ') !== false) {
    $_SESSION['ai_priority_mode'] = 'plate';
    $commandType = 'plate';
    $subQuery = preg_replace('/^\/plate\s*/iu', '', trim($prompt));
    $subQuery = preg_replace('/^à¦ªà§à¦²à§‡à¦Ÿ\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^à¤ªà¥à¤²à¥‡à¤Ÿ\s*/u', '', $subQuery);
    $subQuery = trim(trim($subQuery), '"');

    if ($subQuery === '') {
        $navUrl = $baseNavUrl . '/modules/plate-tools/plate-management/index.php';
        if ($userLang === 'Bengali') {
            $answer = "ðŸŽ¯ **à¦ªà§à¦²à§‡à¦Ÿ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œà§‡ à¦…à¦—à§à¦°à¦¾à¦§à¦¿à¦•à¦¾à¦° à¦¦à§‡à¦“à¦¯à¦¼à¦¾ à¦¹à¦šà§à¦›à§‡!**\n\nðŸ‘‰ [à¦ªà§à¦²à§‡à¦Ÿ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦–à§à¦²à§à¦¨]($navUrl)\n\n**ðŸ“Œ à¦à¦–à¦¨ à¦¥à§‡à¦•à§‡ à¦¸à¦¬ à¦ªà§à¦°à¦¶à§à¦¨à§‡ à¦ªà§à¦²à§‡à¦Ÿ à¦¡à¦¾à¦Ÿà¦¾ à¦†à¦—à§‡ à¦–à§à¦à¦œà¦¬à§‡à¥¤**\n\n**à¦†à¦ªà¦¨à¦¿ à¦¯à¦¾ à¦œà¦¿à¦œà§à¦žà¦¾à¦¸à¦¾ à¦•à¦°à¦¤à§‡ à¦ªà¦¾à¦°à§‡à¦¨:**\nâ€¢ à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§à¦²à§‡à¦Ÿ à¦†à¦›à§‡?\nâ€¢ Chromo à¦ªà§‡à¦ªà¦¾à¦°à§‡à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹?\nâ€¢ Flat Bed à¦¬à¦¾ Rotary à¦ªà§à¦²à§‡à¦Ÿ à¦•à¦¤?\nâ€¢ Alpha Flex / SFL / Pidilite â€” à¦•à§‹à¦¨ à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿à¦° à¦ªà§à¦²à§‡à¦Ÿ?\nâ€¢ 9 inch à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“\nâ€¢ à¦¸à¦¬à¦šà§‡à¦¯à¦¼à§‡ à¦¨à¦¤à§à¦¨ à¦ªà§à¦²à§‡à¦Ÿà¦Ÿà¦¿ à¦•à§€?\nâ€¢ CMYK à¦•à¦¾à¦²à¦¾à¦° à¦¸à§à¦ªà§‡à¦¸à¦¿à¦«à¦¿à¦•à§‡à¦¶à¦¨ à¦¦à§‡à¦–à¦¾à¦“";
            $suggestions = ['à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§à¦²à§‡à¦Ÿ à¦†à¦›à§‡?', 'Chromo à¦ªà§‡à¦ªà¦¾à¦°à§‡à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦•à¦¤?', 'Flat Bed à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“', 'Rotary à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“', 'Alpha Flex à¦à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“', '9 inch à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦° à¦ªà§à¦²à§‡à¦Ÿ à¦¦à§‡à¦–à¦¾à¦“', 'à¦¸à¦¬à¦šà§‡à¦¯à¦¼à§‡ à¦¨à¦¤à§à¦¨ à¦ªà§à¦²à§‡à¦Ÿà¦Ÿà¦¿ à¦•à§€?'];
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸŽ¯ **à¤ªà¥à¤²à¥‡à¤Ÿ à¤®à¥ˆà¤¨à¥‡à¤œà¤®à¥‡à¤‚à¤Ÿ à¤ªà¥‡à¤œ à¤•à¥‹ à¤ªà¥à¤°à¤¾à¤¥à¤®à¤¿à¤•à¤¤à¤¾ à¤¦à¥€ à¤œà¤¾ à¤°à¤¹à¥€ à¤¹à¥ˆ!**\n\nðŸ‘‰ [à¤ªà¥à¤²à¥‡à¤Ÿ à¤®à¥ˆà¤¨à¥‡à¤œà¤®à¥‡à¤‚à¤Ÿ à¤–à¥‹à¤²à¥‡à¤‚]($navUrl)\n\n**ðŸ“Œ à¤…à¤¬ à¤¸à¥‡ à¤¸à¤­à¥€ à¤¸à¤µà¤¾à¤²à¥‹à¤‚ à¤®à¥‡à¤‚ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¡à¥‡à¤Ÿà¤¾ à¤ªà¤¹à¤²à¥‡ à¤–à¥‹à¤œà¤¾ à¤œà¤¾à¤à¤—à¤¾à¥¤**\n\n**à¤†à¤ª à¤¯à¥‡ à¤ªà¥‚à¤› à¤¸à¤•à¤¤à¥‡ à¤¹à¥ˆà¤‚:**\nâ€¢ à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¹à¥ˆà¤‚?\nâ€¢ Chromo à¤ªà¥‡à¤ªà¤° à¤•à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¤¿à¤¤à¤¨à¥€?\nâ€¢ Flat Bed à¤¬à¤¾ Rotary à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¤¿à¤¤à¤¨à¥€?\nâ€¢ Alpha Flex / SFL / Pidilite â€” à¤•à¤¿à¤¸ à¤•à¤‚à¤ªà¤¨à¥€ à¤•à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ?\nâ€¢ 9 inch à¤¸à¤¿à¤²à¥‡à¤‚à¤¡à¤° à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¤¿à¤–à¤¾à¤“\nâ€¢ à¤¸à¤¬à¤¸à¥‡ à¤¨à¤ˆ à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¥Œà¤¨ à¤¸à¥€ à¤¹à¥ˆ?\nâ€¢ CMYK à¤•à¤²à¤° à¤¸à¥à¤ªà¥‡à¤¸à¤¿à¤«à¤¿à¤•à¥‡à¤¶à¤¨ à¤¦à¤¿à¤–à¤¾à¤“";
            $suggestions = ['à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¹à¥ˆà¤‚?', 'Chromo à¤ªà¥‡à¤ªà¤° à¤•à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¤¿à¤¤à¤¨à¥€?', 'Flat Bed à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¥‡à¤–à¤¾à¤“', 'Rotary à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¥‡à¤–à¤¾à¤“', 'Alpha Flex à¤•à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¥‡à¤–à¤¾à¤“', '9 inch à¤¸à¤¿à¤²à¥‡à¤‚à¤¡à¤° à¤ªà¥à¤²à¥‡à¤Ÿ à¤¦à¥‡à¤–à¤¾à¤“', 'à¤¸à¤¬à¤¸à¥‡ à¤¨à¤ˆ à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¥Œà¤¨ à¤¸à¥€ à¤¹à¥ˆ?'];
        } else {
            $answer = "ðŸŽ¯ **Prioritizing Plate Management Page!**\n\nðŸ‘‰ [Open Plate Management]($navUrl)\n\n**ðŸ“Œ All your queries will now search Plate data first.**\n\n**You can ask about:**\nâ€¢ Total number of plates\nâ€¢ Plates by paper type (Chromo, Thermal, etc.)\nâ€¢ Flat Bed vs Rotary plate count\nâ€¢ Plates by company (Alpha Flex, SFL, Pidilite)\nâ€¢ Cylinder size filter (9 inch, 12 inch, etc.)\nâ€¢ Latest / newest plate added\nâ€¢ CMYK color specifications for any plate";
            $suggestions = ['Total number of plates', 'Chromo paper plates count', 'Show Flat Bed plates', 'Show Rotary plates', 'Alpha Flex company plates', '9 inch cylinder plates', 'Latest plate added'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'Plate Priority Command', 'user_lang' => $userLang, 'nav_url' => $navUrl, 'suggestions' => $suggestions, 'command_type' => 'plate'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // /plate <query> â†’ rewrite prompt, fall through to normal processing (priority mode searches plate first)
    $prompt = $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}

// /paperstock or /paper command
if (strpos($pTrimmed, '/paperstock') === 0 || strpos($pTrimmed, '/paper stock') === 0 || $pTrimmed === 'paperstock' || $pTrimmed === 'paper stock' || $pTrimmed === 'paper' || strpos($pTrimmed, 'à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦•') !== false || strpos($pTrimmed, 'à¦ªà§‡à¦ªà¦¾à¦°') !== false || strpos($pTrimmed, 'à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤•') !== false || strpos($pTrimmed, 'à¤ªà¥‡à¤ªà¤°') !== false) {
    $_SESSION['ai_priority_mode'] = 'paperstock';
    $commandType = 'paperstock';
    $subQuery = preg_replace('/^\/paper\s*stock\s*/iu', '', trim($prompt));
    $subQuery = preg_replace('/^\/paper\s*/i', '', $subQuery);
    $subQuery = preg_replace('/^paper\s*stock\s*/i', '', $subQuery);
    $subQuery = preg_replace('/^paper\s*/i', '', $subQuery);
    $subQuery = preg_replace('/^à¦ªà§‡à¦ªà¦¾à¦°\s*à¦¸à§à¦Ÿà¦•\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^à¦ªà§‡à¦ªà¦¾à¦°\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^à¤ªà¥‡à¤ªà¤°\s*à¤¸à¥à¤Ÿà¥‰à¤•\s*/u', '', $subQuery);
    $subQuery = preg_replace('/^à¤ªà¥‡à¤ªà¤°\s*/u', '', $subQuery);
    $subQuery = trim(trim($subQuery), '"');

    if ($subQuery === '') {
        $navUrl = $baseNavUrl . '/modules/paper_stock/index.php';
        if ($userLang === 'Bengali') {
            $answer = "ðŸŽ¯ **à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦ªà§‡à¦œà§‡ à¦…à¦—à§à¦°à¦¾à¦§à¦¿à¦•à¦¾à¦° à¦¦à§‡à¦“à¦¯à¦¼à¦¾ à¦¹à¦šà§à¦›à§‡!**\n\nðŸ‘‰ [à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦–à§à¦²à§à¦¨]($navUrl)\n\n**ðŸ“Œ à¦à¦–à¦¨ à¦¥à§‡à¦•à§‡ à¦¸à¦¬ à¦ªà§à¦°à¦¶à§à¦¨à§‡ à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦¡à¦¾à¦Ÿà¦¾ à¦†à¦—à§‡ à¦–à§à¦à¦œà¦¬à§‡à¥¤**\n\n**à¦†à¦ªà¦¨à¦¿ à¦¯à¦¾ à¦œà¦¿à¦œà§à¦žà¦¾à¦¸à¦¾ à¦•à¦°à¦¤à§‡ à¦ªà¦¾à¦°à§‡à¦¨:**\nâ€¢ à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦² à¦†à¦›à§‡?\nâ€¢ Chromo / PP White / Thermal / Maplitho â€” à¦•à§‹à¦¨ à¦Ÿà¦¾à¦‡à¦ªà§‡à¦° à¦•à¦¤?\nâ€¢ Krishna / Austin / Navkar / NRGI â€” à¦•à§‹à¦¨ à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿à¦° à¦•à¦¤?\nâ€¢ à¦®à§‹à¦Ÿ à¦°à¦¾à¦¨à¦¿à¦‚ à¦®à¦¿à¦Ÿà¦¾à¦° à¦•à¦¤?\nâ€¢ à¦®à§‹à¦Ÿ SQM à¦•à¦¤?\nâ€¢ à¦•à§‹à¦¨ à¦°à§‹à¦² Slitting-à¦ à¦†à¦›à§‡?\nâ€¢ Job Assign à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸à§‡ à¦•à¦¤à¦Ÿà¦¾ à¦†à¦›à§‡?\nâ€¢ 1500mm à¦šà¦“à¦¡à¦¼à¦¾à¦° à¦°à§‹à¦² à¦¦à§‡à¦–à¦¾à¦“";
            $suggestions = ['à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦²?', 'Chromo à¦ªà§‡à¦ªà¦¾à¦° à¦•à¦¤?', 'Krishna à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿à¦° à¦°à§‹à¦² à¦¦à§‡à¦–à¦¾à¦“', 'à¦®à§‹à¦Ÿ à¦°à¦¾à¦¨à¦¿à¦‚ à¦®à¦¿à¦Ÿà¦¾à¦° à¦•à¦¤?', 'PP White à¦¸à§à¦Ÿà¦• à¦•à¦¤?', 'Slitting à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸à§‡ à¦•à¦¤à¦Ÿà¦¾?', '1500mm à¦šà¦“à¦¡à¦¼à¦¾à¦° à¦°à§‹à¦² à¦¦à§‡à¦–à¦¾à¦“'];
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸŽ¯ **à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤ªà¥‡à¤œ à¤•à¥‹ à¤ªà¥à¤°à¤¾à¤¥à¤®à¤¿à¤•à¤¤à¤¾ à¤¦à¥€ à¤œà¤¾ à¤°à¤¹à¥€ à¤¹à¥ˆ!**\n\nðŸ‘‰ [à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤–à¥‹à¤²à¥‡à¤‚]($navUrl)\n\n**ðŸ“Œ à¤…à¤¬ à¤¸à¥‡ à¤¸à¤­à¥€ à¤¸à¤µà¤¾à¤²à¥‹à¤‚ à¤®à¥‡à¤‚ à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤¡à¥‡à¤Ÿà¤¾ à¤ªà¤¹à¤²à¥‡ à¤–à¥‹à¤œà¤¾ à¤œà¤¾à¤à¤—à¤¾à¥¤**\n\n**à¤†à¤ª à¤¯à¥‡ à¤ªà¥‚à¤› à¤¸à¤•à¤¤à¥‡ à¤¹à¥ˆà¤‚:**\nâ€¢ à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥‡ à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤² à¤¹à¥ˆà¤‚?\nâ€¢ Chromo / PP White / Thermal / Maplitho â€” à¤•à¤¿à¤¸ à¤Ÿà¤¾à¤‡à¤ª à¤•à¥‡ à¤•à¤¿à¤¤à¤¨à¥‡?\nâ€¢ Krishna / Austin / Navkar / NRGI â€” à¤•à¤¿à¤¸ à¤•à¤‚à¤ªà¤¨à¥€ à¤•à¥‡ à¤•à¤¿à¤¤à¤¨à¥‡?\nâ€¢ à¤•à¥à¤² à¤°à¤¨à¤¿à¤‚à¤— à¤®à¥€à¤Ÿà¤° à¤•à¤¿à¤¤à¤¨à¤¾?\nâ€¢ à¤•à¥à¤² SQM à¤•à¤¿à¤¤à¤¨à¤¾?\nâ€¢ à¤•à¥Œà¤¨ à¤¸à¥‡ à¤°à¥‹à¤² Slitting à¤®à¥‡à¤‚ à¤¹à¥ˆà¤‚?\nâ€¢ Job Assign à¤¸à¥à¤Ÿà¥‡à¤Ÿà¤¸ à¤®à¥‡à¤‚ à¤•à¤¿à¤¤à¤¨à¥‡ à¤¹à¥ˆà¤‚?\nâ€¢ 1500mm à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ à¤µà¤¾à¤²à¥‡ à¤°à¥‹à¤² à¤¦à¥‡à¤–à¤¾à¤“";
            $suggestions = ['à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥‡ à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤²?', 'Chromo à¤ªà¥‡à¤ªà¤° à¤•à¤¿à¤¤à¤¨à¥‡?', 'Krishna à¤•à¤‚à¤ªà¤¨à¥€ à¤•à¥‡ à¤°à¥‹à¤² à¤¦à¥‡à¤–à¤¾à¤“', 'à¤•à¥à¤² à¤°à¤¨à¤¿à¤‚à¤— à¤®à¥€à¤Ÿà¤° à¤•à¤¿à¤¤à¤¨à¤¾?', 'PP White à¤¸à¥à¤Ÿà¥‰à¤• à¤•à¤¿à¤¤à¤¨à¤¾?', 'Slitting à¤¸à¥à¤Ÿà¥‡à¤Ÿà¤¸ à¤®à¥‡à¤‚ à¤•à¤¿à¤¤à¤¨à¥‡?', '1500mm à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ à¤µà¤¾à¤²à¥‡ à¤°à¥‹à¤² à¤¦à¥‡à¤–à¤¾à¤“'];
        } else {
            $answer = "ðŸŽ¯ **Prioritizing Paper Stock Page!**\n\nðŸ‘‰ [Open Paper Stock]($navUrl)\n\n**ðŸ“Œ All your queries will now search Paper Stock data first.**\n\n**You can ask about:**\nâ€¢ Total roll count in stock\nâ€¢ Rolls by paper type (Chromo, PP White, Thermal, Maplitho)\nâ€¢ Rolls by company (Krishna, Austin, Navkar, NRGI)\nâ€¢ Total running meters / total SQM\nâ€¢ Rolls currently in Slitting / Job Assign status\nâ€¢ Rolls by width (e.g. 1500mm wide rolls)\nâ€¢ Rolls received this month / this week\nâ€¢ Purchase rate / cost analysis";
            $suggestions = ['Total roll count', 'Chromo paper rolls', 'Krishna company rolls', 'Total running meters', 'PP White stock count', 'Slitting status rolls', '1500mm width rolls'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'Paper Stock Priority Command', 'user_lang' => $userLang, 'nav_url' => $navUrl, 'suggestions' => $suggestions, 'command_type' => 'paperstock'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // /paperstock <query> â†’ rewrite prompt, fall through to normal processing (priority mode searches paper_stock first)
    $prompt = $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}
// /erp command â€” ERP-only mode: force KB + ERP data only, skip external LLM
$erpOnlyMode = false;
if (preg_match('/^\/erp\s*/iu', $pTrimmed)) {
    $subQuery = preg_replace('/^\/erp\s*/iu', '', $prompt);
    $subQuery = trim($subQuery);
    if ($subQuery === '') {
        if ($userLang === 'Bengali') {
            $answer = "ðŸ”’ **ERP-à¦…à¦¨à¦²à¦¿ à¦®à§‹à¦¡**\n\n"
                . "à¦†à¦ªà¦¨à¦¿ à¦à¦–à¦¨ /erp **ERP-à¦…à¦¨à¦²à¦¿ à¦®à§‹à¦¡à§‡** à¦†à¦›à§‡à¦¨à¥¤ à¦†à¦®à¦¿ **à¦¶à§à¦§à§ ERP à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸ à¦à¦¬à¦‚ à¦¨à¦²à§‡à¦œ à¦¬à§‡à¦¸** à¦¥à§‡à¦•à§‡ à¦‰à¦¤à§à¦¤à¦° à¦¦à§‡à¦¬à¥¤\n\n"
                . "**à¦†à¦ªà¦¨à¦¿ à¦¯à¦¾ à¦œà¦¿à¦œà§à¦žà¦¾à¦¸à¦¾ à¦•à¦°à¦¤à§‡ à¦ªà¦¾à¦°à§‡à¦¨:**\n"
                . "â€¢ ðŸ“¦ à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• â€” à¦°à§‹à¦², à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿, à¦Ÿà¦¾à¦‡à¦ª\n"
                . "â€¢ ðŸ­ à¦ªà§à¦°à§‹à¦¡à¦¾à¦•à¦¶à¦¨ â€” à¦œà¦¬, à¦ªà§à¦²à§à¦¯à¦¾à¦¨à¦¿à¦‚, à¦®à§‡à¦¶à¦¿à¦¨\n"
                . "â€¢ ðŸ“‹ à¦ªà§à¦²à§‡à¦Ÿ â€” à¦ªà§à¦²à§‡à¦Ÿ à¦¡à¦¾à¦Ÿà¦¾, à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿, à¦Ÿà¦¾à¦‡à¦ª\n"
                . "â€¢ ðŸ“„ ERP à¦¨à¦²à§‡à¦œ à¦¬à§‡à¦¸ â€” à¦Ÿà§à¦°à§‡à¦‡à¦¨à¦¡ à¦ªà§à¦°à¦¶à§à¦¨\n\n"
                . "ðŸ‘‰ à¦¯à§‡à¦®à¦¨: **\"/erp à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§à¦²à§‡à¦Ÿ à¦†à¦›à§‡?\"**\n"
                . "ðŸ‘‰ à¦¬à¦¾: **\"/erp Chromo à¦ªà§‡à¦ªà¦¾à¦°à§‡à¦° à¦•à¦¤ à¦°à§‹à¦²?\"**";
            $suggestions = ['/erp à¦®à§‹à¦Ÿ à¦•à¦¤à¦—à§à¦²à§‹ à¦ªà§à¦²à§‡à¦Ÿ à¦†à¦›à§‡?', '/erp Chromo à¦ªà§‡à¦ªà¦¾à¦°à§‡à¦° à¦°à§‹à¦²', '/erp Krishna à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿à¦° à¦°à§‹à¦²'];
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ”’ **ERP-à¤“à¤¨à¤²à¥€ à¤®à¥‹à¤¡**\n\n"
                . "à¤†à¤ª /erp **ERP-à¤“à¤¨à¤²à¥€ à¤®à¥‹à¤¡** à¤®à¥‡à¤‚ à¤¹à¥ˆà¤‚à¥¤ à¤®à¥ˆà¤‚ **à¤•à¥‡à¤µà¤² ERP à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤”à¤° à¤¨à¥‰à¤²à¥‡à¤œ à¤¬à¥‡à¤¸** à¤¸à¥‡ à¤‰à¤¤à¥à¤¤à¤° à¤¦à¥‚à¤‚à¤—à¤¾à¥¤\n\n"
                . "**à¤†à¤ª à¤¯à¥‡ à¤ªà¥‚à¤› à¤¸à¤•à¤¤à¥‡ à¤¹à¥ˆà¤‚:**\n"
                . "â€¢ ðŸ“¦ à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• â€” à¤°à¥‹à¤², à¤•à¤‚à¤ªà¤¨à¥€, à¤Ÿà¤¾à¤‡à¤ª\n"
                . "â€¢ ðŸ­ à¤ªà¥à¤°à¥‹à¤¡à¤•à¥à¤¶à¤¨ â€” à¤œà¥‰à¤¬, à¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤—, à¤®à¤¶à¥€à¤¨\n"
                . "â€¢ ðŸ“‹ à¤ªà¥à¤²à¥‡à¤Ÿ â€” à¤ªà¥à¤²à¥‡à¤Ÿ à¤¡à¥‡à¤Ÿà¤¾, à¤•à¤‚à¤ªà¤¨à¥€, à¤Ÿà¤¾à¤‡à¤ª\n"
                . "â€¢ ðŸ“„ ERP à¤¨à¥‰à¤²à¥‡à¤œ à¤¬à¥‡à¤¸ â€” à¤Ÿà¥à¤°à¥‡à¤‚à¤¡ à¤ªà¥à¤°à¤¶à¥à¤¨\n\n"
                . "ðŸ‘‰ à¤œà¥ˆà¤¸à¥‡: **\"/erp à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¹à¥ˆà¤‚?\"**\n"
                . "ðŸ‘‰ à¤¯à¤¾: **\"/erp Chromo à¤ªà¥‡à¤ªà¤° à¤•à¥‡ à¤•à¤¿à¤¤à¤¨à¥‡ à¤°à¥‹à¤²?\"**";
            $suggestions = ['/erp à¤•à¥à¤² à¤•à¤¿à¤¤à¤¨à¥€ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¹à¥ˆà¤‚?', '/erp Chromo à¤ªà¥‡à¤ªà¤° à¤•à¥‡ à¤°à¥‹à¤²', '/erp Krishna à¤•à¤‚à¤ªà¤¨à¥€ à¤•à¥‡ à¤°à¥‹à¤²'];
        } else {
            $answer = "ðŸ”’ **ERP-Only Mode**\n\n"
                . "You are in /erp **ERP-Only Mode**. I will answer **only from ERP database and Knowledge Base**.\n\n"
                . "**You can ask about:**\n"
                . "â€¢ ðŸ“¦ Paper Stock â€” rolls, companies, types\n"
                . "â€¢ ðŸ­ Production â€” jobs, planning, machines\n"
                . "â€¢ ðŸ“‹ Plates â€” plate data, companies, types\n"
                . "â€¢ ðŸ“„ ERP Knowledge Base â€” trained Q&A\n\n"
                . "ðŸ‘‰ Example: **\"/erp Total number of plates\"**\n"
                . "ðŸ‘‰ Or: **\"/erp Chromo paper roll count\"**";
            $suggestions = ['/erp Total number of plates', '/erp Chromo paper rolls', '/erp Krishna company rolls'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI Priority Command', 'tool_used' => 'ERP-Only Mode', 'user_lang' => $userLang, 'suggestions' => $suggestions, 'command_type' => 'erp'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    // /erp <query> â€” strip prefix, set ERP-only mode, fall through
    $erpOnlyMode = true;
    $prompt = $subQuery;
    $p = mb_strtolower($prompt, 'UTF-8');
}

// Clear priority mode
if (strpos($pTrimmed, '/clear') === 0 || $pTrimmed === 'clear priority' || $pTrimmed === 'reset' || $pTrimmed === 'normal' || strpos($pTrimmed, 'à¦¨à¦°à¦®à¦¾à¦²') !== false || strpos($pTrimmed, 'à¤¸à¤¾à¤®à¤¾à¤¨à¥à¤¯') !== false) {
    unset($_SESSION['ai_priority_mode']);

    echo json_encode([
        'ok' => true,
        'answer' => $userLang === 'Bengali' ? "âœ… **à¦ªà§à¦°à¦¾à§Ÿà§‹à¦°à¦¿à¦Ÿà¦¿ à¦®à§‹à¦¡ à¦°à¦¿à¦¸à§‡à¦Ÿ à¦•à¦°à¦¾ à¦¹à§Ÿà§‡à¦›à§‡à¥¤** à¦à¦–à¦¨ à¦¸à¦¬ à¦¡à¦¾à¦Ÿà¦¾ à¦¸à¦®à¦¾à¦¨ à¦…à¦—à§à¦°à¦¾à¦§à¦¿à¦•à¦¾à¦° à¦ªà¦¾à¦¬à§‡à¥¤"
            : ($userLang === 'Hindi' ? "âœ… **à¤ªà¥à¤°à¤¾à¤¥à¤®à¤¿à¤•à¤¤à¤¾ à¤®à¥‹à¤¡ à¤°à¥€à¤¸à¥‡à¤Ÿ à¤•à¤° à¤¦à¤¿à¤¯à¤¾ à¤—à¤¯à¤¾ à¤¹à¥ˆà¥¤** à¤…à¤¬ à¤¸à¤­à¥€ à¤¡à¥‡à¤Ÿà¤¾ à¤¸à¤®à¤¾à¤¨ à¤ªà¥à¤°à¤¾à¤¥à¤®à¤¿à¤•à¤¤à¤¾ à¤ªà¤° à¤¹à¥ˆà¥¤"
                : "âœ… **Priority mode reset.** All data sources now have equal priority."),
        'provider' => 'ERP AI Priority Command',
        'tool_used' => 'Priority Reset'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// â”€â”€â”€ Bare Quoted Query Handler ("blue 500" etc.) â”€â”€â”€
// If user types just a quoted string like "blue 500" without /plate or /paperstock prefix,
// search broadly (jobs, plates, paper stock) and ask user what they want to see.
if (!isset($_SESSION['ai_priority_mode']) && preg_match('/^["\x{201C}\x{201D}]([^"\x{201C}\x{201D}]+)["\x{201C}\x{201D}]$/u', trim($prompt), $qm)) {
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

    if (empty($foundAreas)) {
        if ($userLang === 'Bengali') {
            $answer = "ðŸ” **\"$searchTerm\" â€” à¦•à§‹à¦¥à¦¾à¦“ à¦ªà¦¾à¦“à¦¯à¦¼à¦¾ à¦¯à¦¾à¦¯à¦¼à¦¨à¦¿à¥¤**\n\nà¦à¦‡ à¦Ÿà¦¾à¦°à§à¦®à¦Ÿà¦¿ ERP-à¦¤à§‡ à¦•à§‹à¦¨à§‹ à¦œà¦¬, à¦ªà§à¦²à§‡à¦Ÿ à¦¬à¦¾ à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦•à§‡ à¦¸à¦‚à¦¯à§à¦•à§à¦¤ à¦¨à¦¯à¦¼à¥¤\n\n**à¦†à¦ªà¦¨à¦¿ à¦•à¦¿ à¦•à¦¿à¦›à§ à¦…à¦¨à§à¦¯ à¦¨à¦¾à¦®à§‡ à¦–à§à¦à¦œà¦›à§‡à¦¨?**";
            $suggestions = ['à¦…à¦¨à§à¦¯ à¦•à§‹à¦¨à§‹ à¦¨à¦¾à¦®à§‡ à¦–à§à¦à¦œà§à¦¨', 'à¦ªà§à¦²à§‡à¦Ÿ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦¦à§‡à¦–à§à¦¨', 'à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦¦à§‡à¦–à§à¦¨'];
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ” **\"$searchTerm\" â€” à¤•à¤¹à¥€à¤‚ à¤¨à¤¹à¥€à¤‚ à¤®à¤¿à¤²à¤¾à¥¤**\n\nà¤¯à¤¹ à¤Ÿà¤°à¥à¤® ERP à¤®à¥‡à¤‚ à¤•à¤¿à¤¸à¥€ à¤œà¥‰à¤¬, à¤ªà¥à¤²à¥‡à¤Ÿ à¤¯à¤¾ à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤¸à¥‡ à¤œà¥à¤¡à¤¼à¤¾ à¤¨à¤¹à¥€à¤‚ à¤¹à¥ˆà¥¤\n\n**à¤•à¥à¤¯à¤¾ à¤†à¤ª à¤•à¤¿à¤¸à¥€ à¤…à¤¨à¥à¤¯ à¤¨à¤¾à¤® à¤¸à¥‡ à¤–à¥‹à¤œ à¤°à¤¹à¥‡ à¤¹à¥ˆà¤‚?**";
            $suggestions = ['à¤•à¤¿à¤¸à¥€ à¤…à¤¨à¥à¤¯ à¤¨à¤¾à¤® à¤¸à¥‡ à¤–à¥‹à¤œà¥‡à¤‚', 'à¤ªà¥à¤²à¥‡à¤Ÿ à¤®à¥ˆà¤¨à¥‡à¤œà¤®à¥‡à¤‚à¤Ÿ à¤¦à¥‡à¤–à¥‡à¤‚', 'à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤¦à¥‡à¤–à¥‡à¤‚'];
        } else {
            $answer = "ðŸ” **\"$searchTerm\" â€” not found anywhere in ERP.**\n\nThis term is not linked to any job, plate, or paper stock record.\n\n**Would you like to search with a different name?**";
            $suggestions = ['Search with different name', 'View Plate Management', 'View Paper Stock'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI', 'tool_used' => 'Bare Quoted Search', 'user_lang' => $userLang, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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
            $areaLabels['jobs'] = ['bn' => "ðŸ“‹ **à¦œà¦¬ à¦ªà§à¦²à§à¦¯à¦¾à¦¨à¦¿à¦‚ / à¦°à¦¾à¦¨à¦¿à¦‚ à¦œà¦¬** (" . count($results['jobs']) . " à¦Ÿà¦¿)", 'hi' => "ðŸ“‹ **à¤œà¥‰à¤¬ à¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤— / à¤°à¤¨à¤¿à¤‚à¤— à¤œà¥‰à¤¬** (" . count($results['jobs']) . " à¤Ÿà¤¿)", 'en' => "ðŸ“‹ **Job Planning / Running Jobs** (" . count($results['jobs']) . ")"];
        }
        if (isset($results['plates'])) {
            $areaLabels['plates'] = ['bn' => "ðŸ–¼ï¸ **à¦ªà§à¦²à§‡à¦Ÿ à¦¡à¦¾à¦Ÿà¦¾** (" . count($results['plates']) . " à¦Ÿà¦¿)", 'hi' => "ðŸ–¼ï¸ **à¤ªà¥à¤²à¥‡à¤Ÿ à¤¡à¥‡à¤Ÿà¤¾** (" . count($results['plates']) . " à¤Ÿà¤¿)", 'en' => "ðŸ–¼ï¸ **Plate Data** (" . count($results['plates']) . ")"];
        }
        if (isset($results['stock'])) {
            $areaLabels['stock'] = ['bn' => "ðŸ“¦ **à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦•** (" . count($results['stock']) . " à¦Ÿà¦¿)", 'hi' => "ðŸ“¦ **à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤•** (" . count($results['stock']) . " à¤Ÿà¤¿)", 'en' => "ðŸ“¦ **Paper Stock** (" . count($results['stock']) . ")"];
        }
        $langCode = ($userLang === 'Bengali') ? 'bn' : (($userLang === 'Hindi') ? 'hi' : 'en');
        $areaLines = array_map(fn($a) => $a[$langCode], $areaLabels);
        $areaStr = implode("\n", $areaLines);

        if ($userLang === 'Bengali') {
            $answer = "ðŸ” **\"$searchTerm\" â€” à¦à¦•à¦¾à¦§à¦¿à¦• à¦œà¦¾à¦¯à¦¼à¦—à¦¾à¦¯à¦¼ à¦ªà¦¾à¦“à¦¯à¦¼à¦¾ à¦—à§‡à¦›à§‡:**\n\n$areaStr\n\n**à¦†à¦ªà¦¨à¦¿ à¦•à§‹à¦¨à¦Ÿà¦¿ à¦¦à§‡à¦–à¦¤à§‡ à¦šà¦¾à¦¨?**\nâ€¢ à¦œà¦¬/à¦ªà§à¦°à§‹à¦¡à¦¾à¦•à¦¶à¦¨ à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ â€” à¦¬à¦²à§à¦¨ \"à¦œà¦¬\"\nâ€¢ à¦ªà§à¦²à§‡à¦Ÿ à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ â€” à¦¬à¦²à§à¦¨ \"à¦ªà§à¦²à§‡à¦Ÿ\"\nâ€¢ à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ â€” à¦¬à¦²à§à¦¨ \"à¦ªà§‡à¦ªà¦¾à¦°\"";
            $suggestions = ['à¦œà¦¬ à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ à¦¦à§‡à¦–à¦¾à¦“', 'à¦ªà§à¦²à§‡à¦Ÿ à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ à¦¦à§‡à¦–à¦¾à¦“', 'à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ à¦¦à§‡à¦–à¦¾à¦“'];
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ” **\"$searchTerm\" â€” à¤•à¤ˆ à¤œà¤—à¤¹ à¤®à¤¿à¤²à¤¾:**\n\n$areaStr\n\n**à¤†à¤ª à¤•à¥Œà¤¨ à¤¸à¤¾ à¤¦à¥‡à¤–à¤¨à¤¾ à¤šà¤¾à¤¹à¤¤à¥‡ à¤¹à¥ˆà¤‚?**\nâ€¢ à¤œà¥‰à¤¬/à¤ªà¥à¤°à¥‹à¤¡à¤•à¥à¤¶à¤¨ à¤µà¤¿à¤µà¤°à¤£ â€” à¤¬à¥‹à¤²à¥‡à¤‚ \"à¤œà¥‰à¤¬\"\nâ€¢ à¤ªà¥à¤²à¥‡à¤Ÿ à¤µà¤¿à¤µà¤°à¤£ â€” à¤¬à¥‹à¤²à¥‡à¤‚ \"à¤ªà¥à¤²à¥‡à¤Ÿ\"\nâ€¢ à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤µà¤¿à¤µà¤°à¤£ â€” à¤¬à¥‹à¤²à¥‡à¤‚ \"à¤ªà¥‡à¤ªà¤°\"";
            $suggestions = ['à¤œà¥‰à¤¬ à¤µà¤¿à¤µà¤°à¤£ à¤¦à¤¿à¤–à¤¾à¤“', 'à¤ªà¥à¤²à¥‡à¤Ÿ à¤µà¤¿à¤µà¤°à¤£ à¤¦à¤¿à¤–à¤¾à¤“', 'à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤µà¤¿à¤µà¤°à¤£ à¤¦à¤¿à¤–à¤¾à¤“'];
        } else {
            $answer = "ðŸ” **\"$searchTerm\" â€” found in multiple areas:**\n\n$areaStr\n\n**What would you like to see?**\nâ€¢ Job / Production details â€” say \"job\"\nâ€¢ Plate details â€” say \"plate\"\nâ€¢ Paper Stock details â€” say \"paper\"";
            $suggestions = ['Show Job details', 'Show Plate details', 'Show Paper Stock details'];
        }
        echo json_encode(['ok' => true, 'answer' => $answer, 'provider' => 'ERP AI', 'tool_used' => 'Ambiguous Quoted Search', 'user_lang' => $userLang, 'suggestions' => $suggestions], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// â”€â”€â”€ Knowledge Base Lookup (Admin-Trained Custom Answers) â”€â”€â”€
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
    $kbStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'show', 'details', 'this', 'the', 'a', 'an', 'what', 'where', 'how', 'when', 'who', 'list', 'get', 'for', 'about', 'with', 'from', 'ache', 'koto', 'kotogulo', 'ki', 'kon', 'jabe', 'hote', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'à¦¹à§‡', 'à¦†à¦›à§‡', 'à¦•à¦¤', 'à¦•à¦¤à¦—à§à¦²à§‹', 'à¦•à¦¿', 'à¦•à§€', 'à¦•à§‹à¦¨', 'à¦¦à¦¿à§Ÿà§‡', 'à¦—à¦¿à§Ÿà§‡', 'à¦¨à¦¾à¦®', 'à¦¬à¦²à§‹', 'à¦•à§‹à¦¥à¦¾à§Ÿ', 'à¦•à§‹à¦¨à¦Ÿà¦¿', 'à¦à¦¬à¦‚', 'à¦¬à¦¾', 'à¦à¦°', 'à¦¸à§‡à¦°à¦¾', 'à¦Ÿà¦¿', 'à¦—à§à¦²à§‹', 'à¦—à§à¦²à¦¾', 'à¦¦à¦¾à¦“', 'à¦•à¦°à§‹', 'à¦•à¦°à¦¬à§‡', 'à¦•à§‡', 'à¦•à§‡à¦¨', 'à¦•à¦¬à§‡', 'à¦¥à§‡à¦•à§‡', 'à¦¤à§‡', 'à¦¯à§‡', 'à¦“', 'à¦†à¦°', 'à¦¹à¦²à§‹', 'à¦¹à¦²', 'à¦¨à¦¾à¦•à¦¿', 'à¦¤à¦¾à¦‡', 'à¦¯à§‡à¦¨', 'à¦¤à¦¬à§‡', 'à¦¸à§à¦¤à¦°à¦¾à¦‚', 'à¦–à§à¦¬', 'à¦¸à¦¬à¦šà§‡à¦¯à¦¼à§‡', 'à¦•à§Ÿà§‡à¦•à¦Ÿà¦¿', 'à¦¬à§ˆà¦¶à¦¿à¦·à§à¦Ÿà§à¦¯', 'à¦ªà§ƒà¦¥à¦¿à¦¬à§€à¦°'];

    $promptTokens = array_filter($promptMatches[0] ?? [], function ($t) use ($kbStopwords) {
        return mb_strlen($t) >= 3 && !in_array($t, $kbStopwords, true);
    });

    $bestMatch = null;
    $bestScore = 0;

    while ($row = $res->fetch_assoc()) {
        $rawKeywords = array_map('trim', explode(',', mb_strtolower($row['keywords'], 'UTF-8')));
        $matchScore = 0;
        $matchedPairs = [];   // Track unique kwTokenâ†”pToken pairs to prevent double-counting
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
        // 2 distinct prompt tokens to have matched â€” a single fuzzy token is too weak.
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


// â”€â”€â”€ Direct SQM <-> SQ Inch Unit Conversion Handler â”€â”€â”€
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
            $answer = "ðŸ“ **SQ Inch to SQM Paper Rate Conversion:**\n\n"
                . "â€¢ **Per SQ Inch Rate:** â‚¹" . number_format($givenInchRate, 4) . " / SQ Inch\n"
                . "â€¢ **1 SQM =** 1,550.0031 SQ Inches\n"
                . "â€¢ **Calculated Rate per SQM:** **â‚¹" . number_format($calcSqmRate, 2) . "** / SQM\n\n"
                . "ðŸ’¡ **Formula:** `Per SQM Rate = Per SQ Inch Rate Ã— 1550.0031` (" . number_format($givenInchRate, 4) . " Ã— 1550.0031 = â‚¹" . number_format($calcSqmRate, 2) . " / SQM)\n";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“ **SQ Inch à¤¸à¥‡ SQM à¤ªà¥‡à¤ªà¤° à¤°à¥‡à¤Ÿ à¤—à¤£à¤¨à¤¾:**\n\n"
                . "â€¢ **à¤ªà¥à¤°à¤¤à¤¿ à¤µà¤°à¥à¤— à¤‡à¤‚à¤š à¤¦à¤°:** â‚¹" . number_format($givenInchRate, 4) . " / SQ Inch\n"
                . "â€¢ **1 à¤µà¤°à¥à¤— à¤®à¥€à¤Ÿà¤° =** 1,550.0031 à¤µà¤°à¥à¤— à¤‡à¤‚à¤š\n"
                . "â€¢ **à¤—à¤£à¤¨à¤¾ à¤•à¥€ à¤—à¤ˆ à¤ªà¥à¤°à¤¤à¤¿ à¤µà¤°à¥à¤— à¤®à¥€à¤Ÿà¤° à¤¦à¤°:** **â‚¹" . number_format($calcSqmRate, 2) . "** / SQM\n\n"
                . "ðŸ’¡ **à¤¸à¥‚à¤¤à¥à¤°:** `Per SQM Rate = Per SQ Inch Rate Ã— 1550.0031` (" . number_format($givenInchRate, 4) . " Ã— 1550.0031 = â‚¹" . number_format($calcSqmRate, 2) . " / SQM)\n";
        } else {
            $answer = "ðŸ“ **SQ Inch à¦¥à§‡à¦•à§‡ SQM à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‡à¦Ÿ à¦¹à¦¿à¦¸à¦¾à¦¬:**\n\n"
                . "â€¢ **à¦ªà§à¦°à¦¤à¦¿ à¦¸à§à¦•à§Ÿà¦¾à¦° à¦‡à¦žà§à¦šà¦¿ à¦¦à¦¾à¦®:** â‚¹" . number_format($givenInchRate, 4) . " / SQ Inch\n"
                . "â€¢ **à§§ à¦¸à§à¦•à§Ÿà¦¾à¦° à¦®à¦¿à¦Ÿà¦¾à¦° =** 1,550.0031 à¦¸à§à¦•à§Ÿà¦¾à¦° à¦‡à¦žà§à¦šà¦¿\n"
                . "â€¢ **à¦—à¦£à¦¨à¦¾ à¦•à§€ à¦ªà§à¦°à¦¤à¦¿ à¦¸à§à¦•à¦¯à¦¼à¦¾à¦° à¦®à¦¿à¦Ÿà¦¾à¦° à¦¦à¦¾à¦®:** **â‚¹" . number_format($calcSqmRate, 2) . "** / SQM\n\n"
                . "ðŸ’¡ **à¦—à¦¾à¦£à¦¿à¦¤à¦¿à¦• à¦¨à¦¿à§Ÿà¦®:** `Per SQM Rate = Per SQ Inch Rate Ã— 1550.0031` (" . number_format($givenInchRate, 4) . " Ã— 1550.0031 = â‚¹" . number_format($calcSqmRate, 2) . " / SQM)\n";
        }
    } else {
        preg_match('/(\d+(\.\d+)?)\s*(\/|per|\s*)\s*(sqm|sq mtr|sqr mtr|square meter)/i', $prompt, $sqmMatch);
        $sqmRate = !empty($sqmMatch[1]) ? (float) $sqmMatch[1] : 20.00;
        $sqInchRate = round($sqmRate / 1550.0031, 6);
        $sqInchFormatted = number_format($sqInchRate, 4);
        $sqInchPaise = round($sqInchRate * 100, 2);

        if ($userLang === 'English') {
            $answer = "ðŸ“ **SQM to SQ Inch Paper Rate Conversion:**\n\n"
                . "â€¢ **Per SQM Rate:** â‚¹" . number_format($sqmRate, 2) . " / SQM\n"
                . "â€¢ **1 SQM =** 1,550.0031 SQ Inches\n"
                . "â€¢ **Calculated Cost per SQ Inch:** **â‚¹{$sqInchFormatted}** / SQ Inch (or **{$sqInchPaise} Paise** / SQ Inch)\n\n"
                . "ðŸ’¡ **Formula:** `Per SQ Inch Cost = Per SQM Rate / 1550.0031` (" . number_format($sqmRate, 2) . " Ã· 1550.0031 = â‚¹{$sqInchFormatted})\n";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“ **SQM à¤¸à¥‡ SQ Inch à¤ªà¥‡à¤ªà¤° à¤°à¥‡à¤Ÿ à¤—à¤£à¤¨à¤¾:**\n\n"
                . "â€¢ **à¤ªà¥à¤°à¤¤à¤¿ à¤µà¤°à¥à¤— à¤®à¥€à¤Ÿà¤° à¤¦à¤°:** â‚¹" . number_format($sqmRate, 2) . " / SQM\n"
                . "â€¢ **1 à¤µà¤°à¥à¤— à¤®à¥€à¤Ÿà¤° =** 1,550.0031 à¤µà¤°à¥à¤— à¤‡à¤‚à¤š\n"
                . "â€¢ **à¤—à¤£à¤¨à¤¾ à¤•à¥€ à¤—à¤ˆ à¤ªà¥à¤°à¤¤à¤¿ à¤µà¤°à¥à¤— à¤‡à¤‚à¤š à¤²à¤¾à¤—à¤¤:** **â‚¹{$sqInchFormatted}** / SQ Inch (à¤¯à¤¾ **{$sqInchPaise} à¤ªà¥ˆà¤¸à¥‡** / à¤µà¤°à¥à¤— à¤‡à¤‚à¤š)\n\n"
                . "ðŸ’¡ **à¤¸à¥‚à¤¤à¥à¤°:** `Per SQ Inch Cost = Per SQM Rate / 1550.0031` (" . number_format($sqmRate, 2) . " Ã· 1550.0031 = â‚¹{$sqInchFormatted})\n";
        } else {
            $answer = "ðŸ“ **SQM à¦¥à§‡à¦•à§‡ SQ Inch à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‡à¦Ÿ à¦¹à¦¿à¦¸à¦¾à¦¬:**\n\n"
                . "â€¢ **à¦¸à§à¦•à§Ÿà¦¾à¦° à¦®à¦¿à¦Ÿà¦¾à¦° à¦¦à¦¾à¦® (Per SQM Rate):** â‚¹" . number_format($sqmRate, 2) . " / SQM\n"
                . "â€¢ **à§§ à¦¸à§à¦•à§Ÿà¦¾à¦° à¦®à¦¿à¦Ÿà¦¾à¦° (1 SQM):** 1,550.0031 à¦¸à§à¦•à§Ÿà¦¾à¦° à¦‡à¦žà§à¦šà¦¿ (SQ Inches)\n"
                . "â€¢ **à¦ªà§à¦°à¦¤à¦¿ à¦¸à§à¦•à§Ÿà¦¾à¦° à¦‡à¦žà§à¦šà¦¿ à¦¦à¦¾à¦® (Per SQ Inch Cost):** **â‚¹{$sqInchFormatted}** / SQ Inch (à¦¬à¦¾ **{$sqInchPaise} à¦ªà§Ÿà¦¸à¦¾** / à¦¸à§à¦•à§Ÿà¦¾à¦° à¦‡à¦žà§à¦šà¦¿)\n\n"
                . "ðŸ’¡ **à¦—à¦¾à¦£à¦¿à¦¤à¦¿à¦• à¦¨à¦¿à¦¯à¦¼à¦®:** `Per SQ Inch = Per SQM Rate / 1550.0031` (" . number_format($sqmRate, 2) . " Ã· 1550.0031 = â‚¹{$sqInchFormatted})\n";
        }
    }

    echo json_encode([
        'ok' => true,
        'answer' => $answer,
        'provider' => 'ERP Industrial Unit Conversion Engine',
        'tool_used' => 'SQM & SQ Inch Unit Converter',
        'user_lang' => $userLang
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


// â”€â”€â”€ KB Skip-Logic: Queries with specific data entities bypass KB, go straight to ERP search â”€â”€â”€
$kbSkipPatterns = ['how many', 'à¦•à¦¤', 'à¦•à¦¤à¦—à§à¦²à§‹', 'à¦•à¦¤à¦Ÿà¦¾', 'kitne', 'kitna', 'total count', 'total rolls', 'total paper', 'stock count', 'roll count', 'paper count', 'summary', 'à¦¸à¦¾à¦°à¦¸à¦‚à¦•à§à¦·à§‡à¦ª', 'à¦¸à¦®à¦·à§à¦Ÿà¦¿', 'à¤¸à¤¾à¤°à¤¾à¤‚à¤¶', 'à¤•à¥à¤² à¤—à¤¿à¤¨à¤¤à¥€', 'date', 'time', 'ajke', 'kon date', 'today', 'tarikh', 'à¦¤à¦¾à¦°à¦¿à¦–', 'à¦†à¦œà¦•à§‡', 'à¦¸à¦®à§Ÿ', 'somoy', 'hello', 'hi ', 'kemon acho'];
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
if (!$skipKB && preg_match('/\b\d{2,}\b/', $prompt) && preg_match('/(plate|à¦ªà§à¦²à§‡à¦Ÿ|à¤ªà¥à¤²à¥‡à¤Ÿ|roll|à¦°à§‹à¦²|à¤°à¥‹à¤²|job|à¦œà¦¬|à¤œà¥‰à¤¬)/iu', $p)) {
    $skipKB = true;
}
// Skip KB when query is mm dimension â†’ square inch area conversion
if (!$skipKB && preg_match('/\d+(?:\.\d+)?\s*mm\s*[xX*]\s*\d+(?:\.\d+)?\s*mm/i', $prompt) && preg_match('/sqr?\s*inch(es)?|sq\.?\s*in|square\s*inch(es)?/i', $prompt)) {
    $skipKB = true;
}

$knowledgeMatch = $skipKB ? null : check_knowledge_base($db, $prompt);
if ($knowledgeMatch !== null) {

    $kbAnswer = $knowledgeMatch['answer'];
    $kbCategory = $knowledgeMatch['category'];

    echo json_encode([
        'ok' => true,
        'answer' => "ðŸ“š " . $kbAnswer,
        'provider' => 'ERP AI Knowledge Base',
        'tool_used' => 'Admin Knowledge Base (' . $kbCategory . ')',
        'user_lang' => $userLang,
        'kb_match_id' => (int) $knowledgeMatch['id']
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

$hasCompanyQuery = preg_match('/\b(krishna|austin|navkar|nrgi)\b/i', $prompt) || strpos($p, 'à¦•à§ƒà¦·à§à¦£à¦¾') !== false || strpos($p, 'à¦…à¦¸à§à¦Ÿà¦¿à¦¨') !== false || strpos($p, 'à¦¨à¦­à¦•à¦¾à¦°') !== false || strpos($p, 'à¦à¦¨à¦†à¦°à¦œà¦¿à¦†à¦‡') !== false;
$hasDbQueryIntent = preg_match('/\b(die|dies|plate|plates|stock|inventory|search|find|any|is there|kono|ache)\b/i', $prompt);

$isMathIntent = !$hasCompanyQuery && !$hasDbQueryIntent && (
    preg_match('/\d+\s*mm\s*[xX*]\s*\d+\s*mm/i', $prompt) ||
    strpos($p, 'running meter') !== false ||
    strpos($p, 'running mtr') !== false ||
    (strpos($p, 'ups') !== false && strpos($p, 'gap') !== false)
);

// â”€â”€â”€ Simple mmÂ² â†’ Square Inch Area Conversion â”€â”€â”€
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
            $answer = "ðŸ“ **Millimeter to Square Inch Area Conversion:**\n\n"
                . "â€¢ **Dimensions:** {$mmW}mm Ã— {$mmL}mm\n"
                . "â€¢ **In Inches:** {$mmWInch}â€³ Ã— {$mmLInch}â€³\n"
                . "â€¢ **Area:** **{$mm2} mmÂ²** = **{$sqInches} sq in**\n\n"
                . "ðŸ’¡ **Formula:** `({$mmW} Ã— {$mmL}) Ã· 645.16 = {$sqInches} sq in`\n"
                . "*(1 sq in = 25.4mm Ã— 25.4mm = 645.16 mmÂ²)*";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“ **à¤®à¤¿à¤²à¥€à¤®à¥€à¤Ÿà¤° à¤¸à¥‡ à¤µà¤°à¥à¤— à¤‡à¤‚à¤š à¤•à¥à¤·à¥‡à¤¤à¥à¤°à¤«à¤² à¤°à¥‚à¤ªà¤¾à¤‚à¤¤à¤°à¤£:**\n\n"
                . "â€¢ **à¤†à¤¯à¤¾à¤®:** {$mmW}mm Ã— {$mmL}mm\n"
                . "â€¢ **à¤‡à¤‚à¤š à¤®à¥‡à¤‚:** {$mmWInch}â€³ Ã— {$mmLInch}â€³\n"
                . "â€¢ **à¤•à¥à¤·à¥‡à¤¤à¥à¤°à¤«à¤²:** **{$mm2} mmÂ²** = **{$sqInches} à¤µà¤°à¥à¤— à¤‡à¤‚à¤š**\n\n"
                . "ðŸ’¡ **à¤¸à¥‚à¤¤à¥à¤°:** `({$mmW} Ã— {$mmL}) Ã· 645.16 = {$sqInches} à¤µà¤°à¥à¤— à¤‡à¤‚à¤š`\n"
                . "*(1 à¤µà¤°à¥à¤— à¤‡à¤‚à¤š = 25.4mm Ã— 25.4mm = 645.16 mmÂ²)*";
        } else {
            $answer = "ðŸ“ **à¦®à¦¿à¦²à¦¿à¦®à¦¿à¦Ÿà¦¾à¦° à¦¥à§‡à¦•à§‡ à¦¸à§à¦•à¦¯à¦¼à¦¾à¦° à¦‡à¦žà§à¦šà¦¿ à¦à¦²à¦¾à¦•à¦¾ à¦°à§‚à¦ªà¦¾à¦¨à§à¦¤à¦°:**\n\n"
                . "â€¢ **à¦®à¦¾à¦ª:** {$mmW}mm Ã— {$mmL}mm\n"
                . "â€¢ **à¦‡à¦žà§à¦šà¦¿à¦¤à§‡:** {$mmWInch}â€³ Ã— {$mmLInch}â€³\n"
                . "â€¢ **à¦à¦²à¦¾à¦•à¦¾:** **{$mm2} mmÂ²** = **{$sqInches} à¦¬à¦°à§à¦— à¦‡à¦žà§à¦šà¦¿**\n\n"
                . "ðŸ’¡ **à¦¸à§‚à¦¤à§à¦°:** `({$mmW} Ã— {$mmL}) Ã· 645.16 = {$sqInches} à¦¬à¦°à§à¦— à¦‡à¦žà§à¦šà¦¿`\n"
                . "*(1 à¦¬à¦°à§à¦— à¦‡à¦žà§à¦šà¦¿ = 25.4mm Ã— 25.4mm = 645.16 mmÂ²)*";
        }

        echo json_encode([
            'ok' => true,
            'answer' => $answer,
            'provider' => 'ERP Industrial Unit Conversion Engine',
            'tool_used' => 'mmÂ² to Square Inch Converter',
            'user_lang' => $userLang
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if ($isMathIntent) {
    $math = calculate_label_costing_math($prompt);


    if ($userLang === 'English') {
        $answer = "ðŸ§® **Industrial Label Calculation Breakdown:**\n\n"
            . "â€¢ **Label Specification:** {$math['width_mm']}mm X {$math['length_mm']}mm | **{$math['ups']} Ups** | **Middle Gap:** {$math['gap_mm']}mm\n"
            . "â€¢ **Total Quantity Required:** " . number_format($math['qty']) . " Labels / Pieces\n"
            . "â€¢ **Repeat Pitch:** {$math['repeat_pitch_mm']}mm per impression\n"
            . "â€¢ **Total Impressions Required:** " . number_format($math['impressions']) . "\n"
            . "â€¢ **Net Used Width:** {$math['net_used_width_mm']}mm\n"
            . "â€¢ **Required Running Meters:** **" . number_format($math['running_meters'], 2) . " Meters**\n";

        if ($math['has_roll_width']) {
            $answer .= "\nðŸ“ **Parent Roll & Wastage Analysis:**\n"
                . "â€¢ **Parent Roll Width:** {$math['parent_width_mm']}mm\n"
                . "â€¢ **Side Wastage Width:** {$math['side_waste_width_mm']}mm ({$math['side_waste_pct']}% Wastage)\n"
                . "â€¢ **Total Paper Area (SQM):** **{$math['total_paper_sqm']} SQM**\n"
                . "â€¢ **Total Weight (Est. 80 GSM):** **{$math['total_weight_kg']} KG**\n";
        }
        if ($math['has_rate']) {
            $answer .= "\nðŸ’° **Costing Breakdown:**\n"
                . "â€¢ **Total Paper Cost:** **â‚¹" . number_format($math['total_paper_cost'], 2) . "**\n"
                . "â€¢ **Cost per Label:** **â‚¹{$math['cost_per_label']}** / label\n";
        }
        if (!$math['has_roll_width'] || !$math['has_rate']) {
            $answer .= "\nðŸ’¡ **Need Total Cost, SQM & Wastage Calculation?**\n"
                . "Please reply with your missing inputs:\n"
                . (!$math['has_roll_width'] ? "1. ðŸ“ **Parent Roll Width (mm):** (e.g. `on 250mm roll`)\n" : "")
                . (!$math['has_rate'] ? "2. ðŸ’° **Paper Price (Rate):** (e.g. `at Rs 300/kg`)\n" : "");
        }
    } elseif ($userLang === 'Hindi') {
        $answer = "ðŸ§® **à¤”à¤¦à¥à¤¯à¥‹à¤—à¤¿à¤• à¤²à¥‡à¤¬à¤² à¤—à¤£à¤¨à¤¾ à¤µà¤¿à¤µà¤°à¤£:**\n\n"
            . "â€¢ **à¤²à¥‡à¤¬à¤² à¤µà¤¿à¤¨à¤¿à¤°à¥à¤¦à¥‡à¤¶:** {$math['width_mm']}mm X {$math['length_mm']}mm | **{$math['ups']} Ups** | **à¤—à¥ˆà¤ª:** {$math['gap_mm']}mm\n"
            . "â€¢ **à¤•à¥à¤² à¤†à¤µà¤¶à¥à¤¯à¤• à¤®à¤¾à¤¤à¥à¤°à¤¾:** " . number_format($math['qty']) . " à¤ªà¥€à¤¸\n"
            . "â€¢ **à¤°à¤¿à¤ªà¥€à¤Ÿ à¤ªà¤¿à¤š:** {$math['repeat_pitch_mm']}mm à¤ªà¥à¤°à¤¤à¤¿ à¤‡à¤®à¥à¤ªà¥à¤°à¥ˆà¤¶à¤¨\n"
            . "â€¢ **à¤•à¥à¤² à¤‡à¤®à¥à¤ªà¥à¤°à¥‡à¤¶à¤¨ à¤†à¤µà¤¶à¥à¤¯à¤•à¤¤à¤¾:** " . number_format($math['impressions']) . " à¤Ÿà¤¿\n"
            . "â€¢ **à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ (Net Width):** {$math['net_used_width_mm']}mm\n"
            . "â€¢ **à¤†à¤µà¤¶à¥à¤¯à¤• à¤°à¤¨à¤¿à¤‚à¤— à¤®à¥€à¤Ÿà¤°:** **" . number_format($math['running_meters'], 2) . " à¤®à¥€à¤Ÿà¤°**\n";

        if ($math['has_roll_width']) {
            $answer .= "\nðŸ“ **à¤ªà¥‡à¤ªà¤° à¤µà¥‡à¤¸à¥à¤Ÿà¥‡à¤œ:**\n"
                . "â€¢ **à¤®à¤¦à¤° à¤°à¥‹à¤² à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ:** {$math['parent_width_mm']}mm\n"
                . "â€¢ **à¤¸à¤¾à¤‡à¤Ÿ à¤µà¥‡à¤¸à¥à¤Ÿà¥‡à¤œ:** {$math['side_waste_width_mm']}mm ({$math['side_waste_pct']}%)\n"
                . "â€¢ **à¤•à¥à¤² à¤ªà¥‡à¤ªà¤° à¤•à¥à¤·à¥‡à¤¤à¥à¤°à¤«à¤² (SQM):** **{$math['total_paper_sqm']} SQM**\n"
                . "â€¢ **à¤•à¥à¤² à¤“à¤œà¤¨ (Est. 80 GSM):** **{$math['total_weight_kg']} KG**\n";
        }
        if (!$math['has_roll_width'] || !$math['has_rate']) {
            $answer .= "\nðŸ’¡ **à¤•à¥à¤¯à¤¾ à¤†à¤ª à¤•à¥à¤² à¤²à¤¾à¤—à¤¤ à¤”à¤° à¤µà¥‡à¤¸à¥à¤Ÿà¥‡à¤œ à¤•à¥€ à¤—à¤£à¤¨à¤¾ à¤šà¤¾à¤¹à¤¤à¥‡ à¤¹à¥ˆà¤‚?**\n"
                . "à¤•à¥ƒà¤ªà¤¯à¤¾ à¤¶à¥‡à¤· à¤µà¤¿à¤µà¤°à¤£ à¤¬à¤¤à¤¾à¤à¤‚:\n"
                . (!$math['has_roll_width'] ? "1. ðŸ“ **à¤®à¤¦à¤° à¤°à¥‹à¤² à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ (mm):** (à¤‰à¤¦à¤¾. `250mm roll`)\n" : "")
                . (!$math['has_rate'] ? "2. ðŸ’° **à¤ªà¥‡à¤ªà¤° à¤•à¥€ à¤•à¥€à¤®à¤¤ (Rate):** (à¤‰à¤¦à¤¾. `Rs 300/kg`)\n" : "");
        }
    } else {
        $answer = "ðŸ§® **à¦‡à¦¨à§à¦¡à¦¾à¦¸à§à¦Ÿà§à¦°à¦¿à¦¯à¦¼à¦¾à¦² à¦²à§‡à¦¬à§‡à¦² à¦—à¦¾à¦£à¦¿à¦¤à¦¿à¦• à¦¹à¦¿à¦¸à¦¾à¦¬:**\n\n"
            . "â€¢ **à¦²à§‡à¦¬à§‡à¦² à¦¸à¦¾à¦‡à¦œ:** {$math['width_mm']}mm X {$math['length_mm']}mm | **{$math['ups']} Ups** | **à¦®à¦¿à¦¡à¦² à¦—à§à¦¯à¦¾à¦ª:** {$math['gap_mm']}mm\n"
            . "â€¢ **à¦®à§‹à¦Ÿ à¦•à§‹à§Ÿà¦¾à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿:** " . number_format($math['qty']) . " à¦Ÿà¦¿ à¦²à§‡à¦¬à§‡à¦² / à¦ªà¦¿à¦¸\n"
            . "â€¢ **à¦°à¦¿à¦ªà¦¿à¦Ÿ à¦ªà¦¿à¦š:** {$math['repeat_pitch_mm']}mm à¦ªà§à¦°à¦¤à¦¿ à¦‡à¦®à§à¦ªà§à¦°à§‡à¦¶à¦¨à§‡\n"
            . "â€¢ **à¦®à§‹à¦Ÿ à¦‡à¦®à§à¦ªà§à¦°à§‡à¦¶à¦¨ à¦ªà§à¦°à¦¯à¦¼à§‹à¦œà¦¨:** " . number_format($math['impressions']) . " à¦Ÿà¦¿\n"
            . "â€¢ **à¦¬à§à¦¯à¦¬à¦¹à§ƒà¦¤ à¦šà¦“à¦¡à¦¼à¦¾ (Net Width):** {$math['net_used_width_mm']}mm\n"
            . "â€¢ **à¦†à¦¬à¦¶à§à¦¯à¦• à¦°à¦¾à¦¨à¦¿à¦‚ à¦®à¦¿à¦Ÿà¦¾à¦°:** **" . number_format($math['running_meters'], 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°**\n";

        if ($math['has_roll_width']) {
            $answer .= "\nðŸ“ **à¦®à¦¾à¦¦à¦¾à¦° à¦°à§‹à¦² à¦“ à¦“à¦¯à¦¼à§‡à¦¸à§à¦Ÿà§‡à¦œ à¦¹à¦¿à¦¸à¦¾à¦¬:**\n"
                . "â€¢ **à¦®à¦¾à¦¦à¦¾à¦° à¦°à§‹à¦² à¦šà¦“à§œà¦¾:** {$math['parent_width_mm']}mm\n"
                . "â€¢ **à¦¸à¦¾à¦‡à¦Ÿ à¦“à¦¯à¦¼à§‡à¦¸à§à¦Ÿà§‡à¦œ:** {$math['side_waste_width_mm']}mm ({$math['side_waste_pct']}%)\n"
                . "â€¢ **à¦®à§‹à¦Ÿ à¦ªà§‡à¦ªà¦¾à¦° à¦•à§à¦·à§‡à¦¤à§à¦°à¦«à¦² (SQM):** **{$math['total_paper_sqm']} SQM**\n"
                . "â€¢ **à¦®à§‹à¦Ÿ à¦“à¦œà¦¨ (Est. 80 GSM):** **{$math['total_weight_kg']} KG**\n";
        }
        if (!$math['has_roll_width'] || !$math['has_rate']) {
            $answer .= "\nðŸ’¡ **à¦†à¦ªà¦¨à¦¿ à¦•à¦¿ à¦®à§‹à¦Ÿ à¦ªà§‡à¦ªà¦¾à¦° à¦–à¦°à¦š à¦à¦¬à¦‚ à¦“à§Ÿà§‡à¦¸à§à¦Ÿà§‡à¦œ à¦¹à¦¿à¦¸à¦¾à¦¬ à¦œà¦¾à¦¨à¦¤à§‡ à¦šà¦¾à¦¨?**\n"
                . "à¦¤à¦¾à¦¹à¦²à§‡ à¦…à¦¨à§à¦—à¦¹ à¦•à¦°à§‡ à¦¨à¦¿à¦šà§‡à¦° à¦¬à¦¾à¦•à¦¿ à¦¤à¦¥à§à¦¯à¦—à§à¦²à§‹ à¦Ÿà¦¾à¦‡à¦ª à¦•à¦°à§à¦¨:\n"
                . (!$math['has_roll_width'] ? "à§§. ðŸ“ **à¦®à¦¾à¦¦à¦¾à¦° à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦²à§‡à¦° à¦šà¦“à§œà¦¾ (mm):** (à¦¯à§‡à¦®à¦¨: `250mm roll`)\n" : "")
                . (!$math['has_rate'] ? "à§¨. ðŸ’° **à¦•à¦¾à¦—à¦œà§‡à¦° à¦¦à¦¾à¦® (Rate):** (à¦¯à§‡à¦®à¦¨: `Rs 300/kg`)\n" : "");
        }
    }

    echo json_encode([
        'ok' => true,
        'answer' => $answer,
        'provider' => 'ERP Industrial Label Math Engine',
        'tool_used' => 'Industrial Label Calculator',
        'user_lang' => $userLang
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

    // â”€â”€â”€ SESSION PRIORITY MODE: Search priority source FIRST â”€â”€â”€
    $priorityMode = $_SESSION['ai_priority_mode'] ?? '';

    // PRIORITY: Paper Stock â€” search first if priority mode active
    if ($priorityMode === 'paperstock') {
        $isOtherModuleQuery = (strpos($p, 'live') !== false || strpos($p, 'floor') !== false || strpos($p, 'plate') !== false || strpos($p, 'plate') !== false || strpos($p, 'die') !== false || strpos($p, 'anilox') !== false || strpos($p, 'job') !== false || strpos($p, 'planning') !== false || strpos($p, 'dispatch') !== false || strpos($p, 'slit') !== false || strpos($p, 'finished') !== false || strpos($p, 'packing') !== false || strpos($p, 'à¦²à¦¾à¦‡à¦­') !== false || strpos($p, 'à¦«à§à¦²à§‹à¦°') !== false || strpos($p, 'à¦ªà§à¦²à§‡à¦Ÿ') !== false || strpos($p, 'à¦¡à¦¾à¦‡') !== false || strpos($p, 'à¦œà¦¬') !== false);
        if ($isOtherModuleQuery) {
            unset($_SESSION['ai_priority_mode']);
            $priorityMode = '';
        }
        $isPaperQuery = !$isOtherModuleQuery && (strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'slc/') !== false || strpos($p, 'chromo') !== false || strpos($p, 'thermal') !== false || strpos($p, 'stock') !== false || strpos($p, 'maplitho') !== false || strpos($p, 'pp') !== false || strpos($p, 'white') !== false || strpos($p, 'jumbo') !== false || strpos($p, 'avery') !== false || strpos($p, 'krishna') !== false || strpos($p, 'austin') !== false || strpos($p, 'navkar') !== false || strpos($p, 'nrgi') !== false || strpos($p, 'company') !== false || strpos($p, 'à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿') !== false || strpos($p, 'à¦¸à§à¦Ÿà¦•') !== false || strpos($p, 'à¦°à§‹à¦²') !== false || strpos($p, 'à¦•à¦¤') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'summary') !== false || strpos($p, 'breakdown') !== false || strpos($p, 'metro') !== false || strpos($p, 'sqm') !== false || strpos($p, 'running') !== false || strpos($p, 'status') !== false);
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

                $pTitle = 'Paper Stock';
                if ($pCompany && $pType) {
                    $pTitle = strtoupper($pCompany . ' ' . $pType);
                } elseif ($pCompany) {
                    $pTitle = strtoupper($pCompany) . ' Paper Stock';
                } elseif ($pType) {
                    $pTitle = strtoupper($pType) . ' Paper Stock';
                }
                $pDirectAnswer = "ðŸ“œ **{$pTitle}** â€” Found **{$totalCount} rolls**:\n\n" . format_records_table($data, 'paper_stock', $userLang);
                return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => $totalMeters, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $pDirectAnswer, 'data' => []];
            }
        }
    }

    // PRIORITY: Printing Plates â€” search first if priority mode active
    if ($priorityMode === 'plate') {
        $isOtherModuleQueryPlate = (strpos($p, 'live') !== false || strpos($p, 'floor') !== false || strpos($p, 'paper') !== false || strpos($p, 'roll') !== false || strpos($p, 'stock') !== false || strpos($p, 'anilox') !== false || strpos($p, 'dispatch') !== false || strpos($p, 'finished') !== false || strpos($p, 'packing') !== false || strpos($p, 'ãƒ©ã‚¤ãƒ–') !== false || strpos($p, 'í”Œë¡œì–´') !== false || strpos($p, 'íŽ˜ì´í¼') !== false || strpos($p, 'ë¡¤') !== false);
        if ($isOtherModuleQueryPlate) {
            unset($_SESSION['ai_priority_mode']);
            $priorityMode = '';
        }
        $isPlateQuery = !$isOtherModuleQueryPlate && (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'à¦ªà§à¦²à§‡à¦Ÿ') !== false || strpos($p, 'à¤ªà¥à¤²à¥‡à¤Ÿ') !== false || strpos($p, 'die') !== false || strpos($p, 'cylinder') !== false || strpos($p, 'ups') !== false || strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'à¦•à¦¤') !== false || strpos($p, 'à¦•à¦¤à¦—à§à¦²à§‹') !== false || strpos($p, 'kitne') !== false);
        if ($isPlateQuery) {
            $pToolName = 'Printing Plates Master Tool (Priority)';
            $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
            $pTotalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
            if ($pTotalCount > 0) {
                $toolName = $pToolName;
                $totalCount = $pTotalCount;
                $pStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'plate', 'plates', 'list', 'show', 'details', 'detail', 'this', 'the', 'a', 'an', 'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does', 'repeat', 'gap', 'gaph', 'gapv', 'size', 'ups', 'cylinder', 'paper', 'die', 'core', 'rewinding', 'value', 'color', 'colors', 'spec', 'special', 'what', 'how', 'give', 'if', 'run', 'running', 'much', 'many', 'quantity', 'qty', 'meter', 'meters', 'mtr', 'will', 'be', 'produced', 'print', 'printing', 'require', 'required', 'need', 'needed', 'or', 'and', 'calculate', 'calculating', 'calc', 'length', 'roll', 'pcs', 'pieces', 'labels', 'koto', 'kotogulo', 'hobe', 'lagbe', 'korle', 'korte', 'asob', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'à¦¹à¦¬à§‡', 'à¦†à¦›à§‡', 'à¦•à¦¤', 'à¦•à¦¤à¦—à§à¦²à§‹', 'à¦•à¦¿', 'à¦•à§€', 'à¦•à§‹à¦¨'];
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

                    $pDirectAnswer = "ðŸ“ **Printing Plates** â€” Found **{$totalCount} plates**:\n\n" . format_records_table($data, 'plate', $userLang);
                    return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $pDirectAnswer, 'data' => []];
                }
            }
        }
    }
    // â”€â”€â”€ END PRIORITY MODE â”€â”€â”€

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
        if (strpos($p, 'à¦•à§ƒà¦·à§à¦£à¦¾') !== false) $compName = 'KRISHNA';
        elseif (strpos($p, 'à¦…à¦¸à§à¦Ÿà¦¿à¦¨') !== false) $compName = 'AUSTIN';
        elseif (strpos($p, 'à¦¨à¦­à¦•à¦¾à¦°') !== false) $compName = 'NAVKAR';
        elseif (strpos($p, 'à¦à¦¨à¦†à¦°à¦œà¦¿à¦†à¦‡') !== false) $compName = 'NRGI';
        elseif (strpos($p, 'à¦¨à¦¿à¦¤à¦¿à¦¨') !== false) $compName = 'NITIN';
    }

    $paperTypeFilter = '';
    if (strpos($p, 'chromo') !== false || strpos($p, 'à¦•à§à¦°à§‹à¦®') !== false || strpos($p, 'à¦•à§à¦°à§‹à¦®à§‹') !== false) {
        $paperTypeFilter = 'chromo';
    } elseif (strpos($p, 'thermal') !== false || strpos($p, 'à¦¥à¦¾à¦°à§à¦®à¦¾à¦²') !== false) {
        $paperTypeFilter = 'thermal';
    } elseif (strpos($p, 'pp white') !== false || strpos($p, 'pp-white') !== false || strpos($p, 'à¦ªà¦¿à¦ªà¦¿ à¦¹à§‹à¦¯à¦¼à¦¾à¦‡à¦Ÿ') !== false) {
        $paperTypeFilter = 'pp-white';
    } elseif (strpos($p, 'pp clear') !== false || strpos($p, 'pp-clear') !== false || strpos($p, 'à¦ªà¦¿à¦ªà¦¿ à¦•à§à¦²à¦¿à¦¯à¦¼à¦¾à¦°') !== false) {
        $paperTypeFilter = 'pp-clear';
    } elseif (strpos($p, 'maplitho') !== false || strpos($p, 'à¦®à§à¦¯à¦¾à¦ªà¦²à¦¿à¦¥à§‹') !== false) {
        $paperTypeFilter = 'maplitho';
    }

    $hasToolIntent = (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'à¦ªà§à¦²à§‡à¦Ÿ') !== false || strpos($p, 'à¤ªà¥à¤²à¥‡à¤Ÿ') !== false || ((strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false || strpos($p, 'à¦•à¦¤') !== false) && (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || preg_match('/\b(run|paper)\b/', $p))));

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
            $answer = "ðŸ“Š **{$labelStr} Paper Stock Analytics Dashboard:**\n"
                . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                . "ðŸ”¢ **Total Paper Rolls:** **" . number_format($totalRolls) . " Rolls**\n"
                . "ðŸ“ **Total Running Length:** **" . number_format($totalRunningMtr, 2) . " meters**\n"
                . "ðŸ“ **Total Paper Area (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n\n"
                . "ðŸ“œ **Jumbo Parent Rolls (â‰¥1000mm):** **" . number_format($jumboCount) . " Rolls** (1000mm or above width)\n"
                . "âœ‚ï¸ **Slitted Stock Rolls (<1000mm):** **" . number_format($slittedCount) . " Rolls**\n\n"
                . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                . "ðŸ‘‰ [Open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“Š **{$labelStr} à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡:**\n"
                . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                . "ðŸ”¢ **à¤•à¥à¤² à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤²:** **" . number_format($totalRolls) . " à¤°à¥‹à¤²**\n"
                . "ðŸ“ **à¤•à¥à¤² à¤°à¤¨à¤¿à¤‚à¤— à¤®à¥€à¤Ÿà¤°:** **" . number_format($totalRunningMtr, 2) . " à¤®à¥€à¤Ÿà¤°**\n"
                . "ðŸ“ **à¤•à¥à¤² à¤•à¥à¤·à¥‡à¤¤à¥à¤°à¤«à¤² (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n\n"
                . "ðŸ“œ **à¤œà¤‚à¤¬à¥‹ à¤°à¥‹à¤² (à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ â‰¥ 1000mm):** **" . number_format($jumboCount) . " à¤œà¤‚à¤¬à¥‹ à¤°à¥‹à¤²**\n"
                . "âœ‚ï¸ **à¤¸à¥à¤²à¤¿à¤Ÿà¥‡à¤¡ à¤°à¥‹à¤² (à¤šà¥Œà¤¡à¤¼à¤¾à¤ˆ < 1000mm):** **" . number_format($slittedCount) . " à¤°à¥‹à¤²**\n\n"
                . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                . "ðŸ‘‰ [à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/paper_stock/index.php)";
        } else {
            $answer = "ðŸ“Š **{$labelStr} à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡:**\n"
                . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                . "ðŸ”¢ **à¦®à§‹à¦Ÿ à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦²:** **" . number_format($totalRolls) . "à¦Ÿà¦¿ à¦°à§‹à¦²**\n"
                . "ðŸ“ **à¦®à§‹à¦Ÿ à¦°à¦¾à¦¨à¦¿à¦‚ à¦®à¦¿à¦Ÿà¦¾à¦°:** **" . number_format($totalRunningMtr, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°**\n"
                . "ðŸ“ **à¦®à§‹à¦Ÿ à¦•à§à¦·à§‡à¦¤à§à¦°à¦«à¦² (SQM):** **" . number_format($totalSqm, 2) . " SQM**\n\n"
                . "ðŸ“œ **à¦œà¦¾à¦®à§à¦¬à§‹ à¦°à§‹à¦² (à¦šà¦“à§œà¦¾à¤ˆ â‰¥ à§§à§¦à§¦à§¦ à¦®à¦¿à¦®à¦¿):** **" . number_format($jumboCount) . "à¦Ÿà¦¿ à¦œà¦¾à¦®à§à¦¬à§‹ à¦°à§‹à¦²**\n"
                . "âœ‚ï¸ **à¦¸à§à¦²à¦¿à¦Ÿà§‡à¦¡ à¦°à§‹à¦² (à¦šà¦“à¦¡à¦¼à¦¾à¤ˆ < à§§à§¦à§¦à§¦ à¦®à¦¿à¦®à¦¿):** **" . number_format($slittedCount) . "à¦Ÿà¦¿ à¦°à§‹à¦²**\n\n"
                . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                . "ðŸ‘‰ [à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨]({$baseUrl}/modules/paper_stock/index.php)";
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
        (strpos($p, 'roll') !== false || strpos($p, 'à¦°à§‹à¦²') !== false) &&
        (strpos($p, 'total') !== false || strpos($p, 'koto') !== false || strpos($p, 'à¦•à¦¤') !== false || strpos($p, 'how many') !== false) &&
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
            $answer = "ðŸ“Š **Total ERP Roll Summary Breakdown:**\n\n"
                . "Your ERP database contains 2 categories of roll inventory:\n\n"
                . "1. ðŸ“œ **Parent Paper Stock Rolls:** **" . number_format($paperCount) . " Rolls** (Total **" . number_format($paperMtr, 2) . " meters** in stock)\n"
                . "2. ðŸ“¦ **Finished Goods Packed Rolls:** **" . number_format($fgCount) . " Batches / Rolls** (Total **" . number_format($fgQty) . " items**)\n\n"
                . "â“ **Which specific roll details would you like to view?**\n"
                . "â€¢ Reply **\"Show paper stock rolls\"** for Parent Jumbo Paper Rolls\n"
                . "â€¢ Reply **\"Show finished goods stock\"** for Packed Finished Label Rolls";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“Š **à¤•à¥à¤² à¤ˆà¤†à¤°à¤ªà¥€ à¤°à¥‹à¤² à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n\n"
                . "à¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤®à¥‡à¤‚ 2 à¤ªà¥à¤°à¤•à¤¾à¤° à¤•à¥‡ à¤°à¥‹à¤² à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¹à¥ˆà¤‚:\n\n"
                . "1. ðŸ“œ **à¤®à¤¦à¤° à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¥‰à¤• à¤°à¥‹à¤²:** **" . number_format($paperCount) . " à¤°à¥‹à¤²** (à¤•à¥à¤² **" . number_format($paperMtr, 2) . " à¤®à¥€à¤Ÿà¤°**)\n"
                . "2. ðŸ“¦ **à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤—à¥à¤¡à¥à¤¸ à¤ªà¥ˆà¤•à¥à¤¡ à¤°à¥‹à¤²:** **" . number_format($fgCount) . " à¤¬à¥ˆà¤š / à¤°à¥‹à¤²** (à¤•à¥à¤² **" . number_format($fgQty) . " à¤ªà¥€à¤¸**)\n\n"
                . "â“ **à¤†à¤ª à¤•à¤¿à¤¸ à¤°à¥‹à¤² à¤•à¤¾ à¤µà¤¿à¤µà¤°à¤£ à¤¦à¥‡à¤–à¤¨à¤¾ à¤šà¤¾à¤¹à¤¤à¥‡ à¤¹à¥ˆà¤‚?**\n"
                . "â€¢ **\"Paper stock roll dekhaw\"** à¤Ÿà¤¾à¤‡à¤ª à¤•à¤°à¥‡à¤‚ - à¤®à¤¦à¤° à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤² à¤•à¥‡ à¤²à¤¿à¤\n"
                . "â€¢ **\"Finished goods stock dekhaw\"** à¤Ÿà¤¾à¤‡à¤ª à¤•à¤°à¥‡à¤‚ - à¤ªà¥ˆà¤•à¥à¤¡ à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤°à¥‹à¤² à¤•à¥‡ à¤²à¤¿à¤";
        } else {
            $answer = "ðŸ“Š **à¦®à§‹à¦Ÿ à¦‡à¦†à¦°à¦ªà¦¿ à¦°à§‹à¦² à¦¸à§à¦Ÿà¦• à¦¸à¦¾à¦°à¦¾à¦‚à¦¶:**\n\n"
                . "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ à§¨ à¦§à¦°à¦¨à§‡à¦° à¦°à§‹à¦² à¦‰à¦ªà¦²à¦¬à§à¦§ à¦°à¦¯à¦¼à§‡à¦›à§‡:\n\n"
                . "à§§. ðŸ“œ **à¦®à¦¾à¦¦à¦¾à¦° à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦°à§‹à¦²:** **" . number_format($paperCount) . "à¦Ÿà¦¿ à¦°à§‹à¦²** (à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ **" . number_format($paperMtr, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°**)\n"
                . "à§¨. ðŸ“¦ **à¦«à¦¿à¦¨à¦¿à¦¶à§à¦¡ à¦—à§à¦¡à¦¸ à¦ªà§à¦¯à¦¾à¦•à¦¡ à¦°à§‹à¦²:** **" . number_format($fgCount) . "à¦Ÿà¦¿ à¦¬à§à¦¯à¦¾à¦š/à¦ªà§à¦¯à¦¾à¦•à¦¡ à¦°à§‹à¦²** (à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ **" . number_format($fgQty) . "à¦Ÿà¦¿**)\n\n"
                . "â“ **à¦†à¦ªà¦¨à¦¿ à¦•à§‹à¦¨ à¦°à§‹à¦²à§‡à¦° à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ à¦¤à¦¾à¦²à¦¿à¦•à¦¾ à¦¦à§‡à¦–à¦¤à§‡ à¦šà¦¾à¦¨?**\n"
                . "â€¢ à¦Ÿà¦¾à¦‡à¦ª à¦•à¦°à§à¦¨: **\"Paper stock roll dekhaw\"** (à¦®à¦¦à¦° à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦²à§‡à¦° à¦œà¦¨à§à¦¯)\n"
                . "â€¢ à¦Ÿà¦¾à¦‡à¦ª à¦•à¦°à§à¦¨: **\"Finished goods stock dekhaw\"** (à¦ªà§à¦¯à¦¾à¦•à¦¡ à¦«à¦¿à¦¨à¦¿à¦¶à§à¦¡ à¦°à§‹à¦²à§‡à¦° à¦œà¦¨à§à¦¯)";
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
    if (strpos($p, 'dash') !== false || strpos($p, 'kpi') !== false || strpos($p, 'overview') !== false || strpos($p, 'metric') !== false || strpos($p, 'analytic') !== false || preg_match('/\b(stat|stats|statistics)\b/i', $prompt) || strpos($p, 'executive') !== false || strpos($p, 'à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡') !== false) {

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
            $answer = "ðŸ“Š **ERP Executive Dashboard & Live System KPIs:**\n\n"
                . "Here is the real-time operational summary from your ERP Dashboard:\n\n"
                . "ðŸ“œ **Paper Roll Inventory:**\n"
                . "  - Total Available Rolls: **" . number_format($stockCount) . " Rolls** (" . number_format($stockMtr, 2) . " meters)\n"
                . "  - Low Stock Alert (<500m): **" . number_format($lowStock) . " Rolls**\n\n"
                . "ðŸ­ **Production & Live Floor:**\n"
                . "  - Active Master Jobs: **" . number_format($jobsActive) . " Jobs**\n"
                . "  - Currently Running Jobs: **" . number_format($jobsRunning) . " Jobs**\n"
                . "  - Pending / Queued Department Jobs: **" . number_format($jobsPending) . " Job Cards**\n"
                . "  - Completed Jobs This Month: **" . number_format($jobsCompletedMonth) . " Jobs**\n\n"
                . "ðŸ’¼ **Sales & Estimates:**\n"
                . "  - Active Running Sales Orders: **" . number_format($ordersActive) . " Orders**\n"
                . "  - Active Cost Estimates: **" . number_format($estimatesActive) . " Estimates** (This Month Value: **â‚¹" . number_format($estimatesVal, 2) . "**)\n\n"
                . "ðŸ‘‰ [Click here to open Executive Dashboard Page]({$baseUrl}/modules/dashboard/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“Š **à¤ˆà¤†à¤°à¤ªà¥€ à¤à¤•à¥à¤œà¥€à¤•à¥à¤¯à¥‚à¤Ÿà¤¿à¤µ à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡ à¤”à¤° à¤²à¤¾à¤‡à¤µ à¤•à¥‡à¤ªà¥€à¤†à¤ˆ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n\n"
                . "à¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡ à¤¸à¥‡ à¤µà¤¾à¤¸à¥à¤¤à¤µà¤¿à¤• à¤¸à¤®à¤¯ à¤•à¤¾ à¤¡à¥‡à¤Ÿà¤¾ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:\n\n"
                . "ðŸ“œ **à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤² à¤‡à¤¨à¥à¤µà¥‡à¤‚à¤Ÿà¤°à¥€:**\n"
                . "  - à¤•à¥à¤² à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤°à¥‹à¤²: **" . number_format($stockCount) . " à¤°à¥‹à¤²** (" . number_format($stockMtr, 2) . " à¤®à¥€à¤Ÿà¤°)\n"
                . "  - à¤•à¤® à¤¸à¥à¤Ÿà¥‰à¤• à¤…à¤²à¤°à¥à¤Ÿ (<500m): **" . number_format($lowStock) . " à¤°à¥‹à¤²**\n\n"
                . "ðŸ­ **à¤‰à¤¤à¥à¤ªà¤¾à¤¦à¤¨ à¤”à¤° à¤²à¤¾à¤‡à¤µ à¤«à¥à¤²à¥‹à¤°:**\n"
                . "  - à¤¸à¤•à¥à¤°à¤¿à¤¯ à¤®à¤¾à¤¸à¥à¤Ÿà¤° à¤œà¥‰à¤¬à¥à¤¸: **" . number_format($jobsActive) . " à¤œà¥‰à¤¬à¥à¤¸**\n"
                . "  - à¤µà¤°à¥à¤¤à¤®à¤¾à¤¨ à¤®à¥‡à¤‚ à¤šà¤² à¤°à¤¹à¥‡ à¤œà¥‰à¤¬à¥à¤¸: **" . number_format($jobsRunning) . " à¤œà¥‰à¤¬à¥à¤¸**\n"
                . "  - à¤ªà¥‡à¤‚à¤¡à¤¿à¤‚à¤— / à¤•à¤¿à¤‰à¤¡ à¤¡à¤¿à¤ªà¤¾à¤°à¥à¤Ÿà¤®à¥‡à¤‚à¤Ÿ à¤œà¥‰à¤¬à¥à¤¸: **" . number_format($jobsPending) . " à¤œà¤¬ à¤•à¤¾à¤°à¥à¤¡**\n"
                . "  - à¤‡à¤¸ à¤®à¤¹à¥€à¤¨à¥‡ à¤ªà¥‚à¤°à¥à¤£ à¤œà¥‰à¤¬à¥à¤¸: **" . number_format($jobsCompletedMonth) . " à¤œà¥‰à¤¬à¥à¤¸**\n\n"
                . "ðŸ’¼ **à¤¬à¤¿à¤•à¥à¤°à¥€ à¤”à¤° à¤…à¤¨à¥à¤®à¤¾à¤¨:**\n"
                . "  - à¤¸à¤•à¥à¤°à¤¿à¤¯ à¤¬à¤¿à¤•à¥à¤°à¥€ à¤•à¥‡ à¤†à¤¦à¥‡à¤¶: **" . number_format($ordersActive) . " à¤‘à¤°à¥à¤¡à¤°**\n"
                . "  - à¤¸à¤•à¥à¤°à¤¿à¤¯ à¤²à¤¾à¤—à¤¤ à¤…à¤¨à¥à¤®à¤¾à¤¨: **" . number_format($estimatesActive) . " à¤…à¤¨à¥à¤®à¤¾à¤¨** (à¤‡à¤¸ à¤®à¤¹à¥€à¤¨à¥‡ à¤•à¤¾ à¤®à¥‚à¤²à¥à¤¯: **â‚¹" . number_format($estimatesVal, 2) . "**)\n\n"
                . "ðŸ‘‰ [à¤à¤•à¥à¤œà¥€à¤•à¥à¤¯à¥‚à¤Ÿà¤¿à¤µ à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/dashboard/index.php)";
        } else {
            $answer = "ðŸ“Š **à¦‡à¦†à¦°à¦ªà¦¿ à¦à¦•à§à¦¸à¦¿à¦•à¦¿à¦‰à¦Ÿà¦¿à¦­ à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡ à¦“ à¦²à¦¾à¦‡à¦­ à¦•à§‡à¦ªà¦¿à¦†à¦‡ à¦“à¦­à¦¾à¦°à¦­à¦¿à¦‰:**\n\n"
                . "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡ à¦¥à§‡à¦•à§‡ à¦°à¦¿à§Ÿà§‡à¦²-à¦Ÿà¦¾à¦‡à¦® à¦°à¦¾à¦¨à¦¿à¦‚ à¦¡à¦¾à¦Ÿà¦¾ à¦¸à¦®à§à¦¬à¦²à¦¿à¦¤ à¦“à¦­à¦¾à¦°à¦­à¦¿à¦‰:\n\n"
                . "ðŸ“œ **à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦² à¦‡à¦¨à¦­à§‡à¦¨à§à¦Ÿà¦°à¦¿:**\n"
                . "  - à¦•à§à¦² à¦‰à¦ªà¦²à¦¬à§à¦§ à¦°à§‹à¦²: **" . number_format($stockCount) . " à¦°à§‹à¦²** (" . number_format($stockMtr, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°)\n"
                . "  - à¦•à¦® à¦¸à§à¦Ÿà¦• à¦…à¦²à¦°à§à¦Ÿ (<à§«à§¦à§¦à¦®à¦¿.): **" . number_format($lowStock) . " à¦°à§‹à¦²**\n\n"
                . "ðŸ­ **à¦‰à¦¤à§à¦ªà¦¾à¦¦à¦¨ à¦“ à¦²à¦¾à¦‡à¦­ à¦«à§à¦²à§‹à¦°:**\n"
                . "  - à¦¸à¦•à§à¦°à¦¿à¦¯à¦¼ à¦®à¦¾à¦¸à§à¦Ÿà¦¾à¦° à¦œà¦¬: **" . number_format($jobsActive) . " à¦œà¦¬**\n"
                . "  - à¦¬à¦°à§à¦¤à¦®à¦¾à¦¨à§‡ à¦šà¦² à¦°à¦¹à§‡ à¦œà¦¬: **" . number_format($jobsRunning) . " à¦œà¦¬**\n"
                . "  - à¦ªà§‡à¦¨à§à¦¡à¦¿à¦‚ / à¦•à¦¿à¦‰à¦¡ à¦¡à¦¿à¦ªà¦¾à¦°à§à¦Ÿà¦®à§‡à¦¨à§à¦Ÿ à¦œà¦¬: **" . number_format($jobsPending) . " à¦œà¦¬ à¦•à¦¾à¦°à§à¦¡**\n"
                . "  - à¦šà¦²à¦¤à¦¿ à¦®à¦¾à¦¸à§‡ à¦¸à¦®à§à¦ªà¦¨à§à¦¨ à¦œà¦¬: **" . number_format($jobsCompletedMonth) . " à¦œà¦¬**\n\n"
                . "ðŸ’¼ **à¦¬à¦¿à¦•à§à¦°à§€ à¦“ à¦…à¦¨à§à¦®à¦¾à¦¨:**\n"
                . "  - à¦¸à¦•à§à¦°à¦¿à¦¯à¦¼ à¦¬à¦¿à¦•à§à¦°à§€ à¦†à¦¦à§‡à¦¶: **" . number_format($ordersActive) . " à¦Ÿà¦¿ à¦…à¦°à§à¦¡à¦¾à¦°**\n"
                . "  - à¦¸à¦•à§à¦°à¦¿à¦¯à¦¼ à¦²à¦¾à¦—à¦¤ à¦…à¦¨à§à¦®à¦¾à¦¨: **" . number_format($estimatesActive) . " à¦Ÿà¦¿ à¦à¦¸à§à¦Ÿà¦¿à¦®à§‡à¦Ÿ** (à¦šà¦²à¦¤à¦¿ à¦®à¦¾à¦¸à§‡à¦° à¦®à§‹à¦Ÿ à¦­à§à¦¯à¦¾à¦²à§: **â‚¹" . number_format($estimatesVal, 2) . "**)\n\n"
                . "ðŸ‘‰ [à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡ à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/dashboard/index.php)";
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
    } elseif (strpos($p, 'dispatch') !== false || strpos($p, 'dispatched') !== false || strpos($p, 'ready queue') !== false || strpos($p, 'ready stock') !== false || strpos($p, 'challan') !== false || strpos($p, 'sales person') !== false || strpos($p, 'à¦¡à¦¿à¦¸à§à¦ªà¥ˆà¦š') !== false || strpos($p, 'à¦°à§‡à¦¡à¦¿') !== false) {
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
            $answer = "ðŸšš **Dispatch & Ready Queue 3-Tab Operational Summary:**\n\n"
                . "ðŸ“¦ **1. Ready Queue (Awaiting Dispatch â€” For Sales & Dispatch Operators):**\n"
                . "  - Ready Finished Stock Items: **" . number_format($totalReadyItems) . " Items / Batches**\n"
                . "  - Total Quantity Available to Ship: **" . number_format($totalReadyQty) . " PCS / Labels**\n\n"
                . "ðŸšš **2. Live Dispatch Operations Summary:**\n"
                . "  - Total Shipment Records: **" . number_format($totalDispatches) . " Dispatches**\n"
                . "  - Total Dispatched Quantity: **" . number_format($totalDispatchedQty) . " PCS**\n"
                . "  - Pending Delivery / In Transit: **" . number_format($pendingTransit) . " Shipments**\n"
                . "  - Successfully Delivered: **" . number_format($deliveredCnt) . " Shipments**\n"
                . "  - Total Transport Logistics Spend: **â‚¹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "ðŸ“‹ **Itemized Ready Stock List (Awaiting Dispatch Handover):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "â€¢ **Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}** | Batch: `" . ($r['batch_no'] ?: 'N/A') . "`)\n"
                    . "  - ðŸ“ **Size:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ“¦ **Ready Stock to Dispatch:** **" . number_format((float) $r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "ðŸ‘‰ [Click here to open Dispatch Workspace]({$baseUrl}/modules/dispatch/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸšš **à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤”à¤° à¤°à¥‡à¤¡à¥€ à¤•à¥à¤¯à¥‚ 3-à¤Ÿà¥ˆà¤¬ à¤¸à¤‚à¤šà¤¾à¤²à¤¨ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n\n"
                . "ðŸ“¦ **1. à¤°à¥‡à¤¡à¥€ à¤•à¥à¤¯à¥‚ (à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤•à¥‡ à¤²à¤¿à¤ à¤¤à¥ˆà¤¯à¤¾à¤° â€” à¤¸à¥‡à¤² à¤Ÿà¥€à¤® à¤•à¥‡ à¤²à¤¿à¤):**\n"
                . "  - à¤°à¥‡à¤¡à¥€ à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤¸à¥à¤Ÿà¥‰à¤• à¤†à¤‡à¤Ÿà¤®: **" . number_format($totalReadyItems) . " à¤†à¤‡à¤Ÿà¤®**\n"
                . "  - à¤¤à¥ˆà¤¯à¤¾à¤° à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤®à¤¾à¤¤à¥à¤°à¤¾: **" . number_format($totalReadyQty) . " à¤ªà¥€à¤¸**\n\n"
                . "ðŸšš **2. à¤²à¤¾à¤‡à¤µ à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤…à¤ªà¥à¤°à¤¿à¤¯à¤¾à¤¨à¥à¤¤à¥à¤°à¤¿à¤• à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n"
                . "  - à¤•à¥à¤² à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤°à¤¿à¤•à¥‰à¤°à¥à¤¡: **" . number_format($totalDispatches) . " à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š**\n"
                . "  - à¤•à¥à¤² à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤šà¤¡ à¤®à¤¾à¤¤à¥à¤°à¤¾: **" . number_format($totalDispatchedQty) . " à¤ªà¥€à¤¸**\n"
                . "  - à¤ªà¥‡à¤‚à¤¡à¤¿à¤‚à¤— à¤¡à¥‡à¤²à¤¿à¤­à¤°à¥€ / à¤‡à¤¨-à¤Ÿà¥à¤°à¤¾à¤‚à¤œà¤¿à¤Ÿ: **" . number_format($pendingTransit) . " à¤¶à¤¿à¤ªà¤®à¥‡à¤‚à¤Ÿ**\n"
                . "  - à¤¸à¤«à¤² à¤¡à¥‡à¤²à¤¿à¤­à¤°à¤¡: **" . number_format($deliveredCnt) . " à¤¶à¤¿à¤ªà¤®à¥‡à¤‚à¤Ÿ**\n"
                . "  - à¤•à¥à¤² à¤Ÿà¥à¤°à¤¾à¤‚à¤¸à¤ªà¥‹à¤°à¥à¤Ÿ à¤²à¤¾à¤—à¤¤: **â‚¹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "ðŸ“‹ **à¤°à¥‡à¤¡à¥€ à¤¸à¥à¤Ÿà¥‰à¤• à¤¸à¥‚à¤šà¥€ (à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤•à¥‡ à¤²à¤¿à¤ à¤¤à¥ˆà¤¯à¤¾à¤°):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "â€¢ **à¤†à¤‡à¤Ÿà¤® " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}**)\n"
                    . "  - ðŸ“ **à¤¸à¤¾à¤‡à¥›:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ“¦ **à¤°à¥‡à¤¡à¥€ à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤¸à¥à¤Ÿà¥‰à¤•:** **" . number_format((float) $r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "ðŸ‘‰ [à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/dispatch/index.php)";
        } else {
            $answer = "ðŸšš **à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦“ à¦°à§‡à¦¡à¦¿ à¦•à¦¿à¦‰ à§©-à¦Ÿà§à¦¯à¦¾à¦¬ à¦…à¦ªà¦¾à¦°à§‡à¦¶à¦¨à¦¾à¦² à¦¸à¦¾à¦®à¦¾à¦°à¦¿:**\n\n"
                . "ðŸ“¦ **à§§. à¦°à§‡à¦¡à¦¿ à¦•à¦¿à¦‰ (à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦šà§‡à¦° à¦œà¦¨à§à¦¯ à¦ªà§à¦°à¦¸à§à¦¤à§à¦¤ à¦¸à§à¦Ÿà¦• - à¦¸à§‡à¦²à¦¸ à¦Ÿà¦¿à¦® à¦“ à¦…à¦ªà¦¾à¦°à§‡à¦Ÿà¦°à¦¦à§‡à¦° à¦œà¦¨à§à¦¯):**\n"
                . "  - à¦°à§‡à¦¡à¦¿ à¦«à¦¿à¦¨à¦¿à¦¶à§à¦¡ à¦¸à§à¦Ÿà¦• à¦†à¦‡à¦Ÿà§‡à¦®: **" . number_format($totalReadyItems) . " à¦†à¦‡à¦Ÿà§‡à¦®**\n"
                . "  - à¦¤à§ˆà¦¯à¦¼à¦¾à¦° à¦‰à¦ªà¦²à¦¬à§à¦§ à¦®à¦¾à¦¤à§à¦°à¦¾: **" . number_format($totalReadyQty) . " à¦ªà¦¿à¦¸**\n\n"
                . "ðŸšš **à§¨. à¦²à¦¾à¦‡à¦­ à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦…à¦ªà¦¾à¦°à§‡à¦¶à¦¨ à¦¸à¦¾à¦®à¦¾à¦°à¦¿:**\n"
                . "  - à¦•à§à¦² à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦°à¦¿à¦•à§‹à¦°à§à¦¡: **" . number_format($totalDispatches) . " à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š**\n"
                . "  - à¦•à§à¦² à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦šà¦¡ à¦®à¦¾à¦¤à§à¦°à¦¾: **" . number_format($totalDispatchedQty) . " à¦ªà¦¿à¦¸**\n"
                . "  - à¦ªà§‡à¦¨à§à¦¡à¦¿à¦‚ à¦¡à§‡à¦²à¦¿à¦­à¦¾à¦°à¦¿ / à¦‡à¦¨-à¦Ÿà§à¦°à¦¾à¦¨à¦œà¦¿à¦Ÿ: **" . number_format($pendingTransit) . " à¦¶à¦¿à¦ªà¦®à§‡à¦¨à§à¦Ÿ**\n"
                . "  - à¦¸à¦«à¦² à¦¡à§‡à¦²à¦¿à¦­à¦¾à¦°à¦¡: **" . number_format($deliveredCnt) . " à¦¶à¦¿à¦ªà¦®à§‡à¦¨à§à¦Ÿ**\n"
                . "  - à¦•à§à¦² à¦Ÿà§à¦°à¦¾à¦¨à§à¦¸à¦ªà§‹à¦°à§à¦Ÿ à¦²à¦¾à¦—à¦¤: **â‚¹" . number_format($totalTransportCost, 2) . "**\n\n"
                . "ðŸ“‹ **à¦°à§‡à¦¡à¦¿ à¦¸à§à¦Ÿà¦• à¦†à¦‡à¦Ÿà§‡à¦® à¦¤à¦¾à¦²à¦¿à¦•à¦¾ (à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦šà§‡à¦° à¦œà¦¨à§à¦¯ à¦ªà§à¦°à¦¸à§à¦¤à§à¦¤):**\n\n";

            foreach ($readyRows as $idx => $r) {
                $answer .= "â€¢ **à¦†à¦‡à¦Ÿà§‡à¦® " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Ready Product') . "`** (ID: **{$r['id']}** | à¦¬à§à¦¯à¦¾à¦š: `" . ($r['batch_no'] ?: 'N/A') . "`)\n"
                    . "  - ðŸ“ **à¦¸à¦¾à¦‡à¦œ:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ“¦ **à¦°à§‡à¦¡à¦¿ à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦¸à§à¦Ÿà¦•:** **" . number_format((float) $r['ready_qty']) . " " . ($r['unit'] ?: 'PCS') . "**\n\n";
            }

            $answer .= "ðŸ‘‰ [à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦š à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/dispatch/index.php)";
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
    } elseif (strpos($p, 'finished') !== false || strpos($p, 'fg stock') !== false || strpos($p, 'fg') !== false || strpos($p, 'packed label') !== false || strpos($p, 'packed stock') !== false || strpos($p, 'à¦«à¦¿à¦¨à¦¿à¦¶à¦¡') !== false || strpos($p, 'à¦ªà§à¦¯à¦¾à¦•à¦¡') !== false) {

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
            $answer = "ðŸ“¦ **Finished Goods & Packed Label Stock â€” Live Inventory Summary:**\n\n"
                . "ðŸ“Š **Inventory Summary Metrics:**\n"
                . "  - Total Finished Products/Batches: **" . number_format($totalItems) . " Items**\n"
                . "  - Total Quantity Packed: **" . number_format($totalQty) . " PCS / Labels**\n"
                . "  - Total Dispatched Quantity: **" . number_format($totalDispatch) . " PCS**\n"
                . "  - Total Available Closing Stock: **" . number_format($totalClosing) . " PCS**\n\n"
                . "ðŸ“¦ **Master Finished Stock Grid:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "â€¢ **Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | Category: **" . strtoupper($r['category']) . "**)\n"
                    . "  - ðŸ“ **Size & Spec:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ”¢ **Packed Quantity:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - ðŸšš **Dispatched:** **" . number_format((float) $r['dispatch_qty_total']) . " PCS** | Available Closing: **" . number_format((float) $r['available_closing']) . " PCS**\n"
                    . "  - ðŸ·ï¸ **Batch / Job No:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [Click here to open Finished Goods Page]({$baseUrl}/modules/inventory/finished/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“¦ **à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤—à¥à¤¡à¥à¤¸ à¤”à¤° à¤ªà¥ˆà¤•à¥à¤¡ à¤²à¥‡à¤¬à¤² à¤¸à¥à¤Ÿà¥‰à¤• â€” à¤²à¤¾à¤‡à¤µ à¤‡à¤¨à¥à¤µà¥‡à¤‚à¤Ÿà¤°à¥€ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n\n"
                . "ðŸ“Š **à¤‡à¤¨à¥à¤µà¥‡à¤‚à¤Ÿà¤°à¥€ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n"
                . "  - à¤•à¥à¤² à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤‰à¤¤à¥à¤ªà¤¾à¤¦ / à¤¬à¥ˆà¤š: **" . number_format($totalItems) . " à¤†à¤‡à¤Ÿà¤®**\n"
                . "  - à¤•à¥à¤² à¤ªà¥ˆà¤•à¥à¤¡ à¤®à¤¾à¤¤à¥à¤°à¤¾: **" . number_format($totalQty) . " à¤ªà¥€à¤¸**\n"
                . "  - à¤•à¥à¤² à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤š à¤®à¤¾à¤¤à¥à¤°à¤¾: **" . number_format($totalDispatch) . " à¤ªà¥€à¤¸**\n"
                . "  - à¤•à¥à¤² à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤•à¥à¤²à¥‹à¤œà¤¿à¤‚à¤— à¤¸à¥à¤Ÿà¥‰à¤•: **" . number_format($totalClosing) . " à¤ªà¥€à¤¸**\n\n"
                . "ðŸ“¦ **à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤¸à¥à¤Ÿà¥‰à¤• à¤—à¥à¤°à¤¿à¤¡:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "â€¢ **à¤†à¤‡à¤Ÿà¤® " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}**)\n"
                    . "  - ðŸ“ **à¤¸à¤¾à¤‡à¥›:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ”¢ **à¤ªà¥ˆà¤•à¥à¤¡ à¤®à¤¾à¤¤à¥à¤°à¤¾:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - ðŸšš **à¤¡à¤¿à¤¸à¥à¤ªà¥ˆà¤šà¤¡:** **" . number_format((float) $r['dispatch_qty_total']) . " PCS** | à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤•à¥à¤²à¥‹à¤œà¤¿à¤‚à¤— à¤¸à¥à¤Ÿà¥‰à¤•: **" . number_format((float) $r['available_closing']) . " PCS**\n"
                    . "  - ðŸ·ï¸ **à¤¬à¥ˆà¤š / à¤œà¤¬ à¤¨à¤‚à¤¬à¤°:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤—à¥à¤¡à¥à¤¸ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/inventory/finished/index.php)";
        } else {
            $answer = "ðŸ“¦ **à¦«à¦¿à¦¨à¦¿à¦¶à¦¡ à¦—à§à¦¡à¦¸ à¦“ à¦ªà§à¦¯à¦¾à¦•à¦¡ à¦²à§‡à¦¬à§‡à¦² à¦¸à§à¦Ÿà¦• â€” à¦²à¦¾à¦‡à¦­ à¦‡à¦¨à¦­à§‡à¦¨à§à¦Ÿà¦°à¦¿ à¦¸à¦¾à¦®à¦¾à¦°à¦¿:**\n\n"
                . "ðŸ“Š **à¦‡à¦¨à¦­à§‡à¦¨à§à¦Ÿà¦°à¦¿ à¦¸à¦¾à¦®à¦¾à¦°à¦¿ à¦®à§à¦¯à¦¾à¦Ÿà§à¦°à¦¿à¦•à§à¦¸:**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦«à¦¿à¦¨à¦¿à¦¶à¦¡ à¦ªà§à¦°à§‹à¦¡à¦¾à¦•à§à¦Ÿ/à¦¬à§à¦¯à¦¾à¦š: **" . number_format($totalItems) . "à¦Ÿà¦¿ à¦†à¦‡à¦Ÿà§‡à¦®**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦ªà§à¦¯à¦¾à¦•à¦¡ à¦•à§‹à¦¯à¦¼à¦¾à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿: **" . number_format($totalQty) . "à¦Ÿà¦¿ à¦ªà¦¿à¦¸/à¦²à§‡à¦¬à§‡à¦²**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦šà¦¡ à¦•à§‹à¦¯à¦¼à¦¾à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿: **" . number_format($totalDispatch) . "à¦Ÿà¦¿ à¦ªà¦¿à¦¸**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦‰à¦ªà¦²à¦¬à§à¦§ à¦•à§à¦²à§‹à¦œà¦¿à¦‚ à¦¸à§à¦Ÿà¦•: **" . number_format($totalClosing) . "à¦Ÿà¦¿ à¦ªà¦¿à¦¸**\n\n"
                . "ðŸ“¦ **à¦®à¦¾à¦¸à§à¦Ÿà¦¾à¦° à¦«à¦¿à¦¨à¦¿à¦¶à¦¡ à¦¸à§à¦Ÿà¦• à¦—à§à¦°à¦¿à¦¡:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "â€¢ **à¦†à¦‡à¦Ÿà§‡à¦® " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Finished Product') . "`** (ID: **{$r['id']}** | à¦•à§à¦¯à¦¾à¦Ÿà¦¾à¦—à¦°à¦¿: **" . strtoupper($r['category']) . "**)\n"
                    . "  - ðŸ“ **à¦¸à¦¾à¦‡à¦œ à¦“ à¦¸à§à¦ªà§‡à¦•:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ”¢ **à¦ªà§à¦¯à¦¾à¦•à¦¡ à¦•à§‹à§Ÿà¦¾à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - ðŸšš **à¦¡à¦¿à¦¸à§à¦ªà§ˆà¦šà¦¡:** **" . number_format((float) $r['dispatch_qty_total']) . "à¦Ÿà¦¿ à¦ªà¦¿à¦¸** | à¦‰à¦ªà¦²à¦¬à§à¦§ à¦•à§à¦²à§‹à¦œà¦¿à¦‚ à¦¸à§à¦Ÿà¦•: **" . number_format((float) $r['available_closing']) . "à¦Ÿà¦¿ à¦ªà¦¿à¦¸**\n"
                    . "  - ðŸ·ï¸ **à¦¬à§à¦¯à¦¾à¦š / à¦œà¦¬ à¦¨à¦®à§à¦¬à¦°:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [à¦«à¦¿à¦¨à¦¿à¦¶à¦¡ à¦—à§à¦¡à¦¸ à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/inventory/finished/index.php)";
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
    } elseif (strpos($p, 'login') !== false || strpos($p, 'logged') !== false || strpos($p, 'who am i') !== false || strpos($p, 'user id') !== false || strpos($p, 'active user') !== false || strpos($p, 'à¦²à¦—à¦‡à¦¨') !== false || strpos($p, 'à¦‡à¦‰à¦œà¦¾à¦°') !== false) {
        $toolName = 'User & Session Master Tool';

        $sessionUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;

        $currRes = $db->query("SELECT id, name, email, role, is_active, updated_at FROM users WHERE id = {$sessionUserId}");
        $currUser = $currRes ? $currRes->fetch_assoc() : null;

        $allRes = $db->query("SELECT id, name, email, role, is_active, updated_at FROM users WHERE is_active = 1 ORDER BY id ASC");
        $allUsers = $allRes ? $allRes->fetch_all(MYSQLI_ASSOC) : [];


        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

        if ($userLang === 'English') {
            $answer = "ðŸ‘¤ **System User & Active Session Overview:**\n\n";

            if ($currUser) {
                $answer .= "ðŸ”‘ **Currently Active Logged In Session:**\n"
                    . "  - ðŸ†” **User ID:** **`#" . $currUser['id'] . "`**\n"
                    . "  - ðŸ‘¤ **Name:** **" . $currUser['name'] . "**\n"
                    . "  - ðŸ›¡ï¸ **Role:** **" . strtoupper($currUser['role']) . "**\n"
                    . "  - ðŸ“§ **Email:** `" . $currUser['email'] . "`\n"
                    . "  - â° **Last Session Activity:** `" . $currUser['updated_at'] . "`\n\n";
            }

            $answer .= "ðŸ‘¥ **Registered Active System Users (" . count($allUsers) . " Users):**\n\n";

            foreach ($allUsers as $u) {
                $timeStr = date('d M Y, h:i A', strtotime($u['updated_at']));
                $answer .= "â€¢ **User ID `#" . $u['id'] . "`: " . $u['name'] . "** (Role: `" . strtoupper($u['role']) . "`)\n"
                    . "  - â° **Last Activity / Login:** `" . $timeStr . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [Click here to open User Management Page]({$baseUrl}/modules/hr_management/users/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ‘¤ **à¤¸à¤¿à¤¸à¥à¤Ÿà¤® à¤‰à¤ªà¤¯à¥‹à¤—à¤•à¤°à¥à¤¤à¤¾ à¤”à¤° à¤¸à¤•à¥à¤°à¤¿à¤¯ à¤¸à¤¤à¥à¤° à¤µà¤¿à¤µà¤°à¤£:**\n\n";

            if ($currUser) {
                $answer .= "ðŸ”‘ **à¤µà¤°à¥à¤¤à¤®à¤¾à¤¨ à¤²à¥‰à¤—-à¤‡à¤¨ à¤¸à¤¤à¥à¤°:**\n"
                    . "  - ðŸ†” **à¤‰à¤ªà¤¯à¥‹à¤—à¤•à¤°à¥à¤¤à¤¾ à¤†à¤ˆà¤¡à¥€ (User ID):** **`#" . $currUser['id'] . "`**\n"
                    . "  - ðŸ‘¤ **à¤¨à¤¾à¤®:** **" . $currUser['name'] . "**\n"
                    . "  - ðŸ›¡ï¸ **à¤°à¥‹à¤²:** **" . strtoupper($currUser['role']) . "**\n"
                    . "  - ðŸ“§ **à¤ˆà¤®à¥‡à¤²:** `" . $currUser['email'] . "`\n"
                    . "  - â° **à¤…à¤‚à¤¤à¤¿à¤® à¤—à¤¤à¤¿à¤µà¤¿à¤§à¤¿ à¤¸à¤®à¤¯:** `" . $currUser['updated_at'] . "`\n\n";
            }

            $answer .= "ðŸ‘¥ **à¤ªà¤‚à¤œà¥€à¤•à¥ƒà¤¤ à¤¸à¤•à¥à¤°à¤¿à¤¯ à¤‰à¤ªà¤¯à¥‹à¤—à¤•à¤°à¥à¤¤à¤¾ à¤¸à¥‚à¤šà¥€ (" . count($allUsers) . " à¤‰à¤ªà¤¯à¥‹à¤—à¤•à¤°à¥à¤¤à¤¾):**\n\n";

            foreach ($allUsers as $u) {
                $answer .= "â€¢ **à¤¯à¥‚à¤œà¤° à¤†à¤ˆà¤¡à¥€ `#" . $u['id'] . "`: " . $u['name'] . "** (à¤°à¥‹à¤²: `" . strtoupper($u['role']) . "`)\n"
                    . "  - â° **à¤…à¤‚à¤¤à¤¿à¤® à¤—à¤¤à¤¿à¤µà¤¿à¤§à¤¿:** `" . $u['updated_at'] . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [à¤‰à¤ªà¤¯à¥‹à¤—à¤•à¤°à¥à¤¤à¤¾ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/hr_management/users/index.php)";
        } else {
            $answer = "ðŸ‘¤ **à¦¸à¦¿à¦¸à§à¦Ÿà§‡à¦® à¦‡à¦‰à¦œà¦¾à¦° à¦“ à¦…à§à¦¯à¦¾à¦•à§à¦Ÿà¦¿à¦­ à¦²à¦—à¦‡à¦¨ à¦¸à§‡à¦¶à¦¨ à¦¸à¦¾à¦®à¦¾à¦°à¦¿:**\n\n";

            if ($currUser) {
                $answer .= "ðŸ”‘ **à¦¬à¦°à§à¦¤à¦®à¦¾à¦¨à§‡ à¦¸à¦•à§à¦°à¦¿à¦¯à¦¼ à¦²à¦—à¦‡à¦¨ à¦¸à§‡à¦¶à¦¨ à¦‡à¦‰à¦œà¦¾à¦°:**\n"
                    . "  - ðŸ†” **à¦‡à¦‰à¦œà¦¾à¦° à¦†à¦‡à¦¡à§‡à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿ (User ID):** **`#" . $currUser['id'] . "`**\n"
                    . "  - ðŸ‘¤ **à¦¨à¦¾à¦® (Name):** **" . $currUser['name'] . "**\n"
                    . "  - ðŸ›¡ï¸ **à¦°à§‹à¦² (Role):** **" . strtoupper($currUser['role']) . "**\n"
                    . "  - ðŸ“§ **à¦‡à¦®à§‡à¦‡à¦² (Email):** `" . $currUser['email'] . "`\n"
                    . "  - â° **à¦¸à¦°à§à¦¬à¦¶à§‡à¦· à¦…à§à¦¯à¦¾à¦•à§à¦Ÿà¦¿à¦­à¦¿à¦Ÿà¦¿ / à¦²à¦—à¦‡à¦¨ à¦¸à¦®à§Ÿ:** `" . $currUser['updated_at'] . "`\n\n";
            }

            $answer .= "ðŸ‘¥ **à¦¸à¦¿à¦¸à§à¦Ÿà§‡à¦®à§‡ à¦¨à¦¿à¦¬à¦¨à§à¦§à¦¿à¦¤ à¦…à§à¦¯à¦¾à¦•à§à¦Ÿà¦¿à¦­ à¦‡à¦‰à¦œà¦¾à¦°à¦¦à§‡à¦° à¦¤à¦¾à¦²à¦¿à¦•à¦¾ (" . count($allUsers) . "à¦œà¦¨ à¦‡à¦‰à¦œà¦¾à¦°):**\n\n";

            foreach ($allUsers as $u) {
                $timeStr = date('d M Y, h:i A', strtotime($u['updated_at']));
                $answer .= "â€¢ **à¦‡à¦‰à¦œà¦¾à¦° à¦†à¦‡à¦¡à¦¿ `#" . $u['id'] . "`: " . $u['name'] . "** (à¦°à§‹à¦²: `" . strtoupper($u['role']) . "`)\n"
                    . "  - â° **à¦¸à¦°à§à¦¬à¦¶à§‡à¦· à¦…à§à¦¯à¦¾à¦•à§à¦Ÿà¦¿à¦­à¦¿à¦Ÿà¦¿ / à¦²à¦—à¦‡à¦¨:** `" . $timeStr . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [à¦‡à¦‰à¦œà¦¾à¦° à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/hr_management/users/index.php)";
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
    } elseif (strpos($p, 'mixed') !== false || strpos($p, 'extra pool') !== false || strpos($p, 'repack') !== false || strpos($p, 'à¦®à¦¿à¦•à§à¦¸à¦¡') !== false || strpos($p, 'à¦à¦•à§à¦¸à¦Ÿà§à¦°à¦¾') !== false) {

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
            $answer = "ðŸ”€ **Mixed Item & Extra Production Pool â€” Live Inventory Summary:**\n\n"
                . "ðŸ“Š **Pool Metrics Summary:**\n"
                . "  - Total Extra Pool Batches: **" . number_format($totalItems) . " Items**\n"
                . "  - Total Extra Stock Quantity: **" . number_format($totalExtraQty) . " PCS / Rolls**\n"
                . "  - Pending Handover Assignments: **" . number_format($pendingAssign) . " Assignments** (Target: Packing / Planning / Repack)\n\n"
                . "ðŸ”€ **Active Mixed Extra Items Grid:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "â€¢ **Extra Item " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}** | Category: **" . strtoupper($r['category']) . "**)\n"
                    . "  - ðŸ“ **Size & Spec:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ”¢ **Extra Quantity:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - ðŸ·ï¸ **Batch / Job No:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [Click here to open Mixed Item Inventory Page]({$baseUrl}/modules/inventory/mixed-item/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ”€ **à¤®à¤¿à¤•à¥à¤¸à¤¡ à¤†à¤‡à¤Ÿà¤® à¤”à¤° à¤à¤•à¥à¤¸à¥à¤Ÿà¥à¤°à¤¾ à¤ªà¥à¤°à¥‹à¤¡à¤•à¥à¤¶à¤¨ à¤ªà¥‚à¤² â€” à¤²à¤¾à¤‡à¤µ à¤‡à¤¨à¥à¤µà¥‡à¤‚à¤Ÿà¤°à¥€ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n\n"
                . "ðŸ“Š **à¤ªà¥‚à¤² à¤®à¥‡à¤Ÿà¥à¤°à¤¿à¤•à¥à¤¸ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n"
                . "  - à¤•à¥à¤² à¤à¤•à¥à¤¸à¥à¤Ÿà¥à¤°à¤¾ à¤ªà¥‚à¤² à¤¬à¥ˆà¤š: **" . number_format($totalItems) . " à¤†à¤‡à¤Ÿà¤®**\n"
                . "  - à¤•à¥à¤² à¤à¤•à¥à¤¸à¥à¤Ÿà¥à¤°à¤¾ à¤¸à¥à¤Ÿà¥‰à¤• à¤®à¤¾à¤¤à¥à¤°à¤¾: **" . number_format($totalExtraQty) . " à¤ªà¥€à¤¸**\n"
                . "  - à¤ªà¥‡à¤‚à¤¡à¤¿à¤‚à¤— à¤¹à¥ˆà¤‚à¤¡à¤“à¤µà¤° à¤…à¤¸à¤¾à¤‡à¤¨à¤®à¥‡à¤‚à¤Ÿ: **" . number_format($pendingAssign) . " à¤…à¤¸à¤¾à¤‡à¤¨à¤®à¥‡à¤‚à¤Ÿ**\n\n"
                . "ðŸ”€ **à¤à¤•à¥à¤Ÿà¤¿à¤µ à¤®à¤¿à¤•à¥à¤¸à¤¡ à¤†à¤‡à¤Ÿà¤® à¤—à¥à¤°à¤¿à¤¡:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "â€¢ **à¤†à¤‡à¤Ÿà¤® " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}**)\n"
                    . "  - ðŸ“ **à¤¸à¤¾à¤‡à¥›:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ”¢ **à¤à¤•à¥à¤¸à¥à¤Ÿà¥à¤°à¤¾ à¤®à¤¾à¤¤à¥à¤°à¤¾:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - ðŸ·ï¸ **à¤¬à¥ˆà¤š à¤¨à¤‚à¤¬à¤°:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [à¤®à¤¿à¤•à¥à¤¸à¤¡ à¤†à¤‡à¤Ÿà¤® à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/inventory/mixed-item/index.php)";
        } else {
            $answer = "ðŸ”€ **à¦®à¦¿à¦•à§à¦¸à¦¡ à¦†à¦‡à¦Ÿà§‡à¦® à¦“ à¦à¦•à§à¦¸à§à¦Ÿà§à¦°à¦¾ à¦ªà§à¦°à§‹à¦¡à¦¾à¦•à¦¶à¦¨ à¦ªà§à¦² â€” à¦²à¦¾à¦‡à¦­ à¦‡à¦¨à¦­à§‡à¦¨à§à¦Ÿà¦°à¦¿ à¦¸à¦¾à¦®à¦¾à¦°à¦¿:**\n\n"
                . "ðŸ“Š **à¦ªà§‚à¦² à¦®à§à¦¯à¦¾à¦Ÿà§à¦°à¦¿à¦•à§à¦¸ à¦¸à¦¾à¦°à¦¾à¦‚à¦¶:**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦à¦•à§à¦¸à§à¦Ÿà§à¦°à¦¾ à¦ªà§à¦² à¦¬à§à¦¯à¦¾à¦š: **" . number_format($totalItems) . "à¦Ÿà¦¿ à¦†à¦‡à¦Ÿà§‡à¦®**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦à¦•à§à¦¸à§à¦Ÿà§à¦°à¦¾ à¦¸à§à¦Ÿà¦• à¦•à§‹à¦¯à¦¼à¦¾à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿: **" . number_format($totalExtraQty) . "à¦Ÿà¦¿ à¦ªà¦¿à¦¸/à¦²à§‡à¦¬à§‡à¦²**\n"
                . "  - à¦ªà§‡à¦¨à§à¦¡à¦¿à¦‚ à¦¹à§ˆà¦¨à§à¦¡à¦“à¦­à¦¾à¦° à¦…à¦¸à¦¾à¦‡à¦¨à¦®à§‡à¦¨à§à¦Ÿ: **" . number_format($pendingAssign) . "à¦Ÿà¦¿ à¦…à¦¸à¦¾à¦‡à¦¨à¦®à§‡à¦¨à§à¦Ÿ**\n\n"
                . "ðŸ”€ **à¦…à§à¦¯à¦¾à¦•à§à¦Ÿà¦¿à¦­ à¦®à¦¿à¦•à§à¦¸à¦¡ à¦à¦•à§à¦¸à§à¦Ÿà§à¦°à¦¾ à¦†à¦‡à¦Ÿà§‡à¦® à¦—à§à¦°à¦¿à¦¡:**\n\n";

            foreach ($rows as $idx => $r) {
                $answer .= "â€¢ **à¦à¦•à§à¦¸à¦Ÿà§à¦°à¦¾ à¦†à¦‡à¦Ÿà§‡à¦® " . ($idx + 1) . ": `" . ($r['item_name'] ?: 'Mixed Item') . "`** (ID: **{$r['id']}** | à¦•à§à¦¯à¦¾à¦Ÿà¦¾à¦—à¦°à¦¿: **" . strtoupper($r['category']) . "**)\n"
                    . "  - ðŸ“ **à¦¸à¦¾à¦‡à¦œ à¦“ à¦¸à§à¦ªà§‡à¦•:** **" . ($r['size'] ?: 'N/A') . "**\n"
                    . "  - ðŸ”¢ **à¦à¦•à§à¦¸à§à¦Ÿà§à¦°à¦¾ à¦•à§‹à¦¯à¦¼à¦¾à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿:** **" . number_format((float) $r['quantity']) . " " . ($r['unit'] ?: 'PCS') . "**\n"
                    . "  - ðŸ·ï¸ **à¦¬à§à¦¯à¦¾à¦š / à¦œà¦¬ à¦¨à¦®à§à¦¬à¦°:** `" . ($r['batch_no'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [à¦®à¦¿à¦•à§à¦¸à¦¡ à¦†à¦‡à¦Ÿà§‡à¦® à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/inventory/mixed-item/index.php)";
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

    if (strpos($p, 'live') !== false || strpos($p, 'life') !== false || strpos($p, 'floor') !== false || strpos($p, 'stage') !== false || strpos($p, 'next department') !== false || strpos($p, 'journey') !== false || strpos($p, 'current job') !== false || strpos($p, 'running job') !== false || strpos($p, 'à¦²à¦¾à¦‡à¦­') !== false || strpos($p, 'à¦«à§à¦²à§‹à¦°') !== false) {
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
            $answer = "ðŸ­ **Live Production Floor â€” Job Journey & Multi-Department Pipeline Summary:**\n\n"
                . "Found **{$totalCount} Master Jobs** moving across production departments:\n\n";

            foreach ($grouped as $job) {
                $answer .= "ðŸ“‹ **Master Job: `{$job['planning_no']}`** | **{$job['job_name']}** (Priority: `{$job['priority']}`)\n";

                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - â³ **Current Stage:** `Planning Stage` (Queued for departmental assignment)\n"
                        . "  - â© **Next Pipeline:** Jumbo Slitting âž” Flexo Printing âž” Label Slitting âž” Packing âž” Finished Goods\n"
                        . "  - ðŸ“Š **Remaining Departments to Cross:** `4 Departments left`\n\n";
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
                        $current = "âš¡ **CURRENT DEPARTMENT:** `{$deptName}` (`{$d['job_no']}`) | Status: **{$d['status']}** (Entry Time: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}`";
                    }
                }

                $upcoming[] = "`à¤ªà¥ˆà¤•à¤¿à¤‚à¤—`";
                $upcoming[] = "`à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤—à¥à¤¡à¥à¤¸ à¤¸à¥à¤Ÿà¥‰à¤•`";

                $answer .= "  - " . ($current ?: "âš¡ **CURRENT DEPARTMENT:** `Department Assignment in Progress`") . "\n";
                $answer .= "  - â© **Next Pipeline:** " . implode(' âž” ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - ðŸ“Š **Remaining Steps to Finished Production:** **`{$remCount} Departments / Stages left`**\n\n";
            }

            $answer .= "ðŸ‘‰ [Click here to open full Live Production Floor page]({$baseUrl}/modules/live/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ­ **à¤²à¤¾à¤‡à¤µ à¤ªà¥à¤°à¥‹à¤¡à¤•à¥à¤¶à¤¨ à¤«à¥à¤²à¥‹à¤° â€” à¤œà¥‰à¤¬ à¤œà¤°à¥à¤¨à¥€ à¤“ à¤µà¤¿à¤­à¤¾à¤—à¥€à¤¯ à¤¸à¥à¤¥à¤¿à¤¤à¤¿ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n\n"
                . "à¤•à¥à¤² **{$totalCount} à¤®à¤¾à¤¸à¥à¤Ÿà¤° à¤œà¥‰à¤¬à¥à¤¸** à¤µà¤¿à¤­à¤¿à¤¨à¥à¤¨ à¤µà¤¿à¤­à¤¾à¤—à¥‹à¤‚ à¤¸à¥‡ à¤¹à¥‹à¤•à¤° à¤—à¥à¤œà¤° à¤°à¤¹à¥‡ à¤¹à¥ˆà¤‚:\n\n";

            foreach ($grouped as $job) {
                $answer .= "ðŸ“‹ **à¤®à¤¾à¤¸à¥à¤Ÿà¤° à¤œà¥‰à¤¬: `{$job['planning_no']}`** | **{$job['job_name']}** (à¤ªà¥à¤°à¤¾à¤¥à¤®à¤¿à¤•à¤¤à¤¾: `{$job['priority']}`)\n";

                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - â³ **à¤µà¤°à¥à¤¤à¤®à¤¾à¤¨ à¤šà¤°à¤£:** `à¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤— à¤¸à¥à¤Ÿà¥‡à¤œ`\n"
                        . "  - ðŸ“Š **à¤¶à¥‡à¤· à¤µà¤¿à¤­à¤¾à¤— (Remaining):** `4 à¤µà¤¿à¤­à¤¾à¤— à¤¬à¤¾à¤•à¥€ à¤¹à¥ˆà¤‚` (à¤œà¤‚à¤¬à¥‹ âž” à¤ªà¥à¤°à¤¿à¤‚à¤Ÿà¤¿à¤‚à¤— âž” à¤¸à¥à¤²à¤¿à¤Ÿà¤¿à¤‚à¤— âž” à¤ªà¥ˆà¤•à¤¿à¤‚à¤— âž” à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡)\n"
                        . "  - ðŸ“Š **à¤¬à¤¾à¤•à¥€ à¤¡à¤¿à¤ªà¤¾à¤°à¥à¤Ÿà¤®à¥‡à¤‚à¤Ÿ:** `4 à¤µà¤¿à¤­à¤¾à¤— à¤¬à¤¾à¤•à¥€ à¤¹à¥ˆà¤‚`\n\n";
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
                        $current = "âš¡ **à¤µà¤°à¥à¤¤à¤®à¤¾à¤¨ à¤µà¤¿à¤­à¤¾à¤—:** `{$deptName}` (`{$d['job_no']}`) | à¤¸à¥à¤¥à¤¿à¤¤à¤¿: **{$d['status']}** (à¤à¤¨à¥à¤Ÿà¥à¤°à¥€ à¤¸à¤®à¤¯: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}`";
                    }
                }

                $upcoming[] = "`à¤ªà¥ˆà¤•à¤¿à¤‚à¤—`";
                $upcoming[] = "`à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤—à¥à¤¡à¥à¤¸ à¤¸à¥à¤Ÿà¥‰à¤•`";

                $answer .= "  - " . ($current ?: "âš¡ **à¤µà¤°à¥à¤¤à¤®à¤¾à¤¨ à¤µà¤¿à¤­à¤¾à¤—:** `Department Assignment in Progress`") . "\n";
                $answer .= "  - â© **Next Pipeline:** " . implode(' âž” ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - ðŸ“Š **à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤ªà¥à¤°à¥‹à¤¡à¤•à¥à¤¶à¤¨ à¤¤à¤• à¤¶à¥‡à¤· à¤¡à¤¿à¤ªà¤¾à¤°à¥à¤Ÿà¤®à¥‡à¤‚à¤Ÿ:** **`{$remCount} à¤µà¤¿à¤­à¤¾à¤— à¤¬à¤¾à¤•à¥€ à¤¹à¥ˆà¤‚`**\n\n";
            }

            $answer .= "ðŸ‘‰ [à¤²à¤¾à¤‡à¤µ à¤ªà¥à¤°à¥‹à¤¡à¤•à¥à¤¶à¤¨ à¤«à¥à¤²à¥‹à¤° à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/live/index.php)";
        } else {
            $answer = "ðŸ­ **Live Production Floor â€” Job Journey & Multi-Department Pipeline Summary:**\n\n"
                . "Found **{$totalCount} Master Jobs** moving across production departments:\n\n";

            foreach ($grouped as $job) {
                $answer .= "ðŸ“‹ **Master Job: `{$job['planning_no']}`** | **{$job['job_name']}** (Priority: `{$job['priority']}`)\n";

                $depts = $job['departments'];
                if (empty($depts)) {
                    $answer .= "  - â³ **Current Stage:** `Planning Stage` (Queued for departmental assignment)\n"
                        . "  - â© **Next Pipeline:** Jumbo Slitting âž” Flexo Printing âž” Label Slitting âž” Packing âž” Finished Goods\n"
                        . "  - ðŸ“Š **Remaining Departments to Cross:** `4 Departments left`\n\n";
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
                        $current = "âš¡ **CURRENT DEPARTMENT:** `{$deptName}` (`{$d['job_no']}`) | Status: **{$d['status']}** (Entry Time: `{$timeStr}`)";
                    } else {
                        $upcoming[] = "`{$deptName}`";
                    }
                }

                $upcoming[] = "`à¤ªà¥ˆà¤•à¤¿à¤‚à¤—`";
                $upcoming[] = "`à¤«à¤¿à¤¨à¤¿à¤¶à¥à¤¡ à¤—à¥à¤¡à¥à¤¸ à¤¸à¥à¤Ÿà¥‰à¤•`";

                $answer .= "  - " . ($current ?: "âš¡ **CURRENT DEPARTMENT:** `Department Assignment in Progress`") . "\n";
                $answer .= "  - â© **Next Pipeline:** " . implode(' âž” ', array_slice($upcoming, 0, 3)) . "\n";
                $remCount = count($upcoming);
                $answer .= "  - ðŸ“Š **Remaining Steps to Finished Production:** **`{$remCount} Departments / Stages left`**\n\n";
            }

            $answer .= "ðŸ‘‰ [Click here to open full Live Production Floor page]({$baseUrl}/modules/live/index.php)";
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
    } elseif (strpos($p, 'plate') !== false || strpos($p, 'repeat') !== false || strpos($p, 'gap') !== false || strpos($p, 'à¦ªà§à¦²à§‡à¦Ÿ') !== false || strpos($p, 'à¤ªà¥à¤²à¥‡à¤Ÿ') !== false || ((strpos($p, 'print') !== false || strpos($p, 'calculat') !== false || strpos($p, 'koto') !== false || strpos($p, 'à¦•à¦¤') !== false) && (strpos($p, 'meter') !== false || strpos($p, 'mtr') !== false || strpos($p, 'qty') !== false || strpos($p, 'quantity') !== false || strpos($p, 'qnty') !== false || strpos($p, 'pcs') !== false || preg_match('/\b(run|paper)\b/', $p)))) {

        $toolName = 'Printing Plates Master Tool';
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
        $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;

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
                $directAnswer = "ðŸ“Š **à¤ªà¥à¤°à¤¿à¤‚à¤Ÿà¤¿à¤‚à¤— à¤ªà¥à¤²à¥‡à¤Ÿ â€” à¤¸à¥à¤Ÿà¥‰à¤• à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **à¤•à¥à¤² à¤ªà¥à¤²à¥‡à¤Ÿ:** **{$totalCount}**\n\n";
                if (!empty($dieStats)) {
                    $directAnswer .= "ðŸ“ **à¤ªà¥à¤°à¤•à¤¾à¤° à¤•à¥‡ à¤…à¤¨à¥à¤¸à¤¾à¤° (Die Type):**\n";
                    foreach ($dieStats as $ds) {
                        $directAnswer .= "   â–¸ **" . ($ds['die'] ?: 'à¤…à¤¨à¥à¤¯') . "** â€” {$ds['cnt']} à¤ªà¥à¤²à¥‡à¤Ÿ\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($makerStats)) {
                    $directAnswer .= "ðŸ­ **à¤¨à¤¿à¤°à¥à¤®à¤¾à¤¤à¤¾ / à¤¸à¤ªà¥à¤²à¤¾à¤¯à¤°:**\n";
                    foreach ($makerStats as $ms) {
                        $directAnswer .= "   â–¸ **{$ms['make_by']}** â€” {$ms['cnt']} à¤ªà¥à¤²à¥‡à¤Ÿ\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "ðŸ†• **à¤¸à¤¬à¤¸à¥‡ à¤¹à¤¾à¤²à¤¿à¤¯à¤¾ à¤ªà¥à¤²à¥‡à¤Ÿ:** `{$latest['name']}` (SL: {$latest['sl_no']})";
                    if (!empty($latest['date_received']) && $latest['date_received'] !== 'NA') {
                        $directAnswer .= " â€” à¤ªà¥à¤°à¤¾à¤ªà¥à¤¤: {$latest['date_received']}";
                    }
                    $directAnswer .= "\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [à¤ªà¥à¤²à¥‡à¤Ÿ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } elseif ($userLang === 'Bengali' || preg_match('/[\x{0980}-\x{09FF}]/u', $prompt) || preg_match('/\b(koto|kotogulo|ache)\b/i', $prompt)) {
                $directAnswer = "ðŸ“Š **à¦ªà§à¦°à¦¿à¦¨à§à¦Ÿà¦¿à¦‚ à¦ªà§à¦²à§‡à¦Ÿ â€” à¦¸à§à¦Ÿà¦• à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **à¦®à§‹à¦Ÿ à¦ªà§à¦²à§‡à¦Ÿ:** **{$totalCount}à¦Ÿà¦¿**\n\n";
                if (!empty($dieStats)) {
                    $directAnswer .= "ðŸ“ **à¦§à¦°à¦£ à¦…à¦¨à§à¦¯à¦¾à¦¯à¦¼à§€ (Die Type):**\n";
                    foreach ($dieStats as $ds) {
                        $directAnswer .= "   â–¸ **" . ($ds['die'] ?: 'à¦…à¦¨à§à¦¯à¦¾à¦¨à§à¦¯') . "** â€” {$ds['cnt']}à¦Ÿà¦¿ à¦ªà§à¦²à§‡à¦Ÿ\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($makerStats)) {
                    $directAnswer .= "ðŸ­ **à¦¨à¦¿à¦°à§à¦®à¦¾à¦¤à¦¾ / à¦¸à¦¾à¦ªà§à¦²à¦¾à¦¯à¦¼à¦¾à¦°:**\n";
                    foreach ($makerStats as $ms) {
                        $directAnswer .= "   â–¸ **{$ms['make_by']}** â€” {$ms['cnt']}à¦Ÿà¦¿ à¦ªà§à¦²à§‡à¦Ÿ\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "ðŸ†• **à¦¸à¦°à§à¦¬à¦¶à§‡à¦· à¦ªà§à¦²à§‡à¦Ÿ:** `{$latest['name']}` (SL: {$latest['sl_no']})";
                    if (!empty($latest['date_received']) && $latest['date_received'] !== 'NA') {
                        $directAnswer .= " â€” à¦ªà§à¦°à¦¾à¦ªà§à¦¤: {$latest['date_received']}";
                    }
                    $directAnswer .= "\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [à¦ªà§à¦²à§‡à¦Ÿ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } else {
                $directAnswer = "ðŸ“Š **Printing Plates â€” Stock Dashboard**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **Total Plates:** **{$totalCount}**\n\n";
                if (!empty($dieStats)) {
                    $directAnswer .= "ðŸ“ **By Die Type:**\n";
                    foreach ($dieStats as $ds) {
                        $directAnswer .= "   â–¸ **" . ($ds['die'] ?: 'Other') . "** â€” {$ds['cnt']} plates\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($makerStats)) {
                    $directAnswer .= "ðŸ­ **By Maker / Supplier:**\n";
                    foreach ($makerStats as $ms) {
                        $directAnswer .= "   â–¸ **{$ms['make_by']}** â€” {$ms['cnt']} plates\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "ðŸ†• **Latest Plate Added:** `{$latest['name']}` (SL: {$latest['sl_no']})";
                    if (!empty($latest['date_received']) && $latest['date_received'] !== 'NA') {
                        $directAnswer .= " â€” Received: {$latest['date_received']}";
                    }
                    $directAnswer .= "\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
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
            'à¦¹à¦¬à§‡',
            'à¦†à¦›à§‡',
            'à¦•à¦¤',
            'à¦•à¦¤à¦—à§à¦²à§‹',
            'à¦•à¦¿',
            'à¦•à§€',
            'à¦•à§‹à¦¨'
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
                $answer = "ðŸ“ **Flexo Printing & Plate Production Calculator:**\n\n"
                    . "ðŸ“‹ **Job / Plate Name:** `{$name}` (SL No: **{$plate['sl_no']}** | ID: **{$plate['id']}**)\n"
                    . "âš™ï¸ **Plate Specifications:** Ups: **{$ups}** | Repeat Value: **{$repeatVal}mm** | Size: **{$plate['size']}**\n\n";

                if ($targetQty !== null && $targetQty > 0) {
                    $revs = $targetQty / $ups;
                    $rawMeters = ($revs * $repeatVal) / 1000.0;
                    $wastageMeters = $rawMeters * 1.05;
                    $answer .= "ðŸŽ¯ **Target Quantity:** **" . number_format($targetQty) . " pcs**\n"
                        . "ðŸ“ **Net Paper Length Required:** **" . number_format($rawMeters, 2) . " meters**\n"
                        . "ðŸ›¡ï¸ **Total Paper (with 5% setup wastage):** **" . number_format($wastageMeters, 2) . " meters**\n\n";
                }

                if ($paperMeters !== null && $paperMeters > 0) {
                    $totalMM = $paperMeters * 1000.0;
                    $revs = $totalMM / $repeatVal;
                    $totalQty = floor($revs * $ups);
                    $answer .= "ðŸ“œ **Paper Roll Run Length:** **" . number_format($paperMeters, 2) . " meters**\n"
                        . "ðŸ“¦ **Expected Label Production Output:** **" . number_format($totalQty) . " pcs / labels**\n\n";
                }

                $answer .= "ðŸ‘‰ [Click here to open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } elseif ($userLang === 'Hindi') {
                $answer = "ðŸ“ **à¤«à¥à¤²à¥‡à¤•à¥à¤¸à¥‹ à¤ªà¥à¤°à¤¿à¤‚à¤Ÿà¤¿à¤‚à¤— à¤”à¤° à¤ªà¥à¤²à¥‡à¤Ÿ à¤‰à¤¤à¥à¤ªà¤¾à¤¦à¤¨ à¤•à¥ˆà¤²à¤•à¥à¤²à¥‡à¤Ÿà¤°:**\n\n"
                    . "ðŸ“‹ **à¤œà¥‰à¤¬ / à¤ªà¥à¤²à¥‡à¤Ÿ à¤•à¤¾ à¤¨à¤¾à¤®:** `{$name}` (SL No: **{$plate['sl_no']}**)\n"
                    . "âš™ï¸ **à¤ªà¥à¤²à¥‡à¤Ÿ à¤µà¤¿à¤µà¤°à¤£:** Ups: **{$ups}** | Repeat Value: **{$repeatVal}mm** | Size: **{$plate['size']}**\n\n";

                if ($targetQty !== null && $targetQty > 0) {
                    $revs = $targetQty / $ups;
                    $rawMeters = ($revs * $repeatVal) / 1000.0;
                    $wastageMeters = $rawMeters * 1.05;
                    $answer .= "ðŸŽ¯ **à¤²à¤•à¥à¤·à¥à¤¯ à¤®à¤¾à¤¤à¥à¤°à¤¾ (Target Quantity):** **" . number_format($targetQty) . " à¤ªà¥€à¤¸**\n"
                        . "ðŸ“ **à¤†à¤µà¤¶à¥à¤¯à¤• à¤•à¤¾à¤—à¤œ (Net Paper Needed):** **" . number_format($rawMeters, 2) . " à¤®à¥€à¤Ÿà¤°**\n"
                        . "ðŸ›¡ï¸ **à¤•à¥à¤² à¤•à¤¾à¤—à¤œ (5% à¤µà¥‡à¤¸à¥à¤Ÿà¥‡à¤œ à¤¸à¤¹à¤¿à¤¤):** **" . number_format($wastageMeters, 2) . " à¤®à¥€à¤Ÿà¤°**\n\n";
                }

                if ($paperMeters !== null && $paperMeters > 0) {
                    $totalMM = $paperMeters * 1000.0;
                    $revs = $totalMM / $repeatVal;
                    $totalQty = floor($revs * $ups);
                    $answer .= "ðŸ“œ **à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤² à¤•à¥€ à¤²à¤‚à¤¬à¤¾à¤ˆ:** **" . number_format($paperMeters, 2) . " à¤®à¥€à¤Ÿà¤°**\n"
                        . "ðŸ“¦ **à¤•à¥à¤² à¤‰à¤¤à¥à¤ªà¤¾à¤¦à¤¨ à¤®à¤¾à¤¤à¥à¤°à¤¾:** **" . number_format($totalQty) . " à¤ªà¥€à¤¸ / à¤²à¥‡à¤¬à¤²**\n\n";
                }

                $answer .= "ðŸ‘‰ [à¤ªà¥à¤²à¥‡à¤Ÿ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
            } else {
                $answer = "ðŸ“ **à¦«à§à¦²à§‡à¦•à§à¦¸à§‹ à¦ªà§à¦°à¦¿à¦¨à§à¦Ÿà¦¿à¦‚ à¦“ à¦ªà§à¦²à§‡à¦Ÿ à¦‰à¦¤à§à¦ªà¦¾à¦¦à¦¨ à¦•à§à¦¯à¦¾à¦²à¦•à§à¦²à§‡à¦Ÿà¦°:**\n\n"
                    . "ðŸ“‹ **à¦œà¦¬ / à¦ªà§à¦²à§‡à¦Ÿà§‡à¦° à¦¨à¦¾à¦®:** `{$name}` (SL No: **{$plate['sl_no']}** | ID: **{$plate['id']}**)\n"
                    . "âš™ï¸ **à¦ªà§à¦²à§‡à¦Ÿ à¦¬à¦¿à¦¬à¦°à¦£:** à¦†à¦«à¦¸ (Ups): **{$ups}** | à¦°à¦¿à¦ªà¦¿à¦Ÿ à¦­à§à¦¯à¦¾à¦²à§: **{$repeatVal}mm** | à¦¸à¦¾à¦‡à¦œ: **{$plate['size']}**\n\n";

                if ($targetQty !== null && $targetQty > 0) {
                    $revs = $targetQty / $ups;
                    $rawMeters = ($revs * $repeatVal) / 1000.0;
                    $wastageMeters = $rawMeters * 1.05;
                    $answer .= "ðŸŽ¯ **à¦Ÿà¦¾à¦°à§à¦—à§‡à¦Ÿ à¦•à§‹à§Ÿà¦¾à¦¨à§à¦Ÿà¦¿à¦Ÿà¦¿ (Target Quantity):** **" . number_format($targetQty) . "à¦Ÿà¦¿**\n"
                        . "ðŸ“ **à¦†à¦¬à¦¶à§à¦¯à¦• à¦•à¦¾à¦—à¦œ (Net Paper Needed):** **" . number_format($rawMeters, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°**\n"
                        . "ðŸ›¡ï¸ **à¦•à§à¦² à¦•à¦¾à¦—à¦œ (5% à¦“à¦¯à¦¼à§‡à¦¸à§à¦Ÿà§‡à¦œ à¦¸à¦¹à¦¿à¦¤):** **" . number_format($wastageMeters, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°**\n\n";
                }

                if ($paperMeters !== null && $paperMeters > 0) {
                    $totalMM = $paperMeters * 1000.0;
                    $revs = $totalMM / $repeatVal;
                    $totalQty = floor($revs * $ups);
                    $answer .= "ðŸ“œ **à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦²à§‡à¦° à¦¦à§ˆà¦°à§à¦˜à§à¦¯ (Paper Roll Length):** **" . number_format($paperMeters, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°**\n"
                        . "ðŸ“¦ **à¦•à§à¦² à¦‰à¦¤à§à¦ªà¦¾à¦¦à¦¨ à¦®à¦¾à¦¤à§à¦°à¦¾:** **" . number_format($totalQty) . "à¦Ÿà¦¿ à¦²à§‡à¦¬à§‡à¦²/à¦ªà¦¿à¦¸**\n\n";
                }

                $answer .= "ðŸ‘‰ [à¦ªà§à¦²à§‡à¦Ÿ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
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
                $directAnswer = "âŒ **No Printing Plate Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Plate database, but no plate record matching **\"{$searchTermDisplay}\"** was found.\n\n"
                    . "ðŸ’¡ **Tip:** Please verify if the plate name or SL No is spelled correctly.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "âŒ **\"{$searchTermDisplay}\" à¤¨à¤¾à¤® à¤•à¤¾ à¤•à¥‹à¤ˆ à¤ªà¥à¤²à¥‡à¤Ÿ à¤¨à¤¹à¥€à¤‚ à¤®à¤¿à¤²à¤¾**\n\n"
                    . "à¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤®à¥‡à¤‚ **\"{$searchTermDisplay}\"** à¤•à¤¾ à¤•à¥‹à¤ˆ à¤°à¤¿à¤•à¥‰à¤°à¥à¤¡ à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¨à¤¹à¥€à¤‚ à¤¹à¥ˆà¥¤";
            } else {
                $directAnswer = "âŒ **\"{$searchTermDisplay}\" à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦ªà§à¦²à§‡à¦Ÿ à¦ªà¦¾à¦“à¦¯à¦¼à¦¾ à¦¯à¦¾à¦¯à¦¼à¦¨à¦¿**\n\n"
                    . "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦®à¦¾à¦¸à§à¦Ÿà¦¾à¦° à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **\"{$searchTermDisplay}\"** à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦ªà§à¦²à§‡à¦Ÿ à¦°à§‡à¦•à¦°à§à¦¡ à¦¨à¦¿à¦¬à¦¨à§à¦§à¦¿à¦¤ à¦¨à§‡à¦‡à¥¤";
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
                $directAnswer = "ðŸ“ **à¤¬à¤¾à¤°à¤•à¥‹à¤¡ à¤¡à¤¾à¤ˆ à¤Ÿà¥‚à¤²à¤¿à¤‚à¤— â€” à¤¸à¥à¤Ÿà¥‰à¤• à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **à¤•à¥à¤² à¤¡à¤¾à¤ˆ:** **{$totalCount}**\n\n";
                if (!empty($typeStats)) {
                    $directAnswer .= "âš™ï¸ **à¤¡à¤¾à¤ˆ à¤ªà¥à¤°à¤•à¤¾à¤°:**\n";
                    foreach ($typeStats as $ts) {
                        $directAnswer .= "   â–¸ **" . ($ts['die_type'] ?: 'à¤…à¤¨à¥à¤¯') . "** â€” {$ts['cnt']} à¤¡à¤¾à¤ˆ\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($catStats)) {
                    $directAnswer .= "ðŸ“¦ **à¤¶à¥à¤°à¥‡à¤£à¥€ (Category):**\n";
                    foreach ($catStats as $cs) {
                        $directAnswer .= "   â–¸ **{$cs['used_for']}** â€” {$cs['cnt']} à¤¡à¤¾à¤ˆ\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "ðŸ†• **à¤¸à¤¬à¤¸à¥‡ à¤¹à¤¾à¤²à¤¿à¤¯à¤¾ à¤¡à¤¾à¤ˆ:** `{$latest['barcode_size']}` (SL: {$latest['sl_no']}) â€” à¤¶à¥à¤°à¥‡à¤£à¥€: {$latest['used_for']}\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [à¤¬à¤¾à¤°à¤•à¥‹à¤¡ à¤¡à¤¾à¤ˆ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } elseif ($userLang === 'Bengali' || preg_match('/[\x{0980}-\x{09FF}]/u', $prompt) || preg_match('/\b(koto|kotogulo|ache)\b/i', $prompt)) {
                $directAnswer = "ðŸ“ **à¦¬à¦¾à¦°à¦•à§‹à¦¡ à¦¡à¦¾à¦‡ à¦Ÿà§‚à¦²à¦¿à¦‚ â€” à¦¸à§à¦Ÿà¦• à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **à¦®à§‹à¦Ÿ à¦¡à¦¾à¦‡:** **{$totalCount}à¦Ÿà¦¿**\n\n";
                if (!empty($typeStats)) {
                    $directAnswer .= "âš™ï¸ **à¦¡à¦¾à¦‡ à¦§à¦°à¦£ (Die Type):**\n";
                    foreach ($typeStats as $ts) {
                        $directAnswer .= "   â–¸ **" . ($ts['die_type'] ?: 'à¦…à¦¨à§à¦¯à¦¾à¦¨à§à¦¯') . "** â€” {$ts['cnt']}à¦Ÿà¦¿ à¦¡à¦¾à¦‡\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($catStats)) {
                    $directAnswer .= "ðŸ“¦ **à¦•à§à¦¯à¦¾à¦Ÿà¦¾à¦—à¦°à¦¿ (Category):**\n";
                    foreach ($catStats as $cs) {
                        $directAnswer .= "   â–¸ **{$cs['used_for']}** â€” {$cs['cnt']}à¦Ÿà¦¿ à¦¡à¦¾à¦‡\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "ðŸ†• **à¦¸à¦°à§à¦¬à¦¶à§‡à¦· à¦¡à¦¾à¦‡:** `{$latest['barcode_size']}` (SL: {$latest['sl_no']}) â€” à¦¶à§à¦°à§‡à¦£à§€: {$latest['used_for']}\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [à¦¬à¦¾à¦°à¦•à§‹à¦¡ à¦¡à¦¾à¦‡ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } else {
                $directAnswer = "ðŸ“ **Barcode Die Tooling â€” Stock Dashboard**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **Total Dies:** **{$totalCount}**\n\n";
                if (!empty($typeStats)) {
                    $directAnswer .= "âš™ï¸ **By Die Type:**\n";
                    foreach ($typeStats as $ts) {
                        $directAnswer .= "   â–¸ **" . ($ts['die_type'] ?: 'Other') . "** â€” {$ts['cnt']} dies\n";
                    }
                    $directAnswer .= "\n";
                }
                if (!empty($catStats)) {
                    $directAnswer .= "ðŸ“¦ **By Category:**\n";
                    foreach ($catStats as $cs) {
                        $directAnswer .= "   â–¸ **{$cs['used_for']}** â€” {$cs['cnt']} dies\n";
                    }
                    $directAnswer .= "\n";
                }
                if ($latest) {
                    $directAnswer .= "ðŸ†• **Latest Die Added:** `{$latest['barcode_size']}` (SL: {$latest['sl_no']}) â€” Category: {$latest['used_for']}\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [Open Barcode Die Management Page]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
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
                $directAnswer = "âŒ **No Barcode Die Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Die Tooling database, but no record matching **\"{$searchTermDisplay}\"** was found.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "âŒ **\"{$searchTermDisplay}\" à¤¨à¤¾à¤® à¤•à¤¾ à¤•à¥‹à¤ˆ à¤¬à¤¾à¤°à¤•à¥‹à¤¡ à¤¡à¤¾à¤ˆ à¤°à¤¿à¤•à¥‰à¤°à¥à¤¡ à¤¨à¤¹à¥€à¤‚ à¤®à¤¿à¤²à¤¾**\n\n"
                    . "à¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤®à¥‡à¤‚ **\"{$searchTermDisplay}\"** à¤•à¤¾ à¤•à¥‹à¤ˆ à¤°à¤¿à¤•à¥‰à¤°à¥à¤¡ à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¨à¤¹à¥€à¤‚ à¤¹à¥ˆà¥¤";
            } else {
                $directAnswer = "âŒ **\"{$searchTermDisplay}\" à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦¬à¦¾à¦°à¦•à§‹à¦¡ à¦¡à¦¾à¦‡ à¦Ÿà§‚à¦²à¦¿à¦‚ à¦ªà¦¾à¦“à§Ÿà¦¾ à¦¯à¦¾à§Ÿà¦¨à¦¿**\n\n"
                    . "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦®à¦¾à¦¸à§à¦Ÿà¦¾à¦° à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **\"{$searchTermDisplay}\"** à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦¡à¦¾à¦‡ à¦°à§‡à¦•à¦°à§à¦¡ à¦¨à¦¿à¦¬à¦¨à§à¦§à¦¿à¦¤ à¦¨à§‡à¦‡à¥¤";
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
                $answer = "ðŸ“ **Barcode Die Management & Tooling Master â€” Specifications:**\n\nFound **" . count($data) . " matching die record(s)** in your ERP database:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "â€¢ **Die " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - ðŸ“ **Barcode Size:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - ðŸ”¢ **Ups (Roll / Die):** Roll Ups: **" . ($row['ups_in_roll'] ?: '1') . "** | Die Ups: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - ðŸ“ **Repeat Size:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **Label Gap:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - âš™ï¸ **Die Type & Cylinder:** **" . ($row['die_type'] ?: 'Rotary') . "** | Cylinder: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - ðŸ“„ **Paper Size & Core:** Paper Size: **" . ($row['paper_size'] ?: 'N/A') . "** | Core: **" . ($row['core'] ?: 'N/A') . "**\n"
                        . "  - ðŸ“¦ **Pieces per Roll:** **" . ($row['pices_per_roll'] ?: 'N/A') . "** | Category: **" . ($row['used_for'] ?: 'Barcode') . "**\n\n";
                }
                $answer .= "ðŸ‘‰ [Click here to open Barcode Die Management Page]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } elseif ($userLang === 'Hindi') {
                $answer = "ðŸ“ **à¤¬à¤¾à¤°à¤•à¥‹à¤¡ à¤¡à¤¾à¤ˆ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤”à¤° à¤Ÿà¥‚à¤²à¤¿à¤‚à¤— à¤®à¤¾à¤¸à¥à¤Ÿà¤° à¤µà¤¿à¤µà¤°à¤£:**\n\nà¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤®à¥‡à¤‚ **" . count($data) . " à¤®à¥ˆà¤šà¤¿à¤‚à¤— à¤¡à¤¾à¤ˆ à¤°à¤¿à¤•à¥‰à¤°à¥à¤¡** à¤®à¤¿à¤²à¥‡ à¤¹à¥ˆà¤‚:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "â€¢ **à¤¡à¤¾à¤ˆ " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}**)\n"
                        . "  - ðŸ“ **à¤¬à¤¾à¤°à¤•à¥‹à¤¡ à¤†à¤•à¤¾à¤°:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - ðŸ”¢ **à¤…à¤ªà¥à¤¸ (Roll / Die):** à¤°à¥‹à¤² à¤…à¤ªà¥à¤¸: **" . ($row['ups_in_roll'] ?: '1') . "** | à¤¡à¤¾à¤ˆ à¤…à¤ªà¥à¤¸: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - ðŸ“ **à¤°à¤¿à¤ªà¥€à¤Ÿ à¤¸à¤¾à¤‡à¤œ:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **à¤²à¥‡à¤¬à¤² à¤—à¥ˆà¤ª:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - âš™ï¸ **à¤¡à¤¾à¤ˆ à¤ªà¥à¤°à¤•à¤¾à¤° à¤“ à¤¸à¤¿à¤²à¥‡à¤‚à¤¡à¤°:** **" . ($row['die_type'] ?: 'Rotary') . "** | à¤¸à¤¿à¤²à¥‡à¤‚à¤¡à¤°: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - ðŸ“„ **à¤ªà¥‡à¤ªà¤° à¤¸à¤¾à¤‡à¤œ à¤“ à¤•à¥‹à¤°:** à¤ªà¥‡à¤ªà¤° à¤¸à¤¾à¤‡à¤œ: **" . ($row['paper_size'] ?: 'N/A') . "** | à¤•à¥‹à¤°: **" . ($row['core'] ?: 'N/A') . "**\n"
                        . "  - ðŸ“¦ **à¤ªà¤¿à¤¸ à¤ªà¥à¤°à¤¤à¤¿ à¤°à¥‹à¤²:** **" . ($row['pices_per_roll'] ?: 'N/A') . "** | à¤•à¥à¤¯à¤¾à¤Ÿà¤¾à¤—à¤°à¥€: **" . ($row['used_for'] ?: 'Barcode') . "**\n\n";
                }
                $answer .= "ðŸ‘‰ [à¤¬à¤¾à¤°à¤•à¥‹à¤¡ à¤¡à¤¾à¤ˆ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
            } else {
                $answer = "ðŸ“ **à¦¬à¦¾à¦°à¦•à§‹à¦¡ à¦¡à¦¾à¦‡ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦“ à¦Ÿà§‚à¦²à¦¿à¦‚ à¦®à¦¾à¦¸à§à¦Ÿà¦¾à¦° à¦¸à§à¦ªà§‡à¦¸à¦¿à¦«à¦¿à¦•à§‡à¦¶à¦¨:**\n\nà¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **" . count($data) . "à¦Ÿà¦¿ à¦®à§à¦¯à¦¾à¦šà¦¿à¦‚ à¦¡à¦¾à¦‡ à¦°à§‡à¦•à¦°à§à¦¡** à¦ªà¦¾à¦“à§Ÿà¦¾ à¦—à§‡à¦›à§‡:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "â€¢ **à¦¡à¦¾à¦‡ " . ($idx + 1) . ": `" . ($row['barcode_size'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - ðŸ“ **à¦¬à¦¾à¦°à¦•à§‹à¦¡ à¦†à¦•à¦¾à¦°:** **" . ($row['barcode_size'] ?: 'N/A') . "**\n"
                        . "  - ðŸ”¢ **à¦†à¦«à¦¸ (Roll / Die):** à¦°à§‹à¦² à¦†à¦«à¦¸: **" . ($row['ups_in_roll'] ?: '1') . "** | à¦¡à¦¾à¦‡ à¦†à¦«à¦¸: **" . ($row['up_in_die'] ?: '1') . "**\n"
                        . "  - ðŸ“ **à¦°à¦¿à¦ªà¦¿à¦Ÿ à¦¸à¦¾à¦‡à¦œ:** **" . ($row['repeat_size'] ?: 'N/A') . "** | **à¦²à§‡à¦¬à§‡à¦² à¦—à§à¦¯à¦¾à¦ª:** **" . ($row['label_gap'] ?: 'N/A') . "**\n"
                        . "  - âš™ï¸ **à¦¡à¦¾à¦‡ à¦Ÿà¦¾à¦‡à¦ª à¦“ à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦°:** **" . ($row['die_type'] ?: 'Rotary') . "** | à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦°: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                        . "  - ðŸ“„ **à¦ªà§‡à¦ªà¦¾à¦° à¦¸à¦¾à¦‡à¦œ à¦“ à¦•à§‹à¦°:** à¦ªà§‡à¦ªà¦¾à¦° à¦¸à¦¾à¦‡à¦œ: **" . ($row['paper_size'] ?: 'N/A') . "** | à¦•à§‹à¦°: **" . ($row['core'] ?: 'N/A') . "**\n"
                        . "  - ðŸ“¦ **à¦ªà¦¿à¦¸ à¦ªà§à¦°à¦¤à¦¿ à¦°à§‹à¦²:** **" . ($row['pices_per_roll'] ?: 'N/A') . "** | à¦•à§à¦¯à¦¾à¦Ÿà¦¾à¦—à¦°à¦¿: **" . ($row['used_for'] ?: 'Barcode') . "**\n\n";
                }
                $answer .= "ðŸ‘‰ [à¦¬à¦¾à¦°à¦•à§‹à¦¡ à¦¡à¦¾à¦‡ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/plate-tools/die-management/barcode/index.php)";
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

    } elseif (strpos($p, 'anilox') !== false || strpos($p, 'lpi') !== false || strpos($p, 'bcm') !== false || strpos($p, 'bmc') !== false || strpos($p, 'à¦à¦¨à¦¿à¦²à¦•à§à¦¸') !== false) {
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

            $lpiRangeStr = ($lpiR && $lpiR['min_lpi']) ? "{$lpiR['min_lpi']} â€” {$lpiR['max_lpi']} LPI" : 'N/A';

            if ($userLang === 'Hindi') {
                $directAnswer = "ðŸŒ€ **à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤°à¥‹à¤² â€” à¤¸à¥à¤Ÿà¥‰à¤• à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **à¤•à¥à¤² à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤ªà¥à¤°à¤•à¤¾à¤°:** **{$totalCount}**\n"
                    . "ðŸ“¦ **à¤•à¥à¤² à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¸à¥à¤Ÿà¥‰à¤•:** **{$totalStock} à¤°à¥‹à¤²**\n\n"
                    . "ðŸ“ **LPI à¤°à¥‡à¤‚à¤œ:** {$lpiRangeStr}\n"
                    . "âœ… **à¤¸à¥à¤Ÿà¥‰à¤• à¤®à¥‡à¤‚:** {$inStockCount} à¤ªà¥à¤°à¤•à¤¾à¤° | âŒ **à¤¸à¥à¤Ÿà¥‰à¤• à¤¸à¥‡ à¤¬à¤¾à¤¹à¤°:** {$outOfStock} à¤ªà¥à¤°à¤•à¤¾à¤°\n\n";
                if ($latest) {
                    $directAnswer .= "ðŸ†• **à¤¨à¤µà¥€à¤¨à¤¤à¤®:** `{$latest['anilox_lpi']} LPI` / `{$latest['anilox_bmc']} BCM` (SL: {$latest['sl_no']}) â€” à¤¸à¥à¤Ÿà¥‰à¤•: {$latest['stock_qty']} à¤°à¥‹à¤²\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } elseif ($userLang === 'Bengali' || preg_match('/[\x{0980}-\x{09FF}]/u', $prompt) || preg_match('/\b(koto|kotogulo|ache)\b/i', $prompt)) {
                $directAnswer = "ðŸŒ€ **à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦°à§‹à¦² â€” à¦¸à§à¦Ÿà¦• à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **à¦®à§‹à¦Ÿ à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦§à¦°à¦£:** **{$totalCount}à¦Ÿà¦¿**\n"
                    . "ðŸ“¦ **à¦®à§‹à¦Ÿ à¦‰à¦ªà¦²à¦¬à§à¦§ à¦¸à§à¦Ÿà¦•:** **{$totalStock}à¦Ÿà¦¿ à¦°à§‹à¦²**\n\n"
                    . "ðŸ“ **LPI à¦°à§‡à¦žà§à¦œ:** {$lpiRangeStr}\n"
                    . "âœ… **à¦¸à§à¦Ÿà¦•à§‡ à¦†à¦›à§‡:** {$inStockCount}à¦Ÿà¦¿ à¦§à¦°à¦£ | âŒ **à¦¸à§à¦Ÿà¦•à§‡ à¦¨à§‡à¦‡:** {$outOfStock}à¦Ÿà¦¿ à¦§à¦°à¦£\n\n";
                if ($latest) {
                    $directAnswer .= "ðŸ†• **à¦¸à¦°à§à¦¬à¦¶à§‡à¦·:** `{$latest['anilox_lpi']} LPI` / `{$latest['anilox_bmc']} BCM` (SL: {$latest['sl_no']}) â€” à¦¸à§à¦Ÿà¦•: {$latest['stock_qty']}à¦Ÿà¦¿ à¦°à§‹à¦²\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œ à¦–à§à¦²à§à¦¨]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } else {
                $directAnswer = "ðŸŒ€ **Anilox Roll â€” Stock Dashboard**\n"
                    . "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n\n"
                    . "ðŸ”¢ **Total Anilox Types:** **{$totalCount}**\n"
                    . "ðŸ“¦ **Total Available Stock:** **{$totalStock} Rolls**\n\n"
                    . "ðŸ“ **LPI Range:** {$lpiRangeStr}\n"
                    . "âœ… **In Stock:** {$inStockCount} types | âŒ **Out of Stock:** {$outOfStock} types\n\n";
                if ($latest) {
                    $directAnswer .= "ðŸ†• **Latest Entry:** `{$latest['anilox_lpi']} LPI` / `{$latest['anilox_bmc']} BCM` (SL: {$latest['sl_no']}) â€” Stock: {$latest['stock_qty']} Rolls\n\n";
                }
                $directAnswer .= "â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”â”\n"
                    . "ðŸ‘‰ [Open Anilox Management Page]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
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
                $directAnswer = "âŒ **No Anilox Roll Found Matching \"{$searchTermDisplay}\"**\n\n"
                    . "I searched your ERP Master Anilox Stock database, but no record matching **\"{$searchTermDisplay}\"** was found.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "âŒ **\"{$searchTermDisplay}\" à¤¨à¤¾à¤® à¤•à¤¾ à¤•à¥‹à¤ˆ à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤°à¥‹à¤² à¤¨à¤¹à¥€à¤‚ à¤®à¤¿à¤²à¤¾**\n\n"
                    . "à¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤®à¥‡à¤‚ **\"{$searchTermDisplay}\"** à¤•à¤¾ à¤•à¥‹à¤ˆ à¤°à¤¿à¤•à¥‰à¤°à¥à¤¡ à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¨à¤¹à¥€à¤‚ à¤¹à¥ˆà¥¤";
            } else {
                $directAnswer = "âŒ **\"{$searchTermDisplay}\" à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦°à§‹à¦² à¦ªà¦¾à¦“à§Ÿà¦¾ à¦¯à¦¾à§Ÿà¦¨à¦¿**\n\n"
                    . "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦®à¦¾à¦¸à§à¦Ÿà¦¾à¦° à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **\"{$searchTermDisplay}\"** à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦°à§‹à¦² à¦°à§‡à¦•à¦°à§à¦¡ à¦¨à¦¿à¦¬à¦¨à§à¦§à¦¿à¦¤ à¦¨à§‡à¦‡à¥¤";
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
                $answer = "ðŸŒ€ **Anilox Management & Inventory Stock â€” Live Specifications:**\n\nFound **" . count($data) . " matching Anilox Roll record(s)** in your ERP database:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "â€¢ **Anilox " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - ðŸŒ€ **Anilox LPI (Lines Per Inch):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - ðŸ§ª **Anilox Volume (BCM / BMC):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - ðŸ“¦ **Available Stock Quantity:** **" . ($row['stock_qty'] ?: '0') . " Roll(s)**\n\n";
                }
                $answer .= "ðŸ‘‰ [Click here to open Anilox Management Page]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } elseif ($userLang === 'Hindi') {
                $answer = "ðŸŒ€ **à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤°à¥‹à¤² â€” à¤¸à¥à¤Ÿà¥‰à¤• à¤¡à¥ˆà¤¶à¤¬à¥‹à¤°à¥à¤¡**\n\nà¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤®à¥‡à¤‚ **" . count($data) . " à¤®à¥ˆà¤šà¤¿à¤‚à¤— à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤°à¥‹à¤² à¤°à¤¿à¤•à¥‰à¤°à¥à¤¡** à¤®à¤¿à¤²à¥‡ à¤¹à¥ˆà¤‚:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "â€¢ **à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}**)\n"
                        . "  - ðŸŒ€ **à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤à¤²à¤ªà¥€à¤†à¤ˆ (LPI):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - ðŸ§ª **à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤µà¥‰à¤²à¥à¤¯à¥‚à¤® (BCM / BMC):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - ðŸ“¦ **à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¸à¥à¤Ÿà¥‰à¤• à¤®à¤¾à¤¤à¥à¤°à¤¾:** **" . ($row['stock_qty'] ?: '0') . " à¤°à¥‹à¤²**\n\n";
                }
                $answer .= "ðŸ‘‰ [à¤à¤¨à¤¿à¤²à¥‰à¤•à¥à¤¸ à¤ªà¥à¤°à¤¬à¤‚à¤§à¤¨ à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
            } else {
                $answer = "ðŸŒ€ **à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦°à§‹à¦² â€” à¦¸à§à¦Ÿà¦• à¦¡à§à¦¯à¦¾à¦¶à¦¬à§‹à¦°à§à¦¡**\n\nà¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **" . count($data) . "à¦Ÿà¦¿ à¦®à§à¦¯à¦¾à¦šà¦¿à¦‚ à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦°à§‹à¦² à¦°à§‡à¦•à¦°à§à¦¡** à¦ªà¦¾à¦“à§Ÿà¦¾ à¦—à§‡à¦›à§‡:\n\n";
                foreach ($data as $idx => $row) {
                    $answer .= "â€¢ **à¦à¦¨à¦¿à¦²à¦•à§à¦¸ " . ($idx + 1) . ": `" . ($row['anilox_lpi'] ?: 'Anilox') . " LPI`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                        . "  - ðŸŒ€ **à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦à¦²à¦ªà¦¿à¦†à¦‡ (LPI):** **" . ($row['anilox_lpi'] ?: 'N/A') . " LPI**\n"
                        . "  - ðŸ§ª **à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦­à¦²à¦¿à¦‰à¦® (BCM / BMC):** **" . ($row['anilox_bmc'] ?: 'N/A') . " BCM**\n"
                        . "  - ðŸ“¦ **à¦‰à¦ªà¦²à¦¬à§à¦§ à¦¸à§à¦Ÿà¦• à¦®à¦¾à¦¤à§à¦°à¦¾:** **" . ($row['stock_qty'] ?: '0') . " à¦°à§‹à¦²**\n\n";
                }
                $answer .= "ðŸ‘‰ [à¦à¦¨à¦¿à¦²à¦•à§à¦¸ à¦®à§à¦¯à¦¾à¦¨à§‡à¦œà¦®à§‡à¦¨à§à¦Ÿ à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/plate-tools/anilox-management/index.php)";
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
            $answer = "ðŸ“œ **{$titleHeading} â€” Complete Technical Inventory Summary:**\n\n"
                . "ðŸ“Š **Total Stock Metrics:**\n"
                . "  - Total Paper Rolls: **" . number_format($totalCount) . " Rolls**\n"
                . "  - Total Running Length: **" . number_format($totalMeters, 2) . " meters**\n"
                . "  - Total Surface Area: **" . number_format($totalSqm, 2) . " SQM**\n"
                . "  - Latest Stock Entry Date: `" . $latestEntryDate . "`\n\n"
                . "ðŸ­ **Jumbo Roll vs Slitting Breakdown:**\n"
                . "  - ðŸ“œ **Jumbo Parent Rolls (â‰¥1000mm):** **" . number_format($jumboRolls) . " Rolls** (" . number_format($jumboMtr, 2) . " meters)\n"
                . "  - âœ‚ï¸ **Slitted Stock Rolls (<1000mm):** **" . number_format($slittedRolls) . " Rolls** (" . number_format($slittedMtr, 2) . " meters)\n\n";

            if (!empty($companyList) && count($companyList) > 1) {
                $answer .= "ðŸ¢ **Available Companies / Brands Breakdown:**\n";
                foreach ($companyList as $cb) {
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . " Rolls** (" . number_format((float) $cb['total_mtr'], 2) . " meters)\n";
                }
                $answer .= "\n";
            }

            $answer .= "ðŸ“¦ **Master Roll Grid (Sample " . count($data) . " Rolls):**\n\n";

            foreach ($data as $idx => $r) {
                $answer .= "â€¢ **Roll " . ($idx + 1) . ": `" . ($r['roll_no'] ?: 'Roll #' . $r['id']) . "`** (ID: **{$r['id']}** | Status: **{$r['status']}**)\n"
                    . "  - ðŸ·ï¸ **Brand & Type:** **" . ($r['company'] ?: 'General') . " " . ($r['paper_type'] ?: 'Substrate') . "**\n"
                    . "  - ðŸ“ **Dimensions:** Width: **" . ($r['width_mm'] ?: '0') . "mm** | Length: **" . number_format((float) $r['length_mtr'], 2) . "m**\n"
                    . "  - ðŸ“ **Surface Area:** **" . number_format((float) $r['sqm'], 2) . " SQM**\n"
                    . "  - ðŸ“… **Date Received:** `" . ($r['date_received'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [Click here to open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";
        } elseif ($userLang === 'Hindi') {
            $answer = "ðŸ“œ **{$titleHeading} â€” à¤ªà¥‚à¤°à¥à¤£ à¤¤à¤•à¤¨à¥€à¤•à¥€ à¤‡à¤¨à¥à¤µà¥‡à¤‚à¤Ÿà¤°à¥€ à¤¸à¤¾à¤°à¤¾à¤‚à¤¶:**\n\n"
                . "ðŸ“Š **à¤•à¥à¤² à¤¸à¥à¤Ÿà¥‰à¤• à¤®à¥‡à¤Ÿà¥à¤°à¤¿à¤•à¥à¤¸:**\n"
                . "  - à¤•à¥à¤² à¤ªà¥‡à¤ªà¤° à¤°à¥‹à¤²: **" . number_format($totalCount) . " à¤°à¥‹à¤²**\n"
                . "  - à¤•à¥à¤² à¤°à¤¨à¤¿à¤‚à¤— à¤²à¤‚à¤¬à¤¾à¤ˆ: **" . number_format($totalMeters, 2) . " à¤®à¥€à¤Ÿà¤°**\n"
                . "  - à¤•à¥à¤² à¤¸à¤¤à¤¹ à¤•à¥à¤·à¥‡à¤¤à¥à¤°à¤«à¤²: **" . number_format($totalSqm, 2) . " SQM**\n"
                . "  - à¤¨à¤µà¥€à¤¨à¤¤à¤® à¤ªà¥à¤°à¤µà¤¿à¤·à¥à¤Ÿà¤¿ à¤¤à¤¿à¤¥à¤¿: `" . $latestEntryDate . "`\n\n"
                . "ðŸ­ **à¤œà¤‚à¤¬à¥‹ à¤°à¥‹à¤² à¤¬à¤¨à¤¾à¤® à¤¸à¥à¤²à¤¿à¤Ÿà¤¿à¤‚à¤— à¤µà¤¿à¤µà¤°à¤£:**\n"
                . "  - ðŸ“œ **à¤œà¤‚à¤¬à¥‹ à¤ªà¥ˆà¤°à¥‡à¤‚à¤Ÿ à¤°à¥‹à¤² (â‰¥1000mm):** **" . number_format($jumboRolls) . " à¤°à¥‹à¤²** (" . number_format($jumboMtr, 2) . " à¤®à¥€à¤Ÿà¤°)\n"
                . "  - âœ‚ï¸ **à¤¸à¥à¤²à¤¿à¤Ÿà¥‡à¤¡ à¤¸à¥à¤Ÿà¥‰à¤• à¤°à¥‹à¤² (<1000mm):** **" . number_format($slittedRolls) . " à¤°à¥‹à¤²** (" . number_format($slittedMtr, 2) . " à¤®à¥€à¤Ÿà¤°)\n\n";

            if (!empty($companyList) && count($companyList) > 1) {
                $answer .= "ðŸ¢ **à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤•à¤‚à¤ªà¤¨à¤¿à¤¯à¤¾à¤‚ / à¤¬à¥à¤°à¤¾à¤‚à¤¡à¥à¤¸:**\n";
                foreach ($companyList as $cb) {
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . " à¤°à¥‹à¤²** (" . number_format((float) $cb['total_mtr'], 2) . " à¤®à¥€à¤Ÿà¤°)\n";
                }
                $answer .= "\n";
            }

            $answer .= "ðŸ‘‰ [à¤ªà¥‡à¤ªà¤° à¤¸à¥à¤Ÿà¤• à¤ªà¥‡à¤œ à¤–à¥‹à¤²à¥‡à¤‚]({$baseUrl}/modules/paper_stock/index.php)";
        } else {
            $answer = "ðŸ“œ **{$titleHeading} â€” à¦¸à¦®à§à¦ªà§‚à¦°à§à¦£ à¦Ÿà§‡à¦•à¦¨à¦¿à¦•à§à¦¯à¦¾à¦² à¦‡à¦¨à¦­à§‡à¦¨à§à¦Ÿà¦°à¦¿ à¦¸à¦¾à¦®à¦¾à¦°à¦¿:**\n\n"
                . "ðŸ“Š **à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦‡à¦¨à¦­à§‡à¦¨à§à¦Ÿà¦°à¦¿ à¦®à§à¦¯à¦¾à¦Ÿà§à¦°à¦¿à¦•à§à¦¸:**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦°à§‡à¦¡à¦¿ à¦ªà§‡à¦ªà¦¾à¦° à¦°à§‹à¦²: **" . number_format($totalCount) . "à¦Ÿà¦¿ à¦°à§‹à¦²**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦°à¦¾à¦¨à¦¿à¦‚ à¦¦à§ˆà¦°à§à¦˜à§à¦¯: **" . number_format($totalMeters, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°**\n"
                . "  - à¦¸à¦°à§à¦¬à¦®à§‹à¦Ÿ à¦¸à¦¾à¦°à¦«à§‡à¦¸ à¦à¦°à¦¿à§Ÿà¦¾ (SQM): **" . number_format($totalSqm, 2) . " SQM**\n"
                . "  - à¦¸à¦°à§à¦¬à¦¶à§‡à¦· à¦à¦¨à§à¦Ÿà§à¦°à¦¿ à¦¤à¦¾à¦°à¦¿à¦–: `" . $latestEntryDate . "`\n\n"
                . "ðŸ­ **à¦œà¦®à§à¦¬à§‹ à¦°à§‹à¦² à¦¬à¦¨à¦¾à¦® à¦¸à§à¦²à¦¿à¦Ÿà¦¿à¦‚ à¦¬à§à¦°à§‡à¦•à¦¡à¦¾à¦‰à¦¨:**\n"
                . "  - ðŸ“œ **à¦œà¦®à§à¦¬à§‹ à¦ªà§ˆà¦°à§‡à¦¨à§à¦Ÿ à¦°à§‹à¦² (â‰¥à§§à§¦à§¦à§¦à¦®à¦¿à¦®à¦¿):** **" . number_format($jumboRolls) . "à¦Ÿà¦¿ à¦°à§‹à¦²** (" . number_format($jumboMtr, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°)\n"
                . "  - âœ‚ï¸ **à¦¸à§à¦²à¦¿à¦Ÿà§‡à¦¡ à¦¸à§à¦Ÿà¦• à¦°à§‹à¦² (<à§§à§¦à§¦à§¦à¦®à¦¿à¦®à¦¿):** **" . number_format($slittedRolls) . "à¦Ÿà¦¿ à¦°à§‹à¦²** (" . number_format($slittedMtr, 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°)\n\n";

            if (!empty($companyList) && count($companyList) > 1) {
                $answer .= "ðŸ¢ **à¦‰à¦ªà¦²à¦¬à§à¦§ à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿ / à¦¬à§à¦°à§à¦¯à¦¾à¦¨à§à¦¡ à¦¤à¦¾à¦²à¦¿à¦•à¦¾:**\n";
                foreach ($companyList as $cb) {
                    $answer .= "  - **{$cb['company']}:** **" . number_format($cb['rolls']) . "à¦Ÿà¦¿ à¦°à§‹à¦²** (" . number_format((float) $cb['total_mtr'], 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°)\n";
                }
                $answer .= "\n";
            }

            $answer .= "ðŸ“¦ **à¦®à¦¾à¦¸à§à¦Ÿà¦¾à¦° à¦°à§‹à¦² à¦—à§à¦°à¦¿à¦¡ (à¦¸à§à¦¯à¦¾à¦®à§à¦ªà¦² " . count($data) . " à¦°à§‹à¦²):**\n\n";

            foreach ($data as $idx => $r) {
                $answer .= "â€¢ **à¦°à§‹à¦² " . ($idx + 1) . ": `" . ($r['roll_no'] ?: 'Roll #' . $r['id']) . "`** (ID: **{$r['id']}** | à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸: **{$r['status']}**)\n"
                    . "  - ðŸ·ï¸ **à¦¬à§à¦°à§à¦¯à¦¾à¦¨à§à¦¡ à¦“ à¦ªà§‡à¦ªà¦¾à¦° à¦Ÿà¦¾à¦‡à¦ª:** **" . ($r['company'] ?: 'General') . " " . ($r['paper_type'] ?: 'Substrate') . "**\n"
                    . "  - ðŸ“ **à¦¸à¦¾à¦‡à¦œ à¦“ à¦¦à§ˆà¦°à§à¦˜à§à¦¯:** à¦ªà§à¦°à¦¸à§à¦¥: **" . ($r['width_mm'] ?: '0') . "mm** | à¦¦à§ˆà¦°à§à¦˜à§à¦¯: **" . number_format((float) $r['length_mtr'], 2) . "m**\n"
                    . "  - ðŸ“ **à¦¸à¦¾à¦°à¦«à§‡à¦¸ à¦à¦°à¦¿à§Ÿà¦¾ (SQM):** **" . number_format((float) $r['sqm'], 2) . " SQM**\n"
                    . "  - ðŸ“… **à¦—à§à¦°à¦¹à¦£à§‡à¦° à¦¤à¦¾à¦°à¦¿à¦–:** `" . ($r['date_received'] ?: 'N/A') . "`\n\n";
            }

            $answer .= "ðŸ‘‰ [à¦ªà§‡à¦ªà¦¾à¦° à¦¸à§à¦Ÿà¦• à¦ªà§‡à¦œà¦Ÿà¦¿ à¦¸à¦°à¦¾à¦¸à¦°à¦¿ à¦–à§à¦²à¦¤à§‡ à¦à¦–à¦¾à¦¨à§‡ à¦•à§à¦²à¦¿à¦• à¦•à¦°à§à¦¨]({$baseUrl}/modules/paper_stock/index.php)";
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
                        $answer = "ðŸ“Š **Printing Plates Master Tool â€” Technical Specifications:**\n\nFound **{$sampleCount} matching plate record(s)** in your ERP database:\n\n";
                        foreach ($plateData as $idx => $row) {
                            $answer .= "â€¢ **Plate " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                                . "  - ðŸ“ **Repeat Value:** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                                . "  - ðŸ“ **Gap (Horizontal / Vertical):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                                . "  - ðŸ“ **Plate Size:** **" . ($row['size'] ?: 'N/A') . "** | **Ups:** **" . ($row['ups'] ?: '1') . "**\n"
                                . "  - ðŸ“„ **Paper Type & Size:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                                . "  - âš™ï¸ **Die & Cylinder:** **" . ($row['die'] ?: 'N/A') . "** | Cylinder: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                                . "  - ðŸ­ **Make By:** **" . ($row['make_by'] ?: 'N/A') . "** | Date Received: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                        }
                    } else {
                        $answer = "ðŸ“Š **à¦ªà§à¦°à¦¿à¦¨à§à¦Ÿà¦¿à¦‚ à¦ªà§à¦²à§‡à¦Ÿà§‡à¦° à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ à¦Ÿà§‡à¦•à¦¨à¦¿à¦•à§à¦¯à¦¾à¦² à¦¸à§à¦ªà§‡à¦¸à¦¿à¦«à¦¿à¦•à§‡à¦¶à¦¨:**\n\nà¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **{$sampleCount}à¦Ÿà¦¿ à¦®à§à¦¯à¦¾à¦šà¦¿à¦‚ à¦ªà§à¦²à§‡à¦Ÿ** à¦ªà¦¾à¦“à§Ÿà¦¾ à¦—à§‡à¦›à§‡:\n\n";
                        foreach ($plateData as $idx => $row) {
                            $answer .= "â€¢ **à¦ªà§à¦²à§‡à¦Ÿ " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                                . "  - ðŸ“ **à¦°à¦¿à¦ªà¦¿à¦Ÿ à¦­à§à¦¯à¦¾à¦²à§ (Repeat Value):** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                                . "  - ðŸ“ **à¦—à§à¦¯à¦¾à¦ª (Gap H / Gap V):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                                . "  - ðŸ“ **à¦ªà§à¦²à§‡à¦Ÿ à¦¸à¦¾à¦‡à¦œ:** **" . ($row['size'] ?: 'N/A') . "** | **à¦†à¦«à¦¸ (Ups):** **" . ($row['ups'] ?: '1') . "**\n"
                                . "  - ðŸ“„ **à¦•à¦¾à¦—à¦œà§‡à¦° à¦Ÿà¦¾à¦‡à¦ª à¦“ à¦¸à¦¾à¦‡à¦œ:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                                . "  - âš™ï¸ **à¦¡à¦¾à¦‡ à¦“ à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦°:** **" . ($row['die'] ?: 'N/A') . "** | à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦°: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                                . "  - ðŸ­ **à¦®à§‡à¦•à¦¾à¦°:** **" . ($row['make_by'] ?: 'N/A') . "** | à¦à¦¨à§à¦Ÿà§à¦°à¦¿ à¦¤à¦¾à¦°à¦¿à¦–: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
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
                    $directAnswer = "âŒ **No Job Found Matching \"{$jobSearchTerm}\"**\n\n"
                        . "I searched your ERP Planning Board and Job Cards database, but no active job matching **\"{$jobSearchTerm}\"** was found.\n\n"
                        . "ðŸ’¡ **Tip:** Please check if the job name or Job Card number is spelled correctly.";
            } elseif ($userLang === 'Hindi') {
                $directAnswer = "âŒ **\"{$jobSearchTerm}\" à¤¨à¤¾à¤® à¤•à¤¾ à¤•à¥‹à¤ˆ à¤œà¥‰à¤¬ à¤¨à¤¹à¥€à¤‚ à¤®à¤¿à¤²à¤¾**\n\n"
                    . "à¤†à¤ªà¤•à¥‡ à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾à¤¬à¥‡à¤¸ à¤®à¥‡à¤‚ **\"{$jobSearchTerm}\"** à¤•à¤¾ à¤•à¥‹à¤ˆ à¤œà¥‰à¤¬ à¤¯à¤¾ à¤œà¥‰à¤¬ à¤•à¤¾à¤°à¥à¤¡ à¤¦à¤°à¥à¤œ à¤¨à¤¹à¥€à¤‚ à¤¹à¥ˆà¥¤\n\n"
                    . "ðŸ’¡ **à¤Ÿà¤¿à¤ª:** à¤•à¥ƒà¤ªà¤¯à¤¾ à¤œà¤¾à¤‚à¤šà¥‡à¤‚ à¤•à¤¿ à¤œà¥‰à¤¬ à¤•à¤¾ à¤¨à¤¾à¤® à¤¯à¤¾ à¤œà¥‰à¤¬ à¤•à¤¾à¤°à¥à¤¡ à¤¨à¤‚à¤¬à¤° à¤¸à¤¹à¥€ à¤¹à¥ˆ à¤¯à¤¾ à¤¨à¤¹à¥€à¤‚à¥¤";
            } else {
                $directAnswer = "âŒ **\"{$jobSearchTerm}\" à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦œà¦¬ à¦ªà¦¾à¦“à¦¯à¦¼à¦¾ à¦¯à¦¾à¦¯à¦¼à¦¨à¦¿**\n\n"
                    . "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **\"{$jobSearchTerm}\"** à¦¨à¦¾à¦®à§‡ à¦•à§‹à¦¨à§‹ à¦¸à¦šà¦² à¦œà¦¬ à¦¬à¦¾ à¦œà¦¬ à¦•à¦¾à¦°à§à¦¡ à¦¨à¦¿à¦¬à¦¨à§à¦§à¦¿à¦¤ à¦¨à§‡à¦‡à¥¤\n\n"
                    . "ðŸ’¡ **à¦ªà¦°à¦¾à¦®à¦°à§à¦¶:** à¦…à¦¨à§à¦—à§à¦°à¦¹ à¦•à¦°à§‡ à¦œà¦¬ à¦à¦° à¦¨à¦¾à¦® à¦¬à¦¾ à¦œà¦¬ à¦•à¦¾à¦°à§à¦¡ à¦¨à¦®à§à¦¬à¦°à¦Ÿà¦¿ à¦¸à¦ à¦¿à¦• à¦°à¦¯à¦¼à§‡à¦›à§‡ à¦•à¦¿à¦¨à¦¾ à¦šà§‡à¦• à¦•à¦°à§à¦¨à¥¤";
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
                    $answer = "ðŸ“Š **Printing Plates Master Tool â€” Technical Specifications:**\n\nFound **{$sampleCount} matching plate record(s)** in your ERP database:\n\n";
                    foreach ($plateData as $idx => $row) {
                        $answer .= "â€¢ **Plate " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                            . "  - ðŸ“ **Repeat Value:** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                            . "  - ðŸ“ **Gap (Horizontal / Vertical):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                            . "  - ðŸ“ **Plate Size:** **" . ($row['size'] ?: 'N/A') . "** | **Ups:** **" . ($row['ups'] ?: '1') . "**\n"
                            . "  - ðŸ“„ **Paper Type & Size:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                            . "  - âš™ï¸ **Die & Cylinder:** **" . ($row['die'] ?: 'N/A') . "** | Cylinder: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                            . "  - ðŸ­ **Make By:** **" . ($row['make_by'] ?: 'N/A') . "** | Date Received: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
                    }
                } else {
                    $answer = "ðŸ“Š **à¦ªà§à¦°à¦¿à¦¨à§à¦Ÿà¦¿à¦‚ à¦ªà§à¦²à§‡à¦Ÿà§‡à¦° à¦¬à¦¿à¦¸à§à¦¤à¦¾à¦°à¦¿à¦¤ à¦Ÿà§‡à¦•à¦¨à¦¿à¦•à§à¦¯à¦¾à¦² à¦¸à§à¦ªà§‡à¦¸à¦¿à¦«à¦¿à¦•à§‡à¦¶à¦¨:**\n\nà¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ **{$sampleCount}à¦Ÿà¦¿ à¦®à§à¦¯à¦¾à¦šà¦¿à¦‚ à¦ªà§à¦²à§‡à¦Ÿ** à¦ªà¦¾à¦“à§Ÿà¦¾ à¦—à§‡à¦›à§‡:\n\n";
                    foreach ($plateData as $idx => $row) {
                        $answer .= "â€¢ **à¦ªà§à¦²à§‡à¦Ÿ " . ($idx + 1) . ": `" . ($row['name'] ?: 'Barcode Die') . "`** (SL No: **{$row['sl_no']}** | ID: **{$row['id']}**)\n"
                            . "  - ðŸ“ **à¦°à¦¿à¦ªà¦¿à¦Ÿ à¦­à§à¦¯à¦¾à¦²à§ (Repeat Value):** **" . ($row['repeat_value'] ?: 'N/A') . "**\n"
                            . "  - ðŸ“ **à¦—à§à¦¯à¦¾à¦ª (Gap H / Gap V):** **Gap H: " . ($row['gap_h'] ?: '0') . "** | **Gap V: " . ($row['gap_v'] ?: '0') . "**\n"
                            . "  - ðŸ“ **à¦ªà§à¦²à§‡à¦Ÿ à¦¸à¦¾à¦‡à¦œ:** **" . ($row['size'] ?: 'N/A') . "** | **à¦†à¦«à¦¸ (Ups):** **" . ($row['ups'] ?: '1') . "**\n"
                            . "  - ðŸ“„ **à¦•à¦¾à¦—à¦œà§‡à¦° à¦Ÿà¦¾à¦‡à¦ª à¦“ à¦¸à¦¾à¦‡à¦œ:** **" . ($row['paper_type'] ?: 'N/A') . "** (" . ($row['paper_size'] ?: 'N/A') . ")\n"
                            . "  - âš™ï¸ **à¦¡à¦¾à¦‡ à¦“ à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦°:** **" . ($row['die'] ?: 'N/A') . "** | à¦¸à¦¿à¦²à¦¿à¦¨à§à¦¡à¦¾à¦°: **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                            . "  - ðŸ­ **à¦®à§‡à¦•à¦¾à¦°:** **" . ($row['make_by'] ?: 'N/A') . "** | à¦à¦¨à§à¦Ÿà§à¦°à¦¿ à¦¤à¦¾à¦°à¦¿à¦–: **" . ($row['date_received'] ?: 'N/A') . "**\n\n";
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
        $finalAnswer = "ðŸ“Š **Job Planning Board & Department Statuses:**\n\nFound **{$totalCount} Active Jobs** on the Planning Board:\n\n";
        foreach ($dbData as $idx => $item) {
            $finalAnswer .= "â€¢ **Job " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | Priority: **{$item['priority']}** | Board Status: **{$item['status']}**\n";
            if (!empty($item['departments'])) {
                $finalAnswer .= "  - ðŸ­ **Departmental Progress:**\n";
                foreach ($item['departments'] as $d) {
                    $finalAnswer .= "    â–¸ **" . strtoupper(str_replace('_', ' ', $d['department'])) . "** (Job Card: `{$d['job_no']}`): **{$d['status']}**\n";
                }
            } else {
                $finalAnswer .= "  - â„¹ï¸ *No departmental job cards assigned yet.*\n";
            }
            $finalAnswer .= "\n";
        }
    } elseif ($userLang === 'Hindi') {
        $finalAnswer = "ðŸ“Š **à¤œà¥‰à¤¬ à¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤— à¤¬à¥‹à¤°à¥à¤¡ à¤”à¤° à¤µà¤¿à¤­à¤¾à¤— à¤¸à¥à¤¥à¤¿à¤¤à¤¿:**\n\nà¤ªà¥à¤²à¤¾à¤¨à¤¿à¤‚à¤— à¤¬à¥‹à¤°à¥à¤¡ à¤ªà¤° à¤•à¥à¤² **{$totalCount} à¤¸à¤•à¥à¤°à¤¿à¤¯ à¤œà¥‰à¤¬** à¤‰à¤ªà¤²à¤¬à¥à¤§ à¤¹à¥ˆà¤‚:\n\n";
        foreach ($dbData as $idx => $item) {
            $finalAnswer .= "â€¢ **Job " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | à¤ªà¥à¤°à¤¾à¤¥à¤®à¤¿à¤•à¤¤à¤¾: **{$item['priority']}** | à¤¬à¥‹à¤°à¥à¤¡ à¤¸à¥à¤¥à¤¿à¤¤à¤¿: **{$item['status']}**\n";
            if (!empty($item['departments'])) {
                $finalAnswer .= "  - ðŸ­ **à¤µà¤¿à¤­à¤¾à¤—à¥€à¤¯ à¤¸à¥à¤¥à¤¿à¤¤à¤¿ (Department Progress):**\n";
                foreach ($item['departments'] as $d) {
                    $finalAnswer .= "    â–¸ **" . strtoupper(str_replace('_', ' ', $d['department'])) . "** (Job Card: `{$d['job_no']}`): **{$d['status']}**\n";
                }
            }
            $finalAnswer .= "\n";
        }
    } else {
        // Bengali
        $finalAnswer = "ðŸ“Š **à¦ªà§à¦²à§à¦¯à¦¾à¦¨à¦¿à¦‚ à¦¬à§‹à¦°à§à¦¡ à¦à¦¬à¦‚ à¦¡à¦¿à¦ªà¦¾à¦°à§à¦Ÿà¦®à§‡à¦¨à§à¦Ÿà¦­à¦¿à¦¤à§à¦¤à¦¿à¦• à¦œà¦¬ à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸:**\n\nà¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦ªà§à¦²à§à¦¯à¦¾à¦¨à¦¿à¦‚ à¦¬à§‹à¦°à§à¦¡à§‡ à¦®à§‹à¦Ÿ **{$totalCount}à¦Ÿà¦¿ à¦œà¦¬** à¦ªà§à¦°à¦¸à§à¦¤à§à¦¤ à¦°à§Ÿà§‡à¦›à§‡:\n\n";
        foreach ($dbData as $idx => $item) {
            $finalAnswer .= "â€¢ **à¦œà¦¬ " . ($idx + 1) . ": {$item['job_no']}** | **{$item['job_name']}** | à¦ªà§à¦°à¦¾à¦¥à¦®à¦¿à¦•à¦¤à¦¾: **{$item['priority']}** | à¦¬à§‹à¦°à§à¦¡ à¦¸à§à¦Ÿà§à¦¯à¦¾à¦Ÿà¦¾à¦¸: **{$item['status']}**\n";
            if (!empty($item['departments'])) {
                $finalAnswer .= "  - ðŸ­ **à¦¡à¦¿à¦ªà¦¾à¦°à§à¦Ÿà¦®à§‡à¦¨à§à¦Ÿà¦­à¦¿à¦¤à§à¦¤à¦¿à¦• à¦ªà§à¦°à§‹à¦—à§à¦°à§‡à¦¸ (Department Status):**\n";
                foreach ($item['departments'] as $d) {
                    $finalAnswer .= "    â–¸ **" . strtoupper(str_replace('_', ' ', $d['department'])) . "** (à¦œà¦¬ à¦•à¦¾à¦°à§à¦¡: `{$d['job_no']}`): **{$d['status']}**\n";
                }
            } else {
                $finalAnswer .= "  - â„¹ï¸ *à¦à¦–à¦¨à§‹ à¦¡à¦¿à¦ªà¦¾à¦°à§à¦Ÿà¦®à§‡à¦¨à§à¦Ÿà§‡ à¦ªà§à¦°à¦¸à§‡à¦¸ à¦¶à§à¦°à§ à¦¹à§Ÿà¦¨à¦¿à¥¤*\n";
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
            $finalAnswer = "ðŸ¤” **I don't have knowledge regarding this topic.**\n\n"
                . "I searched my trained knowledge base and ERP data, but couldn't find an answer to: **\"" . htmlspecialchars($prompt) . "\"**.\n\n"
                . "ðŸ’¡ **Admin Tip:** You can train me in **Settings â†’ AI Agent â†’ Knowledge Base** by clicking **\"+ Add New Entry\"** with keywords and an answer.\n\n"
                . "ðŸ‘¨â€ðŸ’¼ **For help, please contact your ERP Administrator.**";
        } elseif ($userLang === 'Hindi') {
            $finalAnswer = "ðŸ¤” **à¤®à¥à¤à¥‡ à¤‡à¤¸ à¤µà¤¿à¤·à¤¯ à¤•à¥‡ à¤¬à¤¾à¤°à¥‡ à¤®à¥‡à¤‚ à¤•à¥‹à¤ˆ à¤œà¤¾à¤¨à¤•à¤¾à¤°à¥€ à¤¨à¤¹à¥€à¤‚ à¤¹à¥ˆà¥¤**\n\n"
                . "à¤®à¥ˆà¤‚à¤¨à¥‡ à¤…à¤ªà¤¨à¥‡ à¤Ÿà¥à¤°à¥‡à¤¨à¥à¤¡ à¤¨à¥‰à¤²à¥‡à¤œ à¤¬à¥‡à¤¸ à¤”à¤° à¤ˆà¤†à¤°à¤ªà¥€ à¤¡à¥‡à¤Ÿà¤¾ à¤®à¥‡à¤‚ à¤–à¥‹à¤œà¤¾, à¤²à¥‡à¤•à¤¿à¤¨ à¤†à¤ªà¤•à¥€ à¤•à¥à¤µà¥‡à¤°à¥€: **\"" . htmlspecialchars($prompt) . "\"** à¤•à¤¾ à¤‰à¤¤à¥à¤¤à¤° à¤¨à¤¹à¥€à¤‚ à¤®à¤¿à¤²à¤¾à¥¤\n\n"
                . "ðŸ’¡ **à¤à¤¡à¤®à¤¿à¤¨ à¤Ÿà¤¿à¤ª:** à¤†à¤ª **Settings â†’ AI Agent â†’ Knowledge Base** à¤®à¥‡à¤‚ à¤œà¤¾à¤•à¤° **\"+ Add New Entry\"** à¤¸à¥‡ à¤®à¥à¤à¥‡ à¤Ÿà¥à¤°à¥‡à¤¨ à¤•à¤° à¤¸à¤•à¤¤à¥‡ à¤¹à¥ˆà¤‚à¥¤\n\n"
                . "ðŸ‘¨â€ðŸ’¼ **à¤•à¥ƒà¤ªà¤¯à¤¾ à¤¸à¤¹à¤¾à¤¯à¤¤à¤¾ à¤•à¥‡ à¤²à¤¿à¤ à¤…à¤ªà¤¨à¥‡ ERP à¤à¤¡à¤®à¤¿à¤¨à¤¿à¤¸à¥à¤Ÿà¥à¤°à¥‡à¤Ÿà¤° à¤¸à¥‡ à¤¸à¤‚à¤ªà¤°à¥à¤• à¤•à¤°à¥‡à¤‚à¥¤**";
        } else {
            $finalAnswer = "ðŸ¤” **à¦à¦‡ à¦¬à¦¿à¦·à¦¯à¦¼à§‡ à¦†à¦®à¦¾à¦° à¦•à§‹à¦¨à§‹ à¦œà§à¦žà¦¾à¦¨ à¦¨à§‡à¦‡à¥¤**\n\n"
                . "à¦†à¦®à¦¿ à¦†à¦®à¦¾à¦° à¦Ÿà§à¦°à§‡à¦‡à¦¨à¦¡ à¦¨à¦²à§‡à¦œ à¦¬à§‡à¦¸ à¦à¦¬à¦‚ à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à§‡à¦Ÿà¦¾à¦¤à§‡ à¦–à§à¦à¦œà§‡à¦›à¦¿, à¦•à¦¿à¦¨à§à¦¤à§ à¦†à¦ªà¦¨à¦¾à¦° à¦ªà§à¦°à¦¶à§à¦¨: **\"" . htmlspecialchars($prompt) . "\"** à¦à¦° à¦‰à¦¤à§à¦¤à¦° à¦ªà¦¾à¦‡à¦¨à¦¿à¥¤\n\n"
                . "ðŸ’¡ **à¦à¦¡à¦®à¦¿à¦¨ à¦ªà¦°à¦¾à¦®à¦°à§à¦¶:** à¦†à¦ªà¦¨à¦¿ **Settings â†’ AI Agent â†’ Knowledge Base** à¦ à¦—à¦¿à¦¯à¦¼à§‡ **\"+ Add New Entry\"** à¦•à§à¦²à¦¿à¦• à¦•à¦°à§‡ à¦•à§€à¦“à¦¯à¦¼à¦¾à¦°à§à¦¡ à¦“ à¦‰à¦¤à§à¦¤à¦° à¦¯à§‹à¦— à¦•à¦°à§‡ à¦†à¦®à¦¾à¦•à§‡ à¦Ÿà§à¦°à§‡à¦‡à¦¨ à¦•à¦°à¦¤à§‡ à¦ªà¦¾à¦°à§‡à¦¨à¥¤\n\n"
                . "ðŸ‘¨â€ðŸ’¼ **à¦¸à¦¾à¦¹à¦¾à¦¯à§à¦¯à§‡à¦° à¦œà¦¨à§à¦¯ à¦†à¦ªà¦¨à¦¾à¦° ERP à¦…à§à¦¯à¦¾à¦¡à¦®à¦¿à¦¨à¦¿à¦¸à§à¦Ÿà§à¦°à§‡à¦Ÿà¦°à§‡à¦° à¦¸à¦¾à¦¥à§‡ à¦¯à§‹à¦—à¦¾à¦¯à§‹à¦— à¦•à¦°à§à¦¨à¥¤**";
        }
    }
} else {

    if ($userLang === 'English') {
        $finalAnswer = "ðŸ“Š **{$toolUsed} Results:**\n\n";
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
                    $finalAnswer .= "â€¢ **" . str_replace('_', ' ', strtoupper($k)) . ":** {$v}\n";
                }
            }
        } elseif ($toolUsed === 'Printing Plates Master Tool') {
            $finalAnswer = "ðŸ“Š **Printing Plates â€” Found {$sampleCount} plates:**\n\n" . format_records_table($dbData, 'plate', $userLang);
        } else {
            $finalAnswer .= "Total **{$totalCount} records** in your ERP database.\n\n";
            if ($sampleCount > 0) {
                $finalAnswer .= format_records_table($dbData, 'generic', 'English');
            }
        }
    } else {

        $finalAnswer = "ðŸ“Š **{$toolUsed} à¦«à¦²à¦¾à¦«à¦²:**\n\n";
        if ($isCompanyList) {
            $finalAnswer .= "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¸à§à¦Ÿà¦•à§‡ à¦®à§‹à¦Ÿ **{$totalCount}à¦Ÿà¦¿ à¦ªà§‡à¦ªà¦¾à¦° à¦•à§‹à¦®à§à¦ªà¦¾à¦¨à¦¿/à¦¸à¦¾à¦ªà§à¦²à¦¾à¦¯à¦¼à¦¾à¦°à§‡à¦°** à¦•à¦¾à¦—à¦œ à¦°à¦¯à¦¼à§‡à¦›à§‡:\n\n";
            foreach ($dbData as $idx => $row) {
                $finalAnswer .= ($idx + 1) . ". **{$row['company']}**: {$row['roll_count']}à¦Ÿà¦¿ à¦°à§‹à¦² (" . number_format((float) $row['total_meters'], 2) . " à¦®à¦¿à¦Ÿà¦¾à¦°)\n";
            }
        } elseif ($sampleCount === 1) {
            $finalAnswer .= "à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ à¦¨à¦¿à¦°à§à¦¦à¦¿à¦·à§à¦Ÿ à¦¸à¦šà¦² à¦°à§‡à¦•à¦°à§à¦¡à¦Ÿà¦¿ à¦ªà¦¾à¦“à¦¯à¦¼à¦¾ à¦—à§‡à¦›à§‡:\n\n";
            foreach ($dbData[0] as $k => $v) {
                if ($v !== null && $v !== '') {
                    $finalAnswer .= "â€¢ **" . str_replace('_', ' ', strtoupper($k)) . ":** {$v}\n";
                }
            }
        } else {
            $finalAnswer .= "à¦†à¦ªà¦¨à¦¾à¦° à¦‡à¦†à¦°à¦ªà¦¿ à¦¡à¦¾à¦Ÿà¦¾à¦¬à§‡à¦¸à§‡ à¦®à§‹à¦Ÿ **{$totalCount}à¦Ÿà¦¿ à¦°à§‡à¦•à¦°à§à¦¡** à¦¨à¦¿à¦¬à¦¨à§à¦§à¦¿à¦¤ à¦°à¦¯à¦¼à§‡à¦›à§‡à¥¤\n\n";
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