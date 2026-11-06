<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$patient_id = $input['patient_id'] ?? null;
$consent_title = $input['consent_title'] ?? 'Consent Form';
$consent_html = $input['consent_html'] ?? '';
$service_id = $input['service_id'] ?? null;

if (!$patient_id || !$consent_html) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    // Determine file type and create appropriate filename
    $timestamp = date('YmdHis');
    $patient_folder = "patient_{$patient_id}";
    $consent_folder = "consent_forms";
    
    if ($service_id) {
        // Service-specific consent - get service name and category
        $serviceStmt = $pdo->prepare("SELECT service_name, category FROM tbl_service WHERE service_id = ?");
        $serviceStmt->execute([$service_id]);
        $service = $serviceStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($service) {
            // Clean service name for filename (remove special characters, replace spaces with underscores)
            $clean_service_name = preg_replace('/[^A-Za-z0-9_]/', '', str_replace(' ', '_', $service['service_name']));
            $filename = "{$clean_service_name}_SSCF_{$timestamp}.html";
        } else {
            $filename = "Service_SSCF_{$timestamp}.html";
        }
    } else {
        // General consent form
        $filename = "GNCF_{$timestamp}.html";
    }
    
    // Create nested folder structure: uploads/patient_files/patient_{id}/consent_forms/
    $upload_dir = "../uploads/patient_files/{$patient_folder}/{$consent_folder}";
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $filepath = "{$upload_dir}/{$filename}";
    $relative_path = "uploads/patient_files/{$patient_folder}/{$consent_folder}/{$filename}";
    
    // Create full HTML document for the consent form
    $fullHTML = "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>{$consent_title}</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 40px auto; padding: 20px; }
        .border-b { border-bottom: 1px solid #000; }
        .uppercase { text-transform: uppercase; }
        .font-bold { font-weight: bold; }
        .text-center { text-align: center; }
        .space-y-6 > * + * { margin-top: 1.5rem; }
        .grid { display: grid; }
        .grid-cols-2 { grid-template-columns: repeat(2, 1fr); }
        .gap-4 { gap: 1rem; }
        .gap-12 { gap: 3rem; }
        .p-5 { padding: 1.25rem; }
        .border-2 { border-width: 2px; }
        .rounded-lg { border-radius: 0.5rem; }
        .bg-purple-50 { background-color: #faf5ff; }
        .border-purple-200 { border-color: #e9d5ff; }
        .bg-amber-50 { background-color: #fffbeb; }
        .border-amber-200 { border-color: #fde68a; }
        .bg-slate-100 { background-color: #f1f5f9; }
        .border-slate-300 { border-color: #cbd5e1; }
        .pl-8 { padding-left: 2rem; }
        .border-l-4 { border-left-width: 4px; }
        .border-gray-100 { border-color: #f3f4f6; }
        .py-2 { padding-top: 0.5rem; padding-bottom: 0.5rem; }
        .mt-12 { margin-top: 3rem; }
        .border-t-2 { border-top-width: 2px; }
        .border-black { border-color: #000; }
        .pt-2 { padding-top: 0.5rem; }
        .list-disc { list-style-type: disc; }
        .list-inside { list-style-position: inside; }
        .pl-4 { padding-left: 1rem; }
        ul { margin: 0; }
        li + li { margin-top: 0.25rem; }
    </style>
</head>
<body>
    {$consent_html}
</body>
</html>";
    
    // Save HTML file
    if (file_put_contents($filepath, $fullHTML) === false) {
        throw new Exception('Failed to create consent file');
    }
    
    // Get file size
    $file_size = filesize($filepath);
    
    // Determine consent metadata
    $consent_type = 'GNCF';
    $consent_category = null;
    $is_signed = 1; // Auto-signed upon generation/save
    $expiration_date = date('Y-m-d', strtotime('+1 year')); // GNCF defaults to 1 year validity
    
    if ($service_id) {
        $consent_type = 'SSCF';
        $expiration_date = null; // SSCF is valid until procedure is done (no fixed expiration)
        if (isset($service) && $service['category']) {
            $consent_category = $service['category'];
        }
    } else {
        // For GNCF, it applies to General Dentistry by default or global
        $consent_category = 'General Dentistry'; 
    }

    // --- DUPLICATE CHECK ---
    $overwrite = $input['overwrite'] ?? false;
    
    // Check for ANY existing files of this type
    $checkSql = "SELECT file_id, file_path FROM tbl_patient_file WHERE patient_id = ? AND consent_type = ?";
    $checkParams = [$patient_id, $consent_type];
    
    if ($consent_type === 'SSCF') {
        $checkSql .= " AND consent_category = ?";
        $checkParams[] = $consent_category;
    }
    
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute($checkParams);
    $existingFiles = $checkStmt->fetchAll(PDO::FETCH_ASSOC); // Fetch ALL duplicates
    
    if (!empty($existingFiles)) {
        if (!$overwrite) {
            // Found duplicates and no overwrite permission
            echo json_encode([
                'success' => false,
                'requires_confirmation' => true,
                'message' => 'Valid consent form(s) of this type already exist. Do you want to replace them with this new one?'
            ]);
            exit;
        } else {
            // Overwrite allowed: Delete ALL existing files/records
            foreach ($existingFiles as $file) {
                if (file_exists($file['file_path'])) {
                    unlink($file['file_path']);
                }
            }
            
            // Delete query matching the same criteria
            $deleteSql = "DELETE FROM tbl_patient_file WHERE patient_id = ? AND consent_type = ?";
            $deleteParams = [$patient_id, $consent_type];
            
            if ($consent_type === 'SSCF') {
                $deleteSql .= " AND consent_category = ?";
                $deleteParams[] = $consent_category;
            }
            
            $delStmt = $pdo->prepare($deleteSql);
            $delStmt->execute($deleteParams);
        }
    }
    // --- END DUPLICATE CHECK ---

    // Insert into tbl_patient_file
    $stmt = $pdo->prepare("
        INSERT INTO tbl_patient_file (
            patient_id,
            file_name,
            file_path,
            file_type,
            file_size,
            description,
            uploaded_at,
            consent_type,
            consent_category,
            is_signed,
            expiration_date
        ) VALUES (?, ?, ?, 'html', ?, ?, NOW(), ?, ?, ?, ?)
    ");
    
    $description = $consent_title . " - Generated on " . date('Y-m-d H:i:s');
    
    $stmt->execute([
        $patient_id,
        $filename,
        $relative_path,
        $file_size,
        $description,
        $consent_type,
        $consent_category,
        $is_signed,
        $expiration_date
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Consent form saved successfully',
        'file_id' => $pdo->lastInsertId(),
        'filename' => $filename
    ]);
    
} catch (Exception $e) {
    // Clean up file if it was created
    if (isset($filepath) && file_exists($filepath)) {
        unlink($filepath);
    }
    
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>
