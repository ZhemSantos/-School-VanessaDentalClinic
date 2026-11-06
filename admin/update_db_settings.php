<?php
require_once '../db.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_settings (
        setting_id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50) NOT NULL UNIQUE,
        setting_value TEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_key (setting_key)
    )");
    
    $stmt = $pdo->prepare("INSERT INTO tbl_settings (setting_key, setting_value) VALUES ('appointment_fee', '200.00') ON DUPLICATE KEY UPDATE setting_key=setting_key");
    $stmt->execute();
    
    echo "Database updated successfully.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
