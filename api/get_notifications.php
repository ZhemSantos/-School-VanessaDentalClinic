<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

try {
    // Parameters
    $filter = $_GET['filter'] ?? 'all';
    $type = $_GET['type'] ?? 'all';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;

    // Base WHERE clause
    $whereSQL = "(user_id = ? OR (user_id IS NULL AND target_role = ?))";
    $params = [$user_id, $role];

    // Apply Filter
    if ($filter === 'unread') {
        $whereSQL .= " AND is_read = 0";
    }

    // Apply Type
    if ($type !== 'all') {
        $whereSQL .= " AND type = ?";
        $params[] = $type;
    }

    // 1. Get Unread Count (Global, ignoring current type/limit filters for the badge)
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tbl_notifications 
        WHERE (user_id = ? OR (user_id IS NULL AND target_role = ?))
        AND is_read = 0
    ");
    $countStmt->execute([$user_id, $role]);
    $unread_count = $countStmt->fetchColumn();

    // 2. Get Notifications
    $stmt = $pdo->prepare("
        SELECT * 
        FROM tbl_notifications 
        WHERE $whereSQL
        ORDER BY created_at DESC 
        LIMIT $limit
    ");
    $stmt->execute($params);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format relative time (e.g., "2 mins ago")
    foreach ($notifications as &$notif) {
        $notif['time_ago'] = time_elapsed_string($notif['created_at']);
        // Sanitize output
        $notif['title'] = htmlspecialchars($notif['title'] ?? 'Notification');
        $notif['message'] = htmlspecialchars($notif['message']);
        $notif['link'] = htmlspecialchars($notif['link'] ?? '#');
    }

    echo json_encode([
        'unread_count' => $unread_count,
        'notifications' => $notifications
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}

// Helper for relative time
function time_elapsed_string($datetime, $full = false) {
    if ($datetime == '0000-00-00 00:00:00') return "Just now";
    
    $now = new DateTime('now', new DateTimeZone('Asia/Manila'));
    $ago = new DateTime($datetime, new DateTimeZone('Asia/Manila'));
    $diff = $now->diff($ago);

    $diff->w = floor($diff->d / 7);
    $diff->d -= $diff->w * 7;

    $string = array(
        'y' => 'year',
        'm' => 'month',
        'w' => 'week',
        'd' => 'day',
        'h' => 'hour',
        'i' => 'minute',
        's' => 'second',
    );
    foreach ($string as $k => &$v) {
        if ($diff->$k) {
            $v = $diff->$k . ' ' . $v . ($diff->$k > 1 ? 's' : '');
        } else {
            unset($string[$k]);
        }
    }

    if (!$full) $string = array_slice($string, 0, 1);
    return $string ? implode(', ', $string) . ' ago' : 'Just now';
}
?>
