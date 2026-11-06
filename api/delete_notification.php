<?php
session_start();
require_once '../db.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'] ?? '';

// Get JSON input
$data = json_decode(file_get_contents('php://input'), true);
$action = $data['action'] ?? ''; // 'single' or 'all'
$notification_id = $data['notification_id'] ?? null;

try {
    if ($action === 'all') {
        // Delete all notifications for this user/role
        // Note: Deleting broadcast messages for one user is tricky without a mapping table.
        // For now, we will DELETE rows where user_id matches. 
        // For Broadcasts (user_id IS NULL), we CANNOT delete them from the table as it affects others.
        // Ideally, we'd have a `tbl_hidden_notifications` to hide broadcasts for a specific user.
        // SYSTEM LIMITATION: We will only delete PERSONAL notifications (user_id = ID).
        // Broadcasts will remain (maybe just mark as read).
        // Or we warn the user.
        
        // Actually, let's just delete personal ones.
        $stmt = $pdo->prepare("DELETE FROM tbl_notifications WHERE user_id = ?");
        $stmt->execute([$user_id]);
        
        // Optional: If you really want to 'clear' broadcasts from view, you'd need a separate table.
        // We'll stick to personal deletion for safety or mark-as-read for all.
        
    } elseif ($action === 'single' && $notification_id) {
        // Check if it's a personal notification or broadcast
        $check = $pdo->prepare("SELECT user_id FROM tbl_notifications WHERE notification_id = ?");
        $check->execute([$notification_id]);
        $notif = $check->fetch();

        if ($notif) {
            if ($notif['user_id'] == $user_id) {
                // Personal: Delete it
                $del = $pdo->prepare("DELETE FROM tbl_notifications WHERE notification_id = ?");
                $del->execute([$notification_id]);
            } else {
                // Broadcast: We can't delete the record itself. 
                // We will just do nothing for now (or implement a 'hidden' table later).
                // Or maybe the user just wants to mark it read/dismiss it from UI.
                // UI should probably handle 'dismiss' of broadcast by storing ID in 'tbl_user_hidden_notifs' (if we had it)
                // For simplified scope: We will allow deleting ONLY personal notifications.
            }
        }
    }

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
?>
