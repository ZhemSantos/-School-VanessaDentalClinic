<?php
session_start();
require_once '../db.php';
require_once '../includes/email_notifications.php'; // Ensure this exists and has sendEmail function

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$patient_id = $input['patient_id'] ?? null;

if (!$patient_id) {
    echo json_encode(['success' => false, 'message' => 'Patient ID is required']);
    exit;
}

try {
    // Fetch patient email
    $stmt = $pdo->prepare("SELECT email, first_name FROM tbl_patient WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch();

    if (!$patient || empty($patient['email'])) {
        echo json_encode(['success' => false, 'message' => 'Patient not found or has no email address.']);
        exit;
    }

    // Generate 6-digit OTP
    $otp = rand(100000, 999999);
    $expires_at = time() + 300; // 5 minutes

    // Store in session
    $_SESSION['patient_update_otp'] = [
        'code' => $otp,
        'patient_id' => $patient_id,
        'expires' => $expires_at
    ];

    // Send Email
    $subject = "Verification Code for Profile Update";
    $message = "
        <div style='font-family: Arial, sans-serif; padding: 20px; color: #333;'>
            <h2 style='color: #6A1B9A;'>Verification Code</h2>
            <p>Hello <strong>{$patient['first_name']}</strong>,</p>
            <p>You have requested to update your patient information. Please provide the following code to the receptionist:</p>
            <div style='background: #f3f0f4; padding: 15px; border-radius: 8px; text-align: center; margin: 20px 0;'>
                <span style='font-size: 24px; font-weight: bold; letter-spacing: 5px; color: #6A1B9A;'>{$otp}</span>
            </div>
            <p style='color: #666; font-size: 12px;'>This code expires in 5 minutes.</p>
        </div>
    ";

    if (sendEmail($patient['email'], $subject, $message)) {
        echo json_encode(['success' => true, 'message' => 'OTP sent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send email. Please check server logs.']);
    }

} catch (Exception $e) {
    error_log("OTP Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Server error occurred']);
}
