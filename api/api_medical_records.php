<?php
session_start();
require_once '../db.php';

// Check if user is logged in as patient
if (!isset($_SESSION['patient_id'])) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$patient_id = $_SESSION['patient_id'];

try {
    switch ($action) {
        case 'list':
            header('Content-Type: application/json');
            getFiles($pdo, $patient_id);
            break;
        case 'download':
            // Download header is set inside
            downloadFile($pdo, $patient_id);
            break;
        case 'view':
            // View file without download
            viewFile($pdo, $patient_id);
            break;
        default:
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function getFiles($pdo, $patient_id) {
    $stmt = $pdo->prepare("
        SELECT pf.file_id, pf.file_name, pf.uploaded_at, pf.description, pf.file_type,
               u.full_name as doctor_name
        FROM tbl_patient_file pf
        LEFT JOIN tbl_user u ON pf.doctor_id = u.user_id
        WHERE pf.patient_id = ?
        ORDER BY pf.uploaded_at DESC
    ");
    $stmt->execute([$patient_id]);
    echo json_encode(['success' => true, 'files' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function downloadFile($pdo, $patient_id) {
    $file_id = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT file_path, file_name, file_type FROM tbl_patient_file WHERE file_id = ? AND patient_id = ?");
    $stmt->execute([$file_id, $patient_id]);
    $file = $stmt->fetch();
    
    if (!$file) throw new Exception('File not found or access denied');
    
    $filepath = '../' . $file['file_path']; // Adjust path relative to api/ folder
    
    if (!file_exists($filepath)) {
        throw new Exception('Physical file missing');
    }
    
    // Serve File
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file['file_name']) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}

function viewFile($pdo, $patient_id) {
    $file_id = $_GET['id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT file_path, file_name, file_type FROM tbl_patient_file WHERE file_id = ? AND patient_id = ?");
    $stmt->execute([$file_id, $patient_id]);
    $file = $stmt->fetch();
    
    if (!$file) throw new Exception('File not found or access denied');
    
    $filepath = '../' . $file['file_path'];
    
    if (!file_exists($filepath)) {
        throw new Exception('Physical file missing');
    }
    
    // Determine content type
    $extension = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
    $contentTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'pdf' => 'application/pdf',
        'html' => 'text/html',
        'htm' => 'text/html'
    ];
    
    $contentType = $contentTypes[$extension] ?? 'application/octet-stream';
    
    // Serve file for viewing
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($filepath));
    readfile($filepath);
    exit;
}
?>
