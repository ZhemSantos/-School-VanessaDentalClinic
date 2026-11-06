<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';

// Configure secure session
configureSecureSession();

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_checkin'])) {
    
    // Verify CSRF (if security.php provides it, otherwise skip or implement simplistic check)
    // Assuming generated below
    
    $identifier = trim($_POST['identifier'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($identifier) || empty($password)) {
        $message = "Please enter your Username/Email/ID and Password.";
        $message_type = 'error';
    } else {
        try {
            // Find User (Smart Search: Username OR Email OR Patient ID)
            // We need to join account table to get password
            $stmt = $pdo->prepare("
                SELECT p.patient_id, p.first_name, p.last_name, p.email, p.account_id,
                       pa.username, pa.password_hash, pa.is_temporary_password
                FROM tbl_patient p
                JOIN tbl_patient_accounts pa ON p.account_id = pa.account_id
                WHERE pa.username = ? 
                   OR p.email = ? 
                   OR p.patient_id = ?
            ");
            $stmt->execute([$identifier, $identifier, $identifier]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // AUTH SUCESS
                
                // 1. Set Session (Log them in automatically)
                $_SESSION['user_id'] = $user['account_id'];
                $_SESSION['patient_id'] = $user['patient_id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['patient_name'] = $_SESSION['full_name'];
                $_SESSION['role'] = 'Patient';
                $_SESSION['login_time'] = time();
                
                // 2. Perform Check-in
                $today = date('Y-m-d');
                $appt_stmt = $pdo->prepare("
                    SELECT appointment_id, status, time_slot 
                    FROM tbl_appointment 
                    WHERE patient_id = ? AND appointment_date = ? 
                    ORDER BY 
                        CASE 
                            WHEN status IN ('Booked', 'Pending') THEN 1 
                            WHEN status = 'Arrived' THEN 2
                            ELSE 3 
                        END ASC,
                        time_slot ASC 
                    LIMIT 1
                ");
                $appt_stmt->execute([$user['patient_id'], $today]);
                $appt = $appt_stmt->fetch();
                
                $checkin_msg = "";
                $is_checkin_success = false;

                if ($appt) {
                    if (in_array($appt['status'], ['Booked', 'Pending', 'Scheduled'])) {
                        $upd = $pdo->prepare("UPDATE tbl_appointment SET status = 'Arrived' WHERE appointment_id = ?");
                        $upd->execute([$appt['appointment_id']]);
                        $checkin_msg = "You have been successfully CHECKED IN for your " . date('g:i A', strtotime($appt['time_slot'])) . " appointment.";
                        $is_checkin_success = true;
                    } elseif ($appt['status'] == 'Arrived') {
                        $checkin_msg = "You are already marked as Arrived.";
                        $is_checkin_success = true;
                    } elseif ($appt['status'] == 'Cancelled') {
                        $checkin_msg = "Your appointment for today is CANCELLED. Please contact reception.";
                        $is_checkin_success = false;
                    } else {
                        $checkin_msg = "Your appointment status is currently '" . $appt['status'] . "'.";
                        $is_checkin_success = false;
                    }
                } else {
                     $checkin_msg = "No scheduled appointment found for today.";
                     $is_checkin_success = false; // Treat as warning
                }
                
                $message = "Welcome, " . htmlspecialchars($user['first_name']) . "! " . $checkin_msg;
                
                // If login worked but check-in had issues (Cancelled/None), show Error type?
                // User asked for RED if Cancelled.
                if ($is_checkin_success) {
                    $message_type = 'success';
                } else {
                    $message_type = 'error'; 
                }
                
            } else {
                $message = "Invalid credentials. Please check your username/email and password.";
                $message_type = 'error';
            }
            
        } catch (Exception $e) {
            $message = "System error: " . $e->getMessage();
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Secure Check-in</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
</head>
<body class="font-[Manrope] min-h-screen flex items-center justify-center p-4">

    <!-- Background Image -->
    <div class="fixed inset-0 z-0">
        <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('images/pic1.jpg');"></div>
        <div class="absolute inset-0 bg-purple-900/80 backdrop-blur-sm"></div>
    </div>

    <div class="relative z-10 bg-white w-full max-w-lg rounded-3xl shadow-2xl overflow-hidden p-8">
        
        <div class="text-center mb-8">
            <div class="bg-purple-100 w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4 text-purple-600">
                 <span class="material-symbols-outlined text-3xl">verified_user</span>
            </div>
            <h1 class="text-2xl font-black text-gray-900">Patient Check-in</h1>
            <p class="text-gray-500 mt-2">Log in to confirm your arrival</p>
        </div>

        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-xl flex flex-col gap-3 <?php echo $message_type === 'success' ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'; ?>">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined shrink-0"><?php echo $message_type === 'success' ? 'check_circle' : 'error'; ?></span>
                    <p class="font-bold text-sm"><?php echo htmlspecialchars($message); ?></p>
                </div>
                
                <?php if ($message_type === 'success'): ?>
                    <a href="client/client_dashboard.php" class="mt-2 w-full py-3 bg-green-600 hover:bg-green-700 text-white font-bold rounded-lg text-center transition-colors shadow-md flex items-center justify-center gap-2">
                        <span>Proceed to Dashboard</span>
                        <span class="material-symbols-outlined text-sm">arrow_forward</span>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($message_type !== 'success'): ?>
        <form method="POST" class="space-y-5">
            
            <!-- Identifier Input -->
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Username, Email, or Patient ID</label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 material-symbols-outlined">person_search</span>
                    <input type="text" name="identifier" required placeholder="e.g. juan@email.com" 
                           class="w-full pl-12 pr-4 py-4 bg-gray-50 border-2 border-gray-100 rounded-xl font-bold text-gray-900 focus:outline-none focus:border-purple-500 focus:bg-white transition-all">
                </div>
            </div>

            <!-- Password Input -->
            <div>
                <label class="block text-xs font-bold text-gray-500 uppercase tracking-wide mb-2">Password</label>
                <div class="relative">
                    <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400 material-symbols-outlined">lock</span>
                    <input type="password" name="password" required placeholder="Enter your password" 
                           class="w-full pl-12 pr-4 py-4 bg-gray-50 border-2 border-gray-100 rounded-xl font-bold text-gray-900 focus:outline-none focus:border-purple-500 focus:bg-white transition-all">
                </div>
            </div>

            <button type="submit" name="login_checkin" class="w-full py-4 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-xl shadow-lg shadow-purple-200 transition-all flex items-center justify-center gap-2 transform active:scale-95">
                <span class="material-symbols-outlined">login</span>
                Check In & Log In
            </button>
        </form>
        
        <div class="mt-6 flex justify-center">
            <a href="forgot_password.php" class="text-sm font-bold text-purple-600 hover:text-purple-800 transition-colors">Forgot Password?</a>
        </div>
        <?php endif; ?>

        <div class="mt-8 text-center">
            <p class="text-xs text-gray-400">© 2026 Vanessa Nicolas Dental Clinic</p>
        </div>
    </div>

</body>
</html>
