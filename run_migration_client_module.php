<?php
require_once 'db.php';

try {
    echo "Starting Client Module Database Migration...\n";
    
    $sqlFile = __DIR__ . '/sql/database_client_module.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("SQL file not found: " . $sqlFile);
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Split into individual statements (semicolon + newline) to avoid PDO multi-query issues if emulated prepares are off
    // However, the file logic is relatively simple. Let's try executing full block first.
    // If that fails, we can split.
    
    $pdo->exec($sql);
    
    echo "Migration completed successfully!\n";
    echo "Tables created/updated: tbl_parent_consent, tbl_feedback, tbl_service\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
