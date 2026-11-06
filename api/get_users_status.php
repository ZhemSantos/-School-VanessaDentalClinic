<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

header('Content-Type: application/json');

// Only logged in users (admins/staff) can check status
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get ID and last_active for all users (or filter if needed, but for small team all is fine)
    // Also get 'status' column in case they were deactivated
    $stmt = $pdo->query("SELECT user_id, last_active, status, role FROM tbl_user");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $data = [];
    foreach ($users as $user) {
        $data[$user['user_id']] = [
            'last_active' => $user['last_active'],
            'status' => $user['status'],
            'is_online' => isUserActive($user['last_active'])
        ];
    }
    
    echo json_encode(['success' => true, 'users' => $data]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>
