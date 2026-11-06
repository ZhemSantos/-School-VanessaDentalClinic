<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Dentist role to access this page
requireRole('Dentist');

// Update current user's activity
updateUserActivity();

// Get logged-in user information
$doctor_id = $_SESSION['user_id'] ?? null;
$logged_in_user = [
    'full_name' => $_SESSION['full_name'] ?? 'Dr. User',
    'username' => $_SESSION['username'] ?? 'user'
];

// Get patient ID from URL
$patient_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($patient_id <= 0) {
    header('Location: doctor_patients.php');
    exit;
}

// Verify patient is assigned to this doctor
if ($doctor_id) {
    try {
        $verify_stmt = $pdo->prepare("
            SELECT COUNT(*) as count
            FROM (
                SELECT patient_id FROM tbl_appointment WHERE patient_id = ? AND doctor_id = ?
                UNION
                SELECT patient_id FROM tbl_medical_record WHERE patient_id = ? AND doctor_id = ?
            ) as assigned_patients
        ");
        $verify_stmt->execute([$patient_id, $doctor_id, $patient_id, $doctor_id]);
        $verify_result = $verify_stmt->fetch();
        
        if ($verify_result['count'] == 0) {
            header('Location: doctor_patients.php');
            exit;
        }
    } catch (PDOException $e) {
        error_log('Error verifying patient assignment: ' . $e->getMessage());
        header('Location: doctor_patients.php');
        exit;
    }
}

// Helper function to get default profile photo
function getDefaultProfilePhoto($full_name) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    $colors = [
        ['bg' => '#8a11d4', 'text' => '#ffffff'],
        ['bg' => '#6A1B9A', 'text' => '#ffffff'],
        ['bg' => '#FFD700', 'text' => '#151118'],
        ['bg' => '#10b981', 'text' => '#ffffff'],
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    return ['initials' => $initials, 'bg_color' => $color['bg'], 'text_color' => $color['text']];
}

// Helper function to calculate age from DOB
function calculateAge($dob) {
    if (!$dob) return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y;
}

$current_name = $logged_in_user['full_name'];
$current_profile = null;
if ($doctor_id) {
    try {
        $profile_stmt = $pdo->prepare("SELECT profile_picture FROM tbl_user WHERE user_id = ?");
        $profile_stmt->execute([$doctor_id]);
        $profile_result = $profile_stmt->fetch();
        $current_profile = $profile_result['profile_picture'] ?? null;
    } catch (PDOException $e) {
        error_log('Error fetching profile picture: ' . $e->getMessage());
    }
}
$default_photo = getDefaultProfilePhoto($current_name);

// Get current page for active navigation state
$current_page = basename($_SERVER['PHP_SELF']);

// Fetch patient details
$patient = null;
try {
    $patient_stmt = $pdo->prepare("SELECT * FROM tbl_patient WHERE patient_id = ? LIMIT 1");
    $patient_stmt->execute([$patient_id]);
    $patient = $patient_stmt->fetch();
    
    if (!$patient) {
        header('Location: doctor_patients.php');
        exit;
    }
} catch (PDOException $e) {
    error_log('Error fetching patient: ' . $e->getMessage());
    header('Location: doctor_patients.php');
    exit;
}

// Calculate patient age
$age = calculateAge($patient['dob']);
$initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
$patient_name = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
$patient_id_display = 'P-' . str_pad($patient['patient_id'], 5, '0', STR_PAD_LEFT);

// Format DOB
$dob_date = new DateTime($patient['dob']);
$dob_formatted = $dob_date->format('F d, Y');

// Format registration date
$reg_date = new DateTime($patient['registration_date']);
$reg_formatted = $reg_date->format('M d, Y');

// Fetch next appointment with this doctor
$next_appointment = null;
$current_date = date('Y-m-d');
try {
    $next_appt_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as doctor_name, GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
        FROM tbl_appointment a
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.patient_id = ? 
          AND a.doctor_id = ?
          AND a.appointment_date >= ?
          AND a.status IN ('Pending', 'Booked')
        GROUP BY a.appointment_id
        ORDER BY a.appointment_date ASC, a.time_slot ASC 
        LIMIT 1
    ");
    $next_appt_stmt->execute([$patient_id, $doctor_id, $current_date]);
    $next_appointment = $next_appt_stmt->fetch();
} catch (PDOException $e) {
    error_log('Error fetching next appointment: ' . $e->getMessage());
}

// Fetch recent appointments with this doctor (last 5)
$recent_appointments = [];
try {
    $appt_stmt = $pdo->prepare("
        SELECT a.*, u.full_name as doctor_name, GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
        FROM tbl_appointment a
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.patient_id = ? AND a.doctor_id = ?
        GROUP BY a.appointment_id
        ORDER BY a.appointment_date DESC, a.time_slot DESC
        LIMIT 5
    ");
    $appt_stmt->execute([$patient_id, $doctor_id]);
    $recent_appointments = $appt_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching appointments: ' . $e->getMessage());
}

// Fetch medical records with this doctor (last 5)
$medical_records = [];
try {
    $records_stmt = $pdo->prepare("
        SELECT mr.*, u.full_name as doctor_name, s.service_name
        FROM tbl_medical_record mr
        INNER JOIN tbl_user u ON mr.doctor_id = u.user_id
        INNER JOIN tbl_service s ON mr.service_id = s.service_id
        WHERE mr.patient_id = ? AND mr.doctor_id = ?
        ORDER BY mr.date_of_treatment DESC
        LIMIT 5
    ");
    $records_stmt->execute([$patient_id, $doctor_id]);
    $medical_records = $records_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching medical records: ' . $e->getMessage());
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
<aside class="flex w-72 flex-col border-r border-[#e2dbe6] bg-white h-full hidden lg:flex flex-shrink-0 z-20 shadow-sm">
<div class="flex flex-col h-full justify-between p-6">
<div class="flex flex-col gap-8">
<div class="flex gap-3 items-center">
<div class="bg-center bg-no-repeat bg-cover rounded-xl size-12 shrink-0 shadow-md border-2 border-primary/10" data-alt="Logo showing a stylized tooth with a purple gradient background" style='background-image: url("https://lh3.googleusercontent.com/aida-public/AB6AXuDVLK7T7qRCxOBG-KaXUiV7VW5S5DR2zmQCVv6YRrNOrUS38YGhDstNQjB3IS0WM1pZK9Wo4EHloZ0lx0FQGmj-rZI4gSVFeJTXSjATIJ_tYqqzdo5pdNJ1pksXN6aRO3aeGfXmoEGKDAHJBMYm7CCJnmtPX1pi-uNNh_4RwjGbI6iAxHe6j1e3lNXsLFupAxH4fZU835iEQPEfoQiIO8RNnlF9NY6cD7wJbJ6d_j2M9IrAx20oMjkbfg8QwQadAORe5tubT-S5tKg");'></div>
<div class="flex flex-col">
<h1 class="text-[#151118] text-lg font-bold leading-tight">Vanessa Nicolas</h1>
<p class="text-[#7a6189] text-xs font-medium uppercase tracking-wider">Dental Clinic</p>
</div>
</div>
<nav class="flex flex-col gap-2">
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_dashboard.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">dashboard</span>
<p class="text-sm font-medium leading-normal">Dashboard</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 border-l-4 border-primary shadow-sm group transition-all" href="doctor_patients.php">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform">groups</span>
<p class="text-primary text-sm font-bold leading-normal">My Patients</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_records.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">folder_shared</span>
<p class="text-sm font-medium leading-normal">Medical Records</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_schedule.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">calendar_month</span>
<p class="text-sm font-medium leading-normal">My Schedule</p>
</a>
</nav>
</div>
<div class="flex flex-col gap-4">
<div class="p-4 bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl border border-primary/10">
<div class="flex items-center gap-3 mb-2">
<span class="material-symbols-outlined text-secondary text-2xl">workspace_premium</span>
<p class="text-xs font-bold text-primary uppercase">Staff Portal</p>
</div>
<p class="text-xs text-[#7a6189]">Logged in securely. Session active.</p>
</div>
<a href="../logout.php" class="flex items-center gap-3 px-4 py-2 text-[#7a6189] hover:text-red-500 transition-colors">
<span class="material-symbols-outlined">logout</span>
<span class="text-sm font-medium">Log Out</span>
</a>
</div>
</div>
</aside>
<main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
<header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
<div class="flex flex-col gap-1">
<div class="flex items-center gap-2 text-sm text-slate-500 font-medium mb-1">
<a href="doctor_dashboard.php" class="hover:text-primary cursor-pointer">Dashboard</a>
<span class="material-symbols-outlined text-sm">chevron_right</span>
<a href="doctor_patients.php" class="hover:text-primary cursor-pointer">My Patients</a>
<span class="material-symbols-outlined text-sm">chevron_right</span>
<span class="text-primary font-bold"><?php echo $patient_id_display; ?></span>
</div>
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Patient Record</h2>
</div>
<div class="flex items-center gap-4">
<a href="doctor_current_user.php" class="flex items-center gap-3 pl-6 border-l border-[#f3f0f4] cursor-pointer hover:opacity-80 transition-opacity">
<div class="flex flex-col items-end">
<p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($logged_in_user['full_name']); ?></p>
<p class="text-xs text-[#7a6189]">Dentist</p>
</div>
<?php
if ($current_profile && file_exists('../' . $current_profile)) {
    echo '<div class="bg-center bg-no-repeat bg-cover rounded-full size-10 ring-2 ring-primary/20" style="background-image: url(\'../' . htmlspecialchars($current_profile) . '\');"></div>';
} else {
    echo '<div class="rounded-full size-10 ring-2 ring-primary/20 flex items-center justify-center text-white font-bold" style="background-color: ' . $default_photo['bg_color'] . '; color: ' . $default_photo['text_color'] . ';">' . htmlspecialchars($default_photo['initials']) . '</div>';
}
?>
</a>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8">
<div class="max-w-[1400px] mx-auto flex flex-col gap-6">
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 flex flex-col md:flex-row items-start md:items-center justify-between gap-6 relative overflow-hidden">
<div class="absolute top-0 left-0 w-full h-1 bg-gradient-to-r from-primary via-purple-500 to-secondary"></div>
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
</div>
<div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
<div class="xl:col-span-2 flex flex-col gap-6">
<section class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
<div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
<h3 class="font-bold text-lg text-primary flex items-center gap-2">
<span class="material-symbols-outlined">person</span>
                                    Personal Information
                                </h3>
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
<h3 class="font-bold text-lg text-primary flex items-center gap-2">
<span class="material-symbols-outlined">contact_phone</span>
                                    Contact Information
                                </h3>
</div>
<div class="p-0">
<table class="w-full text-left">
<tbody class="divide-y divide-gray-100">
<tr class="hover:bg-slate-50/50 transition-colors">
<td class="px-6 py-5 w-1/3 align-top">
<div class="flex items-center gap-2 text-slate-500">
<span class="material-symbols-outlined text-lg">call</span>
<span class="text-sm font-bold">Phone Number</span>
</div>
</td>
<td class="px-6 py-5">
<p class="text-lg font-bold text-[#151118] font-mono tracking-tight"><?php echo htmlspecialchars($patient['phone_number']); ?></p>
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
<p class="text-xl font-bold text-primary"><?php echo $appt_time->format('g:i A'); ?></p>
<p class="text-sm font-medium text-slate-600"><?php echo htmlspecialchars($next_appointment['service_name']); ?></p>
</div>
</div>
<div class="flex items-center gap-2 text-sm text-slate-600 bg-white/60 p-2 rounded-lg border border-green-100/50">
<span class="material-symbols-outlined text-primary text-lg">stethoscope</span>
                                With <span class="font-bold"><?php echo htmlspecialchars($next_appointment['doctor_name']); ?></span>
</div>
</div>
<?php else: ?>
<div class="bg-gradient-to-br from-slate-50 to-white rounded-2xl p-6 shadow-sm border border-gray-100 relative overflow-hidden">
<h3 class="text-sm font-bold text-slate-500 uppercase tracking-wide mb-3">Next Appointment</h3>
<p class="text-slate-400 text-sm">No upcoming appointments scheduled</p>
</div>
<?php endif; ?>
<?php if (!empty($recent_appointments)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
<div class="flex justify-between items-center mb-4">
<h3 class="font-bold text-[#151118]">Recent Appointments</h3>
</div>
<div class="flex flex-col gap-3">
<?php foreach ($recent_appointments as $appt): 
    $appt_date_obj = new DateTime($appt['appointment_date']);
    $appt_time_obj = new DateTime($appt['time_slot']);
    $status_colors = [
        'Pending' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
        'Booked' => 'bg-blue-100 text-blue-700 border-blue-200',
        'In-Procedure' => 'bg-purple-100 text-purple-700 border-purple-200',
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
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

<?php if (!empty($medical_records)): ?>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
<div class="flex justify-between items-center mb-4">
<h3 class="font-bold text-[#151118]">Medical Records</h3>
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
<?php if (!empty($record['tooth_numbers'])): ?>
<p class="text-xs text-slate-600"><strong>Teeth:</strong> <?php echo htmlspecialchars($record['tooth_numbers']); ?></p>
<?php endif; ?>
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

</body></html>

