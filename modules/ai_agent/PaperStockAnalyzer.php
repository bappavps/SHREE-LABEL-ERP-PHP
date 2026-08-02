<?php

class PaperStockAnalyzer {
    
    public static function generateReport(mysqli $db, array $filters, string $intent, string $userLang): array {
        $where = ["LOWER(COALESCE(status,'')) NOT IN ('consumed','disposed','scrap')"];
        $params = [];
        $typesStr = '';

        if (!empty($filters['company'])) {
            $where[] = "company LIKE ?";
            $params[] = '%' . $filters['company'] . '%';
            $typesStr .= 's';
        }
        
        if (!empty($filters['paper_type'])) {
            $t = mb_strtolower($filters['paper_type'], 'UTF-8');
            if ($t === 'pp white' || $t === 'pp-white') {
                $where[] = "(paper_type LIKE '%pp-white%' OR paper_type LIKE '%pp white%' OR paper_type LIKE '%pp_white%')";
            } elseif ($t === 'pp clear' || $t === 'pp-clear') {
                $where[] = "(paper_type LIKE '%pp-clear%' OR paper_type LIKE '%pp clear%' OR paper_type LIKE '%pp_clear%')";
            } elseif ($t === 'thermal' || $t === 'thermal paper') {
                $where[] = "paper_type LIKE '%thermal%'";
            } else {
                $where[] = "paper_type LIKE ?";
                $params[] = '%' . $t . '%';
                $typesStr .= 's';
            }
        }
        
        if (!empty($filters['gsm'])) {
            $where[] = "gsm = ?";
            $params[] = (float) $filters['gsm'];
            $typesStr .= 'd';
        }
        
        if (!empty($filters['width'])) {
            $where[] = "width_mm = ?";
            $params[] = (float) $filters['width'];
            $typesStr .= 'd';
        }

        $whereSql = implode(' AND ', $where);

        // 1. Core Summary
        $summarySql = "SELECT 
            COUNT(*) as roll_count, 
            IFNULL(SUM(length_mtr),0) as total_mtr, 
            IFNULL(SUM((width_mm/1000.0)*length_mtr),0) as total_sqm,
            IFNULL(SUM(weight_kg),0) as total_weight,
            IFNULL(SUM(purchase_rate),0) as total_value,
            COUNT(DISTINCT company) as total_companies,
            MAX(date_received) as last_received
            FROM paper_stock WHERE {$whereSql}";
        
        $stmt = $db->prepare($summarySql);
        if (!empty($params)) $stmt->bind_param($typesStr, ...$params);
        $stmt->execute();
        $summary = $stmt->get_result()->fetch_assoc();
        
        if ((int)$summary['roll_count'] === 0) {
            return [
                'tool_used' => 'Paper Stock Analyzer',
                'total_count' => 0,
                'total_meters' => 0.0,
                'filtered_type' => '',
                'is_company_list' => false,
                'direct_answer' => "⚠️ No matching paper stock found for the requested criteria.",
                'data' => []
            ];
        }

        $isAnalysis = ($intent === 'paper_stock_analysis');
        
        $title = "Paper Stock Report";
        if (!empty($filters['company']) && !empty($filters['paper_type'])) $title = strtoupper($filters['company'] . ' ' . $filters['paper_type']);
        elseif (!empty($filters['company'])) $title = strtoupper($filters['company']);
        elseif (!empty($filters['paper_type'])) $title = strtoupper($filters['paper_type']);
        
        $answer = "📊 **{$title}**\n\n";
        
        // 1. Overall Summary
        $answer .= "### 1. Overall Summary\n";
        $answer .= "- **Total Rolls:** " . number_format((int)$summary['roll_count']) . "\n";
        $answer .= "- **Total Running Meter:** " . number_format((float)$summary['total_mtr'], 1) . " m\n";
        $answer .= "- **Total SQM:** " . number_format((float)$summary['total_sqm'], 1) . " SQM\n";
        if ((float)$summary['total_weight'] > 0) $answer .= "- **Total Weight:** " . number_format((float)$summary['total_weight'], 2) . " kg\n";
        if ((float)$summary['total_value'] > 0) $answer .= "- **Total Stock Value:** ₹" . number_format((float)$summary['total_value'], 2) . "\n";
        $answer .= "- **Total Companies:** " . (int)$summary['total_companies'] . "\n\n";

        if ($isAnalysis) {
            // 2. Company-wise Summary
            $cSql = "SELECT company, COUNT(*) as c, SUM(length_mtr) as m, SUM((width_mm/1000.0)*length_mtr) as sq, SUM(weight_kg) as w, SUM(purchase_rate) as val, MAX(date_received) as last_date, (SELECT roll_no FROM paper_stock p2 WHERE p2.company = paper_stock.company AND p2.date_received = MAX(paper_stock.date_received) AND " . str_replace("paper_stock.", "p2.", $whereSql) . " ORDER BY p2.id DESC LIMIT 1) as last_roll FROM paper_stock WHERE {$whereSql} GROUP BY company ORDER BY c DESC";
            
            // Re-write to avoid dependent subquery complexity which might fail in strictly typed GROUP BY
            $cSql = "SELECT p1.company, COUNT(*) as c, SUM(p1.length_mtr) as m, SUM((p1.width_mm/1000.0)*p1.length_mtr) as sq, SUM(p1.weight_kg) as w, SUM(p1.purchase_rate) as val, MAX(p1.date_received) as last_date, MAX(p1.id) as max_id FROM paper_stock p1 WHERE " . str_replace('width_mm', 'p1.width_mm', str_replace('gsm', 'p1.gsm', str_replace('company ', 'p1.company ', str_replace('paper_type', 'p1.paper_type', str_replace('status', 'p1.status', $whereSql))))) . " GROUP BY p1.company ORDER BY c DESC";
            
            $stmtC = $db->prepare($cSql);
            if (!empty($params)) $stmtC->bind_param($typesStr, ...$params);
            $stmtC->execute();
            $companies = $stmtC->get_result()->fetch_all(MYSQLI_ASSOC);
            
            // To get the exact roll no, let's fetch it simply via max_id
            $compWithRolls = [];
            foreach ($companies as $comp) {
                $id = $comp['max_id'];
                $rRes = $db->query("SELECT roll_no FROM paper_stock WHERE id = $id");
                $rRow = $rRes->fetch_assoc();
                $comp['last_roll'] = $rRow ? $rRow['roll_no'] : 'N/A';
                $compWithRolls[] = $comp;
            }

            if (count($compWithRolls) > 0) {
                $answer .= "### 2. Company-wise Summary\n";
                $answer .= "| Company | Roll Count | Running Meter | SQM | Weight | Stock Value | Last Received Roll | Last Received Date |\n";
                $answer .= "|---------|-----------:|--------------:|----:|-------:|------------:|--------------------|--------------------|\n";
                foreach ($compWithRolls as $c) {
                    $answer .= "| " . ($c['company'] ?: 'Unknown') . " | " . number_format($c['c']) . " | " . number_format((float)$c['m'], 1) . " | " . number_format((float)$c['sq'], 1) . " | " . number_format((float)$c['w'], 2) . " | ₹" . number_format((float)$c['val'], 2) . " | `" . $c['last_roll'] . "` | " . ($c['last_date'] ?: 'N/A') . " |\n";
                }
                $answer .= "\n";
            }

            // 3. Width-wise Summary
            $wSql = "SELECT 
                SUM(CASE WHEN width_mm < 1000 THEN 1 ELSE 0 END) as slitted_c,
                SUM(CASE WHEN width_mm < 1000 THEN length_mtr ELSE 0 END) as slitted_m,
                SUM(CASE WHEN width_mm < 1000 THEN (width_mm/1000.0)*length_mtr ELSE 0 END) as slitted_sq,
                SUM(CASE WHEN width_mm = 1000 THEN 1 ELSE 0 END) as ex_c,
                SUM(CASE WHEN width_mm = 1000 THEN length_mtr ELSE 0 END) as ex_m,
                SUM(CASE WHEN width_mm = 1000 THEN (width_mm/1000.0)*length_mtr ELSE 0 END) as ex_sq,
                SUM(CASE WHEN width_mm > 1000 THEN 1 ELSE 0 END) as jumbo_c,
                SUM(CASE WHEN width_mm > 1000 THEN length_mtr ELSE 0 END) as jumbo_m,
                SUM(CASE WHEN width_mm > 1000 THEN (width_mm/1000.0)*length_mtr ELSE 0 END) as jumbo_sq
                FROM paper_stock WHERE {$whereSql}";
            $stmtW = $db->prepare($wSql);
            if (!empty($params)) $stmtW->bind_param($typesStr, ...$params);
            $stmtW->execute();
            $wStats = $stmtW->get_result()->fetch_assoc();
            
            $answer .= "### 3. Width-wise Summary\n";
            $answer .= "| Category | Roll Count | Running Meter | SQM |\n";
            $answer .= "|----------|-----------:|--------------:|----:|\n";
            $answer .= "| Below 1000 mm | " . (int)$wStats['slitted_c'] . " | " . number_format((float)$wStats['slitted_m'], 1) . " | " . number_format((float)$wStats['slitted_sq'], 1) . " |\n";
            $answer .= "| Exactly 1000 mm | " . (int)$wStats['ex_c'] . " | " . number_format((float)$wStats['ex_m'], 1) . " | " . number_format((float)$wStats['ex_sq'], 1) . " |\n";
            $answer .= "| Above 1000 mm (Jumbo) | " . (int)$wStats['jumbo_c'] . " | " . number_format((float)$wStats['jumbo_m'], 1) . " | " . number_format((float)$wStats['jumbo_sq'], 1) . " |\n\n";

            // 4. GSM-wise Summary
            $gSql = "SELECT gsm, COUNT(*) as c, SUM(length_mtr) as m, SUM((width_mm/1000.0)*length_mtr) as sq FROM paper_stock WHERE {$whereSql} AND gsm IS NOT NULL AND gsm != '' GROUP BY gsm ORDER BY c DESC LIMIT 10";
            $stmtG = $db->prepare($gSql);
            if (!empty($params)) $stmtG->bind_param($typesStr, ...$params);
            $stmtG->execute();
            $gsms = $stmtG->get_result()->fetch_all(MYSQLI_ASSOC);
            if (count($gsms) > 0) {
                $answer .= "### 4. GSM-wise Summary\n";
                $answer .= "| GSM | Roll Count | Running Meter | SQM |\n";
                $answer .= "|----:|-----------:|--------------:|----:|\n";
                foreach ($gsms as $g) {
                    $answer .= "| " . $g['gsm'] . " | " . number_format($g['c']) . " | " . number_format((float)$g['m'], 1) . " | " . number_format((float)$g['sq'], 1) . " |\n";
                }
                $answer .= "\n";
            }
        }

        // 5. Detailed Roll Table
        $listSql = "SELECT id, roll_no, company, paper_type, width_mm, length_mtr, (width_mm/1000.0 * length_mtr) as sqm, weight_kg, gsm, purchase_rate, status, date_received, remarks FROM paper_stock WHERE {$whereSql} ORDER BY id DESC LIMIT 20";
        $stmtList = $db->prepare($listSql);
        if (!empty($params)) $stmtList->bind_param($typesStr, ...$params);
        $stmtList->execute();
        $rolls = $stmtList->get_result()->fetch_all(MYSQLI_ASSOC);

        $answer .= "### 5. Detailed Roll Table\n";
        $answer .= "| # | Roll No | Company | Type | W (mm) | L (m) | SQM | GSM | Rate | Status |\n";
        $answer .= "|--:|---------|---------|------|------:|------:|----:|----:|-----:|--------|\n";
        foreach ($rolls as $idx => $r) {
            $answer .= "| " . ($idx + 1) . " | `" . ($r['roll_no'] ?: '-') . "` | " . ($r['company'] ?: '-') . " | " . ($r['paper_type'] ?: '-') . " | " . (float)$r['width_mm'] . " | " . (float)$r['length_mtr'] . " | " . number_format((float)$r['sqm'], 1) . " | " . ($r['gsm'] ?: '-') . " | ₹" . (float)$r['purchase_rate'] . " | " . ($r['status'] ?: '-') . " |\n";
        }
        if ($summary['roll_count'] > 20) {
            $answer .= "*Showing latest 20 rolls out of " . number_format((int)$summary['roll_count']) . ".*\n";
        }
        $answer .= "\n";

        if ($isAnalysis) {
            // 6. Inventory Statistics
            // First we need to find the specific rolls.
            $statSql = "
            (SELECT 'Largest Roll' as metric, roll_no, length_mtr as val FROM paper_stock WHERE {$whereSql} ORDER BY length_mtr DESC LIMIT 1)
            UNION ALL
            (SELECT 'Smallest Roll' as metric, roll_no, length_mtr as val FROM paper_stock WHERE {$whereSql} AND length_mtr > 0 ORDER BY length_mtr ASC LIMIT 1)
            UNION ALL
            (SELECT 'Highest Width' as metric, roll_no, width_mm as val FROM paper_stock WHERE {$whereSql} ORDER BY width_mm DESC LIMIT 1)
            UNION ALL
            (SELECT 'Lowest Width' as metric, roll_no, width_mm as val FROM paper_stock WHERE {$whereSql} AND width_mm > 0 ORDER BY width_mm ASC LIMIT 1)
            UNION ALL
            (SELECT 'Oldest Roll' as metric, roll_no, UNIX_TIMESTAMP(date_received) as val FROM paper_stock WHERE {$whereSql} AND date_received IS NOT NULL AND date_received != '0000-00-00' ORDER BY date_received ASC LIMIT 1)
            UNION ALL
            (SELECT 'Latest Roll' as metric, roll_no, UNIX_TIMESTAMP(date_received) as val FROM paper_stock WHERE {$whereSql} AND date_received IS NOT NULL ORDER BY date_received DESC LIMIT 1)
            ";
            
            // To be safe with union and bind_param (bind param applies sequentially), it's easier to run individual queries or duplicate params.
            // Duplicate params for 6 queries = count(params) * 6
            $p6 = [];
            $t6 = '';
            for($i=0; $i<6; $i++) {
                foreach($params as $p) $p6[] = $p;
                $t6 .= $typesStr;
            }
            $stmtStat = $db->prepare($statSql);
            if (!empty($p6)) $stmtStat->bind_param($t6, ...$p6);
            $stmtStat->execute();
            $statRes = $stmtStat->get_result()->fetch_all(MYSQLI_ASSOC);
            
            $statMap = [];
            foreach($statRes as $s) {
                $statMap[$s['metric']] = ['roll' => $s['roll_no'], 'val' => $s['val']];
            }

            $answer .= "### 6. Inventory Statistics\n";
            if (isset($statMap['Largest Roll'])) $answer .= "- **Largest Roll:** `" . $statMap['Largest Roll']['roll'] . "` (" . number_format($statMap['Largest Roll']['val'], 1) . " m)\n";
            if (isset($statMap['Smallest Roll'])) $answer .= "- **Smallest Roll:** `" . $statMap['Smallest Roll']['roll'] . "` (" . number_format($statMap['Smallest Roll']['val'], 1) . " m)\n";
            if (isset($statMap['Oldest Roll'])) $answer .= "- **Oldest Roll:** `" . $statMap['Oldest Roll']['roll'] . "` (" . date('Y-m-d', $statMap['Oldest Roll']['val']) . ")\n";
            if (isset($statMap['Latest Roll'])) $answer .= "- **Latest Roll:** `" . $statMap['Latest Roll']['roll'] . "` (" . date('Y-m-d', $statMap['Latest Roll']['val']) . ")\n";
            if (isset($statMap['Highest Width'])) $answer .= "- **Highest Width:** `" . $statMap['Highest Width']['roll'] . "` (" . $statMap['Highest Width']['val'] . " mm)\n";
            if (isset($statMap['Lowest Width'])) $answer .= "- **Lowest Width:** `" . $statMap['Lowest Width']['roll'] . "` (" . $statMap['Lowest Width']['val'] . " mm)\n\n";
            
            // 7. AI Insights
            $answer .= "### 7. AI Insights\n";
            $insight = "";
            
            // Highest stock company
            $topStockComp = null;
            $topMtrComp = null;
            if (count($compWithRolls) > 0) {
                // Already sorted by roll count descending
                $topStockComp = $compWithRolls[0];
                $pct = round(($topStockComp['c'] / max(1, $summary['roll_count'])) * 100);
                $insight .= "💡 **" . ($topStockComp['company'] ?: 'Unknown') . "** has the highest stock by roll count (**{$topStockComp['c']} rolls** / {$pct}%).\n";
                
                // Find highest by running meter
                $maxMtr = -1;
                foreach($compWithRolls as $c) {
                    if ($c['m'] > $maxMtr) {
                        $maxMtr = $c['m'];
                        $topMtrComp = $c;
                    }
                }
                if ($topMtrComp && $topMtrComp['company'] !== $topStockComp['company']) {
                    $insight .= "💡 **" . ($topMtrComp['company'] ?: 'Unknown') . "** has the highest stock by running meter (**" . number_format($topMtrComp['m'], 1) . " m**).\n";
                } elseif ($topMtrComp) {
                    $insight .= "💡 **" . ($topMtrComp['company'] ?: 'Unknown') . "** also holds the highest running meter (**" . number_format($topMtrComp['m'], 1) . " m**).\n";
                }
            }
            
            $insight .= "💡 There are **" . (int)$wStats['jumbo_c'] . "** Jumbo Rolls ready for slitting.\n";
            $insight .= "💡 There are **" . (int)$wStats['slitted_c'] . "** Slit Rolls available for production.\n";
            
            $answer .= $insight . "\n";
        }
        
        $baseUrl = defined('BASE_URL') ? BASE_URL : '/shree-label-php';
        $answer .= "\n👉 [Open Paper Stock Page]({$baseUrl}/modules/paper_stock/index.php)";

        return [
            'tool_used' => 'Paper Stock Analysis Engine',
            'total_count' => (int)$summary['roll_count'],
            'total_meters' => (float)$summary['total_mtr'],
            'filtered_type' => $title,
            'is_company_list' => false,
            'direct_answer' => $answer,
            'data' => []
        ];
    }
}
