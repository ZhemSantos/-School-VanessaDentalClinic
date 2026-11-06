<?php
// includes/notification_helper.php

/**
 * Create a new notification
 * 
 * @param PDO $pdo Database connection object
 * @param int|null $user_id Target user ID (NULL for broadcasting to role)
 * @param string|null $target_role Target role ('Admin', 'Doctor', 'Receptionist') if user_id is NULL
 * @param string $type Notification type ('Appointment', 'System', 'Inventory', 'Payment')
 * @param string $message Notification message
 * @param string|null $link Optional link to redirect to
 * @return bool True on success, False on failure
 */
function createNotification($pdo, $user_id, $target_role, $type, $title, $message, $link = null) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO tbl_notifications (user_id, target_role, type, title, message, link) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        return $stmt->execute([$user_id, $target_role, $type, $title, $message, $link]);
    } catch (PDOException $e) {
        // Log error but don't stop execution of the main script
        error_log("Notification Error: " . $e->getMessage());
        return false;
    }
}
?>
