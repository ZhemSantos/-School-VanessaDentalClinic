<?php
session_start();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$otp = $input['otp'] ?? '';
$email = $input['email'] ?? '';

if (empty($otp)) {
    echo json_encode(['success' => false, 'message' => 'OTP is required']);
    exit;
}

$stored_otp = $_SESSION['registration_otp'] ?? null;

if (!$stored_otp) {
    echo json_encode(['success' => false, 'message' => 'No OTP request found. Please resend.']);
    exit;
}

if ($stored_otp['email'] !== $email) {
    echo json_encode(['success' => false, 'message' => 'Email mismatch.']);
    exit;
}

if (time() > $stored_otp['expiry']) {
    echo json_encode(['success' => false, 'message' => 'OTP has expired.']);
    exit;
}

if ($otp === $stored_otp['otp']) {
    // Verified
    $_SESSION['email_verified'] = true;
    $_SESSION['verified_email_address'] = $email;
    unset($_SESSION['registration_otp']); // Clear OTP
    echo json_encode(['success' => true, 'message' => 'Email verified successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid OTP']);
}
?>
