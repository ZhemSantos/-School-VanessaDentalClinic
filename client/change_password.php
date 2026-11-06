<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Ensure user is logged in
if (!isset($_SESSION['patient_id'])) {
    header("Location: ../landing.php");
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    if (strlen($new_password) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } else {
        try {
            // Get account_id
            $stmt = $pdo->prepare("SELECT account_id FROM tbl_patient WHERE patient_id = ?");
            $stmt->execute([$_SESSION['patient_id']]);
            $patient = $stmt->fetch();
            
            if ($patient) {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $pdo->prepare("UPDATE tbl_patient_accounts SET password_hash = ?, is_temporary_password = 0 WHERE account_id = ?");
                $update->execute([$hash, $patient['account_id']]);
                $success = "Password updated successfully. redirecting...";
                echo "<script>setTimeout(function(){ window.location.href = 'client_dashboard.php'; }, 2000);</script>";
            }
        } catch (PDOException $e) {
            $error = "Error updating password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Password - Security Check</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 flex items-center justify-center h-screen">
    <div class="bg-white p-8 rounded-xl shadow-lg w-full max-w-md">
        <h2 class="text-2xl font-bold mb-2 text-center text-gray-800">Security Check</h2>
        <p class="text-sm text-gray-500 mb-6 text-center">You are using a temporary password. Please create a new password to continue.</p>
        
        <?php if($error): ?>
            <div class="bg-red-100 text-red-700 p-3 rounded mb-4 text-sm"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="bg-green-100 text-green-700 p-3 rounded mb-4 text-sm"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label class="block text-sm font-medium mb-1">New Password</label>
                <input type="password" name="new_password" required class="w-full border p-2 rounded focus:ring-2 focus:ring-purple-500 outline-none">
            </div>
            <div class="mb-6">
                <label class="block text-sm font-medium mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" required class="w-full border p-2 rounded focus:ring-2 focus:ring-purple-500 outline-none">
            </div>
            <button type="submit" class="w-full bg-purple-600 text-white py-2 rounded hover:bg-purple-700 transition">Update Password</button>
        </form>
    </div>
</body>
</html>
