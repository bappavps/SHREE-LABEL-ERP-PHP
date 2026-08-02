<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/KnowledgeIndexer.php';

session_start();
if (!isset($_SESSION['user_id']) || !function_exists('isAdmin') || !isAdmin()) {
    // If isAdmin is not globally available in this scope, just check session role
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
        exit;
    }
}

$action = $_REQUEST['action'] ?? '';

if ($action === 'status') {
    $db = getDB();
    $res = $db->query("SELECT COUNT(DISTINCT file_path) as total_files, COUNT(*) as total_entities, MAX(last_indexed) as last_scan FROM ai_knowledge_entities");
    $stats = $res->fetch_assoc();
    
    $manifestFile = __DIR__ . '/ai_manifest.json';
    $manifest = file_exists($manifestFile) ? json_decode(file_get_contents($manifestFile), true) : [];
    
    echo json_encode([
        'ok' => true,
        'total_files' => (int)$stats['total_files'],
        'total_entities' => (int)$stats['total_entities'],
        'last_scan' => $stats['last_scan'] ?? 'Never',
        'manifest_version' => $manifest['manifest_version'] ?? 'N/A'
    ]);
    exit;
}

if ($action === 'build' || $action === 'rebuild') {
    try {
        $db = getDB();
        
        if ($action === 'rebuild') {
            $db->query("TRUNCATE TABLE ai_relationships");
            $db->query("TRUNCATE TABLE ai_entity_keywords");
            $db->query("TRUNCATE TABLE ai_knowledge_entities");
        }
        
        $rootDir = realpath(__DIR__ . '/../../../');
        $indexer = new KnowledgeIndexer($db);
        $stats = $indexer->buildIndex($rootDir);
        
        echo json_encode(['ok' => true, 'stats' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action']);
