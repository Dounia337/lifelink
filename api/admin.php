<?php

// LifeLink - Admin API
// GET /api/admin.php?action=stats|users|hospitals|approve
require_once __DIR__ . '/config.php';
startSessionIfNeeded();

$action = $_GET['action'] ?? 'stats';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {
    case 'stats':      getStats(); break;
    case 'users':      getUsers(); break;
    case 'hospitals':  getHospitals(); break;
    case 'approve':    approveHospital(); break;
    case 'toggle_user': toggleUser(); break;
    case 'notifications': getNotifications(); break;
    case 'mark_read':  markNotificationRead(); break;
    default:           jsonResponse(false, 'Unknown action', [], 400);
}

function getStats(): void {
    $session = requireRole('admin');
    $db = getDB();

    $stats = [];

    $queries = [
        'total_donors'     => "SELECT COUNT(*) FROM users WHERE role='donor' AND is_active=1",
        'verified_donors'  => "SELECT COUNT(*) FROM donor_profiles WHERE blood_type_verified=1",
        'total_hospitals'  => "SELECT COUNT(*) FROM hospitals WHERE is_approved=1",
        'open_requests'    => "SELECT COUNT(*) FROM blood_requests WHERE status IN ('open','matched','in_progress')",
        'fulfilled_today'  => "SELECT COUNT(*) FROM blood_requests WHERE status='fulfilled' AND DATE(fulfilled_at)=CURDATE()",
        'total_donations'  => "SELECT COUNT(*) FROM donation_records",
        'pending_hospitals'=> "SELECT COUNT(*) FROM hospitals WHERE is_approved=0",
    ];

    foreach ($queries as $key => $sql) {
        $stats[$key] = $db->query($sql)->fetchColumn();
    }

    // Fulfillment rate
    $total = $db->query("SELECT COUNT(*) FROM blood_requests")->fetchColumn();
    $fulfilled = $db->query("SELECT COUNT(*) FROM blood_requests WHERE status='fulfilled'")->fetchColumn();
    $stats['fulfillment_rate'] = $total > 0 ? round(($fulfilled / $total) * 100, 1) : 0;

    // Recent activity
    $stmt = $db->prepare("
        SELECT br.id, br.blood_type, br.urgency, br.status, br.created_at, h.hospital_name
        FROM blood_requests br
        JOIN hospitals h ON h.id = br.hospital_id
        ORDER BY br.created_at DESC LIMIT 10
    ");
    $stmt->execute();
    $stats['recent_requests'] = $stmt->fetchAll();

    jsonResponse(true, 'OK', ['stats' => $stats]);
}

function getUsers(): void {
    $session = requireRole('admin');
    $db = getDB();

    $role = $_GET['role'] ?? null;
    $search = $_GET['search'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);

    $where = ['1=1'];
    $params = [];
    if ($role) { $where[] = 'role = ?'; $params[] = $role; }
    if ($search) {
        $where[] = '(full_name LIKE ? OR email LIKE ?)';
        $params[] = "%$search%"; $params[] = "%$search%";
    }

    $stmt = $db->prepare("
        SELECT id, full_name, email, phone, role, city, is_active, is_verified, created_at
        FROM users
        WHERE " . implode(' AND ', $where) . "
        ORDER BY created_at DESC LIMIT ? OFFSET ?
    ");
    $params[] = $limit; $params[] = $offset;
    $stmt->execute($params);

    jsonResponse(true, 'OK', ['users' => $stmt->fetchAll()]);
}

function getHospitals(): void {
    $session = requireRole('admin');
    $db = getDB();

    $approved = $_GET['approved'] ?? null;
    $params = [];
    $where = ['1=1'];
    if ($approved !== null) { $where[] = 'h.is_approved = ?'; $params[] = (int)$approved; }

    $stmt = $db->prepare("
        SELECT h.*, u.full_name, u.email, u.phone, u.is_active
        FROM hospitals h
        JOIN users u ON u.id = h.user_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY h.is_approved ASC, h.id DESC
    ");
    $stmt->execute($params);
    jsonResponse(true, 'OK', ['hospitals' => $stmt->fetchAll()]);
}

function approveHospital(): void {
    $session = requireRole('admin');
    $db = getDB();
    $data = getRequestBody();
    $hospitalId = (int)($data['hospital_id'] ?? $_GET['hospital_id'] ?? 0);
    if (!$hospitalId) jsonResponse(false, 'hospital_id required', [], 422);

    $db->prepare("UPDATE hospitals SET is_approved=1, approved_by=?, approved_at=NOW() WHERE id=?")->execute([$session['user_id'], $hospitalId]);
    $db->prepare("UPDATE users SET is_verified=1 WHERE id=(SELECT user_id FROM hospitals WHERE id=?)")->execute([$hospitalId]);
    jsonResponse(true, 'Hospital approved');
}

function toggleUser(): void {
    $session = requireRole('admin');
    $db = getDB();
    $data = getRequestBody();
    $userId = (int)($data['user_id'] ?? 0);
    if (!$userId || $userId === $session['user_id']) jsonResponse(false, 'Invalid user_id', [], 422);
    $db->prepare("UPDATE users SET is_active = NOT is_active WHERE id=?")->execute([$userId]);
    jsonResponse(true, 'User status toggled');
}

function getNotifications(): void {
    $session = requireAuth();
    $db = getDB();
    $unreadOnly = (bool)($_GET['unread'] ?? false);
    $limit = min((int)($_GET['limit'] ?? 20), 50);

    $where = 'user_id = ?';
    $params = [$session['user_id']];
    if ($unreadOnly) { $where .= ' AND is_read = 0'; }

    $stmt = $db->prepare("
        SELECT n.*, br.blood_type as request_blood_type, br.urgency as request_urgency
        FROM notifications n
        LEFT JOIN blood_requests br ON br.id = n.related_request_id
        WHERE $where
        ORDER BY n.created_at DESC
        LIMIT ?
    ");
    $params[] = $limit;
    $stmt->execute($params);

    $notifs = $stmt->fetchAll();
    $unreadCount = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $unreadCount->execute([$session['user_id']]);

    jsonResponse(true, 'OK', ['notifications' => $notifs, 'unread_count' => $unreadCount->fetchColumn()]);
}

function markNotificationRead(): void {
    $session = requireAuth();
    $db = getDB();
    $data = getRequestBody();
    $notifId = (int)($data['id'] ?? 0);
    $all = (bool)($data['all'] ?? false);

    if ($all) {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$session['user_id']]);
    } elseif ($notifId) {
        $db->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?")->execute([$notifId, $session['user_id']]);
    }
    jsonResponse(true, 'Marked as read');
}
