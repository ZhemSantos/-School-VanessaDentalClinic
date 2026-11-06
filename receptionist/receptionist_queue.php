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

// Get date filter (default to today)
$selected_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
try {
    $date_obj = new DateTime($selected_date);
} catch (Exception $e) {
    $date_obj = new DateTime();
    $selected_date = $date_obj->format('Y-m-d');
}

// Get status filter
$selected_status = isset($_GET['status']) ? sanitizeInput($_GET['status']) : 'all';

// Build query for queue items
$where_conditions = ["a.appointment_date = ?"];
$params = [$selected_date];

if ($selected_status !== 'all') {
    $where_conditions[] = "a.status = ?";
    $params[] = $selected_status;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

// Fetch live queue (today's appointments)
$queue_items = [];
try {
    $queue_stmt = $pdo->prepare("
        SELECT a.appointment_id, a.time_slot, a.status, 
               p.patient_id, p.first_name, p.last_name,
               u.full_name as doctor_name,
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
    $queue_stmt->execute($params);
    $queue_items = $queue_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching queue: ' . $e->getMessage());
}

// Get statistics
$stats = [
    'total' => 0,
    'pending' => 0,
    'booked' => 0,
    'completed' => 0
];

try {
    $stats_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'Booked' THEN 1 ELSE 0 END) as booked,
            SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed
        FROM tbl_appointment
        WHERE appointment_date = ?
    ");
    $stats_stmt->execute([$selected_date]);
    $stats_row = $stats_stmt->fetch();
    $stats['total'] = $stats_row['total'] ?? 0;
    $stats['pending'] = $stats_row['pending'] ?? 0;
    $stats['booked'] = $stats_row['booked'] ?? 0;
    $stats['completed'] = $stats_row['completed'] ?? 0;
} catch (PDOException $e) {
    error_log('Error fetching stats: ' . $e->getMessage());
}

// Handle appointment status updates
$action_message = '';
$action_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_status'])) {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        $new_status = sanitizeInput($_POST['new_status'] ?? '');
        
        if ($appointment_id > 0 && in_array($new_status, ['Pending', 'Booked', 'Completed', 'Cancelled'])) {
            try {
                $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = ? WHERE appointment_id = ?");
                $update_stmt->execute([$new_status, $appointment_id]);
                $action_message = 'Appointment status updated successfully!';
                $action_type = 'success';
                // Refresh page to show updated status
                header("Location: receptionist_queue.php?date=" . $selected_date . "&status=" . urlencode($selected_status));
                exit;
            } catch (PDOException $e) {
                error_log('Error updating appointment: ' . $e->getMessage());
                $action_message = 'Failed to update appointment status.';
                $action_type = 'error';
            }
        }
    }
}

$date_display = $date_obj->format('l, M d, Y');
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Queue Monitor - Receptionist Portal</title>
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
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="patient_records.php">
<span class="material-symbols-outlined">folder_shared</span>
<span class="<?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'font-bold' : 'font-medium'; ?>">Patient Records</span>
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
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Queue Monitor</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">schedule</span>
                    <?php echo $date_display; ?> • <?php echo date('g:i A'); ?>
                </p>
</div>
<div class="flex items-center gap-4">
<label class="relative hidden lg:flex items-center h-12 w-80">
<div class="absolute left-4 text-slate-400 flex items-center justify-center">
<span class="material-symbols-outlined">search</span>
</div>
<input class="w-full h-full pl-12 pr-4 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400 shadow-sm" placeholder="Search patient, doctor..."/>
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
<?php if ($action_message): ?>
<div class="bg-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-50 border border-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-200 rounded-xl p-4 mb-6">
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-600"><?php echo $action_type === 'success' ? 'check_circle' : 'error'; ?></span>
<p class="text-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-800 font-medium"><?php echo htmlspecialchars($action_message); ?></p>
</div>
</div>
<?php endif; ?>
<!-- Filters and Date Navigation -->
<form method="GET" action="" class="flex flex-col lg:flex-row justify-between items-center bg-white p-4 rounded-2xl shadow-sm border border-gray-100 gap-4">
<div class="flex items-center gap-4 w-full lg:w-auto">
<div class="flex items-center bg-background-light rounded-xl p-1 border border-gray-200">
<a href="?date=<?php echo date('Y-m-d', strtotime($selected_date . ' -1 day')); ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white hover:shadow-sm text-slate-500 transition-all">
<span class="material-symbols-outlined text-lg">chevron_left</span>
</a>
<input type="date" name="date" value="<?php echo $selected_date; ?>" class="px-4 font-bold text-royal-purple text-sm bg-transparent border-none outline-none" onchange="this.form.submit()"/>
<a href="?date=<?php echo date('Y-m-d', strtotime($selected_date . ' +1 day')); ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="w-9 h-9 flex items-center justify-center rounded-lg hover:bg-white hover:shadow-sm text-slate-500 transition-all">
<span class="material-symbols-outlined text-lg">chevron_right</span>
</a>
</div>
<a href="?date=<?php echo date('Y-m-d'); ?><?php echo $selected_status !== 'all' ? '&status=' . urlencode($selected_status) : ''; ?>" class="text-sm font-bold text-royal-purple underline decoration-dotted hover:text-purple-800">Today</a>
</div>
<div class="flex gap-3 w-full lg:w-auto overflow-x-auto">
<select name="status" class="form-select bg-gray-50 border-gray-200 text-sm font-medium rounded-xl focus:ring-royal-purple focus:border-royal-purple py-2.5 pl-4 pr-10" onchange="this.form.submit()">
<option value="all" <?php echo $selected_status === 'all' ? 'selected' : ''; ?>>All Statuses</option>
<option value="Pending" <?php echo $selected_status === 'Pending' ? 'selected' : ''; ?>>Pending</option>
<option value="Booked" <?php echo $selected_status === 'Booked' ? 'selected' : ''; ?>>Booked</option>
<option value="Arrived" <?php echo $selected_status === 'Arrived' ? 'selected' : ''; ?>>Arrived</option>
<option value="Completed" <?php echo $selected_status === 'Completed' ? 'selected' : ''; ?>>Completed</option>
<option value="Cancelled" <?php echo $selected_status === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
</select>
</div>
</form>
<!-- Statistics Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 gap-4">
<div class="bg-white p-4 rounded-xl border border-gray-100 shadow-sm flex items-center gap-4">
<div class="w-10 h-10 rounded-full bg-purple-100 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined">queue</span>
</div>
<div>
<p class="text-xs text-slate-500 font-bold uppercase tracking-wide">Total Queue</p>
<p class="text-xl font-black text-[#151118]"><?php echo $stats['total']; ?></p>
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
<div class="w-10 h-10 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center">
<span class="material-symbols-outlined">done_all</span>
</div>
<div>
<p class="text-xs text-slate-500 font-bold uppercase tracking-wide">Completed</p>
<p class="text-xl font-black text-[#151118]"><?php echo $stats['completed']; ?></p>
</div>
</div>
</div>
<!-- Queue Table -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
<div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
<div class="flex items-center gap-2">
<div class="h-2 w-2 rounded-full bg-green-500 animate-pulse"></div>
<h3 class="font-bold text-lg text-royal-purple">Live Queue</h3>
</div>
</div>
<div class="overflow-x-auto flex-1">
<table class="w-full text-left text-sm whitespace-nowrap">
<thead class="bg-gray-50 text-slate-500 font-medium border-b border-gray-100">
<tr>
<th class="px-6 py-4">Time</th>
<th class="px-6 py-4">Patient Name</th>
<th class="px-6 py-4">Service</th>
<th class="px-6 py-4">Assigned Dentist</th>
<th class="px-6 py-4">Status</th>
<th class="px-6 py-4 text-right">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php if (empty($queue_items)): ?>
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
<?php 
foreach ($queue_items as $item): 
    $patient_initials = strtoupper(substr($item['first_name'], 0, 1) . substr($item['last_name'], 0, 1));
    $patient_name = htmlspecialchars($item['first_name'] . ' ' . $item['last_name']);
    $patient_id_display = 'P-' . str_pad($item['patient_id'], 4, '0', STR_PAD_LEFT);
    $status_colors = [
        'Pending' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'dot' => 'bg-yellow-600'],
        'Booked' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'dot' => 'bg-green-500'],
        'Arrived' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'dot' => 'bg-emerald-500'],
        'Confirmed' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'dot' => 'bg-green-500'],
        'In-Procedure' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'dot' => 'bg-blue-500'],
        'On-Hold' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'dot' => 'bg-orange-500'],
        'Completed' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'dot' => ''],
        'Cancelled' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-500', 'dot' => 'bg-gray-400'],
    ];
    $status_style = $status_colors[$item['status']] ?? $status_colors['Pending'];
    $time = date('g:i A', strtotime($item['time_slot']));
?>
<tr class="hover:bg-gray-50/50 transition-colors group <?php echo $item['status'] === 'Pending' ? 'bg-yellow-50/30 border-l-4 border-l-clinic-gold' : ''; ?>">
<td class="px-6 py-4 font-mono font-bold text-royal-purple"><?php echo $time; ?></td>
<td class="px-6 py-4">
<div class="flex items-center gap-3">
<div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs"><?php echo $patient_initials; ?></div>
<div>
<span class="font-bold text-[#151118] block"><?php echo $patient_name; ?></span>
<span class="text-xs text-slate-400"><?php echo $patient_id_display; ?></span>
</div>
</div>
</td>
<td class="px-6 py-4 text-slate-600"><?php echo htmlspecialchars($item['service_name']); ?></td>
<td class="px-6 py-4">
<span class="text-xs font-bold text-slate-600"><?php echo htmlspecialchars($item['doctor_name']); ?></span>
</td>
<td class="px-6 py-4">
<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold <?php echo $status_style['bg'] . ' ' . $status_style['text']; ?>">
<?php if ($item['status'] === 'Booked' || $item['status'] === 'Confirmed' || $item['status'] === 'In-Procedure' || $item['status'] === 'On-Hold'): ?>
<span class="w-1.5 h-1.5 rounded-full <?php echo $status_style['dot']; ?>"></span>
<?php elseif ($item['status'] === 'Pending'): ?>
<span class="material-symbols-outlined text-[14px]">hourglass_empty</span>
<?php endif; ?>
<?php echo htmlspecialchars($item['status'] === 'On-Hold' ? 'Procedure On-Hold' : $item['status']); ?>
</span>
</td>
<td class="px-6 py-4 text-right">
<div class="flex justify-end gap-2">
<?php if ($item['status'] === 'Pending'): ?>
<form method="POST" action="" class="inline-block">
<input type="hidden" name="appointment_id" value="<?php echo $item['appointment_id']; ?>">
<input type="hidden" name="new_status" value="Booked">
<button type="submit" name="update_status" class="text-royal-purple bg-purple-50 hover:bg-purple-100 px-3 py-1.5 rounded-lg text-xs font-bold border border-transparent">Confirm</button>
</form>
<?php endif; ?>
<a href="patient_information.php?id=<?php echo $item['patient_id']; ?>" class="p-2 hover:bg-gray-100 rounded-lg text-slate-400 hover:text-royal-purple transition-colors inline-block" title="View Patient">
<span class="material-symbols-outlined text-[18px]">visibility</span>
</a>
<a href="receptionist_booking.php?date=<?php echo $selected_date; ?>" class="p-2 hover:bg-gray-100 rounded-lg text-slate-400 hover:text-royal-purple transition-colors inline-block" title="View Booking">
<span class="material-symbols-outlined text-[18px]">calendar_month</span>
</a>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
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
        const path = window.location.pathname.replace('/receptionist/receptionist_queue.php', '/check-in.php');
        const fullUrl = `${protocol}//${host}${path}`; // e.g., http://localhost/check-in.php
        
        // Generate QR
        const qrApi = `https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=${encodeURIComponent(fullUrl)}`;
        
        document.getElementById('qrImage').src = qrApi;
        document.getElementById('qrLinkDisplay').textContent = fullUrl;
        document.getElementById('qrDownload').href = qrApi;
        
        document.getElementById('qrModal').classList.remove('hidden');
    }
</script>


<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>

