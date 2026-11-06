<?php
/**
 * Demo Account Setup Script
 * Creates a demo admin account for testing purposes
 * 
 * SECURITY NOTE: Delete this file after creating the demo account!
 */

require_once 'db.php';

// Demo account credentials
$demo_username = 'zhem';
$demo_password = '123';
$demo_role = 'Admin';
$demo_full_name = 'Demo Admin User';
$demo_email = 'zhem@demo.clinic';
$demo_contact = '09123456789';
$demo_blood_type = 'Unknown';

try {
    // Check if demo account already exists
    $check_stmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE username = ? LIMIT 1");
    $check_stmt->execute([$demo_username]);
    $existing_user = $check_stmt->fetch();
    
    if ($existing_user) {
        echo "<h2>Demo Account Already Exists</h2>";
        echo "<p>The demo account with username '<strong>$demo_username</strong>' already exists.</p>";
        echo "<p>If you want to reset the password, please delete the account first or use a different username.</p>";
        echo "<hr>";
        echo "<p><strong>Login Credentials:</strong></p>";
        echo "<ul>";
        echo "<li>Username: <strong>$demo_username</strong></li>";
        echo "<li>Password: <strong>$demo_password</strong></li>";
        echo "<li>Role: <strong>$demo_role</strong></li>";
        echo "</ul>";
        echo "<p><a href='index.php'>Go to Login Page</a></p>";
    } else {
        // Hash the password (even though it's weak, we'll hash it properly)
        // Note: Password "123" doesn't meet our strong requirements, but this is for demo purposes
        $password_hash = password_hash($demo_password, PASSWORD_DEFAULT);
        
        if ($password_hash === false) {
            throw new Exception('Password hashing failed.');
        }
        
        // Insert demo account into database
        // Note: We're inserting directly, bypassing form validation (password "123" is weak but OK for demo)
        $stmt = $pdo->prepare("INSERT INTO tbl_user (username, password_hash, full_name, role, email, contact_number, blood_type, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'Active')");
        $result = $stmt->execute([$demo_username, $password_hash, $demo_full_name, $demo_role, $demo_email, $demo_contact, $demo_blood_type]);
        
        if (!$result) {
            throw new Exception('Failed to insert demo account.');
        }
        
        echo "<h2>✅ Demo Account Created Successfully!</h2>";
        echo "<div style='background: #d4edda; border: 1px solid #c3e6cb; padding: 20px; border-radius: 8px; margin: 20px 0;'>";
        echo "<h3>Login Credentials:</h3>";
        echo "<ul style='font-size: 18px; line-height: 2;'>";
        echo "<li><strong>Username:</strong> <code style='background: #fff; padding: 4px 8px; border-radius: 4px;'>$demo_username</code></li>";
        echo "<li><strong>Password:</strong> <code style='background: #fff; padding: 4px 8px; border-radius: 4px;'>$demo_password</code></li>";
        echo "<li><strong>Role:</strong> <code style='background: #fff; padding: 4px 8px; border-radius: 4px;'>$demo_role</code></li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<div style='background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 8px; margin: 20px 0;'>";
        echo "<p><strong>⚠️ Security Reminder:</strong></p>";
        echo "<ul>";
        echo "<li>This is a demo account with a weak password for testing purposes only.</li>";
        echo "<li><strong>Please delete this setup file (setup_demo_account.php) after use!</strong></li>";
        echo "<li>Change the password or delete this account before going to production.</li>";
        echo "</ul>";
        echo "</div>";
        
        echo "<p style='margin-top: 30px;'><a href='index.php' style='background: #8a11d4; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; display: inline-block; font-weight: bold;'>Go to Login Page →</a></p>";
    }
    
} catch (PDOException $e) {
    echo "<h2>❌ Error Creating Demo Account</h2>";
    echo "<p style='color: red;'>Database Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Please check your database connection in db.php</p>";
} catch (Exception $e) {
    echo "<h2>❌ Error Creating Demo Account</h2>";
    echo "<p style='color: red;'>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>

<!DOCTYPE html>

<html>
<head>
    <title>Demo Account Setup</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f7f6f8;
        }
        h2 {
            color: #8a11d4;
        }
        code {
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
</body>
</html>

