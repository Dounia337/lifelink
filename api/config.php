<?php
// ============================================================
// LifeLink - Database Configuration
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');         // Change for production
define('DB_PASS', '');             // Change for production
define('DB_NAME', 'lifelink');
define('APP_URL', 'http://localhost');  // Change for production
define('JWT_SECRET', 'lifelink_secret_key_2025_change_in_prod');

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// PDO Connection
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
            exit();
        }
    }
    return $pdo;
}

function jsonResponse(bool $success, string $message, array $data = [], int $code = 200): void {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data));
    exit();
}

function getRequestBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return $data ?? $_POST;
}

function sanitize(string $value): string {
    return htmlspecialchars(strip_tags(trim($value)));
}

// Simple session-based auth (no JWT needed for PHP)
function startSessionIfNeeded(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function requireAuth(): array {
    startSessionIfNeeded();
    if (empty($_SESSION['user_id'])) {
        jsonResponse(false, 'Authentication required', [], 401);
    }
    return $_SESSION;
}

function requireRole(string ...$roles): array {
    $session = requireAuth();
    if (!in_array($session['role'], $roles)) {
        jsonResponse(false, 'Insufficient permissions', [], 403);
    }
    return $session;
}

// Haversine distance formula
function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $R = 6371; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($R * $c, 2);
}
