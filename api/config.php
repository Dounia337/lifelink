<?php
// ============================================================
// LifeLink - Database Configuration
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'deubaybe.dounia');
define('DB_PASS', 'Dou81387');
define('DB_NAME', 'mobileapps_2026B_deubaybe_dounia');
define('APP_URL', 'http://localhost');
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

// Load the three design pattern files
require_once __DIR__ . '/Database.php';    // Pattern 1: Singleton
require_once __DIR__ . '/UserFactory.php'; // Pattern 2: Factory
require_once __DIR__ . '/EventSystem.php'; // Pattern 3: Observer

// getDB() now delegates to the Singleton — one connection, always the same instance
function getDB(): PDO {
    return Database::getInstance()->getConnection();
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

function normalizeDateTime(string $value): ?string {
    try {
        $dt = new DateTime($value);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        return null;
    }
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
