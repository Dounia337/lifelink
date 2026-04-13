<?php
// ============================================================
// LifeLink - Authentication API
// POST /api/auth.php?action=login|register|logout|me
// ============================================================
require_once __DIR__ . '/config.php';
startSessionIfNeeded();

$action = $_GET['action'] ?? '';
$body = getRequestBody();

switch ($action) {
    case 'register':
        handleRegister($body);
        break;
    case 'login':
        handleLogin($body);
        break;
    case 'logout':
        handleLogout();
        break;
    case 'me':
        handleMe();
        break;
    default:
        jsonResponse(false, 'Unknown action', [], 400);
}

function handleRegister(array $data): void {
    $db = getDB();

    $required = ['full_name', 'email', 'password', 'role'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse(false, "Field '$field' is required", [], 422);
        }
    }

    $email = strtolower(sanitize($data['email']));
    $role = $data['role'];
    $allowedRoles = ['donor', 'hospital', 'health_worker'];
    if (!in_array($role, $allowedRoles)) {
        jsonResponse(false, 'Invalid role', [], 422);
    }

    // Check duplicate
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(false, 'Email already registered', [], 409);
    }

    $passwordHash = password_hash($data['password'], PASSWORD_BCRYPT);
    $stmt = $db->prepare("
        INSERT INTO users (full_name, email, phone, password_hash, role, location, city, region, latitude, longitude)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        sanitize($data['full_name']),
        $email,
        sanitize($data['phone'] ?? ''),
        $passwordHash,
        $role,
        sanitize($data['location'] ?? ''),
        sanitize($data['city'] ?? ''),
        sanitize($data['region'] ?? ''),
        $data['latitude'] ?? null,
        $data['longitude'] ?? null,
    ]);
    $userId = $db->lastInsertId();

    if ($role === 'donor') {
        $stmt2 = $db->prepare("
            INSERT INTO donor_profiles (user_id, blood_type, date_of_birth, gender, weight_kg)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt2->execute([
            $userId,
            $data['blood_type'] ?? 'unknown',
            $data['date_of_birth'] ?? null,
            $data['gender'] ?? null,
            $data['weight_kg'] ?? null,
        ]);
    } elseif ($role === 'hospital') {
        $stmt2 = $db->prepare("
            INSERT INTO hospitals (user_id, hospital_name, registration_number, hospital_type, address, city, region, latitude, longitude)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt2->execute([
            $userId,
            sanitize($data['hospital_name'] ?? $data['full_name']),
            sanitize($data['registration_number'] ?? ''),
            $data['hospital_type'] ?? 'public',
            sanitize($data['address'] ?? $data['location'] ?? ''),
            sanitize($data['city'] ?? ''),
            sanitize($data['region'] ?? ''),
            $data['latitude'] ?? null,
            $data['longitude'] ?? null,
        ]);
    }

    // Auto-login
    $_SESSION['user_id'] = $userId;
    $_SESSION['role'] = $role;
    $_SESSION['full_name'] = sanitize($data['full_name']);

    jsonResponse(true, 'Registration successful', [
        'user' => ['id' => $userId, 'role' => $role, 'full_name' => sanitize($data['full_name'])]
    ], 201);
}

function handleLogin(array $data): void {
    $db = getDB();

    if (empty($data['email']) || empty($data['password'])) {
        jsonResponse(false, 'Email and password required', [], 422);
    }

    $email = strtolower(sanitize($data['email']));
    $stmt = $db->prepare("SELECT id, full_name, email, password_hash, role, is_active, is_verified FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($data['password'], $user['password_hash'])) {
        jsonResponse(false, 'Invalid email or password', [], 401);
    }
    if (!$user['is_active']) {
        jsonResponse(false, 'Account is deactivated. Contact support.', [], 403);
    }

    // For hospitals, check approval
    if ($user['role'] === 'hospital') {
        $stmt2 = $db->prepare("SELECT is_approved FROM hospitals WHERE user_id = ?");
        $stmt2->execute([$user['id']]);
        $hospital = $stmt2->fetch();
        if ($hospital && !$hospital['is_approved']) {
            jsonResponse(false, 'Hospital account pending approval by admin.', [], 403);
        }
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];

    jsonResponse(true, 'Login successful', [
        'user' => [
            'id' => $user['id'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role'],
            'is_verified' => (bool)$user['is_verified'],
        ]
    ]);
}

function handleLogout(): void {
    session_destroy();
    jsonResponse(true, 'Logged out successfully');
}

function handleMe(): void {
    $session = requireAuth();
    $db = getDB();

    $stmt = $db->prepare("SELECT id, full_name, email, phone, role, location, city, region, latitude, longitude, is_verified, profile_photo, created_at FROM users WHERE id = ?");
    $stmt->execute([$session['user_id']]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(false, 'User not found', [], 404);
    }

    $extra = [];
    if ($user['role'] === 'donor') {
        $stmt2 = $db->prepare("SELECT * FROM donor_profiles WHERE user_id = ?");
        $stmt2->execute([$user['id']]);
        $extra['donor_profile'] = $stmt2->fetch();
    } elseif ($user['role'] === 'hospital') {
        $stmt2 = $db->prepare("SELECT * FROM hospitals WHERE user_id = ?");
        $stmt2->execute([$user['id']]);
        $extra['hospital'] = $stmt2->fetch();
    }

    jsonResponse(true, 'OK', ['user' => array_merge($user, $extra)]);
}
