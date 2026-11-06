<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role
requireRole('Receptionist');
updateUserActivity();

// --- VERIFICATION CHECK ---
$patient_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$patient_id) {
    header("Location: patient_records.php");
    exit;
}

// Check if verified in session
if (!isset($_SESSION['patient_update_verified']) || 
    $_SESSION['patient_update_verified']['patient_id'] != $patient_id || 
    time() > $_SESSION['patient_update_verified']['expires']) {
    
    // Redirect back if not verified
    header("Location: patient_records.php?error=verification_required");
    exit;
}
// --------------------------

$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $user_details_stmt = $pdo->prepare("SELECT user_id, username, full_name, role, email, contact_number, blood_type, status, profile_picture FROM tbl_user WHERE user_id = ? LIMIT 1");
        $user_details_stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $user_details_stmt->fetch();
    } catch (PDOException $e) { /* Ignore */ }
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

// Fetch Patient Data
$patient = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tbl_patient WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch();
    if (!$patient) {
        die("Patient not found.");
    }
} catch (PDOException $e) {
    die("Database error.");
}

// Handle Form Submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_patient'])) {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } else {
        $first_name = sanitizeInput($_POST['first_name']);
        $middle_name = sanitizeInput($_POST['middle_name']);
        $last_name = sanitizeInput($_POST['last_name']);
        $dob = $_POST['dob'];
        $gender = $_POST['gender'];
        $civil_status = $_POST['civil_status'];
        $occupation = sanitizeInput($_POST['occupation']);
        $blood_type = $_POST['blood_type'];
        $medical_history = sanitizeInput($_POST['medical_history']);
        $phone_number = preg_replace('/[^0-9]/', '', $_POST['phone_number']);
        $email = sanitizeInput($_POST['email']);
        $address = sanitizeInput($_POST['address']);
        
        $errors = [];
        if (empty($first_name)) $errors[] = 'First name is required.';
        if (empty($last_name)) $errors[] = 'Last name is required.';
        if (empty($phone_number)) $errors[] = 'Phone number is required.';
        
        // Check duplication (excluding self) - REMOVED per user request to allow dependents
        // try {
        //     $check = $pdo->prepare("SELECT patient_id FROM tbl_patient WHERE (phone_number = ? OR (email = ? AND email != '')) AND patient_id != ?");
        //     $check->execute([$phone_number, $email, $patient_id]);
        //     if ($check->fetch()) {
        //         $errors[] = 'Phone number or Email already in use by another patient.';
        //     }
        // } catch (PDOException $e) {}

        if (empty($errors)) {
            try {
                // Update Age
                $age = date_diff(date_create($dob), date_create('today'))->y;
                $age_category = ($age >= 60) ? 'Senior' : (($age < 18) ? 'Minor' : 'Adult');

                $sql = "UPDATE tbl_patient SET 
                        first_name=?, middle_name=?, last_name=?, dob=?, age=?, age_category=?, gender=?, 
                        phone_number=?, email=?, medical_history=?, blood_type=?, civil_status=?, 
                        occupation=?, address=?
                        WHERE patient_id=?";
                $params = [
                    $first_name, $middle_name, $last_name, $dob, $age, $age_category, $gender,
                    $phone_number, $email, $medical_history, $blood_type, $civil_status,
                    $occupation, $address, $patient_id
                ];
                
                $update_stmt = $pdo->prepare($sql);
                $update_stmt->execute($params);
                
                $success_message = "Patient information updated successfully.";
                
                // Refresh data
                $stmt->execute([$patient_id]);
                $patient = $stmt->fetch();
                
            } catch (PDOException $e) {
                $error_message = "Update failed: " . $e->getMessage();
            }
        } else {
            $error_message = implode(' ', $errors);
        }
    }
}

// Helpers
function isSelected($value, $current) { return $value === $current ? 'selected' : ''; }
$csrf_token = generateCSRFToken();

// UI Variables
$current_name = $current_user_details['full_name'] ?? 'Receptionist';
$current_role = $current_user_details['role'] ?? 'Receptionist';
$current_profile = $current_user_details['profile_picture'] ?? null;
$default_photo = getDefaultProfilePhoto($current_name);

// Get current page for active navigation state
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Update Patient - <?php echo htmlspecialchars($patient['first_name']); ?></title>
    <!-- Fonts & Icons -->
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: { "primary": "#8a11d4", "royal-purple": "#6A1B9A", "clinic-gold": "#FFD700", "background-light": "#f7f6f8", "background-dark": "#1b1022" },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
        }
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark text-[#151118] font-display antialiased overflow-hidden">
<div class="flex h-screen w-full">
    <!-- Full Sidebar -->
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
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_update_information.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="patient_records.php">
                <span class="material-symbols-outlined">folder_shared</span>
                <span class="<?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_update_information.php') ? 'font-bold' : 'font-medium'; ?>">Patient Records</span>
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
                <div class="flex items-center gap-3">
                    <a href="patient_records.php" class="p-2 rounded-lg hover:bg-gray-100 text-slate-500 transition-colors">
                        <span class="material-symbols-outlined">arrow_back</span>
                    </a>
                    <h2 class="text-3xl font-black text-[#151118] tracking-tight">Update Patient</h2>
                </div>
                <p class="text-slate-500 font-medium ml-12">Edit information for <span class="text-royal-purple font-bold"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></span></p>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-5xl mx-auto">
                <?php if ($success_message): ?>
                    <div class="mb-6 p-4 bg-green-50 text-green-700 border border-green-200 rounded-xl flex items-center gap-3">
                        <span class="material-symbols-outlined">check_circle</span>
                        <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($error_message): ?>
                    <div class="mb-6 p-4 bg-red-50 text-red-700 border border-red-200 rounded-xl flex items-center gap-3">
                        <span class="material-symbols-outlined">error</span>
                        <?php echo htmlspecialchars($error_message); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="update_patient" value="1">

                    <!-- Personal Information -->
                    <div class="mb-8">
                        <h3 class="text-lg font-bold text-royal-purple mb-6 flex items-center gap-2">
                            <span class="material-symbols-outlined">person</span> Personal Information
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">First Name *</label>
                                <input type="text" name="first_name" required value="<?php echo htmlspecialchars($patient['first_name']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Middle Name</label>
                                <input type="text" name="middle_name" value="<?php echo htmlspecialchars($patient['middle_name']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Last Name *</label>
                                <input type="text" name="last_name" required value="<?php echo htmlspecialchars($patient['last_name']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Date of Birth *</label>
                                <input type="date" name="dob" required value="<?php echo htmlspecialchars($patient['dob']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Gender *</label>
                                <select name="gender" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                                    <option value="Male" <?php echo isSelected('Male', $patient['gender']); ?>>Male</option>
                                    <option value="Female" <?php echo isSelected('Female', $patient['gender']); ?>>Female</option>
                                    <option value="Other" <?php echo isSelected('Other', $patient['gender']); ?>>Other</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Civil Status *</label>
                                <select name="civil_status" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                                    <option value="Single" <?php echo isSelected('Single', $patient['civil_status']); ?>>Single</option>
                                    <option value="Married" <?php echo isSelected('Married', $patient['civil_status']); ?>>Married</option>
                                    <option value="Widowed" <?php echo isSelected('Widowed', $patient['civil_status']); ?>>Widowed</option>
                                    <option value="Divorced" <?php echo isSelected('Divorced', $patient['civil_status']); ?>>Divorced</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Occupation</label>
                                <input type="text" name="occupation" value="<?php echo htmlspecialchars($patient['occupation']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                             <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Blood Type</label>
                                <select name="blood_type" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                                    <option value="Unknown" <?php echo isSelected('Unknown', $patient['blood_type']); ?>>Unknown</option>
                                    <option value="A+" <?php echo isSelected('A+', $patient['blood_type']); ?>>A+</option>
                                    <option value="A-" <?php echo isSelected('A-', $patient['blood_type']); ?>>A-</option>
                                    <option value="B+" <?php echo isSelected('B+', $patient['blood_type']); ?>>B+</option>
                                    <option value="B-" <?php echo isSelected('B-', $patient['blood_type']); ?>>B-</option>
                                    <option value="O+" <?php echo isSelected('O+', $patient['blood_type']); ?>>O+</option>
                                    <option value="O-" <?php echo isSelected('O-', $patient['blood_type']); ?>>O-</option>
                                    <option value="AB+" <?php echo isSelected('AB+', $patient['blood_type']); ?>>AB+</option>
                                    <option value="AB-" <?php echo isSelected('AB-', $patient['blood_type']); ?>>AB-</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Contact Information -->
                    <div class="mb-8 border-t border-gray-100 pt-8">
                        <h3 class="text-lg font-bold text-royal-purple mb-6 flex items-center gap-2">
                            <span class="material-symbols-outlined">contact_phone</span> Contact Information
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Phone Number *</label>
                                <input type="text" name="phone_number" required value="<?php echo htmlspecialchars($patient['phone_number']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($patient['email']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Address</label>
                                <input type="text" name="address" value="<?php echo htmlspecialchars($patient['address']); ?>" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Medical History -->
                    <div class="mb-8 border-t border-gray-100 pt-8">
                        <h3 class="text-lg font-bold text-royal-purple mb-6 flex items-center gap-2">
                            <span class="material-symbols-outlined">medical_services</span> Medical Information
                        </h3>
                        <div>
                             <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">Medical History / Allergies</label>
                             <textarea name="medical_history" rows="4" class="w-full px-4 py-3 rounded-xl border-gray-200 focus:border-royal-purple focus:ring-royal-purple"><?php echo htmlspecialchars($patient['medical_history']); ?></textarea>
                        </div>
                    </div>

                    <div class="flex justify-end gap-4 border-t border-gray-100 pt-8">
                        <a href="patient_records.php" class="px-6 py-3 rounded-xl border border-gray-200 text-slate-600 font-bold hover:bg-gray-50 transition-colors">Cancel</a>
                        <button type="submit" class="px-8 py-3 rounded-xl bg-royal-purple hover:bg-purple-800 text-white font-bold shadow-lg shadow-purple-200 transition-colors flex items-center gap-2">
                            <span class="material-symbols-outlined">save</span> Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
