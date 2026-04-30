<?php
// ============================================================
// LifeLink - Donors API
// GET  /api/donors.php            - List donors (admin/health_worker)
// GET  /api/donors.php?id=X       - Get donor profile
// PUT  /api/donors.php?id=X       - Update donor profile
// PUT  /api/donors.php?id=X&action=verify - Verify blood type
// PUT  /api/donors.php?id=X&action=respond - Accept/decline match
// GET  /api/donors.php?action=history - Donation history
// ============================================================
require_once __DIR__ . '/config.php';
startSessionIfNeeded();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($method) {
    case 'GET':
        if ($action === 'history') getDonationHistory();
        elseif ($action === 'stats') getDonorStats();
        elseif ($id) getDonorProfile($id);
        else listDonors();
        break;
    case 'PUT':
        if ($action === 'respond_request') { respondToMatchByRequest(); break; }
        if (!$id) jsonResponse(false, 'ID required', [], 400);
        if ($action === 'verify')        verifyBloodType($id);
        elseif ($action === 'respond')   respondToMatch($id);
        elseif ($action === 'availability') updateAvailability($id);
        else updateDonorProfile($id);
        break;
    default:
        jsonResponse(false, 'Method not allowed', [], 405);
}

function listDonors(): void {
    $session = requireRole('admin', 'health_worker', 'hospital');
    $db = getDB();

    $search = $_GET['search'] ?? '';
    $blood_type = $_GET['blood_type'] ?? '';
    $verified = $_GET['verified'] ?? '';
    $availability = $_GET['availability'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = ['u.role = "donor"', 'u.is_active = 1'];
    $params = [];

    if ($search) {
        $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.city LIKE ?)';
        $s = "%$search%";
        $params = array_merge($params, [$s, $s, $s]);
    }
    if ($blood_type) { $where[] = 'dp.blood_type = ?'; $params[] = $blood_type; }
    if ($verified !== '') { $where[] = 'dp.blood_type_verified = ?'; $params[] = (int)$verified; }
    if ($availability) { $where[] = 'dp.availability_status = ?'; $params[] = $availability; }

    $whereStr = implode(' AND ', $where);
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.city, u.region, u.is_verified,
               u.latitude, u.longitude,
               dp.blood_type, dp.blood_type_verified, dp.availability_status,
               dp.total_donations, dp.last_donation_date, dp.is_eligible
        FROM Users u
        JOIN donor_profiles dp ON dp.user_id = u.id
        WHERE $whereStr
        ORDER BY dp.blood_type_verified DESC, u.full_name ASC
        LIMIT ? OFFSET ?
    ");
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);

    jsonResponse(true, 'OK', ['donors' => $stmt->fetchAll()]);
}

function getDonorProfile(int $id): void {
    $session = requireAuth();
    // Donors can only see their own profile unless admin/health_worker
    if ($session['role'] === 'donor' && $session['user_id'] !== $id) {
        jsonResponse(false, 'Forbidden', [], 403);
    }

    $db = getDB();
    $stmt = $db->prepare("
        SELECT u.id, u.full_name, u.email, u.phone, u.city, u.region, u.location,
               u.is_verified, u.profile_photo, u.created_at,
               dp.*
        FROM Users u
        JOIN donor_profiles dp ON dp.user_id = u.id
        WHERE u.id = ? AND u.role = 'donor'
    ");
    $stmt->execute([$id]);
    $donor = $stmt->fetch();
    if (!$donor) jsonResponse(false, 'Donor not found', [], 404);

    jsonResponse(true, 'OK', ['donor' => $donor]);
}

function updateDonorProfile(int $id): void {
    $session = requireAuth();
    if ($session['role'] === 'donor' && $session['user_id'] !== $id) {
        jsonResponse(false, 'Forbidden', [], 403);
    }

    $db = getDB();
    $data = getRequestBody();

    // Update user table
    $userFields = ['full_name', 'phone', 'location', 'city', 'region'];
    $userUpdates = [];
    $userParams = [];
    foreach ($userFields as $f) {
        if (isset($data[$f])) { $userUpdates[] = "$f = ?"; $userParams[] = sanitize($data[$f]); }
    }
    if ($userUpdates) {
        $userParams[] = $id;
        $db->prepare("UPDATE Users SET " . implode(', ', $userUpdates) . " WHERE id = ?")->execute($userParams);
    }

    // Update donor profile
    $dpFields = ['blood_type', 'date_of_birth', 'gender', 'weight_kg', 'emergency_contact_name',
                 'emergency_contact_phone', 'medical_conditions'];
    $dpUpdates = [];
    $dpParams = [];
    foreach ($dpFields as $f) {
        if (isset($data[$f])) {
            $dpUpdates[] = "$f = ?";
            $dpParams[] = in_array($f, ['medical_conditions']) ? $data[$f] : sanitize($data[$f]);
        }
    }
    if ($dpUpdates) {
        $dpParams[] = $id;
        $db->prepare("UPDATE donor_profiles SET " . implode(', ', $dpUpdates) . " WHERE user_id = ?")->execute($dpParams);
    }

    jsonResponse(true, 'Profile updated');
}

function verifyBloodType(int $donorId): void {
    $session = requireRole('admin', 'health_worker');
    $db   = getDB();
    $data = getRequestBody();

    if (empty($data['blood_type'])) jsonResponse(false, 'blood_type required', [], 422);

    // Core action: update the blood type record
    $stmt = $db->prepare("
        UPDATE donor_profiles
        SET blood_type = ?, blood_type_verified = 1, verified_by = ?, verified_at = NOW()
        WHERE user_id = ?
    ");
    $stmt->execute([$data['blood_type'], $session['user_id'], $donorId]);

    // OBSERVER PATTERN — fire 'blood_type_verified' event.
    // The notification INSERT and is_verified UPDATE that used to live here
    // are now inside BloodTypeVerifiedObserver::update().
    // This function no longer "knows" about notifications at all.
    $subject = new EventSubject();
    $subject->addObserver('blood_type_verified', new BloodTypeVerifiedObserver($db));
    $subject->notifyObservers('blood_type_verified', [
        'donor_id'   => $donorId,
        'blood_type' => $data['blood_type'],
    ]);

    jsonResponse(true, 'Blood type verified');
}

function respondToMatch(int $matchId): void {
    $session = requireRole('donor');
    $db = getDB();
    $data = getRequestBody();

    if (!in_array($data['response'] ?? '', ['accepted', 'declined'])) {
        jsonResponse(false, 'Response must be accepted or declined', [], 422);
    }

    $stmt = $db->prepare("SELECT * FROM donor_matches WHERE id = ? AND donor_id = ?");
    $stmt->execute([$matchId, $session['user_id']]);
    $match = $stmt->fetch();
    if (!$match) jsonResponse(false, 'Match not found', [], 404);

    $db->prepare("UPDATE donor_matches SET status = ?, responded_at = NOW() WHERE id = ?")->execute([$data['response'], $matchId]);

    if ($data['response'] === 'accepted') {
        // Update request status
        $db->prepare("UPDATE blood_requests SET status = 'in_progress' WHERE id = ?")->execute([$match['request_id']]);

        // Notify the hospital and donor about the acceptance
        $stmt = $db->prepare("
            SELECT br.patient_name, br.blood_type, h.user_id as hospital_user_id, h.hospital_name, u.full_name as donor_name
            FROM blood_requests br
            JOIN hospitals h ON h.id = br.hospital_id
            JOIN Users u ON u.id = ?
            WHERE br.id = ?
        ");
        $stmt->execute([$session['user_id'], $match['request_id']]);
        $row = $stmt->fetch();
        if ($row) {
            $db->prepare("INSERT INTO notifications (user_id, type, title, message, related_request_id) VALUES (?, 'match_found', 'Donor Committed ✓', ?, ?)")
               ->execute([
                   $row['hospital_user_id'],
                   "{$row['donor_name']} committed to donate {$row['blood_type']} for {$row['patient_name']}.",
                   $match['request_id'],
               ]);
            $db->prepare("INSERT INTO notifications (user_id, type, title, message, related_request_id) VALUES (?, 'match_found', 'Donation Confirmed ✓', ?, ?)")
               ->execute([
                   $session['user_id'],
                   "Your commitment to donate {$row['blood_type']} for {$row['patient_name']} has been sent to {$row['hospital_name']}.",
                   $match['request_id'],
               ]);
        }
    }

    jsonResponse(true, 'Response recorded');
}

function updateAvailability(int $id): void {
    $session = requireAuth();
    if ($session['role'] === 'donor' && $session['user_id'] !== $id) {
        jsonResponse(false, 'Forbidden', [], 403);
    }
    $db = getDB();
    $data = getRequestBody();
    $allowed = ['available', 'unavailable'];
    if (!in_array($data['status'] ?? '', $allowed)) jsonResponse(false, 'Invalid status', [], 422);
    $db->prepare("UPDATE donor_profiles SET availability_status = ? WHERE user_id = ?")->execute([$data['status'], $id]);
    jsonResponse(true, 'Availability updated');
}

function getDonationHistory(): void {
    $session = requireAuth();
    $db = getDB();
    $userId = $_GET['user_id'] ?? $session['user_id'];
    if ($session['role'] === 'donor') $userId = $session['user_id'];

    $stmt = $db->prepare("
        SELECT dr.*, h.hospital_name, u.full_name as verified_by_name
        FROM donation_records dr
        LEFT JOIN hospitals h ON h.id = dr.hospital_id
        LEFT JOIN Users u ON u.id = dr.verified_by
        WHERE dr.donor_id = ?
        ORDER BY dr.donation_date DESC
    ");
    $stmt->execute([$userId]);
    jsonResponse(true, 'OK', ['history' => $stmt->fetchAll()]);
}

// Donor responds to a request using request_id (from notification).
// Looks up the donor_matches row automatically — caller only needs request_id.
function respondToMatchByRequest(): void {
    $session = requireRole('donor');
    $db   = getDB();
    $data = getRequestBody();

    if (!in_array($data['response'] ?? '', ['accepted', 'declined'])) {
        jsonResponse(false, 'Response must be accepted or declined', [], 422);
    }
    $requestId = (int)($data['request_id'] ?? 0);
    if (!$requestId) jsonResponse(false, 'request_id is required', [], 422);

    // Find this donor's pending match for the given request
    $stmt = $db->prepare("
        SELECT * FROM donor_matches
        WHERE request_id = ? AND donor_id = ? AND status = 'notified'
    ");
    $stmt->execute([$requestId, $session['user_id']]);
    $match = $stmt->fetch();

    if (!$match) jsonResponse(false, 'No pending match found for this request', [], 404);

    $db->prepare("UPDATE donor_matches SET status = ?, responded_at = NOW() WHERE id = ?")
       ->execute([$data['response'], $match['id']]);

    if ($data['response'] === 'accepted') {
        // Update request status
        $db->prepare("UPDATE blood_requests SET status = 'in_progress' WHERE id = ? AND status NOT IN ('fulfilled','cancelled')")
           ->execute([$requestId]);

        // Get request details and hospital info to notify them
        $requestStmt = $db->prepare("
            SELECT br.id, br.blood_type, br.patient_name, br.urgency,
                   h.user_id as hospital_user_id, h.hospital_name,
                   u.full_name as donor_name
            FROM blood_requests br
            JOIN hospitals h ON h.id = br.hospital_id
            JOIN Users u ON u.id = ?
            WHERE br.id = ?
        ");
        $requestStmt->execute([$session['user_id'], $requestId]);
        $requestData = $requestStmt->fetch();

        if ($requestData) {
            // Notify the hospital that a donor has accepted
            $db->prepare("INSERT INTO notifications (user_id, type, title, message, related_request_id) VALUES (?, 'match_found', 'Donor Committed ✓', ?, ?)")
               ->execute([
                   $requestData['hospital_user_id'],
                   "{$requestData['donor_name']} committed to donate {$requestData['blood_type']} for {$requestData['patient_name']}.",
                   $requestId,
               ]);
            // Notify the donor that their commitment was recorded
            $db->prepare("INSERT INTO notifications (user_id, type, title, message, related_request_id) VALUES (?, 'match_found', 'Donation Confirmed ✓', ?, ?)")
               ->execute([
                   $session['user_id'],
                   "Your commitment to donate {$requestData['blood_type']} for {$requestData['patient_name']} has been sent to {$requestData['hospital_name']}.",
                   $requestId,
               ]);
        }
    }

    jsonResponse(true, 'Response recorded');
}

function getDonorStats(): void {
    $session = requireRole('donor');
    $db = getDB();
    $userId = $session['user_id'];

    $stats = [];
    $stmt = $db->prepare("SELECT total_donations, last_donation_date, availability_status, blood_type, blood_type_verified FROM donor_profiles WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats = $stmt->fetch() ?: [];

    // Active matches
    $stmt2 = $db->prepare("SELECT COUNT(*) as pending FROM donor_matches WHERE donor_id = ? AND status = 'notified'");
    $stmt2->execute([$userId]);
    $stats['pending_matches'] = $stmt2->fetch()['pending'];

    // Lives estimate
    $stats['lives_impacted'] = ($stats['total_donations'] ?? 0) * 3;

    jsonResponse(true, 'OK', ['stats' => $stats]);
}
