<?php
session_start();
require_once 'db.php';

// Check if user is logged in
if (!isset($_SESSION['patient_id'])) {
    header("Location: landing.php");
    exit;
}

// Get the target patient ID
$target_patient_id = $_GET['patient_id'] ?? null;

if (!$target_patient_id) {
    header("Location: client/client_dependencies.php");
    exit;
}

    // Use the logged-in user's Account ID (guardian) directly
    // This handles cases where patient_id might be different or not linked correctly in subquery
    $guardian_account_id = $_SESSION['user_id'] ?? 0;

    $stmt = $pdo->prepare("
        SELECT d.*, p.first_name, p.last_name, p.email, p.phone_number
        FROM tbl_dependencies d
        JOIN tbl_patient p ON d.sub_patient_id = p.patient_id
        WHERE d.sub_patient_id = ? 
        AND d.guardian_account_id = ?
    ");
    $stmt->execute([$target_patient_id, $guardian_account_id]);
$dependent = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dependent) {
    // Not authorized to switch to this account
    header("Location: client/client_dependencies.php?error=unauthorized");
    exit;
}

// Check access verification for non-child relationships
if (strtolower($dependent['relationship']) !== 'child' && !$dependent['access_verified']) {
    // Access not verified yet
    $_SESSION['error_message'] = "Access to this account requires verification. Please check the dependent's email for the verification link.";
    header("Location: client/client_dependencies.php?error=not_verified");
    exit;
}

// Store original account info for switching back
if (!isset($_SESSION['original_patient_id'])) {
    $_SESSION['original_patient_id'] = $_SESSION['patient_id'];
    $_SESSION['original_patient_name'] = $_SESSION['patient_name'];
}

// Switch session to dependent
$_SESSION['patient_id'] = $target_patient_id;
$_SESSION['patient_name'] = $dependent['first_name'] . ' ' . $dependent['last_name'];
$_SESSION['is_dependent'] = true;

// Redirect to client dashboard
header("Location: client/client_dashboard.php?switched=1");
exit;
