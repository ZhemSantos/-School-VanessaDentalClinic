<?php
require_once '../db.php';

try {
    echo "Starting Database Migration...\n";

    // 1. Create Patient Accounts
    $sql1 = "CREATE TABLE IF NOT EXISTS tbl_patient_accounts (
        account_id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        otp VARCHAR(10) DEFAULT NULL,
        otp_expiry DATETIME DEFAULT NULL,
        is_verified TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql1);
    echo "Checked/Created tbl_patient_accounts.\n";

    // 2. Modify tbl_patients - Helper function to add column safely
    function addColumnIfNotExists($pdo, $table, $column, $definition) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM $table LIKE ?");
        $stmt->execute([$column]);
        if (!$stmt->fetch()) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
            echo "Added column $column to $table.\n";
        } else {
            echo "Column $column already exists in $table.\n";
        }
    }

    // Check if first_name/last_name exist (based on findings, they might). 
    // If not, we should rename full_name or add them. 
    // Let's inspect first.
    $stmt = $pdo->query("SHOW COLUMNS FROM tbl_patients LIKE 'first_name'");
    if (!$stmt->fetch()) {
        // Assume full_name exists, we might need to migrate data later, but for now just add columns
        $pdo->exec("ALTER TABLE tbl_patients ADD COLUMN first_name VARCHAR(100) AFTER patient_id");
        $pdo->exec("ALTER TABLE tbl_patients ADD COLUMN last_name VARCHAR(100) AFTER first_name");
        echo "Added first_name and last_name to tbl_patients.\n";
    }

    addColumnIfNotExists($pdo, 'tbl_patients', 'middle_name', 'VARCHAR(100) AFTER first_name');
    addColumnIfNotExists($pdo, 'tbl_patients', 'date_of_birth', 'DATE'); // Ensure DOB exists
    addColumnIfNotExists($pdo, 'tbl_patients', 'age', 'INT AFTER date_of_birth');
    addColumnIfNotExists($pdo, 'tbl_patients', 'age_category', 'VARCHAR(20) AFTER age');
    addColumnIfNotExists($pdo, 'tbl_patients', 'account_id', 'INT NULL');

    // Add FK for account_id if not exists (hard to check constraint existence easily in portable way, trying safe approach)
    // We'll skip adding constraint name check for now to avoid errors, simple index is fine for this step.
    
    // 3. Create Dependencies
    $sql3 = "CREATE TABLE IF NOT EXISTS tbl_dependencies (
        dependency_id INT AUTO_INCREMENT PRIMARY KEY,
        guardian_account_id INT NOT NULL,
        sub_patient_id INT NOT NULL,
        relationship VARCHAR(50),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (guardian_account_id) REFERENCES tbl_patient_accounts(account_id) ON DELETE CASCADE,
        FOREIGN KEY (sub_patient_id) REFERENCES tbl_patients(patient_id) ON DELETE CASCADE
    )";
    $pdo->exec($sql3);
    echo "Checked/Created tbl_dependencies.\n";

    echo "Migration Completed Successfully!\n";

} catch (PDOException $e) {
    die("Migration Failed: " . $e->getMessage() . "\n");
}
?>
