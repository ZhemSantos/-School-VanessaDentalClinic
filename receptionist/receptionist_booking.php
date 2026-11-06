<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/auto_cancel.php'; // Run auto-cancellation logic

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

// Handle appointment actions
$action_message = '';
$action_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Removed confirm_appointment handler - appointments can only be booked after payment
    if (isset($_POST['cancel_appointment'])) {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        if ($appointment_id > 0) {
            try {
                $pdo->beginTransaction();
                
                // Get appointment details before updating for email
                $appt_stmt = $pdo->prepare("
                    SELECT a.*, p.first_name, p.last_name, p.email,
                           GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
                           u.full_name as doctor_name
                    FROM tbl_appointment a
                    INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                    INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                    INNER JOIN tbl_service s ON tas.service_id = s.service_id
                    INNER JOIN tbl_user u ON a.doctor_id = u.user_id
                    WHERE a.appointment_id = ?
                    GROUP BY a.appointment_id
                ");
                $appt_stmt->execute([$appointment_id]);
                $appointment = $appt_stmt->fetch();
                
                if (!$appointment) {
                    throw new Exception('Appointment not found');
                }
                
                $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = 'Cancelled' WHERE appointment_id = ?");
                $update_stmt->execute([$appointment_id]);
                
                // Send cancellation email
                require_once '../includes/email_notifications.php';
                try {
                    sendAppointmentCancellation(
                        $appointment['appointment_id'],
                        $appointment['patient_id'],
                        $appointment['appointment_date'],
                        $appointment['time_slot'],
                        $appointment['service_name'],
                        'Cancelled by receptionist'
                    );
                } catch (Exception $e) {
                    error_log("Error sending cancellation email: " . $e->getMessage());
                    // Don't fail the cancellation if email fails
                }
                
                $pdo->commit();
                $action_message = 'Appointment cancelled successfully! Cancellation email sent.';
                $action_type = 'success';
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Error cancelling appointment: ' . $e->getMessage());
                $action_message = 'Failed to cancel appointment.';
                $action_type = 'error';
            } catch (Exception $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Error cancelling appointment: ' . $e->getMessage());
                $action_message = 'Failed to cancel appointment.';
                $action_type = 'error';
            }
        }
    }
}

// Get date filter (default to today)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
try {
    $date_obj = new DateTime($selected_date);
} catch (Exception $e) {
    $date_obj = new DateTime();
    $selected_date = $date_obj->format('Y-m-d');
}

// Get patient_id filter if provided
$selected_patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;

// Get doctor filter
$selected_doctor = isset($_GET['doctor']) ? intval($_GET['doctor']) : 0;

// Get status filter
$selected_status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';

// Fetch dentists for filter
$dentists = [];
try {
    $dentist_stmt = $pdo->prepare("SELECT user_id, full_name FROM tbl_user WHERE role = 'Dentist' AND status = 'Active' ORDER BY full_name");
    $dentist_stmt->execute();
    $dentists = $dentist_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching dentists: ' . $e->getMessage());
}

// Build query for appointments
$where_conditions = ["a.appointment_date = ?"];
$params = [$selected_date];

if ($selected_doctor > 0) {
    $where_conditions[] = "a.doctor_id = ?";
    $params[] = $selected_doctor;
}

if ($selected_status !== 'all') {
    $where_conditions[] = "a.status = ?";
    $params[] = $selected_status;
} else {
    // Hide Completed appointments by default to avoid confusion
    $where_conditions[] = "a.status != 'Completed'";
}

if ($selected_patient_id > 0) {
    $where_conditions[] = "a.patient_id = ?";
    $params[] = $selected_patient_id;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch appointments for selected date
$appointments = [];
try {
    $appt_stmt = $pdo->prepare("
        SELECT a.*, 
               p.patient_id, p.first_name, p.last_name,
               u.full_name as doctor_name, u.profile_picture as doctor_photo,
               GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
        FROM tbl_appointment a
        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        INNER JOIN tbl_service s ON tas.service_id = s.service_id
        $where_clause
        GROUP BY a.appointment_id
        ORDER BY a.time_slot ASC
    ");
    $appt_stmt->execute($params);
    $appointments = $appt_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching appointments: ' . $e->getMessage());
}

// Get statistics for selected date
$stats = [
    'scheduled' => 0,
    'booked' => 0,
    'pending' => 0
];

try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as scheduled,
            SUM(CASE WHEN status = 'Booked' THEN 1 ELSE 0 END) as booked,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending
        FROM tbl_appointment
        WHERE appointment_date = ?
    ");
    $stats_stmt->execute([$selected_date]);
    $stats_row = $stats_stmt->fetch();
    $stats['scheduled'] = $stats_row['scheduled'] ?? 0;
    $stats['booked'] = $stats_row['booked'] ?? 0;
    $stats['pending'] = $stats_row['pending'] ?? 0;
} catch (PDOException $e) {
    error_log('Error fetching stats: ' . $e->getMessage());
}

// Format date for display
$date_display = $date_obj->format('M d, Y');
$current_time = date('H:i');
$current_date_time = date('Y-m-d H:i');

// Calendar functionality - get month/year for calendar view
$calendar_month = isset($_GET['cal_month']) ? intval($_GET['cal_month']) : (int)date('n');
$calendar_year = isset($_GET['cal_year']) ? intval($_GET['cal_year']) : (int)date('Y');

// Validate month and year
if ($calendar_month < 1 || $calendar_month > 12) {
    $calendar_month = (int)date('n');
}
if ($calendar_year < 2020 || $calendar_year > 2100) {
    $calendar_year = (int)date('Y');
}

// Create calendar date object
$calendar_date = new DateTime();
$calendar_date->setDate($calendar_year, $calendar_month, 1);

// Get first day of month and number of days
$first_day = (int)$calendar_date->format('w'); // 0 = Sunday, 6 = Saturday
$days_in_month = (int)$calendar_date->format('t');
$month_name = $calendar_date->format('F Y');

// Get previous and next month
$prev_month = clone $calendar_date;
$prev_month->modify('-1 month');
$next_month = clone $calendar_date;
$next_month->modify('+1 month');

// Fetch appointment counts for each day in the month
$appointment_counts = [];
try {
    $start_date = $calendar_date->format('Y-m-01');
    $end_date = $calendar_date->format('Y-m-t');
    
    $count_stmt = $pdo->prepare("
        SELECT appointment_date, COUNT(*) as count
        FROM tbl_appointment
        WHERE appointment_date >= ? AND appointment_date <= ?
        GROUP BY appointment_date
    ");
    $count_stmt->execute([$start_date, $end_date]);
    $counts = $count_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($counts as $count) {
        $day = (int)date('j', strtotime($count['appointment_date']));
        $appointment_counts[$day] = (int)$count['count'];
    }
} catch (PDOException $e) {
    error_log('Error fetching appointment counts: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Receptionist Bookings - Vanessa Nicolas Dental</title>
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
<style>.scrollbar-hide::-webkit-scrollbar {
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
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Bookings Manager</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">schedule</span>
                    <?php echo $date_obj->format('l, M d'); ?> • <?php echo date('g:i A'); ?>
                </p>
</div>
<div class="flex items-center gap-4">
<label class="relative hidden lg:flex items-center h-12 w-80">
<div class="absolute left-4 text-slate-400 flex items-center justify-center">
<span class="material-symbols-outlined">search</span>
</div>
<input class="w-full h-full pl-12 pr-4 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400 shadow-sm" placeholder="Search patient, date, ID..."/>
</label>
<button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
<span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full"></span>
<span class="material-symbols-outlined">notifications</span>
</button>
<button onclick="document.getElementById('policyModal').classList.remove('hidden')" class="h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative tooltip-container group transition-all transform active:scale-95" title="View Policies">
<span class="material-symbols-outlined">policy</span>
</button>
<button onclick="openQRModal()" class="h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-royal-purple relative tooltip-container group transition-all transform active:scale-95" title="Show Check-in QR">
<span class="material-symbols-outlined">qr_code_scanner</span>
</button>
<a href="receptionist_registration.php" class="h-12 px-6 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
<span class="material-symbols-outlined">add_circle</span>
<span>Quick Register</span>
</a>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8">
<div class="max-w-[1600px] mx-auto flex flex-col gap-6">
<?php 
// Show success message if redirected from registration
if (isset($_GET['success']) && $_GET['success'] == '1' && isset($_GET['patient_id'])): 
    $patient_id = intval($_GET['patient_id']);
    $patient_id_display = 'P-' . str_pad($patient_id, 4, '0', STR_PAD_LEFT);
?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
    <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-green-600">check_circle</span>
        <p class="text-green-800 font-medium">Patient registered successfully! Patient ID: <?php echo htmlspecialchars($patient_id_display); ?></p>
    </div>
</div>
<?php endif; ?>
<?php 
// Show success message if redirected from appointment creation
if (isset($_GET['success']) && $_GET['success'] == '1' && isset($_GET['appointment_id'])): 
    $appointment_id = intval($_GET['appointment_id']);
?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
    <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-green-600">check_circle</span>
        <p class="text-green-800 font-medium">Appointment created successfully! Appointment ID: #<?php echo $appointment_id; ?></p>
    </div>
</div>
<?php endif; ?>
<?php 
// Show success message if redirected from appointment update
if (isset($_GET['success']) && $_GET['success'] == 'updated' && isset($_GET['appointment_id'])): 
    $appointment_id = intval($_GET['appointment_id']);
?>
<div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
    <div class="flex items-center gap-3">
        <span class="material-symbols-outlined text-green-600">check_circle</span>
        <p class="text-green-800 font-medium">Appointment updated successfully! Appointment ID: #<?php echo $appointment_id; ?></p>
    </div>
</div>
<?php endif; ?>
<?php if ($action_message): ?>
<div class="bg-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-50 border border-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-200 rounded-xl p-4 mb-6">
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-600"><?php echo $action_type === 'success' ? 'check_circle' : 'error'; ?></span>
<p class="text-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-800 font-medium"><?php echo htmlspecialchars($action_message); ?></p>
</div>
</div>
<?php endif; ?>
<form method="GET" action="" class="flex flex-col lg:flex-row justify-between items-center bg-white p-4 rounded-2xl shadow-sm border border-gray-100 gap-4">
<div class="flex items-center gap-4 w-full lg:w-auto">
<div class="flex items-center bg-background-light rounded-xl p-1 border border-gray-200">
<a href="?date=<?php echo date('Y-m-d', strtotime($selected_date . ' -1 day')); ?><?php echo $selected_doctor > 0 ? '&doctor=' . $selected_doctor : ''; ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white hover:shadow-sm text-slate-500 transition-all">
<span class="material-symbols-outlined text-lg">chevron_left</span>
</a>
<input type="date" name="date" value="<?php echo $selected_date; ?>" class="px-4 font-bold text-royal-purple text-sm bg-transparent border-none outline-none" onchange="this.form.submit()"/>
<a href="?date=<?php echo date('Y-m-d', strtotime($selected_date . ' +1 day')); ?><?php echo $selected_doctor > 0 ? '&doctor=' . $selected_doctor : ''; ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white hover:shadow-sm text-slate-500 transition-all">
<span class="material-symbols-outlined text-lg">chevron_right</span>
</a>
</div>
<a href="?date=<?php echo date('Y-m-d'); ?><?php echo $selected_doctor > 0 ? '&doctor=' . $selected_doctor : ''; ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="text-sm font-bold text-royal-purple underline decoration-dotted hover:text-purple-800">Today</a>
</div>
<div class="flex gap-3 w-full lg:w-auto overflow-x-auto">
<select name="doctor" class="form-select bg-gray-50 border-gray-200 text-sm font-medium rounded-xl focus:ring-royal-purple focus:border-royal-purple py-2.5 pl-4 pr-10" onchange="this.form.submit()">
<option value="0">All Doctors</option>
<?php foreach ($dentists as $dentist): ?>
<option value="<?php echo $dentist['user_id']; ?>" <?php echo $selected_doctor == $dentist['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($dentist['full_name']); ?></option>
<?php endforeach; ?>
</select>
<select name="status" class="form-select bg-gray-50 border-gray-200 text-sm font-medium rounded-xl focus:ring-royal-purple focus:border-royal-purple py-2.5 pl-4 pr-10" onchange="this.form.submit()">
                            <option value="all" <?php echo $selected_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="Booked" <?php echo $selected_status === 'Booked' ? 'selected' : ''; ?>>Booked</option>
                            <option value="Arrived" <?php echo $selected_status === 'Arrived' ? 'selected' : ''; ?>>Arrived</option>
                            <option value="Pending" <?php echo $selected_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="In-Procedure" <?php echo $selected_status === 'In-Procedure' ? 'selected' : ''; ?>>In-Procedure</option>
                            <option value="On-Hold" <?php echo $selected_status === 'On-Hold' ? 'selected' : ''; ?>>On-Hold</option>
                            <option value="Cancelled" <?php echo $selected_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                            <option value="Completed" <?php echo $selected_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
                        </select>
                        <button type="button" onclick="openBookingTypeModal()" class="flex items-center gap-2 px-4 py-2 bg-royal-purple text-white rounded-xl text-sm font-bold shadow-md hover:bg-purple-800 transition-colors whitespace-nowrap">
                            <span class="material-symbols-outlined text-lg">calendar_add_on</span>
                            New Booking
                        </button>
</div>
</form>
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
<div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
<div class="w-10 h-10 rounded-full bg-purple-100 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined">calendar_today</span>
</div>
<div>
<p class="text-xs text-slate-500 font-bold uppercase tracking-wide">Scheduled</p>
<p class="text-xl font-black text-[#151118]"><?php echo $stats['scheduled']; ?></p>
</div>
</div>
<div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
<div class="w-10 h-10 rounded-full bg-green-100 text-green-700 flex items-center justify-center">
<span class="material-symbols-outlined">check_circle</span>
</div>
<div>
<p class="text-xs text-slate-500 font-bold uppercase tracking-wide">Booked</p>
<p class="text-xl font-black text-[#151118]"><?php echo $stats['booked']; ?></p>
</div>
</div>
<div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
<div class="w-10 h-10 rounded-full bg-yellow-100 text-yellow-700 flex items-center justify-center">
<span class="material-symbols-outlined">pending</span>
</div>
<div>
<p class="text-xs text-slate-500 font-bold uppercase tracking-wide">Pending</p>
<p class="text-xl font-black text-[#151118]"><?php echo $stats['pending']; ?></p>
</div>
</div>
</div>
<div class="flex flex-col xl:flex-row gap-6 h-full min-h-[500px]">
<div class="flex-1 bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
<div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
<div class="flex items-center gap-2">
<h3 class="font-bold text-lg text-royal-purple">Daily Schedule</h3>
<span class="px-2 py-0.5 bg-gray-200 text-slate-600 text-[10px] font-bold uppercase rounded tracking-wide">List View</span>
</div>
<button class="text-slate-400 hover:text-royal-purple transition-colors">
<span class="material-symbols-outlined">print</span>
</button>
</div>
<div class="overflow-x-auto flex-1">
<table class="w-full text-left text-sm whitespace-nowrap">
<thead class="bg-gray-50 text-slate-500 font-medium border-b border-gray-100">
<tr>
<th class="px-6 py-4">Time</th>
<th class="px-6 py-4">Patient</th>
<th class="px-6 py-4">Treatment</th>
<th class="px-6 py-4">Doctor</th>
<th class="px-6 py-4">Status</th>
<th class="px-6 py-4 text-right">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php if (empty($appointments)): ?>
<tr>
<td colspan="6" class="px-6 py-12 text-center text-slate-500">
<div class="flex flex-col items-center gap-3">
<span class="material-symbols-outlined text-4xl text-slate-300">event_busy</span>
<p class="font-medium">No appointments scheduled for this date</p>
<a href="receptionist_registration.php" class="text-royal-purple hover:underline text-sm">Create a new appointment</a>
</div>
</td>
</tr>
<?php else: ?>
<?php foreach ($appointments as $appt): 
    $time_obj = new DateTime($appt['time_slot']);
    $time_display = $time_obj->format('g:i A');
    $patient_initials = strtoupper(substr($appt['first_name'], 0, 1) . substr($appt['last_name'], 0, 1));
    $patient_name = htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']);
    $patient_id_display = 'P-' . str_pad($appt['patient_id'], 4, '0', STR_PAD_LEFT);
    
    $status_colors = [
        'Pending' => 'bg-yellow-100 text-yellow-800',
        'Pending' => 'bg-yellow-100 text-yellow-800',
        'Booked' => 'bg-green-100 text-green-700',
        'Arrived' => 'bg-emerald-100 text-emerald-700',
        'Confirmed' => 'bg-green-100 text-green-700',
        'In-Procedure' => 'bg-blue-100 text-blue-700',
        'On-Hold' => 'bg-orange-100 text-orange-700',
        'Completed' => 'bg-blue-100 text-blue-700',
        'Cancelled' => 'bg-red-100 text-red-700',
        'No-Show' => 'bg-gray-100 text-gray-700'
    ];
    $status_color = $status_colors[$appt['status']] ?? 'bg-gray-100 text-gray-700';
    $is_pending = $appt['status'] === 'Pending';
    $is_cancelled = $appt['status'] === 'Cancelled';
?>
<tr class="hover:bg-gray-50/50 transition-colors group <?php echo $is_pending ? 'bg-yellow-50/30 border-l-4 border-l-yellow-400' : ''; ?>">
<td class="px-6 py-4 font-mono font-bold text-royal-purple"><?php echo $time_display; ?></td>
<td class="px-6 py-4">
<div class="flex items-center gap-3">
<div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs"><?php echo $patient_initials; ?></div>
<div>
<span class="font-bold text-[#151118] block"><?php echo $patient_name; ?></span>
<span class="text-xs text-slate-400"><?php echo $patient_id_display; ?></span>
</div>
</div>
</td>
<td class="px-6 py-4 text-slate-600"><?php echo htmlspecialchars($appt['service_name']); ?></td>
<td class="px-6 py-4">
<div class="flex items-center gap-2">
<?php if ($appt['doctor_photo'] && file_exists('../' . $appt['doctor_photo'])): ?>
<img class="w-6 h-6 rounded-full object-cover" src="../<?php echo htmlspecialchars($appt['doctor_photo']); ?>" alt="<?php echo htmlspecialchars($appt['doctor_name']); ?>"/>
<?php else: ?>
<div class="w-6 h-6 rounded-full bg-purple-100 text-purple-600 flex items-center justify-center text-xs font-bold"><?php echo strtoupper(substr($appt['doctor_name'], 0, 2)); ?></div>
<?php endif; ?>
<span class="text-xs font-bold text-slate-600"><?php echo htmlspecialchars($appt['doctor_name']); ?></span>
</div>
</td>
<td class="px-6 py-4">
<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold <?php echo $status_color; ?>">
<?php if ($appt['status'] === 'Booked' || $appt['status'] === 'Confirmed'): ?>
<span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
<?php elseif ($appt['status'] === 'Pending'): ?>
<span class="material-symbols-outlined text-[14px]">hourglass_empty</span>
<?php endif; ?>
<?php echo $appt['status']; ?>
</span>
</td>
<td class="px-6 py-4 text-right">
<?php if ($is_pending): ?>
<form method="POST" action="" class="inline-block mr-2">
<input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
<a href="receptionist_process_payment.php" class="text-white bg-royal-purple hover:bg-purple-800 px-3 py-1.5 rounded-lg text-xs font-bold inline-block shadow-md">Pay</a>
</form>
<?php endif; ?>
<?php if (!$is_cancelled): ?>
<?php 
    $edit_page = ($appt['appointment_type'] === 'Walk-in') ? 'receptionist_edit_walkin.php' : 'receptionist_edit_appointment.php';
?>
<a href="<?php echo $edit_page; ?>?id=<?php echo $appt['appointment_id']; ?>" class="p-2 hover:bg-gray-100 rounded-lg text-slate-400 hover:text-royal-purple transition-colors inline-block" title="Edit">
<span class="material-symbols-outlined text-[18px]">edit</span>
</a>
<form method="POST" action="" class="inline-block cancel-appointment-form" data-appointment-id="<?php echo $appt['appointment_id']; ?>" id="cancelForm<?php echo $appt['appointment_id']; ?>">
<input type="hidden" name="appointment_id" value="<?php echo $appt['appointment_id']; ?>">
<input type="hidden" name="cancel_appointment" value="1">
<button type="button" onclick="confirmCancelAppointment(<?php echo $appt['appointment_id']; ?>)" class="p-2 hover:bg-gray-100 rounded-lg text-slate-400 hover:text-red-500 transition-colors" title="Cancel">
<span class="material-symbols-outlined text-[18px]">cancel</span>
</button>
</form>
<?php endif; ?>
<a href="patient_information.php?id=<?php echo $appt['patient_id']; ?>" class="p-2 hover:bg-gray-100 rounded-lg text-slate-400 hover:text-royal-purple transition-colors inline-block" title="View Patient">
<span class="material-symbols-outlined text-[18px]">visibility</span>
</a>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<div class="w-full xl:w-96 flex flex-col gap-6">
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
<div class="flex items-center justify-between mb-4">
<h3 class="font-bold text-royal-purple"><?php echo $month_name; ?></h3>
<div class="flex gap-1">
<a href="?date=<?php echo $selected_date; ?>&cal_month=<?php echo $prev_month->format('n'); ?>&cal_year=<?php echo $prev_month->format('Y'); ?><?php echo $selected_doctor > 0 ? '&doctor=' . $selected_doctor : ''; ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="w-6 h-6 flex items-center justify-center text-slate-400 hover:text-royal-purple rounded-full hover:bg-purple-50 transition-colors"><span class="material-symbols-outlined text-sm">chevron_left</span></a>
<a href="?date=<?php echo $selected_date; ?>&cal_month=<?php echo $next_month->format('n'); ?>&cal_year=<?php echo $next_month->format('Y'); ?><?php echo $selected_doctor > 0 ? '&doctor=' . $selected_doctor : ''; ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="w-6 h-6 flex items-center justify-center text-slate-400 hover:text-royal-purple rounded-full hover:bg-purple-50 transition-colors"><span class="material-symbols-outlined text-sm">chevron_right</span></a>
</div>
</div>
<div class="grid grid-cols-7 gap-1 text-center text-xs mb-2 text-slate-400 font-medium">
<div>Su</div><div>Mo</div><div>Tu</div><div>We</div><div>Th</div><div>Fr</div><div>Sa</div>
</div>
<div class="grid grid-cols-7 gap-1 text-center text-sm font-medium">
<?php
// Get previous month's last days for padding
$prev_month_days = (int)$prev_month->format('t');
$start_padding = $first_day;

// Previous month's trailing days
for ($i = $start_padding - 1; $i >= 0; $i--) {
    $day = $prev_month_days - $i;
    echo '<div class="p-2 text-slate-300">' . $day . '</div>';
}

// Current month's days
$today = new DateTime();
$is_current_month = ($calendar_month == (int)date('n')) && ($calendar_year == (int)date('Y'));
$selected_day = (int)date('j', strtotime($selected_date));

for ($day = 1; $day <= $days_in_month; $day++) {
    $date_str = sprintf('%04d-%02d-%02d', $calendar_year, $calendar_month, $day);
    $is_today = $is_current_month && ($day == (int)date('j')) && ($calendar_year == (int)date('Y'));
    $is_selected = ($date_str === $selected_date);
    $appt_count = $appointment_counts[$day] ?? 0;
    
    $classes = 'p-2 rounded-lg hover:bg-gray-50 cursor-pointer transition-colors relative';
    if ($is_selected) {
        $classes .= ' bg-royal-purple text-white shadow-md shadow-purple-200';
    } elseif ($is_today) {
        $classes .= ' bg-purple-50 text-royal-purple border-2 border-royal-purple';
    } else {
        $classes .= ' text-slate-700';
    }
    
    $url_params = '?date=' . $date_str;
    if ($selected_doctor > 0) $url_params .= '&doctor=' . $selected_doctor;
    if ($selected_status !== 'all') $url_params .= '&status=' . urlencode($selected_status);
    if ($calendar_month != (int)date('n') || $calendar_year != (int)date('Y')) {
        $url_params .= '&cal_month=' . $calendar_month . '&cal_year=' . $calendar_year;
    }
    
    echo '<a href="' . $url_params . '" class="' . $classes . '">';
    echo $day;
    if ($appt_count > 0 && !$is_selected) {
        echo '<span class="absolute bottom-1 left-1/2 transform -translate-x-1/2 w-1.5 h-1.5 bg-clinic-gold rounded-full"></span>';
    }
    echo '</a>';
}

// Next month's leading days for padding
$total_cells = $start_padding + $days_in_month;
$remaining_cells = 42 - $total_cells; // 6 rows * 7 days = 42
if ($remaining_cells < 0) $remaining_cells = 0;

for ($day = 1; $day <= $remaining_cells; $day++) {
    echo '<div class="p-2 text-slate-300">' . $day . '</div>';
}
?>
</div>
</div>
<div class="bg-gradient-to-b from-[#151118] to-gray-900 rounded-2xl p-6 text-white shadow-lg relative overflow-hidden">
<div class="flex justify-between items-center mb-4">
<div class="flex items-center gap-2">
<span class="w-2 h-2 bg-clinic-gold rounded-full animate-pulse"></span>
<h3 class="font-bold">Online Requests</h3>
</div>
<span class="bg-white/10 px-2 py-1 rounded text-xs font-bold text-white/80">0 Pending</span>
</div>
<div class="flex flex-col gap-3">
<div class="text-center py-8 text-white/50">
<span class="material-symbols-outlined text-4xl mb-2 block">inbox</span>
<p class="text-sm">No pending requests</p>
</div>
</div>
<div class="mt-4 pt-4 border-t border-white/10 text-center">
<button class="text-xs text-white/60 hover:text-clinic-gold transition-colors">View All Requests</button>
</div>
</div>
</div>
</div>
</div>
</div>
</main>
</div>

<!-- QR Code Modal -->
<div id="qrModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl max-w-sm w-full p-8 text-center relative animate-fade-in-up">
        <button onclick="document.getElementById('qrModal').classList.add('hidden')" class="absolute top-4 right-4 p-2 rounded-full hover:bg-gray-100 text-slate-400 hover:text-slate-600 transition-colors">
            <span class="material-symbols-outlined">close</span>
        </button>
        
        <div class="w-16 h-16 bg-purple-50 rounded-full flex items-center justify-center mx-auto mb-4 text-royal-purple">
            <span class="material-symbols-outlined text-3xl">qr_code_2</span>
        </div>
        
        <h3 class="text-2xl font-black text-royal-purple mb-2">Self Check-In</h3>
        <p class="text-slate-500 text-sm mb-6">Scan this code to arrive instantly.</p>
        
        <div class="bg-white p-4 border border-gray-100 rounded-2xl shadow-inner mb-6 mx-auto w-fit">
            <img id="qrImage" src="" alt="QR Code" class="w-48 h-48 object-contain">
        </div>
        
        <p class="text-xs font-mono text-slate-400 break-all mb-6 bg-gray-50 p-2 rounded-lg" id="qrLinkDisplay">...</p>
        
        <div class="flex gap-2">
            <button onclick="window.print()" class="flex-1 py-3 border border-gray-200 hover:bg-gray-50 text-slate-600 font-bold rounded-xl transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">print</span> Print
            </button>
            <a id="qrDownload" href="#" download="checkin-qr.png" class="flex-1 py-3 bg-royal-purple hover:bg-purple-800 text-white font-bold rounded-xl shadow-lg transition-all flex items-center justify-center gap-2">
                <span class="material-symbols-outlined">download</span> Save
            </a>
        </div>
    </div>
</div>

<!-- Policy Modal -->
<div id="policyModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4 transition-opacity duration-300">
    <div class="bg-white rounded-3xl shadow-2xl max-w-lg w-full p-8 relative animate-fade-in-up">
        <button onclick="document.getElementById('policyModal').classList.add('hidden')" class="absolute top-4 right-4 p-2 rounded-full hover:bg-gray-100 text-slate-400 hover:text-slate-600 transition-colors">
            <span class="material-symbols-outlined">close</span>
        </button>
        
        <div class="flex items-center gap-3 mb-6">
            <div class="w-12 h-12 bg-red-50 rounded-full flex items-center justify-center text-red-600">
                <span class="material-symbols-outlined text-2xl">gavel</span>
            </div>
            <div>
                <h3 class="text-xl font-black text-[#151118]">Clinic Policies</h3>
                <p class="text-slate-500 text-sm">Rules regarding appointments & conduct</p>
            </div>
        </div>
        
        <div class="space-y-4">
            <!-- No-Show Policy -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                <h4 class="font-bold text-[#151118] mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-red-500 text-lg">event_busy</span>
                    No-Show & Fairness Policy
                </h4>
                <div class="text-sm text-slate-600 space-y-2">
                    <p>To ensure fair access to appointments for all patients:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li><span class="font-bold text-slate-700">1st Offense:</span> Warning issued. Booking allowed.</li>
                        <li><span class="font-bold text-slate-700">2nd Offense (Lifetime):</span> If a patient misses an appointment, they are <span class="text-red-600 font-bold">BLOCKED</span> from booking any other appointment for the <span class="font-bold">SAME DAY</span>.</li>
                        <li><span class="italic text-xs">Note: Late arrival > 15 mins is considered a No-Show.</span></li>
                    </ul>
                </div>
            </div>
            
            <!-- Payment Policy -->
            <div class="bg-slate-50 p-4 rounded-xl border border-slate-100">
                <h4 class="font-bold text-[#151118] mb-2 flex items-center gap-2">
                    <span class="material-symbols-outlined text-green-600 text-lg">payments</span>
                    Payment & Installments
                </h4>
                <div class="text-sm text-slate-600 space-y-2">
                    <ul class="list-disc pl-5 space-y-1">
                        <li>Down payments are non-refundable.</li>
                        <li>Installments must be paid by the due date to avoid penalties.</li>
                    </ul>
                </div>
            </div>
        </div>

        <div class="mt-8">
            <button onclick="document.getElementById('policyModal').classList.add('hidden')" class="w-full py-3 bg-[#151118] hover:bg-black text-white font-bold rounded-xl transition-all">
                Understood
            </button>
        </div>
    </div>
</div>

<script>
    function openQRModal() {
        // Detect current host
        const protocol = window.location.protocol;
        const host = window.location.host;
        // Construct path to check-in.php (Assuming it's in root, and we are in /receptionist/)
        // Go up one level from current path
        const path = window.location.pathname.replace('/receptionist/receptionist_booking.php', '/check-in.php');
        const fullUrl = `${protocol}//${host}${path}`; // e.g., http://localhost/check-in.php
        
        // Generate QR
        const qrApi = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(fullUrl)}`;
        
        document.getElementById('qrImage').src = qrApi;
        document.getElementById('qrLinkDisplay').textContent = fullUrl;
        document.getElementById('qrDownload').href = qrApi;
        
        document.getElementById('qrModal').classList.remove('hidden');
    }
</script>

<!-- Custom Popup Modal -->
<div id="customPopup" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-2xl p-6 max-w-md w-[90%] shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="popupContent">
        <div class="flex items-center gap-4 mb-4">
            <div id="popupIcon" class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-2xl"></span>
            </div>
            <h3 id="popupTitle" class="text-lg font-bold text-[#151118] flex-1"></h3>
        </div>
        <p id="popupMessage" class="text-slate-600 mb-6 whitespace-pre-line"></p>
        <div id="popupButtons" class="flex gap-3 justify-end"></div>
    </div>
</div>

<script>
// Custom Popup System
(function() {
    const popup = document.getElementById('customPopup');
    const popupContent = document.getElementById('popupContent');
    const popupIcon = document.getElementById('popupIcon');
    const popupTitle = document.getElementById('popupTitle');
    const popupMessage = document.getElementById('popupMessage');
    const popupButtons = document.getElementById('popupButtons');
    
    let currentResolve = null;
    
    function showPopup(type, title, message) {
        return new Promise((resolve) => {
            currentResolve = resolve;
            
            const iconMap = {
                'alert': { icon: 'info', color: 'bg-purple-100 text-royal-purple' },
                'confirm': { icon: 'help', color: 'bg-yellow-100 text-yellow-700' },
                'success': { icon: 'check_circle', color: 'bg-green-100 text-green-700' },
                'error': { icon: 'error', color: 'bg-red-100 text-red-700' }
            };
            
            const config = iconMap[type] || iconMap['alert'];
            popupIcon.className = `w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ${config.color}`;
            popupIcon.querySelector('span').textContent = config.icon;
            
            popupTitle.textContent = title;
            popupMessage.textContent = message;
            
            popupButtons.innerHTML = '';
            if (type === 'confirm') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'px-4 py-2 rounded-xl bg-gray-100 text-slate-600 hover:bg-gray-200 font-bold text-sm transition-all';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.onclick = () => closePopup(false);
                
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-royal-purple text-white hover:bg-purple-800 font-bold text-sm transition-all shadow-md';
                okBtn.textContent = 'Confirm';
                okBtn.onclick = () => closePopup(true);
                
                popupButtons.appendChild(cancelBtn);
                popupButtons.appendChild(okBtn);
            } else {
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-royal-purple text-white hover:bg-purple-800 font-bold text-sm transition-all shadow-md';
                okBtn.textContent = 'OK';
                okBtn.onclick = () => closePopup(true);
                popupButtons.appendChild(okBtn);
            }
            
            popup.classList.remove('hidden');
            setTimeout(() => {
                popupContent.classList.remove('scale-95', 'opacity-0');
                popupContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        });
    }
    
    function closePopup(result) {
        popupContent.classList.remove('scale-100', 'opacity-100');
        popupContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            popup.classList.add('hidden');
            if (currentResolve) {
                currentResolve(result);
                currentResolve = null;
            }
        }, 300);
    }
    
    popup.addEventListener('click', function(e) {
        if (e.target === popup) {
            closePopup(false);
        }
    });
    
    window.showAlert = (message, title = 'Alert') => showPopup('alert', title, message);
    window.showConfirm = (message, title = 'Confirm') => showPopup('confirm', title, message);
    window.showSuccess = (message, title = 'Success') => showPopup('success', title, message);
    window.showError = (message, title = 'Error') => showPopup('error', title, message);
})();

// Cancel appointment confirmation
window.confirmCancelAppointment = function(appointmentId) {
    showConfirm('Are you sure you want to cancel this appointment?', 'Cancel Appointment').then(confirmed => {
        if (confirmed) {
            const form = document.getElementById(`cancelForm${appointmentId}`);
            if (form) {
                form.submit();
            } else {
                // Fallback to query selector
                const formAlt = document.querySelector(`form.cancel-appointment-form[data-appointment-id="${appointmentId}"]`);
                if (formAlt) {
                    formAlt.submit();
                }
            }
        }
    });
};

// Booking Type Selection Modal
window.openBookingTypeModal = function() {
    const modal = document.createElement('div');
    modal.id = 'bookingTypeModal';
    modal.className = 'fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm';
    modal.innerHTML = `
        <div class="bg-white rounded-2xl p-8 max-w-md w-[90%] shadow-2xl">
            <h3 class="text-2xl font-bold text-royal-purple mb-2">New Booking</h3>
            <p class="text-sm text-slate-500 mb-6">Choose the type of booking you want to create:</p>
            <div class="flex flex-col gap-3">
                <button onclick="window.location.href='receptionist_create_booking.php'; window.closeBookingTypeModal();" class="w-full px-6 py-4 bg-royal-purple hover:bg-purple-800 text-white rounded-xl font-bold text-sm transition-colors flex items-center justify-center gap-3">
                    <span class="material-symbols-outlined">calendar_month</span>
                    <div class="text-left">
                        <div class="font-bold">Appointment</div>
                        <div class="text-xs text-white/70 font-normal">Scheduled with date and time</div>
                    </div>
                </button>
                <button onclick="window.location.href='receptionist_create_walkin.php'; window.closeBookingTypeModal();" class="w-full px-6 py-4 bg-orange-500 hover:bg-orange-600 text-white rounded-xl font-bold text-sm transition-colors flex items-center justify-center gap-3">
                    <span class="material-symbols-outlined">directions_walk</span>
                    <div class="text-left">
                        <div class="font-bold">Walk-In</div>
                        <div class="text-xs text-white/70 font-normal">Immediate service, auto-booked</div>
                    </div>
                </button>
                <button onclick="closeBookingTypeModal()" class="w-full px-6 py-3 bg-gray-100 hover:bg-gray-200 text-slate-600 rounded-xl font-bold text-sm transition-colors mt-2">
                    Cancel
                </button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            closeBookingTypeModal();
        }
    });
};

window.closeBookingTypeModal = function() {
    const modal = document.getElementById('bookingTypeModal');
    if (modal) {
        modal.remove();
    }
};
</script>


<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>