<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$patient_id = $_SESSION['patient_id'];

try {
    switch ($action) {
        case 'get_unread_count':
            getUnreadCount($pdo, $patient_id);
            break;
        case 'get_notifications':
            getNotifications($pdo, $patient_id);
            break;
        case 'mark_read':
            markAsRead($pdo, $patient_id);
            break;
        case 'mark_all_read':
            markAllAsRead($pdo, $patient_id);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function getUnreadCount($pdo, $patient_id) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_notifications WHERE patient_id = ? AND is_read = 0");
    $stmt->execute([$patient_id]);
    $count = $stmt->fetchColumn();
    
    echo json_encode(['success' => true, 'count' => (int)$count]);
}

function getNotifications($pdo, $patient_id) {
    $limit = $_GET['limit'] ?? 10;
    
    $stmt = $pdo->prepare("
        SELECT notification_id, title, message, type, is_read, created_at
        FROM tbl_notifications
        WHERE patient_id = ?
        ORDER BY created_at DESC
        LIMIT ?
    ");
    $stmt->execute([$patient_id, (int)$limit]);
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'notifications' => $notifications]);
}

function markAsRead($pdo, $patient_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $notification_id = $input['notification_id'] ?? 0;
    
    $stmt = $pdo->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE notification_id = ? AND patient_id = ?");
    $stmt->execute([$notification_id, $patient_id]);
    
    echo json_encode(['success' => true]);
}

function markAllAsRead($pdo, $patient_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    
    $stmt = $pdo->prepare("UPDATE tbl_notifications SET is_read = 1 WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    
    echo json_encode(['success' => true]);
}
?>
