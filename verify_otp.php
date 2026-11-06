<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';
require_once 'includes/email_config.php';

// Configure secure session
configureSecureSession();

$email = $_SESSION['verify_email'] ?? '';
$error = '';
$success = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);

if (empty($email)) {
    // If no email in session, maybe redirect or let them type it?
    // For now, let's just let them type if empty, but usually it's passed.
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['verify_otp'])) {
        $input_otp = trim($_POST['otp']);
        $input_email = trim($_POST['email']);
        
        if (empty($input_otp)) {
            $error = 'Please enter the verification code.';
        } else {
            try {
                // Verify OTP
                // Join to check email and OTP
                $stmt = $pdo->prepare("
                    SELECT pa.account_id, pa.otp, pa.otp_expiry 
                    FROM tbl_patient_accounts pa
                    JOIN tbl_patient p ON pa.account_id = p.account_id
                    WHERE p.email = ? AND pa.is_verified = 0
                ");
                $stmt->execute([$input_email]);
                $account = $stmt->fetch();
                
                if ($account) {
                    if ($account['otp'] === $input_otp) {
                        if (strtotime($account['otp_expiry']) > time()) {
                            // Valid and not expired
                            $update = $pdo->prepare("UPDATE tbl_patient_accounts SET is_verified = 1, otp = NULL, otp_expiry = NULL WHERE account_id = ?");
                            $update->execute([$account['account_id']]);
                            
                            // Clear session
                            unset($_SESSION['verify_email']);
                            
                            // Redirect to login
                            header("Location: landing.php?verified=1");
                            exit();
                        } else {
                            $error = 'Verification code has expired. Please request a new one.';
                        }
                    } else {
                        $error = 'Invalid verification code. Please try again.';
                    }
                } else {
                    $error = 'Account not found or already verified.';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    } elseif (isset($_POST['resend_otp'])) {
        $input_email = trim($_POST['email']);
        
        try {
            // Get account based on email
            $stmt = $pdo->prepare("
                SELECT pa.account_id, p.first_name, p.last_name 
                FROM tbl_patient_accounts pa
                JOIN tbl_patient p ON pa.account_id = p.account_id
                WHERE p.email = ? AND pa.is_verified = 0
            ");
            $stmt->execute([$input_email]);
            $account = $stmt->fetch();
            
            if ($account) {
                $new_otp = str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
                $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                
                $update = $pdo->prepare("UPDATE tbl_patient_accounts SET otp = ?, otp_expiry = ? WHERE account_id = ?");
                $update->execute([$new_otp, $expiry, $account['account_id']]);
                
                // Send email
                // Need to duplicate this function or include it properly if it's not global
                // Ideally sendVerificationEmail should be in functions file.
                // For now, I'll assume I can just use sendEmail
                $subject = "Resend: Verify Your Account - Vanessa Nicolas Dental Clinic";
                $message = "Your new verification code is: <h1>$new_otp</h1>";
                sendEmail($input_email, $subject, $message);
                
                $success = 'A new verification code has been sent to your email.';
            } else {
                $error = 'Account not found or already verified.';
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
    <title>Verify Account - Vanessa Nicolas Dental Clinic</title>
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
            <h2 class="text-3xl font-bold text-gray-900 mb-2">Verify OTP</h2>
            <p class="text-gray-500 text-sm">We sent a 6-digit code to your email.</p>
            <p class="font-medium text-primary mt-1 mb-2"><?php echo htmlspecialchars($email); ?></p>
            <p class="text-xs text-amber-600 bg-amber-50 inline-block px-3 py-1 rounded-full border border-amber-100">
                <span class="font-bold">Note:</span> If you don't see it, please check your Spam/Junk folder.
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
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
            
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-2 text-center">Enter Verification Code</label>
                <div class="flex justify-center">
                    <input type="text" name="otp" required maxlength="6" 
                        class="w-48 text-center text-3xl tracking-widest font-bold h-14 rounded-xl border border-gray-300 focus:border-primary focus:ring-4 focus:ring-primary/10 transition-all outline-none"
                        placeholder="000000" pattern="[0-9]*" inputmode="numeric">
                </div>
            </div>

            <button type="submit" name="verify_otp" class="w-full bg-primary text-white h-12 rounded-xl font-bold shadow-lg hover:shadow-xl hover:bg-[#6A1B9A] transition-all transform hover:-translate-y-0.5">
                Verify Account
            </button>
        </form>

        <div class="mt-6 text-center">
            <form method="POST" action="">
                <input type="hidden" name="email" value="<?php echo htmlspecialchars($email); ?>">
                <p class="text-sm text-gray-600">
                    Didn't receive the code? 
                    <button type="submit" name="resend_otp" class="text-primary font-bold hover:underline bg-transparent border-none cursor-pointer">Resend</button>
                </p>
            </form>
        </div>
        
        <div class="mt-4 text-center">
            <a href="landing.php" class="text-sm text-gray-400 hover:text-gray-600">Back to Login</a>
        </div>
    </div>

</body>
</html>
