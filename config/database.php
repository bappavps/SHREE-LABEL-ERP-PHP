<?php
require_once __DIR__ . '/config.php';

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        if ($this->conn->connect_error) {
            die('Database connection failed: ' . htmlspecialchars($this->conn->connect_error));
        }
        $this->conn->set_charset('utf8mb4');
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }

    public function query($sql) {
        $result = $this->conn->query($sql);
        if ($result === false) {
            error_log('DB Query Error: ' . $this->conn->error . ' | SQL: ' . $sql);
        }
        return $result;
    }

    public function prepare($sql) {
        return $this->conn->prepare($sql);
    }

    public function escape($value) {
        return $this->conn->real_escape_string($value);
    }

    public function lastInsertId() {
        return $this->conn->insert_id;
    }

    public function affectedRows() {
        return $this->conn->affected_rows;
    }
}

function db() {
    return Database::getInstance()->getConnection();
}
