<?php
session_start();
require_once 'db.php';

// Clear last_active timestamp when user logs out
// Clear last_active timestamp when user logs out
if (isset($_SESSION['user_id'])) {
    try {
        $role = $_SESSION['role'] ?? '';
        if ($role === 'Patient') {
            $stmt = $pdo->prepare("UPDATE tbl_patient_accounts SET last_active = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE account_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        } else {
            // Default to tbl_user for staff
            $stmt = $pdo->prepare("UPDATE tbl_user SET last_active = DATE_SUB(NOW(), INTERVAL 10 MINUTE) WHERE user_id = ?");
            $stmt->execute([$_SESSION['user_id']]);
        }
    } catch (PDOException $e) {
        // Silently fail if column doesn't exist yet
        error_log('Logout activity update failed: ' . $e->getMessage());
    }
}

session_destroy();
header('Location: index.php');
exit;
?>

