<?php
class CalculationEngine
{
    /**
     * Generic entry point for all job and plate calculations.
     * Automatically determines which calculation to perform based on provided params.
     * Does NOT duplicate formulas, reuses existing logic, and calculates new jobs 
     * using dimensions without saving to DB.
     */
    public function calculateJob(mysqli $db, array $params, string $prompt): ?array
    {
        $plateName = $params['plate_name'] ?? null;
        $widthMm = $params['width_mm'] ?? 0;
        $heightMm = $params['height_mm'] ?? 0;
        $gapH = $params['gap_h'] ?? 0;
        $gapV = $params['gap_v'] ?? 0;
        $runningMeter = $params['running_meter'] ?? 0;
        $labels = $params['labels'] ?? 0;
        $rate = $params['rate'] ?? 0;
        $budget = $params['budget'] ?? 0;

        $userLang = detect_language($prompt);
        // Currency: ৳ when the user mentions taka/tk/টাকা, otherwise ₹
        $cur = preg_match('/(taka|tk|টাকা|৳)/iu', $prompt) ? '৳' : '₹';

        $plateRow = null;
        $suggestions = [];
        $baseUrlCalc = defined('BASE_URL') ? BASE_URL : '/calipot-erp/shree-label-php';

        if ($plateName) {
            $likeP = '%' . preg_replace('/\s+/', '%', $plateName) . '%';
            $stmtP = $db->prepare("SELECT * FROM master_plate_data WHERE name LIKE ? ORDER BY id DESC LIMIT 10");
            $stmtP->bind_param('s', $likeP);
            $stmtP->execute();
            $plateRows = $stmtP->get_result()->fetch_all(MYSQLI_ASSOC);

            if (count($plateRows) > 0) {
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
                    if (preg_match('/([\d.]+)\s*(?:mm)?\s*[xX×]\s*([\d.]+)\s*(?:mm)?/', $sizeStr, $sm)) {
                        $widthMm = (float)$sm[1];
                        $heightMm = (float)$sm[2];
                    }
                    $ups = max(1, (int)($plateRow['ups'] ?? 1));
                    $repeatMm = (float)($plateRow['repeat_value'] ?? 0);
                    $paperSizeMm = (float)preg_replace('/[^0-9.]/', '', $plateRow['paper_size'] ?? '0');
                } else {
                    // MULTIPLE MATCHES (Disambiguation)
                    $answer = "🤔 **Multiple items found matching \"{$plateName}\"!**\n\n";
                    $answer .= "Which one did you mean? Please select from the options below:\n";
                    foreach ($plateRows as $r) {
                        $suggestCmd = "/job \"{$r['name']}\"";
                        if ($rate > 0) $suggestCmd .= " rate {$rate}";
                        if ($budget > 0) $suggestCmd .= " budget {$budget}";
                        if ($runningMeter > 0) $suggestCmd .= " {$runningMeter} mtr";
                        if ($labels > 0) $suggestCmd .= " {$labels} labels";

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
            } else {
                return null;
            }
        } else {
            // New Job Calculator (no existing plate)
            $maxPrintWidth = 250.0;
            if ($widthMm <= 0 || $heightMm <= 0) {
                return null;
            }
            $ups = floor($maxPrintWidth / ($widthMm + $gapH));
            if ($ups < 1) $ups = 1;
            $repeatMm = $heightMm + $gapV;
            $paperSizeMm = $maxPrintWidth;
        }

        // Common Mathematical Logic (reused formulas)
        $labelAreaSqMm = $widthMm * $heightMm;
        $labelAreaSqInch = $labelAreaSqMm / 625;
        $oneLabelPrice = ($rate > 0) ? ($labelAreaSqInch * $rate) : 0;

        $moq = 0;
        $runningMetersCalc = 0;
        $totalPaperSqM = 0;
        $labelsPerMeter = ($repeatMm > 0) ? ((1000 / $repeatMm) * $ups) : 0;
        $totalImpressions = 0;

        // Determine calculation mode
        if ($runningMeter > 0) {
            $calcType = 'running_meter';
            $runningMetersCalc = $runningMeter;
            $totalImpressions = ($repeatMm > 0) ? ($runningMetersCalc * 1000 / $repeatMm) : 0;
            $moq = floor($totalImpressions * $ups);
            $totalPaperSqM = ($paperSizeMm > 0) ? ($runningMetersCalc * $paperSizeMm / 1000) : 0;
        } elseif ($labels > 0) {
            $calcType = 'labels';
            $moq = $labels;
            $runningMetersCalc = ($repeatMm > 0) ? (($moq / $ups) * $repeatMm / 1000) : 0;
            $totalPaperSqM = ($paperSizeMm > 0) ? ($runningMetersCalc * $paperSizeMm / 1000) : 0;
        } elseif ($rate > 0 && $budget > 0) {
            $calcType = 'budget';
            $moq = floor($budget / $oneLabelPrice);
            $runningMetersCalc = ($repeatMm > 0) ? (($moq / $ups) * $repeatMm / 1000) : 0;
            $totalPaperSqM = ($paperSizeMm > 0) ? ($runningMetersCalc * $paperSizeMm / 1000) : 0;
        } elseif ($rate > 0) {
            $calcType = 'rate_only';
        } else {
            return null; // Not enough parameters to calculate anything
        }

        $title = $plateRow ? "\"{$plateRow['name']}\"" : "New Job [{$widthMm}mm x {$heightMm}mm]";
        $answer = "🧮 **Job Calculation Engine — {$title}**\n";
        $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        if ($plateRow) {
            $sizeStr = $plateRow['size'] ?? "{$widthMm}mm x {$heightMm}mm";
            $answer .= "📋 **Job Technical Data (from ERP):**\n";
            $answer .= "  - 🏷️ **Job Name:** `{$plateRow['name']}` (SL: {$plateRow['sl_no']})\n";
            $answer .= "  - 📐 **Job Size:** **{$sizeStr}**\n";
            $answer .= "  - 🔄 **Repeat Value:** **{$repeatMm}mm**\n";
            $answer .= "  - 🔢 **Ups:** **{$ups}**\n";
            $answer .= "  - 📄 **Paper Size:** **{$plateRow['paper_size']}** ({$plateRow['paper_type']})\n";
            $answer .= "  - ⚙️ **Die:** {$plateRow['die']} | **Cylinder:** {$plateRow['cylinder']}\n\n";
        } else {
            $answer .= "📋 **Job Technical Data (New Dimensions):**\n";
            $answer .= "  - 📐 **Label Size:** **{$widthMm}mm x {$heightMm}mm**\n";
            $answer .= "  - ↔️ **Gap:** **H: {$gapH}mm | V: {$gapV}mm**\n";
            $answer .= "  - 🔄 **Effective Repeat:** **{$repeatMm}mm**\n";
            $answer .= "  - 🔢 **Maximum Ups:** **{$ups}** (Max Width: 250mm)\n";
            $answer .= "  - 🏷️ **Labels per Meter:** **" . round($labelsPerMeter, 2) . "**\n\n";
        }

        $answer .= "📊 **Step-by-Step Calculation:**\n";
        $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

        if ($calcType === 'rate_only' || $rate > 0) {
            $answer .= "**Step 1: Label Area (sq inch)**\n";
            $answer .= "  `({$widthMm}mm × {$heightMm}mm) / 625 = " . round($labelAreaSqInch, 4) . " sq inch`\n\n";
            $answer .= "**Step 2: One Label Price**\n";
            $answer .= "  `" . round($labelAreaSqInch, 4) . " sq inch × {$cur}{$rate} = {$cur}" . round($oneLabelPrice, 4) . " per label`\n\n";
        }

        if ($calcType === 'budget') {
            $answer .= "**Step 3: MOQ (Minimum Order Quantity)**\n";
            $answer .= "  `{$cur}" . number_format($budget, 2) . " / {$cur}" . round($oneLabelPrice, 4) . " = **" . number_format($moq) . " labels**`\n\n";
        }

        if ($calcType !== 'rate_only') {
            if ($calcType === 'running_meter') {
                $answer .= "**Labels Produced**\n";
                $answer .= "  `" . number_format($totalImpressions, 2) . " impressions × {$ups} ups = **" . number_format($moq) . " labels**`\n\n";
            } else {
                $answer .= "**Running Meters**\n";
                $answer .= "  `(" . number_format($moq) . " / {$ups}) × {$repeatMm}mm / 1000 = **" . number_format($runningMetersCalc, 2) . " meters**`\n\n";
            }

            if ($paperSizeMm > 0 && $runningMetersCalc > 0) {
                $stepName = ($calcType === 'budget' || $calcType === 'labels') ? 'Total Paper' : 'Required Paper';
                $answer .= "**{$stepName} (sq mtr)**\n";
                $answer .= "  `" . number_format($runningMetersCalc, 2) . "m × {$paperSizeMm}mm / 1000 = **" . number_format($totalPaperSqM, 2) . " sq mtr**`\n\n";
            }
        }

        $answer .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $answer .= "📦 **Summary:**\n";

        if ($calcType === 'rate_only') {
            $answer .= "  - 💵 **Per Label Price:** **{$cur}" . round($oneLabelPrice, 4) . "**\n\n";
            if ($plateRow) {
                $answer .= "👉 Want the full job costing? Give a total budget, e.g. `/job \"{$plateRow['name']}\" budget 50000 rate {$rate}`";
            }
        } else {
            $answer .= "  - 🏷️ **Total Labels:** **" . number_format($moq) . " pcs**\n";
            if ($rate > 0) {
                $answer .= "  - 💰 **Per Label Cost:** **{$cur}" . round($oneLabelPrice, 4) . "**\n";
            }
            if ($calcType === 'running_meter' && !$plateRow) {
                $answer .= "  - 🔄 **Total Impressions:** **" . number_format($totalImpressions, 2) . "**\n";
            }
            $answer .= "  - 📏 **Running Meters:** **" . number_format($runningMetersCalc, 2) . " mtr**\n";
            $answer .= "  - 📄 **Total Paper (SQM):** **" . number_format($totalPaperSqM, 2) . " sq mtr**\n";
            if ($calcType === 'budget') {
                $answer .= "  - 💵 **Total Job Value:** **{$cur}" . number_format($budget, 2) . "**\n";
            }
        }

        if ($plateRow) {
            $answer .= "\n👉 [Open Job Management Page]({$baseUrlCalc}/modules/plate-tools/plate-management/index.php)";
            $suggestions = ["Show {$plateRow['name']} details", "Show all plates"];
        } else {
            $suggestions = ["Calculate another size", "Show all jobs"];
        }

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
