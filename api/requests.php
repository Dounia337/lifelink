<?php
// ============================================================
// LifeLink - Blood Requests API
// GET  /api/requests.php          - List requests
// POST /api/requests.php          - Create request (hospital)
// GET  /api/requests.php?id=X     - Get single request
// PUT  /api/requests.php?id=X     - Update status
// ============================================================
require_once __DIR__ . '/config.php';
startSessionIfNeeded();

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($method) {
    case 'GET':
        if (isset($_GET['stats'])) getRequestStats();
        elseif ($id) getSingleRequest($id);
        else getRequests();
        break;
    case 'POST':
        createRequest();
        break;
    case 'PUT':
        if ($id) updateRequest($id);
        else jsonResponse(false, 'ID required', [], 400);
        break;
    default:
        jsonResponse(false, 'Method not allowed', [], 405);
}

function getRequests(): void {
    $db = getDB();
    $session = $_SESSION ?? null;
    $params = [];

    $status = $_GET['status'] ?? null;
    $blood_type = $_GET['blood_type'] ?? null;
    $urgency = $_GET['urgency'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = ['1=1'];
    if ($status) { $where[] = 'br.status = ?'; $params[] = $status; }
    if ($blood_type) { $where[] = 'br.blood_type = ?'; $params[] = $blood_type; }
    if ($urgency) { $where[] = 'br.urgency = ?'; $params[] = $urgency; }

    if (!empty($session['role']) && $session['role'] === 'hospital') {
        $stmt = $db->prepare('SELECT id FROM hospitals WHERE user_id = ? LIMIT 1');
        $stmt->execute([$session['user_id']]);
        $hospital = $stmt->fetch();
        if ($hospital) {
            $where[] = 'br.hospital_id = ?';
            $params[] = $hospital['id'];
        }
    } elseif (isset($_GET['hospital_id'])) {
        $where[] = 'br.hospital_id = ?';
        $params[] = (int)$_GET['hospital_id'];
    }

    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT br.*, 
               h.hospital_name,
               u.full_name as requested_by_name,
               COUNT(dm.id) as match_count
        FROM blood_requests br
        JOIN hospitals h ON h.id = br.hospital_id
        JOIN users u ON u.id = br.requested_by
        LEFT JOIN donor_matches dm ON dm.request_id = br.id AND dm.status NOT IN ('declined','cancelled')
        WHERE $whereStr
        GROUP BY br.id
        ORDER BY 
            FIELD(br.urgency,'critical','urgent','standard'),
            br.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $requests = $stmt->fetchAll();

    jsonResponse(true, 'OK', ['requests' => $requests, 'count' => count($requests)]);
}

function getRequestStats(): void {
    $db = getDB();
    $session = $_SESSION ?? null;
    $params = [];
    $where = ['1=1'];

    if (!empty($session['role']) && $session['role'] === 'hospital') {
        $stmt = $db->prepare('SELECT id FROM hospitals WHERE user_id = ? LIMIT 1');
        $stmt->execute([$session['user_id']]);
        $hospital = $stmt->fetch();
        if ($hospital) {
            $where[] = 'br.hospital_id = ?';
            $params[] = $hospital['id'];
        }
    } elseif (isset($_GET['hospital_id'])) {
        $where[] = 'br.hospital_id = ?';
        $params[] = (int)$_GET['hospital_id'];
    }

    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare("SELECT
            SUM(br.status = 'open') AS open_requests,
            SUM(br.status IN ('matched','in_progress')) AS matched_requests,
            SUM(br.status = 'fulfilled' AND DATE(br.fulfilled_at) = CURDATE()) AS fulfilled_today,
            SUM(br.status = 'fulfilled') AS total_fulfilled,
            COUNT(*) AS total_requests
        FROM blood_requests br
        WHERE $whereStr
    ");
    $stmt->execute($params);
    $stats = $stmt->fetch();
    $stats['fulfillment_rate'] = $stats['total_requests'] > 0 ? round(($stats['total_fulfilled'] / $stats['total_requests']) * 100, 1) : 0;

    jsonResponse(true, 'OK', ['stats' => $stats]);
}

function getSingleRequest(int $id): void {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT br.*, h.hospital_name, h.address as hospital_address,
               u.full_name as requested_by_name, u.phone as hospital_phone
        FROM blood_requests br
        JOIN hospitals h ON h.id = br.hospital_id
        JOIN users u ON u.id = br.requested_by
        WHERE br.id = ?
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch();

    if (!$request) jsonResponse(false, 'Request not found', [], 404);

    // Get matches
    $stmt2 = $db->prepare("
        SELECT dm.*, u.full_name as donor_name, dp.blood_type as donor_blood_type, dp.blood_type_verified
        FROM donor_matches dm
        JOIN users u ON u.id = dm.donor_id
        JOIN donor_profiles dp ON dp.user_id = dm.donor_id
        WHERE dm.request_id = ?
    ");
    $stmt2->execute([$id]);
    $request['matches'] = $stmt2->fetchAll();

    jsonResponse(true, 'OK', ['request' => $request]);
}

function createRequest(): void {
    $session = requireRole('hospital', 'admin');
    $db = getDB();
    $data = getRequestBody();

    $required = ['blood_type', 'units_needed', 'urgency'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse(false, "Field '$field' is required", [], 422);
        }
    }

    // Get hospital id
    $hospitalId = null;
    if ($session['role'] === 'hospital') {
        $stmt = $db->prepare("SELECT id FROM hospitals WHERE user_id = ?");
        $stmt->execute([$session['user_id']]);
        $hospital = $stmt->fetch();
        if (!$hospital) jsonResponse(false, 'Hospital profile not found', [], 404);
        $hospitalId = $hospital['id'];
    } else {
        $hospitalId = (int)($data['hospital_id'] ?? 0);
    }

    $stmt = $db->prepare("
        INSERT INTO blood_requests (hospital_id, requested_by, patient_name, blood_type, units_needed, urgency, reason, ward, location, latitude, longitude)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $hospitalId,
        $session['user_id'],
        sanitize($data['patient_name'] ?? 'Anonymous'),
        $data['blood_type'],
        (int)$data['units_needed'],
        $data['urgency'],
        sanitize($data['reason'] ?? ''),
        sanitize($data['ward'] ?? ''),
        sanitize($data['location'] ?? ''),
        $data['latitude'] ?? null,
        $data['longitude'] ?? null,
    ]);
    $requestId = $db->lastInsertId();

    // Auto-run matching
    runMatching($requestId, $db);

    jsonResponse(true, 'Blood request created and matching initiated', ['request_id' => $requestId], 201);
}

function updateRequest(int $id): void {
    $session = requireAuth();
    $db = getDB();
    $data = getRequestBody();

    $allowed = ['status', 'notes', 'fulfilled_at'];
    $updates = [];
    $params = [];
    foreach ($allowed as $field) {
        if (isset($data[$field])) {
            $updates[] = "$field = ?";
            $params[] = $data[$field];
        }
    }
    if (empty($updates)) jsonResponse(false, 'Nothing to update', [], 422);

    $params[] = $id;
    $stmt = $db->prepare("UPDATE blood_requests SET " . implode(', ', $updates) . " WHERE id = ?");
    $stmt->execute($params);

    jsonResponse(true, 'Request updated');
}

function runMatching(int $requestId, PDO $db): void {
    // Get request details
    $stmt = $db->prepare("SELECT * FROM blood_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) return;

    // Find compatible blood types
    $stmt = $db->prepare("SELECT donor_type FROM blood_compatibility WHERE recipient_type = ?");
    $stmt->execute([$request['blood_type']]);
    $compatibleTypes = array_column($stmt->fetchAll(), 'donor_type');
    if (empty($compatibleTypes)) return;

    $placeholders = implode(',', array_fill(0, count($compatibleTypes), '?'));

    // Find eligible available donors
    $stmt = $db->prepare("
        SELECT u.id, u.latitude, u.longitude, u.full_name,
               dp.blood_type, dp.blood_type_verified
        FROM users u
        JOIN donor_profiles dp ON dp.user_id = u.id
        WHERE dp.blood_type IN ($placeholders)
          AND dp.is_eligible = 1
          AND dp.availability_status = 'available'
          AND u.is_active = 1
          AND u.id NOT IN (
              SELECT donor_id FROM donor_matches WHERE request_id = ?
          )
        ORDER BY dp.blood_type_verified DESC
        LIMIT 20
    ");
    $stmt->execute(array_merge($compatibleTypes, [$requestId]));
    $donors = $stmt->fetchAll();

    if (empty($donors)) return;

    // Calculate distances and insert matches
    $insertStmt = $db->prepare("
        INSERT IGNORE INTO donor_matches (request_id, donor_id, distance_km, match_score, status)
        VALUES (?, ?, ?, ?, 'notified')
    ");
    $notifStmt = $db->prepare("
        INSERT INTO notifications (user_id, type, title, message, related_request_id)
        VALUES (?, 'emergency_request', ?, ?, ?)
    ");

    foreach ($donors as $donor) {
        $distKm = 999;
        if ($donor['latitude'] && $donor['longitude'] && $request['latitude'] && $request['longitude']) {
            $distKm = haversineDistance(
                (float)$donor['latitude'], (float)$donor['longitude'],
                (float)$request['latitude'], (float)$request['longitude']
            );
        }
        $score = ($donor['blood_type_verified'] ? 20 : 0) + max(0, 100 - $distKm);

        $insertStmt->execute([$requestId, $donor['id'], $distKm, $score]);

        $urgencyLabel = strtoupper($request['urgency']);
        $notifStmt->execute([
            $donor['id'],
            "[$urgencyLabel] {$request['blood_type']} Blood Needed",
            "A {$request['blood_type']} donor is urgently needed. Distance: ~{$distKm}km. Please respond ASAP.",
            $requestId
        ]);
    }

    // Update request status to matched
    $db->prepare("UPDATE blood_requests SET status='matched' WHERE id = ? AND status='open'")->execute([$requestId]);
}
