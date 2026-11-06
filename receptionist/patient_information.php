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

// Get patient ID from URL
$patient_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($patient_id <= 0) {
    header('Location: patient_records.php');
    exit;
}

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

// Helper function to calculate age from DOB
function calculateAge($dob) {
    if (!$dob) return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y;
}

$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Receptionist';
$current_role = $current_user['role'] ?? 'Receptionist';
$current_profile = $current_user['profile_picture'] ?? null;
$default_photo = getDefaultProfilePhoto($current_name);

// Get current page for active navigation state
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch patient details
$patient = null;
try {
    $patient_stmt = $pdo->prepare("
        SELECT p.*, pa.username 
        FROM tbl_patient p 
        LEFT JOIN tbl_patient_accounts pa ON p.account_id = pa.account_id 
        WHERE p.patient_id = ? 
        LIMIT 1
    ");
    $patient_stmt->execute([$patient_id]);
    $patient = $patient_stmt->fetch();
    
    if (!$patient) {
        header('Location: patient_records.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Error fetching patient: ' . $e->getMessage());
    header('Location: patient_records.php');
    exit;
}

// Calculate patient age
$age = calculateAge($patient['dob']);
$initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
$patient_name = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
$patient_id_display = 'P-' . str_pad($patient['patient_id'], 4, '0', STR_PAD_LEFT);

// Format DOB
$dob_date = new DateTime($patient['dob']);
$dob_formatted = $dob_date->format('F d, Y');

// Format registration date
$reg_date = new DateTime($patient['registration_date']);
$reg_formatted = $reg_date->format('M d, Y');

// Fetch next appointment
$next_appointment = null;
try {
    $next_appt_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as doctor_name, GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
        FROM tbl_appointment a
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.patient_id = ? 
          AND a.appointment_date >= CURDATE() 
          AND a.status IN ('Pending', 'Booked')
        GROUP BY a.appointment_id
        ORDER BY a.appointment_date ASC, a.time_slot ASC 
        LIMIT 1
    ");
    $next_appt_stmt->execute([$patient_id]);
    $next_appointment = $next_appt_stmt->fetch();
} catch (PDOException $e) {
    error_log('Error fetching next appointment: ' . $e->getMessage());
}

// Fetch recent appointments (last 5)
$recent_appointments = [];
try {
    $appt_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as doctor_name, GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
        FROM tbl_appointment a
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.patient_id = ?
        GROUP BY a.appointment_id
        ORDER BY a.appointment_date DESC, a.time_slot DESC
        LIMIT 5
    ");
    $appt_stmt->execute([$patient_id]);
    $recent_appointments = $appt_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching appointments: ' . $e->getMessage());
}

// Fetch medical records (last 5)
$medical_records = [];
try {
    $records_stmt = $pdo->prepare("
        SELECT mr.*, u.full_name as doctor_name, s.service_name
        FROM tbl_medical_record mr
        INNER JOIN tbl_user u ON mr.doctor_id = u.user_id
        INNER JOIN tbl_service s ON mr.service_id = s.service_id
        WHERE mr.patient_id = ?
        ORDER BY mr.date_of_treatment DESC
        LIMIT 5
    ");
    $records_stmt->execute([$patient_id]);
    $medical_records = $records_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching medical records: ' . $e->getMessage());
}

// Fetch Installment Plans
$patient_installments = [];
try {
    $inst_stmt = $pdo->prepare("
        SELECT i.*, s.service_name 
        FROM tbl_installment i 
        JOIN tbl_service s ON i.service_id = s.service_id 
        WHERE i.patient_id = ? 
        ORDER BY FIELD(i.status, 'Active', 'Defaulted', 'Completed'), i.created_at DESC
    ");
    $inst_stmt->execute([$patient_id]);
    $patient_installments = $inst_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching installments: ' . $e->getMessage());
}

// Determine if patient is active (has recent visit or upcoming appointment)
$is_active = !empty($next_appointment) || !empty($medical_records);
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Patient Record - Vanessa Nicolas Dental</title>
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
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="patient_records.php">
<span class="material-symbols-outlined">folder_shared</span>
<span class="<?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'font-bold' : 'font-medium'; ?>">Patient Records</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_booking.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_booking.php">
<span class="material-symbols-outlined">calendar_month</span>
<span class="<?php echo ($current_page === 'receptionist_booking.php') ? 'font-bold' : 'font-medium'; ?>">Bookings</span>
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
<div class="flex items-center gap-2 text-sm text-slate-500 font-medium mb-1">
<a href="receptionist_dashboard.php" class="hover:text-royal-purple cursor-pointer">Dashboard</a>
<span class="material-symbols-outlined text-sm">chevron_right</span>
<a href="patient_records.php" class="hover:text-royal-purple cursor-pointer">Patient Records</a>
<span class="material-symbols-outlined text-sm">chevron_right</span>
<span class="text-royal-purple font-bold"><?php echo $patient_id_display; ?></span>
</div>
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Patient Record</h2>
</div>
<div class="flex items-center gap-4">
<label class="relative hidden lg:flex items-center h-12 w-80">
<div class="absolute left-4 text-slate-400 flex items-center justify-center">
<span class="material-symbols-outlined">search</span>
</div>
<input class="w-full h-full pl-12 pr-4 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400 shadow-sm" placeholder="Search another patient..."/>
</label>
<button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
<span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full"></span>
<span class="material-symbols-outlined">notifications</span>
</button>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8">
<div class="max-w-[1400px] mx-auto flex flex-col gap-6">
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
<div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-royal-purple via-purple-500 to-clinic-gold"></div>
<div class="flex items-center gap-6 relative z-10">
<div class="w-24 h-24 rounded-full bg-blue-50 border-4 border-white shadow-lg flex items-center justify-center text-blue-600 font-bold text-3xl">
<?php echo $initials; ?>
</div>
<div class="flex flex-col gap-1">
<div class="flex items-center gap-3">
<h1 class="text-3xl font-bold text-[#151118]"><?php echo $patient_name; ?></h1>
<?php if ($is_active): ?>
<span class="px-3 py-1 bg-green-100 text-green-700 text-xs font-bold rounded-full border border-green-200 flex items-center gap-1">
<span class="w-1.5 h-1.5 rounded-full bg-green-600 animate-pulse"></span>
                                    Active Patient
                                </span>
<?php else: ?>
<span class="px-3 py-1 bg-gray-100 text-gray-700 text-xs font-bold rounded-full border border-gray-200">
                                    Inactive
                                </span>
<?php endif; ?>
</div>
<div class="flex flex-col md:flex-row md:items-center gap-2 md:gap-4 text-slate-500 text-sm font-medium">
<span class="flex items-center gap-1">
<span class="material-symbols-outlined text-lg text-slate-400">badge</span>
                                    ID: <span class="text-slate-700 font-mono"><?php echo $patient_id_display; ?></span>
</span>
<span class="hidden md:inline text-slate-300">|</span>
<span class="flex items-center gap-1">
<span class="material-symbols-outlined text-lg text-slate-400">cake</span>
                                    <?php echo $age; ?> Years Old
                                </span>
<span class="hidden md:inline text-slate-300">|</span>
<span class="flex items-center gap-1">
<span class="material-symbols-outlined text-lg text-slate-400">calendar_clock</span>
                                    Registered: <?php echo $reg_formatted; ?>
                                </span>
</div>
</div>
</div>
<div class="flex items-center gap-3 self-end md:self-center">
<a href="receptionist_booking.php?patient_id=<?php echo $patient_id; ?>" class="h-11 px-5 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold text-sm flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
<span class="material-symbols-outlined text-lg">event_upcoming</span>
                            New Appointment
                        </a>
</div>
</div>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
<div class="xl:col-span-2 flex flex-col gap-6">
<section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
<div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
<h3 class="font-bold text-lg text-royal-purple flex items-center gap-2">
<span class="material-symbols-outlined">person</span>
                                    Personal Information
                                </h3>
<span class="text-xs font-bold text-green-600 bg-green-50 px-2 py-1 rounded">Verified</span>
</div>
<div class="p-6">
<div class="grid grid-cols-1 md:grid-cols-2 gap-y-6 gap-x-8">
<div class="flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">First Name</label>
<p class="text-lg font-bold text-[#151118]"><?php echo htmlspecialchars($patient['first_name']); ?></p>
</div>
<div class="flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Last Name</label>
<p class="text-lg font-bold text-[#151118]"><?php echo htmlspecialchars($patient['last_name']); ?></p>
</div>
<div class="flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Date of Birth</label>
<div class="flex items-center gap-2">
<p class="text-lg font-bold text-[#151118]"><?php echo $dob_formatted; ?></p>
<span class="text-xs bg-slate-100 text-slate-600 px-2 py-0.5 rounded font-medium"><?php echo $age; ?> yrs</span>
</div>
</div>
<div class="flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Gender</label>
<p class="text-lg font-bold text-[#151118]"><?php echo htmlspecialchars($patient['gender'] ?? 'N/A'); ?></p>
</div>
<?php if (!empty($patient['blood_type'])): ?>
<div class="flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Blood Type</label>
<p class="text-lg font-bold text-[#151118]"><?php echo htmlspecialchars($patient['blood_type']); ?></p>
</div>
<?php endif; ?>
<?php if (!empty($patient['civil_status'])): ?>
<div class="flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Civil Status</label>
<p class="text-lg font-bold text-[#151118]"><?php echo htmlspecialchars($patient['civil_status']); ?></p>
</div>
<?php endif; ?>
<?php if (!empty($patient['occupation'])): ?>
<div class="flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Occupation</label>
<p class="text-lg font-bold text-[#151118]"><?php echo htmlspecialchars($patient['occupation']); ?></p>
</div>
<?php endif; ?>
<?php if (!empty($patient['medical_history'])): ?>
<div class="col-span-1 md:col-span-2 flex flex-col gap-1">
<label class="text-xs font-bold text-slate-400 uppercase tracking-wider">Medical History & Alerts</label>
<p class="text-sm text-[#151118] leading-relaxed"><?php echo nl2br(htmlspecialchars($patient['medical_history'])); ?></p>
</div>
<?php endif; ?>
</div>
</div>
</section>
<section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
<div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
<h3 class="font-bold text-lg text-royal-purple flex items-center gap-2">
<span class="material-symbols-outlined">contact_phone</span>
                                    Contact Information
                                </h3>
<button class="text-xs font-bold text-royal-purple hover:underline">Update Contact</button>
</div>
<div class="p-0">
<table class="w-full text-left">
<tbody class="divide-y divide-gray-100">
<tr class="hover:bg-slate-50/50 transition-colors">
<td class="px-6 py-5 w-1/3 align-top">
<div class="flex items-center gap-2 text-slate-500">
<span class="material-symbols-outlined text-lg">badge</span>
<span class="text-sm font-bold">Username</span>
</div>
</td>
<td class="px-6 py-5">
<p class="text-lg font-bold text-royal-purple font-mono tracking-tight"><?php echo htmlspecialchars($patient['username'] ?? 'N/A'); ?></p>
</td>
</tr>
<tr class="hover:bg-slate-50/50 transition-colors">
<td class="px-6 py-5 w-1/3 align-top">
<div class="flex items-center gap-2 text-slate-500">
<span class="material-symbols-outlined text-lg">call</span>
<span class="text-sm font-bold">Phone Number</span>
</div>
</td>
<td class="px-6 py-5">
<p class="text-lg font-bold text-[#151118] font-mono tracking-tight"><?php echo htmlspecialchars($patient['phone_number']); ?></p>
<p class="text-xs text-green-600 mt-1 flex items-center gap-1 font-bold">
<span class="material-symbols-outlined text-sm">check_circle</span>
                                                    Primary - SMS Enabled
                                                </p>
</td>
</tr>
<?php if (!empty($patient['email'])): ?>
<tr class="hover:bg-slate-50/50 transition-colors">
<td class="px-6 py-5 w-1/3 align-top">
<div class="flex items-center gap-2 text-slate-500">
<span class="material-symbols-outlined text-lg">mail</span>
<span class="text-sm font-bold">Email Address</span>
</div>
</td>
<td class="px-6 py-5">
<p class="text-lg font-bold text-[#151118]"><?php echo htmlspecialchars($patient['email']); ?></p>
<p class="text-xs text-slate-400 mt-1">Used for invoicing and reminders</p>
</td>
</tr>
<?php endif; ?>
<?php if (!empty($patient['address'])): ?>
<tr class="hover:bg-slate-50/50 transition-colors">
<td class="px-6 py-5 w-1/3 align-top">
<div class="flex items-center gap-2 text-slate-500">
<span class="material-symbols-outlined text-lg">home_pin</span>
<span class="text-sm font-bold">Home Address</span>
</div>
</td>
<td class="px-6 py-5">
<p class="text-lg font-bold text-[#151118] leading-snug">
<?php echo nl2br(htmlspecialchars($patient['address'])); ?>
</p>
</td>
</tr>
<?php endif; ?>
</tbody>
</table>
</div>
</section>

<?php if (!empty($patient_installments)): ?>
<section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
        <h3 class="font-bold text-lg text-royal-purple flex items-center gap-2">
            <span class="material-symbols-outlined">credit_score</span>
            Installment Plans
        </h3>
        <a href="receptionist_installments.php?search=<?php echo urlencode($patient['first_name'] . ' ' . $patient['last_name']); ?>" class="text-xs font-bold text-royal-purple hover:underline">Manage</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="bg-gray-50 border-b border-gray-100 text-slate-500 font-bold text-xs uppercase">
                <tr>
                    <th class="px-6 py-3">Service</th>
                    <th class="px-6 py-3">Balance</th>
                    <th class="px-6 py-3">Next Due</th>
                    <th class="px-6 py-3 text-right">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach($patient_installments as $inst): 
                    $progress = ($inst['total_amount'] > 0) ? (1 - ($inst['balance_remaining'] / $inst['total_amount'])) * 100 : 0;
                    $is_overdue = ($inst['status'] == 'Active' && strtotime($inst['next_payment_date']) < time());
                ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-6 py-3 font-medium">
                        <?php echo htmlspecialchars($inst['service_name']); ?>
                        <div class="text-xs text-slate-400 mt-1">Total: ₱<?php echo number_format($inst['total_amount'], 2); ?></div>
                    </td>
                    <td class="px-6 py-3">
                        <div class="font-bold text-[#151118]">₱<?php echo number_format($inst['balance_remaining'], 2); ?></div>
                        <div class="w-24 h-1.5 bg-gray-100 rounded-full mt-1 overflow-hidden">
                            <div class="h-full bg-clinic-gold rounded-full" style="width: <?php echo $progress; ?>%"></div>
                        </div>
                    </td>
                    <td class="px-6 py-3 <?php echo $is_overdue ? 'text-red-500 font-bold' : 'text-slate-600'; ?>">
                         <?php echo ($inst['status'] == 'Completed') ? '-' : date('M d, Y', strtotime($inst['next_payment_date'])); ?>
                         <?php if ($is_overdue) echo '<span class="material-symbols-outlined text-[14px] align-middle">warning</span>'; ?>
                    </td>
                    <td class="px-6 py-3 text-right">
                         <?php 
                            $status_colors = [
                                'Active' => 'bg-blue-50 text-blue-600',
                                'Completed' => 'bg-green-50 text-green-600',
                                'Pending' => 'bg-yellow-50 text-yellow-600',
                                'Defaulted' => 'bg-red-50 text-red-600',
                                'Cancelled' => 'bg-gray-50 text-gray-500'
                            ];
                            $badge_color = $status_colors[$inst['status']] ?? 'bg-gray-50 text-gray-600';
                         ?>
                         <span class="px-2 py-1 rounded-full text-xs font-bold <?php echo $badge_color; ?>">
                            <?php echo $inst['status']; ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php endif; ?>
</div>
<div class="flex flex-col gap-6">
<?php if ($next_appointment): 
    $appt_date = new DateTime($next_appointment['appointment_date']);
    $appt_time = new DateTime($next_appointment['time_slot']);
    $today = new DateTime();
    $is_today = $appt_date->format('Y-m-d') === $today->format('Y-m-d');
?>
<div class="bg-gradient-to-br from-green-50 to-white rounded-2xl p-6 shadow-sm border border-green-100 relative overflow-hidden">
<div class="absolute right-0 top-0 w-24 h-24 bg-green-100 rounded-bl-full -mr-4 -mt-4 opacity-50"></div>
<h3 class="text-sm font-bold text-slate-500 uppercase tracking-wide mb-3">Next Appointment</h3>
<div class="flex items-start gap-4 mb-4">
<div class="w-12 h-12 rounded-xl bg-white border border-green-200 flex flex-col items-center justify-center text-green-700 shadow-sm">
<span class="text-xs font-bold uppercase"><?php echo $appt_date->format('M'); ?></span>
<span class="text-xl font-black leading-none"><?php echo $appt_date->format('d'); ?></span>
</div>
<div>
<p class="text-xl font-bold text-royal-purple"><?php echo $appt_time->format('g:i A'); ?></p>
<p class="text-sm font-medium text-slate-600"><?php echo htmlspecialchars($next_appointment['service_name']); ?></p>
</div>
</div>
<div class="flex items-center gap-2 text-sm text-slate-600 bg-white/60 p-2 rounded-lg border border-green-100/50">
<span class="material-symbols-outlined text-royal-purple text-lg">stethoscope</span>
                                With <span class="font-bold"><?php echo htmlspecialchars($next_appointment['doctor_name']); ?></span>
</div>
<?php if ($is_today): ?>
<button class="w-full mt-4 py-2 bg-green-600 hover:bg-green-700 text-white font-bold rounded-lg shadow-md shadow-green-200 transition-all text-sm">
                                Check In Now
                            </button>
<?php endif; ?>
</div>
<?php else: ?>
<div class="bg-gradient-to-br from-slate-50 to-white rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
<h3 class="text-sm font-bold text-slate-500 uppercase tracking-wide mb-3">Next Appointment</h3>
<p class="text-slate-400 text-sm">No upcoming appointments scheduled</p>
<a href="receptionist_booking.php?patient_id=<?php echo $patient_id; ?>" class="w-full mt-4 py-2 bg-royal-purple hover:bg-purple-800 text-white font-bold rounded-lg shadow-md transition-all text-sm flex items-center justify-center gap-2">
<span class="material-symbols-outlined text-sm">event_upcoming</span>
                                Schedule Appointment
                            </a>
</div>
<?php endif; ?>
<?php if (!empty($recent_appointments)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
<div class="flex justify-between items-center mb-4">
<h3 class="font-bold text-[#151118]">Recent Appointments</h3>
<a href="receptionist_booking.php?patient_id=<?php echo $patient_id; ?>" class="text-xs font-bold text-royal-purple hover:underline">View All</a>
</div>
<div class="flex flex-col gap-3">
<?php foreach ($recent_appointments as $appt): 
    $appt_date_obj = new DateTime($appt['appointment_date']);
    $appt_time_obj = new DateTime($appt['time_slot']);
    $status_colors = [
        'Pending' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
        'Booked' => 'bg-blue-100 text-blue-700 border-blue-200',
        'Completed' => 'bg-green-100 text-green-700 border-green-200',
        'Cancelled' => 'bg-red-100 text-red-700 border-red-200',
        'No-Show' => 'bg-gray-100 text-gray-700 border-gray-200'
    ];
    $status_color = $status_colors[$appt['status']] ?? 'bg-gray-100 text-gray-700 border-gray-200';
?>
<div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
<div class="flex justify-between items-start mb-2">
<div>
<p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($appt['service_name']); ?></p>
<p class="text-xs text-slate-500"><?php echo $appt_date_obj->format('M d, Y') . ' at ' . $appt_time_obj->format('g:i A'); ?></p>
</div>
<span class="px-2 py-1 rounded text-xs font-bold border <?php echo $status_color; ?>"><?php echo $appt['status']; ?></span>
</div>
<p class="text-xs text-slate-600">With <?php echo htmlspecialchars($appt['doctor_name']); ?></p>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<?php if (!empty($medical_records)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
<div class="flex justify-between items-center mb-4">
<h3 class="font-bold text-[#151118]">Medical Records</h3>
<a href="#" class="text-xs font-bold text-royal-purple hover:underline">View All</a>
</div>
<div class="flex flex-col gap-3">
<?php foreach ($medical_records as $record): 
    $record_date = new DateTime($record['date_of_treatment']);
?>
<div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
<div class="flex justify-between items-start mb-2">
<div>
<p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($record['service_name']); ?></p>
<p class="text-xs text-slate-500"><?php echo $record_date->format('M d, Y'); ?></p>
</div>
</div>
<?php if (!empty($record['diagnosis'])): ?>
<p class="text-xs text-slate-600 mb-1"><strong>Diagnosis:</strong> <?php echo htmlspecialchars($record['diagnosis']); ?></p>
<?php endif; ?>
<?php if (!empty($record['treatment_notes'])): ?>
<p class="text-xs text-slate-600"><strong>Notes:</strong> <?php echo htmlspecialchars(substr($record['treatment_notes'], 0, 100)) . (strlen($record['treatment_notes']) > 100 ? '...' : ''); ?></p>
<?php endif; ?>
<p class="text-xs text-slate-500 mt-1">By <?php echo htmlspecialchars($record['doctor_name']); ?></p>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
</main>
</div>


<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>