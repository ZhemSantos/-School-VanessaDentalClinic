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
$patient_id = $_POST['patient_id'] ?? null;
$file_type = $_POST['file_type'] ?? 'other';
$description = $_POST['description'] ?? '';

if (!$doctor_id || !$patient_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Verify patient is assigned to this doctor
try {
    $check_stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM tbl_appointment 
        WHERE patient_id = ? AND doctor_id = ?
        UNION ALL
        SELECT COUNT(*) as count FROM tbl_medical_record 
        WHERE patient_id = ? AND doctor_id = ?
    ");
    $check_stmt->execute([$patient_id, $doctor_id, $patient_id, $doctor_id]);
    $results = $check_stmt->fetchAll();
    $total = ($results[0]['count'] ?? 0) + ($results[1]['count'] ?? 0);
    
    if ($total == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Patient not assigned to this doctor']);
        exit;
    }
} catch (PDOException $e) {
    error_log('Error checking patient assignment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Handle file upload
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File upload error']);
    exit;
}

$file = $_FILES['file'];
$allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
$max_size = 10 * 1024 * 1024; // 10MB

if (!in_array($file['type'], $allowed_types)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only images and PDFs are allowed.']);
    exit;
}

if ($file['size'] > $max_size) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'File size exceeds 10MB limit']);
    exit;
}

// Create upload directory if it doesn't exist
$upload_dir = '../uploads/patient_files/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Generate unique filename
$file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$file_name = 'patient_' . $patient_id . '_' . time() . '_' . uniqid() . '.' . $file_extension;
$file_path = $upload_dir . $file_name;
$relative_path = 'uploads/patient_files/' . $file_name;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $file_path)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit;
}

// Save to database
try {
    $insert_stmt = $pdo->prepare("
        INSERT INTO tbl_patient_file (patient_id, doctor_id, file_name, file_path, file_type, file_size, description)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $insert_stmt->execute([
        $patient_id,
        $doctor_id,
        $file['name'],
        $relative_path,
        $file_type,
        $file['size'],
        $description
    ]);
    
    $file_id = $pdo->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'message' => 'File uploaded successfully',
        'file_id' => $file_id,
        'file_name' => $file['name'],
        'file_path' => $relative_path
    ]);
} catch (PDOException $e) {
    // Delete uploaded file if database insert fails
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    error_log('Error saving file record: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

