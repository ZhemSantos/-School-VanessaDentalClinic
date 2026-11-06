<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Dentist role
requireRole('Dentist');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$doctor_id = $_SESSION['user_id'] ?? null;
$file_id = $_POST['file_id'] ?? null;

if (!$doctor_id || !$file_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    // Get file info
    $file_stmt = $pdo->prepare("
        SELECT file_path, doctor_id FROM tbl_patient_file WHERE file_id = ?
    ");
    $file_stmt->execute([$file_id]);
    $file_info = $file_stmt->fetch();
    
    if (!$file_info) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'File not found']);
        exit;
    }
    
    // Verify doctor owns this file
    if ($file_info['doctor_id'] != $doctor_id) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    
    // Delete file from filesystem
    $file_path = '../' . $file_info['file_path'];
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    
    // Delete from database
    $delete_stmt = $pdo->prepare("DELETE FROM tbl_patient_file WHERE file_id = ?");
    $delete_stmt->execute([$file_id]);
    
    echo json_encode(['success' => true, 'message' => 'File deleted successfully']);
} catch (PDOException $e) {
    error_log('Error deleting file: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

