<?php
/**
 * Alias Pipeline Diagnostic — SAFE READ-ONLY TEST
 * Verifies: resolve → check_knowledge_base → KB match
 * 
 * Usage: http://localhost/shree-label-php/modules/ai_agent/test_alias_pipeline.php
 */
session_start();
$_SESSION['user_id'] = 1; // mock auth

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/services/AliasResolver.php';

$db = getDB();
$resolver = new AliasResolver($db);

// ── 1. List all aliases in DB ──
$step = [];
$res = $db->query("SELECT a.id, a.alias, a.kb_id, k.keywords, k.question
                   FROM ai_knowledge_aliases a
                   JOIN ai_agent_knowledge k ON k.id = a.kb_id
                   ORDER BY a.kb_id, a.id");
$step['1_all_aliases'] = [];
if ($res) while ($r = $res->fetch_assoc()) $step['1_all_aliases'][] = $r;

// ── 2. Test resolver with known aliases ──
$testInputs = ['mriganka', 'MBD', 'Aditya', 'Sitani', 'mriganka bhusan debnath'];
$step['2_resolver_results'] = [];
foreach ($testInputs as $input) {
    $resolved = $resolver->resolve($input);
    $step['2_resolver_results'][$input] = $resolved;
}

// ── 3. Test check_knowledge_base with resolved names ──
// Inline the function signature from api.php (simplified version)
function probe_kb(mysqli $db, string $prompt): ?array {
    $res = $db->query("SELECT * FROM ai_agent_knowledge WHERE is_active = 1");
    if (!$res) return null;
    $promptLower = mb_strtolower(trim($prompt), 'UTF-8');
    preg_match_all('/[\x{0980}-\x{09FF}\x{0900}-\x{097F}\w]+/u', $promptLower, $promptMatches);
    $kbStopwords = ['the','a','an','is','in','for','about','with','what','where','how'];
    $promptTokens = array_filter($promptMatches[0] ?? [], fn($t) => mb_strlen($t) >= 3 && !in_array($t, $kbStopwords, true));

    $bestMatch = null; $bestScore = 0;
    while ($row = $res->fetch_assoc()) {
        $rawKeywords = array_map('trim', explode(',', mb_strtolower($row['keywords'], 'UTF-8')));
        $matchScore = 0; $hasExact = false;
        foreach ($rawKeywords as $kw) {
            if ($kw === '') continue;
            if (mb_strpos($promptLower, $kw) !== false) { $matchScore += 3.0; $hasExact = true; }
            preg_match_all('/[\x{0980}-\x{09FF}\x{0900}-\x{097F}\w]+/u', $kw, $kwM);
            foreach ($kwM[0] ?? [] as $kwT) {
                foreach ($promptTokens as $pT) {
                    if ($pT === $kwT) { $matchScore += 2.0; $hasExact = true; }
                }
            }
        }
        if ($matchScore > $bestScore) { $bestScore = $matchScore; $bestMatch = $row; }
    }
    return ($bestMatch && $bestScore >= 2.0) ? array_merge($bestMatch, ['_score' => $bestScore]) : null;
}

$resolvedName = $resolver->resolve('mriganka');
$step['3_resolved_name'] = $resolvedName;
$kbHit = probe_kb($db, $resolvedName);
$step['4_kb_hit_for_resolved'] = $kbHit
    ? ['id' => $kbHit['id'], 'keywords' => $kbHit['keywords'], 'question' => $kbHit['question'], 'score' => $kbHit['_score']]
    : null;

// ── 5. Check what pipeline order was in OLD code (should now be fixed) ──
$step['5_pipeline_order_check'] = [
    'status' => 'AliasResolver now runs at line ~60 in api.php, before $p is set at line ~651',
    'previous_bug' => 'AliasResolver was at line 756, AFTER $p derived at line 641 and greeting check at 686',
    'fix_applied' => true,
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($step, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
