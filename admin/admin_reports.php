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

// --- REPORT LOGIC ---

// 1. FINANCIAL FILTERS (Default: Current Month)
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$doctor_id  = $_GET['doctor_id'] ?? 'all';

// 2. Fetch Doctors for Filter
$doctors = [];
try {
    $stmt = $pdo->query("SELECT user_id, full_name FROM tbl_user WHERE role = 'Dentist' OR role = 'Admin' ORDER BY full_name ASC");
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { }

// Helper for SQL clauses
$date_clause = " BETWEEN ? AND ? ";
$params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59']; 

// --- FINANCIAL & OPERATIONS DATA (Filtered by Date) ---
$total_revenue = 0;
$invoices_issued = 0;
$new_patients_period = 0;
$appointments_period = 0;
$chart_labels = [];
$chart_data = [];
$transactions = [];
$attendance_data = ['Completed'=>0, 'No-Show'=>0, 'Cancelled'=>0, 'Walk-in'=>0];
$top_services = [];

try {
    // REVENUE (Income Only) & INVOICES
    $inv_sql = "SELECT SUM(i.total_amount) as rev, COUNT(i.invoice_id) as cnt 
                FROM tbl_invoice i 
                LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id 
                LEFT JOIN tbl_medical_record mr ON i.record_id = mr.record_id
                WHERE i.paid_at BETWEEN ? AND ? AND i.payment_status = 'Paid'";
    
    $inv_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    if ($doctor_id !== 'all') {
        $inv_sql .= " AND (a.doctor_id = ? OR mr.doctor_id = ?)";
        $inv_params[] = $doctor_id;
        $inv_params[] = $doctor_id;
    }
    
    $stmt = $pdo->prepare($inv_sql);
    $stmt->execute($inv_params);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_revenue = $res['rev'] ?? 0;
    
    // Invoices Issued
    $inv_count_sql = "SELECT COUNT(i.invoice_id) FROM tbl_invoice i 
                      LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id 
                      LEFT JOIN tbl_medical_record mr ON i.record_id = mr.record_id
                      WHERE i.paid_at BETWEEN ? AND ? ";
    if ($doctor_id !== 'all') {
        $inv_count_sql .= " AND (a.doctor_id = ? OR mr.doctor_id = ?)";
    }
    // Reuse params from revenue query as filters are identical
    $stmt = $pdo->prepare($inv_count_sql);
    $stmt->execute($inv_params);
    $invoices_issued = $stmt->fetchColumn();

    // New Patients (Period) - Wrapped in try-catch to prevent failure if created_at is missing
    // New Patients (Period)
    if ($doctor_id === 'all') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_patient WHERE registration_date BETWEEN ? AND ?");
        $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $new_patients_period = $stmt->fetchColumn();
    } else {
        $new_patients_period = "-"; 
    }

    // Appointments (Period)
    $apt_sql = "SELECT COUNT(*) FROM tbl_appointment a WHERE DATE(a.appointment_date) BETWEEN ? AND ?";
    $apt_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') {
        $apt_sql .= " AND a.doctor_id = ?";
        $apt_params[] = $doctor_id;
    }
    $stmt = $pdo->prepare($apt_sql);
    $stmt->execute($apt_params);
    $appointments_period = $stmt->fetchColumn();

    // Revenue Chart
    $diff = strtotime($end_date) - strtotime($start_date);
    $is_daily = ($diff / (60 * 60 * 24)) <= 31;
    $format = $is_daily ? '%Y-%m-%d' : '%Y-%m';

    $chart_sql = "SELECT DATE_FORMAT(i.paid_at, '$format') as d, SUM(i.total_amount) as total 
                  FROM tbl_invoice i
                  LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
                  LEFT JOIN tbl_medical_record mr ON i.record_id = mr.record_id
                  WHERE i.payment_status = 'Paid' AND i.paid_at BETWEEN ? AND ?";
    $chart_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    if ($doctor_id !== 'all') {
        $chart_sql .= " AND (a.doctor_id = ? OR mr.doctor_id = ?)";
        $chart_params[] = $doctor_id;
        $chart_params[] = $doctor_id;
    }
    $chart_sql .= " GROUP BY DATE_FORMAT(i.paid_at, '$format') ORDER BY d ASC";
    $stmt = $pdo->prepare($chart_sql);
    $stmt->execute($chart_params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    $chart_labels = array_keys($raw_data);
    $chart_data = array_values($raw_data);

    // Transactions
    $trx_sql = "SELECT i.*, 
                COALESCE(p_a.first_name, p_mr.first_name) as first_name, 
                COALESCE(p_a.last_name, p_mr.last_name) as last_name,
                CONCAT(COALESCE(p_a.first_name, p_mr.first_name), ' ', COALESCE(p_a.last_name, p_mr.last_name)) as patient_name
                FROM tbl_invoice i
                LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
                LEFT JOIN tbl_medical_record mr ON i.record_id = mr.record_id
                LEFT JOIN tbl_patient p_a ON a.patient_id = p_a.patient_id
                LEFT JOIN tbl_patient p_mr ON mr.patient_id = p_mr.patient_id
                WHERE i.paid_at BETWEEN ? AND ? ";
    $trx_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    if ($doctor_id !== 'all') {
        $trx_sql .= " AND (a.doctor_id = ? OR mr.doctor_id = ?)";
        $trx_params[] = $doctor_id;
        $trx_params[] = $doctor_id;
    }
    $trx_sql .= " ORDER BY i.paid_at DESC LIMIT 10";
    $stmt = $pdo->prepare($trx_sql);
    $stmt->execute($trx_params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Attendance
    $att_sql = "SELECT status, COUNT(*) FROM tbl_appointment a WHERE DATE(a.appointment_date) BETWEEN ? AND ? ";
    $att_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') {
        $att_sql .= " AND a.doctor_id = ?";
        $att_params[] = $doctor_id;
    }
    $att_sql .= " GROUP BY status";
    $stmt = $pdo->prepare($att_sql);
    $stmt->execute($att_params);
    while($row = $stmt->fetch(PDO::FETCH_NUM)) {
        if(isset($attendance_data[$row[0]])) $attendance_data[$row[0]] = $row[1];
    }
    // Walk-ins
    $walk_sql = "SELECT COUNT(*) FROM tbl_appointment a WHERE a.appointment_type = 'Walk-in' AND DATE(a.appointment_date) BETWEEN ? AND ?";
    $walk_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') {
        $walk_sql .= " AND a.doctor_id = ?";
        $walk_params[] = $doctor_id;
    }
    $stmt = $pdo->prepare($walk_sql);
    $stmt->execute($walk_params);
    $attendance_data['Walk-in'] = $stmt->fetchColumn();

    // Top Services
    $srv_sql = "SELECT s.service_name, COUNT(tas.service_id) as usage_count 
                FROM tbl_appointment_service tas
                JOIN tbl_appointment a ON tas.appointment_id = a.appointment_id
                JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE DATE(a.appointment_date) BETWEEN ? AND ? AND a.status = 'Completed' ";
    $srv_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') {
        $srv_sql .= " AND a.doctor_id = ?";
        $srv_params[] = $doctor_id;
    }
    $srv_sql .= " GROUP BY s.service_name ORDER BY usage_count DESC LIMIT 5";
    $stmt = $pdo->prepare($srv_sql);
    $stmt->execute($srv_params);
    $top_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { 
    echo "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4' role='alert'>" . 
         "<strong class='font-bold'>Report Error:</strong> " . 
         "<span class='block sm:inline'>" . htmlspecialchars($e->getMessage()) . "</span>" .
         "</div>";
    error_log($e->getMessage()); 
}


// --- DEMOGRAPHICS DATA (ALL TIME - DECOUPLED) ---
$gender_labels = [];
$gender_counts = [];
$age_groups = [0, 0, 0, 0]; // Minors, 18-30, 31-50, 51+
$total_patients_all_time = 0;

try {
    // Total Patients
    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_patient");
    $total_patients_all_time = $stmt->fetchColumn();

    // Gender (All Time)
    $gender_colors = [];
    $stmt = $pdo->query("SELECT gender, COUNT(*) FROM tbl_patient GROUP BY gender");
    while($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $g = $row[0] ?: 'Unknown';
        $gender_labels[] = $g;
        $gender_counts[] = $row[1];
        
        // Color Mapping
        if(stripos($g, 'Female') !== false) $gender_colors[] = '#FFD700'; // Gold
        elseif(stripos($g, 'Male') !== false) $gender_colors[] = '#8a11d4'; // Purple
        else $gender_colors[] = '#D2B48C'; // Tan/Beige (Darker for visibility)
    }

    // Age (All Time, Dynamic Calculation)
    $stmt = $pdo->query("SELECT TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age FROM tbl_patient");
    $ages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ages as $age) {
        if ($age < 18) $age_groups[0]++;
        elseif ($age >= 18 && $age <= 30) $age_groups[1]++;
        elseif ($age >= 31 && $age <= 50) $age_groups[2]++;
        elseif ($age >= 51) $age_groups[3]++;
    }
} catch (Exception $e) { }


// Fetch current user details
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT full_name, role, profile_picture FROM tbl_user WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $stmt->fetch();
    } catch (PDOException $e) {}
}

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
        ['bg' => '#3b82f6', 'text' => '#ffffff'], 
        ['bg' => '#f59e0b', 'text' => '#ffffff'], 
        ['bg' => '#ef4444', 'text' => '#ffffff'], 
        ['bg' => '#8b5cf6', 'text' => '#ffffff'], 
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    
    return ['initials'=>$initials, 'bg_color'=>$color['bg'], 'text_color'=>$color['text']];
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Admin Myers - Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4", "accent": "#FFD700",
                        "background-light": "#f7f6f8", "background-dark": "#1b1022",
                        "surface-light": "#ffffff", "surface-dark": "#2d1b36",
                        "text-main": "#151118", "text-sub": "#7a6189",
                    },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
        }
        function setDateFilter(type) {
            const today = new Date();
            let start = new Date();
            let end = new Date();
            
            if (type === 'today') {
                // start/end already now
            } else if (type === 'week') {
                const day = today.getDay() || 7; 
                if (day !== 1) start.setHours(-24 * (day - 1));
            } else if (type === 'month') {
                start.setDate(1);
            }
            
            const fmt = d => d.toISOString().split('T')[0];
            document.querySelector('input[name="start_date"]').value = fmt(start);
            document.querySelector('input[name="end_date"]').value = fmt(end);
            document.querySelector('form').submit();
        }

        function toggleExportDropdown() {
            const dd = document.getElementById('exportDropdown');
            dd.classList.toggle('hidden');
        }

        // Close dropdown when clicking outside
        window.onclick = function(event) {
            if (!event.target.closest('.export-container')) {
                document.getElementById('exportDropdown').classList.add('hidden');
            }
        }

        function switchTab(tabId) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(el => el.classList.add('hidden'));
            // Show selected tab
            document.getElementById(tabId).classList.remove('hidden');
            
            // Update Tab Styles
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('text-primary', 'border-primary', 'bg-primary/5');
                el.classList.add('text-text-sub', 'border-transparent');
            });
            const btn = document.querySelector(`button[onclick="switchTab('${tabId}')"]`);
            btn.classList.remove('text-text-sub', 'border-transparent');
            btn.classList.add('text-primary', 'border-primary', 'bg-primary/5');
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="bg-background-light dark:bg-background-dark text-text-main dark:text-white font-display overflow-hidden">
<div class="flex h-screen w-full">
    <!-- Sidebar -->
    <aside class="w-72 flex-shrink-0 flex flex-col bg-surface-light dark:bg-surface-dark border-r border-[#f3f0f4] dark:border-gray-800 transition-colors duration-200">
        <div class="flex flex-col h-full p-4">
            <!-- Profile -->
            <a href="admin_current_user.php" class="flex gap-3 mb-8 items-center p-2 cursor-pointer hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 rounded-xl p-2 transition-colors">
                <?php
                $curr_u = $current_user_details ?? $_SESSION['user'] ?? ['full_name'=>'Admin'];
                $path = $curr_u['profile_picture'] ?? null;
                if ($path && file_exists('../'.$path)) {
                    echo '<div class="bg-center bg-no-repeat aspect-square bg-cover rounded-full size-12 border-2 border-primary" style="background-image: url(\'../'.htmlspecialchars($path).'\');"></div>';
                } else {
                    $def = getDefaultProfilePhoto($curr_u['full_name']);
                    echo '<div class="rounded-full size-12 border-2 border-primary flex items-center justify-center text-white font-bold" style="background-color:'.$def['bg_color'].'">'.$def['initials'].'</div>';
                }
                ?>
                <div class="flex flex-col">
                    <h1 class="text-text-main dark:text-white text-base font-bold"><?php echo htmlspecialchars($curr_u['full_name']); ?></h1>
                    <p class="text-primary text-xs font-semibold uppercase"><?php echo htmlspecialchars($curr_u['role'] ?? 'Admin'); ?></p>
                </div>
            </a>
            <!-- Nav -->
            <nav class="flex flex-col gap-2 flex-1">
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50" href="admin_dashboard.php"><span class="material-symbols-outlined">dashboard</span><p class="text-sm font-medium">Dashboard</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50" href="admin_user.php"><span class="material-symbols-outlined">group</span><p class="text-sm font-medium">Users</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50" href="admin_patients.php"><span class="material-symbols-outlined">accessibility_new</span><p class="text-sm font-medium">Patients</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50" href="admin_schedule.php"><span class="material-symbols-outlined">schedule</span><p class="text-sm font-medium">Schedules</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50" href="admin_service.php"><span class="material-symbols-outlined">medical_services</span><p class="text-sm font-medium">Services</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary" href="admin_reports.php"><span class="material-symbols-outlined fill-1">description</span><p class="text-sm font-bold">Reports</p></a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50" href="admin_settings.php"><span class="material-symbols-outlined">settings</span><p class="text-sm font-medium">Settings</p></a>
            </nav>
            <!-- Logout -->
            <div class="mt-auto pt-6 border-t border-gray-100 dark:border-gray-800">
                <a href="../logout.php" class="flex items-center gap-3 px-4 py-2 text-text-sub hover:text-red-500"><span class="material-symbols-outlined">logout</span><span class="text-sm font-medium">Logout</span></a>
            </div>
        </div>
    </aside>

    <!-- Main -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light dark:bg-background-dark relative">
        <header class="h-20 flex items-center justify-between px-8 bg-surface-light dark:bg-surface-dark border-b border-[#f3f0f4] dark:border-gray-800 flex-shrink-0 z-20">
            <h2 class="text-text-main dark:text-white text-xl font-bold">Reports & Analytics</h2>
            
            <!-- Tab Navigation -->
            <div class="flex gap-2 bg-gray-100 dark:bg-gray-800 p-1 rounded-xl">
                <button onclick="switchTab('tab-financial')" class="tab-btn px-4 py-2 rounded-lg text-sm font-bold transition-all text-primary border-primary bg-primary/5">Financials</button>
                <button onclick="switchTab('tab-demographics')" class="tab-btn px-4 py-2 rounded-lg text-sm font-bold transition-all text-text-sub hover:text-text-main">Demographics</button>
            </div>

            <div class="flex gap-3">
                <!-- Detailed Reports Link -->
                <a href="admin_detailed_reports.php" target="_blank" class="flex items-center gap-2 px-4 py-2 bg-primary/10 border border-primary/20 rounded-xl text-primary hover:bg-primary/20 font-bold text-sm transition-colors">
                    <span class="material-symbols-outlined text-[20px]">analytics</span> Detailed Analytics
                </a>

                <!-- Export -->
                <div class="relative export-container">
                <button onclick="toggleExportDropdown()" class="flex items-center gap-2 px-4 py-2 bg-surface-light dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-xl text-text-main dark:text-white hover:bg-gray-50 font-bold text-sm">
                    <span class="material-symbols-outlined text-[20px]">download</span> Export
                </button>
                <!-- Dropdown -->
                <div id="exportDropdown" class="absolute right-0 top-full mt-2 w-48 bg-white dark:bg-gray-800 rounded-xl shadow-xl border border-gray-100 dark:border-gray-700 hidden z-50">
                    <a href="admin_export_reports.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&doctor_id=<?php echo $doctor_id; ?>" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-t-xl text-sm font-medium border-b border-gray-50">
                        <span class="material-symbols-outlined text-green-600">table_chart</span> CSV (Excel)
                    </a>
                    <a href="admin_reports_print.php?start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&doctor_id=<?php echo $doctor_id; ?>" target="_blank" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-b-xl text-sm font-medium">
                        <span class="material-symbols-outlined text-red-600">picture_as_pdf</span> PDF / Print
                    </a>
                </div>
            </div>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8 scroll-smooth">
            <div class="max-w-[1400px] mx-auto pb-10">

                <!-- TAB 1: FINANCIALS & OPERATIONS -->
                <div id="tab-financial" class="tab-content flex flex-col gap-6">
                    
                    <!-- Date Filter Bar -->
                    <div class="p-4 bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-wrap items-center gap-4">
                        <span class="text-sm font-bold text-text-main dark:text-white">Filter Data:</span>
                        <form method="GET" class="flex items-center gap-3 flex-1 flex-wrap">
                            <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                <span class="text-[10px] text-text-sub font-bold uppercase">From</span>
                                <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="bg-transparent border-none p-0 text-sm font-bold text-text-main dark:text-white focus:ring-0">
                            </div>
                            <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                <span class="text-[10px] text-text-sub font-bold uppercase">To</span>
                                <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="bg-transparent border-none p-0 text-sm font-bold text-text-main dark:text-white focus:ring-0">
                            </div>
                            <div class="flex items-center gap-2 px-3 py-2 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                                <span class="text-[10px] text-text-sub font-bold uppercase">Doc</span>
                                <select name="doctor_id" class="bg-transparent border-none p-0 text-sm font-bold text-text-main dark:text-white focus:ring-0 min-w-[100px]">
                                    <option value="all">All Doctors</option>
                                    <?php foreach($doctors as $d): ?>
                                        <option value="<?php echo $d['user_id']; ?>" <?php echo $doctor_id==$d['user_id']?'selected':''; ?>><?php echo htmlspecialchars($d['full_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="p-2 bg-primary text-white rounded-lg hover:bg-primary/90"><span class="material-symbols-outlined">filter_list</span></button>
                        </form>
                        <div class="h-8 w-px bg-gray-200 dark:bg-gray-700"></div>
                        <div class="flex gap-2">
                             <button onclick="setDateFilter('today')" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 text-xs font-bold rounded-lg transition">Today</button>
                             <button onclick="setDateFilter('week')" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 text-xs font-bold rounded-lg transition">This Week</button>
                             <button onclick="setDateFilter('month')" class="px-3 py-1.5 bg-gray-100 dark:bg-gray-800 hover:bg-gray-200 text-xs font-bold rounded-lg transition">This Month</button>
                        </div>
                    </div>

                    <!-- KPI Cards -->
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                        <div class="bg-surface-light dark:bg-surface-dark p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                            <div class="flex justify-between items-start mb-4"><div class="p-2 bg-purple-50 dark:bg-purple-900/20 rounded-lg"><span class="material-symbols-outlined text-primary text-2xl">attach_money</span></div></div>
                            <p class="text-text-sub text-xs font-semibold uppercase tracking-wide">Total Revenue (Profit)</p>
                            <h3 class="text-2xl font-bold mt-1">₱<?php echo number_format($total_revenue, 2); ?></h3>
                        </div>
                        <div class="bg-surface-light dark:bg-surface-dark p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                            <div class="flex justify-between items-start mb-4"><div class="p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg"><span class="material-symbols-outlined text-blue-600 text-2xl">receipt_long</span></div></div>
                            <p class="text-text-sub text-xs font-semibold uppercase tracking-wide">Invoices Issued</p>
                            <h3 class="text-2xl font-bold mt-1"><?php echo number_format($invoices_issued); ?></h3>
                        </div>
                         <div class="bg-surface-light dark:bg-surface-dark p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                            <div class="flex justify-between items-start mb-4"><div class="p-2 bg-amber-50 dark:bg-amber-900/20 rounded-lg"><span class="material-symbols-outlined text-amber-600 text-2xl">person_add</span></div></div>
                            <p class="text-text-sub text-xs font-semibold uppercase tracking-wide">New Patients (Period)</p>
                            <h3 class="text-2xl font-bold mt-1"><?php echo $new_patients_period; ?></h3>
                        </div>
                        <div class="bg-surface-light dark:bg-surface-dark p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                            <div class="flex justify-between items-start mb-4"><div class="p-2 bg-teal-50 dark:bg-teal-900/20 rounded-lg"><span class="material-symbols-outlined text-teal-600 text-2xl">event_available</span></div></div>
                            <p class="text-text-sub text-xs font-semibold uppercase tracking-wide">Total Appointments</p>
                            <h3 class="text-2xl font-bold mt-1"><?php echo number_format($appointments_period); ?></h3>
                        </div>
                    </div>

                    <!-- Revenue & Attendance -->
                    <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                        <div class="xl:col-span-2 bg-surface-light dark:bg-surface-dark p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                            <h3 class="text-lg font-bold text-text-main dark:text-white mb-6">Revenue Analysis</h3>
                            <div class="relative h-[300px] w-full"><canvas id="revenueChart"></canvas></div>
                        </div>
                        <div class="bg-surface-light dark:bg-surface-dark p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                            <h3 class="text-lg font-bold text-text-main dark:text-white mb-4">Appointment Attendance</h3>
                            <div class="space-y-4">
                                <?php 
                                $statuses = [
                                    ['label'=>'Completed', 'color'=>'bg-green-500', 'key'=>'Completed'],
                                    ['label'=>'No-Show', 'color'=>'bg-red-500', 'key'=>'No-Show'],
                                    ['label'=>'Cancelled', 'color'=>'bg-gray-400', 'key'=>'Cancelled'],
                                    ['label'=>'Walk-ins', 'color'=>'bg-blue-500', 'key'=>'Walk-in']
                                ];
                                $total_att = array_sum($attendance_data) ?: 1;
                                foreach($statuses as $st): 
                                    $cnt = $attendance_data[$st['key']] ?? 0;
                                    $pct = round(($cnt / $total_att)*100);
                                ?>
                                <div>
                                    <div class="flex justify-between text-sm mb-1">
                                        <span class="text-text-sub font-medium"><?php echo $st['label']; ?></span>
                                        <span class="font-bold text-text-main dark:text-white"><?php echo $cnt; ?> (<?php echo $pct; ?>%)</span>
                                    </div>
                                    <div class="h-2 w-full bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                                        <div class="h-full <?php echo $st['color']; ?>" style="width: <?php echo $pct; ?>%"></div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Transactions & Services -->
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                         <!-- Transactions (2 cols) -->
                         <div class="lg:col-span-2 bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden flex flex-col">
                            <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center">
                                <h3 class="text-lg font-bold text-text-main dark:text-white">Recent Transactions</h3>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead class="bg-gray-50 dark:bg-gray-800/50">
                                        <tr>
                                            <th class="px-6 py-4 text-xs font-bold text-text-sub uppercase">Patient</th>
                                            <th class="px-6 py-4 text-xs font-bold text-text-sub uppercase">Date</th>
                                            <th class="px-6 py-4 text-xs font-bold text-text-sub uppercase text-right">Amount</th>
                                            <th class="px-6 py-4 text-xs font-bold text-text-sub uppercase text-right">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        <?php foreach($transactions as $t): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/20">
                                            <td class="px-6 py-3 text-sm font-medium"><?php echo htmlspecialchars($t['patient_name']); ?></td>
                                            <td class="px-6 py-3 text-sm text-text-sub"><?php echo date('M d', strtotime($t['created_at'])); ?></td>
                                            <td class="px-6 py-3 text-sm font-bold text-right">₱<?php echo number_format($t['total_amount']); ?></td>
                                            <td class="px-6 py-3 text-right">
                                                <span class="text-xs font-bold px-2 py-1 rounded <?php echo $t['payment_status']=='Paid'?'bg-green-100 text-green-700':'bg-red-100 text-red-700'; ?>">
                                                    <?php echo htmlspecialchars($t['payment_status']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if(empty($transactions)): ?>
                                            <tr><td colspan="4" class="px-6 py-4 text-sm text-center text-text-sub">No transactions in this period.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Top Services (1 col) -->
                        <div class="bg-surface-light dark:bg-surface-dark p-6 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 h-fit">
                            <h3 class="text-lg font-bold text-text-main dark:text-white mb-4">Top Services</h3>
                             <div class="space-y-3">
                                <?php foreach($top_services as $idx => $srv): ?>
                                <div class="flex items-center gap-3 p-3 bg-gray-50 dark:bg-gray-800/50 rounded-xl">
                                    <div class="w-8 h-8 rounded-full bg-primary/10 text-primary flex-shrink-0 flex items-center justify-center font-bold text-sm">#<?php echo $idx+1; ?></div>
                                    <div class="min-w-0">
                                        <p class="text-sm font-bold text-text-main dark:text-white truncate"><?php echo htmlspecialchars($srv['service_name']); ?></p>
                                        <p class="text-xs text-text-sub"><?php echo $srv['usage_count']; ?> Completed</p>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php if(empty($top_services)): ?>
                                    <p class="text-text-sub text-sm">No services data available.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div> 
                <!-- END FINANCIAL TAB -->


                <!-- TAB 2: PATIENT DEMOGRAPHICS (ALL TIME) -->
                <div id="tab-demographics" class="tab-content hidden flex flex-col gap-6">
                    
                    <!-- Header -->
                    <div class="p-6 bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex justify-between items-center">
                        <div>
                            <h3 class="text-2xl font-bold text-text-main dark:text-white">Active Patient Demographics</h3>
                            <p class="text-text-sub">Analyzing <strong class="text-primary"><?php echo number_format($total_patients_all_time); ?></strong> total registered patients.</p>
                        </div>
                        <div class="px-4 py-2 bg-primary/10 text-primary rounded-xl text-sm font-bold">
                            All Time Data
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                        <!-- Gender Chart -->
                        <div class="bg-surface-light dark:bg-surface-dark p-8 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col items-center">
                            <h3 class="text-lg font-bold text-text-main dark:text-white mb-6 w-full text-left">Gender Distribution</h3>
                            <div class="relative h-64 w-64">
                                <canvas id="genderChart"></canvas>
                            </div>
                        </div>

                        <!-- Age Groups -->
                        <div class="bg-surface-light dark:bg-surface-dark p-8 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800">
                             <h3 class="text-lg font-bold text-text-main dark:text-white mb-6">Age Distribution</h3>
                             <div class="flex flex-col justify-center space-y-6 h-full pb-8">
                                 <?php 
                                 $age_labels = ['Minors (<18)', 'Young Adults (18-30)', 'Adults (31-50)', 'Seniors (51+)'];
                                 $total_ages = array_sum($age_groups) ?: 1;
                                 foreach($age_groups as $idx => $cnt):
                                    $pct = round(($cnt/$total_ages)*100);
                                 ?>
                                 <div>
                                     <div class="flex justify-between text-sm mb-2">
                                         <span class="font-bold text-text-main dark:text-white"><?php echo $age_labels[$idx]; ?></span>
                                         <span class="font-bold text-primary"><?php echo $cnt; ?> Patient(s)</span>
                                     </div>
                                     <div class="flex items-center gap-4">
                                         <div class="flex-1 h-3 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                                             <div class="h-full bg-primary" style="width: <?php echo $pct; ?>%"></div>
                                         </div>
                                         <span class="text-xs font-bold w-8"><?php echo $pct; ?>%</span>
                                     </div>
                                 </div>
                                 <?php endforeach; ?>
                             </div>
                        </div>
                    </div>
                </div>
                <!-- END DEMOGRAPHICS TAB -->

            </div>
        </div>
    </main>
</div>

<!-- Charts Script -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Revenue Chart (Financial Tab)
    new Chart(document.getElementById('revenueChart'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($chart_labels); ?>,
            datasets: [{
                label: 'Income',
                data: <?php echo json_encode($chart_data); ?>,
                borderColor: '#8a11d4',
                backgroundColor: 'rgba(138, 17, 212, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointRadius: 3
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                y: { grid: { color: 'rgba(0,0,0,0.05)' }, beginAtZero: true },
                x: { grid: { display: false } }
            }
        }
    });

    // Gender Chart (Demographics Tab)
    new Chart(document.getElementById('genderChart'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode($gender_labels); ?>,
            datasets: [{
                data: <?php echo json_encode($gender_counts); ?>,
                backgroundColor: <?php echo json_encode($gender_colors); ?>,
                borderWidth: 0,
                hoverOffset: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { 
                legend: { position: 'bottom', labels: { padding: 20, font: { size: 12 } } } 
            },
            cutout: '75%'
        }
    });
});
</script>
</body>
</html>