<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';

// Configure secure session
configureSecureSession();

// Ensure user is logged in
if (!isLoggedIn()) {
    header('Location: landing.php');
    exit;
}

// Ensure the user actually needs to reset their password
if (!isset($_SESSION['user']['is_password_temporary']) || !$_SESSION['user']['is_password_temporary']) {
    // Re-check DB to be safe, sometimes session might be stale? 
    // Ideally session is fresh from landing.
    // If not temporary, redirect to dashboard based on role
    $role = $_SESSION['role'];
    switch ($role) {
        case 'Admin': header('Location: admin/admin_dashboard.php'); exit;
        case 'Dentist': header('Location: doctor/doctor_dashboard.php'); exit;
        case 'Receptionist': header('Location: receptionist/receptionist_dashboard.php'); exit;
        case 'Dental Assistant': header('Location: dental_assistant/dashboard.php'); exit; 
        case 'Patient': header('Location: client/client_dashboard.php'); exit;
        default: header('Location: landing.php'); exit;
    }
}

$error_message = '';
$success_message = '';
$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submitted_token)) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        $new_username = sanitizeInput($_POST['new_username'] ?? '');
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        $errors = [];
        
        // Validate Username
        if (empty($new_username)) {
            $errors[] = "Username is required.";
        } elseif (strlen($new_username) < 3 || strlen($new_username) > 50) {
            $errors[] = "Username must be between 3 and 50 characters.";
        } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $new_username)) {
            $errors[] = "Username can only contain letters, numbers, and underscores.";
        }
        
        // Validate Password
        if (empty($new_password)) {
            $errors[] = "Password is required.";
        } elseif (strlen($new_password) < 8) {
            $errors[] = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $new_password) || !preg_match('/[a-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
            $errors[] = "Password must contain uppercase, lowercase, and numbers.";
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
        }
        
        // Improve Confidentiality: Enforce username change for Patients (if requested or by default for all)
        // User specifically asked for patients to change username
        if ($_SESSION['role'] === 'Patient' && $new_username === $_SESSION['username']) {
            $errors[] = "For security and confidentiality, you must change your username.";
        }
        
        if (empty($errors)) {
            try {
                // Determine table and ID column based on role
                $is_patient = ($_SESSION['role'] === 'Patient');
                $table = $is_patient ? 'tbl_patient_accounts' : 'tbl_user';
                $id_col = $is_patient ? 'account_id' : 'user_id';
                $temp_pass_col = $is_patient ? 'is_temporary_password' : 'is_password_temporary';
                $user_id = $is_patient ? $_SESSION['user']['user_id'] : $_SESSION['user_id'];
                
                // Check username uniqueness
                $stmt = $pdo->prepare("SELECT $id_col FROM $table WHERE username = ? AND $id_col != ?");
                $stmt->execute([$new_username, $user_id]);
                if ($stmt->fetch()) {
                    $errors[] = "Username is already taken.";
                } else {
                    // Update Credentials
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update = $pdo->prepare("UPDATE $table SET username = ?, password_hash = ?, $temp_pass_col = 0 WHERE $id_col = ?");
                    $update->execute([$new_username, $hashed_password, $user_id]);
                    
                    // Update Session
                    $_SESSION['username'] = $new_username;
                    if (isset($_SESSION['user'])) {
                        $_SESSION['user']['username'] = $new_username;
                        unset($_SESSION['user']['is_password_temporary']);
                    }
                    
                    // Redirect to dashboard
                    $role = $_SESSION['role'];
                    $redirect = 'landing.php';
                    switch ($role) {
                        case 'Admin': $redirect = 'admin/admin_dashboard.php'; break;
                        case 'Dentist': $redirect = 'doctor/doctor_dashboard.php'; break;
                        case 'Receptionist': $redirect = 'receptionist/receptionist_dashboard.php'; break;
                        case 'Patient': $redirect = 'client/client_dashboard.php'; break;
                    }
                    
                    header("Location: $redirect");
                    exit;
                }
            } catch (Exception $e) {
                error_log("Credential Update Error: " . $e->getMessage());
                $errors[] = "System error. Please try again.";
            }
        }
        
        if (!empty($errors)) {
            $error_message = implode('<br>', $errors);
        }
    }
    $csrf_token = generateCSRFToken();
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Set New Credentials - Vanessa Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;700;800&amp;display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
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
                    }
                },
            },
        }
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark font-display min-h-screen flex items-center justify-center p-4 text-[#151118] dark:text-white">
    <div class="relative z-10 w-full max-w-md bg-white dark:bg-[#251a2e] rounded-2xl shadow-2xl overflow-hidden border border-[#e2dbe6] dark:border-[#3a2a45]">
        <div class="p-8">
            <div class="text-center mb-8">
                <div class="size-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
                    <span class="material-symbols-outlined text-primary text-4xl">lock_reset</span>
                </div>
                <h1 class="text-2xl font-bold mb-2">Setup Your Account</h1>
                <p class="text-sm text-[#7a6189]">Because this is your first time logging in, you must set a new username and password for security.</p>
            </div>
            
            <?php if ($error_message): ?>
            <div class="bg-red-50 border border-red-200 rounded-lg p-3 mb-6 flex items-start gap-3">
                <span class="material-symbols-outlined text-red-600">error</span>
                <span class="text-sm text-red-800 font-medium"><?php echo $error_message; ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" action="reset_credentials.php" class="flex flex-col gap-5">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                
                <!-- New Username -->
                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-[#7a6189]">New Username</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[#7a6189]">
                            <span class="material-symbols-outlined text-[20px]">person</span>
                        </span>
                        <input type="text" name="new_username" required 
                               class="w-full pl-11 pr-4 py-3 rounded-xl border border-[#e2dbe6] dark:border-[#4a3b55] bg-background-light/50 focus:border-primary focus:ring-primary transition-colors text-sm"
                               placeholder="Choose a unique username"
                               value="<?php echo htmlspecialchars($_POST['new_username'] ?? $_SESSION['username'] ?? ''); ?>">
                    </div>
                </div>

                <!-- New Password -->
                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-[#7a6189]">New Password</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[#7a6189]">
                            <span class="material-symbols-outlined text-[20px]">lock</span>
                        </span>
                        <input type="password" name="new_password" required minlength="8"
                               class="w-full pl-11 pr-4 py-3 rounded-xl border border-[#e2dbe6] dark:border-[#4a3b55] bg-background-light/50 focus:border-primary focus:ring-primary transition-colors text-sm"
                               placeholder="Min. 8 chars, Uppercase, Number">
                    </div>
                </div>

                <!-- Confirm Password -->
                <div class="space-y-1.5">
                    <label class="text-xs font-bold uppercase tracking-wider text-[#7a6189]">Confirm Password</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-[#7a6189]">
                            <span class="material-symbols-outlined text-[20px]">lock_clock</span>
                        </span>
                        <input type="password" name="confirm_password" required minlength="8"
                               class="w-full pl-11 pr-4 py-3 rounded-xl border border-[#e2dbe6] dark:border-[#4a3b55] bg-background-light/50 focus:border-primary focus:ring-primary transition-colors text-sm"
                               placeholder="Re-enter new password">
                    </div>
                </div>

                <button type="submit" class="mt-4 w-full bg-primary hover:bg-[#720eb0] text-white font-bold py-3.5 rounded-xl shadow-lg transition-all hover:scale-[1.01]">
                    Update Credentials
                </button>
            </form>
        </div>
    </div>
</body>
</html>
