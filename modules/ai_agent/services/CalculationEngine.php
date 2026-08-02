<?php
class CalculationEngine
{
    /**
     * Executes the plate job calculation and returns the formatted result.
     * Extracts exactly the legacy calculation code with no business logic changes.
     */
    public function calculatePlateCosting(mysqli $db, string $plateName, float $perSqrInchPrice, float $totalJobAmount, string $prompt): ?array
    {
        $likeP = '%' . preg_replace('/\s+/', '%', $plateName) . '%';
        $stmtP = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? ORDER BY id DESC LIMIT 10");
        $stmtP->bind_param('s', $likeP);
        $stmtP->execute();
        $resP = $stmtP->get_result();
        
        $plateRows = [];
        while ($r = $resP->fetch_assoc()) {
            $plateRows[] = $r;
        }

        if (count($plateRows) > 0) {
            // Determine if there is an exact match (case/space insensitive)
            $exactMatch = null;
            $searchNormalized = strtolower(preg_replace('/\s+/', '', $plateName));
            foreach ($plateRows as $r) {
                if (strtolower(preg_replace('/\s+/', '', $r['name'])) === $searchNormalized) {
                    $exactMatch = $r;
                    break;
                }
            }

            if (count($plateRows) === 1 || $exactMatch) {
                $plateRow = $exactMatch ? $exactMatch : $plateRows[0];
                $sizeStr = $plateRow['size'] ?? '';
                $widthMm = 0; $heightMm = 0;
                if (preg_match('/([\d.]+)\s*(?:mm)?\s*[xX×]\s*([\d.]+)\s*(?:mm)?/', $sizeStr, $sm)) {
                    $widthMm = (float)$sm[1];
                    $heightMm = (float)$sm[2];
                }
                $ups = max(1, (int)($plateRow['ups'] ?? 1));
                $repeatMm = (float)($plateRow['repeat_value'] ?? 0);
                $paperSizeMm = (float)preg_replace('/[^0-9.]/', '', $plateRow['paper_size'] ?? '0');

                if ($widthMm > 0 && $heightMm > 0) {
                    $labelAreaSqMm = $widthMm * $heightMm;
                    $labelAreaSqInch = $labelAreaSqMm / 625;
                    $oneLabelPrice = $labelAreaSqInch * $perSqrInchPrice;
                    $moq = floor($totalJobAmount / $oneLabelPrice);
                    $runningMeters = ($repeatMm > 0) ? (($moq / $ups) * $repeatMm / 1000) : 0;
                    $totalPaperSqM = ($paperSizeMm > 0) ? ($runningMeters * $paperSizeMm / 1000) : 0;

                    $answer = "🧮 **Job Calculation Engine — \"{$plateRow['name']}\"**\n";
                    $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                    $answer .= "📋 **Job Technical Data (from ERP):**\n";
                    $answer .= "  - 🏷️ **Job Name:** `{$plateRow['name']}` (SL: {$plateRow['sl_no']})\n";
                    $answer .= "  - 📐 **Job Size:** **{$sizeStr}**\n";
                    $answer .= "  - 🔄 **Repeat Value:** **{$repeatMm}mm**\n";
                    $answer .= "  - 🔢 **Ups:** **{$ups}**\n";
                    $answer .= "  - 📄 **Paper Size:** **{$plateRow['paper_size']}** ({$plateRow['paper_type']})\n";
                    $answer .= "  - ⚙️ **Die:** {$plateRow['die']} | **Cylinder:** {$plateRow['cylinder']}\n\n";
                    $answer .= "📊 **Step-by-Step Calculation:**\n";
                    $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                    $answer .= "**Step 1: Label Area (sq inch)**\n";
                    $answer .= "  `({$widthMm}mm × {$heightMm}mm) / 625 = " . round($labelAreaSqInch, 4) . " sq inch`\n\n";
                    $answer .= "**Step 2: One Label Price**\n";
                    $answer .= "  `" . round($labelAreaSqInch, 4) . " sq inch × ₹{$perSqrInchPrice} = ₹" . round($oneLabelPrice, 4) . " per label`\n\n";
                    $answer .= "**Step 3: MOQ (Minimum Order Quantity)**\n";
                    $answer .= "  `₹" . number_format($totalJobAmount, 2) . " / ₹" . round($oneLabelPrice, 4) . " = **" . number_format($moq) . " labels**`\n\n";
                    if ($repeatMm > 0) {
                        $answer .= "**Step 4: Running Meters**\n";
                        $answer .= "  `(" . number_format($moq) . " / {$ups}) × {$repeatMm}mm / 1000 = **" . number_format($runningMeters, 2) . " meters**`\n\n";
                    }
                    if ($paperSizeMm > 0 && $runningMeters > 0) {
                        $answer .= "**Step 5: Total Paper (sq mtr)**\n";
                        $answer .= "  `" . number_format($runningMeters, 2) . "m × {$paperSizeMm}mm / 1000 = **" . number_format($totalPaperSqM, 2) . " sq mtr**`\n\n";
                    }
                    $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                    $answer .= "📦 **Summary:**\n";
                    $answer .= "  - 🏷️ **Total Labels:** **" . number_format($moq) . " pcs**\n";
                    $answer .= "  - 💰 **Per Label Cost:** **₹" . round($oneLabelPrice, 4) . "**\n";
                    $answer .= "  - 📏 **Running Meters:** **" . number_format($runningMeters, 2) . " mtr**\n";
                    $answer .= "  - 📄 **Total Paper:** **" . number_format($totalPaperSqM, 2) . " sq mtr**\n";
                    $answer .= "  - 💵 **Total Job Value:** **₹" . number_format($totalJobAmount, 2) . "**\n";
                    $baseUrlCalc = defined('BASE_URL') ? BASE_URL : '/calipot-erp/shree-label-php';
                    $answer .= "\n👉 [Open Job Management Page]({$baseUrlCalc}/modules/plate-tools/plate-management/index.php)";
                    $suggestions = ["Show {$plateRow['name']} job details", "Calculate for 50000 taka job", "Show all jobs"];
                    $userLang = detect_language($prompt);

                    return [
                        'ok' => true, 
                        'answer' => $answer, 
                        'provider' => 'ERP AI Job Calculator', 
                        'tool_used' => 'Job Calculation Engine', 
                        'user_lang' => $userLang, 
                        'suggestions' => $suggestions, 
                        'command_type' => 'plate'
                    ];
                }
            } 
            
            // MULTIPLE MATCHES (Disambiguation)
            $answer = "🤔 **Multiple items found matching \"{$plateName}\"!**\n\n";
            $answer .= "Which one did you mean? Please select from the options below:\n";
            $suggestions = [];
            $userLang = detect_language($prompt);

            foreach ($plateRows as $r) {
                $suggestCmd = "/job \"{$r['name']}\" budget {$totalJobAmount} rate {$perSqrInchPrice}";
                $onClick = "if(typeof applyChipPrompt === 'function'){applyChipPrompt('".htmlspecialchars($suggestCmd, ENT_QUOTES)."', true);}else if(typeof _applyFloatSuggestion === 'function'){_applyFloatSuggestion('".htmlspecialchars($suggestCmd, ENT_QUOTES)."');}return false;";
                $answer .= "🔹 <a href=\"javascript:void(0)\" style=\"text-decoration: none; font-weight: bold; color: inherit;\" onclick=\"{$onClick}\">{$r['name']}</a> (Size: {$r['size']})\n";
                // Build a suggestion button for the specific item
                $suggestions[] = $suggestCmd;
            }
            
            return [
                'ok' => true, 
                'answer' => $answer, 
                'provider' => 'ERP AI Calculator', 
                'tool_used' => 'Disambiguation Engine', 
                'user_lang' => $userLang, 
                'suggestions' => $suggestions, 
                'command_type' => 'plate'
            ];
        }

        // Return null if no matches were found so the calling code knows it failed
        return null;
    }

    /**
     * Computes just the per-label price from a per-square-inch rate when the user
     * does NOT give a total job amount.
     * e.g. `/job "Blue 200ml" per sqr inch price is 0.065 then how many price will be per label?`
     */
    public function calculatePerLabelPrice(mysqli $db, string $plateName, float $perSqrInchPrice, string $prompt): ?array
    {
        $likeP = '%' . preg_replace('/\s+/', '%', $plateName) . '%';
        $stmtP = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? ORDER BY id DESC LIMIT 10");
        $stmtP->bind_param('s', $likeP);
        $stmtP->execute();
        $plateRows = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);

        $userLang = detect_language($prompt);
        // Currency: ৳ when the user mentions taka/tk/টাকা, otherwise ₹ (matches the costing engine)
        $cur = preg_match('/(taka|tk|টাকা|৳)/iu', $prompt) ? '৳' : '₹';

        if (count($plateRows) > 0) {
            // Determine if there is an exact match (case/space insensitive)
            $exactMatch = null;
            $searchNormalized = strtolower(preg_replace('/\s+/', '', $plateName));
            foreach ($plateRows as $r) {
                if (strtolower(preg_replace('/\s+/', '', $r['name'])) === $searchNormalized) {
                    $exactMatch = $r;
                    break;
                }
            }

            if (count($plateRows) === 1 || $exactMatch) {
                $plateRow = $exactMatch ? $exactMatch : $plateRows[0];
                $sizeStr = $plateRow['size'] ?? '';
                $widthMm = 0; $heightMm = 0;
                if (preg_match('/([\d.]+)\s*(?:mm)?\s*[xX×]\s*([\d.]+)\s*(?:mm)?/', $sizeStr, $sm)) {
                    $widthMm = (float)$sm[1];
                    $heightMm = (float)$sm[2];
                }
                if ($widthMm > 0 && $heightMm > 0) {
                    $labelAreaSqMm = $widthMm * $heightMm;
                    $labelAreaSqInch = $labelAreaSqMm / 625;
                    $oneLabelPrice = $labelAreaSqInch * $perSqrInchPrice;

                    $answer = "🧮 **Per Label Price — \"{$plateRow['name']}\"**\n";
                    $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                    $answer .= "📋 **Label Technical Data (from ERP):**\n";
                    $answer .= "  - 🏷️ **Label Name:** `{$plateRow['name']}` (SL: {$plateRow['sl_no']})\n";
                    $answer .= "  - 📐 **Label Size:** **{$sizeStr}**\n";
                    $answer .= "  - 🔢 **Ups:** **" . max(1, (int)($plateRow['ups'] ?? 1)) . "**\n";
                    $answer .= "  - ⚙️ **Die:** {$plateRow['die']} | **Cylinder:** {$plateRow['cylinder']}\n\n";
                    $answer .= "📊 **Step-by-Step Calculation:**\n";
                    $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
                    $answer .= "**Step 1: Label Area (sq inch)**\n";
                    $answer .= "  `({$widthMm}mm × {$heightMm}mm) / 625 = " . round($labelAreaSqInch, 4) . " sq inch`\n\n";
                    $answer .= "**Step 2: Per Label Price**\n";
                    $answer .= "  `" . round($labelAreaSqInch, 4) . " sq inch × {$cur}{$perSqrInchPrice} = {$cur}" . round($oneLabelPrice, 4) . " per label`\n\n";
                    $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
                    $answer .= "💵 **Per Label Price: {$cur}" . round($oneLabelPrice, 4) . "**\n\n";
                    $answer .= "👉 Want the full job costing (MOQ, running meters, paper)? Give a total budget, e.g. `/job \"{$plateRow['name']}\" budget 50000 rate {$perSqrInchPrice}`";
                    $baseUrlCalc = defined('BASE_URL') ? BASE_URL : '/calipot-erp/shree-label-php';
                    $answer .= "\n👉 [Open Plate Management]({$baseUrlCalc}/modules/plate-tools/plate-management/index.php)";
                    $suggestions = ["/job \"{$plateRow['name']}\" budget 50000 rate {$perSqrInchPrice}", "Show {$plateRow['name']} job details", "Show all plates"];

                    return [
                        'ok' => true,
                        'answer' => $answer,
                        'provider' => 'ERP AI Calculator',
                        'tool_used' => 'Per Label Price Calculator',
                        'user_lang' => $userLang,
                        'suggestions' => $suggestions,
                        'command_type' => 'plate'
                    ];
                }
            }

            // MULTIPLE MATCHES (Disambiguation)
            $answer = "🤔 **Multiple items found matching \"{$plateName}\"!**\n\n";
            $answer .= "Which one did you mean? Please select from the options below:\n";
            $suggestions = [];
            foreach ($plateRows as $r) {
                $suggestCmd = "/job \"{$r['name']}\" rate {$perSqrInchPrice}";
                $onClick = "if(typeof applyChipPrompt === 'function'){applyChipPrompt('" . htmlspecialchars($suggestCmd, ENT_QUOTES) . "', true);}else if(typeof _applyFloatSuggestion === 'function'){_applyFloatSuggestion('" . htmlspecialchars($suggestCmd, ENT_QUOTES) . "');}return false;";
                $answer .= "🔹 <a href=\"javascript:void(0)\" style=\"text-decoration: none; font-weight: bold; color: inherit;\" onclick=\"{$onClick}\">{$r['name']}</a> (Size: {$r['size']})\n";
                $suggestions[] = $suggestCmd;
            }
            return [
                'ok' => true,
                'answer' => $answer,
                'provider' => 'ERP AI Calculator',
                'tool_used' => 'Disambiguation Engine',
                'user_lang' => $userLang,
                'suggestions' => $suggestions,
                'command_type' => 'plate'
            ];
        }

        // Return null if no matches were found so the calling code knows it failed
        return null;
    }
}
