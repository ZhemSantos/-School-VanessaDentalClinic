<?php
require_once '../db.php';

function getTableColumns($pdo, $table) {
    echo "Structure for table: $table\n";
    try {
        $stmt = $pdo->query("DESCRIBE $table");
        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($columns as $col) {
            echo "{$col['Field']} - {$col['Type']} - {$col['Null']} - {$col['Key']}\n";
        }
    } catch (PDOException $e) {
        echo "Error describing $table: " . $e->getMessage() . "\n";
    }
    echo "------------------------------------------------\n";
}

getTableColumns($pdo, 'tbl_user');
getTableColumns($pdo, 'tbl_patients');
?>
