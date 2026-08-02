<?php

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/KnowledgeIndexer.php';

// Support both CLI execution and Web Execution (if authenticated or with a secure token)
if (php_sapi_name() !== 'cli') {
    // If run via web, basic auth or secret key is required
    $secret = $_GET['secret'] ?? '';
    if ($secret !== 'erp_cron_secret_123' && !isset($_SESSION['user_id'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
    header('Content-Type: application/json');
}

try {
    $db = getDB();
    
    // Set root dir to the top of the shree-label-php project
    $rootDir = realpath(__DIR__ . '/../../../');
    
    $indexer = new KnowledgeIndexer($db);
    $stats = $indexer->buildIndex($rootDir);
    
    if (php_sapi_name() === 'cli') {
        echo "=====================================\n";
        echo "   ERP KNOWLEDGE INDEXER COMPLETE\n";
        echo "=====================================\n";
        echo "Time taken: " . $stats['time_ms'] . " ms\n";
        echo "Files scanned: " . $stats['scanned_files'] . "\n";
        echo "Files newly indexed: " . $stats['indexed_files'] . "\n";
        echo "Files skipped (unchanged): " . $stats['skipped_files'] . "\n";
        echo "Total entities extracted: " . $stats['total_entities'] . "\n";
        echo "-------------------------------------\n";
        echo "Manifest generated at: modules/ai_agent/indexer/ai_manifest.json\n";
    } else {
        echo json_encode(['ok' => true, 'stats' => $stats]);
    }
} catch (Exception $e) {
    if (php_sapi_name() === 'cli') {
        echo "ERROR: " . $e->getMessage() . "\n";
    } else {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
}
