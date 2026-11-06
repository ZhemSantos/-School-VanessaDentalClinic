<?php
require_once '../db.php';

try {
    $sql = file_get_contents('../database_alter_installments_v2.sql');
    $pdo->exec($sql);
    echo "Migration V2 executed successfully.";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage();
}
?>
