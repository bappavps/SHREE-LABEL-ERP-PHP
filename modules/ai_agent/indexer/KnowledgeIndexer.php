<?php

require_once __DIR__ . '/Parsers/PhpParser.php';
require_once __DIR__ . '/Parsers/SqlParser.php';
require_once __DIR__ . '/Parsers/MarkdownParser.php';

class KnowledgeIndexer
{
    private mysqli $db;
    private array $config;
    
    public function __construct(mysqli $db)
    {
        $this->db = $db;
        $this->config = require __DIR__ . '/indexer_config.php';
    }
    
    /**
     * Incrementally builds the knowledge index catalog.
     * @param string $rootDir The root directory of the ERP
     * @return array Indexing statistics
     */
    public function buildIndex(string $rootDir): array
    {
        $stats = [
            'scanned_files' => 0,
            'indexed_files' => 0,
            'skipped_files' => 0,
            'total_entities' => 0,
            'time_ms' => 0
        ];
        
        $startTime = microtime(true);
        $indexedPaths = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        $ignoreFolders = $this->config['ignore_folders'] ?? [];
        $ignoreFiles = $this->config['ignore_files'] ?? [];
        $supportedExtensions = $this->config['supported_extensions'] ?? [];

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                if (in_array($file->getFilename(), $ignoreFolders)) {
                    // Tell iterator to skip this directory's children
                    // However RecursiveIteratorIterator doesn't have skipChildren natively unless overridden.
                    // We will just skip processing files inside it.
                }
                continue;
            }

            $path = $file->getPathname();
            // Normalize slashes for Windows/Linux consistency
            $path = str_replace('\\', '/', $path);
            $rootDirNormalized = str_replace('\\', '/', $rootDir);
            $relativePath = str_replace($rootDirNormalized . '/', '', $path);
            
            // Check ignores
            $skip = false;
            foreach ($ignoreFolders as $folder) {
                if (strpos($relativePath, $folder . '/') === 0 || strpos($relativePath, '/' . $folder . '/') !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;
            
            if (in_array($file->getFilename(), $ignoreFiles)) continue;
            
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $supportedExtensions)) continue;

            $stats['scanned_files']++;
            $indexedPaths[] = $relativePath;
            
            // Incremental check
            $fileHash = hash_file('sha256', $path);
            if ($this->isFileUnchanged($relativePath, $fileHash)) {
                $stats['skipped_files']++;
                continue;
            }
            
            // Parse and Index
            $this->removeFileEntities($relativePath);
            
            $entities = [];
            if ($ext === 'php') $entities = PhpParser::parse($path);
            elseif ($ext === 'sql') $entities = SqlParser::parse($path);
            elseif ($ext === 'md') $entities = MarkdownParser::parse($path);
            
            foreach ($entities as $e) {
                $this->insertEntity($relativePath, $fileHash, $e);
                $stats['total_entities']++;
            }
            $stats['indexed_files']++;
        }
        
        // Clean up deleted files from DB
        $this->cleanupDeletedFiles($indexedPaths);
        
        // Generate Manifest
        $this->generateManifest();
        
        $stats['time_ms'] = round((microtime(true) - $startTime) * 1000);
        return $stats;
    }
    
    private function isFileUnchanged(string $relativePath, string $fileHash): bool
    {
        $stmt = $this->db->prepare("SELECT file_hash FROM ai_knowledge_entities WHERE file_path = ? LIMIT 1");
        $stmt->bind_param('s', $relativePath);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            return $row['file_hash'] === $fileHash;
        }
        return false;
    }
    
    private function removeFileEntities(string $relativePath): void
    {
        $stmt = $this->db->prepare("DELETE FROM ai_knowledge_entities WHERE file_path = ?");
        $stmt->bind_param('s', $relativePath);
        $stmt->execute();
    }
    
    private function insertEntity(string $relativePath, string $fileHash, array $e): void
    {
        $stmt = $this->db->prepare("INSERT INTO ai_knowledge_entities (type, name, file_path, line_start, line_end, signature, summary, file_hash, last_indexed, schema_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)");
        
        $type = $e['type'] ?? 'unknown';
        $name = substr($e['name'] ?? '', 0, 255);
        $start = (int)($e['line_start'] ?? 0);
        $end = (int)($e['line_end'] ?? 0);
        $sig = $e['signature'] ?? '';
        $sum = $e['summary'] ?? '';
        $schemaVersion = $this->config['manifest']['index_schema_version'] ?? 1;
        
        $stmt->bind_param('sssiiisss', $type, $name, $relativePath, $start, $end, $sig, $sum, $fileHash, $schemaVersion);
        $stmt->execute();
        $entityId = $stmt->insert_id;
        
        // Generate a naive keyword from the name
        $this->insertKeyword($entityId, $name);
    }
    
    private function insertKeyword(int $entityId, string $name): void
    {
        // Very basic mock heuristic since LLM is not active yet
        $parts = preg_split('/(?=[A-Z])|::|_|-/', $name);
        $parts = array_filter(array_map('strtolower', $parts));
        
        $stmt = $this->db->prepare("INSERT INTO ai_entity_keywords (entity_id, keyword) VALUES (?, ?)");
        foreach ($parts as $p) {
            $p = trim(substr($p, 0, 100));
            if ($p !== '') {
                $stmt->bind_param('is', $entityId, $p);
                $stmt->execute();
            }
        }
    }
    
    private function cleanupDeletedFiles(array $indexedPaths): void
    {
        // This is a simplified cleanup. In production on huge DBs, a NOT IN array might be slow.
        if (count($indexedPaths) === 0) return;
        
        // Get all unique file paths in DB
        $res = $this->db->query("SELECT DISTINCT file_path FROM ai_knowledge_entities");
        $dbPaths = [];
        while ($row = $res->fetch_assoc()) {
            $dbPaths[] = $row['file_path'];
        }
        
        foreach ($dbPaths as $dbPath) {
            if (!in_array($dbPath, $indexedPaths)) {
                $this->removeFileEntities($dbPath);
            }
        }
    }
    
    private function generateManifest(): void
    {
        $manifest = [
            'manifest_version' => $this->config['manifest']['parser_version'] ?? '1.0',
            'last_indexed' => gmdate('Y-m-d\TH:i:s\Z'),
            'modules' => [],
            'services' => [],
            'controllers' => [],
            'api_endpoints' => [],
            'database_tables' => [],
            'workflows' => []
        ];
        
        $res = $this->db->query("SELECT type, name, file_path FROM ai_knowledge_entities");
        while ($row = $res->fetch_assoc()) {
            $type = $row['type'];
            $entry = ['name' => $row['name'], 'file' => $row['file_path']];
            
            if ($type === 'class' && stripos($row['name'], 'controller') !== false) {
                $manifest['controllers'][] = $entry;
            } elseif ($type === 'class' && stripos($row['name'], 'service') !== false) {
                $manifest['services'][] = $entry;
            } elseif ($type === 'table') {
                $manifest['database_tables'][] = $entry;
            } elseif ($type === 'doc') {
                $manifest['workflows'][] = $entry;
            } else {
                $manifest['modules'][] = $entry;
            }
        }
        
        file_put_contents(__DIR__ . '/ai_manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
