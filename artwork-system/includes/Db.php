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
            $this->ensureFinalFileServerSchema();
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

    private function tableColumnExists(string $table, string $column): bool {
        $tableQuoted = $this->pdo->quote($table);
        $columnQuoted = $this->pdo->quote($column);
        $stmt = $this->pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = " . $this->pdo->quote(ARTWORK_DB_NAME) . " AND table_name = " . $tableQuoted . " AND column_name = " . $columnQuoted . " LIMIT 1");
        return (bool)$stmt->fetchColumn();
    }

    private function tableIndexExists(string $table, string $indexName): bool {
        $tableQuoted = $this->pdo->quote($table);
        $indexQuoted = $this->pdo->quote($indexName);
        $stmt = $this->pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = " . $this->pdo->quote(ARTWORK_DB_NAME) . " AND table_name = " . $tableQuoted . " AND index_name = " . $indexQuoted . " LIMIT 1");
        return (bool)$stmt->fetchColumn();
    }

    private function ensureFinalFileServerSchema(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS artwork_final_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            project_id INT NULL,
            legacy_artwork_file_id INT NULL,
            client_name VARCHAR(150) NOT NULL,
            job_name VARCHAR(160) DEFAULT NULL,
            plate_number VARCHAR(100) DEFAULT NULL,
            date_received DATE DEFAULT NULL,
            job_size VARCHAR(120) DEFAULT NULL,
            paper_size VARCHAR(120) DEFAULT NULL,
            paper_type VARCHAR(120) DEFAULT NULL,
            make_by VARCHAR(120) DEFAULT NULL,
            die_number VARCHAR(100) DEFAULT NULL,
            color_job VARCHAR(100) DEFAULT NULL,
            job_date DATE DEFAULT NULL,
            original_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(255) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            mime_type VARCHAR(100) DEFAULT NULL,
            uploaded_by INT NULL,
            uploaded_by_name VARCHAR(120) DEFAULT NULL,
            notes TEXT DEFAULT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_final_stored_name (stored_name),
            UNIQUE KEY uq_final_legacy_file (legacy_artwork_file_id),
            KEY idx_final_project (project_id),
            KEY idx_final_client (client_name),
            KEY idx_final_job_name (job_name),
            KEY idx_final_plate (plate_number),
            KEY idx_final_date_received (date_received),
            KEY idx_final_job_size (job_size),
            KEY idx_final_paper_size (paper_size),
            KEY idx_final_paper_type (paper_type),
            KEY idx_final_make_by (make_by),
            KEY idx_final_die (die_number),
            KEY idx_final_color (color_job),
            KEY idx_final_job_date (job_date),
            KEY idx_final_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!$this->tableColumnExists('artwork_final_files', 'legacy_artwork_file_id')) {
            $this->pdo->exec("ALTER TABLE artwork_final_files ADD COLUMN legacy_artwork_file_id INT NULL AFTER project_id");
        }
        if (!$this->tableIndexExists('artwork_final_files', 'uq_final_legacy_file')) {
            $this->pdo->exec("ALTER TABLE artwork_final_files ADD UNIQUE KEY uq_final_legacy_file (legacy_artwork_file_id)");
        }

        $requiredColumns = [
            'job_name' => "ALTER TABLE artwork_final_files ADD COLUMN job_name VARCHAR(160) DEFAULT NULL AFTER client_name",
            'date_received' => "ALTER TABLE artwork_final_files ADD COLUMN date_received DATE DEFAULT NULL AFTER plate_number",
            'job_size' => "ALTER TABLE artwork_final_files ADD COLUMN job_size VARCHAR(120) DEFAULT NULL AFTER date_received",
            'paper_size' => "ALTER TABLE artwork_final_files ADD COLUMN paper_size VARCHAR(120) DEFAULT NULL AFTER job_size",
            'paper_type' => "ALTER TABLE artwork_final_files ADD COLUMN paper_type VARCHAR(120) DEFAULT NULL AFTER paper_size",
            'make_by' => "ALTER TABLE artwork_final_files ADD COLUMN make_by VARCHAR(120) DEFAULT NULL AFTER paper_type",
        ];
        foreach ($requiredColumns as $column => $sql) {
            if (!$this->tableColumnExists('artwork_final_files', $column)) {
                $this->pdo->exec($sql);
            }
        }

        $requiredIndexes = [
            'idx_final_job_name' => "ALTER TABLE artwork_final_files ADD KEY idx_final_job_name (job_name)",
            'idx_final_date_received' => "ALTER TABLE artwork_final_files ADD KEY idx_final_date_received (date_received)",
            'idx_final_job_size' => "ALTER TABLE artwork_final_files ADD KEY idx_final_job_size (job_size)",
            'idx_final_paper_size' => "ALTER TABLE artwork_final_files ADD KEY idx_final_paper_size (paper_size)",
            'idx_final_paper_type' => "ALTER TABLE artwork_final_files ADD KEY idx_final_paper_type (paper_type)",
            'idx_final_make_by' => "ALTER TABLE artwork_final_files ADD KEY idx_final_make_by (make_by)",
        ];
        foreach ($requiredIndexes as $index => $sql) {
            if (!$this->tableIndexExists('artwork_final_files', $index)) {
                $this->pdo->exec($sql);
            }
        }
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance->pdo;
    }
}
?>
