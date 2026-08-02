<?php
/* ============================================================
   AliasResolver — Knowledge Alias System
   ERP Master System — AI Agent
   
   Resolves alias names to their canonical KB names before
   search so that "MBD", "Mriganka", "Mriganka Bhusan Debnath"
   all route to the same KB entry.
   ============================================================ */

class AliasResolver
{
    private mysqli $db;
    /** @var array<string, string>  lower(alias) => canonical_name */
    private array $aliasMap = [];
    private bool  $loaded   = false;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->ensureTable();
    }

    /* ── Schema bootstrap (shared-hosting safe) ─────────────────── */
    private function ensureTable(): void
    {
        $this->db->query("
            CREATE TABLE IF NOT EXISTS `ai_knowledge_aliases` (
                `id`         INT AUTO_INCREMENT PRIMARY KEY,
                `kb_id`      INT NOT NULL,
                `alias`      VARCHAR(300) NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_kb_id`  (`kb_id`),
                INDEX `idx_alias`  (`alias`(100))
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /* ── Lazy-load alias map ─────────────────────────────────────── */
    private function load(): void
    {
        if ($this->loaded) return;
        $this->aliasMap = [];

        $sql = "
            SELECT a.alias, k.keywords
            FROM   ai_knowledge_aliases a
            JOIN   ai_agent_knowledge   k ON k.id = a.kb_id
            WHERE  k.is_active = 1
        ";
        $res = $this->db->query($sql);
        if (!$res) { $this->loaded = true; return; }

        while ($row = $res->fetch_assoc()) {
            // Use the first keyword phrase as the canonical name
            $parts     = array_map('trim', explode(',', $row['keywords']));
            $canonical = $parts[0] ?? '';
            if ($canonical === '') continue;

            $normalizedAlias = mb_strtolower(trim($row['alias']), 'UTF-8');
            $this->aliasMap[$normalizedAlias] = $canonical;
        }
        $this->loaded = true;
    }

    /* ── Public API ─────────────────────────────────────────────── */

    /**
     * Resolve aliases in a free-text prompt.
     * Replaces any recognized alias token with its canonical name.
     *
     * Example:
     *   "Show MBD's orders" → "Show Mriganka Bhusan Debnath's orders"
     *
     * @param  string $prompt  Original user prompt
     * @return string          Prompt with aliases replaced
     */
    public function resolve(string $prompt): string
    {
        $this->load();
        if (empty($this->aliasMap)) return $prompt;

        // Sort by descending length so longer aliases are matched first.
        $sorted = $this->aliasMap;
        uksort($sorted, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));

        // Track which canonical values have already been injected
        // so partial aliases (e.g. "Aditya") don't re-expand a canonical
        // that was just inserted (e.g. "Aditya Sitani").
        $injected = [];

        $lower = mb_strtolower($prompt, 'UTF-8');

        foreach ($sorted as $alias => $canonical) {
            $canonicalLower = mb_strtolower($canonical, 'UTF-8');

            // Skip if this canonical was already injected this pass
            if (isset($injected[$canonicalLower])) continue;

            // Skip if the canonical phrase is already verbatim in the prompt
            // (e.g. user typed "Aditya Sitani" — no need to expand "Aditya" alias)
            if (mb_strpos($lower, $canonicalLower, 0, 'UTF-8') !== false) continue;

            // Whole-word boundary match (case-insensitive, Unicode-safe)
            $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}])/iu';
            if (preg_match($pattern, $lower)) {
                // Replace in the ORIGINAL prompt (preserving case elsewhere)
                $prompt = preg_replace($pattern, $canonical, $prompt);
                // Update lower for subsequent passes
                $lower  = mb_strtolower($prompt, 'UTF-8');
                // Mark canonical as already injected so shorter sub-aliases don't re-expand it
                $injected[$canonicalLower] = true;
            }
        }
        return $prompt;
    }

    /**
     * Return the canonical keyword phrase for an alias, or null if not found.
     *
     * @param  string $alias  e.g. "MBD"
     * @return string|null    e.g. "Mriganka Bhusan Debnath"
     */
    public function canonical(string $alias): ?string
    {
        $this->load();
        $key = mb_strtolower(trim($alias), 'UTF-8');
        return $this->aliasMap[$key] ?? null;
    }

    /**
     * Return all aliases for a given KB entry ID.
     *
     * @param  int $kbId
     * @return string[]
     */
    public function getAliasesForKb(int $kbId): array
    {
        $stmt = $this->db->prepare(
            "SELECT alias FROM ai_knowledge_aliases WHERE kb_id = ? ORDER BY id ASC"
        );
        $stmt->bind_param('i', $kbId);
        $stmt->execute();
        $res  = $stmt->get_result();
        $list = [];
        while ($row = $res->fetch_assoc()) {
            $list[] = $row['alias'];
        }
        return $list;
    }

    /**
     * Add a new alias for a KB entry.
     *
     * @param  int    $kbId
     * @param  string $alias
     * @return bool
     */
    public function addAlias(int $kbId, string $alias): bool
    {
        $alias = trim($alias);
        if ($alias === '') return false;
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO ai_knowledge_aliases (kb_id, alias) VALUES (?, ?)"
        );
        $stmt->bind_param('is', $kbId, $alias);
        return $stmt->execute();
    }

    /**
     * Delete an alias by its own ID.
     *
     * @param  int $aliasId
     * @return bool
     */
    public function deleteAlias(int $aliasId): bool
    {
        $stmt = $this->db->prepare(
            "DELETE FROM ai_knowledge_aliases WHERE id = ?"
        );
        $stmt->bind_param('i', $aliasId);
        return $stmt->execute();
    }

    /**
     * List all aliases (with their KB entry info) — for admin UI.
     *
     * @return array[]
     */
    public function listAll(): array
    {
        $sql = "
            SELECT a.id, a.kb_id, a.alias, a.created_at,
                   k.question, k.keywords
            FROM   ai_knowledge_aliases a
            JOIN   ai_agent_knowledge   k ON k.id = a.kb_id
            ORDER  BY a.kb_id ASC, a.id ASC
        ";
        $res  = $this->db->query($sql);
        $list = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $list[] = $row;
            }
        }
        return $list;
    }

    /** Force reload on next resolve() call (useful after alias CRUD). */
    public function clearCache(): void
    {
        $this->loaded   = false;
        $this->aliasMap = [];
    }
}
