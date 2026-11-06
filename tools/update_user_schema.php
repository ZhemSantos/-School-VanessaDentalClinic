<?php
require_once '../db.php';

try {
    echo "Checking tbl_user schema...\n";

    // Check if columns exist
    $check = $pdo->query("SHOW COLUMNS FROM tbl_user LIKE 'otp'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbl_user ADD COLUMN otp VARCHAR(6) NULL");
        echo "Added 'otp' column.\n";
    } else {
        echo "'otp' column already exists.\n";
    }

    $check = $pdo->query("SHOW COLUMNS FROM tbl_user LIKE 'otp_expiry'");
    if ($check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbl_user ADD COLUMN otp_expiry DATETIME NULL");
        echo "Added 'otp_expiry' column.\n";
    } else {
        echo "'otp_expiry' column already exists.\n";
    }

    echo "Schema update complete.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
