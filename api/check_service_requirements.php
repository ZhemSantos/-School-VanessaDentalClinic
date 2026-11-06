<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

// Check if user is logged in and is a receptionist
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'Receptionist') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get input parameters
$patient_id = isset($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
$service_id = isset($_POST['service_id']) ? intval($_POST['service_id']) : 0;

if ($patient_id <= 0 || $service_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

try {
    // Step 1: Get the service requirements
    $service_stmt = $pdo->prepare("
        SELECT service_name, requires_checkup, requires_xray 
        FROM tbl_service 
        WHERE service_id = ?
    ");
    $service_stmt->execute([$service_id]);
    $service = $service_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        echo json_encode(['success' => false, 'message' => 'Service not found']);
        exit;
    }
    
    $requires_checkup = (bool)$service['requires_checkup'];
    $requires_xray = (bool)$service['requires_xray'];
    
    // If no requirements, allow immediately
    if (!$requires_checkup && !$requires_xray) {
        echo json_encode([
            'success' => true,
            'allowed' => true,
            'message' => 'No requirements needed'
        ]);
        exit;
    }
    
    // Step 2: Find Check-up and X-ray service IDs dynamically
    $checkup_id = null;
    $xray_id = null;
    
    if ($requires_checkup) {
        $checkup_stmt = $pdo->prepare("SELECT service_id FROM tbl_service WHERE service_name = 'Check-up' LIMIT 1");
        $checkup_stmt->execute();
        $checkup_result = $checkup_stmt->fetch(PDO::FETCH_ASSOC);
        $checkup_id = $checkup_result ? $checkup_result['service_id'] : null;
    }
    
    if ($requires_xray) {
        $xray_stmt = $pdo->prepare("SELECT service_id FROM tbl_service WHERE service_name = 'X-ray' LIMIT 1");
        $xray_stmt->execute();
        $xray_result = $xray_stmt->fetch(PDO::FETCH_ASSOC);
        $xray_id = $xray_result ? $xray_result['service_id'] : null;
    }
    
    // Step 3: Check if patient has completed and paid for required services
    $missing_requirements = [];
    
    if ($requires_checkup && $checkup_id) {
        $has_checkup = checkServiceCompleted($pdo, $patient_id, $checkup_id);
        if (!$has_checkup) {
            $missing_requirements[] = 'Check-up';
        }
    }
    
    if ($requires_xray && $xray_id) {
        $has_xray = checkServiceCompleted($pdo, $patient_id, $xray_id);
        if (!$has_xray) {
            $missing_requirements[] = 'X-ray';
        }
    }
    
    // Step 4: Return validation result
    if (empty($missing_requirements)) {
        echo json_encode([
            'success' => true,
            'allowed' => true,
            'message' => 'All requirements met'
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'allowed' => false,
            'missing_requirements' => $missing_requirements,
            'message' => 'Patient must complete and pay for: ' . implode(' and ', $missing_requirements) . ' before booking this service.'
        ]);
    }
    
} catch (PDOException $e) {
    error_log('Error checking service requirements: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}

/**
 * Check if patient has completed and paid for a specific service
 */
function checkServiceCompleted($pdo, $patient_id, $service_id) {
    // Query to check if patient has:
    // 1. An appointment with this service
    // 2. The appointment is completed
    // 3. The invoice for that appointment is paid
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM tbl_appointment a
        INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_invoice i ON a.appointment_id = i.appointment_id
        WHERE a.patient_id = ?
          AND tas.service_id = ?
          AND a.status IN ('Completed', 'Done')
          AND (i.payment_status = 'Paid' OR i.invoice_id IS NULL)
    ");
    
    $stmt->execute([$patient_id, $service_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['count'] > 0;
}
