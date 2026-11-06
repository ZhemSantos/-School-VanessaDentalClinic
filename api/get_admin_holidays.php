<?php
// api/get_admin_holidays.php
ob_start(); // Buffer output to prevent HTML errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Shutdown handler for Fatal Errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE)) {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Fatal API Error: ' . $error['message']]);
        exit;
    }
});

require_once '../db.php';
require_once '../includes/security.php';

session_start();

// Custom Admin Check for API (Returns JSON instead of Redirect)
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized: Admin access required']);
    exit;
}

try {
    // Prevent caching
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Fetch blocked dates from today onwards
    // We fetch ALL future holidays for admin management
    $stmt = $pdo->prepare("
        SELECT t.block_id, t.block_date, t.start_time, t.end_time, t.reason, 
               u.full_name as doctor_name, u.role as user_role
        FROM tbl_blocked_time t
        JOIN tbl_user u ON t.doctor_id = u.user_id
        WHERE t.block_date >= CURDATE() 
        ORDER BY block_date ASC 
    ");
    $stmt->execute();
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format for display
    foreach ($holidays as &$h) {
        $dateObj = new DateTime($h['block_date']);
        $h['formatted_date'] = $dateObj->format('F j, Y');
        
        $is_whole = ($h['start_time'] == '00:00:00' && $h['end_time'] >= '23:00:00');
        $h['display_time'] = $is_whole ? 'Whole Day' : date('g:i A', strtotime($h['start_time'])) . ' - ' . date('g:i A', strtotime($h['end_time']));
    }

    echo json_encode(['success' => true, 'holidays' => $holidays]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
