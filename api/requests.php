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
        if ($id) {
            if (isset($_GET['match_id'])) updateMatchStatus($id);
            else updateRequest($id);
        } else {
            jsonResponse(false, 'ID required', [], 400);
        }
        break;
    default:
        jsonResponse(false, 'Method not allowed', [], 405);
}

function getRequests(): void {
    $db = getDB();
    $session = $_SESSION ?? null;
    $params = [];

    $status     = $_GET['status']     ?? null;
    $blood_type = $_GET['blood_type'] ?? null;
    $urgency    = $_GET['urgency']    ?? null;
    $limit  = min((int)($_GET['limit']  ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = ['1=1'];

    // 'active' is a virtual filter meaning open+matched+in_progress —
    // the statuses a donor can still act on. Any individual status value
    // (open, matched, in_progress, fulfilled, cancelled) is also accepted.
    if ($status === 'active') {
        $where[] = "br.status IN ('open','matched','in_progress')";
    } elseif ($status) {
        $where[] = 'br.status = ?';
        $params[] = $status;
    }

    if ($blood_type) { $where[] = 'br.blood_type = ?'; $params[] = $blood_type; }
    if ($urgency)    { $where[] = 'br.urgency    = ?'; $params[] = $urgency; }

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
        JOIN Users u ON u.id = br.requested_by
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
        JOIN Users u ON u.id = br.requested_by
        WHERE br.id = ?
    ");
    $stmt->execute([$id]);
    $request = $stmt->fetch();

    if (!$request) jsonResponse(false, 'Request not found', [], 404);

    // Get matches
    $stmt2 = $db->prepare("
        SELECT dm.*, u.full_name as donor_name, dp.blood_type as donor_blood_type, dp.blood_type_verified
        FROM donor_matches dm
        JOIN Users u ON u.id = dm.donor_id
        JOIN donor_profiles dp ON dp.user_id = dm.donor_id
        WHERE dm.request_id = ?
    ");
    $stmt2->execute([$id]);
    $request['matches'] = $stmt2->fetchAll();

    jsonResponse(true, 'OK', ['request' => $request]);
}

function createRequest(): void {
    // hospital: creates requests for their own facility (hospital_id derived from session)
    // admin + health_worker: must select a hospital — hospital_id comes from the form payload
    $session = requireRole('hospital', 'admin', 'health_worker');
    $db = getDB();
    $data = getRequestBody();

    $required = ['blood_type', 'units_needed', 'urgency'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse(false, "Field '$field' is required", [], 422);
        }
    }

    // Resolve hospital_id from session (hospital role) or from the payload (admin/health_worker)
    $hospitalId = null;
    if ($session['role'] === 'hospital') {
        $stmt = $db->prepare("SELECT id FROM hospitals WHERE user_id = ?");
        $stmt->execute([$session['user_id']]);
        $hospital = $stmt->fetch();
        if (!$hospital) jsonResponse(false, 'Hospital profile not found', [], 404);
        $hospitalId = $hospital['id'];
    } else {
        $hospitalId = (int)($data['hospital_id'] ?? 0);
        if ($hospitalId <= 0) {
            jsonResponse(false, 'Please select a hospital before submitting the request', [], 422);
        }
        // Confirm the chosen hospital actually exists and is approved
        $stmt = $db->prepare("SELECT id FROM hospitals WHERE id = ? AND is_approved = 1");
        $stmt->execute([$hospitalId]);
        if (!$stmt->fetch()) {
            jsonResponse(false, 'Selected hospital not found or not approved', [], 404);
        }
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

    // If status is being set to 'fulfilled', create donation records and notify donors
    if (isset($data['status']) && $data['status'] === 'fulfilled') {
        handleRequestFulfilled($id, $db);
    }

    jsonResponse(true, 'Request updated');
}

function handleRequestFulfilled(int $requestId, PDO $db): void {
    // Get the request details and accepted donors
    $stmt = $db->prepare("
        SELECT br.id, br.blood_type, br.units_needed, br.hospital_id, h.user_id as hospital_user_id,
               dm.donor_id, dm.id as match_id,
               u.full_name as donor_name
        FROM blood_requests br
        JOIN hospitals h ON h.id = br.hospital_id
        LEFT JOIN donor_matches dm ON dm.request_id = br.id AND dm.status = 'accepted'
        LEFT JOIN Users u ON u.id = dm.donor_id
        WHERE br.id = ?
    ");
    $stmt->execute([$requestId]);
    $data = $stmt->fetchAll();

    if (empty($data)) return;

    $request = $data[0];
    $unitsPerDonor = $request['units_needed'] / max(1, count(array_filter(array_column($data, 'donor_id'))));

    $donationDate = date('Y-m-d'); // Today's date

    // Create donation records for all accepted donors
    foreach ($data as $row) {
        if (!$row['donor_id']) continue; // Skip if no donor

        // Create donation record
        $db->prepare("
            INSERT INTO donation_records (donor_id, match_id, hospital_id, blood_type, units_donated, donation_date)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([
            $row['donor_id'],
            $row['match_id'],
            $row['hospital_id'],
            $row['blood_type'],
            $unitsPerDonor,
            $donationDate,
        ]);

        // Update donor profile stats
        $db->prepare("
            UPDATE donor_profiles
            SET total_donations = total_donations + 1,
                last_donation_date = ?
            WHERE user_id = ?
        ")->execute([$donationDate, $row['donor_id']]);

        // Notify donor that their donation has been recorded
        $db->prepare("
            INSERT INTO notifications (user_id, type, title, message, related_request_id)
            VALUES (?, 'request_fulfilled', 'Donation Completed ✓', ?, ?)
        ")->execute([
            $row['donor_id'],
            "Your donation of {$row['blood_type']} has been recorded. Thank you for saving lives!",
            $requestId,
        ]);
    }
}

function updateMatchStatus(int $requestId): void {
    $session = requireRole('hospital', 'admin');
    $db = getDB();
    $data = getRequestBody();
    $matchId = isset($_GET['match_id']) ? (int)$_GET['match_id'] : 0;
    $status = $data['status'] ?? null;

    if (!$matchId || !in_array($status, ['accepted', 'declined'])) {
        jsonResponse(false, 'Invalid match id or status', [], 422);
    }

    // Verify match belongs to this request and user owns the request
    $stmt = $db->prepare("SELECT br.hospital_id FROM blood_requests br JOIN donor_matches dm ON dm.request_id = br.id WHERE br.id = ? AND dm.id = ?");
    $stmt->execute([$requestId, $matchId]);
    $owner = $stmt->fetch();
    if (!$owner) jsonResponse(false, 'Match not found for request', [], 404);

    if ($session['role'] === 'hospital') {
        $stmt = $db->prepare('SELECT id FROM hospitals WHERE user_id = ? LIMIT 1');
        $stmt->execute([$session['user_id']]);
        $hospital = $stmt->fetch();
        if (!$hospital || $hospital['id'] !== $owner['hospital_id']) {
            jsonResponse(false, 'Not authorized for this request', [], 403);
        }
    }

    $stmt = $db->prepare('UPDATE donor_matches SET status = ?, responded_at = NOW() WHERE id = ?');
    $stmt->execute([$status, $matchId]);

    if ($status === 'accepted') {
        $db->prepare("UPDATE blood_requests SET status = 'in_progress' WHERE id = ? AND status NOT IN ('fulfilled','cancelled')")->execute([$requestId]);
    }

    jsonResponse(true, 'Match status updated');
}

function runMatching(int $requestId, PDO $db): void {
    // Get request details
    $stmt = $db->prepare("SELECT * FROM blood_requests WHERE id = ?");
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) return;

    // Find compatible blood types from the compatibility table
    $stmt = $db->prepare("SELECT donor_type FROM blood_compatibility WHERE recipient_type = ?");
    $stmt->execute([$request['blood_type']]);
    $compatibleTypes = array_column($stmt->fetchAll(), 'donor_type');
    if (empty($compatibleTypes)) return;

    $placeholders = implode(',', array_fill(0, count($compatibleTypes), '?'));

    // Find eligible available donors
    $stmt = $db->prepare("
        SELECT u.id, u.latitude, u.longitude, u.full_name,
               dp.blood_type, dp.blood_type_verified
        FROM Users u
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

    // Calculate distance + score for each donor (pure algorithm, no side effects)
    $scoredDonors = [];
    foreach ($donors as $donor) {
        $distKm = 999;
        if ($donor['latitude'] && $donor['longitude'] && $request['latitude'] && $request['longitude']) {
            $distKm = haversineDistance(
                (float)$donor['latitude'],  (float)$donor['longitude'],
                (float)$request['latitude'], (float)$request['longitude']
            );
        }
        $donor['distance_km'] = $distKm;
        $donor['match_score'] = ($donor['blood_type_verified'] ? 20 : 0) + max(0, 100 - $distKm);
        $scoredDonors[] = $donor;
    }

    // OBSERVER PATTERN — fire 'blood_request_created' event.
    // The donor_matches INSERT and notifications INSERT that used to live
    // in the loop above are now inside DonorMatchNotificationObserver::update().
    // runMatching() no longer knows or cares about notifications.
    $subject = new EventSubject();
    $subject->addObserver('blood_request_created', new DonorMatchNotificationObserver($db));
    $subject->notifyObservers('blood_request_created', [
        'request' => $request,
        'donors'  => $scoredDonors,
    ]);

    // Update request status to matched
    $db->prepare("UPDATE blood_requests SET status='matched' WHERE id = ? AND status='open'")->execute([$requestId]);
}
