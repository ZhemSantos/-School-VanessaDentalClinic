<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';

// Force logout if logged in (for security)
if (isset($_SESSION['user_id'])) {
    session_destroy();
    session_start();
}

$message = '';
$message_type = '';
$token = $_GET['token'] ?? '';
$valid_token = false;

// Verify Token integrity
if (empty($token) || strlen($token) !== 64) {
    $message = 'Invalid or missing reset token.';
    $message_type = 'error';
} else {
    // Check DB
    try {
        $stmt = $pdo->prepare("SELECT user_id, full_name, username FROM tbl_user WHERE reset_token = ? AND reset_expires_at > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if ($user) {
            $valid_token = true;
        } else {
            $message = 'This link has expired or is invalid.';
            $message_type = 'error';
        }
    } catch (PDOException $e) {
        $message = 'Database error.';
        $message_type = 'error';
    }
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid_token) {
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate
    if (strlen($password) < 8) {
        $message = 'Password must be at least 8 characters long.';
        $message_type = 'error';
    } elseif ($password !== $confirm_password) {
        $message = 'Passwords do not match.';
        $message_type = 'error';
    } else {
        // Update Password
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $update = $pdo->prepare("UPDATE tbl_user SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL, is_password_temporary = 0 WHERE user_id = ?");
            $update->execute([$hash, $user['user_id']]);
            
            $message = 'Password updated successfully! You can now login.';
            $message_type = 'success';
            $valid_token = false; // Disable form
        } catch (PDOException $e) {
            $message = 'Failed to update password.';
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - Vanessa Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1" rel="stylesheet" />
    <style>
        body { font-family: 'Manrope', sans-serif; }
    </style>
</head>
<body class="bg-purple-50 flex items-center justify-center h-screen px-4">

    <div class="bg-white rounded-2xl shadow-xl p-8 max-w-md w-full border border-purple-100">
        <div class="text-center mb-8">
            <div class="bg-purple-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-purple-600">
                <span class="material-symbols-outlined text-3xl">lock_reset</span>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">Reset Password</h1>
            <?php if ($valid_token): ?>
                <p class="text-gray-500 text-sm mt-2">Set a new password for <strong><?php echo htmlspecialchars($user['username']); ?></strong></p>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl flex items-start gap-3 <?php echo $message_type === 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?>">
                <span class="material-symbols-outlined"><?php echo $message_type === 'success' ? 'check_circle' : 'error'; ?></span>
                <p class="text-sm font-medium pt-0.5"><?php echo htmlspecialchars($message); ?></p>
            </div>
            <?php if ($message_type === 'success'): ?>
                <a href="landing.php" class="block w-full text-center py-3 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl transition-all shadow-lg shadow-purple-200">
                    Proceed to Login
                </a>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($valid_token && $message_type !== 'success'): ?>
        <form method="POST" class="space-y-5">
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">New Password</label>
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xl">lock</span>
                    <input type="password" name="password" required class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none transition-all" placeholder="Minimum 8 characters">
                </div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase mb-1">Confirm Password</label>
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-xl">lock_clock</span>
                    <input type="password" name="confirm_password" required class="w-full pl-10 pr-4 py-3 bg-gray-50 border border-gray-200 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 outline-none transition-all" placeholder="Re-enter password">
                </div>
            </div>

            <button type="submit" class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl transition-all shadow-lg shadow-purple-200 flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">save</span>
                Update Password
            </button>
        </form>
        <?php endif; ?>
        
        <?php if (!$valid_token && $message_type !== 'success'): ?>
            <a href="landing.php" class="block w-full text-center py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold rounded-xl transition-all">
                Return to Login
            </a>
        <?php endif; ?>
    </div>

</body>
</html>
