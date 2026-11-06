<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Dentist role
requireRole('Dentist');

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$doctor_id = $_SESSION['user_id'] ?? null;
$appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : null;

if (!$doctor_id || !$appointment_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    // Get appointment details with more information
    $appt_stmt = $pdo->prepare("
        SELECT a.*, 
               GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
               p.first_name, p.last_name, p.phone_number, p.email,
               CONCAT(p.first_name, ' ', p.last_name) as patient_name,
               u.full_name as doctor_name
        FROM tbl_appointment a
        INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        INNER JOIN tbl_service s ON tas.service_id = s.service_id
        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.appointment_id = ? AND a.doctor_id = ?
        GROUP BY a.appointment_id
    ");
    $appt_stmt->execute([$appointment_id, $doctor_id]);
    $appointment = $appt_stmt->fetch();
    
    if (!$appointment) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Appointment not found']);
        exit;
    }
    
    // Store original date and time before formatting
    $original_date = $appointment['appointment_date'];
    $original_time = $appointment['time_slot'];
    
    // Format date and time for display
    $appointment['appointment_date_formatted'] = date('M d, Y', strtotime($original_date));
    $appointment['time_slot_formatted'] = date('g:i A', strtotime($original_time));
    
    // Get medical record for this appointment - DIRECT LINKING NOW
    $record_stmt = $pdo->prepare("
        SELECT *, 
               DATE(date_of_treatment) as treatment_date,
               TIME(date_of_treatment) as treatment_time
        FROM tbl_medical_record
        WHERE appointment_id = ?
        LIMIT 1
    ");
    $record_stmt->execute([$appointment_id]);
    $record = $record_stmt->fetch();
    
    // Format record date/time if exists
    if ($record) {
        $record['treatment_date_formatted'] = date('M d, Y', strtotime($record['treatment_date']));
        $record['treatment_time_formatted'] = date('g:i A', strtotime($record['treatment_time']));
    }
    
    echo json_encode([
        'success' => true,
        'appointment' => $appointment,
        'record' => $record ?: null
    ]);
} catch (PDOException $e) {
    error_log('Error fetching appointment details: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}

