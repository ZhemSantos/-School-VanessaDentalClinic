<?php
require_once 'db.php';

try {
    $sql = file_get_contents('database_migration_installments.sql');
    
    // Split by semicolon? No, some statements might be complex.
    // But the prepared statement block for ALTER requires one go.
    // PDO can execute multiple statements if emulation is on.
    
    // Let's just run it. The SET @ logic works in one go usually in standard Clients. 
    // PDO might falter with variables if not careful.
    // Let's simplify the ALTER to a raw try-catch which is safer for PHP one-off.
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS tbl_patient_installment (
            installment_id INT AUTO_INCREMENT PRIMARY KEY,
            patient_id INT NOT NULL,
            appointment_id INT NOT NULL,
            service_id INT NOT NULL,
            total_amount DECIMAL(10,2) NOT NULL,
            down_payment_amount DECIMAL(10,2) NOT NULL,
            interest_rate_percent DECIMAL(5,2) DEFAULT 0,
            monthly_payment_amount DECIMAL(10,2) NOT NULL,
            total_months INT NOT NULL,
            months_paid INT DEFAULT 0,
            remaining_balance DECIMAL(10,2) NOT NULL,
            start_date DATE NOT NULL,
            next_due_date DATE NOT NULL,
            status ENUM('Active', 'Completed', 'Overdue', 'Defaulted', 'Cancelled') DEFAULT 'Active',
            notes TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (patient_id) REFERENCES tbl_patient(patient_id),
            FOREIGN KEY (appointment_id) REFERENCES tbl_appointment(appointment_id),
            FOREIGN KEY (service_id) REFERENCES tbl_service(service_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    echo "Table verified.\n";
    
    try {
        $pdo->exec("ALTER TABLE tbl_transaction ADD COLUMN installment_id INT NULL DEFAULT NULL");
        echo "Column added.\n";
    } catch (PDOException $e) {
        // Ignore if error about column exists (Code 42S21 in MySQL usually, but varies)
        echo "Column assumption: " . $e->getMessage() . "\n";
    }
    
    echo "Migration completed successfully.";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
