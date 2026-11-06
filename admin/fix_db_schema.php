<?php
require_once '../db.php';

try {
    echo "<h2>Attempting to update database schema...</h2>";

    // 1. Check if columns exist
    $columns = [];
    $stmt = $pdo->query("SHOW COLUMNS FROM tbl_user");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $row['Field'];
    }

    // 2. Add reset_token if missing
    if (!in_array('reset_token', $columns)) {
        $pdo->exec("ALTER TABLE tbl_user ADD COLUMN reset_token VARCHAR(64) NULL AFTER status");
        echo "<p style='color:green'>Success: Added 'reset_token' column.</p>";
    } else {
        echo "<p style='color:blue'>Info: 'reset_token' column already exists.</p>";
    }

    // 3. Add reset_expires_at if missing
    if (!in_array('reset_expires_at', $columns)) {
        $pdo->exec("ALTER TABLE tbl_user ADD COLUMN reset_expires_at DATETIME NULL AFTER reset_token");
        echo "<p style='color:green'>Success: Added 'reset_expires_at' column.</p>";
    } else {
        echo "<p style='color:blue'>Info: 'reset_expires_at' column already exists.</p>";
    }

    echo "<h3>Database update complete! You can now use the password reset feature.</h3>";
    echo "<a href='admin_user.php'>Return to Admin Users</a>";

} catch (PDOException $e) {
    echo "<h3 style='color:red'>Database Error: " . htmlspecialchars($e->getMessage()) . "</h3>";
}
?>
