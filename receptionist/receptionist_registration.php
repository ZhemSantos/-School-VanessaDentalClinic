<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role to access this page
requireRole('Receptionist');

// Update current user's activity
updateUserActivity();

// Fetch current user's full details from database
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $user_details_stmt = $pdo->prepare("SELECT user_id, username, full_name, role, email, contact_number, blood_type, status, profile_picture, created_at FROM tbl_user WHERE user_id = ? LIMIT 1");
        $user_details_stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $user_details_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching user details: ' . $e->getMessage());
    }
}

// Helper function to generate time dropdown with 15-minute intervals
function generateTimeDropdown($name, $value = '', $min_time = '00:00', $max_time = '23:45', $classes = '', $disabled = false, $required = false, $data_attr = '', $id = '') {
    $options = [];
    
    // Normalize the value to HH:MM format (round to nearest 15 minutes if needed)
    $normalized_value = '';
    if (!empty($value)) {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            $hour = (int)$matches[1];
            $min = (int)$matches[2];
            // Round to nearest 15 minutes
            $min = round($min / 15) * 15;
            if ($min >= 60) {
                $min = 0;
                $hour++;
            }
            $normalized_value = sprintf('%02d:%02d', $hour, $min);
        }
    }
    
    // Parse min and max times
    list($min_hour, $min_min) = explode(':', $min_time);
    list($max_hour, $max_min) = explode(':', $max_time);
    $min_total = (int)$min_hour * 60 + (int)$min_min;
    $max_total = (int)$max_hour * 60 + (int)$max_min;
    
    // Generate time options in 15-minute intervals
    for ($hour = 0; $hour < 24; $hour++) {
        for ($min = 0; $min < 60; $min += 15) {
            $time_total = $hour * 60 + $min;
            if ($time_total >= $min_total && $time_total <= $max_total) {
                $time_str = sprintf('%02d:%02d', $hour, $min);
                $time_display = date('g:i A', strtotime($time_str));
                $options[] = [
                    'value' => $time_str,
                    'display' => $time_display,
                    'selected' => ($normalized_value === $time_str)
                ];
            }
        }
    }
    
    $disabled_attr = $disabled ? 'disabled' : '';
    $required_attr = $required ? 'required' : '';
    $data_attr_html = $data_attr ? ' ' . $data_attr : '';
    $id_attr = $id ? ' id="' . htmlspecialchars($id) . '"' : '';
    
    $html = '<select name="' . htmlspecialchars($name) . '"' . $id_attr . ' class="' . htmlspecialchars($classes) . '" ' . $disabled_attr . ' ' . $required_attr . $data_attr_html . '>';
    $html .= '<option value="">Select time...</option>';
    foreach ($options as $option) {
        $selected = $option['selected'] ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($option['value']) . '" ' . $selected . '>' . htmlspecialchars($option['display']) . '</option>';
    }
    $html .= '</select>';
    
    return $html;
}

// Helper function to get default profile photo
function getDefaultProfilePhoto($full_name, $size = 40) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    
    $colors = [
        ['bg' => '#FFD700', 'text' => '#6A1B9A'],
        ['bg' => '#6A1B9A', 'text' => '#ffffff'],
        ['bg' => '#8a11d4', 'text' => '#ffffff'],
        ['bg' => '#10b981', 'text' => '#ffffff'],
        ['bg' => '#3b82f6', 'text' => '#ffffff'],
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    
    return [
        'initials' => $initials,
        'bg_color' => $color['bg'],
        'text_color' => $color['text']
    ];
}

$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Receptionist';
$current_role = $current_user['role'] ?? 'Receptionist';
$current_profile = $current_user['profile_picture'] ?? null;
$default_photo = getDefaultProfilePhoto($current_name);

// Get current page for active navigation state
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch dentists for dropdown
$dentists = [];
try {
    $dentist_stmt = $pdo->prepare("SELECT user_id, full_name FROM tbl_user WHERE role = 'Dentist' AND status = 'Active' ORDER BY full_name");
    $dentist_stmt->execute();
    $dentists = $dentist_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching dentists: ' . $e->getMessage());
}

// Fetch services for dropdown
$services = [];
try {
    $service_stmt = $pdo->prepare("SELECT service_id, service_name, base_price FROM tbl_service ORDER BY service_name");
    $service_stmt->execute();
    $services = $service_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching services: ' . $e->getMessage());
}

// Handle form submission
$success_message = '';
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_patient'])) {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        // Get form data
        $first_name = sanitizeInput($_POST['first_name'] ?? '');
        $middle_name = sanitizeInput($_POST['middle_name'] ?? ''); // Added Middle Name
        $last_name = sanitizeInput($_POST['last_name'] ?? '');
        $dob = $_POST['dob'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $civil_status = $_POST['civil_status'] ?? '';
        $occupation = sanitizeInput($_POST['occupation'] ?? '');
        $blood_type = $_POST['blood_type'] ?? 'Unknown';
        $medical_history = sanitizeInput($_POST['medical_history'] ?? '');
        $phone_number = sanitizeInput($_POST['phone_number'] ?? '');
        $phone_number = preg_replace('/[^0-9]/', '', $phone_number);
        $email = sanitizeInput($_POST['email'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? ''); // Concatenated address from Frontend
        
        // Visit details
        $visit_type = $_POST['visit_type'] ?? '';
        
        // Validation
        $errors = [];
        
        if (empty($first_name)) $errors[] = 'First name is required.';
        if (empty($last_name)) $errors[] = 'Last name is required.';
        if (empty($dob)) $errors[] = 'Date of birth is required.';
        if (empty($gender)) $errors[] = 'Gender is required.';
        if (empty($civil_status)) $errors[] = 'Civil status is required.';
        if (empty($phone_number)) $errors[] = 'Phone number is required.';
        if (!empty($email) && !validateEmail($email)) {
            $errors[] = 'Invalid email format.';
        }

        // Age Verification (< 18 restriction)
        $dob_date = new DateTime($dob);
        $today_date = new DateTime();
        $age_check = $today_date->diff($dob_date)->y;
        
        if ($age_check < 18) {
            // $errors[] = 'Registration is not allowed for minors (under 18 years old).';
            // Allow minors but check for guardian
            if (empty($_POST['guardian_id'])) {
                $errors[] = 'A Guardian/Parent is required for minors (under 18).';
            }
        }
        
        // Check if patient already exists (by phone or email) - REMOVED per user request to allow dependents
        // if (empty($errors)) {
        //     try {
        //         $check_stmt = $pdo->prepare("SELECT patient_id FROM tbl_patient WHERE phone_number = ? OR (email = ? AND email != '')");
        //         $check_stmt->execute([$phone_number, $email]);
        //         $existing = $check_stmt->fetch();
        //         if ($existing) {
        //             $errors[] = 'A patient with this phone number or email already exists.';
        //         }
        //     } catch (PDOException $e) {
        //         error_log('Error checking existing patient: ' . $e->getMessage());
        //     }
        // }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1. Generate Credentials
                $currentYear = date('Y');
                $randomCode = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                $generatedUsername = "VN-{$currentYear}-{$randomCode}";
                
                // Retry if username exists
                while(true) {
                    $u_check = $pdo->prepare("SELECT account_id FROM tbl_patient_accounts WHERE username = ?");
                    $u_check->execute([$generatedUsername]);
                    if (!$u_check->fetch()) break; // Unique
                    $randomCode = str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
                    $generatedUsername = "VN-{$currentYear}-{$randomCode}";
                }

                $birthYear = date('Y', strtotime($dob));
                $generatedPasswordPlain = ucfirst(strtolower($last_name)) . $birthYear;
                $passwordHash = password_hash($generatedPasswordPlain, PASSWORD_DEFAULT);

                // 2. Insert Account
                $account_stmt = $pdo->prepare("INSERT INTO tbl_patient_accounts (username, password_hash, is_verified, is_temporary_password) VALUES (?, ?, 1, 1)");
                $account_stmt->execute([$generatedUsername, $passwordHash]);
                $account_id = $pdo->lastInsertId();

                // 3. Insert Patient Profile
                $age = date_diff(date_create($dob), date_create('today'))->y;
                $age_category = ($age >= 60) ? 'Senior' : (($age < 18) ? 'Minor' : 'Adult');

                // Handle duplicate email for dependents (use NULL if it matches guardian)
                if (!empty($_POST['guardian_id']) && !empty($email)) {
                   $g_check = $pdo->prepare("SELECT email FROM tbl_patient WHERE patient_id = ?");
                   $g_check->execute([$_POST['guardian_id']]);
                   $g_email = $g_check->fetchColumn();
                   
                   if ($g_email && strtolower(trim($email)) === strtolower(trim($g_email))) {
                       $email = null; 
                   }
                }

                $insert_stmt = $pdo->prepare("
                    INSERT INTO tbl_patient (account_id, first_name, middle_name, last_name, dob, age, age_category, gender, phone_number, email, medical_history, blood_type, civil_status, occupation, address)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $insert_stmt->execute([
                    $account_id, $first_name, $middle_name, $last_name, $dob, $age, $age_category, $gender, $phone_number, 
                    $email ?: null, $medical_history ?: null, $blood_type, $civil_status, 
                    $occupation ?: null, $address
                ]);
                
                $patient_id = $pdo->lastInsertId();
                
                // 3.5 Insert Dependency Link if Guardian is set
                if (!empty($_POST['guardian_id'])) {
                    $guardianId = $_POST['guardian_id'];
                    $relationship = $_POST['relationship'] ?? 'Guardian';
                    
                    // Fetch Guardian's Account ID
                    $ga_stmt = $pdo->prepare("SELECT account_id FROM tbl_patient WHERE patient_id = ?");
                    $ga_stmt->execute([$guardianId]);
                    $guardianAccountId = $ga_stmt->fetchColumn();
                    
                    if ($guardianAccountId) {
                        $dep_stmt = $pdo->prepare("
                            INSERT INTO tbl_dependencies (guardian_account_id, sub_patient_id, relationship, access_verified)
                            VALUES (?, ?, ?, 1)
                        "); // Auto-verify for receptionist created links? Yes, assumed verified in person.
                        $dep_stmt->execute([$guardianAccountId, $patient_id, $relationship]);
                    }
                }
                
                // 4. Send Email (Credentials)
                if (!empty($email)) {
                    require_once '../includes/email_notifications.php';
                    // We need to use the patient_id we just created
                    sendNewAccountCredentials($patient_id, $generatedUsername, $generatedPasswordPlain, $email);
                }
                
                $pdo->commit();
                
                // Redirect with Success Data for Modal
                $redirectUrl = "receptionist_registration.php?success=1" . 
                               "&username=" . urlencode($generatedUsername) . 
                               "&temp_pass=" . urlencode($generatedPasswordPlain) .
                               "&visit_type=" . urlencode($visit_type) . 
                               "&patient_id=" . $patient_id;
                               
                header("Location: " . $redirectUrl);
                exit;
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Error registering patient: ' . $e->getMessage());
                $error_message = 'Failed to register patient: ' . $e->getMessage();
            }
        } else {
            $error_message = implode(' ', $errors);
            $form_data = $_POST;
        }
    }
}

// Generate CSRF token
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Patient Registration - Receptionist Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "primary": "#8a11d4",
              "royal-purple": "#6A1B9A",
              "clinic-gold": "#FFD700",
              "background-light": "#f7f6f8",
              "background-dark": "#1b1022",
            },
            fontFamily: {
              "display": ["Manrope", "sans-serif"]
            },
            borderRadius: {"DEFAULT": "0.5rem", "lg": "1rem", "xl": "1.5rem", "full": "9999px"},
          },
        },
      }
    </script>
<style>
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }.form-checkbox:checked {
            background-color: #6A1B9A;
            border-color: #6A1B9A;
        }
        .toggle-checkbox:checked {
            right: 0;
            border-color: #6A1B9A;
        }
        .toggle-checkbox:checked + .toggle-label {
            background-color: #6A1B9A;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-[#151118] font-display antialiased overflow-hidden">
<div class="flex h-screen w-full">
<aside class="w-72 bg-royal-purple text-white flex flex-col flex-shrink-0 h-full shadow-2xl z-20 relative">
<div class="p-8 pb-4">
<div class="flex flex-col gap-1">
<h1 class="text-xl font-bold leading-tight tracking-wide">Vanessa Nicolas</h1>
<p class="text-white/70 text-sm font-medium">Dental Clinic System</p>
</div>
</div>
<div class="px-8 py-4 mb-2">
<a href="receptionist_current_profile.php" class="flex items-center gap-3 bg-white/10 p-3 rounded-xl border border-white/10 backdrop-blur-sm hover:bg-white/20 transition-all cursor-pointer group">
<?php if ($current_profile && file_exists('../' . $current_profile)): ?>
<div class="w-10 h-10 rounded-full bg-cover bg-center flex-shrink-0 border-2 border-transparent group-hover:border-white/50 transition-all" style="background-image: url('../<?php echo htmlspecialchars($current_profile); ?>');"></div>
<?php else: ?>
<div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-lg flex-shrink-0 border-2 border-transparent group-hover:border-white/50 transition-all" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;">
<?php echo htmlspecialchars($default_photo['initials']); ?>
</div>
<?php endif; ?>
<div class="flex flex-col overflow-hidden">
<span class="text-sm font-bold truncate group-hover:text-white transition-colors"><?php echo htmlspecialchars($current_name); ?></span>
<span class="text-xs text-white/70 truncate group-hover:text-white/90 transition-colors"><?php echo htmlspecialchars($current_role); ?></span>
</div>
</a>
</div>
<nav class="flex-1 px-4 gap-2 flex flex-col overflow-y-auto py-4">
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_dashboard.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_dashboard.php">
<span class="material-symbols-outlined">dashboard</span>
<span class="<?php echo ($current_page === 'receptionist_dashboard.php') ? 'font-bold' : 'font-medium'; ?>">Dashboard</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_registration.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_registration.php">
<span class="material-symbols-outlined">person_add</span>
<span class="<?php echo ($current_page === 'receptionist_registration.php') ? 'font-bold' : 'font-medium'; ?>">Patient Registration</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'patient_records.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="patient_records.php">
<span class="material-symbols-outlined">folder_shared</span>
<span class="<?php echo ($current_page === 'patient_records.php') ? 'font-bold' : 'font-medium'; ?>">Patient Records</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_booking.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_booking.php">
<span class="material-symbols-outlined">calendar_month</span>
<span class="<?php echo ($current_page === 'receptionist_booking.php') ? 'font-bold' : 'font-medium'; ?>">Bookings</span>
</a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_doctor_schedule.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_doctor_schedule.php">
                <span class="material-symbols-outlined">perm_contact_calendar</span>
                <span class="<?php echo ($current_page === 'receptionist_doctor_schedule.php') ? 'font-bold' : 'font-medium'; ?>">Doctor Schedules</span>
            </a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_queue.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_queue.php">
<span class="material-symbols-outlined">monitor_heart</span>
<span class="<?php echo ($current_page === 'receptionist_queue.php') ? 'font-bold' : 'font-medium'; ?>">Queue Monitor</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_payment.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_payment.php">
<span class="material-symbols-outlined">payments</span>
<span class="<?php echo ($current_page === 'receptionist_payment.php') ? 'font-bold' : 'font-medium'; ?>">Payments</span>
</a>
</nav>
<div class="p-6">
<div class="text-xs text-white/40 text-center font-medium">v2.4.0 • Reception Portal</div>
<a href="../logout.php" class="flex items-center justify-center gap-2 mt-4 w-full py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium">
<span class="material-symbols-outlined text-[18px]">logout</span>
Logout
</a>
</div>
</aside>
<main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
<header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
<div class="flex flex-col gap-1">
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Patient Registration</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">info</span>
                    Create new profile and assign to queue
                </p>
</div>
<div class="flex items-center gap-4">
<label class="relative hidden lg:flex items-center h-12 w-80">
<div class="absolute left-4 text-slate-400 flex items-center justify-center">
<span class="material-symbols-outlined">search</span>
</div>
<input class="w-full h-full pl-12 pr-4 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400 shadow-sm" placeholder="Check if patient exists..."/>
</label>
<button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
<span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full"></span>
<span class="material-symbols-outlined">notifications</span>
</button>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8">
<div class="max-w-[1400px] mx-auto flex flex-col gap-6">
<div class="flex items-center justify-between">
<a class="flex items-center gap-2 text-slate-500 hover:text-royal-purple font-bold text-sm transition-colors" href="receptionist_dashboard.php">
<span class="material-symbols-outlined text-lg">arrow_back</span>
                        Back to Dashboard
                    </a>
<span class="text-xs font-mono text-slate-400 bg-white px-3 py-1 rounded-full border border-gray-200">Session ID: REG-<?php echo date('YmdHis'); ?></span>
</div>
<?php if ($success_message): ?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-green-600">check_circle</span>
<p class="text-green-800 font-medium"><?php echo htmlspecialchars($success_message); ?></p>
</div>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-red-600">error</span>
<p class="text-red-800 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
</div>
</div>
<?php endif; ?>
<form action="" method="POST" class="flex flex-col gap-6">
<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
<input type="hidden" name="register_patient" value="1">
<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
<div class="xl:col-span-8 flex flex-col gap-6">
    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 relative overflow-hidden">
        <div class="absolute top-0 left-0 w-1 h-full bg-royal-purple"></div>
        
        <!-- Dependent Switch -->
        <div class="flex items-center justify-between mb-8 pb-8 border-b border-gray-100">
            <div>
                <h3 class="text-lg font-bold text-[#151118] flex items-center gap-2">
                    <span class="material-symbols-outlined text-royal-purple">family_restroom</span>
                    Register as Dependent?
                </h3>
                <p class="text-xs text-slate-500 mt-1">Enable this for minors or patients managed by a guardian.</p>
            </div>
            <label class="relative inline-flex items-center cursor-pointer">
                <input type="checkbox" id="is_dependent_toggle" class="sr-only peer" onchange="toggleDependentMode()">
                <div class="w-14 h-7 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-0.5 after:left-[4px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-royal-purple"></div>
            </label>
        </div>

        <h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
            <span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
                <span class="material-symbols-outlined text-sm">badge</span>
            </span>
            Personal Identity
        </h3>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">First Name <span class="text-red-500">*</span></label>
<input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" placeholder="e.g. Michael" name="first_name" value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>" required type="text"/>
</div>
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Middle Name</label>
<input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" placeholder="e.g. Santos" name="middle_name" value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>" type="text"/>
</div>
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Last Name <span class="text-red-500">*</span></label>
<input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" placeholder="e.g. Jordan" name="last_name" value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" required type="text"/>
</div>
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Date of Birth <span class="text-red-500">*</span></label>
<input id="dob_input" onchange="calculateAge()" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none text-slate-600" name="dob" value="<?php echo htmlspecialchars($form_data['dob'] ?? ''); ?>" required type="date" max="<?php echo date('Y-m-d'); ?>"/>
</div>
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Gender</label>
<div class="flex items-center gap-4 h-[46px]">
<label class="flex items-center gap-2 cursor-pointer">
<input class="w-5 h-5 text-royal-purple border-gray-300 focus:ring-royal-purple" name="gender" value="Male" type="radio" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'Male') ? 'checked' : ''; ?> required/>
<span class="text-sm font-medium text-slate-700">Male</span>
</label>
<label class="flex items-center gap-2 cursor-pointer">
<input class="w-5 h-5 text-royal-purple border-gray-300 focus:ring-royal-purple" name="gender" value="Female" type="radio" <?php echo (isset($form_data['gender']) && $form_data['gender'] === 'Female') ? 'checked' : ''; ?> required/>
<span class="text-sm font-medium text-slate-700">Female</span>
</label>
</div>
</div>
<div class="col-span-1">
    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Age (Auto-calculated)</label>
    <input id="age_display" class="w-full bg-gray-100 border border-gray-200 rounded-xl px-4 py-3 text-sm text-slate-500 font-bold focus:outline-none cursor-not-allowed" placeholder="0 years old" readonly type="text"/>
</div>
<div class="col-span-1 hidden" id="guardian_field">
    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Guardian / Parent <span class="text-red-500">*</span></label>
    
    <!-- Trigger Button -->
    <div id="guardianSelectContainer">
        <button type="button" onclick="openGuardianModal()" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold text-slate-600 hover:bg-gray-100 hover:border-royal-purple transition-all flex items-center justify-center gap-2">
            <span class="material-symbols-outlined">person_search</span>
            <span>Select Guardian...</span>
        </button>
    </div>

    <!-- Selected Guardian Display -->
    <div id="guardianDisplayContainer" class="hidden p-4 bg-purple-50 rounded-xl border border-purple-100 flex items-center gap-3 flex-wrap">
        <div class="flex items-center gap-3 flex-1 min-w-[200px]">
            <div class="w-10 h-10 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-lg" id="guardianInitials">--</div>
            <div>
                <p class="text-sm font-bold text-royal-purple" id="guardianName">--</p>
                <p class="text-xs text-slate-500" id="guardianInfo">--</p>
            </div>
        </div>

        <!-- Relationship Dropdown -->
        <div class="flex-shrink-0">
             <select name="relationship" class="bg-white border border-purple-200 text-royal-purple text-xs font-bold rounded-lg focus:ring-royal-purple focus:border-royal-purple block w-full p-2.5">
                <option value="Parent">Parent</option>
                <option value="Sibling">Sibling</option>
                <option value="Spouse">Spouse</option>
                <option value="Child">Child</option>
                <option value="Relative">Relative</option>
                <option value="Guardian">Legal Guardian</option>
                <option value="Other">Other</option>
            </select>
        </div>

        <button type="button" class="text-slate-400 hover:text-red-500 ml-auto" onclick="clearGuardianSelection()"><span class="material-symbols-outlined text-sm">close</span></button>
    </div>

    <input type="hidden" name="guardian_id" id="guardian_id">
    
    <!-- Hidden stores for guardian info -->
    <input type="hidden" id="guardian_email_store">
    <input type="hidden" id="guardian_address_store">
    <input type="hidden" id="guardian_phone_store">
    
    <p class="text-[10px] text-orange-500 mt-2 font-bold hidden" id="minor_warning"><span class="material-symbols-outlined text-[12px] align-middle">warning</span> Required for minors under 18.</p>
</div>

</div>
</div>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 relative">
<h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
<span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined text-sm">demography</span>
</span>
                                    Demographics &amp; Health
                                </h3>
<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Civil Status <span class="text-red-500">*</span></label>
<select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none cursor-pointer" name="civil_status" required>
<option value="">Select Status</option>
<option value="Single" <?php echo (isset($form_data['civil_status']) && $form_data['civil_status'] === 'Single') ? 'selected' : ''; ?>>Single</option>
<option value="Married" <?php echo (isset($form_data['civil_status']) && $form_data['civil_status'] === 'Married') ? 'selected' : ''; ?>>Married</option>
<option value="Widowed" <?php echo (isset($form_data['civil_status']) && $form_data['civil_status'] === 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
<option value="Separated" <?php echo (isset($form_data['civil_status']) && $form_data['civil_status'] === 'Separated') ? 'selected' : ''; ?>>Separated</option>
</select>
</div>
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Occupation <span class="text-slate-400 font-normal normal-case">(Optional)</span></label>
<input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" placeholder="e.g. Teacher" name="occupation" value="<?php echo htmlspecialchars($form_data['occupation'] ?? ''); ?>" type="text"/>
</div>
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Blood Type <span class="text-red-500">*</span></label>
<select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none cursor-pointer" name="blood_type" required>
<option value="">Select Type</option>
<option value="A+" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'A+') ? 'selected' : ''; ?>>A+</option>
<option value="A-" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'A-') ? 'selected' : ''; ?>>A-</option>
<option value="B+" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'B+') ? 'selected' : ''; ?>>B+</option>
<option value="B-" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'B-') ? 'selected' : ''; ?>>B-</option>
<option value="O+" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'O+') ? 'selected' : ''; ?>>O+</option>
<option value="O-" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'O-') ? 'selected' : ''; ?>>O-</option>
<option value="AB+" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'AB+') ? 'selected' : ''; ?>>AB+</option>
<option value="AB-" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'AB-') ? 'selected' : ''; ?>>AB-</option>
<option value="Unknown" <?php echo (isset($form_data['blood_type']) && $form_data['blood_type'] === 'Unknown' || !isset($form_data['blood_type'])) ? 'selected' : ''; ?>>Unknown</option>
</select>
</div>
</div>
<div class="col-span-1">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Medical History &amp; Alerts</label>
<textarea class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none resize-none" placeholder="List any allergies, past surgeries, or ongoing medical conditions..." name="medical_history" rows="3"><?php echo htmlspecialchars($form_data['medical_history'] ?? ''); ?></textarea>
</div>
</div>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 relative">
<h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
<span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined text-sm">contact_phone</span>
</span>
                                    Contact Information
                                </h3>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
<div class="col-span-1">
    <div class="flex items-center justify-between mb-2">
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide">Phone Number <span class="text-red-500">*</span></label>
        <label class="inline-flex items-center gap-1 cursor-pointer opacity-50 pointer-events-none transition-all" id="use_guardian_phone_container">
            <input type="checkbox" id="use_guardian_phone" class="form-checkbox h-3 w-3 text-royal-purple rounded border-gray-300 focus:ring-royal-purple" onchange="copyGuardianPhone()">
            <span class="text-[10px] font-bold text-royal-purple">Use Guardian's</span>
        </label>
    </div>
    <input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" placeholder="09123456789" name="phone_number" value="<?php echo htmlspecialchars($form_data['phone_number'] ?? ''); ?>" required type="tel" pattern="[0-9]{10,15}" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')" maxlength="15"/>
</div>
<div class="col-span-1">
    <div class="flex items-center justify-between mb-2">
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide">Email Address</label>
        <label class="inline-flex items-center gap-1 cursor-pointer opacity-50 pointer-events-none transition-all" id="use_guardian_email_container">
            <input type="checkbox" id="use_guardian_email" class="form-checkbox h-3 w-3 text-royal-purple rounded border-gray-300 focus:ring-royal-purple" onchange="copyGuardianEmail()">
            <span class="text-[10px] font-bold text-royal-purple">Use Guardian's</span>
        </label>
    </div>
    <input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" placeholder="patient@email.com" name="email" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" type="email"/>
</div>
</div>
<div>
    <div class="flex items-center justify-between mb-2">
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide">Complete Address <span class="text-red-500">*</span></label>
        <label class="inline-flex items-center gap-1 cursor-pointer opacity-50 pointer-events-none transition-all" id="use_guardian_address_container">
            <input type="checkbox" id="use_guardian_address" class="form-checkbox h-3 w-3 text-royal-purple rounded border-gray-300 focus:ring-royal-purple" onchange="copyGuardianAddress()">
            <span class="text-[10px] font-bold text-royal-purple">Use Guardian's</span>
        </label>
    </div>
<input type="hidden" name="address" id="full_address">
<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
    <select id="province" required onchange="fetchCities()" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
        <option value="">Select Province</option>
    </select>
    <select id="city" required onchange="fetchBarangays()" disabled class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none disabled:bg-gray-200">
        <option value="">Select City/Municipality</option>
    </select>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-3">
    <select id="barangay" required onchange="updateFullAddress()" disabled class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none disabled:bg-gray-200">
        <option value="">Select Barangay</option>
    </select>
    <input type="text" id="street" required oninput="updateFullAddress()" placeholder="Street / House No." class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
</div>
</div>
</div>
</div>
<div class="xl:col-span-4 flex flex-col gap-6">
<div class="bg-green-50 border border-green-100 rounded-2xl p-6">
<div class="flex items-start gap-4">
<div class="w-10 h-10 rounded-full bg-green-100 text-green-600 flex items-center justify-center flex-shrink-0">
<span class="material-symbols-outlined">how_to_reg</span>
</div>
<div>
<h4 class="font-bold text-green-900 text-lg">New Registration</h4>
<p class="text-sm text-green-800/70 mt-1 leading-snug">This patient will be assigned a new System ID automatically upon submission.</p>
</div>
</div>
</div>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 relative">
<h3 class="text-lg font-bold text-[#151118] mb-4">Visit Details</h3>
<div class="flex flex-col gap-4">
<div>
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Visit Type</label>
<select class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none cursor-pointer" name="visit_type" id="visit_type">
<option value="Walk-In (Unscheduled)" <?php echo (isset($form_data['visit_type']) && $form_data['visit_type'] === 'Walk-In (Unscheduled)') ? 'selected' : ''; ?>>Walk-In (Unscheduled)</option>
<option value="Emergency" <?php echo (isset($form_data['visit_type']) && $form_data['visit_type'] === 'Emergency') ? 'selected' : ''; ?>>Emergency</option>
<option value="Pre-Booked Appointment" <?php echo (isset($form_data['visit_type']) && $form_data['visit_type'] === 'Pre-Booked Appointment') ? 'selected' : ''; ?>>Pre-Booked Appointment</option>
</select>
<p class="text-xs text-gray-500 mt-2">Redirects to booking after registration if selected.</p>
</div>
</div>
</div>

</div>
</div>

<div class="sticky bottom-0 bg-white/80 backdrop-blur-md p-4 -m-8 mt-4 border-t border-gray-200 flex items-center justify-end z-20 shadow-[0_-10px_40px_-15px_rgba(0,0,0,0.1)] rounded-t-2xl gap-4">
    <a href="receptionist_dashboard.php" class="px-6 py-3 rounded-xl font-bold text-slate-500 hover:bg-gray-100 transition-colors">Cancel</a>
    <button id="submit_btn" class="px-8 py-3 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all" type="submit">
        <span class="material-symbols-outlined">person_add</span>
        <span>Register & Check In</span>
    </button>
</div>
</form>
</div>
</div>
</main>
</div>

<script>
// Show/hide appointment fields based on visit type
document.getElementById('visit_type')?.addEventListener('change', function() {
    const visitType = this.value;
    const appointmentFields = document.querySelectorAll('#doctor_id, #service_id, [name="appointment_date"], [name="appointment_time"]').forEach(el => {
        if (el.closest('div')) {
            el.closest('div').style.display = (visitType === 'Walk-In (Unscheduled)') ? 'none' : 'block';
        }
    });
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    const visitType = document.getElementById('visit_type')?.value;
    if (visitType === 'Walk-In (Unscheduled)') {
        document.querySelectorAll('#doctor_id, #service_id, [name="appointment_date"], [name="appointment_time"]').forEach(el => {
            if (el.closest('div')) {
                el.closest('div').style.display = 'none';
            }
        });
    }
    
    // Phone number validation - only allow numbers
    const phoneInput = document.querySelector('input[name="phone_number"]');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            // Remove any non-numeric characters
            this.value = this.value.replace(/[^0-9]/g, '');
        });
        
        phoneInput.addEventListener('blur', function(e) {
            const value = this.value.replace(/[^0-9]/g, '');
            if (value.length < 10) {
                this.setCustomValidity('Phone number must be at least 10 digits');
            } else if (value.length > 15) {
                this.setCustomValidity('Phone number must not exceed 15 digits');
            } else {
                this.setCustomValidity('');
            }
        });
    }
    
    // Check if patient was just registered and show booking popup
    // const urlParams = new URLSearchParams(window.location.search);
    // if (urlParams.get('success') === '1' && urlParams.get('patient_id')) {
    //     const patientId = urlParams.get('patient_id');
    //     setTimeout(() => {
    //        // openBookingPromptModal(patientId); // Removed
    //     }, 500);
    // }
});

// Booking Prompt Modal removed as per user request
// Success modal logic remains if needed, but the booking prompt choice is gone.
window.closeBookingPromptModal = function() {
    // Cleanup if any leftovers, though modal is gone.
    const url = new URL(window.location.search);
    if (url.searchParams.has('success')) {
        url.searchParams.delete('success');
        url.searchParams.delete('patient_id');
        window.history.replaceState({}, '', url);
    }
};
</script>



<!-- Success Modal -->
<div id="successModal" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-green-100 sm:mx-0 sm:h-10 sm:w-10">
                        <span class="material-symbols-outlined text-green-600">check_circle</span>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                        <h3 class="text-lg leading-6 font-medium text-gray-900" id="modal-title">Registration Complete!</h3>
                        <div class="mt-2 text-sm text-gray-500">
                            <p class="mb-2">Patient has been successfully registered.</p>
                            <div class="bg-gray-50 p-3 rounded-lg border border-gray-200 text-left">
                                <p class="font-bold text-gray-700">Account Credentials:</p>
                                <p>Username: <span id="modal-username" class="font-mono text-royal-purple font-bold"></span></p>
                                <p>Password: <span id="modal-password" class="font-mono text-royal-purple font-bold"></span></p>
                            </div>
                            <p class="mt-2 text-xs italic">Please provide these credentials to the patient. They will be prompted to change their password upon first login.</p>
                            <p class="mt-2 text-xs text-gray-400">Default Password Rule: [LastName][BirthYear] (e.g. Cruz1995)</p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" onclick="handleSuccessProceed()" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-royal-purple text-base font-medium text-white hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-royal-purple sm:ml-3 sm:w-auto sm:text-sm">
                    Proceed
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Booking Redirect Modal -->
<div id="bookingModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
     <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
             <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <h3 class="text-lg leading-6 font-medium text-gray-900">Proceed to Booking?</h3>
                <p class="text-sm text-gray-500 mt-2">Would you like to continue to the booking page for this patient?</p>
             </div>
             <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <a id="bookingLink" href="#" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-royal-purple text-base font-medium text-white hover:bg-purple-700 sm:ml-3 sm:w-auto sm:text-sm">
                    Yes, Proceed
                </a>
                <button type="button" onclick="closeBookingModal()" class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    No, Stay Here
                </button>
             </div>
        </div>
     </div>
</div>

<script>
    // Address API
    const psgcBaseUrl = 'https://psgc.gitlab.io/api';

    async function fetchCities() {
        const provinceCode = document.getElementById('province').value;
        const citySelect = document.getElementById('city');
        const barangaySelect = document.getElementById('barangay');
        
        citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
        barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
        citySelect.disabled = true;
        barangaySelect.disabled = true;

        if (!provinceCode) return;

        try {
            const response = await fetch(`${psgcBaseUrl}/provinces/${provinceCode}/cities-municipalities/`);
            const data = await response.json();
            data.sort((a,b) => a.name.localeCompare(b.name));
            citySelect.disabled = false;
            data.forEach(city => {
                const opt = document.createElement('option');
                opt.value = city.code;
                opt.textContent = city.name;
                citySelect.appendChild(opt);
            });
             updateFullAddress();
        } catch (e) { console.error(e); }
    }

    async function fetchBarangays() {
        const cityCode = document.getElementById('city').value;
        const bSelect = document.getElementById('barangay');
        bSelect.innerHTML = '<option value="">Select Barangay</option>';
        bSelect.disabled = true;
        if (!cityCode) return;

        try {
            const response = await fetch(`${psgcBaseUrl}/cities-municipalities/${cityCode}/barangays/`);
            const data = await response.json();
             data.sort((a,b) => a.name.localeCompare(b.name));
            bSelect.disabled = false;
            data.forEach(b => {
                const opt = document.createElement('option');
                opt.value = b.name; 
                opt.textContent = b.name;
                bSelect.appendChild(opt);
            });
             updateFullAddress();
        } catch (e) { console.error(e); }
    }
    
    // Load Provinces
    document.addEventListener('DOMContentLoaded', async () => {
        try {
            const response = await fetch(`${psgcBaseUrl}/provinces/`);
            const data = await response.json();
            data.sort((a,b) => a.name.localeCompare(b.name));
            const pSelect = document.getElementById('province');
            data.forEach(p => {
                const opt = document.createElement('option');
                opt.value = p.code;
                opt.textContent = p.name;
                pSelect.appendChild(opt);
            });
        } catch (e) { console.error(e); }
        
        checkSuccessParams();
    });

    function updateFullAddress() {
        const p = document.getElementById('province');
        const c = document.getElementById('city');
        const b = document.getElementById('barangay');
        const s = document.getElementById('street');
        
        let full = '';
        if (s.value) full += s.value + ', ';
        if (b.value) full += 'Brgy. ' + b.value + ', ';
        if (c.selectedIndex > 0) full += c.options[c.selectedIndex].text + ', ';
        if (p.selectedIndex > 0) full += p.options[p.selectedIndex].text;
        
        document.getElementById('full_address').value = full;
    }

    function calculateAge() {
        const dobInput = document.getElementById('dob_input');
        const ageDisplay = document.getElementById('age_display');
        
        if (!dobInput.value) {
            ageDisplay.value = '';
            return;
        }

        const dob = new Date(dobInput.value);
        const today = new Date();
        
        let age = today.getFullYear() - dob.getFullYear();
        const monthDiff = today.getMonth() - dob.getMonth();
        
        if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
            age--;
        }

        // Handle text display
        if (age < 0) {
            ageDisplay.value = "Invalid Date";
            document.getElementById('submit_btn').disabled = true;
            document.getElementById('submit_btn').classList.add('opacity-50', 'cursor-not-allowed');
        } else if (age < 18) {
            // Minor Restriction
            ageDisplay.value = age + " years old (Minor - Not Allowed)";
            ageDisplay.classList.add('text-red-600');
            document.getElementById('submit_btn').disabled = true;
            document.getElementById('submit_btn').classList.add('opacity-50', 'cursor-not-allowed');
            // Use standard alert if preferred, or just the visual indication
        } else {
            // Allowed
            ageDisplay.value = age + (age === 1 ? " year old" : " years old");
            ageDisplay.classList.remove('text-red-600'); // Reset color if previously red
             // Only re-enable if valid date
            document.getElementById('submit_btn').disabled = false;
            document.getElementById('submit_btn').classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }

    // Modal Variables
    let redirectType = '';
    let redirectPid = '';

    function checkSuccessParams() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('success')) {
            const username = urlParams.get('username');
            const pass = urlParams.get('temp_pass');
             redirectType = urlParams.get('visit_type');
             redirectPid = urlParams.get('patient_id');

            document.getElementById('modal-username').textContent = username;
            document.getElementById('modal-password').textContent = pass;
            
            document.getElementById('successModal').classList.remove('hidden');
        }
    }

    function handleSuccessProceed() {
        document.getElementById('successModal').classList.add('hidden');
        
        if (redirectType === 'Walk-In (Unscheduled)' && redirectPid) {
             document.getElementById('bookingLink').href = `receptionist_create_walkin.php?type=walkin&patient_id=${redirectPid}`;
             document.getElementById('bookingModal').classList.remove('hidden');
        } else if (redirectType === 'Pre-Booked Appointment' && redirectPid) {
             document.getElementById('bookingLink').href = `receptionist_create_booking.php?type=appointment&patient_id=${redirectPid}`;
             document.getElementById('bookingModal').classList.remove('hidden');
        } else {
            // Emergency or just registered
            window.location.href = 'receptionist_registration.php';
        }
    }
    
    function closeBookingModal() {
        document.getElementById('bookingModal').classList.add('hidden');
        window.location.href = 'receptionist_registration.php';
    }

    // --- Guardian & Age Logic ---
    function calculateAge() {
        const dobInput = document.getElementById('dob_input');
        const ageDisplay = document.getElementById('age_display');
        const guardianField = document.getElementById('guardian_field');
        const guardianInput = document.getElementById('guardian_id'); // Hidden input
        
        if (dobInput.value) {
            const dob = new Date(dobInput.value);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            ageDisplay.value = age + " years old";
            
            // Minor Check
            if (age < 18) {
                guardianField.classList.remove('hidden');
                guardianInput.required = true;
            } else {
                guardianField.classList.add('hidden');
                guardianInput.required = false;
                // Clear if hiding
                clearGuardianSelection();
            }
        } else {
            ageDisplay.value = "";
            guardianField.classList.add('hidden');
            clearGuardianSelection();
        }
    }

    // --- Guardian Modal Logic ---
    let currentGuardianPage = 1;
    let totalGuardianPages = 1;

    function openGuardianModal() {
        document.getElementById('guardianModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        searchGuardians(); // Load initial list
    }

    function closeGuardianModal() {
        document.getElementById('guardianModal').classList.add('hidden');
        document.body.style.overflow = '';
    }

    function searchGuardians() {
        const searchInput = document.getElementById('modalGuardianSearch');
        const searchFilter = document.getElementById('modalGuardianFilter');
        const guardianList = document.getElementById('guardianModalList');
        const pagination = document.getElementById('guardianModalPagination');
        
        const searchQuery = searchInput.value.trim();
        const filterType = searchFilter ? searchFilter.value : 'all';
        
        // Reset to page 1
        currentGuardianPage = 1;
        
        // Show loading
        guardianList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block animate-spin">hourglass_empty</span><p class="text-sm font-medium">Loading guardians...</p></div>';
        
        // Force Min Age 18
        const url = `../api/search_patients_modal.php?search=${encodeURIComponent(searchQuery)}&filter_type=${encodeURIComponent(filterType)}&min_age=18&page=${currentGuardianPage}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.patients) {
                    displayGuardianResults(data.patients, data.pagination);
                } else {
                    guardianList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block">person_off</span><p class="text-sm font-medium">No adult guardians found</p></div>';
                    pagination.classList.add('hidden');
                }
            })
            .catch(error => {
                console.error('Error searching guardians:', error);
                guardianList.innerHTML = '<div class="text-center py-12 text-red-400"><span class="material-symbols-outlined text-4xl mb-2 block">error</span><p class="text-sm font-medium">Error loading guardians.</p></div>';
                pagination.classList.add('hidden');
            });
    }

    function loadNextGuardianPage() {
        if (currentGuardianPage < totalGuardianPages) {
            currentGuardianPage++;
            refreshGuardianList();
        }
    }

    function loadPreviousGuardianPage() {
        if (currentGuardianPage > 1) {
            currentGuardianPage--;
            refreshGuardianList();
        }
    }

    function refreshGuardianList() {
        const searchInput = document.getElementById('modalGuardianSearch');
        const searchFilter = document.getElementById('modalGuardianFilter');
        
        const searchQuery = searchInput.value.trim();
        const filterType = searchFilter ? searchFilter.value : 'all';
        const guardianList = document.getElementById('guardianModalList');
        
        // Show loading
        guardianList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block animate-spin">hourglass_empty</span><p class="text-sm font-medium">Loading...</p></div>';
        
        const url = `../api/search_patients_modal.php?search=${encodeURIComponent(searchQuery)}&filter_type=${encodeURIComponent(filterType)}&min_age=18&page=${currentGuardianPage}`;
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.patients) {
                    displayGuardianResults(data.patients, data.pagination);
                }
            });
    }

    function displayGuardianResults(patients, pagination) {
        const guardianList = document.getElementById('guardianModalList');
        const paginationDiv = document.getElementById('guardianModalPagination');
        const pageInfo = document.getElementById('guardianPageInfo');
        const prevBtn = document.getElementById('prevGuardianPageBtn');
        const nextBtn = document.getElementById('nextGuardianPageBtn');
        
        if (patients.length === 0) {
            guardianList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block">person_off</span><p class="text-sm font-medium">No guardians found</p></div>';
            paginationDiv.classList.add('hidden');
            return;
        }
        
        // Update pagination info
        currentGuardianPage = pagination.current_page;
        totalGuardianPages = pagination.total_pages;
        pageInfo.textContent = `Page ${currentGuardianPage} of ${totalGuardianPages}`;
        
        prevBtn.disabled = currentGuardianPage <= 1;
        nextBtn.disabled = currentGuardianPage >= totalGuardianPages;
        
        if (totalGuardianPages > 1) {
            paginationDiv.classList.remove('hidden');
        } else {
            paginationDiv.classList.add('hidden');
        }
        
        guardianList.innerHTML = patients.map(renderGuardianItem).join('');
    }

    function renderGuardianItem(patient) {
        const initials = (patient.first_name.charAt(0) + patient.last_name.charAt(0)).toUpperCase();
        const patientName = `${escapeHtml(patient.first_name)} ${escapeHtml(patient.last_name)}`;
        const patientIdDisplay = 'P-' + String(patient.patient_id).padStart(4, '0');
        const phone = patient.phone_number || 'No Phone';
        const email = patient.email || '';
        const address = patient.address || '';
        
        return `
            <div class="px-4 py-3 hover:bg-purple-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors group" 
                onclick="selectGuardianFromModal(${patient.patient_id}, '${escapeHtml(patient.first_name)}', '${escapeHtml(patient.last_name)}', '${escapeHtml(phone)}', '${escapeHtml(email)}', '${escapeHtml(address)}')">
                <div class="flex items-center gap-4">
                    <div class="w-10 h-10 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-xs flex-shrink-0">${initials}</div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center">
                            <p class="font-bold text-sm text-[#151118] group-hover:text-royal-purple transition-colors truncate">${patientName}</p>
                            <span class="ml-2 text-[10px] px-1.5 py-0.5 bg-gray-100 text-slate-600 rounded font-medium">${patient.age} yo</span>
                        </div>
                        <p class="text-xs text-slate-500 mt-0.5">${patientIdDisplay} • ${escapeHtml(phone)}</p>
                    </div>
                    <button class="px-3 py-1 bg-white border border-gray-200 text-slate-600 text-xs font-bold rounded-lg group-hover:bg-royal-purple group-hover:text-white group-hover:border-royal-purple transition-all">Select</button>
                </div>
            </div>
        `;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function calculateAge() {
        const dobInput = document.getElementById('dob_input');
        const ageDisplay = document.getElementById('age_display');
        const guardianInput = document.getElementById('guardian_id'); 
        const isDependentToggle = document.getElementById('is_dependent_toggle');
        const minorWarning = document.getElementById('minor_warning');
        
        if (dobInput.value) {
            const dob = new Date(dobInput.value);
            const today = new Date();
            let age = today.getFullYear() - dob.getFullYear();
            const m = today.getMonth() - dob.getMonth();
            if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
                age--;
            }
            ageDisplay.value = age + " years old";
            
            // Minor Check
            if (age < 18) {
                // Must be dependent
                if (!isDependentToggle.checked) {
                    isDependentToggle.checked = true;
                    toggleDependentMode();
                }
                isDependentToggle.parentElement.classList.add('opacity-50', 'bg-gray-100', 'p-1', 'rounded'); // visual lock indication
                minorWarning.classList.remove('hidden');
                guardianInput.required = true;
            } else {
                // Adult
                minorWarning.classList.add('hidden');
                isDependentToggle.parentElement.classList.remove('opacity-50', 'bg-gray-100', 'p-1', 'rounded');
                if (isDependentToggle.checked && age >= 18) {
                    guardianInput.required = false; 
                }
            }
        } else {
            ageDisplay.value = "";
        }
    }

    function toggleDependentMode() {
        const isDependent = document.getElementById('is_dependent_toggle').checked;
        const guardianField = document.getElementById('guardian_field');
        const guardianInput = document.getElementById('guardian_id');
        
        if (isDependent) {
            guardianField.classList.remove('hidden');
        } else {
            guardianField.classList.add('hidden');
            // Optional: clear selection logic here if desired
        }
    }

    function selectGuardianFromModal(id, firstName, lastName, phone, email, address) {
        // Update Hidden Inputs
        document.getElementById('guardian_id').value = id;
        document.getElementById('guardian_phone_store').value = phone;
        document.getElementById('guardian_email_store').value = email;
        document.getElementById('guardian_address_store').value = address;

        // Toggle UI
        document.getElementById('guardianSelectContainer').classList.add('hidden');
        document.getElementById('guardianDisplayContainer').classList.remove('hidden');

        // Populate Details
        const initials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();
        const patientIdDisplay = 'P-' + String(id).padStart(4, '0');

        document.getElementById('guardianInitials').textContent = initials;
        document.getElementById('guardianName').textContent = `${firstName} ${lastName}`;
        document.getElementById('guardianInfo').textContent = `${patientIdDisplay} • ${phone}`;

        // Enable "Use Guardian" checkboxes
        enableGuardianCheckboxes(true);
        closeGuardianModal();
    }

    function clearGuardianSelection() {
        document.getElementById('guardian_id').value = '';
        document.getElementById('guardian_phone_store').value = '';
        document.getElementById('guardian_email_store').value = '';
        document.getElementById('guardian_address_store').value = '';
        
        document.getElementById('guardianSelectContainer').classList.remove('hidden');
        document.getElementById('guardianDisplayContainer').classList.add('hidden');

        document.getElementById('guardianInitials').textContent = '--';
        document.getElementById('guardianName').textContent = '--';
        document.getElementById('guardianInfo').textContent = '--';
        
        // Disable and uncheck "Use Guardian" checkboxes
        enableGuardianCheckboxes(false);
    }

    function enableGuardianCheckboxes(enable) {
        const containers = [
            'use_guardian_phone_container', 
            'use_guardian_email_container', 
            'use_guardian_address_container'
        ];
        
        containers.forEach(id => {
            const el = document.getElementById(id);
            if(el) {
                if(enable) {
                    el.classList.remove('opacity-50', 'pointer-events-none');
                } else {
                    el.classList.add('opacity-50', 'pointer-events-none');
                    // Uncheck inputs
                    const cb = el.querySelector('input');
                    if(cb) {
                         cb.checked = false;
                         // Trigger change to reset fields if they were using guardian data
                         cb.dispatchEvent(new Event('change'));
                    }
                }
            }
        });
    }

    function copyGuardianPhone() {
        const isChecked = document.getElementById('use_guardian_phone').checked;
        const gPhone = document.getElementById('guardian_phone_store').value;
        const input = document.querySelector('input[name="phone_number"]');
        
        if (isChecked && gPhone) {
            input.value = gPhone.replace(/[^0-9]/g, '');
        }
    }

    function copyGuardianEmail() {
        const isChecked = document.getElementById('use_guardian_email').checked;
        const gEmail = document.getElementById('guardian_email_store').value;
        const input = document.querySelector('input[name="email"]');
        
        if (isChecked && gEmail) {
            input.value = gEmail;
        }
    }

    function copyGuardianAddress() {
        const isChecked = document.getElementById('use_guardian_address').checked;
        const gAddress = document.getElementById('guardian_address_store').value;
        
        // Address Fields
        const province = document.getElementById('province');
        const city = document.getElementById('city');
        const barangay = document.getElementById('barangay');
        const street = document.getElementById('street');
        const fullAddressInput = document.getElementById('full_address');

        if (isChecked && gAddress) {
            province.disabled = true;
            city.disabled = true;
            barangay.disabled = true;
            street.value = gAddress;
            street.placeholder = "Guardian's Full Address";
            fullAddressInput.value = gAddress;

            province.classList.add('opacity-50', 'bg-gray-100');
            city.classList.add('opacity-50', 'bg-gray-100');
            barangay.classList.add('opacity-50', 'bg-gray-100');
        } else {
            // Restore
            province.disabled = false;
            province.classList.remove('opacity-50', 'bg-gray-100');
            city.disabled = true; 
            city.classList.remove('opacity-50', 'bg-gray-100');
            barangay.disabled = true; 
            barangay.classList.remove('opacity-50', 'bg-gray-100');
            
            street.placeholder = "Street / House No.";
            street.value = ''; 
            updateFullAddress(); 
        }
    }
    
    // Safety override for updateFullAddress to respect lock
    const originalUpdateFullAddress = updateFullAddress;
    updateFullAddress = function() {
        if (document.getElementById('use_guardian_address')?.checked) {
             document.getElementById('full_address').value = document.getElementById('street').value;
        } else {
             // Replicate basic update logic if original not available or safer to inline
             const p = document.getElementById('province');
             const c = document.getElementById('city');
             const b = document.getElementById('barangay');
             const s = document.getElementById('street');
             let full = '';
             if (s && s.value) full += s.value + ', ';
             if (b && b.value) full += 'Brgy. ' + b.value + ', ';
             if (c && c.selectedIndex > 0) full += c.options[c.selectedIndex].text + ', ';
             if (p && p.selectedIndex > 0) full += p.options[p.selectedIndex].text;
             
             const fullAddrInput = document.getElementById('full_address');
             if(fullAddrInput) fullAddrInput.value = full;
        }
    };


</script>

<!-- Guardian Selection Modal -->
<div id="guardianModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h3 class="text-2xl font-bold text-royal-purple">Select Guardian</h3>
                <p class="text-sm text-slate-500 mt-1">Select an adult guardian/parent</p>
            </div>
            <button type="button" onclick="closeGuardianModal()" class="w-10 h-10 flex items-center justify-center rounded-xl hover:bg-gray-100 text-slate-400 hover:text-slate-600 transition-colors">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 flex-1 overflow-hidden flex flex-col gap-4">
            <!-- Search and Filter Bar -->
            <div class="flex flex-col md:flex-row gap-3">
                <div class="flex-1 relative">
                    <span class="absolute left-3 top-3 text-slate-400 material-symbols-outlined text-sm">search</span>
                    <input id="modalGuardianSearch" class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400" placeholder="Search guardians... (Name, Phone, ID)" type="text" autocomplete="off" onkeypress="if(event.key === 'Enter') searchGuardians()"/>
                </div>
                <select id="modalGuardianFilter" class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple">
                    <option value="all">All Fields</option>
                    <option value="name">Name</option>
                    <option value="phone">Phone</option>
                    <option value="email">Email</option>
                    <option value="id">Patient ID</option>
                </select>
                 <div class="flex gap-2">
                    <button type="button" onclick="searchGuardians()" class="px-6 py-2.5 bg-royal-purple hover:bg-purple-700 text-white rounded-xl text-sm font-bold transition-colors shadow-lg shadow-purple-500/20">
                        Search
                    </button>
                </div>
            </div>
            
            <!-- Results List -->
            <div class="flex-1 overflow-y-auto min-h-[300px] border border-gray-100 rounded-xl bg-white" id="guardianModalList">
                <div class="flex flex-col items-center justify-center h-full text-slate-400">
                    <span class="material-symbols-outlined text-4xl mb-2">search</span>
                    <p class="text-sm font-medium">Search for a guardian above</p>
                </div>
            </div>
            
            <!-- Pagination -->
            <div id="guardianModalPagination" class="flex items-center justify-between pt-2 border-t border-gray-100 hidden">
                <p class="text-xs text-slate-500 font-medium" id="guardianPageInfo">Page 1 of 1</p>
                <div class="flex gap-2">
                    <button type="button" id="prevGuardianPageBtn" onclick="loadPreviousGuardianPage()" class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        Previous
                    </button>
                    <button type="button" id="nextGuardianPageBtn" onclick="loadNextGuardianPage()" class="px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-bold text-slate-600 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                        Next
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>


</body>
</html>