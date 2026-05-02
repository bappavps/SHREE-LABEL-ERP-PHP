<?php
/**
 * Database Singleton Class
 */

class Db {
    private static $instance = null;
    private $pdo;

    private function __construct() {
        try {
            $dsn = "mysql:host=" . ARTWORK_DB_HOST . ";dbname=" . ARTWORK_DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $this->pdo = new PDO($dsn, ARTWORK_DB_USER, ARTWORK_DB_PASS, $options);
            $this->ensureSchema();
            $this->ensureProjectColumns();
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    private function ensureSchema(): void {
        if ($this->tableExists('artwork_users')) {
            return;
        }

        $schemaPath = __DIR__ . '/../database.sql';
        if (!is_file($schemaPath)) {
            return;
        }

        $sql = file_get_contents($schemaPath);
        if ($sql === false) {
            return;
        }

        $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        $statements = array_filter(array_map('trim', explode(';', (string)$sql)));

        foreach ($statements as $stmt) {
            if ($stmt === '') {
                continue;
            }

            if (stripos($stmt, 'INSERT INTO artwork_users') === 0) {
                $stmt = preg_replace('/^INSERT\s+INTO/i', 'INSERT IGNORE INTO', $stmt, 1) ?? $stmt;
            }

            try {
                $this->pdo->exec($stmt);
            } catch (PDOException $e) {
                // Ignore bootstrap-safe errors and keep startup resilient.
                $msg = strtolower((string)$e->getMessage());
                if (strpos($msg, 'already exists') !== false || strpos($msg, 'duplicate') !== false) {
                    continue;
                }
                throw $e;
            }
        }
    }

    private function tableExists(string $table): bool {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
        $stmt->execute([ARTWORK_DB_NAME, $table]);
        return (bool)$stmt->fetchColumn();
    }

    private function ensureProjectColumns(): void {
        $required = [
            'job_size' => 'ALTER TABLE artwork_projects ADD COLUMN job_size VARCHAR(100) DEFAULT NULL AFTER job_name',
            'job_color' => 'ALTER TABLE artwork_projects ADD COLUMN job_color VARCHAR(100) DEFAULT NULL AFTER job_size',
            'job_remark' => 'ALTER TABLE artwork_projects ADD COLUMN job_remark TEXT DEFAULT NULL AFTER job_color',
        ];

        foreach ($required as $column => $sql) {
            if ($this->projectColumnExists($column)) {
                continue;
            }
            $this->pdo->exec($sql);
        }
    }

    private function projectColumnExists(string $column): bool {
        $quotedColumn = $this->pdo->quote($column);
        $stmt = $this->pdo->query("SHOW COLUMNS FROM artwork_projects LIKE " . $quotedColumn);
        return (bool) $stmt->fetch();
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
}
?>
