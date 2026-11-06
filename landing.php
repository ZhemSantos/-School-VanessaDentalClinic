<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';

// Configure secure session
configureSecureSession();

// If already logged in, redirect to appropriate dashboard
if (isLoggedIn()) {
    switch ($_SESSION['role']) {
        case 'Admin':
            header('Location: admin/admin_dashboard.php');
            exit;
        case 'Dentist':
            header('Location: doctor/doctor_dashboard.php');
            exit;
        case 'Receptionist':
            header('Location: receptionist/receptionist_dashboard.php');
            exit;
    }
}

$error_message = '';
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submitted_token)) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        $username = sanitizeInput($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        
        // Input validation
        if (empty($username) || empty($password)) {
            $error_message = 'Please enter both username and password.';
        } elseif (strlen($username) > 50) {
            $error_message = 'Invalid username format.';
        } else {
            // Check rate limiting
            $rate_limit = checkLoginAttempts($username);
            if ($rate_limit['locked']) {
                $error_message = 'Too many failed login attempts. Please try again in ' . $rate_limit['remaining_minutes'] . ' minutes.';
            } else {
                try {
                    // ------------------------------------------------------------------
                    // 1. Attempt Staff Login (tbl_user)
                    // ------------------------------------------------------------------
                    $stmt = $pdo->prepare("SELECT user_id, username, password_hash, full_name, role, status, is_password_temporary FROM tbl_user WHERE username = ? LIMIT 1");
                    $stmt->execute([$username]);
                    $staff_user = $stmt->fetch();
                    
                    $staff_logged_in = false;

                    if ($staff_user && password_verify($password, $staff_user['password_hash'])) {
                        if ($staff_user['status'] === 'Inactive') {
                            $error_message = 'Your account is inactive. Please contact administrator.';
                            recordFailedLogin($username);
                            $staff_logged_in = true;
                        } else {
                            // Staff Login Success
                            $staff_logged_in = true;
                            clearLoginAttempts($username);
                            session_regenerate_id(true);
                            
                            $_SESSION['user_id'] = $staff_user['user_id'];
                            $_SESSION['username'] = $staff_user['username'];
                            $_SESSION['full_name'] = $staff_user['full_name'];
                            $_SESSION['role'] = $staff_user['role'];
                            $_SESSION['login_time'] = time();
                            $_SESSION['user'] = [
                                'user_id' => $staff_user['user_id'],
                                'username' => $staff_user['username'],
                                'full_name' => $staff_user['full_name'],
                                'role' => $staff_user['role'],
                                'email' => $staff_user['email'] ?? null,
                                'profile_picture' => $staff_user['profile_picture'] ?? null,
                                'is_password_temporary' => $staff_user['is_password_temporary'] ?? 0 // Store temp flag
                            ];

                            // Check Force Password Reset
                            if (isset($staff_user['is_password_temporary']) && $staff_user['is_password_temporary']) {
                                header('Location: reset_credentials.php');
                                exit;
                            }

                            // Update Staff Last Active
                            try {
                                $update = $pdo->prepare("UPDATE tbl_user SET last_active = NOW() WHERE user_id = ?");
                                $update->execute([$staff_user['user_id']]);
                            } catch (Exception $ignore) {}
                            
                            generateCSRFToken();
                            
                            // Redirect based on role
                            switch ($staff_user['role']) {
                                case 'Admin': header('Location: admin/admin_dashboard.php'); exit;
                                case 'Dentist': header('Location: doctor/doctor_dashboard.php'); exit;
                                case 'Receptionist': header('Location: receptionist/receptionist_dashboard.php'); exit;
                                default: session_destroy(); $error_message = 'Invalid role.';
                            }
                        }
                    }

                    // ------------------------------------------------------------------
                    // 2. Attempt Patient Login (tbl_patient_accounts)
                    // Only if not logged in as staff
                    // ------------------------------------------------------------------
                    if (!$staff_logged_in && empty($error_message)) {
                        $stmt = $pdo->prepare("
                            SELECT pa.*, p.patient_id, p.first_name, p.last_name, p.email, pa.is_temporary_password 
                            FROM tbl_patient_accounts pa 
                            LEFT JOIN tbl_patient p ON pa.account_id = p.account_id 
                            WHERE pa.username = ? OR p.email = ?
                        ");
                        $stmt->execute([$username, $username]);
                        $patient_user = $stmt->fetch();

                        if ($patient_user && password_verify($password, $patient_user['password_hash'])) {
                            // Patient Login Success
                            clearLoginAttempts($username);
                            session_regenerate_id(true);
                            
                            $_SESSION['user_id'] = $patient_user['account_id'];
                            $_SESSION['patient_id'] = $patient_user['patient_id'];
                            $_SESSION['username'] = $patient_user['username'];
                            $_SESSION['full_name'] = trim($patient_user['first_name'] . ' ' . $patient_user['last_name']);
                            $_SESSION['patient_name'] = $_SESSION['full_name'];
                            $_SESSION['role'] = 'Patient';
                            $_SESSION['login_time'] = time();
                            $_SESSION['verified'] = $patient_user['is_verified'];
                            
                            // Store comprehensive user array
                            $_SESSION['user'] = [
                                'user_id' => $patient_user['account_id'],
                                'patient_id' => $patient_user['patient_id'],
                                'username' => $patient_user['username'],
                                'full_name' => trim($patient_user['first_name'] . ' ' . $patient_user['last_name']),
                                'role' => 'Patient',
                                'email' => $patient_user['email'],
                                'profile_picture' => $patient_user['profile_picture'] ?? null,
                                'is_password_temporary' => $patient_user['is_temporary_password'] ?? 0 // Normalize to common key
                            ];

                            // Check Force Password Reset
                            if (isset($patient_user['is_password_temporary']) && $patient_user['is_password_temporary']) {
                                header('Location: reset_credentials.php');
                                exit;
                            }

                            // Update Patient Last Active (Silently fail if column missing)
                            try {
                                $update = $pdo->prepare("UPDATE tbl_patient_accounts SET last_active = NOW() WHERE account_id = ?");
                                $update->execute([$patient_user['account_id']]);
                            } catch (Exception $ignore) {}

                            generateCSRFToken();
                            header('Location: client/client_dashboard.php');
                            exit;

                        } else {
                            // Both Staff and Patient checks failed
                            recordFailedLogin($username);
                            $error_message = 'Invalid username or password.';
                        }
                    }

                } catch (PDOException $e) {
                    error_log('Login error: ' . $e->getMessage());
                    $error_message = 'System error: ' . $e->getMessage();
                }
            }
        }
    }
    
    // Regenerate CSRF token after POST
    $csrf_token = generateCSRFToken();
}
?>
<!DOCTYPE html>

<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Universal Staff Login - Vanessa Nicolas Dental Clinic</title>
<!-- Google Fonts: Manrope -->
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&amp;display=swap" rel="stylesheet"/>
<!-- Material Symbols -->
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<!-- Tailwind CSS -->
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<!-- Tailwind Config -->
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4",
                        "background-light": "#f7f6f8",
                        "background-dark": "#1b1022",
                    },
                    fontFamily: {
                        "display": ["Manrope", "sans-serif"]
                    },
                    borderRadius: {
                        "DEFAULT": "0.5rem",
                        "lg": "1rem",
                        "xl": "1.5rem",
                        "full": "9999px"
                    },
                },
            },
        }
    </script>
<style>
        /* Custom Gold Accent for the theme requirements */
        .border-gold {
            border-color: #FFD700;
        }
        .bg-gold {
            background-color: #FFD700;
        }
        .text-gold {
            color: #FFD700;
        }
        
        /* Smooth fade for interactive elements */
        .transition-all {
            transition-property: all;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 300ms;
        }
    </style>
<script>
// Password visibility toggle functionality
document.addEventListener('DOMContentLoaded', function() {
    const passwordInput = document.getElementById('password-input');
    const toggleButton = document.getElementById('toggle-password');
    const passwordIcon = document.getElementById('password-icon');
    
    if (toggleButton && passwordInput && passwordIcon) {
        toggleButton.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Toggle icon between visibility and visibility_off
            if (type === 'text') {
                passwordIcon.textContent = 'visibility_off';
            } else {
                passwordIcon.textContent = 'visibility';
            }
        });
    }
});
</script>
</head>
<body class="bg-background-light dark:bg-background-dark font-display min-h-screen flex items-center justify-center p-4 text-[#151118] dark:text-white">
    <!-- Background Image / Pattern Overlay -->
    <div class="fixed inset-0 z-0 opacity-15 pointer-events-none bg-center bg-cover" data-alt="Dental Clinic Background" style="background-image: url('images/pic1.jpg');">
    </div>

    <!-- Header -->
    <nav class="fixed top-0 left-0 w-full z-50 bg-white border-b border-[#e2dbe6]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <div class="flex-shrink-0 flex items-center">
                    <a href="index.php">
                        <img class="h-16 w-auto" src="images/logo/logo.png" alt="Vanessa Nicolas Dental Clinic">
                    </a>
                </div>
                <div class="flex items-center gap-4">
                    <a href="index.php" class="text-[#7a6189] hover:text-primary font-bold text-sm transition-colors">
                        Back to Home
                    </a>
                    <a href="registration.php" class="bg-primary hover:bg-[#720eb0] text-white font-bold py-2 px-6 rounded-full text-sm transition-all shadow-lg shadow-primary/20 hover:shadow-xl hover:-translate-y-0.5">
                        Register
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- Main Card -->
    <div class="relative z-10 w-full max-w-4xl bg-white dark:bg-[#251a2e] rounded-2xl shadow-2xl overflow-hidden flex flex-col md:flex-row border border-[#e2dbe6] dark:border-[#3a2a45] mt-24">
        
        <!-- Left Panel: Info & Benefits -->
        <div class="w-full md:w-1/2 bg-primary p-8 md:p-12 text-white flex flex-col justify-center relative overflow-hidden">
            <!-- Decorative circle -->
            <div class="absolute -top-10 -left-10 w-40 h-40 bg-white opacity-10 rounded-full blur-2xl"></div>
            <div class="absolute bottom-10 right-10 w-60 h-60 bg-gold opacity-10 rounded-full blur-3xl"></div>
            
            <div class="relative z-10 flex flex-col h-full justify-between">
                <div>
                    <h2 class="text-3xl font-bold mb-2">Welcome Back!</h2>
                    <p class="text-purple-100 mb-8">Access your personal dental health portal.</p>
                    
                    <div class="flex flex-col gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-gold">bolt</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm">Quick Access</h3>
                                <p class="text-xs text-purple-200">Instant login to your records</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-gold">calendar_month</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm">View Your Appointments</h3>
                                <p class="text-xs text-purple-200">Check upcoming schedules & details</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-gold">receipt_long</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm">Payment History</h3>
                                <p class="text-xs text-purple-200">Track all your transactions</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-white/10 flex items-center justify-center shrink-0">
                                <span class="material-symbols-outlined text-gold">lock</span>
                            </div>
                            <div>
                                <h3 class="font-bold text-sm">Secure Login</h3>
                                <p class="text-xs text-purple-200">Your data is fully protected</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="pt-8 mt-8 border-t border-white/10">
                    <p class="text-xs text-purple-200">Not a patient yet?</p>
                    <a href="registration.php" class="text-gold font-bold hover:underline">Create an Account</a>
                </div>
            </div>
        </div>

        <!-- Right Panel: Login Form -->
        <div class="w-full md:w-1/2 p-8 md:p-12 flex flex-col justify-center bg-white dark:bg-[#251a2e]">
            <!-- Header Section -->
            <div class="flex flex-col items-center text-center gap-3 mb-8">
                <div class="size-14 rounded-full bg-primary/10 flex items-center justify-center mb-2">
                    <span class="material-symbols-outlined text-primary text-3xl">dentistry</span>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-[#151118] dark:text-white leading-tight">Vanessa Nicolas Dental Clinic</h2>
                    <p class="text-[#7a6189] dark:text-[#bca4cd] text-sm font-medium">User Portal</p>
                </div>
            </div>

            <?php
            // Check for upcoming holidays
            try {
               if(isset($pdo)) {
                   $h_stmt = $pdo->query("SELECT DISTINCT block_date, reason FROM tbl_blocked_time WHERE block_date >= CURDATE() AND block_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND reason LIKE '%Holiday%' ORDER BY block_date ASC LIMIT 1");
                   $holiday = $h_stmt->fetch(PDO::FETCH_ASSOC);
                   if($holiday) {
                       $h_date = date('F d, Y', strtotime($holiday['block_date']));
                       echo '<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3 mb-4 flex items-center gap-2 text-sm shadow-sm">
                               <span class="material-symbols-outlined text-yellow-600 text-lg">warning</span>
                               <span class="text-yellow-800">
                                   <strong>Notice:</strong> The clinic will be closed on <strong>'.$h_date.'</strong> for <strong>'.htmlspecialchars($holiday['reason']).'</strong>.
                               </span>
                             </div>';
                   }
               }
            } catch(PDOException $e) {}
            ?>

            <?php if ($error_message): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-red-600 text-lg">error</span>
                <span class="text-sm font-medium text-red-800"><?php echo htmlspecialchars($error_message); ?></span>
            </div>
            <?php endif; ?>

            <!-- Login Form -->
            <form class="flex flex-col gap-4" method="POST" action="landing.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <!-- Username Field -->
                <label class="flex flex-col gap-1.5">
                    <span class="text-[#151118] dark:text-white text-xs font-bold uppercase tracking-wider">Username / ID / Email</span>
                    <div class="flex w-full items-center rounded-xl border border-[#e2dbe6] dark:border-[#4a3b55] bg-background-light dark:bg-[#2f223a] focus-within:border-primary focus-within:ring-1 focus-within:ring-primary transition-all overflow-hidden h-12">
                        <div class="pl-4 pr-2 text-[#7a6189]">
                            <span class="material-symbols-outlined text-[20px]">person</span>
                        </div>
                        <input class="flex-1 bg-transparent border-none text-[#151118] dark:text-white placeholder:text-[#7a6189] px-2 text-sm focus:ring-0 h-full" placeholder="Enter your username" type="text" name="username" required autocomplete="username"/>
                    </div>
                </label>
                <!-- Password Field -->
                <label class="flex flex-col gap-1.5">
                    <span class="text-[#151118] dark:text-white text-xs font-bold uppercase tracking-wider">Password</span>
                    <div class="flex w-full items-center rounded-xl border border-[#e2dbe6] dark:border-[#4a3b55] bg-background-light dark:bg-[#2f223a] focus-within:border-primary focus-within:ring-1 focus-within:ring-primary transition-all overflow-hidden h-12">
                        <div class="pl-4 pr-2 text-[#7a6189]">
                            <span class="material-symbols-outlined text-[20px]">lock</span>
                        </div>
                        <input id="password-input" class="flex-1 bg-transparent border-none text-[#151118] dark:text-white placeholder:text-[#7a6189] px-2 text-sm focus:ring-0 h-full" placeholder="Enter your password" type="password" name="password" required autocomplete="current-password"/>
                        <button type="button" id="toggle-password" class="px-4 h-full flex items-center justify-center text-[#7a6189] hover:text-primary transition-colors cursor-pointer focus:outline-none">
                            <span class="material-symbols-outlined text-[20px]" id="password-icon">visibility</span>
                        </button>
                    </div>
                </label>
                
                <div class="flex items-center justify-between text-xs mt-1">
                    <label class="flex items-center gap-2 cursor-pointer text-[#7a6189]">
                        <input type="checkbox" class="rounded border-gray-300 text-primary focus:ring-primary">
                        <span>Remember me</span>
                    </label>
                    <a class="font-bold text-primary hover:text-[#720eb0] transition-colors" href="forgot_password.php">Forgot Password?</a>
                </div>

                <!-- Login Button -->
                <button type="submit" class="mt-4 w-full bg-primary hover:bg-[#720eb0] text-white font-bold h-12 rounded-xl flex items-center justify-center gap-2 shadow-lg shadow-primary/20 transition-all hover:scale-[1.01] hover:shadow-xl">
                    <span>Log In</span>
                    <span class="material-symbols-outlined text-[20px]">arrow_forward</span>
                </button>
            </form>
            
            <p class="mt-8 text-center text-xs text-[#7a6189]">
                System v1.0.4 • <a href="index.php" class="hover:underline">Back to Home</a>
            </p>
        </div>
    </div>
</body>
</html>