<?php
// api/search_patients.php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_GET['query'])) {
    echo json_encode([]);
    exit;
}

$query = $_GET['query'] . '%';

try {
    $stmt = $pdo->prepare("SELECT patient_id, first_name, last_name, phone_number FROM tbl_patient WHERE (first_name LIKE ? OR last_name LIKE ?) AND age >= 18 LIMIT 10");
    $stmt->execute([$query, $query]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (PDOException $e) {
    error_log('Error searching patients: ' . $e->getMessage());
    echo json_encode([]);
}
?>
