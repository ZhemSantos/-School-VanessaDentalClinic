<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';
require_once 'includes/email_config.php'; // For resend

// Configure secure session
configureSecureSession();

$email = $_SESSION['reset_email'] ?? '';
$error = '';
$success = ''; // Can be set if resent

if (empty($email)) {
    header("Location: forgot_password.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_otp'])) {
        $input_otp = trim($_POST['otp']);
        
        if (empty($input_otp)) {
            $error = 'Please enter the code.';
        } else {
            try {
                // Verify OTP
                $stmt = $pdo->prepare("
                    SELECT pa.account_id, pa.otp, pa.otp_expiry 
                    FROM tbl_patient_accounts pa
                    JOIN tbl_patient p ON pa.account_id = p.account_id
                    WHERE p.email = ?
                ");
                $stmt->execute([$email]);
                $account = $stmt->fetch();
                
                if ($account) {
                    if ($account['otp'] === $input_otp) {
                        if (strtotime($account['otp_expiry']) > time()) {
                            // Valid Code
                            $_SESSION['reset_account_id'] = $account['account_id'];
                            
                            // Optional: Clear OTP now or clear after password set? 
                            // Better to clear after password set to prevent replay if step 2 fails.
                            // But for security, maybe one-time use token is better.
                            // We will clear it in the next step.
                            
                            header("Location: reset_password_final.php");
                            exit();
                        } else {
                            $error = 'Code has expired. Please request a new one.';
                        }
                    } else {
                        $error = 'Invalid code. Please try again.';
                    }
                } else {
                    $error = 'Account error.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['resend_otp'])) {
        // Resend Logic
         try {
            $stmt = $pdo->prepare("
                SELECT pa.account_id, p.first_name 
                FROM tbl_patient_accounts pa
                JOIN tbl_patient p ON pa.account_id = p.account_id
                WHERE p.email = ?
            ");
            $stmt->execute([$email]);
            $account = $stmt->fetch();
            
            if ($account) {
                $new_otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                
                $update = $pdo->prepare("UPDATE tbl_patient_accounts SET otp = ?, otp_expiry = ? WHERE account_id = ?");
                $update->execute([$new_otp, $expiry, $account['account_id']]);
                
                $subject = "Resend: Password Reset Code";
                $message = "Your new password reset code is: <h1>$new_otp</h1>";
                sendEmail($email, $subject, $message);
                
                $success = 'A new code has been sent.';
            }
        } catch (PDOException $e) {
            $error = 'Error resending code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Code - Vanessa Nicolas Dental Clinic</title>
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
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Check Your Email</h2>
            <p class="text-gray-500 text-sm">We sent a reset code to</p>
            <p class="font-medium text-primary mt-1 mb-2"><?php echo htmlspecialchars($email); ?></p>
            <p class="text-xs text-amber-600 bg-amber-50 inline-block px-3 py-1 rounded-full border border-amber-100">
                <span class="font-bold">Note:</span> check your Spam/Junk folder if missing.
            </p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="mb-6 bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg text-sm text-center">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success)): ?>
            <div class="mb-6 bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg text-sm text-center">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="" class="space-y-6">
            <div>
                <div class="flex justify-center">
                    <input type="text" name="otp" required maxlength="6" 
                        class="w-48 text-center text-3xl tracking-widest font-bold h-14 rounded-xl border border-gray-300 focus:border-primary focus:ring-4 focus:ring-primary/10 transition-all outline-none"
                        placeholder="000000" pattern="[0-9]*" inputmode="numeric">
                </div>
            </div>

            <button type="submit" name="verify_otp" class="w-full bg-primary text-white h-12 rounded-xl font-bold shadow-lg hover:shadow-xl hover:bg-[#6A1B9A] transition-all transform hover:-translate-y-0.5">
                Verify Code
            </button>
        </form>

        <div class="mt-6 text-center">
             <form method="POST" action="">
                <p class="text-sm text-gray-600">
                    Didn't receive it? 
                    <button type="submit" name="resend_otp" class="text-primary font-bold hover:underline bg-transparent border-none cursor-pointer">Resend</button>
                </p>
            </form>
        </div>
        
        <div class="mt-4 text-center">
            <a href="forgot_password.php" class="text-sm text-gray-400 hover:text-gray-600">Use different email</a>
        </div>
    </div>

</body>
</html>
