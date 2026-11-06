<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

try {
    // Check if we should filter for SSCF-required services only
    $sscf_only = isset($_GET['sscf_only']) && $_GET['sscf_only'] === 'true';
    
    // Fetch all services from database
    $sql = "
        SELECT s.service_id, s.service_name, s.category, s.base_price, s.estimated_duration_mins,
               s.is_installment_available, s.installment_down_payment
        FROM tbl_service s
    ";
    
    if ($sscf_only) {
        $sql .= "
            INNER JOIN tbl_consent_requirement cr ON s.category = cr.category_name
            WHERE cr.requires_sscf = 1
        ";
    } else {
        $sql .= " WHERE 1=1 ";
    }
    
    $sql .= " ORDER BY s.category, s.service_name";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cast numeric fields to proper types
    foreach ($services as &$service) {
        $service['is_installment_available'] = (int)$service['is_installment_available'];
        $service['installment_down_payment'] = (float)$service['installment_down_payment'];
        $service['base_price'] = (float)$service['base_price'];
        $service['estimated_duration_mins'] = (int)$service['estimated_duration_mins'];
    }
    
    echo json_encode([
        'success' => true,
        'services' => $services
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching services: ' . $e->getMessage()
    ]);
}
?>
