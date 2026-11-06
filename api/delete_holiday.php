<?php
// api/delete_holiday.php
ob_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Shutdown handler
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

// Custom Admin Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Admin') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$block_id = $input['block_id'] ?? null;
$block_date = $input['block_date'] ?? null;

if (!$block_id && !$block_date) {
    echo json_encode(['success' => false, 'message' => 'Missing Block ID or Date']);
    exit;
}

try {
    if ($block_id) {
        $stmt = $pdo->prepare("DELETE FROM tbl_blocked_time WHERE block_id = ?");
        $stmt->execute([$block_id]);
    } else {
        // Bulk delete by date
        $stmt = $pdo->prepare("DELETE FROM tbl_blocked_time WHERE block_date = ?");
        $stmt->execute([$block_date]);
    }
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'deleted_count' => $stmt->rowCount()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'No blocks found to delete']);
    }

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
