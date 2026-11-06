<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/email_config.php';

header('Content-Type: application/json');

// 1. Verify Admin Role
if (!isLoggedIn() || !hasRole('Admin')) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access.']);
    exit;
}

// 2. Validate Request
$user_id = $_POST['user_id'] ?? null;
if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID is required.']);
    exit;
}

// 3. Fetch User
try {
    $stmt = $pdo->prepare("SELECT email, full_name, username FROM tbl_user WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found.']);
        exit;
    }

    if (empty($user['email'])) {
        echo json_encode(['success' => false, 'message' => 'User has no email address.']);
        exit;
    }

    // 4. Generate Token (32 bytes = 64 hex chars)
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours')); // Token valid for 24 hours

    // 5. Store Token
    $update = $pdo->prepare("UPDATE tbl_user SET reset_token = ?, reset_expires_at = ? WHERE user_id = ?");
    $update->execute([$token, $expires, $user_id]);

    // 6. Send Email
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'];
    // Determine path relative to admin folder (assuming admin is in /admin/ subdir)
    // We want the reset_password.php in the root.
    // If request is from admin/admin_send_password_reset.php, the root is ../
    // But for the URL, we need the public path.
    // Simplest approach: Use current path and strip 'admin/...'
    $script_dir = dirname($_SERVER['PHP_SELF']); // e.g. /Code/admin
    $base_dir = dirname($script_dir); // e.g. /Code
    
    // Fallback if base_dir is empty or just slash
    if ($base_dir === '/' || $base_dir === '\\') $base_dir = '';
    
    $reset_link = $protocol . $host . $base_dir . "/reset_password.php?token=" . $token;
    
    $subject = "Password Reset Request - Vanessa Nicolas Dental Clinic";
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 10px;'>
            <h2 style='color: #8a11d4; text-align: center;'>Password Reset Request</h2>
            <p>Hello <strong>" . htmlspecialchars($user['full_name']) . "</strong>,</p>
            <p>The administrator has initiated a password reset for your account (<strong>" . htmlspecialchars($user['username']) . "</strong>).</p>
            <p>Please click the button below to set a new password:</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='" . $reset_link . "' style='background-color: #8a11d4; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; font-weight: bold;'>Reset Password</a>
            </div>
            <p style='color: #666; font-size: 12px;'>Or copy this link: <a href='" . $reset_link . "'>" . $reset_link . "</a></p>
            <p>This link is valid for 24 hours.</p>
        </div>
    ";

    if (sendEmail($user['email'], $subject, $body)) {
        echo json_encode(['success' => true, 'message' => 'Reset link sent to ' . $user['email']]);
    } else {
        $error = $GLOBALS['LAST_SMTP_ERROR'] ?? 'Unknown error';
        echo json_encode(['success' => false, 'message' => 'Failed to send email. Error: ' . $error]);
    }

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>
