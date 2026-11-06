<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require admin role to access this page
requireRole('Admin');

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
function generateTimeDropdown($name, $value = '', $min_time = '00:00', $max_time = '23:45', $classes = '', $disabled = false, $required = false, $data_attr = '') {
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
    
    $html = '<select name="' . htmlspecialchars($name) . '" class="' . htmlspecialchars($classes) . '" ' . $disabled_attr . ' ' . $required_attr . $data_attr_html . '>';
    $html .= '<option value="">Select time...</option>';
    foreach ($options as $option) {
        $selected = $option['selected'] ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($option['value']) . '" ' . $selected . '>' . htmlspecialchars($option['display']) . '</option>';
    }
    $html .= '</select>';
    
    return $html;
}

// Helper function to get default profile photo (Facebook-style initials)
function getDefaultProfilePhoto($full_name, $size = 40) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    
    // Generate a color based on the name (consistent color for same name)
    $colors = [
        ['bg' => '#8a11d4', 'text' => '#ffffff'], // Primary purple
        ['bg' => '#6A1B9A', 'text' => '#ffffff'], // Dark purple
        ['bg' => '#FFD700', 'text' => '#151118'], // Gold
        ['bg' => '#10b981', 'text' => '#ffffff'], // Green
        ['bg' => '#3b82f6', 'text' => '#ffffff'], // Blue
        ['bg' => '#f59e0b', 'text' => '#ffffff'], // Orange
        ['bg' => '#ef4444', 'text' => '#ffffff'], // Red
        ['bg' => '#8b5cf6', 'text' => '#ffffff'], // Violet
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    
    return [
        'initials' => $initials,
        'bg_color' => $color['bg'],
        'text_color' => $color['text']
    ];
}

// Specializations list
$specializations_list = [
    'General Dentistry',
    'Orthodontics',
    'Pediatric Dentistry (Pedodontics)',
    'Periodontics',
    'Prosthodontics',
    'Endodontics',
    'Oral and Maxillofacial Surgery',
    'Oral and Maxillofacial Pathology',
    'Oral and Maxillofacial Radiology',
    'Dental Anesthesiology',
    'Dental Public Health',
    'Oral Medicine',
    'Special Needs Dentistry',
    'Implantology'
];

// Fetch Clinic Schedule from DB for Dynamic Limits & Validation
$clinic_schedule_db = [];
try {
    $s_stmt = $pdo->query("SELECT * FROM tbl_clinic_schedule");
    while ($row = $s_stmt->fetch(PDO::FETCH_ASSOC)) {
        $clinic_schedule_db[strtolower($row['day_of_week'])] = $row;
    }
} catch (Exception $e) {
    error_log("Error fetching clinic schedule: " . $e->getMessage());
}

// Function to get clinic hours for a specific day
function getClinicHours($day, $schedule_db) {
    $day = strtolower($day);
    if (isset($schedule_db[$day]) && $schedule_db[$day]['is_open']) {
        // Format to HH:MM
        $start = date('H:i', strtotime($schedule_db[$day]['start_time']));
        $end = date('H:i', strtotime($schedule_db[$day]['end_time']));
        return ['start' => $start, 'end' => $end, 'active' => true];
    }
    return ['start' => '09:00', 'end' => '17:00', 'active' => false]; // Default fallback or closed
}

$message = '';
$message_type = '';
$csrf_token = generateCSRFToken();

// Valid roles
$valid_roles = ['Admin', 'Dentist', 'Receptionist', 'Dental Assistant'];
$valid_blood_types = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-', 'Unknown'];
$valid_specializations = $specializations_list; // Allow all from list

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_staff'])) {
    // Verify CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submitted_token)) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $message_type = 'error';
    } else {
        // Sanitize and validate inputs
        $full_name = sanitizeInput($_POST['full_name'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $contact_number = sanitizeInput($_POST['contact_number'] ?? '');
        $blood_type = $_POST['blood_type'] ?? 'Unknown';
        $role = $_POST['role'] ?? '';
        $specialization = $_POST['specialization'] ?? null; // Optional, only for Dentists
        $email_verified = $_POST['email_verified'] ?? '0';
        
        // Comprehensive validation
        $errors = [];
        
        // Required fields
        if (empty($full_name)) {
            $errors[] = 'Full name is required.';
        } elseif (strlen($full_name) > 100) {
            $errors[] = 'Full name must be less than 100 characters.';
        }
        
        if ($email_verified !== '1' && $email_verified !== 'true') {
             // In case JS fails, though user UI enforces it visually. 
             // Ideally we trust the Logic but better if we re-check session or if we trust the hidden input?
             // Since we use session verified_email, check that too.
             if (!isset($_SESSION['email_verified']) || !$_SESSION['email_verified'] || $_SESSION['verified_email_address'] !== $email) {
                 $errors[] = 'Please verify the email address first.';
             }
        }

        if (empty($email)) {
            $errors[] = 'Email address is required.';
        } elseif (!validateEmail($email)) {
            $errors[] = 'Invalid email format.';
        } elseif (strlen($email) > 100) {
            $errors[] = 'Email address is too long.';
        }
        
        if (empty($contact_number)) {
            $errors[] = 'Contact number is required.';
        } elseif (!validatePhone($contact_number)) {
            $errors[] = 'Invalid phone number format.';
        }
        
        if (empty($role)) {
            $errors[] = 'Role is required.';
        } elseif (!in_array($role, $valid_roles)) {
            $errors[] = 'Invalid role selected.';
        }

        // Validate specialization if role is Dentist (optional but good to check if provided)
        if ($role === 'Dentist' && !empty($specialization) && !in_array($specialization, $valid_specializations)) {
            $errors[] = 'Invalid specialization selected.';
        }
        
        // If not dentist, force specialization to null
        if ($role !== 'Dentist') {
            $specialization = null;
        }
        
        if (!in_array($blood_type, $valid_blood_types)) {
            $errors[] = 'Invalid blood type selected.';
        }
        
        // Check if at least one schedule day is set and validate times
        $days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $has_schedule = false;
        $min_time = '05:00';
        $max_start_time = '11:55'; // Start time must be AM only
        $max_end_time = '22:00'; // End time can be up to 10:00 PM
        
        foreach ($days as $day) {
            // Check if day is active
            if (!empty($_POST[$day.'_active'])) {
                $has_schedule = true; // At least one day is active
                
                // For Dentists, enforce strict time validation
                if ($role === 'Dentist') {
                    if (empty($_POST[$day.'_start']) || empty($_POST[$day.'_end'])) {
                         $errors[] = ucfirst($day) . ': Start and End times are required for Dentists.';
                    } else {
                        $start_time = $_POST[$day.'_start'];
                        $end_time = $_POST[$day.'_end'];
                        
                        if ($start_time < $min_time || $start_time > $max_start_time) {
                            $errors[] = ucfirst($day) . ' start time must be between 5:00 AM and 11:55 AM.';
                        }
                        if ($start_time >= '12:00') {
                            $errors[] = ucfirst($day) . ' start time must be in AM (before 12:00 PM).';
                        }
                        if ($end_time < $min_time || $end_time > $max_end_time) {
                            $errors[] = ucfirst($day) . ' end time must be between 5:00 AM and 10:00 PM.';
                        }
                        if ($start_time >= $end_time) {
                            $errors[] = ucfirst($day) . ' start time must be before end time.';
                        }
                    }
                }
                // For others, times are optional (we will set defaults if empty)
            }
        }
        
        if (!$has_schedule && in_array($role, ['Dentist', 'Dental Assistant', 'Receptionist'])) {
            $errors[] = 'Please select at least one working day.';
        }
        
        // File validation (PRC, Profile)
        // ... (Keep existing validation logic details for files if possible, or simplify)
        // I'll assume similar logic to previous or just brief it? user didn't ask to change file logic.
        // I will copy previous logic for files to be safe.
        // File validation (PRC, Profile)
        // Enforce PRC License for Dentists
        if ($role === 'Dentist') {
            if (!isset($_FILES['prc_license_file']) || $_FILES['prc_license_file']['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'PRC License file is required for Dentists.';
            } elseif ($_FILES['prc_license_file']['error'] !== UPLOAD_ERR_OK) {
                $errors[] = 'Error uploading PRC License file.';
            }
        } else {
             // For others, check errors only if file is provided
             if (isset($_FILES['prc_license_file']) && $_FILES['prc_license_file']['error'] !== UPLOAD_ERR_NO_FILE) {
                 if ($_FILES['prc_license_file']['error'] !== UPLOAD_ERR_OK) $errors[] = 'PRC License upload error.';
             }
        }
        
        if (!empty($errors)) {
            $message = implode(' ', $errors);
            $message_type = 'error';
        } else {
            try {
                // Check if email already exists
                $check_email_stmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE email = ? LIMIT 1");
                $check_email_stmt->execute([$email]);
                if ($check_email_stmt->fetch()) {
                    $message = 'Email address already exists. Please use a different email.';
                    $message_type = 'error';
                } else {
                    // AUTO-GENERATE CREDENTIALS
                    // Username: First name + random number
                    $base_username = strtolower(explode(' ', trim($full_name))[0]);
                    $base_username = preg_replace('/[^a-z0-9]/', '', $base_username);
                    $username = $base_username . rand(100, 999);
                    
                    // Ensure unique username
                    while($pdo->query("SELECT user_id FROM tbl_user WHERE username = '$username'")->fetch()) {
                        $username = $base_username . rand(100, 999);
                    }

                    // Password: 10 chars (Upper, Lower, Number)
                    // Simple logic:
                    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
                    $generated_password = substr(str_shuffle($chars), 0, 10);
                    
                    // Hash
                    $password_hash = password_hash($generated_password, PASSWORD_DEFAULT);
                    
                    // Handle PRC license file
                    $prc_license_number = null;
                    if (isset($_FILES['prc_license_file']) && $_FILES['prc_license_file']['error'] === UPLOAD_ERR_OK) {
                        $prc_license_number = 'UPLOADED'; 
                    }

                    // Handle profile picture
                    $profile_picture_path = null;
                    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                         $pf = $_FILES['profile_picture'];
                         $ext = pathinfo($pf['name'], PATHINFO_EXTENSION);
                         $unique = bin2hex(random_bytes(8));
                         $target_dir = __DIR__ . '/../images/profiles/';
                         if (!is_dir($target_dir)) mkdir($target_dir, 0755, true);
                         $filename = 'profile_' . time() . '_' . $unique . '.' . $ext;
                         if (move_uploaded_file($pf['tmp_name'], $target_dir . $filename)) {
                             $profile_picture_path = 'images/profiles/' . $filename;
                         }
                    }
                    
                    // Insert with is_password_temporary = 1
                    $stmt = $pdo->prepare("INSERT INTO tbl_user (username, password_hash, full_name, role, specialization, email, contact_number, blood_type, status, prc_license_number, profile_picture, is_password_temporary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Active', ?, ?, 1)");
                    $stmt->execute([$username, $password_hash, $full_name, $role, $specialization, $email, $contact_number, $blood_type, $prc_license_number, $profile_picture_path]);

                    $user_id = $pdo->lastInsertId();

                    // Schedule
                    foreach ($days as $day) {
                        if (!empty($_POST[$day.'_active'])) {
                            // If role is Dentist, use posted times. Else use defaults.
                            if ($role === 'Dentist') {
                                $s_time = $_POST[$day.'_start'];
                                $e_time = $_POST[$day.'_end'];
                            } else {
                                // Default "Open Hours" for non-specific schedules
                                $s_time = '08:00';
                                $e_time = '17:00';
                            }
                            
                            if ($s_time && $e_time) {
                                $insert_sched = $pdo->prepare("INSERT INTO tbl_doctor_schedule (doctor_id, day_of_week, start_time, end_time) VALUES (?, ?, ?, ?)");
                                $insert_sched->execute([$user_id, ucfirst($day), $s_time, $e_time]);
                            }
                        }
                    }

                    // SEND CREDENTIALS EMAIL
                    require_once '../includes/email_config.php';
                    
                    $subject = "Welcome to Schoolness Dental Clinic - Staff Credentials";
                    $email_body = "
                        <h2>Welcome to the team, $full_name!</h2>
                        <p>Your staff account has been created.</p>
                        <p><strong>Username:</strong> $username</p>
                        <p><strong>Password:</strong> $generated_password</p>
                        <p>Please login and change your password immediately.</p>
                        <p><a href='http://vanessaclinic.xo.je/landing.php'>Login Here</a></p>
                    "; 
                    
                    try {
                        if (sendEmail($email, $subject, $email_body)) {
                            $message = 'Staff member registered successfully! Credentials sent to email.';
                            $message_type = 'success';
                            
                            // PRG Pattern: Redirect to self with success flag to prevent white screen/resubmission
                            header("Location: admin_registration_user.php?success=1");
                            exit;
                        } else {
                            throw new Exception("Email sending failed.");
                        }
                        $message = 'Staff member registered successfully! Credentials sent to email.';
                        $message_type = 'success';
                        
                        // PRG Pattern: Redirect to self with success flag to prevent white screen/resubmission
                        header("Location: admin_registration_user.php?success=1");
                        exit;
                        
                    } catch (Exception $e) {
                         // If email fails, we still registered the user, so technically success but with warning.
                         // We can redirect with a warning code or stay on page. 
                         // Staying on page is safer to show the error.
                        $message = 'Staff registered, but failed to send email. Creds: ' . $username . ' / ' . $generated_password;
                        $message_type = 'warning';
                    }

                    $_POST = array();
                    $csrf_token = generateCSRFToken();
                    unset($_SESSION['email_verified']);
                }
            } catch (Exception $e) {
                error_log('Registration error: ' . $e->getMessage());
                $message = 'Registration failed. ' . $e->getMessage();
                $message_type = 'error';
            }
        }
    }
    $csrf_token = generateCSRFToken();
}

// Check for Success GET parameter
if (isset($_GET['success']) && $_GET['success'] == 1) {
    $message = 'Staff member registered successfully! Credentials have been sent to their email.';
    $message_type = 'success';
}
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Admin Staff Registration - Vanessa Nicolas Dental Clinic</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
    tailwind.config = {
        darkMode: "class",
        theme: {
            extend: {
                colors: {
                    "primary": "#8a11d4", // Royal Purple-ish
                    "accent": "#FFD700", // Gold
                    "background-light": "#f7f6f8",
                    "background-dark": "#1b1022",
                    "surface-light": "#ffffff",
                    "surface-dark": "#2d1b36",
                    "text-main": "#151118",
                    "text-sub": "#7a6189",
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
::-webkit-scrollbar { 
    width: 8px; 
    height: 8px; 
} 
::-webkit-scrollbar-track { 
    background: transparent; 
} 
::-webkit-scrollbar-thumb { 
    background: #d1c4d9; 
    border-radius: 4px; 
} 
::-webkit-scrollbar-thumb:hover { 
    background: #8a11d4; 
}

/* Default select arrows enabled */

/* Focus improvements */
input:focus, select:focus, textarea:focus {
    outline: none;
}

/* Schedule Cards with Tailwind */
.schedule-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}

.schedule-card.active {
    border-color: #8a11d4;
    background-color: rgba(138, 17, 212, 0.05);
}
</style>
</head>
<body class="bg-background-light dark:bg-background-dark text-text-main dark:text-white font-display overflow-hidden">
<div class="flex h-screen w-full">
<aside class="w-72 flex-shrink-0 flex flex-col bg-surface-light dark:bg-surface-dark border-r border-[#f3f0f4] dark:border-gray-800 transition-colors duration-200">
<div class="flex flex-col h-full p-4">
<a href="admin_current_user.php" class="flex gap-3 mb-8 items-center p-2 cursor-pointer hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 rounded-xl p-2 transition-colors">
<?php
$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Admin User';
$current_role = $current_user['role'] ?? 'Admin';
$current_profile = $current_user['profile_picture'] ?? null;

if ($current_profile && file_exists('../' . $current_profile)) {
    echo '<div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-12 border-2 border-primary cursor-pointer" style="background-image: url(\'../' . htmlspecialchars($current_profile) . '\');"></div>';
} else {
    $default_photo = getDefaultProfilePhoto($current_name);
    echo '<div class="rounded-full size-12 border-2 border-primary flex items-center justify-center text-white font-bold cursor-pointer" style="background-color: ' . $default_photo['bg_color'] . '; color: ' . $default_photo['text_color'] . ';">' . htmlspecialchars($default_photo['initials']) . '</div>';
}
?>
<div class="flex flex-col">
<h1 class="text-text-main dark:text-white text-base font-bold leading-normal"><?php echo htmlspecialchars($current_name); ?></h1>
<p class="text-primary text-xs font-semibold uppercase tracking-wider"><?php echo htmlspecialchars($current_role); ?></p>
</div>
</a>
<nav class="flex flex-col gap-2 flex-1">
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_dashboard.php">
<span class="material-symbols-outlined">dashboard</span>
<p class="text-sm font-medium leading-normal">Dashboard</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_user.php">
<span class="material-symbols-outlined">group</span>
<p class="text-sm font-medium leading-normal">Users</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_schedule.php">
<span class="material-symbols-outlined">schedule</span>
<p class="text-sm font-medium leading-normal">Schedules</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_service.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">medical_services</span>
<p class="text-sm font-medium leading-normal">Services</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_reports.php">
<span class="material-symbols-outlined">description</span>
<p class="text-sm font-medium leading-normal">Reports</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_settings.php">
<span class="material-symbols-outlined">settings</span>
<p class="text-sm font-medium leading-normal">Settings</p>
</a>
</nav>
<div class="mt-auto pt-6 border-t border-gray-100 dark:border-gray-800 flex flex-col gap-4">
<a href="../logout.php" class="flex w-full cursor-pointer items-center justify-start gap-3 px-4 py-2 rounded-xl text-text-sub hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
<span class="material-symbols-outlined text-[20px]">logout</span>
<span class="text-sm font-medium">Logout</span>
</a>
</div>
</div>
</aside>
<main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light dark:bg-background-dark relative">
<header class="h-20 flex items-center justify-between px-8 bg-surface-light dark:bg-surface-dark border-b border-[#f3f0f4] dark:border-gray-800 flex-shrink-0 z-20">
<div class="flex items-center gap-4">
<h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Staff Registration</h2>
</div>
<div class="flex items-center gap-6">
<div class="flex gap-3">
<button class="relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
<span class="material-symbols-outlined text-[22px]">notifications</span>
<span class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700"></span>
</button>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8 scroll-smooth">
<div class="max-w-[1000px] mx-auto flex flex-col gap-8 pb-10">
<?php if ($message): ?>
<div class="bg-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-50 border border-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-200 rounded-xl p-4 flex items-center gap-3">
<span class="material-symbols-outlined text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-600"><?php echo $message_type === 'success' ? 'check_circle' : 'error'; ?></span>
<span class="text-sm font-medium text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-800"><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-8 shadow-sm border border-gray-100 dark:border-gray-800">
<div class="flex items-center justify-between mb-8 border-b border-gray-100 dark:border-gray-800 pb-6">
<div>
<h2 class="text-xl font-bold text-text-main dark:text-white">New Staff Credentials</h2>
<p class="text-sm text-text-sub mt-1">Enter the official details for the new clinic staff member.</p>
</div>
<div class="hidden sm:block">
<div class="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center text-primary">
<span class="material-symbols-outlined">person_add</span>
</div>
</div>
</div>
<form class="max-w-7xl mx-auto" method="POST" action="admin_registration_user.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
    
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
        <!-- LEFT COLUMN: Identity Panel (Static) -->
        <div class="lg:col-span-4 space-y-6">
            <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
                <h4 class="text-sm font-bold text-primary uppercase tracking-wider mb-6 flex items-center gap-2 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <span class="material-symbols-outlined text-lg">badge</span> Identity
                </h4>

                <!-- Profile Photo -->
                <div class="flex flex-col items-center gap-4 mb-8">
                    <div class="relative group">
                        <div id="profilePreview" class="w-32 h-32 rounded-full border-4 border-white dark:border-gray-800 shadow-lg bg-gray-100 dark:bg-gray-800 flex items-center justify-center overflow-hidden transition-all group-hover:border-primary/20">
                            <span class="material-symbols-outlined text-5xl text-text-sub">person</span>
                        </div>
                        <label for="profile_picture" class="absolute bottom-0 right-0 p-2 bg-primary hover:bg-primary/90 text-white rounded-full cursor-pointer shadow-lg transition-transform hover:scale-110">
                            <span class="material-symbols-outlined text-[20px]">photo_camera</span>
                        </label>
                    </div>
                    <div class="text-center">
                        <p class="text-xs text-text-sub">JPG/PNG, Max 2MB</p>
                        <span id="profileError" class="text-xs text-red-500 hidden block mt-1"></span>
                    </div>
                    <input id="profile_picture" name="profile_picture" accept=".jpg,.jpeg,.png" type="file" class="hidden" />
                    <button id="previewButton" type="button" class="text-xs text-primary font-bold hover:underline disabled:opacity-50 disabled:no-underline" disabled>Preview Full Size</button>
                </div>

                <!-- Full Name -->
                <div class="flex flex-col gap-1.5 mb-6">
                    <label class="text-xs font-bold text-text-sub uppercase">Full Name <span class="text-red-500">*</span></label>
                    <input class="h-12 w-full bg-background-light dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl px-4 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all placeholder:text-gray-400 font-bold" placeholder="e.g. Dr. Sarah Jenkins" type="text" name="full_name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" maxlength="100" required/>
                </div>

                <!-- Role Selection -->
                <div class="flex flex-col gap-1.5 mb-6">
                    <label class="text-xs font-bold text-text-sub uppercase">Cilinic Role <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <select class="h-12 w-full bg-background-light dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl pl-4 pr-10 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all cursor-pointer appearance-none" name="role" required>
                            <option disabled="" <?php echo (!isset($_POST['role']) || $_POST['role'] === '') ? 'selected' : ''; ?> value="">Select a role...</option>
                            <option value="Dentist" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Dentist') ? 'selected' : ''; ?>>Dentist</option>
                            <option value="Dental Assistant" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Dental Assistant') ? 'selected' : ''; ?>>Dental Assistant</option>
                            <option value="Receptionist" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Receptionist') ? 'selected' : ''; ?>>Receptionist</option>
                            <option value="Admin" <?php echo (isset($_POST['role']) && $_POST['role'] === 'Admin') ? 'selected' : ''; ?>>Manager</option>
                        </select>
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-text-sub pointer-events-none">
                            <span class="material-symbols-outlined">expand_more</span>
                        </span>
                    </div>
                </div>

                <!-- Specialization Selection (Hidden by default, shown for Dentist) -->
                <div id="specializationContainer" class="flex flex-col gap-1.5 mb-6 hidden">
                    <label class="text-xs font-bold text-text-sub uppercase">Specialization <span class="text-xs font-normal normal-case">(For Dentists)</span></label>
                    <div class="relative">
                        <select class="h-12 w-full bg-background-light dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl pl-4 pr-10 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all cursor-pointer appearance-none" name="specialization">
                            <option value="">Select Specialization...</option>
                            <?php foreach ($specializations_list as $spec): ?>
                            <option value="<?php echo htmlspecialchars($spec); ?>" <?php echo (isset($_POST['specialization']) && $_POST['specialization'] === $spec) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($spec); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="absolute right-4 top-1/2 -translate-y-1/2 text-text-sub pointer-events-none">
                            <span class="material-symbols-outlined">expand_more</span>
                        </span>
                    </div>
                </div>

                <!-- PRC License -->
                <div class="flex flex-col gap-1.5">
                    <label class="text-xs font-bold text-text-sub uppercase">PRC License (Optional)</label>
                    <div class="relative group">
                        <input class="w-full text-sm text-text-sub file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer border border-dashed border-gray-300 dark:border-gray-700 rounded-xl p-2" type="file" name="prc_license_file" accept=".pdf,.jpg,.jpeg,.png" />
                    </div>
                </div>
            </div>

            <!-- Clinic Hours Reference Panel -->
            <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
                <h4 class="text-sm font-bold text-primary uppercase tracking-wider mb-4 flex items-center gap-2 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <span class="material-symbols-outlined text-lg">access_time</span> Clinic Hours
                </h4>
                <div class="flex flex-col gap-3">
                    <?php 
                    $days_ref = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                    foreach ($days_ref as $day_ref): 
                        $hours = getClinicHours(strtolower($day_ref), $clinic_schedule_db);
                        $isOpen = $hours['active'];
                    ?>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-text-sub font-medium"><?php echo $day_ref; ?></span>
                        <?php if ($isOpen): ?>
                            <span class="text-text-main dark:text-white font-bold text-xs bg-green-50 text-green-700 px-2 py-1 rounded">
                                <?php echo date('g:i A', strtotime($hours['start'])) . ' - ' . date('g:i A', strtotime($hours['end'])); ?>
                            </span>
                        <?php else: ?>
                            <span class="text-xs font-bold text-gray-400 bg-gray-100 px-2 py-1 rounded">Closed</span>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- RIGHT COLUMN: Details & Schedule -->
        <div class="lg:col-span-8 flex flex-col gap-6">
            
            <!-- Contact Info Card -->
            <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
                <h4 class="text-sm font-bold text-primary uppercase tracking-wider mb-6 flex items-center gap-2 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <span class="material-symbols-outlined text-lg">contact_mail</span> Contact Information
                </h4>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Email (Spans 2 on mobile, 1 on desktop) -->
                    <div class="col-span-1 md:col-span-2 flex flex-col gap-1.5">
                        <label class="text-xs font-bold text-text-sub uppercase">Email Address <span class="text-red-500">*</span></label>
                        <div class="flex gap-3">
                            <div class="relative flex-1">
                                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-sub">
                                    <span class="material-symbols-outlined text-[20px]">mail</span>
                                </span>
                                <input id="emailInput" class="h-12 w-full bg-background-light dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl pl-11 pr-4 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all placeholder:text-gray-400" placeholder="e.g. sarah.jenkins@clinic.com" type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" maxlength="100" required/>
                            </div>
                            <button type="button" id="verifyEmailBtn" class="px-6 py-2 bg-accent hover:bg-yellow-400 text-black font-bold rounded-xl transition-all shadow-sm">
                                Verify
                            </button>
                        </div>
                        <div class="flex items-center gap-2 mt-1">
                            <input type="hidden" name="email_verified" id="emailVerified" value="0">
                            <p id="verificationStatus" class="text-xs font-bold hidden"></p>
                        </div>
                    </div>

                    <!-- Contact Number -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-xs font-bold text-text-sub uppercase">Contact Number <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-sub">
                                <span class="material-symbols-outlined text-[20px]">call</span>
                            </span>
                            <input class="h-12 w-full bg-background-light dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl pl-11 pr-4 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all placeholder:text-gray-400" placeholder="e.g. 0912 345 6789" type="tel" name="contact_number" value="<?php echo htmlspecialchars($_POST['contact_number'] ?? ''); ?>" maxlength="15" required/>
                        </div>
                    </div>

                    <!-- Blood Type -->
                    <div class="flex flex-col gap-1.5">
                        <label class="text-xs font-bold text-text-sub uppercase">Blood Type</label>
                        <div class="relative">
                             <span class="absolute left-4 top-1/2 -translate-y-1/2 text-text-sub">
                                <span class="material-symbols-outlined text-[20px]">bloodtype</span>
                            </span>
                            <select class="h-12 w-full bg-background-light dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl pl-11 pr-10 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all cursor-pointer appearance-none" name="blood_type">
                                <option disabled="" <?php echo (!isset($_POST['blood_type']) || $_POST['blood_type'] === '') ? 'selected' : ''; ?> value="">Select type...</option>
                                <option value="A+" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'A+') ? 'selected' : ''; ?>>A+</option>
                                <option value="A-" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'A-') ? 'selected' : ''; ?>>A-</option>
                                <option value="B+" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'B+') ? 'selected' : ''; ?>>B+</option>
                                <option value="B-" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'B-') ? 'selected' : ''; ?>>B-</option>
                                <option value="AB+" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'AB+') ? 'selected' : ''; ?>>AB+</option>
                                <option value="AB-" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'AB-') ? 'selected' : ''; ?>>AB-</option>
                                <option value="O+" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'O+') ? 'selected' : ''; ?>>O+</option>
                                <option value="O-" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'O-') ? 'selected' : ''; ?>>O-</option>
                                <option value="Unknown" <?php echo (isset($_POST['blood_type']) && $_POST['blood_type'] === 'Unknown') ? 'selected' : ''; ?>>Unknown</option>
                            </select>
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-text-sub pointer-events-none">
                                <span class="material-symbols-outlined">expand_more</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Schedule Card -->
            <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-700">
                <div class="flex items-center justify-between mb-6 border-b border-gray-100 dark:border-gray-700 pb-2">
                    <h4 class="text-sm font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                        <span class="material-symbols-outlined text-lg">calendar_month</span> Shift Schedule
                    </h4>
                    <span class="text-xs text-text-sub bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">6:00 AM - 10:00 PM</span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 gap-4 schedule-container">
                    <?php 
                    $days = [
                        'monday' => 'Monday',
                        'tuesday' => 'Tuesday',
                        'wednesday' => 'Wednesday',
                        'thursday' => 'Thursday',
                        'friday' => 'Friday',
                        'saturday' => 'Saturday',
                        'sunday' => 'Sunday',
                    ];
                    foreach ($days as $key => $label): 
                        // Get Clinic Hours for this day
                        $clinic_hours = getClinicHours($key, $clinic_schedule_db);
                        $isClinicOpen = $clinic_hours['active'];
                        
                        // If clinic is closed, force inactive or show closed state?
                        // We will allow selecting it BUT restrict times. 
                        // Actually, if clinic is closed, staff shouldn't be working generally.
                        // Let's mark it clearly.
                        
                        $isActive = !empty($_POST[$key.'_active']);
                        
                        // Dynamic Min/Max based on Clinic Hours
                        $min_time_display = $clinic_hours['start'];
                        $max_time_display = $clinic_hours['end'];
                        
                        // Adjust end time for start dropdown (can't start at closing time exactly usually, but allowed)
                        // Adjust start time for end dropdown
                        
                        $disable_card = !$isClinicOpen;
                        $card_opacity = $disable_card ? 'opacity-60 grayscale' : '';
                        $status_text = $disable_card ? '(Clinic Closed)' : '';
                    ?>
                    <div class="schedule-card bg-background-light dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl p-4 transition-all hover:shadow-md <?php echo $isActive ? 'active border-primary ring-1 ring-primary/20' : ''; ?> <?php echo $card_opacity; ?>" data-day="<?php echo $key; ?>">
                        <div class="flex items-center justify-between mb-3">
                            <span class="font-bold text-text-main dark:text-white"><?php echo $label; ?> <span class="text-xs text-red-500"><?php echo $status_text; ?></span></span>
                            <?php if ($isClinicOpen): ?>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="<?php echo $key; ?>_active" class="sr-only peer schedule-active" data-day="<?php echo $key; ?>" <?php echo $isActive ? 'checked' : ''; ?>>
                                <div class="w-9 h-5 bg-gray-200 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-primary/20 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-primary"></div>
                            </label>
                            <?php else: ?>
                                <span class="text-xs font-bold text-gray-400">Closed</span>
                            <?php endif; ?>
                        </div>
                        <?php if ($isClinicOpen): ?>
                        <div class="flex flex-col gap-3">
                             <!-- Start Time -->
                             <div class="flex flex-col gap-1">
                                <label class="text-[10px] uppercase font-bold text-text-sub">Start</label>
                                <?php echo generateTimeDropdown($key.'_start', $_POST[$key.'_start'] ?? '', $min_time_display, $max_time_display, 'w-full text-xs font-semibold px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 schedule-start', !$isActive, false, 'data-day="' . $key . '"'); ?>
                             </div>
                             <!-- End Time -->
                             <div class="flex flex-col gap-1">
                                <label class="text-[10px] uppercase font-bold text-text-sub">End</label>
                                <?php echo generateTimeDropdown($key.'_end', $_POST[$key.'_end'] ?? '', $min_time_display, $max_time_display, 'w-full text-xs font-semibold px-2 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 schedule-end', !$isActive, false, 'data-day="' . $key . '"'); ?>
                             </div>
                        </div>
                        
                        <!-- Mini Actions (Always Visible if Active) -->
                        <div class="quick-actions flex justify-end gap-2 mt-3 pt-3 border-t border-gray-200 dark:border-gray-700 transition-all duration-300 overflow-hidden <?php echo $isActive ? 'max-h-20 opacity-100' : 'max-h-0 opacity-0 pointer-events-none'; ?>">
                            <button type="button" class="copy-day-btn text-xs font-bold px-2 py-1 rounded bg-primary/10 text-primary hover:bg-primary/20 transition-all" data-day="<?php echo $key; ?>">
                                <span class="material-symbols-outlined text-[14px] align-text-bottom">content_copy</span> Copy
                            </button>
                            <button type="button" class="apply-all-btn text-xs font-bold px-2 py-1 rounded bg-accent/10 text-accent-dark hover:bg-accent/20 transition-all" data-day="<?php echo $key; ?>">
                                <span class="material-symbols-outlined text-[14px] align-text-bottom">done_all</span> All
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex gap-3 justify-end mt-6">
                    <button type="button" id="previewScheduleBtn" class="px-4 py-2 text-sm font-bold text-text-sub hover:text-text-main bg-gray-100 hover:bg-gray-200 rounded-lg transition-all">
                        Preview
                    </button>
                    <button type="button" id="confirmScheduleBtn" class="px-4 py-2 text-sm font-bold text-primary bg-primary/10 hover:bg-primary/20 rounded-lg transition-all">
                        Validate Schedule
                    </button>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex items-center justify-end gap-4 pt-4">
                <a href="admin_user.php" class="px-6 py-3 rounded-xl text-sm font-bold text-text-sub hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors">
                    Cancel
                </a>
                <button class="px-8 py-3 rounded-xl bg-primary hover:bg-primary/90 text-white text-sm font-bold shadow-lg shadow-primary/30 transition-all flex items-center gap-2 transform hover:-translate-y-1" type="submit" name="register_staff">
                    <span class="material-symbols-outlined text-[20px]">check_circle</span>
                    Register Staff Member
                </button>
            </div>
        </div>
    </div>
</form>
</div>
</div>
</div>
</main>
</div>

<!-- Modal for previewing the chosen profile picture -->
<div id="profileModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60">
    <div class="bg-surface-light dark:bg-surface-dark rounded-xl p-4 max-w-[90%] max-h-[90%] overflow-auto">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-text-main dark:text-white">Profile Photo Preview</h3>
            <button id="closePreview" class="text-sm text-text-sub hover:text-text-main">Close</button>
        </div>
        <div class="flex items-center justify-center">
            <img id="modalImage" src="" alt="Preview" class="max-h-[70vh] w-auto rounded-lg object-contain" />
        </div>
    </div>
</div>

<!-- Modal for previewing the schedule summary -->
<div id="schedulePreviewModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/60">
    <div class="bg-surface-light dark:bg-surface-dark rounded-xl p-6 max-w-[90%] max-h-[90%] overflow-auto">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-bold text-text-main dark:text-white">Schedule Preview</h3>
            <button id="closeSchedulePreview" class="text-sm text-text-sub hover:text-text-main">Close</button>
        </div>
        <div id="scheduleSummaryContent" class="text-text-main dark:text-white"></div>
    </div>
</div>

<!-- Custom Popup Modal -->
<div id="customPopup" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 max-w-md w-[90%] shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="popupContent">
        <div class="flex items-center gap-4 mb-4">
            <div id="popupIcon" class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-2xl"></span>
            </div>
            <h3 id="popupTitle" class="text-lg font-bold text-text-main dark:text-white flex-1"></h3>
        </div>
        <p id="popupMessage" class="text-text-sub dark:text-gray-300 mb-6 whitespace-pre-line"></p>
        <div id="popupInputContainer" class="mb-6 hidden">
            <input type="text" id="popupInput" class="w-full px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-background-light dark:bg-gray-800 text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary" placeholder="Enter value...">
        </div>
        <div id="popupButtons" class="flex gap-3 justify-end"></div>
    </div>
</div>

<!-- Copy Day Selection Modal -->
<div id="copyDayModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 max-w-md w-[90%] shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="copyDayModalContent">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-3">
                <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 bg-primary/10 text-primary">
                    <span class="material-symbols-outlined text-2xl">content_copy</span>
                </div>
                <h3 class="text-lg font-bold text-text-main dark:text-white">Copy Schedule</h3>
            </div>
            <button id="closeCopyDayModal" class="text-text-sub hover:text-text-main transition-colors">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <p id="copyDaySource" class="text-text-sub dark:text-gray-300 mb-4"></p>
        <p class="text-sm font-semibold text-text-main dark:text-white mb-3">Select target day:</p>
        <div id="copyDayRadioContainer" class="space-y-2 mb-6 max-h-64 overflow-y-auto">
            <!-- Radio buttons will be inserted here -->
        </div>
        <div class="flex gap-3 justify-end">
            <button id="copyDayCancel" class="px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-800 text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700 font-bold text-sm transition-all">
                Cancel
            </button>
            <button id="copyDayConfirm" class="px-4 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm transition-all">
                Copy
            </button>
        </div>
    </div>
</div>

<!-- Client-side preview script for profile picture and schedule preview/confirm logic -->
<script>
// Custom Popup System
(function() {
    // Role Change Listener to toggle Specialization field
    const roleSelect = document.querySelector('select[name="role"]');
    const specializationContainer = document.getElementById('specializationContainer');
    
    const prcLabel = document.querySelector('label[for="prc_license_file"]'); 
    // Note: The previous code didn't have an ID/for on the label, I need to fetch by text content or structure.
    // The label is "PRC License (Optional)". I will find it via structure.
    const prcContainer = document.querySelector('input[name="prc_license_file"]').closest('.flex-col').querySelector('label');
    
    function toggleSpecialization() {
        const isDentist = roleSelect.value === 'Dentist';
        const role = roleSelect.value;
        const validRolesForSchedule = ['Dentist', 'Dental Assistant', 'Receptionist', 'Admin']; // Assuming all staff have working days
        
        // 1. Specialization Dropdown
        if (isDentist) {
            specializationContainer.classList.remove('hidden');
        } else {
            specializationContainer.classList.add('hidden');
            const specSelect = specializationContainer.querySelector('select');
            if (specSelect) specSelect.value = '';
        }
        
        // 2. PRC License Requirement
        if (prcContainer) {
            if (isDentist) {
                prcContainer.innerHTML = 'PRC License <span class="text-red-500">*</span>';
                // Note: File input 'required' attribute is tricky for UX, handled mainly by PHP, but we can add it.
                // document.querySelector('input[name="prc_license_file"]').required = true; 
            } else {
                prcContainer.innerHTML = 'PRC License (Optional)';
                // document.querySelector('input[name="prc_license_file"]').required = false;
            }
        }
        
        // 3. Schedule View (Times hidden if not Dentist)
        const timeSelectors = document.querySelectorAll('.schedule-start, .schedule-end');
        const timeLabels = document.querySelectorAll('.schedule-card label.text-text-sub'); // Start/End labels
        const quickActions = document.querySelectorAll('.quick-actions');
        
        document.querySelectorAll('.schedule-card').forEach(card => {
            const timeInputsDiv = card.querySelector('.flex.flex-col.gap-3'); // Container of times
             if (isDentist) {
                  if (timeInputsDiv) timeInputsDiv.classList.remove('hidden');
                  // Quick actions handled by checkbox state, but we ensure they are allowed
             } else {
                  if (timeInputsDiv) timeInputsDiv.classList.add('hidden');
                  // Hide quick actions as they rely on times
                  // Inline style removed, relying on CSS (body.role-other)
             }
        });
        
        // Fix for "Quick Actions" specifically:
        // The previous script toggles 'quick-actions' visibility based on checkbox.
        // We override this: if not dentist, force hide even if checked.
        
        // Actually, cleaner way:
        document.body.classList.toggle('role-dentist', isDentist);
        document.body.classList.toggle('role-other', !isDentist);
    }
    
    // CSS to help with toggling deeply nested elements easily
    const style = document.createElement('style');
    style.innerHTML = `
        body.role-other .schedule-card .flex.flex-col.gap-3 { display: none !important; }
        body.role-other .schedule-card .quick-actions { display: none !important; }
        body.role-other .schedule-card { min-height: 80px; display: flex; flex-direction: column; justify-content: center; }
    `;
    document.head.appendChild(style);
    
    if (roleSelect) {
        roleSelect.addEventListener('change', toggleSpecialization);
        // Run on load in case of postback
        toggleSpecialization();
    }
    
    const popup = document.getElementById('customPopup');
    const popupContent = document.getElementById('popupContent');
    const popupIcon = document.getElementById('popupIcon');
    const popupTitle = document.getElementById('popupTitle');
    const popupMessage = document.getElementById('popupMessage');
    const popupInputContainer = document.getElementById('popupInputContainer');
    const popupInput = document.getElementById('popupInput');
    const popupButtons = document.getElementById('popupButtons');
    
    let currentResolve = null;
    
    function showPopup(type, title, message, showInput = false) {
        return new Promise((resolve) => {
            currentResolve = resolve;
            
            // Set icon and colors based on type
            const iconMap = {
                'alert': { icon: 'info', color: 'bg-blue-100 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400' },
                'confirm': { icon: 'help', color: 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900/20 dark:text-yellow-400' },
                'prompt': { icon: 'edit', color: 'bg-primary/10 text-primary' },
                'success': { icon: 'check_circle', color: 'bg-green-100 text-green-600 dark:bg-green-900/20 dark:text-green-400' },
                'error': { icon: 'error', color: 'bg-red-100 text-red-600 dark:bg-red-900/20 dark:text-red-400' }
            };
            
            const config = iconMap[type] || iconMap['alert'];
            popupIcon.className = `w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ${config.color}`;
            popupIcon.querySelector('span').textContent = config.icon;
            
            popupTitle.textContent = title;
            popupMessage.textContent = message;
            
            if (showInput) {
                popupInputContainer.classList.remove('hidden');
                popupInput.value = '';
                setTimeout(() => popupInput.focus(), 100);
            } else {
                popupInputContainer.classList.add('hidden');
            }
            
            // Create buttons
            popupButtons.innerHTML = '';
            if (type === 'confirm') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-800 text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700 font-bold text-sm transition-all';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.onclick = () => closePopup(false);
                
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm transition-all';
                okBtn.textContent = 'OK';
                okBtn.onclick = () => closePopup(true);
                
                popupButtons.appendChild(cancelBtn);
                popupButtons.appendChild(okBtn);
            } else if (type === 'prompt') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-800 text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700 font-bold text-sm transition-all';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.onclick = () => closePopup(null);
                
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm transition-all';
                okBtn.textContent = 'OK';
                okBtn.onclick = () => closePopup(popupInput.value);
                
                popupButtons.appendChild(cancelBtn);
                popupButtons.appendChild(okBtn);
                
                popupInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        okBtn.click();
                    }
                });
            } else {
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm transition-all';
                okBtn.textContent = 'OK';
                okBtn.onclick = () => closePopup(true);
                popupButtons.appendChild(okBtn);
            }
            
            // Show popup with animation
            popup.classList.remove('hidden');
            setTimeout(() => {
                popupContent.classList.remove('scale-95', 'opacity-0');
                popupContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        });
    }
    
    function closePopup(value) {
        popupContent.classList.remove('scale-100', 'opacity-100');
        popupContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            popup.classList.add('hidden');
            if (currentResolve) {
                currentResolve(value);
                currentResolve = null;
            }
        }, 300);
    }
    
    // Close on backdrop click
    popup.addEventListener('click', function(e) {
        if (e.target === popup) {
            closePopup(null);
        }
    });
    
    // Export functions
    window.showAlert = (message, title = 'Alert') => showPopup('alert', title, message);
    window.showConfirm = (message, title = 'Confirm') => showPopup('confirm', title, message);
    window.showPrompt = (message, title = 'Prompt') => showPopup('prompt', title, message, true);
    window.showSuccess = (message, title = 'Success') => showPopup('success', title, message);
    window.showError = (message, title = 'Error') => showPopup('error', title, message);
})();
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Profile photo preview logic
    const input = document.getElementById('profile_picture');
    const errorEl = document.getElementById('profileError');
    const previewBtn = document.getElementById('previewButton');
    const modal = document.getElementById('profileModal');
    const modalImage = document.getElementById('modalImage');
    const closeBtn = document.getElementById('closePreview');
    const maxSize = 2 * 1024 * 1024; // 2MB
    const allowed = ['image/jpeg', 'image/jpg', 'image/png'];
    let previewURL = null;

    const profilePreview = document.getElementById('profilePreview');
    
    if (input && previewBtn) {
        input.addEventListener('change', function () {
            errorEl.classList.add('hidden');
            const file = input.files && input.files[0];
            previewURL = null;
            previewBtn.disabled = true;

            if (!file) {
                // Reset preview if no file
                if (profilePreview) {
                    profilePreview.innerHTML = '<span class="material-symbols-outlined text-4xl text-text-sub">person</span>';
                    profilePreview.style.backgroundImage = '';
                    profilePreview.style.backgroundColor = '';
                }
                return;
            }

            if (!allowed.includes(file.type)) {
                errorEl.textContent = 'Invalid file type. Only JPG and PNG are allowed.';
                errorEl.classList.remove('hidden');
                input.value = '';
                return;
            }

            if (file.size > maxSize) {
                errorEl.textContent = 'File size exceeds 2MB limit.';
                errorEl.classList.remove('hidden');
                input.value = '';
                return;
            }

            previewURL = URL.createObjectURL(file);
            previewBtn.disabled = false;
            
            // Update circular preview
            if (profilePreview) {
                profilePreview.innerHTML = '';
                profilePreview.style.backgroundImage = `url(${previewURL})`;
                profilePreview.style.backgroundSize = 'cover';
                profilePreview.style.backgroundPosition = 'center';
                profilePreview.style.backgroundColor = 'transparent';
            }
        });
        
        // Reset preview when file input is cleared
        input.addEventListener('input', function() {
            if (!input.files || input.files.length === 0) {
                if (profilePreview) {
                    profilePreview.innerHTML = '<span class="material-symbols-outlined text-4xl text-text-sub">person</span>';
                    profilePreview.style.backgroundImage = '';
                    profilePreview.style.backgroundColor = '';
                }
                if (previewURL) {
                    URL.revokeObjectURL(previewURL);
                    previewURL = null;
                }
                previewBtn.disabled = true;
            }
        });

        previewBtn.addEventListener('click', function () {
            if (!previewURL) {
                errorEl.textContent = 'Please choose a photo first.';
                errorEl.classList.remove('hidden');
                return;
            }
            modalImage.src = previewURL;
            modal.classList.remove('hidden');
        });

        closeBtn.addEventListener('click', function () {
            modal.classList.add('hidden');
            // Don't revoke URL here as we still need it for the circular preview
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeBtn.click();
            }
        });
    }

    // Schedule preview/confirm logic
    const confirmBtn = document.getElementById('confirmScheduleBtn');
    const schedulePreviewBtn = document.getElementById('previewScheduleBtn');
    const scheduleModal = document.getElementById('schedulePreviewModal');
    const closeScheduleBtn = document.getElementById('closeSchedulePreview');
    const summaryContent = document.getElementById('scheduleSummaryContent');
    const days = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
    let confirmedSchedule = null;

    // Time validation: 5:00 AM to 10:00 PM
    const MIN_TIME = '05:00';
    const MAX_TIME = '22:00';
    const MAX_START_TIME = '11:55'; // Start time must be AM only

    function validateTime(time) {
        if (!time) return false;
        return time >= MIN_TIME && time <= MAX_TIME;
    }

    function validateScheduleEntry(start, end) {
        if (!start || !end) return false;
        
        // Validate start time is within range
        if (start < MIN_TIME || start > MAX_TIME) {
            return { valid: false, error: 'Start time must be between 5:00 AM and 10:00 PM.' };
        }
        
        // Validate end time is within range (5:00 AM to 10:00 PM)
        if (!validateTime(end)) {
            return { valid: false, error: 'End time must be between 5:00 AM and 10:00 PM.' };
        }
        
        if (start >= end) {
            return { valid: false, error: 'Start time must be before end time.' };
        }
        return { valid: true };
    }

    function getScheduleFromForm() {
        const schedule = [];
        const errors = [];
        days.forEach(day => {
            const checkbox = document.querySelector(`[name="${day}_active"]`);
            const start = document.querySelector(`[name="${day}_start"]`);
            const end = document.querySelector(`[name="${day}_end"]`);
            if (checkbox && checkbox.checked) {
                if (!start || !start.value || !end || !end.value) {
                    errors.push(`${day.charAt(0).toUpperCase() + day.slice(1)}: Please set both start and end times.`);
                } else {
                    const validation = validateScheduleEntry(start.value, end.value);
                    if (!validation.valid) {
                        errors.push(`${day.charAt(0).toUpperCase() + day.slice(1)}: ${validation.error}`);
                    } else {
                        schedule.push({ day, start: start.value, end: end.value });
                    }
                }
            }
        });
        return { schedule, errors };
    }

    // Add real-time validation for time inputs and disable/enable based on checkbox
    // Use event delegation to handle checkbox changes (more reliable)
    document.addEventListener('change', function(e) {
        if (e.target && e.target.matches('input[type="checkbox"][name$="_active"]')) {
            const checkbox = e.target;
            const dayAttr = checkbox.getAttribute('data-day') || checkbox.name.replace('_active', '');
            const card = checkbox.closest('.schedule-card');
            
            if (!card) return;
            
            const startInput = card.querySelector(`[name="${dayAttr}_start"]`);
            const endInput = card.querySelector(`[name="${dayAttr}_end"]`);
            
            if (!startInput || !endInput) return;
            
            const isChecked = checkbox.checked;
            
            // Toggle disabled state
            startInput.disabled = !isChecked;
            endInput.disabled = !isChecked;
            
            if (!isChecked) {
                // Unchecked: disable and clear
                startInput.classList.add('opacity-50', 'cursor-not-allowed');
                endInput.classList.add('opacity-50', 'cursor-not-allowed');
                startInput.classList.remove('border-red-500');
                endInput.classList.remove('border-red-500');
                startInput.value = '';
                endInput.value = '';
                card.classList.remove('active', 'border-primary');
                card.classList.add('border-gray-200', 'dark:border-gray-700');
                
                // Hide quick actions with animation
                const quickActions = card.querySelector('.quick-actions');
                if (quickActions) {
                    quickActions.classList.remove('max-h-20', 'opacity-100');
                    quickActions.classList.add('max-h-0', 'opacity-0', 'pointer-events-none');
                }
            } else {
                // Checked: enable
                startInput.classList.remove('opacity-50', 'cursor-not-allowed');
                endInput.classList.remove('opacity-50', 'cursor-not-allowed');
                card.classList.add('active', 'border-primary');
                card.classList.remove('border-gray-200', 'dark:border-gray-700');
                
                // Show quick actions with animation
                const quickActions = card.querySelector('.quick-actions');
                if (quickActions) {
                    quickActions.classList.remove('max-h-0', 'opacity-0', 'pointer-events-none');
                    quickActions.classList.add('max-h-20', 'opacity-100');
                }
            }
        }
    });
    
    // Initialize all checkboxes on page load
    document.querySelectorAll('.schedule-card').forEach(card => {
        const dayAttr = card.getAttribute('data-day');
        if (!dayAttr) return;
        
        const startInput = card.querySelector(`[name="${dayAttr}_start"]`);
        const endInput = card.querySelector(`[name="${dayAttr}_end"]`);
        const checkbox = card.querySelector(`[name="${dayAttr}_active"]`);
        
        if (startInput && endInput && checkbox) {
            // Set initial disabled state
            startInput.disabled = !checkbox.checked;
            endInput.disabled = !checkbox.checked;
            
            if (!checkbox.checked) {
                startInput.classList.add('opacity-50', 'cursor-not-allowed');
                endInput.classList.add('opacity-50', 'cursor-not-allowed');
            }
            
            // Add validation for time inputs
            function validateDayTimes() {
                if (checkbox.checked) {
                    const start = startInput.value;
                    const end = endInput.value;
                    
                    startInput.classList.remove('border-red-500');
                    endInput.classList.remove('border-red-500');
                    
                    if (start && end) {
                        const validation = validateScheduleEntry(start, end);
                        if (!validation.valid) {
                            startInput.classList.add('border-red-500');
                            endInput.classList.add('border-red-500');
                        }
                    }
                }
            }
            
            // Validate start time - Removed AM only restriction
            startInput.addEventListener('change', validateDayTimes);
            
            endInput.addEventListener('change', validateDayTimes);
        }
    });
    
    // Copy Day functionality with radio buttons
    const copyDayModal = document.getElementById('copyDayModal');
    const copyDayModalContent = document.getElementById('copyDayModalContent');
    const copyDaySource = document.getElementById('copyDaySource');
    const copyDayRadioContainer = document.getElementById('copyDayRadioContainer');
    const copyDayCancel = document.getElementById('copyDayCancel');
    const copyDayConfirm = document.getElementById('copyDayConfirm');
    const closeCopyDayModal = document.getElementById('closeCopyDayModal');
    
    let currentSourceDay = null;
    let currentSourceCard = null;
    
    function showCopyDayModal(sourceDay, sourceCard) {
        currentSourceDay = sourceDay;
        currentSourceCard = sourceCard;
        
        const sourceCheckbox = sourceCard.querySelector(`[name="${sourceDay}_active"]`);
        const sourceStart = sourceCard.querySelector(`[name="${sourceDay}_start"]`);
        const sourceEnd = sourceCard.querySelector(`[name="${sourceDay}_end"]`);
        
        if (!sourceCheckbox.checked) {
            showError('Please enable the day first before copying.', 'Copy Day');
            return;
        }
        
        if (!sourceStart.value || !sourceEnd.value) {
            showError('Please set both start and end times before copying.', 'Copy Day');
            return;
        }
        
        // Set source day text
        const sourceDayName = sourceDay.charAt(0).toUpperCase() + sourceDay.slice(1);
        const timeRange = `${sourceStart.value} - ${sourceEnd.value}`;
        copyDaySource.textContent = `Copying schedule from ${sourceDayName} (${timeRange})`;
        
        // Clear previous checkboxes
        copyDayRadioContainer.innerHTML = '';
        
        // Create checkboxes for each day (excluding source day) - allow multiple selection
        days.forEach(day => {
            if (day === sourceDay) return;
            
            const dayName = day.charAt(0).toUpperCase() + day.slice(1);
            const checkboxId = `copyDay_${day}`;
            
            const label = document.createElement('label');
            label.className = 'flex items-center gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer transition-all';
            
            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = 'copyDayTarget';
            checkbox.id = checkboxId;
            checkbox.value = day;
            checkbox.className = 'w-5 h-5 text-primary focus:ring-primary/50 cursor-pointer rounded';
            
            const dayLabel = document.createElement('span');
            dayLabel.className = 'text-sm font-medium text-text-main dark:text-white flex-1';
            dayLabel.textContent = dayName;
            
            label.appendChild(checkbox);
            label.appendChild(dayLabel);
            copyDayRadioContainer.appendChild(label);
        });
        
        // Show modal with animation
        copyDayModal.classList.remove('hidden');
        setTimeout(() => {
            copyDayModalContent.classList.remove('scale-95', 'opacity-0');
            copyDayModalContent.classList.add('scale-100', 'opacity-100');
        }, 10);
    }
    
    function closeCopyDayModalFunc() {
        copyDayModalContent.classList.remove('scale-100', 'opacity-100');
        copyDayModalContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            copyDayModal.classList.add('hidden');
            currentSourceDay = null;
            currentSourceCard = null;
            // Clear radio selection
            copyDayRadioContainer.innerHTML = '';
        }, 300);
    }
    
    if (copyDayCancel) copyDayCancel.addEventListener('click', closeCopyDayModalFunc);
    if (closeCopyDayModal) closeCopyDayModal.addEventListener('click', closeCopyDayModalFunc);
    if (copyDayModal) {
        copyDayModal.addEventListener('click', function(e) {
            if (e.target === copyDayModal) {
                closeCopyDayModalFunc();
            }
        });
    }
    
    if (copyDayConfirm) {
        copyDayConfirm.addEventListener('click', async function() {
            const selectedCheckboxes = document.querySelectorAll('input[name="copyDayTarget"]:checked');
            if (selectedCheckboxes.length === 0) {
                await showError('Please select at least one target day.', 'Copy Day');
                return;
            }
            
            const sourceStart = currentSourceCard.querySelector(`[name="${currentSourceDay}_start"]`);
            const sourceEnd = currentSourceCard.querySelector(`[name="${currentSourceDay}_end"]`);
            
            const copiedDays = [];
            
            // Copy to all selected days
            selectedCheckboxes.forEach(checkbox => {
                const targetDay = checkbox.value;
                const targetCard = document.querySelector(`[data-day="${targetDay}"]`);
                if (!targetCard) return;
                
                const targetCheckbox = targetCard.querySelector(`[name="${targetDay}_active"]`);
                const targetStart = targetCard.querySelector(`[name="${targetDay}_start"]`);
                const targetEnd = targetCard.querySelector(`[name="${targetDay}_end"]`);
                
                if (targetCheckbox && targetStart && targetEnd) {
                    targetCheckbox.checked = true;
                    targetStart.value = sourceStart.value;
                    targetEnd.value = sourceEnd.value;
                    
                    // Enable the inputs (make them editable)
                    targetStart.disabled = false;
                    targetEnd.disabled = false;
                    targetStart.classList.remove('opacity-50', 'cursor-not-allowed');
                    targetEnd.classList.remove('opacity-50', 'cursor-not-allowed');
                    
                    // Trigger change event to update UI (this will show the quick actions)
                    targetCheckbox.dispatchEvent(new Event('change'));
                    targetStart.dispatchEvent(new Event('change'));
                    targetEnd.dispatchEvent(new Event('change'));
                    
                    // Update card styling
                    targetCard.classList.add('active', 'border-primary');
                    targetCard.classList.remove('border-gray-200', 'dark:border-gray-700');
                    
                    // Ensure quick actions are visible (in case change event didn't trigger properly)
                    const quickActions = targetCard.querySelector('.quick-actions');
                    if (quickActions) {
                        quickActions.classList.remove('max-h-0', 'opacity-0');
                        quickActions.classList.add('max-h-20', 'opacity-100');
                    }
                    
                    copiedDays.push(targetDay);
                }
            });
            
            closeCopyDayModalFunc();
            
            if (copiedDays.length > 0) {
                const dayNames = copiedDays.map(d => d.charAt(0).toUpperCase() + d.slice(1)).join(', ');
                await showSuccess(`Schedule copied from ${currentSourceDay} to ${dayNames}!`, 'Copy Day');
            }
        });
    }
    
    // Attach copy day button handlers
    document.querySelectorAll('.copy-day-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const sourceDay = this.getAttribute('data-day');
            const sourceCard = this.closest('.schedule-card');
            showCopyDayModal(sourceDay, sourceCard);
        });
    });
    
    // Apply to All functionality
    document.querySelectorAll('.apply-all-btn').forEach(btn => {
        btn.addEventListener('click', async function() {
            const sourceDay = this.getAttribute('data-day');
            const sourceCard = this.closest('.schedule-card');
            const sourceCheckbox = sourceCard.querySelector(`[name="${sourceDay}_active"]`);
            const sourceStart = sourceCard.querySelector(`[name="${sourceDay}_start"]`);
            const sourceEnd = sourceCard.querySelector(`[name="${sourceDay}_end"]`);
            
            if (!sourceCheckbox.checked) {
                await showError('Please enable the day first before applying to all.', 'Apply to All');
                return;
            }
            
            if (!sourceStart.value || !sourceEnd.value) {
                await showError('Please set both start and end times before applying to all.', 'Apply to All');
                return;
            }
            
            const confirmed = await showConfirm(
                `Apply ${sourceDay.charAt(0).toUpperCase() + sourceDay.slice(1)} schedule to all days?`,
                'Apply to All'
            );
            
            if (!confirmed) {
                return;
            }
            
            days.forEach(day => {
                if (day === sourceDay) return; // Skip source day
                
                const targetCard = document.querySelector(`[data-day="${day}"]`);
                if (!targetCard) return;
                
                const targetCheckbox = targetCard.querySelector(`[name="${day}_active"]`);
                const targetStart = targetCard.querySelector(`[name="${day}_start"]`);
                const targetEnd = targetCard.querySelector(`[name="${day}_end"]`);
                
                if (targetCheckbox && targetStart && targetEnd) {
                    targetCheckbox.checked = true;
                    targetStart.value = sourceStart.value;
                    targetEnd.value = sourceEnd.value;
                    
                    // Enable the inputs (make them editable)
                    targetStart.disabled = false;
                    targetEnd.disabled = false;
                    targetStart.classList.remove('opacity-50', 'cursor-not-allowed');
                    targetEnd.classList.remove('opacity-50', 'cursor-not-allowed');
                    
                    // Trigger change event to update UI
                    targetCheckbox.dispatchEvent(new Event('change'));
                    targetStart.dispatchEvent(new Event('change'));
                    targetEnd.dispatchEvent(new Event('change'));
                    
                    // Update card styling
                    targetCard.classList.add('active', 'border-primary');
                    targetCard.classList.remove('border-gray-200', 'dark:border-gray-700');
                }
            });
            
            await showSuccess('Schedule applied to all days!', 'Apply to All');
        });
    });

    if (confirmBtn && schedulePreviewBtn) {
        confirmBtn.addEventListener('click', async function () {
            const result = getScheduleFromForm();
            if (result.errors.length > 0) {
                await showError('Please fix the following errors:\n\n' + result.errors.join('\n'), 'Validation Error');
                return;
            }
            if (result.schedule.length === 0) {
                await showError('Please select at least one available day and set start/end times.', 'Validation Error');
                return;
            }
            confirmedSchedule = result.schedule;
            confirmBtn.style.backgroundColor = '#16a34a';
            confirmBtn.innerHTML = '<span class="material-symbols-outlined" style="font-size: 18px; vertical-align: middle; margin-right: 4px;">check_circle</span>Confirmed';
            schedulePreviewBtn.disabled = false;
            await showSuccess('Schedule confirmed! You can preview or submit the form.', 'Schedule Confirmed');
        });

        schedulePreviewBtn.addEventListener('click', function () {
            const result = getScheduleFromForm();
            const schedule = confirmedSchedule || result.schedule;
            if (schedule.length === 0) {
                summaryContent.innerHTML = '<p class="text-red-500">No days selected.</p>';
            } else {
                let html = '<div class="overflow-x-auto"><table class="w-full text-sm border rounded-xl mb-2"><thead class="bg-[#f3f0f4] dark:bg-gray-800"><tr><th class="p-2 text-left font-bold">Day</th><th class="p-2 font-bold">Start Time</th><th class="p-2 font-bold">End Time</th></tr></thead><tbody>';
                schedule.forEach(row => {
                    const dayName = row.day.charAt(0).toUpperCase() + row.day.slice(1);
                    // Format time for display (convert 24h to 12h)
                    const startTime = formatTime12h(row.start);
                    const endTime = formatTime12h(row.end);
                    html += `<tr class="border-t border-gray-200 dark:border-gray-700"><td class="p-2 font-bold">${dayName}</td><td class="p-2">${startTime}</td><td class="p-2">${endTime}</td></tr>`;
                });
                html += '</tbody></table></div>';
                summaryContent.innerHTML = html;
            }
            scheduleModal.classList.remove('hidden');
        });

        closeScheduleBtn.addEventListener('click', function () {
            scheduleModal.classList.add('hidden');
        });
        
        scheduleModal.addEventListener('click', function (e) {
            if (e.target === scheduleModal) closeScheduleBtn.click();
        });

        // Helper function to format 24h time to 12h
        function formatTime12h(time24) {
            const [hours, minutes] = time24.split(':');
            const hour = parseInt(hours);
            const ampm = hour >= 12 ? 'PM' : 'AM';
            const hour12 = hour % 12 || 12;
            return `${hour12}:${minutes} ${ampm}`;
        }

        // Enable preview only after confirm
        schedulePreviewBtn.disabled = true;
    }
});
</script>


    <script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
<!-- OTP Verification Modal -->
<div id="otpModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-8 max-w-sm w-[90%] shadow-2xl">
        <div class="text-center mb-6">
            <h3 class="text-2xl font-bold text-text-main dark:text-white mb-2">Verify Email</h3>
            <p class="text-sm text-text-sub">Enter the 6-digit code sent to <br><span id="otpEmailDisplay" class="font-bold text-primary"></span></p>
        </div>
        
        <div class="flex justify-center gap-2 mb-6" id="otpInputContainer">
            <input type="text" maxlength="6" id="otpInput" class="w-full text-center text-3xl font-bold tracking-widest h-14 rounded-xl border border-gray-200 dark:border-gray-700 bg-background-light dark:bg-gray-800 text-text-main dark:text-white focus:ring-4 focus:ring-primary/20 focus:border-primary outline-none transition-all uppercase" placeholder="XXXXXX">
        </div>

        <button type="button" id="submitOtpBtn" class="w-full py-3 rounded-xl bg-primary text-white font-bold text-lg shadow-lg hover:bg-primary/90 transition-all flex items-center justify-center gap-2">
            Verify Code
        </button>

        <button type="button" id="cancelOtpBtn" class="w-full mt-4 py-2 text-text-sub text-sm font-semibold hover:text-text-main transition-colors">
            Cancel
        </button>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const emailInput = document.getElementById('emailInput');
    const verifyBtn = document.getElementById('verifyEmailBtn');
    const otpModal = document.getElementById('otpModal');
    const otpInput = document.getElementById('otpInput');
    const submitOtpBtn = document.getElementById('submitOtpBtn');
    const cancelOtpBtn = document.getElementById('cancelOtpBtn');
    const emailDisplay = document.getElementById('otpEmailDisplay');
    const verificationStatus = document.getElementById('verificationStatus');
    const verifiedInput = document.getElementById('emailVerified');
    
    // Setup Verify Button
    verifyBtn.addEventListener('click', async function() {
        const email = emailInput.value.trim();
        if (!email) {
            await showError('Please enter an email address first.', 'Verification');
            return;
        }
        if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            await showError('Please enter a valid email address.', 'Verification');
            return;
        }

        // Show loading
        const originalText = verifyBtn.innerHTML;
        verifyBtn.disabled = true;
        verifyBtn.innerHTML = '<span class="material-symbols-outlined animate-spin text-sm">progress_activity</span> Sending...';

        try {
            // Send API Request
            const response = await fetch('../api/send_employee_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email })
            });
            const data = await response.json();
            
            if (data.success) {
                // Show Modal
                emailDisplay.textContent = email;
                otpModal.classList.remove('hidden');
                otpInput.value = '';
                otpInput.focus();
                
                // Allow button reset but cooldown logic handled by UI feedback
                verifyBtn.innerHTML = originalText; // Reset text
            } else {
                await showError(data.message || 'Failed to send verification code.', 'Error');
                verifyBtn.disabled = false;
                verifyBtn.innerHTML = originalText;
            }
        } catch (e) {
            console.error(e);
            await showError('Network error occurred.', 'Error');
            verifyBtn.disabled = false;
            verifyBtn.innerHTML = originalText;
        }
    });

    // Handle OTP Submission
    submitOtpBtn.addEventListener('click', async function() {
        const otp = otpInput.value.trim();
        const email = emailInput.value.trim();
        
        if (otp.length !== 6) {
            await showError('Please enter a valid 6-digit code.', 'Validation');
            return;
        }

        submitOtpBtn.disabled = true;
        submitOtpBtn.innerHTML = '<span class="material-symbols-outlined animate-spin">progress_activity</span>';

        try {
             const response = await fetch('../api/verify_employee_otp.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email: email, otp: otp })
            });
            const data = await response.json();
            
            if (data.success) {
                // Success!
                otpModal.classList.add('hidden');
                await showSuccess('Email Verified Successfully!', 'Verified');
                
                // Update UI
                verifiedInput.value = '1';
                verificationStatus.textContent = 'Email Verified';
                verificationStatus.classList.remove('hidden', 'text-red-500');
                verificationStatus.classList.add('text-green-600');
                
                emailInput.classList.add('bg-green-50', 'text-green-800', 'border-green-300');
                emailInput.readOnly = true;
                verifyBtn.classList.add('hidden'); // Hide verify button
                
                // Add checkmark icon
                const checkIcon = document.createElement('span');
                checkIcon.className = 'material-symbols-outlined text-green-600 text-xl absolute right-3 top-1/2 -translate-y-1/2';
                checkIcon.textContent = 'check_circle';
                emailInput.parentElement.appendChild(checkIcon);

            } else {
                await showError(data.message || 'Invalid OTP.', 'Verification Failed');
            }
        } catch (e) {
             await showError('Verification error occurred.', 'Error');
        } finally {
            submitOtpBtn.disabled = false;
            submitOtpBtn.textContent = 'Verify Code';
        }
    });

    cancelOtpBtn.addEventListener('click', function() {
        otpModal.classList.add('hidden');
        verifyBtn.disabled = false;
        verifyBtn.innerHTML = 'Verify';
    });
    
    // Prevent Form Submission if not verified
    const form = document.querySelector('form[action="admin_registration_user.php"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            if (verifiedInput.value !== '1') {
                e.preventDefault();
                showError('Please verify the employee email address before registering.', 'Verification Required');
                // Scroll to email
                emailInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                verifyBtn.focus();
            }
        });
    }
});
</script>
</body></html> 
