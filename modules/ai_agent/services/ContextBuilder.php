<?php

class ContextBuilder
{
    /**
     * Transforms raw retrieval database rows into a highly compressed, 
     * token-efficient Markdown context block for the LLM.
     *
     * @param array $retrievalResults Raw results from RetrievalEngine
     * @param int $maxChars Maximum allowed character length for the entire context block
     * @param float $minScore Minimum score threshold to include an entity
     * @return string Formatted Markdown context
     */
    public static function build(array $retrievalResults, int $maxChars = 4000, float $minScore = 1.0): string
    {
        if (empty($retrievalResults)) {
            return '';
        }

        // 1. Deduplicate by entity ID and filter by minimum score
        $uniqueEntities = [];
        foreach ($retrievalResults as $row) {
            $id = $row['id'] ?? 0;
            $score = (float)($row['total_score'] ?? 0);
            
            if ($score < $minScore) {
                continue; // Skip low-relevance results
            }
            
            if (!isset($uniqueEntities[$id])) {
                $uniqueEntities[$id] = $row;
            }
        }

        // 2. Sort by score descending (just in case they aren't already)
        usort($uniqueEntities, function ($a, $b) {
            return ($b['total_score'] ?? 0) <=> ($a['total_score'] ?? 0);
        });

        // 3. Build the context string incrementally
        $contextString = "## SYSTEM KNOWLEDGE CONTEXT\n\n";
        
        foreach ($uniqueEntities as $entity) {
            $type = strtoupper($entity['type'] ?? 'UNKNOWN');
            $name = $entity['name'] ?? '';
            $file = $entity['file_path'] ?? '';
            $sig = $entity['signature'] ?? '';
            $summary = trim($entity['summary'] ?? '');
            
            // Format block
            $block = "### [{$type}] {$name}\n";
            if ($file) $block .= "**File:** {$file}\n";
            if ($sig && $type !== 'doc') $block .= "**Signature:** `{$sig}`\n";
            if ($summary) {
                // If summary is extremely long, truncate it individually before appending
                if (strlen($summary) > 1000) {
                    $summary = mb_substr($summary, 0, 1000, 'UTF-8') . '... (truncated)';
                }
                $block .= "**Summary:**\n{$summary}\n";
            }
            $block .= "\n";

            // Check if adding this block exceeds our strict limit
            if (strlen($contextString) + strlen($block) > $maxChars) {
                $contextString .= "\n*(Note: Further knowledge context truncated to save tokens)*\n";
                break;
            }

            $contextString .= $block;
        }

        return trim($contextString);
    }
}
