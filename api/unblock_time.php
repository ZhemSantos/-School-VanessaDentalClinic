<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/notification_helper.php';

configureSecureSession();
requireRole('Dentist');

header('Content-Type: application/json');

$doctor_id = $_SESSION['user_id'] ?? null;
if (!$doctor_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$block_id = isset($input['block_id']) ? intval($input['block_id']) : 0;

if ($block_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid Block ID']);
    exit;
}

try {
    // Verify ownership and get details for notification
    $stmt = $pdo->prepare("SELECT * FROM tbl_blocked_time WHERE block_id = ? AND doctor_id = ?");
    $stmt->execute([$block_id, $doctor_id]);
    $block = $stmt->fetch();

    if (!$block) {
        echo json_encode(['success' => false, 'message' => 'Blocked time not found or access denied']);
        exit;
    }

    // Delete
    $delStmt = $pdo->prepare("DELETE FROM tbl_blocked_time WHERE block_id = ?");
    $delStmt->execute([$block_id]);

    // Notify Receptionists
    try {
        $doctor_name = $_SESSION['full_name'] ?? 'A Doctor';
        
        $recStmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE role = 'Receptionist' AND status = 'Active'");
        $recStmt->execute();
        $receptionists = $recStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $dateStr = date('M d, Y', strtotime($block['block_date']));
        $timeStr = date('g:i A', strtotime($block['start_time'])) . ' - ' . date('g:i A', strtotime($block['end_time']));
        $msg = "Dr. $doctor_name has UNBLOCKED time on $dateStr ($timeStr).";
        
        foreach ($receptionists as $rec_id) {
            createNotification(
                $pdo, 
                $rec_id, 
                'Receptionist', 
                'System', 
                "Schedule Unblocked", 
                $msg, 
                "receptionist_booking.php"
            );
        }
    } catch (Exception $e) {
        error_log("Notification error during unblock: " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'message' => 'Time unblocked successfully.']);

} catch (PDOException $e) {
    error_log('Error unblocking time: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>
