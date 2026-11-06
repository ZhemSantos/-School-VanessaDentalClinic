<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';
require_once 'includes/email_config.php';

// Configure secure session
configureSecureSession();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = sanitizeInput($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'Please enter your email address.';
    } else {
        try {
            // Check if email belongs to a patient (NOT an employee)
            // tbl_patient has email, and links to tbl_patient_accounts
            $stmt = $pdo->prepare("
                SELECT p.first_name, p.last_name, pa.account_id 
                FROM tbl_patient p
                JOIN tbl_patient_accounts pa ON p.account_id = pa.account_id
                WHERE p.email = ?
            ");
            $stmt->execute([$email]);
            $patient = $stmt->fetch();
            
            if ($patient) {
                // Generate OTP
                $otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $otpExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                
                // Update tbl_patient_accounts with OTP
                // We use the same 'otp' columns we added for registration
                $update = $pdo->prepare("UPDATE tbl_patient_accounts SET otp = ?, otp_expiry = ? WHERE account_id = ?");
                $update->execute([$otp, $otpExpiry, $patient['account_id']]);
                
                // Send Email
                $subject = "Password Reset - Vanessa Nicolas Dental Clinic";
                $message = "
                    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #eee; border-radius: 10px;'>
                        <h2 style='color: #6A1B9A; text-align: center;'>Reset Your Password</h2>
                        <p>Dear " . htmlspecialchars($patient['first_name']) . ",</p>
                        <p>You requested to reset your password. Please use the following One-Time Password (OTP) to proceed:</p>
                        <div style='background-color: #f8f9fa; padding: 15px; text-align: center; border-radius: 5px; margin: 20px 0;'>
                            <h1 style='color: #8a11d4; letter-spacing: 5px; margin: 0; font-size: 32px;'>" . htmlspecialchars($otp) . "</h1>
                        </div>
                        <p>This code is valid for 10 minutes.</p>
                        <p>If you did not request a password reset, please ignore this email.</p>
                        <hr style='border: none; border-top: 1px solid #eee; margin: 20px 0;'>
                        <p style='font-size: 12px; color: #888; text-align: center;'>Vanessa Nicolas Dental Clinic</p>
                    </div>
                ";
                
                sendEmail($email, $subject, $message);
                
                // Store email in session for verification step
                $_SESSION['reset_email'] = $email;
                
                header("Location: verify_reset_otp.php");
                exit();
                
            } else {
                // Check if it's an employee to give specific error
                $empStmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE email = ?");
                $empStmt->execute([$email]);
                if ($empStmt->fetch()) {
                    $error = 'Employees cannot reset passwords here. Please contact the administrator.';
                } else {
                    // Security: Verify generic message or vague? 
                    // Usually "If account exists..." but user wants functional.
                    $error = 'No patient account found with this email address.';
                }
            }
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
    <title>Forgot Password - Vanessa Nicolas Dental Clinic</title>
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
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Forgot Password</h2>
            <p class="text-gray-500 text-sm">Enter your registered email address to receive a password reset code.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg text-sm text-center">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-6">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Email Address</label>
                <input type="email" name="email" required 
                    class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all outline-none"
                    placeholder="name@example.com">
            </div>

            <button type="submit" class="w-full bg-primary text-white h-12 rounded-xl font-bold shadow-lg hover:shadow-xl hover:bg-[#6A1B9A] transition-all transform hover:-translate-y-0.5">
                Send Reset Code
            </button>
        </form>
        
        <div class="mt-6 text-center">
            <a href="landing.php" class="text-sm text-gray-400 hover:text-gray-600">Back to Login</a>
        </div>
    </div>

</body>
</html>
