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

// Helper function to calculate age from DOB
function calculateAge($dob) {
    if (!$dob) return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y;
}

// Get search and filter parameters
$search_query = sanitizeInput($_GET['search'] ?? '');
$filter_type = sanitizeInput($_GET['filter_type'] ?? 'all');
$status_filter = sanitizeInput($_GET['status'] ?? 'all');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];

// Search filter
if (!empty($search_query)) {
    switch ($filter_type) {
        case 'name':
            $where_conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
            $search_param = '%' . $search_query . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            break;
        case 'phone':
            $where_conditions[] = "p.phone_number LIKE ?";
            $params[] = '%' . $search_query . '%';
            break;
        case 'email':
            $where_conditions[] = "p.email LIKE ?";
            $params[] = '%' . $search_query . '%';
            break;
        case 'id':
            $where_conditions[] = "p.patient_id = ?";
            $params[] = intval($search_query);
            break;
        default: // 'all'
            $where_conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.phone_number LIKE ? OR p.email LIKE ? OR p.patient_id = ?)";
            $search_param = '%' . $search_query . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = is_numeric($search_query) ? intval($search_query) : 0;
            break;
    }
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "SELECT COUNT(*) FROM tbl_patient p $where_clause";
try {
    $count_stmt = $pdo->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->execute($params);
    } else {
        $count_stmt->execute();
    }
    $total_patients = $count_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Error counting patients: ' . $e->getMessage());
    error_log('Count SQL: ' . $count_sql);
    error_log('Params: ' . print_r($params, true));
    $total_patients = 0;
}

// Get patients with pagination
$patients = [];
$total_pages = ceil($total_patients / $per_page);

try {
    // Build SQL query - LIMIT and OFFSET must be integers, not parameters
    $sql = "
        SELECT p.*,
               (SELECT MAX(date_of_treatment) FROM tbl_medical_record WHERE patient_id = p.patient_id) as last_visit
        FROM tbl_patient p
        $where_clause
        ORDER BY p.registration_date DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get next appointments and payment information for each patient
    foreach ($patients as &$patient) {
        // Get next appointment
        $next_appt_stmt = $pdo->prepare("
            SELECT appointment_date, time_slot 
            FROM tbl_appointment 
            WHERE patient_id = ? 
              AND appointment_date >= CURDATE() 
              AND status IN ('Pending', 'Booked')
            ORDER BY appointment_date ASC, time_slot ASC 
            LIMIT 1
        ");
        $next_appt_stmt->execute([$patient['patient_id']]);
        $next_appt = $next_appt_stmt->fetch();
        $patient['next_appt_date'] = $next_appt['appointment_date'] ?? null;
        $patient['next_appt_time'] = $next_appt['time_slot'] ?? null;
        
        // Get balance due (sum of pending invoices)
        $balance_stmt = $pdo->prepare("
            SELECT COALESCE(SUM(i.total_amount), 0) as balance_due
            FROM tbl_invoice i
            INNER JOIN tbl_medical_record mr ON i.record_id = mr.record_id
            WHERE mr.patient_id = ? AND i.payment_status = 'Pending'
        ");
        $balance_stmt->execute([$patient['patient_id']]);
        $balance_result = $balance_stmt->fetch();
        $patient['balance_due'] = floatval($balance_result['balance_due'] ?? 0);
        
        // Check if patient has any invoices (to determine if Self-Pay or has payment history)
        $invoice_count_stmt = $pdo->prepare("
            SELECT COUNT(*) as invoice_count
            FROM tbl_invoice i
            INNER JOIN tbl_medical_record mr ON i.record_id = mr.record_id
            WHERE mr.patient_id = ?
        ");
        $invoice_count_stmt->execute([$patient['patient_id']]);
        $invoice_count_result = $invoice_count_stmt->fetch();
        $patient['has_invoices'] = intval($invoice_count_result['invoice_count'] ?? 0) > 0;
    }
    unset($patient);
    
} catch (PDOException $e) {
    error_log('Error fetching patients: ' . $e->getMessage());
    error_log('SQL Query: ' . $sql);
    error_log('Params: ' . print_r($params, true));
    $patients = [];
}

// Get statistics
$stats = [
    'total' => 0,
    'new_this_month' => 0
];

try {
    $total_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_patient");
    $total_stmt->execute();
    $stats['total'] = $total_stmt->fetchColumn();
    
    $new_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_patient WHERE MONTH(registration_date) = MONTH(CURDATE()) AND YEAR(registration_date) = YEAR(CURDATE())");
    $new_stmt->execute();
    $stats['new_this_month'] = $new_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Error fetching stats: ' . $e->getMessage());
}
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Patient Records - Vanessa Nicolas Dental</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Patient Records</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">folder_managed</span>
                    Manage patient files, history, and status
                </p>
</div>
<div class="flex items-center gap-4">
<form method="GET" action="" class="relative hidden lg:flex items-center h-12 w-80">
<div class="absolute left-4 text-slate-400 flex items-center justify-center">
<span class="material-symbols-outlined">search</span>
</div>
<input class="w-full h-full pl-12 pr-4 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400 shadow-sm" placeholder="Global search..." name="search" value="<?php echo htmlspecialchars($search_query); ?>" type="text" onkeypress="if(event.key === 'Enter') this.form.submit()"/>
</form>
<button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
<span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full"></span>
<span class="material-symbols-outlined">notifications</span>
</button>
<a href="receptionist_registration.php" class="h-12 px-6 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
<span class="material-symbols-outlined">add_circle</span>
<span>Quick Register</span>
</a>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8">
<div class="max-w-[1600px] mx-auto flex flex-col h-full gap-6">
<div class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
<div class="flex items-center gap-6">
<div class="flex flex-col">
<span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">Total Patients</span>
<span class="text-2xl font-black text-royal-purple"><?php echo number_format($stats['total']); ?></span>
</div>
<div class="h-8 w-px bg-gray-200"></div>
<div class="flex flex-col">
<span class="text-xs text-slate-500 font-semibold uppercase tracking-wider">New This Month</span>
<div class="flex items-center gap-1">
<span class="text-2xl font-black text-[#151118]"><?php echo number_format($stats['new_this_month']); ?></span>
</div>
</div>
</div>
<form method="GET" action="" class="flex flex-wrap items-center gap-3 w-full md:w-auto">
<div class="relative flex-1 md:w-64">
<span class="material-symbols-outlined absolute left-3 top-2.5 text-slate-400 text-[20px]">filter_list</span>
<input class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-royal-purple focus:ring-1 focus:ring-royal-purple/20 transition-all" placeholder="Filter by name, phone, or ID..." name="search" value="<?php echo htmlspecialchars($search_query); ?>" type="text"/>
</div>
<select name="filter_type" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-royal-purple bg-white text-slate-600 font-medium">
<option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All Fields</option>
<option value="name" <?php echo $filter_type === 'name' ? 'selected' : ''; ?>>Name</option>
<option value="phone" <?php echo $filter_type === 'phone' ? 'selected' : ''; ?>>Phone</option>
<option value="email" <?php echo $filter_type === 'email' ? 'selected' : ''; ?>>Email</option>
<option value="id" <?php echo $filter_type === 'id' ? 'selected' : ''; ?>>Patient ID</option>
</select>
<button type="submit" class="flex items-center justify-center px-4 py-2.5 border border-gray-200 rounded-xl text-slate-600 hover:bg-gray-50 hover:text-royal-purple font-bold text-sm transition-colors gap-2">
<span class="material-symbols-outlined text-[18px]">search</span>
<span class="hidden sm:inline">Search</span>
</button>
<?php if (!empty($search_query)): ?>
<a href="patient_records.php" class="flex items-center justify-center px-4 py-2.5 border border-gray-200 rounded-xl text-slate-600 hover:bg-gray-50 hover:text-royal-purple font-bold text-sm transition-colors gap-2">
<span class="material-symbols-outlined text-[18px]">close</span>
<span class="hidden sm:inline">Clear</span>
</a>
<?php endif; ?>
</form>
</div>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 flex-1 flex flex-col overflow-hidden">
<div class="overflow-x-auto flex-1">
<table class="w-full text-left text-sm whitespace-nowrap">
<thead class="bg-gray-50/80 text-slate-500 font-bold border-b border-gray-100 sticky top-0 z-10 backdrop-blur-sm">
<tr>
<th class="px-6 py-4 w-20">
<div class="flex items-center gap-1 cursor-pointer hover:text-royal-purple">
                                            ID <span class="material-symbols-outlined text-[14px]">unfold_more</span>
</div>
</th>
<th class="px-6 py-4">
<div class="flex items-center gap-1 cursor-pointer hover:text-royal-purple">
                                            Patient Name <span class="material-symbols-outlined text-[14px]">sort_by_alpha</span>
</div>
</th>
<th class="px-6 py-4">Contact Details</th>
<th class="px-6 py-4">Age / Gender</th>
<th class="px-6 py-4">Last Visit</th>
<th class="px-6 py-4">Next Appt</th>
<th class="px-6 py-4">Status</th>
<th class="px-6 py-4 text-right">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php if (empty($patients)): ?>
<tr>
<td colspan="8" class="px-6 py-12 text-center text-slate-500">
<div class="flex flex-col items-center gap-3">
<span class="material-symbols-outlined text-4xl text-slate-300">folder_off</span>
<p class="font-medium">No patients found</p>
<?php if (!empty($search_query)): ?>
<p class="text-sm">Try adjusting your search criteria</p>
<?php else: ?>
<p class="text-sm"><a href="receptionist_registration.php" class="text-royal-purple hover:underline">Register a new patient</a></p>
<?php endif; ?>
</div>
</td>
</tr>
<?php else: ?>
<?php foreach ($patients as $patient): 
    $age = calculateAge($patient['dob']);
    $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
    $patient_name = htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']);
    $patient_id_display = 'P-' . str_pad($patient['patient_id'], 4, '0', STR_PAD_LEFT);
    
    // Format last visit
    $last_visit_display = '-';
    if ($patient['last_visit']) {
        $last_visit_date = new DateTime($patient['last_visit']);
        $last_visit_display = $last_visit_date->format('M d, Y');
    }
    
    // Format next appointment
    $next_appt_display = '<span class="text-slate-400 text-xs italic">Not Scheduled</span>';
    if (!empty($patient['next_appt_date'])) {
        $appt_date = new DateTime($patient['next_appt_date']);
        $today = new DateTime();
        $tomorrow = clone $today;
        $tomorrow->modify('+1 day');
        
        if ($appt_date->format('Y-m-d') === $today->format('Y-m-d')) {
            $time_display = $patient['next_appt_time'] ? date('g:i A', strtotime($patient['next_appt_time'])) : '';
            $next_appt_display = '<span class="text-royal-purple font-bold bg-purple-50 px-2 py-1 rounded text-xs">Today' . ($time_display ? ', ' . $time_display : '') . '</span>';
        } elseif ($appt_date->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
            $time_display = $patient['next_appt_time'] ? date('g:i A', strtotime($patient['next_appt_time'])) : '';
            $next_appt_display = '<span class="text-royal-purple font-bold bg-purple-50 px-2 py-1 rounded text-xs">Tomorrow' . ($time_display ? ', ' . $time_display : '') . '</span>';
        } else {
            $next_appt_display = '<span class="text-slate-400 text-xs italic">' . $appt_date->format('M d, Y') . '</span>';
        }
    }
    
    // Determine status (active if has recent visit or upcoming appointment)
    $is_active = !empty($patient['last_visit']) || !empty($patient['next_appt_date']);
    $status_badge = $is_active 
        ? '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700 border border-green-200"><span class="w-1.5 h-1.5 rounded-full bg-green-600 animate-pulse"></span>Active</span>'
        : '<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-slate-500 border border-gray-200">Inactive</span>';
    
    // Determine payment status display
    $payment_status_display = '';
    if ($patient['balance_due'] > 0) {
        $payment_status_display = '<span class="text-xs text-red-400 font-medium">Balance Due: $' . number_format($patient['balance_due'], 2) . '</span>';
    } elseif (!$patient['has_invoices']) {
        $payment_status_display = '<span class="text-xs text-slate-400">Self-Pay</span>';
    }
?>
<tr class="hover:bg-gray-50/50 transition-colors group">
<td class="px-6 py-4 font-mono font-bold text-slate-400">#<?php echo $patient_id_display; ?></td>
<td class="px-6 py-4">
<div class="flex items-center gap-3">
<div class="w-9 h-9 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs ring-2 ring-white shadow-sm"><?php echo $initials; ?></div>
<div class="flex flex-col">
<span class="font-bold text-[#151118] group-hover:text-royal-purple transition-colors"><?php echo $patient_name; ?></span>
<?php if ($payment_status_display): ?>
<?php echo $payment_status_display; ?>
<?php elseif (!empty($patient['email'])): ?>
<span class="text-xs text-slate-400"><?php echo htmlspecialchars($patient['email']); ?></span>
<?php else: ?>
<span class="text-xs text-slate-400">Self-Pay</span>
<?php endif; ?>
</div>
</div>
</td>
<td class="px-6 py-4">
<div class="flex flex-col gap-0.5">
<div class="flex items-center gap-1.5 text-[#151118]">
<span class="material-symbols-outlined text-[14px] text-slate-400">call</span>
<span class="font-medium"><?php echo htmlspecialchars($patient['phone_number']); ?></span>
</div>

<?php if (!empty($patient['email'])): ?>
<div class="flex items-center gap-1.5 text-slate-500 text-xs">
<span class="material-symbols-outlined text-[14px] text-slate-400">mail</span>
<span><?php echo htmlspecialchars($patient['email']); ?></span>
</div>
<?php endif; ?>
</div>
</td>
<td class="px-6 py-4 text-slate-600 font-medium"><?php echo $age; ?> / <?php echo htmlspecialchars($patient['gender'] ?? 'N/A'); ?></td>
<td class="px-6 py-4 text-slate-500"><?php echo $last_visit_display; ?></td>
<td class="px-6 py-4"><?php echo $next_appt_display; ?></td>
<td class="px-6 py-4"><?php echo $status_badge; ?></td>
<td class="px-6 py-4 text-right">
                                            <div class="flex justify-end gap-2">
                                                <button onclick="initiateUpdate(<?php echo $patient['patient_id']; ?>, '<?php echo $patient['email'] ? 'yes' : 'no'; ?>')" class="p-2 text-slate-400 hover:text-royal-purple hover:bg-purple-50 rounded-lg transition-colors" title="Update Info">
                                                    <span class="material-symbols-outlined text-[20px]">edit</span>
                                                </button>
                                                <a href="patient_information.php?id=<?php echo $patient['patient_id']; ?>" class="p-2 text-slate-400 hover:text-royal-purple hover:bg-purple-50 rounded-lg transition-colors" title="View Profile">
                                                    <span class="material-symbols-outlined text-[20px]">visibility</span>
                                                </a>
                                            </div>
                                        </td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<div class="p-4 border-t border-gray-100 flex flex-col sm:flex-row items-center justify-between bg-gray-50/30 gap-4">
<span class="text-sm text-slate-500 font-medium">Showing <span class="text-[#151118] font-bold"><?php echo $total_patients > 0 ? ($offset + 1) : 0; ?>-<?php echo min($offset + $per_page, $total_patients); ?></span> of <span class="text-[#151118] font-bold"><?php echo number_format($total_patients); ?></span> patients</span>
<?php if ($total_pages > 1): ?>
<div class="flex gap-2">
<?php if ($page > 1): ?>
<a href="?page=<?php echo $page - 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) . '&filter_type=' . urlencode($filter_type) : ''; ?>" class="px-3 py-1.5 text-sm font-medium text-slate-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">Previous</a>
<?php else: ?>
<button class="px-3 py-1.5 text-sm font-medium text-slate-400 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 cursor-not-allowed opacity-50" disabled>Previous</button>
<?php endif; ?>

<?php
$start_page = max(1, $page - 2);
$end_page = min($total_pages, $page + 2);

if ($start_page > 1): ?>
<a href="?page=1<?php echo !empty($search_query) ? '&search=' . urlencode($search_query) . '&filter_type=' . urlencode($filter_type) : ''; ?>" class="px-3 py-1.5 text-sm font-medium text-slate-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors">1</a>
<?php if ($start_page > 2): ?>
<span class="px-2 text-slate-400 self-end mb-1">...</span>
<?php endif; ?>
<?php endif; ?>

<?php for ($i = $start_page; $i <= $end_page; $i++): ?>
<?php if ($i == $page): ?>
<button class="px-3 py-1.5 text-sm font-bold text-white bg-royal-purple rounded-lg shadow-md hover:bg-purple-800 transition-colors"><?php echo $i; ?></button>
<?php else: ?>
<a href="?page=<?php echo $i; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) . '&filter_type=' . urlencode($filter_type) : ''; ?>" class="px-3 py-1.5 text-sm font-medium text-slate-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"><?php echo $i; ?></a>
<?php endif; ?>
<?php endfor; ?>

<?php if ($end_page < $total_pages): ?>
<?php if ($end_page < $total_pages - 1): ?>
<span class="px-2 text-slate-400 self-end mb-1">...</span>
<?php endif; ?>
<a href="?page=<?php echo $total_pages; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) . '&filter_type=' . urlencode($filter_type) : ''; ?>" class="px-3 py-1.5 text-sm font-medium text-slate-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors"><?php echo $total_pages; ?></a>
<?php endif; ?>

<?php if ($page < $total_pages): ?>
<a href="?page=<?php echo $page + 1; ?><?php echo !empty($search_query) ? '&search=' . urlencode($search_query) . '&filter_type=' . urlencode($filter_type) : ''; ?>" class="px-3 py-1.5 text-sm font-medium text-royal-purple bg-white border border-purple-200 rounded-lg hover:bg-purple-50 transition-colors font-bold">Next</a>
<?php else: ?>
<button class="px-3 py-1.5 text-sm font-medium text-slate-400 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 cursor-not-allowed opacity-50" disabled>Next</button>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
</main>
</div>


<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
<script>
    function initiateUpdate(patientId, hasEmail) {
        if (hasEmail === 'yes') {
            // OTP Flow
            Swal.fire({
                title: 'Consent Verification',
                text: "To update this patient's records, we need to verify their identity. An OTP will be sent to their registered email.",
                icon: 'info',
                showCancelButton: true,
                confirmButtonColor: '#8a11d4',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Send OTP'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Send OTP
                    Swal.fire({
                        title: 'Sending OTP...',
                        text: 'Please wait.',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });

                    fetch('../api/send_patient_otp.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({ patient_id: patientId })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // OTP Input Step
                            Swal.fire({
                                title: 'Enter OTP',
                                text: 'Please enter the 6-digit code sent to the patient.',
                                input: 'text',
                                inputAttributes: { autocapitalize: 'off', maxlength: 6 },
                                showCancelButton: true,
                                confirmButtonText: 'Verify',
                                confirmButtonColor: '#8a11d4',
                                showLoaderOnConfirm: true,
                                preConfirm: (code) => {
                                    return fetch('../api/verify_patient_otp.php', {
                                        method: 'POST',
                                        headers: {'Content-Type': 'application/json'},
                                        body: JSON.stringify({ patient_id: patientId, otp_code: code })
                                    })
                                    .then(response => response.json())
                                    .then(data => {
                                        if (!data.success) {
                                            throw new Error(data.message || 'Verification failed');
                                        }
                                        return data;
                                    })
                                    .catch(error => {
                                        Swal.showValidationMessage(`Request failed: ${error}`);
                                    });
                                },
                                allowOutsideClick: () => !Swal.isLoading()
                            }).then((result) => {
                                if (result.isConfirmed) {
                                    window.location.href = `patient_update_information.php?id=${patientId}`;
                                }
                            });
                        } else {
                            Swal.fire('Error', data.message || 'Failed to send OTP', 'error');
                        }
                    })
                    .catch(error => {
                        Swal.fire('Error', 'Network error occurred.', 'error');
                    });
                }
            });

        } else {
            // Manual Consent Flow (Checkbox)
            Swal.fire({
                title: 'Consent Required',
                html: `
                    <div class="text-left text-sm text-slate-600">
                        <p class="mb-4">This patient does not have a registered email address for OTP verification.</p>
                        <div class="flex items-start gap-3 p-4 bg-purple-50 rounded-xl border border-purple-100">
                            <input type="checkbox" id="consentCheckbox" class="mt-1 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                            <label for="consentCheckbox" class="font-bold text-slate-700">
                                I certify that I have obtained verbal or written consent from the patient to view and modify their personal records.
                            </label>
                        </div>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#8a11d4',
                confirmButtonText: 'Proceed',
                didOpen: () => {
                    const confirmBtn = Swal.getConfirmButton();
                    confirmBtn.disabled = true;
                    document.getElementById('consentCheckbox').addEventListener('change', function() {
                        confirmBtn.disabled = !this.checked;
                    });
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    // Set a session flag for manual consent via simple query param or redirect (Ideally API, but direct param for simplicity given scope)
                    // For security, we should ideally call an API to 'log' this consent, but direct redirect is requested for now.
                    // We'll use a special parameter that PHP can check (or just rely on the fact they are a receptionist).
                    // To be robust, let's call a quick API to set the session flag similar to OTP.
                    
                    fetch('../api/verify_manual_consent.php', { // Updated endpoint to avoid conflict
                         method: 'POST',
                         headers: {'Content-Type': 'application/json'},
                         body: JSON.stringify({ patient_id: patientId, type: 'manual_consent' })
                    }).then(() => {
                         window.location.href = `patient_update_information.php?id=${patientId}`;
                    });
                }
            });
        }
    }
</script>
</body></html>