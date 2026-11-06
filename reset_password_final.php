<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';

// Configure secure session
configureSecureSession();

$accountId = $_SESSION['reset_account_id'] ?? null;
$error = '';

if (empty($accountId)) {
    header("Location: forgot_password.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    
    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            // reset is_temporary_password to 0 if they had one, effectively treating this as a verified change
            // also clear OTP
            $stmt = $pdo->prepare("UPDATE tbl_patient_accounts SET password_hash = ?, otp = NULL, otp_expiry = NULL, is_temporary_password = 0 WHERE account_id = ?");
            $stmt->execute([$hash, $accountId]);
            
            // Clean up session
            unset($_SESSION['reset_account_id']);
            unset($_SESSION['reset_email']);
            
            header("Location: landing.php?reset=success");
            exit();
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set New Password - Vanessa Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#8a11d4',
                        secondary: '#eddafc',
                    },
                    fontFamily: {
                        display: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 font-display min-h-screen flex items-center justify-center p-4">

    <div class="max-w-md w-full bg-white rounded-2xl shadow-xl overflow-hidden border border-gray-100 p-8">
        <div class="text-center mb-8">
            <h2 class="text-3xl font-bold text-gray-900 mb-2">New Password</h2>
            <p class="text-gray-500 text-sm">Create a strong password for your account.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg text-sm text-center">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">New Password</label>
                <input type="password" name="password" required 
                    class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all outline-none"
                    placeholder="Min. 8 characters">
            </div>
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
                <input type="password" name="confirm_password" required 
                    class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all outline-none"
                    placeholder="Re-enter password">
            </div>

            <button type="submit" class="w-full bg-primary text-white h-12 rounded-xl font-bold shadow-lg hover:shadow-xl hover:bg-[#6A1B9A] transition-all transform hover:-translate-y-0.5">
                Update Password
            </button>
        </form>
    </div>

</body>
</html>
