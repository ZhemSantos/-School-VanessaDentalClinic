<?php
require_once 'db.php';

try {
    $sql_file = 'database_add_last_payment_date.sql';
    if (!file_exists($sql_file)) {
        die("Error: $sql_file not found.");
    }
    
    $sql = file_get_contents($sql_file);
    
    echo "Running migration from $sql_file...\n";
    $pdo->exec($sql);
    echo "Migration completed successfully.\n";
    
} catch (PDOException $e) {
    echo "Migration Failed: " . $e->getMessage() . "\n";
}
?>
