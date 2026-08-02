<?php
    $prompt = html_entity_decode(stripslashes($prompt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalize Bengali numerals (০-৯ → 0-9) so plate numbers, sizes & math extract correctly
    $prompt = str_replace(['০','১','২','৩','৪','৫','৬','৭','৮','৯'], ['0','1','2','3','4','5','6','7','8','9'], $prompt);
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
    if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:meter|meters|mtr|m(?!m)|\bমিটার\b|\bমিটারে\b)/i', $prompt, $m)) {
        $targetMeters = (float)str_replace(',', '', $m[1]);
    }
    
    $targetLabels = null;
    if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(lakh|লাখ|k|thousand|হাজার)?\s*(?:label|labels|pcs|piece|pieces|piss|qnty|qnt|qty|quantity|পিস|লেবেল)/i', $prompt, $m) || preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:lakh|k|thousand)/i', $prompt, $m)) {
        $val = (float)str_replace(',', '', $m[1]);
        $mult = strtolower(trim($m[2] ?? ''));
        if ($mult === 'lakh' || $mult === 'লাখ') $val *= 100000;
        elseif ($mult === 'k' || $mult === 'thousand' || $mult === 'হাজার') $val *= 1000;
        $targetLabels = $val;
    }

    // Extract Target Budget & Price per Sq Inch
    $targetBudget = null;
    $pricePerSqInch = null;

    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:taka|tk|টাকা|rs|rupee)?\s*(?:per|\/)\s*(?:sqr|sq|square|স্কয়ার)?\s*(?:inch|in|ইঞ্চি)/i', $prompt, $m)) {
        $pricePerSqInch = (float)$m[1];
    }
    
    if (preg_match_all('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:taka|tk|টাকা|taker|takar|টাকার)\b/i', $prompt, $m)) {
        foreach ($m[1] as $match) {
            $val = (float)str_replace(',', '', $match);
            if ($val != $pricePerSqInch) {
                $targetBudget = $val;
            }
        }
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
        if ($dieMatch === 'flatbed' || $dieMatch === 'fatbed' || $dieMatch === 'fat bed') $dieMatch = 'flat bed';
    }

    // Teeth / gearing intent
    $teethIntent = (strpos($p, 'দাঁত') !== false || strpos($p, 'teeth') !== false);

    // Color intents
    $moreThanFourColors = (preg_match('/more than 4|>4|4\+|4\s*টির বেশি|4\s*এর বেশি|৪\s*টির বেশি|৪\s*এর বেশি/i', $p) !== 0);

    // Latest / newest plate intent
    $latestPlateIntent = (strpos($p, 'latest') !== false || strpos($p, 'newest') !== false || strpos($p, 'সবচেয়ে নতুন') !== false || strpos($p, 'সর্বশেষ') !== false);

    // Export query
    $isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || (strpos($p, 'print') !== false && !strpos($p, 'koto') && !strpos($p, 'কত') && !strpos($p, 'required') && !strpos($p, 'need') && !strpos($p, 'how many')));

    // Find search terms loosely
    $pStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'plate', 'plates', 'list', 'show', 'details', 'detail', 'this', 'the', 'a', 'an', 'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does', 'repeat', 'gap', 'gaph', 'gapv', 'size', 'ups', 'cylinder', 'paper', 'die', 'core', 'rewinding', 'value', 'color', 'colors', 'spec', 'special', 'what', 'how', 'give', 'if', 'run', 'running', 'much', 'many', 'quantity', 'qty', 'meter', 'meters', 'mtr', 'will', 'be', 'produced', 'print', 'printing', 'require', 'required', 'need', 'needed', 'or', 'and', 'calculate', 'calculating', 'calc', 'length', 'roll', 'pcs', 'pieces', 'labels', 'koto', 'kotogulo', 'hobe', 'lagbe', 'korle', 'korte', 'asob', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'হবে', 'আছে', 'কত', 'কতগুলো', 'কি', 'কী', 'কোন', 'pdf', 'excel', 'export', 'download', 'sir', 'amra', 'ai', 'ta', 'korechi', 'kore', 'chi', 'hoyechhe', 'kobe', 'last', 'naki', 'ache', 'এর', 'ডিটেলস', 'দেখাও', 'dikhao', 'dekhaw', 'konta', 'কোনটা', 'dekhaben', 'janaben', 'jante', 'chai', 'লেবেলের', 'লেবেল', 'label', 'labels', 'প্লেট', 'প্লেটের', 'প্লট', 'প্লটের', 'qnty', 'qnt', 'quantity', 'quantities', 'piss', 'piece', 'pieces', 'পেপারে', 'পেপার', 'মিটার', 'মিটারে', 'প্রিন্টিং', 'প্রিন্ট', 'total', 'count', 'summary', 'dashboard', 'all', 'সব', 'সকল', 'din', 'দিন', 'দাও', 'dao', 'do', 'deu', 'dene', 'dena', 'dikhabe', 'send', 'পাঠাও', 'pathao', 'pathaben', 'no', 'number', 'num', 'nber', 'numb', 'id', 'নম্বর', 'নং', 'আইডি',
        // Added for plate-specific query handling
        'মোট', 'সর্বমোট', 'সিলিন্ডার', 'সিলিন্ডারের', 'সিলিন্ডারটা', 'রিপিট', 'রিপিটের', 'কালার', 'কালারের', 'কয়টি', 'কয়', 'দাঁত', 'দাঁতের', 'জবে', 'জবের', 'নতুন', 'সবচেয়ে', 'সর্বশেষ', 'লেটেস্ট', 'তৈরি', 'নামে', 'জন্য', 'সাইজ', 'পেপারসাইজ', 'পেপারের', 'কোনো', 'inch', 'ইঞ্চি', 'ব্যবহার', 'হয়', 'লাগে', 'প্লেটগুলো', 'প্লেটগুলোতে', 'প্লেটটি', 'বেশি', 'লাখ', 'পিস', 'হাজার', 'হাজারে', 'করে', 'করা', 'করার', 'করতে', 'থেকে', 'এটা', 'এটি', 'ডাই', 'টাইপ', 'type', 'যে', 'যেগুলো', 'যেগুলোতে', 'খুঁজে', 'দিয়েছি', 'দিয়ে', 'এরকম', 'তোমার', 'আমার', 'konsa', 'konsi', 'kaun', 'jis', 'jinke', 'লাগবে', 'প্রয়োজন', 'প্রয়োজনীয়', 'চাই', 'চাও', 'চান', 'we', 'our', 'us', 'are', 'were', 'was', 'has', 'had', 'have', 'do', 'does', 'did', 'will', 'would', 'can', 'could', 'should', 'shall', 'may', 'might', 'must', 'shall', 'আমি', 'তুমি', 'আপনি', 'সে', 'আমরা', 'আপনরা', 'তোমরা', 'সবাই'];
    
    $jobSearchExplicit = null;
    if (preg_match('/["“”]([^"“”]+)["“”]|\'([^\']+)\'/u', $prompt, $m)) {
        $jobSearchExplicit = trim($m[1] ?: $m[2]); if (preg_match('/[a-zA-Z]\d|\d[a-zA-Z]/', $jobSearchExplicit)) { $jobSearchExplicit = preg_replace('/([a-zA-Z])(\d)/', '$1 $2', $jobSearchExplicit); $jobSearchExplicit = preg_replace('/(\d)([a-zA-Z])/', '$1 $2', $jobSearchExplicit); }
    }

    $pWords = preg_split('/\s+/', $p);
    $pTerms = [];
    foreach ($pWords as $w) {
        $wC = trim(preg_replace('/[^\p{L}\p{N}\p{M}]/u', '', $w));
        if ($wC !== '' && !in_array($wC, $pStopwords, true) && mb_strlen($wC, 'UTF-8') >= 2) {
            if (is_numeric($wC)) {
                if ((string)$wC === (string)$repeatMatch || (string)$wC === (string)$cylinderMatch || (string)$wC === (string)$targetMeters || (string)$wC === (string)$targetLabels || (string)$wC === (string)$plateNoMatch || (string)$wC === (string)$paperSizeMatch || (string)$wC === (string)$targetBudget || (string)$wC === (string)$pricePerSqInch) {
                    continue;
                }
            }
            // Also skip words that contain a matched numeric value (e.g. "1524mm" from "152.4mm", or "1524" from "152.4")
            $skip = false;
            foreach ([$repeatMatch, $cylinderMatch, $targetMeters, $targetLabels, $plateNoMatch, $paperSizeMatch, $targetBudget, $pricePerSqInch] as $mv) {
                if ($mv !== null && $mv !== '') {
                    if (mb_strpos($wC, (string)$mv) !== false) { $skip = true; break; }
                    // Handle dot-stripped numbers: "1524" should match repeatMatch "152.4"
                    if (is_numeric($wC) && is_numeric($mv) && (float)$wC === (float)$mv) { $skip = true; break; }
                    // Handle dot-stripped: "1524" should match "152.4" (dot removed by preg_replace)
                    $mvNoDot = str_replace('.', '', (string)$mv);
                    if (is_numeric($wC) && is_numeric($mvNoDot) && (float)$wC === (float)$mvNoDot) { $skip = true; break; }
                    // Handle unit-suffixed numbers: "1524mm" should match repeatMatch "152.4"
                    $wNum = preg_replace('/[^\d.]/', '', $wC);
                    if ($wNum !== '' && is_numeric($wNum) && is_numeric($mv) && (float)$wNum === (float)$mv) { $skip = true; break; }
                    if ($wNum !== '' && is_numeric($wNum) && is_numeric($mvNoDot) && (float)$wNum === (float)$mvNoDot) { $skip = true; break; }
                }
            }
            if ($skip) continue;
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
    $renderPlate = function($row, $idx) use ($userLang, $targetMeters, $targetLabels, $targetBudget, $pricePerSqInch) {
        $rep = (float)($row['repeat_value'] ?? 0);
        $ups = (float)($row['ups'] ?? 1);
        if ($ups <= 0) $ups = 1;
        $repFmt = $rep > 0 ? rtrim(rtrim(number_format($rep, 3, '.', ''), '0'), '.') : '—';

        $colors = [];
        foreach(['c','m','y','k','special_1','special_2','special_3','special_4','special_5'] as $c) {
            $cv = trim((string)($row[$c] ?? ''));
            if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-') $colors[] = $cv;
        }
        $colorCount = count($colors);
        $colorDisp = $colorCount > 0 ? implode(', ', $colors) : '—';

        $clean = function($v) { $v = trim((string)$v); return ($v === '' || mb_strtolower($v, 'UTF-8') === 'na' || mb_strtolower($v, 'UTF-8') === 'n/a' || $v === '-') ? '—' : $v; };

        $gapH = trim((string)($row['gap_h'] ?? '')); $gapV = trim((string)($row['gap_v'] ?? ''));
        if ($gapH === '' || mb_strtolower($gapH, 'UTF-8') === 'na') $gapH = '—'; elseif (!preg_match('/mm$/i', $gapH)) $gapH .= 'mm';
        if ($gapV === '' || mb_strtolower($gapV, 'UTF-8') === 'na') $gapV = '—'; elseif (!preg_match('/mm$/i', $gapV)) $gapV .= 'mm';

        $out = "• **Plate " . $idx . ": `" . ($row['name'] ?: 'Job Name') . "`** (Plate: **" . $clean($row['plate']) . "** | SL No: **" . $clean($row['sl_no']) . "** | ID: **{$row['id']}**)\n"
            . "  - 📏 **Repeat:** **{$repFmt}mm** | **Ups:** **{$ups}** | ⚙️ **Cylinder:** **" . $clean($row['cylinder']) . "**\n"
            . "  - 📐 **Gaps:** **Gap H:** **{$gapH}** | **Gap V:** **{$gapV}**\n"
            . "  - 📄 **Paper Type:** **" . $clean($row['paper_type']) . "** | 🏭 **Make By:** **" . $clean($row['make_by']) . "**\n"
            . "  - 📏 **Size:** **" . $clean($row['size']) . "** | 📄 **Paper Size:** **" . $clean($row['paper_size']) . "** | ✂️ **Die:** **" . $clean($row['die']) . "**\n"
            . "  - 🎨 **Colors ({$colorCount}):** {$colorDisp}\n"
            . "  - 📅 **Date Received / Last Job:** **" . $clean($row['date_received']) . "**\n";

        if ($targetMeters > 0 && $rep > 0) {
            $calcLabels = floor(($targetMeters * 1000) / $rep * $ups);
            if ($userLang === 'Bengali') $out .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " মিটার** পেপারে **" . number_format($calcLabels) . " টি লেবেল** প্রিন্ট হবে।\n";
            elseif ($userLang === 'Hindi') $out .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " मीटर** पेपर में **" . number_format($calcLabels) . " लेबेल** प्रिंट होंगे।\n";
            else $out .= "  - 🧮 **Calculation:** A **" . number_format($targetMeters) . "m** roll will yield **" . number_format($calcLabels) . " labels**.\n";
        }
        if ($targetLabels > 0 && $rep > 0) {
            $reqMeters = ceil(($targetLabels / $ups) * $rep / 1000);
            if ($userLang === 'Bengali') $out .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " টি লেবেল** প্রিন্ট করতে **" . number_format($reqMeters) . " মিটার** পেপার লাগবে।\n";
            elseif ($userLang === 'Hindi') $out .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " लेबेल** प्रिंट करने के लिए **" . number_format($reqMeters) . " मीटर** पेपर लगेगा।\n";
            else $out .= "  - 🧮 **Calculation:** To print **" . number_format($targetLabels) . " labels**, you need **" . number_format($reqMeters) . " meters** of paper.\n";
        }
        if ($targetBudget > 0 && $pricePerSqInch > 0 && $rep > 0 && (float)$row['paper_size'] > 0) {
            $paperSize = (float)$row['paper_size'];
            // 1 inch = 25.4 mm -> 1 sq inch = 645.16 sq mm
            $sqMmPerSqInch = 645.16;
            
            // Area of one repeat in sq mm
            $repeatAreaSqMm = $rep * $paperSize;
            
            // Area per label (including waste/gaps) in sq mm
            $areaPerLabelSqMm = $repeatAreaSqMm / $ups;
            
            // Area per label in sq inches
            $areaPerLabelSqInch = $areaPerLabelSqMm / $sqMmPerSqInch;
            
            // Price per label
            $pricePerLabel = $areaPerLabelSqInch * $pricePerSqInch;
            
            // Total labels needed for the target budget
            $calcLabels = ceil($targetBudget / $pricePerLabel);
            
            // Required running meters
            $reqMeters = ceil(($calcLabels / $ups) * $rep / 1000);
            
            // Required paper in square meters
            $reqSqMeters = $reqMeters * ($paperSize / 1000);

            if ($userLang === 'Bengali') {
                $out .= "  - 💰 **Job Calculation:** **" . number_format($targetBudget, 2) . " টাকা** বাজেটে (**" . $pricePerSqInch . " টাকা/sq inch** রেটে):\n";
                $out .= "      ▸ **" . number_format($calcLabels) . " টি লেবেল** প্রিন্ট করতে হবে।\n";
                $out .= "      ▸ **" . number_format($reqMeters) . " রানিং মিটার (Running Meter)** পেপার লাগবে।\n";
                $out .= "      ▸ **" . number_format($reqSqMeters, 2) . " বর্গমিটার (Square Meter)** পেপার লাগবে।\n";
            } elseif ($userLang === 'Hindi') {
                $out .= "  - 💰 **Job Calculation:** **" . number_format($targetBudget, 2) . " रुपये** बजट में (**" . $pricePerSqInch . " रुपये/sq inch** रेट पर):\n";
                $out .= "      ▸ **" . number_format($calcLabels) . " लेबेल** प्रिंट करने होंगे।\n";
                $out .= "      ▸ **" . number_format($reqMeters) . " रनिंग मीटर (Running Meter)** पेपर लगेगा।\n";
                $out .= "      ▸ **" . number_format($reqSqMeters, 2) . " स्क्वायर मीटर (Square Meter)** पेपर लगेगा।\n";
            } else {
                $out .= "  - 💰 **Job Calculation:** For a **" . number_format($targetBudget, 2) . " Tk** job (at **" . $pricePerSqInch . " Tk/sq inch**):\n";
                $out .= "      ▸ **" . number_format($calcLabels) . " Labels** need to be printed.\n";
                $out .= "      ▸ **" . number_format($reqMeters) . " Running Meters** of paper required.\n";
                $out .= "      ▸ **" . number_format($reqSqMeters, 2) . " Square Meters** of paper required.\n";
            }
        }
        if ($targetMeters == null && $targetLabels == null && $targetBudget == null && $rep > 0) {
            $teeth = round($rep / 3.175, 2);
            $out .= "  - ⚙️ **Gearing (1/8 CP):** ~**{$teeth} Teeth** Cylinder\n";
        }
        $out .= "\n";
        return $out;
    };

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
                    for ($i = 0; $i < 12; $i++) $params[] = $l;
                    $typesStr .= 'ssssssssssss';
                }
            } else {
                $where[] = "(" . $searchCols . ")";
                $l = '%' . $jobSearchExplicit . '%';
                for ($i = 0; $i < 12; $i++) $params[] = $l;
                $typesStr .= 'ssssssssssss';
            }
        } else {
            foreach ($pTerms as $term) {
                $where[] = "(" . $searchCols . ")";
                $l = '%' . $term . '%';
                for ($i = 0; $i < 12; $i++) $params[] = $l;
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
                foreach ($dieStats as $ds) $answer .= " ▸ **" . ($ds['die'] ?: 'অন্যান্য') . "** — {$ds['cnt']}টি\n";
            }
            if (!empty($paperStats)) {
                $answer .= "\n📄 **পেপার টাইপ:**\n";
                foreach ($paperStats as $ps) $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']}টি\n";
            }
            if (!empty($makerStats)) {
                $answer .= "\n🏭 **নির্মাতা (Maker):**\n";
                foreach ($makerStats as $ms) $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']}টি\n";
            }
        } elseif ($userLang === 'Hindi') {
            $answer = "📊 **प्रिंटिंग प्लेट डैशबोर्ड**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **कुल प्लेट:** **{$totalCount}**\n\n";
            if (!empty($dieStats)) {
                $answer .= "📐 **प्रकार (Die Type):**\n";
                foreach ($dieStats as $ds) $answer .= " ▸ **" . ($ds['die'] ?: 'अन्य') . "** — {$ds['cnt']}\n";
            }
            if (!empty($paperStats)) {
                $answer .= "\n📄 **पेपर प्रकार:**\n";
                foreach ($paperStats as $ps) $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']}\n";
            }
            if (!empty($makerStats)) {
                $answer .= "\n🏭 **निर्माता (Maker):**\n";
                foreach ($makerStats as $ms) $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']}\n";
            }
        } else {
            $answer = "📊 **Printing Plates Dashboard**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **Total Plates:** **{$totalCount}**\n\n";
            if (!empty($dieStats)) {
                $answer .= "📐 **By Die Type:**\n";
                foreach ($dieStats as $ds) $answer .= " ▸ **" . ($ds['die'] ?: 'Other') . "** — {$ds['cnt']} plates\n";
            }
            if (!empty($paperStats)) {
                $answer .= "\n📄 **By Paper Type:**\n";
                foreach ($paperStats as $ps) $answer .= " ▸ **{$ps['paper_type']}** — {$ps['cnt']} plates\n";
            }
            if (!empty($makerStats)) {
                $answer .= "\n🏭 **By Maker:**\n";
                foreach ($makerStats as $ms) $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']} plates\n";
            }
        }
        $answer .= "\n👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
        return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
    }

    // Latest / newest plate
    if ($latestPlateIntent && !$plateNoMatch && !$jobSearchTerm && !$cylinderMatch && !$repeatMatch && !$paperSizeMatch && !$dieMatch) {
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
    if ($teethIntent && $repeatMatch && (float)$repeatMatch > 0 && !$plateNoMatch && !$cylinderMatch && !$paperSizeMatch && !$dieMatch && !$jobSearchTerm) {
        $repVal = (float)$repeatMatch;
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
    if (!empty($params)) $stmt->bind_param($typesStr, ...$params);
    $stmt->execute();
    $plateData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    // Filter: plates with more than 4 colors (exclude empty / NA)
    if ($moreThanFourColors && !empty($plateData)) {
        $filtered = [];
        foreach ($plateData as $row) {
            $cnt = 0;
            foreach(['c','m','y','k','special_1','special_2','special_3','special_4','special_5'] as $c) {
                $cv = trim((string)($row[$c] ?? ''));
                if ($cv !== '' && mb_strtolower($cv, 'UTF-8') !== 'na' && mb_strtolower($cv, 'UTF-8') !== 'n/a' && $cv !== '-') $cnt++;
            }
            if ($cnt > 4) $filtered[] = $row;
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
        $sampleCount = count($plateData);
        
        if ($userLang === 'Bengali') {
            $answer = "📐 **Plate Management & Math Engine:**\n\nআপনার ডাটাবেসে **{$sampleCount}টি ম্যাচিং প্লেট** পাওয়া গেছে:\n\n";
        } elseif ($userLang === 'Hindi') {
            $answer = "📐 **Plate Management & Math Engine:**\n\nआपके डेटाबेस में **{$sampleCount} मैचिंग प्लेट** मिले हैं:\n\n";
        } else {
            $answer = "📐 **Plate Management & Math Engine:**\n\nFound **{$sampleCount} matching plate(s)** in your database:\n\n";
        }

        foreach ($plateData as $idx => $row) {
            $answer .= $renderPlate($row, $idx + 1);
        }
        
        $answer .= "👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
        
        return ['tool_used' => $toolName, 'total_count' => count($plateData), 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
    }
