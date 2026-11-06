<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$user_code = $input['otp_code'] ?? '';
$patient_id = $input['patient_id'] ?? null;

if (empty($user_code) || empty($patient_id)) {
    echo json_encode(['success' => false, 'message' => 'Missing code or patient ID']);
    exit;
}

if (!isset($_SESSION['patient_update_otp'])) {
    echo json_encode(['success' => false, 'message' => 'No active OTP found. Please request a new one.']);
    exit;
}

$stored = $_SESSION['patient_update_otp'];

if ($stored['patient_id'] != $patient_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid session.']);
    exit;
}

if (time() > $stored['expires']) {
    unset($_SESSION['patient_update_otp']); // Clear expired
    echo json_encode(['success' => false, 'message' => 'OTP has expired.']);
    exit;
}

if ($stored['code'] == $user_code) {
    // OTP Verified
    // Set a verified flag in session allow access to the update page for this patient
    $_SESSION['patient_update_verified'] = [
        'patient_id' => $patient_id,
        'expires' => time() + 600 // 10 minutes access
    ];
    
    // Clear the OTP to prevent reuse
    unset($_SESSION['patient_update_otp']);

    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid OTP code.']);
}
