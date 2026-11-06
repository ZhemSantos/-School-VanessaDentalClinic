<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/theme_config.php';

// Configure secure session
configureSecureSession();

// Require admin role to access this page
requireRole('Admin');

// Update current user's activity
updateUserActivity();

// --- DASHBOARD LOGIC ---

// 1. KPI: Daily Revenue (Today vs Yesterday)
$today_revenue = 0;
$yesterday_revenue = 0;
$revenue_growth = 0;
try {
    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM tbl_invoice WHERE payment_status = 'Paid' AND DATE(paid_at) = CURDATE()");
    $stmt->execute();
    $today_revenue = $stmt->fetchColumn() ?: 0;

    $stmt = $pdo->prepare("SELECT SUM(total_amount) FROM tbl_invoice WHERE payment_status = 'Paid' AND DATE(paid_at) = CURDATE() - INTERVAL 1 DAY");
    $stmt->execute();
    $yesterday_revenue = $stmt->fetchColumn() ?: 0;

    if ($yesterday_revenue > 0) {
        $revenue_growth = (($today_revenue - $yesterday_revenue) / $yesterday_revenue) * 100;
    } else {
        $revenue_growth = $today_revenue > 0 ? 100 : 0;
    }
} catch (Exception $e) { }

// 2. KPI: Patient Stats
$total_patients = 0;
$new_patients_today = 0;
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_patient");
    $total_patients = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_patient WHERE DATE(registration_date) = CURDATE()");
    $new_patients_today = $stmt->fetchColumn();
} catch (Exception $e) { }

// 3. KPI: Staff Online
$staff_online = 0;
$total_staff = 0;
$staff_avatars = [];
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_user WHERE last_active > NOW() - INTERVAL 15 MINUTE");
    $staff_online = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_user");
    $total_staff = $stmt->fetchColumn();
    $stmt = $pdo->query("SELECT profile_picture, full_name, role FROM tbl_user WHERE last_active > NOW() - INTERVAL 15 MINUTE LIMIT 3");
    $staff_avatars = $stmt->fetchAll();
} catch (Exception $e) { }

// 4. Chart Data (Revenue)
$period = $_GET['period'] ?? 'monthly'; // 'monthly' (30 days) or 'weekly' (7 days)
$days = ($period === 'weekly') ? 7 : 30;
$chart_dates = [];
$chart_revenues = [];
try {
    $stmt = $pdo->prepare("
        SELECT DATE(paid_at) as date, SUM(total_amount) as daily_total 
        FROM tbl_invoice 
        WHERE payment_status = 'Paid' AND paid_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY DATE(paid_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$days]);
    $data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Fill gaps
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $chart_dates[] = date('M d', strtotime($d));
        $chart_revenues[] = $data[$d] ?? 0;
    }
} catch (Exception $e) { }

// 5. Staff Manager List
$staff_list = [];
try {
    $stmt = $pdo->query("SELECT * FROM tbl_user ORDER BY role='Dentist' DESC, full_name ASC");
    $staff_list = $stmt->fetchAll();
} catch (Exception $e) { }

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
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Admin Dashboard - Vanessa Nicolas Dental Clinic</title>
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
                        "primary": "#8a11d4",
                        "accent": "#FFD700",
                        "royal-purple": "#6A1B9A",
                        "clinic-gold": "#FFD700",
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
    </style>
    <!-- Add Chart.js (UMD version) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
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
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary group transition-all hover:bg-primary/20" href="admin_dashboard.php">
<span class="material-symbols-outlined fill-1">dashboard</span>
<p class="text-sm font-bold leading-normal">Dashboard</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_user.php">
<span class="material-symbols-outlined">group</span>
<p class="text-sm font-medium leading-normal">Users</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all" href="admin_patients.php">
<span class="material-symbols-outlined">accessibility_new</span>
<p class="text-sm font-medium leading-normal">Patients</p>
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
<a href="admin_registration_user.php" class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl h-12 bg-primary hover:bg-primary/90 text-white text-sm font-bold shadow-lg shadow-primary/30 transition-all">
<span class="material-symbols-outlined text-[20px]">person_add</span>
<span>Register Staff</span>
</a>
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
<h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Dashboard Overview</h2>
</div>
<div class="flex items-center gap-6">
<div class="flex gap-3">
<button class="btn-notifications relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors" aria-label="Notifications">
<span class="material-symbols-outlined text-[22px]">notifications</span>
<!-- Badge will be injected by JS -->
</button>
</div>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8 scroll-smooth">
<div class="max-w-[1200px] mx-auto flex flex-col gap-8 pb-10">
<div>
<h3 class="text-2xl font-extrabold text-text-main dark:text-white">Good Morning, Dr. Nicolas</h3>
<p class="text-text-sub dark:text-gray-400">Here is the latest update on your clinic's performance.</p>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div class="bg-surface-light dark:bg-surface-dark p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col justify-between h-[140px] relative overflow-hidden group">
                    <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                        <span class="material-symbols-outlined text-6xl text-primary">payments</span>
                    </div>
                    <div class="flex flex-col gap-1 z-10">
                        <p class="text-text-sub dark:text-gray-400 text-sm font-medium">Daily Revenue</p>
                        <p class="text-3xl font-bold text-text-main dark:text-white tracking-tight">₱<?php echo number_format($today_revenue, 2); ?></p>
                    </div>
                    <div class="flex items-center gap-1.5 z-10">
                        <span class="flex items-center justify-center <?php echo $revenue_growth >= 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; ?> rounded-full px-2 py-0.5 text-xs font-bold">
                            <span class="material-symbols-outlined text-[14px] mr-0.5"><?php echo $revenue_growth >= 0 ? 'trending_up' : 'trending_down'; ?></span> <?php echo number_format(abs($revenue_growth), 1); ?>%
                        </span>
                        <span class="text-xs text-text-sub dark:text-gray-500">vs yesterday</span>
                    </div>
                </div>
                <div class="bg-surface-light dark:bg-surface-dark p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col justify-between h-[140px] relative overflow-hidden group">
                    <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                        <span class="material-symbols-outlined text-6xl text-primary">vital_signs</span>
                    </div>
                    <div class="flex flex-col gap-1 z-10">
                        <p class="text-text-sub dark:text-gray-400 text-sm font-medium">Total Active Patients</p>
                        <p class="text-3xl font-bold text-text-main dark:text-white tracking-tight"><?php echo number_format($total_patients); ?></p>
                    </div>
                    <div class="flex items-center gap-1.5 z-10">
                        <span class="flex items-center justify-center bg-green-100 text-green-700 rounded-full px-2 py-0.5 text-xs font-bold">
                            +<?php echo $new_patients_today; ?> New
                        </span>
                        <span class="text-xs text-text-sub dark:text-gray-500">today</span>
                    </div>
                </div>
                <div class="bg-surface-light dark:bg-surface-dark p-5 rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col justify-between h-[140px] relative overflow-hidden group">
                    <div class="absolute right-0 top-0 p-4 opacity-10 group-hover:opacity-20 transition-opacity">
                        <span class="material-symbols-outlined text-6xl text-primary">stethoscope</span>
                    </div>
                    <div class="flex flex-col gap-1 z-10">
                        <p class="text-text-sub dark:text-gray-400 text-sm font-medium">Staff Online</p>
                        <div class="flex items-baseline gap-1">
                            <p class="text-3xl font-bold text-text-main dark:text-white tracking-tight"><?php echo $staff_online; ?></p>
                            <span class="text-sm text-text-sub">/ <?php echo $total_staff; ?></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 z-10">
                        <div class="flex -space-x-2">
                        <?php foreach($staff_avatars as $staff): ?>
                            <?php 
                                $s_photo = !empty($staff['profile_picture']) ? '../'.$staff['profile_picture'] : ''; 
                                if($s_photo && file_exists($s_photo)) {
                                    echo '<div class="w-6 h-6 rounded-full border-2 border-white dark:border-gray-800 bg-gray-200 bg-cover" title="'.htmlspecialchars($staff['full_name']).'" style="background-image: url(\''.htmlspecialchars($s_photo).'\')"></div>';
                                } else {
                                    echo '<div class="w-6 h-6 rounded-full border-2 border-white dark:border-gray-800 bg-primary flex items-center justify-center text-[10px] text-white font-bold" title="'.htmlspecialchars($staff['full_name']).'">'.substr($staff['full_name'],0,1).'</div>';
                                }
                            ?>
                        <?php endforeach; ?>
                        </div>
                    </div>
                </div>
</div>
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-800">
<div class="flex flex-wrap items-center justify-between mb-6 gap-4">
<div>
<h2 class="text-lg font-bold text-text-main dark:text-white">Financial Overview</h2>
<p class="text-sm text-text-sub">Clinic revenue performance over the last 30 days</p>
</div>
<div class="flex gap-2 p-1 bg-[#f3f0f4] dark:bg-gray-800 rounded-lg">
    <a href="?period=monthly" class="px-3 py-1.5 text-xs font-bold rounded-md shadow-sm transition-colors <?php echo $period === 'monthly' ? 'bg-white dark:bg-gray-700 text-text-main dark:text-white' : 'text-text-sub hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">Monthly</a>
    <a href="?period=weekly" class="px-3 py-1.5 text-xs font-bold rounded-md shadow-sm transition-colors <?php echo $period === 'weekly' ? 'bg-white dark:bg-gray-700 text-text-main dark:text-white' : 'text-text-sub hover:bg-gray-200 dark:hover:bg-gray-600'; ?>">Weekly</a>
</div>
</div>
                <div class="h-[280px] w-full relative">
                    <canvas id="revenueChart"></canvas>
                </div>
                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        const ctx = document.getElementById('revenueChart').getContext('2d');
                        
                        // Gradient
                        let gradient = ctx.createLinearGradient(0, 0, 0, 300);
                        gradient.addColorStop(0, 'rgba(138, 17, 212, 0.2)');
                        gradient.addColorStop(1, 'rgba(138, 17, 212, 0)');

                        new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels: <?php echo json_encode($chart_dates ?: []); ?>,
                                datasets: [{
                                    label: 'Daily Revenue',
                                    data: <?php echo json_encode($chart_revenues ?: []); ?>,
                                    borderColor: '#8a11d4',
                                    backgroundColor: gradient,
                                    borderWidth: 3,
                                    pointBackgroundColor: '#ffffff',
                                    pointBorderColor: '#8a11d4',
                                    pointRadius: 4,
                                    pointHoverRadius: 6,
                                    fill: true,
                                    tension: 0.4
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        mode: 'index',
                                        intersect: false,
                                        callbacks: {
                                            label: function(context) {
                                                let label = context.dataset.label || '';
                                                if (label) { label += ': '; }
                                                if (context.parsed.y !== null) {
                                                    label += new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(context.parsed.y);
                                                }
                                                return label;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    y: {
                                        beginAtZero: true,
                                        grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false },
                                        ticks: { callback: function(value) { return '₱' + value; } }
                                    },
                                    x: {
                                        grid: { display: false }
                                    }
                                }
                            }
                        });
                    });
                </script>
</div>
<div class="flex flex-col gap-4">
<div class="flex flex-col gap-4">
<div class="flex items-center justify-between">
<h2 class="text-lg font-bold text-text-main dark:text-white">Staff Manager</h2>
<div class="flex gap-2">
<button class="flex items-center gap-1 px-3 py-1.5 rounded-lg bg-[#f3f0f4] dark:bg-gray-800 text-xs font-bold text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">
<span class="material-symbols-outlined text-[16px]">filter_list</span>
                                        Filter
                                    </button>
</div>
</div>
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
<div class="overflow-x-auto">
<table class="w-full text-left border-collapse">
<thead>
<tr class="bg-[#f9f8fa] dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Staff Member</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Role</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Status</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider text-right">Actions</th>
</tr>
</thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach ($staff_list as $staff): ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors">
                                            <td class="p-4">
                                                <div class="flex items-center gap-3">
                                                    <?php 
                                                        $photo_path = !empty($staff['profile_picture']) ? '../'.$staff['profile_picture'] : '';
                                                        if($photo_path && file_exists($photo_path)) {
                                                            echo '<div class="w-10 h-10 rounded-full bg-cover bg-center" style="background-image: url(\''.htmlspecialchars($photo_path).'\')"></div>';
                                                        } else {
                                                            $def = getDefaultProfilePhoto($staff['full_name']);
                                                            echo '<div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold" style="background-color: '.$def['bg_color'].'; color: '.$def['text_color'].';">'.$def['initials'].'</div>';
                                                        }
                                                    ?>
                                                    <div>
                                                        <p class="text-sm font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($staff['full_name']); ?></p>
                                                        <p class="text-xs text-text-sub">ID: <?php echo htmlspecialchars($staff['username']); // Using username as ID display ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="p-4">
                                                <span class="text-sm font-medium text-text-main dark:text-gray-300"><?php echo htmlspecialchars($staff['role']); ?></span>
                                            </td>
                                            <td class="p-4">
                                                <?php if($staff['status'] === 'Active'): ?>
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700" data-user-id="<?php echo $staff['user_id']; ?>">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Active
                                                    </span>
                                                <?php else: ?>
                                                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600" data-user-id="<?php echo $staff['user_id']; ?>">
                                                        <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span> Inactive
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="p-4 text-right">
                                                <a href="admin_edit_user.php?id=<?php echo $staff['user_id']; ?>" class="text-text-sub hover:text-primary transition-colors p-1"><span class="material-symbols-outlined text-[20px]">edit</span></a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
</table>
</div>
<div class="p-3 border-t border-gray-100 dark:border-gray-800 flex justify-center">
<a href="admin_user.php" class="text-sm font-bold text-primary hover:underline">View All Staff</a>
</div>
</div>
</div>
</div>
</div>
</div>
</main>
</div>

    <script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
    <script>
    // Real-time activity tracking & Status Updates
    (function() {
        // 1. Update current user's activity every minute
        function updateActivity() {
            fetch('../api/update_activity.php').catch(e => console.error(e));
        }
        updateActivity();
        setInterval(updateActivity, 60000); // 1 minute
        
        // 2. Poll for other users' status every 10 seconds
        function updateStatusIndicators() {
            fetch('../api/get_users_status.php')
                .then(response => response.json())
                .then(data => {
                    if (!data.success) return;
                    
                    const statusElements = document.querySelectorAll('span[data-user-id]');
                    statusElements.forEach(element => {
                        const userId = element.getAttribute('data-user-id');
                        if (!userId || !data.users[userId]) return;
                        
                        const userInfo = data.users[userId];
                        let newStatus = 'Inactive';
                        let newClass = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600';
                        let dotClass = 'w-1.5 h-1.5 rounded-full bg-gray-400';
                        
                        if (userInfo.is_online) {
                            newStatus = 'Active'; // Dashboard uses "Active" text for online (or we can change to Online)
                            // Match dashboard styles
                            newClass = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700';
                            dotClass = 'w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse';
                        } else if (userInfo.status === 'Active') {
                             // "Away" logic if desired, or just keep as Active/Inactive. 
                             // Dashboard currently binary: Active or Inactive.
                             // Let's stick to binary for now to match PHP logic or introduce 'Away'.
                             // PHP logic (line 450) checks $staff['status'] === 'Active'.
                             // But 'is_online' is better.
                             // Let's mirror admin_user.php logic for consistency?
                             // actually dashboard PHP code just shows "Active" or "Inactive".
                             // Let's upgrade it to show "Online" if online.
                             newStatus = 'Away';
                             newClass = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700';
                             dotClass = 'w-1.5 h-1.5 rounded-full bg-yellow-500';
                        }
                        
                        // If inactive
                        if (!userInfo.is_online && userInfo.status !== 'Active') {
                             newStatus = 'Inactive';
                             newClass = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold bg-gray-100 text-gray-600';
                             dotClass = 'w-1.5 h-1.5 rounded-full bg-gray-400';
                        }
                        
                        // Update element
                        element.className = newClass;
                        
                        // Update dot
                        const dotSpan = element.querySelector('span');
                        if (dotSpan) {
                            dotSpan.className = dotClass;
                        }
                        
                        // Update text (last child text node)
                        const lastNode = element.lastChild;
                        if (lastNode && lastNode.nodeType === 3) {
                            lastNode.textContent = ' ' + newStatus;
                        } else {
                            // Fallback if structure is different
                            element.innerHTML = `<span class="${dotClass}"></span> ${newStatus}`;
                        }
                    });
                })
                .catch(e => console.error(e));
        }
        setInterval(updateStatusIndicators, 10000); // 10 seconds
    })();
    </script>
</body></html>