<?php
require_once 'db.php';

try {
    echo "<h1>Database Debugger</h1>";
    
    // Check columns in tbl_appointment
    echo "<h2>Columns in tbl_appointment:</h2>";
    $stmt = $pdo->query("SHOW COLUMNS FROM tbl_appointment");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<table border='1'><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        foreach ($col as $val) {
            echo "<td>" . htmlspecialchars($val) . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";

    // Check specific appointment
    echo "<h2>Check Appointment 9S (P-0009)</h2>";
    // Assuming patient 9S has an appointment. We need to find the appointment_id.
    // The user screenshot shows "9 S P-0009".
    // We'll search for patient P-0009
    
    // First find patient_id for P-0009 (assuming id matches or is close)
    // Actually search by name '9 S'
    $pat_stmt = $pdo->prepare("SELECT patient_id, first_name, last_name FROM tbl_patient WHERE first_name LIKE '9' AND last_name LIKE 'S%'");
    $pat_stmt->execute();
    $pat = $pat_stmt->fetch();
    
    if ($pat) {
        echo "Found Patient: " . $pat['first_name'] . " " . $pat['last_name'] . " (ID: " . $pat['patient_id'] . ")<br>";
        
        $appt_stmt = $pdo->prepare("SELECT * FROM tbl_appointment WHERE patient_id = ? ORDER BY appointment_date DESC");
        $appt_stmt->execute([$pat['patient_id']]);
        $appts = $appt_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1'><tr><th>Appt ID</th><th>Date</th><th>Status</th><th>Fee Paid</th><th>Inv ID</th></tr>";
        foreach ($appts as $a) {
             echo "<tr>";
             echo "<td>" . $a['appointment_id'] . "</td>";
             echo "<td>" . $a['appointment_date'] . "</td>";
             echo "<td>" . $a['status'] . "</td>";
             echo "<td>" . ($a['appointment_fee_paid'] ?? 'NULL/MISSING') . "</td>";
             echo "<td>" . ($a['appointment_fee_invoice_id'] ?? 'NULL/MISSING') . "</td>";
             echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "Patient 9 S not found.";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
