<?php

class RetrievalEngine
{
    private mysqli $db;
    private ?object $aliasResolver;
    
    public function __construct(mysqli $db, ?object $aliasResolver = null)
    {
        $this->db = $db;
        $this->aliasResolver = $aliasResolver;
    }
    
    /**
     * Retrieve the top relevant entities from the Knowledge Catalog based on a query.
     * Uses MySQL FULLTEXT matching with a fallback to LIKE for broad shared hosting compatibility.
     *
     * @param string $query User's raw prompt
     * @param int $limit Max results to return (default 5)
     * @return array Top matched entities
     */
    public function search(string $query, int $limit = 5): array
    {
        // Resolve aliases before normalization so "MBD" expands to "Mriganka Bhusan Debnath"
        if ($this->aliasResolver !== null) {
            $query = $this->aliasResolver->resolve($query);
        }

        $normalized = $this->normalizeQuery($query);
        if (empty($normalized)) {
            return [];
        }
        
        // Attempt FULLTEXT MATCH() AGAINST() first
        $results = $this->searchFullText($normalized, $limit);
        
        // Fallback to LIKE if FULLTEXT yields empty (could be index missing or min_word_len issues)
        if (empty($results)) {
            $results = $this->searchLike($normalized, $limit);
        }
        
        // Merge Knowledge Base (ai_agent_knowledge) matches — grounds the LLM with ERP KB content.
        // Covers resolved aliases (e.g. mriganka -> kb23, aditya/sitani -> kb24) that have no
        // ai_knowledge_entities rows, plus any FAQ / Business Rule that matches the query tokens.
        $kbResults = $this->searchKnowledgeBase($normalized, $limit);
        if (!empty($kbResults)) {
            $results = array_merge($kbResults, $results);
        }
        
        return $results;
    }
    
    /**
     * Search the ERP Knowledge Base (ai_agent_knowledge) for FAQ / Business Rule rows.
     * Matches normalized query tokens (>= 3 chars) against lowercase keywords + question using
     * Unicode word boundaries, scoring 2.0 per matched token. Rows are shaped like entity rows
     * so ContextBuilder / PromptBuilder can consume them unchanged.
     *
     * @param string $normalizedQuery Normalized query (from normalizeQuery)
     * @param int $limit Max results to return
     * @return array Knowledge Base rows shaped as retrieval entities
     */
    private function searchKnowledgeBase(string $normalizedQuery, int $limit): array
    {
        if (trim($normalizedQuery) === '') return [];
        
        $words = array_filter(explode(' ', $normalizedQuery), function ($w) {
            return mb_strlen($w) >= 3;
        });
        if (empty($words)) return [];
        
        $results = [];
        try {
            $res = $this->db->query("SELECT id, category, keywords, question, answer FROM ai_agent_knowledge WHERE is_active = 1");
            if (!$res) return [];
            
            while ($row = $res->fetch_assoc()) {
                $haystack = mb_strtolower(($row['keywords'] ?? '') . ' ' . ($row['question'] ?? ''), 'UTF-8');
                $matched = 0;
                foreach ($words as $word) {
                    $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/iu';
                    if (preg_match($pattern, $haystack)) {
                        $matched++;
                    }
                }
                if ($matched > 0) {
                    $results[] = [
                        'id' => 'kb_' . $row['id'],
                        'type' => $row['category'] ?? 'FAQ',
                        'name' => !empty($row['question']) ? $row['question'] : ($row['keywords'] ?? 'Knowledge Base'),
                        'file_path' => 'Knowledge Base',
                        'line_start' => null,
                        'line_end' => null,
                        'signature' => '',
                        'summary' => $row['answer'] ?? '',
                        'total_score' => 2.0 * $matched,
                        'source' => 'knowledge_base'
                    ];
                }
                if (count($results) >= $limit) break;
            }
        } catch (Exception $e) {
            return [];
        }
        
        return $results;
    }
    
    private function normalizeQuery(string $query): string
    {
        $stopWords = ['how', 'to', 'calculate', 'the', 'is', 'a', 'an', 'and', 'or', 'for', 'in', 'on', 'of', 'what', 'where', 'when', 'why', 'who', 'does', 'do', 'can', 'i', 'get'];
        
        // Use Unicode-safe regex (\p{L} for letters, \p{N} for numbers, \s for whitespace)
        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($query, 'UTF-8'));
        $words = explode(' ', $query);
        $filtered = [];
        foreach ($words as $word) {
            $word = trim($word);
            if ($word !== '' && !in_array($word, $stopWords)) {
                $filtered[] = $word;
            }
        }
        return implode(' ', $filtered);
    }
    
    private function searchFullText(string $normalizedQuery, int $limit): array
    {
        if (trim($normalizedQuery) === '') return [];
        
        $words = explode(' ', $normalizedQuery);
        $booleanModeQuery = implode(' ', array_map(function($w) { return $w . '*'; }, $words));
        
        $sql = "
            SELECT e.id, e.type, e.name, e.file_path, e.line_start, e.line_end, e.signature, e.summary,
                   (MATCH(e.name, e.signature, e.summary) AGAINST(? IN BOOLEAN MODE) * 2) AS score_entity,
                   (SELECT IFNULL(MAX(MATCH(k.keyword) AGAINST(? IN BOOLEAN MODE)), 0) FROM ai_entity_keywords k WHERE k.entity_id = e.id) AS score_keyword
            FROM ai_knowledge_entities e
            HAVING (score_entity + score_keyword) > 0
            ORDER BY (score_entity + score_keyword) DESC
            LIMIT ?
        ";
        
        $results = [];
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param('ssi', $booleanModeQuery, $booleanModeQuery, $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['total_score'] = $row['score_entity'] + $row['score_keyword'];
                $results[] = $row;
            }
        } catch (Exception $e) {
            echo "FULLTEXT ERROR: " . $e->getMessage() . "\n";
            // Silently catch exceptions (e.g. FULLTEXT index not ready) to allow fallback
            return [];
        }
        
        return $results;
    }
    
    private function searchLike(string $normalizedQuery, int $limit): array
    {
        if (trim($normalizedQuery) === '') return [];
        $words = explode(' ', $normalizedQuery);
        if (empty($words)) return [];
        
        $conditions = [];
        $params = [];
        $types = '';
        
        foreach ($words as $word) {
            $term = '%' . $word . '%';
            $conditions[] = "(e.name LIKE ? OR e.signature LIKE ? OR e.summary LIKE ?)";
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
            $types .= 'sss';
        }
        
        $sql = "
            SELECT e.id, e.type, e.name, e.file_path, e.line_start, e.line_end, e.signature, e.summary
            FROM ai_knowledge_entities e
            WHERE " . implode(' OR ', $conditions) . "
            LIMIT ?
        ";
        
        $params[] = $limit;
        $types .= 'i';
        
        $results = [];
        try {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $row['total_score'] = 1.0; // Flat score for LIKE fallback
                $results[] = $row;
            }
        } catch (Exception $e) {
            return [];
        }
        
        return $results;
    }
}
