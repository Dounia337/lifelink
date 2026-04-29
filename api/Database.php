<?php
// ============================================================
// PATTERN 1: SINGLETON — Database Connection
// ============================================================
// Cheat sheet: "private static instance; private constructor;
//               public static getInstance()"
//
// Problem it solves: getDB() used a local static variable — an
// informal singleton. This formalises it so the single PDO
// connection is owned by one class, cannot be duplicated, and
// is retrieved the same way from anywhere.
// ============================================================

class Database {

    // 1. private static variable — holds the ONE instance
    private static ?Database $instance = null;

    // The actual PDO connection this singleton wraps
    private PDO $connection;

    // 2. private constructor — blocks "new Database()" everywhere else
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
            exit();
        }
    }

    // 3. public static getInstance() — the only way to get the connection
    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;  // always returns the same object
    }

    public function getConnection(): PDO {
        return $this->connection;
    }

    // Prevent cloning — would silently break the "one instance" guarantee
    private function __clone() {}
}
