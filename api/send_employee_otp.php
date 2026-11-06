<?php
session_start();
require_once '../includes/email_config.php';
require_once '../db.php'; // Include database connection

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Check if email already exists in the database
try {
    $stmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'This email address is already associated with an existing account.']);
        exit;
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    exit;
}

// Generate OTP
$otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);

// Store in Session
$_SESSION['registration_otp'] = [
    'otp' => $otp,
    'email' => $email,
    'expiry' => time() + 600 // 10 minutes
];

// Send Email
$subject = "Verify Your Email - Schoolness Dental Clinic";
$message = "<p>Your verification code for staff registration is:</p><h1>$otp</h1><p>This code is valid for 10 minutes.</p>";

try {
    if (sendEmail($email, $subject, $message)) {
        echo json_encode(['success' => true, 'message' => 'OTP sent successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send email. please check server logs.']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>
