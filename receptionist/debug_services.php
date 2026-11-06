<?php
require_once 'db.php';

try {
    $stmt = $pdo->query("SELECT service_id, service_name, base_price, is_installment_available, installment_down_payment, installment_duration_months, installment_interest_percent FROM tbl_service WHERE is_installment_available = 1");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Service Data Dump:\n";
    foreach ($services as $s) {
        echo "ID: " . $s['service_id'] . "\n";
        echo "Name: " . $s['service_name'] . "\n";
        echo "Price: " . $s['base_price'] . "\n";
        echo "Installment: " . ($s['is_installment_available'] ? 'Yes' : 'No') . "\n";
        echo "DP: " . $s['installment_down_payment'] . " (Raw DB Value)\n";
        echo "Interest: " . $s['installment_interest_percent'] . "%\n";
        echo "Months: " . $s['installment_duration_months'] . "\n";
        echo "---------------------------\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
