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

// Handle payment processing
$action_message = '';
$action_type = '';

// Check for success message from redirect
if (isset($_GET['status']) && $_GET['status'] === 'success' && isset($_GET['message'])) {
    $action_message = sanitizeInput($_GET['message']);
    $action_type = 'success';
}

                
// Payment processing logic removed. Handled in receptionist_process_payment.php

// Get search query and filters
$search_query = sanitizeInput($_GET['search'] ?? '');
$status_filter = sanitizeInput($_GET['status'] ?? 'all');
$transaction_type = sanitizeInput($_GET['transaction_type'] ?? 'all');
$date_filter = sanitizeInput($_GET['date_filter'] ?? 'all');
$date_from = sanitizeInput($_GET['date_from'] ?? '');
$date_to = sanitizeInput($_GET['date_to'] ?? '');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build UNION query for all transaction types
$where_conditions = [];
$params = [];

// Common WHERE conditions
if (!empty($search_query)) {
    $search_param = '%' . $search_query . '%';
}

if ($status_filter !== 'all' && in_array($status_filter, ['Paid', 'Pending', 'Failed'])) {
    $status_condition = "payment_status = ?";
}

// Date filtering
$today_date = date('Y-m-d');
if ($date_filter === 'today') {
    $date_condition = "DATE(paid_at) = ?";
    $date_param = $today_date;
} elseif ($date_filter === 'range' && !empty($date_from) && !empty($date_to)) {
    $date_condition = "DATE(paid_at) BETWEEN ? AND ?";
    $date_params = [$date_from, $date_to];
}

// Build individual queries for each transaction type
$queries = [];

// 1. MEDICAL INVOICES
if ($transaction_type === 'all' || $transaction_type === 'medical') {
    $medical_where = [];
    $medical_params = [];
    
    $medical_where[] = "i.record_id IS NOT NULL";
    // User wants Down Payments (Record + Installment) to show as Medical.
    
    if (!empty($search_query)) {
        $medical_where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR i.reference_number LIKE ?)";
        $medical_params[] = $search_param;
        $medical_params[] = $search_param;
        $medical_params[] = $search_param;
        $medical_params[] = $search_param;
    }
    
    if (isset($status_condition)) {
        $medical_where[] = "i." . $status_condition;
        $medical_params[] = $status_filter;
    }
    
    if (isset($date_condition)) {
        $medical_where[] = "i." . $date_condition;
        if (isset($date_params)) {
            $medical_params = array_merge($medical_params, $date_params);
        } else {
            $medical_params[] = $date_param;
        }
    }
    
    $medical_where_clause = !empty($medical_where) ? 'WHERE ' . implode(' AND ', $medical_where) : '';
    
    $queries[] = [
        'sql' => "
            SELECT 
                i.invoice_id,
                i.reference_number,
                i.total_amount,
                i.payment_status,
                i.payment_method,
                i.paid_at,
                i.created_at,
                p.patient_id,
                p.first_name,
                p.last_name,
                GROUP_CONCAT(DISTINCT s.service_name SEPARATOR ', ') as service_name,
                'Medical' as transaction_type,
                mr.date_of_treatment
            FROM tbl_invoice i
            INNER JOIN tbl_medical_record mr ON i.record_id = mr.record_id
            INNER JOIN tbl_patient p ON mr.patient_id = p.patient_id
            LEFT JOIN tbl_appointment_service tas ON mr.appointment_id = tas.appointment_id
            LEFT JOIN tbl_service s ON tas.service_id = s.service_id
            $medical_where_clause
            GROUP BY i.invoice_id
        ",
        'params' => $medical_params
    ];
}

// 2. APPOINTMENT FEES
if ($transaction_type === 'all' || $transaction_type === 'appointment') {
    $appt_where = [];
    $appt_params = [];
    
    $appt_where[] = "i.appointment_id IS NOT NULL";
    $appt_where[] = "i.record_id IS NULL";
    $appt_where[] = "i.installment_id IS NULL"; // Exclude installments
    
    if (!empty($search_query)) {
        $appt_where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR i.reference_number LIKE ?)";
        $appt_params[] = $search_param;
        $appt_params[] = $search_param;
        $appt_params[] = $search_param;
        $appt_params[] = $search_param;
    }
    
    if (isset($status_condition)) {
        $appt_where[] = "i." . $status_condition;
        $appt_params[] = $status_filter;
    }
    
    if (isset($date_condition)) {
        $appt_where[] = "i." . $date_condition;
        if (isset($date_params)) {
            $appt_params = array_merge($appt_params, $date_params);
        } else {
            $appt_params[] = $date_param;
        }
    }
    
    $appt_where_clause = !empty($appt_where) ? 'WHERE ' . implode(' AND ', $appt_where) : '';
    
    $queries[] = [
        'sql' => "
            SELECT 
                i.invoice_id,
                i.reference_number,
                i.total_amount,
                i.payment_status,
                i.payment_method,
                i.paid_at,
                i.created_at,
                p.patient_id,
                p.first_name,
                p.last_name,
                GROUP_CONCAT(DISTINCT s.service_name SEPARATOR ', ') as service_name,
                'Appointment Fee' as transaction_type,
                a.appointment_date as date_of_treatment
            FROM tbl_invoice i
            INNER JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
            INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
            LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
            LEFT JOIN tbl_service s ON tas.service_id = s.service_id
            $appt_where_clause
            GROUP BY i.invoice_id
        ",
        'params' => $appt_params
    ];
}

// 3. INSTALLMENT PAYMENTS
if ($transaction_type === 'all' || $transaction_type === 'installment') {
    $inst_where = [];
    $inst_params = [];
    
    $inst_where[] = "i.installment_id IS NOT NULL";
    $inst_where[] = "i.record_id IS NULL"; // Only show pure installments (monthly payments), let DP show as Medical
    
    if (!empty($search_query)) {
        $inst_where[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR i.reference_number LIKE ?)";
        $inst_params[] = $search_param;
        $inst_params[] = $search_param;
        $inst_params[] = $search_param;
        $inst_params[] = $search_param;
    }
    
    if (isset($status_condition)) {
        $inst_where[] = "i." . $status_condition;
        $inst_params[] = $status_filter;
    }
    
    if (isset($date_condition)) {
        $inst_where[] = "i." . $date_condition;
        if (isset($date_params)) {
            $inst_params = array_merge($inst_params, $date_params);
        } else {
            $inst_params[] = $date_param;
        }
    }
    
    $inst_where_clause = !empty($inst_where) ? 'WHERE ' . implode(' AND ', $inst_where) : '';
    
    $queries[] = [
        'sql' => "
            SELECT 
                i.invoice_id,
                i.reference_number,
                i.total_amount,
                i.payment_status,
                i.payment_method,
                i.paid_at,
                i.created_at,
                p.patient_id,
                p.first_name,
                p.last_name,
                s.service_name,
                'Installment' as transaction_type,
                inst.created_at as date_of_treatment
            FROM tbl_invoice i
            INNER JOIN tbl_installment inst ON i.installment_id = inst.installment_id
            INNER JOIN tbl_patient p ON inst.patient_id = p.patient_id
            INNER JOIN tbl_service s ON inst.service_id = s.service_id
            $inst_where_clause
        ",
        'params' => $inst_params
    ];
}

// Combine queries with UNION
$union_sql = implode(' UNION ALL ', array_column($queries, 'sql'));
$all_params = [];
foreach ($queries as $query) {
    if (!empty($query['params'])) {
        $all_params = array_merge($all_params, $query['params']);
    }
}

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM (" . $union_sql . ") as combined";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($all_params);
    $total_invoices = $count_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Error counting transactions: ' . $e->getMessage());
    $total_invoices = 0;
}

// Fetch transactions
$invoices = [];
$total_pages = ceil($total_invoices / $per_page);

try {
    // Inject LIMIT and OFFSET directly as integers (safer for some PDO drivers)
    $limit_int = intval($per_page);
    $offset_int = intval($offset);
    
    $sql = "SELECT * FROM (" . $union_sql . ") as combined ORDER BY paid_at DESC, created_at DESC LIMIT $limit_int OFFSET $offset_int";
    $stmt = $pdo->prepare($sql);
    
    // Execute with only the WHERE clause parameters
    $stmt->execute($all_params);
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching invoices: ' . $e->getMessage());
    $invoices = [];
}

// Get statistics
$stats = [
    'today_revenue' => 0,
    'pending_invoices' => 0,
    'completed_transactions' => 0
];

try {
    // Today's revenue (paid invoices today)
    $revenue_stmt = $pdo->prepare("
        SELECT COALESCE(SUM(total_amount), 0) as revenue
        FROM tbl_invoice
        WHERE DATE(paid_at) = CURDATE() AND payment_status = 'Paid'
    ");
    $revenue_stmt->execute();
    $stats['today_revenue'] = $revenue_stmt->fetchColumn();
    
    // Pending invoices
    $pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_invoice WHERE payment_status = 'Pending'");
    $pending_stmt->execute();
    $stats['pending_invoices'] = $pending_stmt->fetchColumn();
    
    // Completed transactions (paid invoices)
    $completed_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_invoice WHERE payment_status = 'Paid'");
    $completed_stmt->execute();
    $stats['completed_transactions'] = $completed_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Error fetching stats: ' . $e->getMessage());
}

// Get patient ID from URL if provided (for creating new invoice)
$selected_patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
$selected_patient = null;
$unbilled_records = [];

if ($selected_patient_id > 0) {
    try {
        $patient_stmt = $pdo->prepare("SELECT * FROM tbl_patient WHERE patient_id = ? LIMIT 1");
        $patient_stmt->execute([$selected_patient_id]);
        $selected_patient = $patient_stmt->fetch();
        
        if ($selected_patient) {
            // Get medical records without invoices
            $unbilled_stmt = $pdo->prepare("
                SELECT mr.*, 
                       GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name, 
                       SUM(tas.price_at_booking) as base_price,
                       u.full_name as doctor_name
                FROM tbl_medical_record mr
                INNER JOIN tbl_user u ON mr.doctor_id = u.user_id
                LEFT JOIN tbl_appointment_service tas ON mr.appointment_id = tas.appointment_id
                LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                LEFT JOIN tbl_invoice i ON mr.record_id = i.record_id
                WHERE mr.patient_id = ? AND i.invoice_id IS NULL
                GROUP BY mr.record_id
                ORDER BY mr.date_of_treatment DESC
            ");
            $unbilled_stmt->execute([$selected_patient_id]);
            $unbilled_records = $unbilled_stmt->fetchAll();
        }
    } catch (PDOException $e) {
        error_log('Error fetching patient: ' . $e->getMessage());
    }
}

// Prepare patient data for JavaScript if patient is selected from URL
$js_patient_data = null;
if ($selected_patient) {
    $patient_id_display = 'P-' . str_pad($selected_patient['patient_id'], 4, '0', STR_PAD_LEFT);
    $js_patient_data = [
        'id' => $selected_patient['patient_id'],
        'firstName' => $selected_patient['first_name'],
        'lastName' => $selected_patient['last_name'],
        'displayId' => $patient_id_display,
        'phone' => $selected_patient['phone_number'] ?? ''
    ];
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

$current_date_time = date('l, M d') . ' • ' . date('g:i A');
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Receptionist Payments - Vanessa Nicolas Dental</title>
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
        }.custom-radio:checked + div {
            border-color: #6A1B9A;
            background-color: #f3e8f9;
            color: #6A1B9A;
        }
        .custom-radio:checked + div .check-icon {
            display: block;
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
    <div class="flex items-center gap-4">
        <h2 class="text-3xl font-black text-[#151118] tracking-tight">Payments &amp; Invoicing</h2>
        <!-- Tabs -->
        <div class="flex bg-gray-100 p-1 rounded-xl ml-4">
            <a href="#" class="px-4 py-1.5 rounded-lg bg-white text-royal-purple shadow-sm font-bold text-xs ring-1 ring-black/5">Transactions</a>
            <a href="receptionist_installments.php" class="px-4 py-1.5 rounded-lg text-slate-600 font-bold text-xs hover:bg-white hover:shadow-sm transition-all">Installments</a>
        </div>
    </div>
    <p class="text-slate-500 font-medium flex items-center gap-2">
        <span class="material-symbols-outlined text-lg">schedule</span>
        <?php echo $current_date_time; ?>
    </p>
</div>
<div class="flex items-center gap-4">
<label class="relative hidden lg:flex items-center h-12 w-80">
<div class="absolute left-4 text-slate-400 flex items-center justify-center">
<span class="material-symbols-outlined">search</span>
</div>
<form method="GET" action="" class="w-full">
<input class="w-full h-full pl-12 pr-4 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400 shadow-sm" placeholder="Find invoice or transaction..." name="search" value="<?php echo htmlspecialchars($search_query); ?>" type="text"/>
<?php if ($status_filter !== 'all'): ?>
<input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"/>
<?php endif; ?>
<?php if ($date_filter !== 'all'): ?>
<input type="hidden" name="date_filter" value="<?php echo htmlspecialchars($date_filter); ?>"/>
<?php if ($date_filter === 'range' && !empty($date_from) && !empty($date_to)): ?>
<input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>"/>
<input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>"/>
<?php endif; ?>
<?php endif; ?>
</form>
</label>
<button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
<span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full"></span>
<span class="material-symbols-outlined">notifications</span>
</button>
                <button onclick="toggleQRModal()" class="h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 transition-colors mr-2" title="View GCash QR">
                    <span class="material-symbols-outlined">qr_code_scanner</span>
                </button>
                <button class="h-12 px-6 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
                    <span class="material-symbols-outlined">add_circle</span>
                    <span>New Receipt</span>
                </button>
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
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
<div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group">
<div class="absolute right-0 top-0 w-24 h-24 bg-green-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
<div class="relative z-10 flex justify-between items-start">
<div>
<p class="text-slate-500 font-medium text-sm mb-1">Today's Revenue</p>
<h3 class="text-4xl font-bold text-[#151118]">$<?php echo number_format($stats['today_revenue'], 2); ?></h3>
</div>
<div class="w-10 h-10 rounded-full bg-green-100 text-green-700 flex items-center justify-center">
<span class="material-symbols-outlined">attach_money</span>
</div>
</div>
</div>
<div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group">
<div class="absolute right-0 top-0 w-24 h-24 bg-yellow-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
<div class="relative z-10 flex justify-between items-start">
<div>
<p class="text-slate-500 font-medium text-sm mb-1">Pending Invoices</p>
<h3 class="text-4xl font-bold text-[#151118]"><?php echo $stats['pending_invoices']; ?></h3>
</div>
<div class="w-10 h-10 rounded-full bg-yellow-100 text-yellow-700 flex items-center justify-center">
<span class="material-symbols-outlined">pending_actions</span>
</div>
</div>
<div class="relative z-10 flex items-center gap-1 mt-auto">
<span class="text-slate-500 text-xs font-medium">Needs attention</span>
</div>
</div>
<div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group">
<div class="absolute right-0 top-0 w-24 h-24 bg-purple-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
<div class="relative z-10 flex justify-between items-start">
<div>
<p class="text-slate-500 font-medium text-sm mb-1">Completed Trans.</p>
<h3 class="text-4xl font-bold text-[#151118]"><?php echo $stats['completed_transactions']; ?></h3>
</div>
<div class="w-10 h-10 rounded-full bg-purple-100 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined">check_circle</span>
</div>
</div>
<div class="relative z-10 flex items-center gap-1 mt-auto">
<span class="text-slate-500 text-xs">Updated just now</span>
</div>
</div>
</div>
<div class="flex flex-col xl:flex-row gap-6 h-full min-h-[600px]">
<div class="flex-1 bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
<div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
<div class="flex items-center gap-2">
<div class="p-2 bg-royal-purple/10 rounded-lg text-royal-purple">
<span class="material-symbols-outlined text-lg">history</span>
</div>
<h3 class="font-bold text-lg text-royal-purple">Transaction History</h3>
</div>
<div class="flex gap-2">
<button onclick="exportToCSV()" class="px-3 py-1.5 text-xs font-bold text-royal-purple bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors border border-purple-100 flex items-center gap-1.5">
<span class="material-symbols-outlined text-sm">download</span>
                                    Export CSV
                                </button>
<button onclick="exportToPDF()" class="px-3 py-1.5 text-xs font-bold text-royal-purple bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors border border-purple-100 flex items-center gap-1.5">
<span class="material-symbols-outlined text-sm">picture_as_pdf</span>
                                    Print PDF
                                </button>
<div class="relative">
                                        <button onclick="toggleTypeFilterDropdown()" class="px-3 py-1.5 text-xs font-bold text-slate-500 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors flex items-center gap-1.5">
                                            <span>Type: <?php
                                                if ($transaction_type === 'medical') echo 'Medical';
                                                elseif ($transaction_type === 'appointment') echo 'Appt. Fee';
                                                elseif ($transaction_type === 'installment') echo 'Installment';
                                                else echo 'All';
                                            ?></span>
                                            <span class="material-symbols-outlined text-sm">expand_more</span>
                                        </button>
                                        <div id="typeFilterDropdown" class="hidden absolute right-0 mt-1 w-48 bg-white border border-gray-200 rounded-lg shadow-lg z-10">
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['transaction_type' => 'all', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $transaction_type === 'all' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">All Transactions</a>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['transaction_type' => 'medical', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $transaction_type === 'medical' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">Medical Invoices</a>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['transaction_type' => 'appointment', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $transaction_type === 'appointment' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">Appointment Fees</a>
                                            <a href="?<?php echo http_build_query(array_merge($_GET, ['transaction_type' => 'installment', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $transaction_type === 'installment' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">Installment Payments</a>
                                        </div>
                                    </div>
<div class="relative">
<button onclick="toggleFilterDropdown()" class="px-3 py-1.5 text-xs font-bold text-slate-500 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors flex items-center gap-1.5">
<span>Filter: <?php echo $status_filter === 'all' ? 'All' : $status_filter; ?></span>
<span class="material-symbols-outlined text-sm">expand_more</span>
</button>
<div id="filterDropdown" class="hidden absolute right-0 mt-1 w-40 bg-white border border-gray-200 rounded-lg shadow-lg z-10">
<a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'all', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $status_filter === 'all' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">All</a>
<a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'Paid', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $status_filter === 'Paid' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">Paid</a>
<a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'Pending', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $status_filter === 'Pending' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">Pending</a>
<a href="?<?php echo http_build_query(array_merge($_GET, ['status' => 'Failed', 'page' => 1])); ?>" class="block px-4 py-2 text-xs hover:bg-gray-50 <?php echo $status_filter === 'Failed' ? 'bg-purple-50 text-royal-purple font-bold' : 'text-slate-600'; ?>">Failed</a>
</div>
</div>
<div class="relative">
<button onclick="toggleDateFilterDropdown()" class="px-3 py-1.5 text-xs font-bold text-slate-500 bg-white border border-gray-200 rounded-lg hover:bg-gray-50 transition-colors flex items-center gap-1.5">
<span>Date: <?php
    if ($date_filter === 'today') echo 'Today';
    elseif ($date_filter === 'range' && !empty($date_from) && !empty($date_to)) echo date('M d', strtotime($date_from)) . ' - ' . date('M d', strtotime($date_to));
    else echo 'All';
?></span>
<span class="material-symbols-outlined text-sm">expand_more</span>
</button>
<div id="dateFilterDropdown" class="hidden absolute right-0 mt-1 w-64 bg-white border border-gray-200 rounded-lg shadow-lg z-10 p-4">
<form method="GET" action="" id="dateFilterForm">
<?php if (!empty($search_query)): ?>
<input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>"/>
<?php endif; ?>
<?php if ($status_filter !== 'all'): ?>
<input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"/>
<?php endif; ?>
<?php if ($transaction_type !== 'all'): ?>
<input type="hidden" name="transaction_type" value="<?php echo htmlspecialchars($transaction_type); ?>"/>
<?php endif; ?>
<div class="flex flex-col gap-3">
<label class="cursor-pointer flex items-center gap-2">
<input type="radio" name="date_filter" value="all" <?php echo $date_filter === 'all' ? 'checked' : ''; ?> onchange="document.getElementById('dateFilterForm').submit();" class="text-royal-purple"/>
<span class="text-xs">All Dates</span>
</label>
<label class="cursor-pointer flex items-center gap-2">
<input type="radio" name="date_filter" value="today" <?php echo $date_filter === 'today' ? 'checked' : ''; ?> onchange="document.getElementById('dateFilterForm').submit();" class="text-royal-purple"/>
<span class="text-xs">Today</span>
</label>
<label class="flex flex-col gap-1">
<span class="text-xs font-bold text-slate-600">Date Range:</span>
<div class="flex gap-2">
<input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="text-xs px-2 py-1 border border-gray-200 rounded" placeholder="From"/>
<input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="text-xs px-2 py-1 border border-gray-200 rounded" placeholder="To"/>
</div>
<button type="submit" name="date_filter" value="range" class="mt-2 px-3 py-1 text-xs bg-royal-purple text-white rounded hover:bg-purple-800">Apply Range</button>
</label>
</div>
</form>
</div>
</div>
</div>
</div>
<div class="overflow-x-auto flex-1">
<table class="w-full text-left text-sm whitespace-nowrap">
<thead class="bg-gray-50 text-slate-500 font-medium border-b border-gray-100">
<tr>
<th class="px-6 py-4">Invoice ID</th>
<th class="px-6 py-4">Patient Name</th>
<th class="px-6 py-4">Type</th>
<th class="px-6 py-4">Treatment</th>
<th class="px-6 py-4">Date &amp; Time</th>
<th class="px-6 py-4">Amount</th>
<th class="px-6 py-4">Status</th>
<th class="px-6 py-4 text-right">Action</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php if (empty($invoices)): ?>
<tr>
<td colspan="8" class="px-6 py-12 text-center text-slate-500">
<div class="flex flex-col items-center gap-3">
<span class="material-symbols-outlined text-4xl text-slate-300">receipt_long</span>
<p class="font-medium">No invoices found</p>
<?php if (!empty($search_query)): ?>
<p class="text-sm">Try adjusting your search criteria</p>
<?php endif; ?>
</div>
</td>
</tr>
<?php else: ?>
<?php foreach ($invoices as $inv):
    // The getDefaultProfilePhoto function is assumed to be defined elsewhere
    // For now, we'll keep the original logic for patient initials if getDefaultProfilePhoto is not available
    $patient_initials = strtoupper(substr($inv['first_name'], 0, 1) . substr($inv['last_name'], 0, 1));
    $patient_name = htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']);
    $patient_id_display = 'P-' . str_pad($inv['patient_id'], 4, '0', STR_PAD_LEFT);
    $invoice_id_display = $inv['reference_number'] ?: 'INV-' . str_pad($inv['invoice_id'], 4, '0', STR_PAD_LEFT);

    // FIX: Show Payment Date if Paid, otherwise Creation Date. 
    // Fallback to date_of_treatment only if both are missing (unlikely).
    $date_source = $inv['paid_at'] ?? $inv['created_at'] ?? $inv['date_of_treatment'];
    $date_obj = new DateTime($date_source);
    $date_display = $date_obj->format('M d, g:i A');

    $status_colors = [
        'Paid' => 'bg-green-50 text-green-700 border-green-100',
        'Pending' => 'bg-yellow-100 text-yellow-800 border-yellow-200',
        'Failed' => 'bg-red-100 text-red-700 border-red-200'
    ];
    $status_color = $status_colors[$inv['payment_status']] ?? 'bg-gray-100 text-gray-700 border-gray-200';
    $is_pending = $inv['payment_status'] === 'Pending';
?>
<tr class="hover:bg-gray-50/50 transition-colors group <?php echo $is_pending ? 'bg-yellow-50/30' : ''; ?>">
<td class="px-6 py-4 font-mono font-bold text-royal-purple">#<?php echo $invoice_id_display; ?></td>
<td class="px-6 py-4">
<div class="flex items-center gap-3">
<?php
    $profile = getDefaultProfilePhoto($inv['first_name'] . ' ' . $inv['last_name']);
?>
<div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-xs" style="background-color: <?php echo $profile['bg_color']; ?>; color: <?php echo $profile['text_color']; ?>"><?php echo $profile['initials']; ?></div>
<div class="flex flex-col">
<span class="font-bold text-[#151118]"><?php echo $patient_name; ?></span>
<span class="text-xs text-slate-400"><?php echo $patient_id_display; ?></span>
</div>
</div>
</td>
<td class="px-6 py-4">
<?php
    $typeClass = 'bg-gray-100 text-slate-600';
    $typeIcon = 'receipt';
    if ($inv['transaction_type'] === 'Medical') {
        $typeClass = 'bg-purple-100 text-royal-purple';
        $typeIcon = 'medication';
    } elseif ($inv['transaction_type'] === 'Appointment Fee') {
        $typeClass = 'bg-blue-100 text-blue-700';
        $typeIcon = 'event';
    } elseif ($inv['transaction_type'] === 'Installment') {
        $typeClass = 'bg-orange-100 text-orange-700';
        $typeIcon = 'pie_chart';
    }
?>
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-bold <?php echo $typeClass; ?>">
<span class="material-symbols-outlined text-[14px]"><?php echo $typeIcon; ?></span>
<?php echo htmlspecialchars($inv['transaction_type']); ?>
</span>
</td>
<td class="px-6 py-4">
<div class="text-slate-600 text-sm max-w-[200px] truncate" title="<?php echo htmlspecialchars($inv['service_name']); ?>">
<?php echo htmlspecialchars($inv['service_name'] ?: 'N/A'); ?>
</div>
</td>
<td class="px-6 py-4 text-slate-500"><?php echo $date_display; ?></td>
<td class="px-6 py-4 font-bold text-[#151118]">$<?php echo number_format($inv['total_amount'], 2); ?></td>
<td class="px-6 py-4">
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold border <?php echo $status_color; ?>">
<?php echo $inv['payment_status']; ?>
</span>
</td>
<td class="px-6 py-4 text-right">
<?php if ($is_pending): ?>
<button onclick="openPaymentModal(<?php echo $inv['invoice_id']; ?>, '<?php echo htmlspecialchars($patient_name, ENT_QUOTES); ?>', <?php echo $inv['total_amount']; ?>, '<?php echo htmlspecialchars($inv['payment_method'], ENT_QUOTES); ?>')" class="text-royal-purple font-bold text-xs hover:underline">Collect</button>
<?php else: ?>
<a href="print_receipt.php?invoice_id=<?php echo $inv['invoice_id']; ?>" target="_blank" class="text-slate-400 hover:text-royal-purple transition-colors" title="Print Receipt">
<span class="material-symbols-outlined text-[20px]">print</span>
</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($total_pages > 1): ?>
<div class="p-4 border-t border-gray-100 bg-gray-50 flex justify-end">
<nav class="flex items-center gap-1">
<?php
$pagination_params = [];
if (!empty($search_query)) $pagination_params['search'] = $search_query;
if ($status_filter !== 'all') $pagination_params['status'] = $status_filter;
if ($transaction_type !== 'all') $pagination_params['transaction_type'] = $transaction_type; // Add transaction_type to pagination
if ($date_filter !== 'all') {
    $pagination_params['date_filter'] = $date_filter;
    if ($date_filter === 'range' && !empty($date_from) && !empty($date_to)) {
        $pagination_params['date_from'] = $date_from;
        $pagination_params['date_to'] = $date_to;
    }
}
$pagination_query = !empty($pagination_params) ? '&' . http_build_query($pagination_params) : '';
?>
<?php if ($page > 1): ?>
<a href="?page=<?php echo $page - 1; ?><?php echo $pagination_query; ?>" class="p-2 text-slate-400 hover:text-royal-purple"><span class="material-symbols-outlined text-sm">chevron_left</span></a>
<?php else: ?>
<button class="p-2 text-slate-400 opacity-50 cursor-not-allowed" disabled><span class="material-symbols-outlined text-sm">chevron_left</span></button>
<?php endif; ?>

<?php
$start_page = max(1, $page - 1);
$end_page = min($total_pages, $page + 1);

for ($i = $start_page; $i <= $end_page; $i++):
?>
<?php if ($i == $page): ?>
<button class="w-8 h-8 flex items-center justify-center rounded-lg bg-royal-purple text-white text-xs font-bold shadow-md"><?php echo $i; ?></button>
<?php else: ?>
<a href="?page=<?php echo $i; ?><?php echo $pagination_query; ?>" class="w-8 h-8 flex items-center justify-center rounded-lg text-slate-500 hover:bg-white hover:text-royal-purple text-xs font-bold"><?php echo $i; ?></a>
<?php endif; ?>
<?php endfor; ?>

<?php if ($page < $total_pages): ?>
<a href="?page=<?php echo $page + 1; ?><?php echo $pagination_query; ?>" class="p-2 text-slate-400 hover:text-royal-purple"><span class="material-symbols-outlined text-sm">chevron_right</span></a>
<?php else: ?>
<button class="p-2 text-slate-400 opacity-50 cursor-not-allowed" disabled><span class="material-symbols-outlined text-sm">chevron_right</span></button>
<?php endif; ?>
</nav>
</div>
<?php endif; ?>
</div>
<!-- Payment Block Removed -->
</div>
</main>
</div>

<!-- Modals Removed -->

<!-- Floating Process Payment Button -->
<div class="fixed bottom-8 right-8 z-50">
    <a href="receptionist_process_payment.php" class="flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-clinic-gold to-yellow-400 hover:from-yellow-400 hover:to-yellow-500 text-royal-purple font-bold text-sm rounded-full shadow-lg shadow-yellow-400/40 transform hover:-translate-y-1 transition-all group border border-white/50 backdrop-blur-sm">
        <span class="bg-white/20 p-1 rounded-full backdrop-blur-md flex items-center justify-center">
             <span class="material-symbols-outlined text-xl group-hover:rotate-12 transition-transform">point_of_sale</span>
        </span>
        <span class="tracking-wide">Process Payment</span>
    </a>
</div>

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

            // Set icon and colors based on type (using purple and gold theme)
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

            // Create buttons
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

            // Show popup with animation
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
    
    // Export functions
    window.showAlert = (message, title = 'Alert') => showPopup('alert', title, message);
    window.showConfirm = (message, title = 'Confirm') => showPopup('confirm', title, message);
    window.showSuccess = (message, title = 'Success') => showPopup('success', title, message);
    window.showError = (message, title = 'Error') => showPopup('error', title, message);
})();
</script>

<script>

// Filter Logic


// Filter dropdown functionality
function toggleFilterDropdown() {
    const dropdown = document.getElementById('filterDropdown');
    const typeDropdown = document.getElementById('typeFilterDropdown');
    const dateDropdown = document.getElementById('dateFilterDropdown');
    
    if (dropdown) {
        dropdown.classList.toggle('hidden');
        // Close other dropdowns
        if (typeDropdown) typeDropdown.classList.add('hidden');
        if (dateDropdown) dateDropdown.classList.add('hidden');
    }
}

function toggleTypeFilterDropdown() {
    const dropdown = document.getElementById('typeFilterDropdown');
    const statusDropdown = document.getElementById('filterDropdown');
    const dateDropdown = document.getElementById('dateFilterDropdown');
    
    if (dropdown) {
        dropdown.classList.toggle('hidden');
        // Close other dropdowns
        if (statusDropdown) statusDropdown.classList.add('hidden');
        if (dateDropdown) dateDropdown.classList.add('hidden');
    }
}

// Close filter dropdowns when clicking outside
document.addEventListener('click', function(e) {
    const filterButton = document.querySelector('[onclick="toggleFilterDropdown()"]');
    const filterDropdown = document.getElementById('filterDropdown');
    const typeButton = document.querySelector('[onclick="toggleTypeFilterDropdown()"]');
    const typeDropdown = document.getElementById('typeFilterDropdown');
    
    if (filterButton && filterDropdown && !filterButton.contains(e.target) && !filterDropdown.contains(e.target)) {
        filterDropdown.classList.add('hidden');
    }
    
    if (typeButton && typeDropdown && !typeButton.contains(e.target) && !typeDropdown.contains(e.target)) {
        typeDropdown.classList.add('hidden');
    }
});

// Export to CSV functionality
function exportToCSV() {
    const searchQuery = '<?php echo addslashes($search_query); ?>';
    const statusFilter = '<?php echo addslashes($status_filter); ?>';
    const transactionType = '<?php echo addslashes($transaction_type); ?>';
    const dateFilter = '<?php echo addslashes($date_filter); ?>';
    const dateFrom = '<?php echo addslashes($date_from); ?>';
    const dateTo = '<?php echo addslashes($date_to); ?>';
    
    // Build export URL with current filters
    let exportUrl = 'export_invoices.php?';
    const params = [];
    if (searchQuery) params.push('search=' + encodeURIComponent(searchQuery));
    if (statusFilter !== 'all') params.push('status=' + encodeURIComponent(statusFilter));
    if (transactionType !== 'all') params.push('transaction_type=' + encodeURIComponent(transactionType));
    if (dateFilter !== 'all') {
        params.push('date_filter=' + encodeURIComponent(dateFilter));
        if (dateFilter === 'range' && dateFrom && dateTo) {
            params.push('date_from=' + encodeURIComponent(dateFrom));
            params.push('date_to=' + encodeURIComponent(dateTo));
        }
    }
    exportUrl += params.join('&');
    
    // Open export in new window/tab
    window.location.href = exportUrl;
}

// Export to PDF functionality
function exportToPDF() {
    const searchQuery = '<?php echo addslashes($search_query); ?>';
    const statusFilter = '<?php echo addslashes($status_filter); ?>';
    const transactionType = '<?php echo addslashes($transaction_type); ?>';
    const dateFilter = '<?php echo addslashes($date_filter); ?>';
    const dateFrom = '<?php echo addslashes($date_from); ?>';
    const dateTo = '<?php echo addslashes($date_to); ?>';
    
    // Build export URL with current filters
    let exportUrl = 'export_invoices_pdf.php?';
    const params = [];
    if (searchQuery) params.push('search=' + encodeURIComponent(searchQuery));
    if (statusFilter !== 'all') params.push('status=' + encodeURIComponent(statusFilter));
    if (transactionType !== 'all') params.push('transaction_type=' + encodeURIComponent(transactionType));
    if (dateFilter !== 'all') {
        params.push('date_filter=' + encodeURIComponent(dateFilter));
        if (dateFilter === 'range' && dateFrom && dateTo) {
            params.push('date_from=' + encodeURIComponent(dateFrom));
            params.push('date_to=' + encodeURIComponent(dateTo));
        }
    }
    exportUrl += params.join('&');
    
    // Open export in new window/tab
    window.open(exportUrl, '_blank');
}

// Date filter dropdown functionality
function toggleDateFilterDropdown() {
    const dropdown = document.getElementById('dateFilterDropdown');
    if (dropdown) {
        dropdown.classList.toggle('hidden');
    }
}

// Close date filter dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dateFilterButton = document.querySelector('[onclick="toggleDateFilterDropdown()"]');
    const dateFilterDropdown = document.getElementById('dateFilterDropdown');
    if (dateFilterButton && dateFilterDropdown && !dateFilterButton.contains(e.target) && !dateFilterDropdown.contains(e.target)) {
        dateFilterDropdown.classList.add('hidden');
    }
});





</script>
<!-- QR Code Modal -->
<div id="qrModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleQRModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden p-6 transform transition-all scale-100">
        <div class="text-center">
            <h3 class="text-xl font-bold text-slate-800 mb-2">Scan to Pay</h3>
            <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100 mb-4">
                <div class="bg-white p-2 rounded-lg shadow-sm w-fit mx-auto mb-2">
                    <img src="../images/Gcash.jpg" class="w-48 h-48 object-contain">
                </div>
                <p class="font-black text-xl text-slate-800 tracking-wide">0962 878 6207</p>
                <p class="text-xs text-slate-500 font-medium mt-1">GCash Payment</p>
            </div>
            <button onclick="toggleQRModal()" class="w-full py-3 bg-gray-100 hover:bg-gray-200 text-slate-700 font-bold rounded-xl transition-colors">
                Close
            </button>
        </div>
    </div>
</div>

<script>
function toggleQRModal() {
    const modal = document.getElementById('qrModal');
    if (modal) {
        modal.classList.toggle('hidden');
    }
}
</script>
<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>