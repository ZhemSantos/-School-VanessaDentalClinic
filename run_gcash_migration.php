<?php
require_once 'c:/Zhem/Schoolness/2nd Year/Information Management/Final Project/Code/db.php';

try {
    // Add payment_reference column
    $sql = "ALTER TABLE tbl_invoice ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payment_method";
    $pdo->exec($sql);
    echo "Migration successful: Added payment_reference column.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), "Duplicate column name") !== false) {
        echo "Column payment_reference already exists.\n";
    } else {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
?>
