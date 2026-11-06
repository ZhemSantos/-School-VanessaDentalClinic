<?php
session_start();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$patient_id = $input['patient_id'] ?? null;
$type = $input['type'] ?? '';

if (empty($patient_id) || $type !== 'manual_consent') {
    echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
    exit;
}

// Set a verified flag in session allow access to the update page for this patient
$_SESSION['patient_update_verified'] = [
    'patient_id' => $patient_id,
    'expires' => time() + 600, // 10 minutes access
    'method' => 'manual_consent'
];

echo json_encode(['success' => true]);
