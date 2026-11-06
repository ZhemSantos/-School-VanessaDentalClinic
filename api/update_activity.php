<?php
/**
 * API endpoint to update user activity
 * Called via AJAX to keep user status active while on page
 */
session_start();
require_once '../db.php';
require_once '../includes/security.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE tbl_user SET last_active = NOW() WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    echo json_encode(['success' => true, 'timestamp' => date('Y-m-d H:i:s')]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

