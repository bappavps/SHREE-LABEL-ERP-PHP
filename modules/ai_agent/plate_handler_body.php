<?php
    $prompt = html_entity_decode(stripslashes($prompt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $toolName = 'Printing Plates Master Tool';
    
    // Check if it's a count/dashboard request
    $isCountIntent = (strpos($p, 'how many') !== false || strpos($p, 'total') !== false || strpos($p, 'count') !== false || strpos($p, 'কতগুলো') !== false || strpos($p, 'kitne') !== false || strpos($p, 'summary') !== false || strpos($p, 'dashboard') !== false || strpos($p, 'কত') !== false || strpos($p, 'koto') !== false || strpos($p, 'সব') !== false || strpos($p, 'সকল') !== false || strpos($p, 'all') !== false) && !preg_match('/\b(run|paper|meter|mtr|qty|quantity|pcs)\b/i', $p);
    
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

    // Extract Cylinder
    $cylinderMatch = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:cylinder|সিলিন্ডার|t|teeth|teth)/i', $prompt, $m)) {
        $cylinderMatch = trim($m[1]);
    }

    // Extract Repeat
    $repeatMatch = null;
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:mm\s*)?(?:repeat|রিপিট)/i', $prompt, $m) || preg_match('/(?:repeat|রিপিট)\s*(\d+(?:\.\d+)?)/i', $prompt, $m)) {
        $repeatMatch = trim($m[1]);
    }

    // Extract Target Meters & Labels for Math
    $targetMeters = null;
    if (preg_match('/(\d+(?:,\d+)*(?:\.\d+)?)\s*(?:meter|meters|mtr|m|মিটার|মিটারে)/i', $prompt, $m)) {
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

    // Export query
    $isExportQuery = (strpos($p, 'pdf') !== false || strpos($p, 'excel') !== false || strpos($p, 'csv') !== false || strpos($p, 'export') !== false || strpos($p, 'report') !== false || strpos($p, 'print') !== false && !strpos($p, 'koto') && !strpos($p, 'কত'));
    
    // Find search terms loosely
    $pStopwords = ['can', 'you', 'tell', 'me', 'which', 'is', 'in', 'my', 'plate', 'plates', 'list', 'show', 'details', 'detail', 'this', 'the', 'a', 'an', 'job', 'jobs', 'for', 'about', 'get', 'search', 'find', 'there', 'any', 'named', 'by', 'name', 'of', 'with', 'are', 'do', 'have', 'exist', 'does', 'repeat', 'gap', 'gaph', 'gapv', 'size', 'ups', 'cylinder', 'paper', 'die', 'core', 'rewinding', 'value', 'color', 'colors', 'spec', 'special', 'what', 'how', 'give', 'if', 'run', 'running', 'much', 'many', 'quantity', 'qty', 'meter', 'meters', 'mtr', 'will', 'be', 'produced', 'print', 'printing', 'require', 'required', 'need', 'needed', 'or', 'and', 'calculate', 'calculating', 'calc', 'length', 'roll', 'pcs', 'pieces', 'labels', 'koto', 'kotogulo', 'hobe', 'lagbe', 'korle', 'korte', 'asob', 'ar', 'er', 'diye', 'giye', 'ache', 'hobe', 'হবে', 'আছে', 'কত', 'কতগুলো', 'কি', 'কী', 'কোন', 'pdf', 'excel', 'export', 'download', 'sir', 'amra', 'ai', 'ta', 'korechi', 'kore', 'chi', 'hoyechhe', 'kobe', 'last', 'naki', 'ache', 'এর', 'ডিটেলস', 'দেখাও', 'dikhao', 'dekhaw', 'konta', 'কোনটা', 'dekhaben', 'janaben', 'jante', 'chai', 'লেবেলের', 'লেবেল', 'label', 'labels', 'প্লেট', 'প্লেটের', 'প্লট', 'প্লটের', 'qnty', 'qnt', 'quantity', 'quantities', 'piss', 'piece', 'pieces', 'পেপারে', 'পেপার', 'মিটার', 'মিটারে', 'প্রিন্টিং', 'প্রিন্ট', 'total', 'count', 'summary', 'dashboard', 'all', 'সব', 'সকল', 'din', 'দিন', 'দাও', 'dao', 'do', 'deu', 'dene', 'dena', 'dikhabe', 'send', 'পাঠাও', 'pathao', 'pathaben', 'no', 'number', 'num', 'nber', 'numb', 'id', 'নম্বর', 'নং', 'আইডি'];
    
    $jobSearchExplicit = null;
    if (preg_match('/["“”]([^"“”]+)["“”]|\'([^\']+)\'/u', $prompt, $m)) {
        $jobSearchExplicit = trim($m[1] ?: $m[2]);
    }

    $pWords = preg_split('/\s+/', mb_strtolower($prompt, 'UTF-8'));
    $pTerms = [];
    foreach ($pWords as $w) {
        $wC = trim(preg_replace('/[^\p{L}\p{N}\p{M}]/u', '', $w));
        if ($wC !== '' && !in_array($wC, $pStopwords, true) && mb_strlen($wC, 'UTF-8') >= 2) {
            if (is_numeric($wC)) {
                if ((string)$wC === (string)$repeatMatch || (string)$wC === (string)$cylinderMatch || (string)$wC === (string)$targetMeters || (string)$wC === (string)$targetLabels || (string)$wC === (string)$plateNoMatch) {
                    continue;
                }
            }
            $pTerms[] = $wC;
        }
    }
    
    $jobSearchRaw = $jobSearchExplicit ?: implode(' ', $pTerms);
    $jobSearchTerm = trim($jobSearchRaw);

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
    
    // Add job search terms (LOOSE AND MATCHING across words if multiple words)
    if ($jobSearchTerm && !$plateNoMatch) {
        if ($jobSearchExplicit) {
            $expWords = array_filter(preg_split('/\s+|-/', $jobSearchExplicit));
            if (count($expWords) > 1) {
                foreach ($expWords as $ew) {
                    $where[] = "(name LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ?)";
                    $l = '%' . $ew . '%';
                    $params[] = $l; $params[] = $l; $params[] = $l; $params[] = $l;
                    $typesStr .= 'ssss';
                }
            } else {
                $where[] = "(name LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ?)";
                $l = '%' . $jobSearchExplicit . '%';
                $params[] = $l; $params[] = $l; $params[] = $l; $params[] = $l;
                $typesStr .= 'ssss';
            }
        } else {
            foreach ($pTerms as $term) {
                $where[] = "(name LIKE ? OR paper_type LIKE ? OR make_by LIKE ? OR die LIKE ?)";
                $l = '%' . $term . '%';
                $params[] = $l; $params[] = $l; $params[] = $l; $params[] = $l;
                $typesStr .= 'ssss';
            }
        }
    }

    $whereSql = implode(' AND ', $where);
    $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';

    if ($isCountIntent && !$plateNoMatch && !$jobSearchTerm && !$cylinderMatch) {
        // Dashboard mode
        $cntRes = $db->query("SELECT COUNT(*) as cnt FROM master_plate_data");
        $totalCount = $cntRes ? (int) ($cntRes->fetch_assoc()['cnt'] ?? 0) : 0;
        $dieBreak = $db->query("SELECT die, COUNT(*) as cnt FROM master_plate_data WHERE die IS NOT NULL AND die != '' GROUP BY die ORDER BY cnt DESC LIMIT 5");
        $dieStats = $dieBreak ? $dieBreak->fetch_all(MYSQLI_ASSOC) : [];
        $makerBreak = $db->query("SELECT make_by, COUNT(*) as cnt FROM master_plate_data WHERE make_by IS NOT NULL AND make_by != '' GROUP BY make_by ORDER BY cnt DESC LIMIT 5");
        $makerStats = $makerBreak ? $makerBreak->fetch_all(MYSQLI_ASSOC) : [];
        
        if ($userLang === 'Bengali') {
            $answer = "📊 **প্রিন্টিং প্লেট ড্যাশবোর্ড**\n━━━━━━━━━━━━━━━━━━━━━━\n🔢 **মোট প্লেট:** **{$totalCount}টি**\n\n";
            if (!empty($dieStats)) {
                $answer .= "📐 **ধরণ (Die Type):**\n";
                foreach ($dieStats as $ds) $answer .= " ▸ **" . ($ds['die'] ?: 'অন্যান্য') . "** — {$ds['cnt']}টি\n";
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
            if (!empty($makerStats)) {
                $answer .= "\n🏭 **By Maker:**\n";
                foreach ($makerStats as $ms) $answer .= " ▸ **{$ms['make_by']}** — {$ms['cnt']} plates\n";
            }
        }
        $answer .= "\n👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
        return ['tool_used' => $toolName, 'total_count' => $totalCount, 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
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

    $stmt = $db->prepare("SELECT * FROM master_plate_data WHERE {$whereSql} ORDER BY id DESC LIMIT 5");
    if (!empty($params)) $stmt->bind_param($typesStr, ...$params);
    $stmt->execute();
    $plateData = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($plateData) && $jobSearchTerm) {
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
            $rep = (float)($row['repeat_value'] ?: 0);
            $ups = (float)($row['ups'] ?: 1);
            if ($ups <= 0) $ups = 1;
            
            // Color logic
            $colors = [];
            foreach(['c','m','y','k','special_1','special_2','special_3','special_4','special_5'] as $c) {
                if (!empty(trim((string)$row[$c]))) $colors[] = trim((string)$row[$c]);
            }
            $colorCount = count($colors);
            
            $answer .= "• **Plate " . ($idx + 1) . ": `" . ($row['name'] ?: 'Job Name') . "`** (Plate: **" . ($row['plate'] ?: 'N/A') . "** | ID: **{$row['id']}**)\n"
                . "  - 📏 **Repeat:** **{$rep}mm** | **Ups:** **{$ups}** | ⚙️ **Cylinder:** **" . ($row['cylinder'] ?: 'N/A') . "**\n"
                . "  - 📐 **Gaps:** **Gap H:** **" . ($row['gap_h'] ?: '0') . "mm** | **Gap V:** **" . ($row['gap_v'] ?: '0') . "mm**\n"
                . "  - 📄 **Paper Type:** **" . ($row['paper_type'] ?: 'N/A') . "** | 🏭 **Make By:** **" . ($row['make_by'] ?: 'N/A') . "**\n"
                . "  - 🎨 **Colors ({$colorCount}):** " . implode(', ', $colors) . "\n"
                . "  - 📅 **Date Received / Last Job:** **" . ($row['date_received'] ?: 'Not recorded') . "**\n";

            // Math Engine
            if ($targetMeters > 0 && $rep > 0) {
                $calcLabels = floor(($targetMeters * 1000) / $rep * $ups);
                if ($userLang === 'Bengali') {
                    $answer .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " মিটার** পেপারে **" . number_format($calcLabels) . " টি লেবেল** প্রিন্ট হবে।\n";
                } elseif ($userLang === 'Hindi') {
                    $answer .= "  - 🧮 **Calculation:** **" . number_format($targetMeters) . " मीटर** पेपर में **" . number_format($calcLabels) . " लेबेल** प्रिंट होंगे।\n";
                } else {
                    $answer .= "  - 🧮 **Calculation:** A **" . number_format($targetMeters) . "m** roll will yield **" . number_format($calcLabels) . " labels**.\n";
                }
            }
            if ($targetLabels > 0 && $rep > 0) {
                $reqMeters = ceil(($targetLabels / $ups) * $rep / 1000);
                if ($userLang === 'Bengali') {
                    $answer .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " টি লেবেল** প্রিন্ট করতে **" . number_format($reqMeters) . " মিটার** পেপার লাগবে।\n";
                } elseif ($userLang === 'Hindi') {
                    $answer .= "  - 🧮 **Calculation:** **" . number_format($targetLabels) . " लेबेल** प्रिंट करने के लिए **" . number_format($reqMeters) . " मीटर** पेपर लगेगा।\n";
                } else {
                    $answer .= "  - 🧮 **Calculation:** To print **" . number_format($targetLabels) . " labels**, you need **" . number_format($reqMeters) . " meters** of paper.\n";
                }
            }
            if ($targetMeters == null && $targetLabels == null && $rep > 0) {
                $teeth = round($rep / 3.175, 2);
                $answer .= "  - ⚙️ **Gearing (1/8 CP):** ~**{$teeth} Teeth** Cylinder\n";
            }
            $answer .= "\n";
        }
        
        $answer .= "👉 [Open Plate Management Page]({$baseUrl}/modules/plate-tools/plate-management/index.php)";
        
        return ['tool_used' => $toolName, 'total_count' => count($plateData), 'total_meters' => 0, 'filtered_type' => '', 'is_company_list' => false, 'direct_answer' => $answer, 'data' => []];
    }
