<?php
session_start();
require_once '../db.php';

// STRICT LOGIN CHECK
if (!isset($_SESSION['patient_id'])) {
    header("Location: ../landing.php");
    exit();
}

// Prevent Caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Check for temporary password force-reset
if (isset($_SESSION['patient_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT pa.is_temporary_password 
                               FROM tbl_patient p 
                               JOIN tbl_patient_accounts pa ON p.account_id = pa.account_id 
                               WHERE p.patient_id = ?");
        $stmt->execute([$_SESSION['patient_id']]);
        $status = $stmt->fetch();
        
        if ($status && $status['is_temporary_password'] == 1) {
            header("Location: ../reset_credentials.php");
            exit;
        }
    } catch (Exception $e) { }
}

$patient_id = $_SESSION['patient_id'] ?? 0;
$patient_name = $_SESSION['patient_name'] ?? 'Patient';

// --- DATA FETCHING ---

// 1. Upcoming Appointment
$upcoming_appt = null;
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as doctor_name 
        FROM tbl_appointment a
        LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.patient_id = ? 
        AND a.status IN ('Pending', 'Booked') 
        AND a.appointment_date >= CURDATE() 
        ORDER BY a.appointment_date ASC, a.time_slot ASC 
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $upcoming_appt = $stmt->fetch();
} catch (Exception $e) {}

// 2. Last Visit
$last_visit = null;
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as doctor_name 
        FROM tbl_appointment a
        LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.patient_id = ? 
        AND a.status = 'Completed' 
        ORDER BY a.appointment_date DESC 
        LIMIT 1
    ");
    $stmt->execute([$patient_id]);
    $last_visit = $stmt->fetch();
} catch (Exception $e) {}

// 3. Next Cleaning (6 months rule)
$next_cleaning = "Not Scheduled";
if ($last_visit) {
    $last_date = new DateTime($last_visit['appointment_date']);
    $last_date->modify('+6 months');
    $next_cleaning = $last_date->format('M d, Y');
} else {
    $next_cleaning = "Recommended Now";
}

// 4. Recent Activity (Appointments)
$recent_activity = [];
try {
    $stmt = $pdo->prepare("
        SELECT a.*, u.full_name as doctor_name 
        FROM tbl_appointment a
        LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.patient_id = ? 
        ORDER BY a.appointment_date DESC 
        LIMIT 5
    ");
    $stmt->execute([$patient_id]);
    $recent_activity = $stmt->fetchAll();
} catch (Exception $e) {}

// 5. Patient Files Count
$files_count = 0;
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_patient_file WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $files_count = $stmt->fetchColumn();
} catch (PDOException $e) { }

// 6. Pending Payments Count
$pending_payments = 0;
try {
    // Count unpaid appointment fees
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tbl_appointment 
        WHERE patient_id = ? 
        AND status IN ('Pending', 'Booked') 
        AND (appointment_fee_paid IS NULL OR appointment_fee_paid < 300)
    ");
    $stmt->execute([$patient_id]);
    $pending_payments = $stmt->fetchColumn();
    
    // Add unpaid invoices
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM tbl_invoice i
        JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        WHERE a.patient_id = ? AND i.payment_status = 'Pending'
    ");
    $stmt->execute([$patient_id]);
    $pending_payments += $stmt->fetchColumn();
} catch (Exception $e) {}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Vanessa Nicolas Dental Clinic</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
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
    <script>
    
    function showClinicPolicy() {
        document.getElementById('policyModal').classList.remove('hidden');
    }
    
    function closePolicyModal() {
        document.getElementById('policyModal').classList.add('hidden');
    }
</script>
    <style>
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-background-light font-display antialiased overflow-hidden">

<div class="flex h-screen w-full">
    <!-- Sidebar -->
    <aside class="w-72 bg-royal-purple text-white flex flex-col flex-shrink-0 h-full shadow-2xl z-20 relative">
        <div class="p-8 pb-4">
            <a href="client_dashboard.php" class="flex flex-col gap-1">
                <h1 class="text-xl font-bold leading-tight tracking-wide">Vanessa Nicolas</h1>
                <p class="text-white/70 text-sm font-medium">Dental Clinic System</p>
            </a>
        </div>
        
        <!-- User Widget -->
        <div class="px-8 py-4 mb-2">
            <a href="client_profile_info.php" class="block">
                <div class="flex items-center gap-3 bg-white/10 p-3 rounded-xl border border-white/10 backdrop-blur-sm hover:bg-white/20 transition-all cursor-pointer">
                    <div class="w-10 h-10 rounded-full bg-clinic-gold text-royal-purple flex items-center justify-center font-bold text-lg flex-shrink-0">
                        <?php echo strtoupper(substr($patient_name, 0, 1)); ?>
                    </div>
                <div class="flex flex-col overflow-hidden">
                    <span class="text-sm font-bold truncate"><?php echo htmlspecialchars($patient_name); ?></span>
                    <span class="text-xs text-white/70 truncate">ID: <?php echo str_pad($patient_id, 4, '0', STR_PAD_LEFT); ?></span>
                </div>
            </div>
            </a>
        </div>
        
        <!-- Navigation -->
        <nav class="flex-1 px-4 gap-2 flex flex-col overflow-y-auto py-4">
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl bg-white text-royal-purple shadow-lg transition-all group" href="client_dashboard.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="font-bold">Dashboard</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_appointment.php">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="font-medium">Appointments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-medium">Payments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_feedback.php">
                <span class="material-symbols-outlined">reviews</span>
                <span class="font-medium">Feedback</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_medical_records.php">
                <span class="material-symbols-outlined">medical_information</span>
                <span class="font-medium">Medical Records</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_dependencies.php">
                <span class="material-symbols-outlined">group</span>
                <span class="font-medium">My Dependents</span>
            </a>
        </nav>
        
        <!-- Footer -->
        <div class="p-6">
            <div class="text-xs text-white/40 text-center font-medium">v2.0.0 • Client Portal</div>
            <a href="../logout.php" class="flex items-center justify-center gap-2 mt-4 w-full py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium">
                <span class="material-symbols-outlined text-[18px]">logout</span>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
        <!-- Header -->
        <header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
            <div class="flex flex-col gap-1">
                <h2 class="text-3xl font-black text-[#151118] tracking-tight">Good <?php 
                    $hour = date('H');
                    echo ($hour < 12) ? 'Morning' : (($hour < 18) ? 'Afternoon' : 'Evening');
                ?>, <?php echo explode(' ', $patient_name)[0]; ?>!</h2>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">health_and_safety</span>
                    Here is your health overview for today.
                </p>
            </div>
            <div class="flex items-center gap-3">
                <!-- Notification Bell -->
                <div class="relative">
                    <button onclick="toggleNotifications()" class="relative flex items-center justify-center w-11 h-11 bg-white hover:bg-purple-50 border-2 border-purple-200 rounded-lg transition-all shadow-sm hover:shadow-md" title="Notifications">
                        <span class="material-symbols-outlined text-purple-600">notifications</span>
                        <span id="notificationBadge" class="hidden absolute -top-1 -right-1 w-5 h-5 bg-red-500 text-white text-xs font-bold rounded-full flex items-center justify-center"></span>
                    </button>
                    
                    <!-- Notification Dropdown -->
                    <div id="notificationDropdown" class="hidden absolute right-0 mt-2 w-96 bg-white rounded-xl shadow-2xl border border-gray-200 z-50 max-h-96 overflow-hidden flex flex-col">
                        <div class="p-4 border-b border-gray-200 flex items-center justify-between bg-gradient-to-r from-purple-50 to-purple-100">
                            <h3 class="font-bold text-gray-800 flex items-center gap-2">
                                <span class="material-symbols-outlined text-purple-600">notifications_active</span>
                                Notifications
                            </h3>
                            <button onclick="markAllAsRead()" class="text-xs text-purple-600 hover:text-purple-800 font-semibold">Mark all read</button>
                        </div>
                        <div id="notificationList" class="overflow-y-auto flex-1">
                            <div class="p-8 text-center text-gray-400">
                                <span class="material-symbols-outlined text-4xl mb-2">notifications_off</span>
                                <p>No notifications</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <button onclick="showClinicPolicy()" class="flex items-center gap-2 px-4 py-2.5 bg-gradient-to-r from-amber-500 to-amber-600 hover:from-amber-600 hover:to-amber-700 text-white font-bold rounded-lg transition-all shadow-lg hover:shadow-xl" title="Clinic Appointment Policy">
                    <span class="material-symbols-outlined text-xl">shield</span>
                    Policy
                </button>
                <a href="client_appointment.php" class="flex items-center gap-2 px-5 py-2.5 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-lg transition-all shadow-lg hover:shadow-xl">
                    <span class="material-symbols-outlined text-xl">add_circle</span>
                    <span>Book Appointment</span>
                </a>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-[1400px] mx-auto flex flex-col gap-6">
                
                <!-- Holiday Banner -->
                <div id="holidayBanner" class="hidden">
                    <div id="bannerContent" class="rounded-xl border p-4 flex items-start gap-4 shadow-sm bg-purple-50 border-purple-100">
                        <div id="bannerIconContainer" class="p-2 rounded-full bg-purple-100 text-purple-700 flex-shrink-0 flex items-center justify-center">
                            <span id="bannerIcon" class="material-symbols-outlined text-xl">event</span>
                        </div>
                        <div class="flex-1">
                            <h4 class="font-bold text-sm mb-1 text-purple-900" id="bannerTitle">Clinic Holiday Notice</h4>
                            <div id="holidayList" class="text-sm space-y-1 text-purple-800"></div>
                        </div>
                        <button onclick="document.getElementById('holidayBanner').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition-colors">
                            <span class="material-symbols-outlined">close</span>
                        </button>
                    </div>
                </div>

                <script>
                    document.addEventListener('DOMContentLoaded', () => {
                        fetch('../api/get_upcoming_holidays.php?t=' + Date.now())
                            .then(r => r.json())
                            .then(data => {
                                if (data.success && data.holidays.length > 0) {
                                    const list = document.getElementById('holidayList');
                                    const banner = document.getElementById('holidayBanner');
                                    const content = document.getElementById('bannerContent');
                                    const iconContainer = document.getElementById('bannerIconContainer');
                                    const icon = document.getElementById('bannerIcon');
                                    const title = document.getElementById('bannerTitle');
                                    
                                    const now = new Date();
                                    now.setHours(0,0,0,0);
                                    let minDiff = 999;
                                    const msPerDay = 1000 * 60 * 60 * 24;
                                    const visibleHolidays = [];

                                    data.holidays.forEach(h => {
                                        const dateParts = h.block_date.split('-');
                                        const hDate = new Date(dateParts[0], dateParts[1]-1, dateParts[2]);
                                        const diffTime = hDate - now;
                                        const diffDays = Math.ceil(diffTime / msPerDay);
                                        
                                        if (diffDays >= -1) {
                                            if (diffDays < minDiff) minDiff = diffDays;
                                            visibleHolidays.push({...h, diffDays});
                                        }
                                    });
                                    
                                    if (visibleHolidays.length > 0) {
                                        // Color Logic
                                        let theme = {
                                            bg: 'bg-purple-50', border: 'border-purple-100', 
                                            text: 'text-purple-800', title: 'text-purple-900',
                                            iconBg: 'bg-purple-100', iconText: 'text-purple-700', icon: 'event'
                                        };

                                        if (minDiff <= 2) { // Red
                                            theme = {
                                                bg: 'bg-red-50', border: 'border-red-100', 
                                                text: 'text-red-800', title: 'text-red-900',
                                                iconBg: 'bg-red-100', iconText: 'text-red-700', icon: 'warning'
                                            };
                                        } else if (minDiff <= 5) { // Orange
                                            theme = {
                                                bg: 'bg-orange-50', border: 'border-orange-100', 
                                                text: 'text-orange-800', title: 'text-orange-900',
                                                iconBg: 'bg-orange-100', iconText: 'text-orange-700', icon: 'priority_high'
                                            };
                                        }

                                        // Apply Theme
                                        content.className = `rounded-xl border p-4 flex items-start gap-4 shadow-sm ${theme.bg} ${theme.border}`;
                                        iconContainer.className = `p-2 rounded-full ${theme.iconBg} ${theme.iconText} flex-shrink-0 flex items-center justify-center`;
                                        icon.textContent = theme.icon;
                                        title.className = `font-bold text-sm mb-1 ${theme.title}`;
                                        list.className = `text-sm space-y-1 ${theme.text}`;

                                        // Render Items
                                        visibleHolidays.forEach(h => {
                                            const div = document.createElement('div');
                                            div.innerHTML = `<span class="font-bold">${h.formatted_date}:</span> ${h.reason} <span class="bg-white/50 px-1.5 py-0.5 rounded text-xs font-bold ml-1">${h.display_time}</span>`;
                                            list.appendChild(div);
                                        });
                                        
                                        banner.classList.remove('hidden');
                                    }
                                }
                            })
                            .catch(e => console.error(e));
                    });
                </script>
                
                <!-- Stats Cards Row -->
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <!-- Upcoming Appointment -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-lg transition-shadow">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Upcoming Appointment</p>
                                <?php if ($upcoming_appt): ?>
                                    <h3 class="text-xl font-bold text-royal-purple"><?php echo date('M d, Y', strtotime($upcoming_appt['appointment_date'])); ?></h3>
                                    <p class="text-sm text-slate-600 mt-1"><?php echo date('g:i A', strtotime($upcoming_appt['time_slot'])); ?></p>
                                    <p class="text-xs text-slate-500 mt-2"><?php echo htmlspecialchars($upcoming_appt['doctor_name'] ?? 'Doctor TBA'); ?></p>
                                <?php else: ?>
                                    <h3 class="text-xl font-bold text-slate-400">No Upcoming</h3>
                                    <p class="text-sm text-slate-500 mt-1">Book your next visit</p>
                                <?php endif; ?>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-purple-50 text-royal-purple flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-2xl">calendar_month</span>
                            </div>
                        </div>
                        <?php if ($upcoming_appt): ?>
                            <span class="inline-block px-3 py-1 bg-yellow-50 text-yellow-700 rounded-full text-xs font-bold">Scheduled</span>
                        <?php endif; ?>
                    </div>

                    <!-- Last Visit -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-lg transition-shadow">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Last Visit</p>
                                <?php if ($last_visit): ?>
                                    <h3 class="text-xl font-bold text-royal-purple"><?php echo date('M d, Y', strtotime($last_visit['appointment_date'])); ?></h3>
                                    <p class="text-xs text-slate-500 mt-2"><?php echo htmlspecialchars($last_visit['doctor_name'] ?? 'Doctor'); ?></p>
                                <?php else: ?>
                                    <h3 class="text-xl font-bold text-slate-400">No History</h3>
                                    <p class="text-sm text-slate-500 mt-1">First visit pending</p>
                                <?php endif; ?>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-green-50 text-green-600 flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-2xl">history</span>
                            </div>
                        </div>
                    </div>

                    <!-- Next Cleaning -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-lg transition-shadow">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Next Cleaning Due</p>
                                <h3 class="text-xl font-bold text-clinic-gold"><?php echo $next_cleaning; ?></h3>
                                <p class="text-xs text-slate-500 mt-2">6-month checkup cycle</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-yellow-50 text-clinic-gold flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-2xl">dentistry</span>
                            </div>
                        </div>
                    </div>

                    <!-- Pending Payments -->
                    <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hover:shadow-lg transition-shadow">
                        <div class="flex items-start justify-between mb-4">
                            <div class="flex-1">
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Pending Payments</p>
                                <h3 class="text-xl font-bold text-royal-purple"><?php echo $pending_payments; ?></h3>
                                <p class="text-xs text-slate-500 mt-2">Outstanding items</p>
                            </div>
                            <div class="w-12 h-12 rounded-xl bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0">
                                <span class="material-symbols-outlined text-2xl">payments</span>
                            </div>
                        </div>
                        <?php if ($pending_payments > 0): ?>
                            <a href="client_payment.php" class="text-xs font-bold text-royal-purple hover:underline">View Payments →</a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Recent Activity & Quick Actions -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Recent Activity -->
                    <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-xl font-bold text-[#151118] mb-4">Recent Activity</h3>
                        <div class="overflow-x-auto">
                            <table class="w-full">
                                <thead>
                                    <tr class="border-b border-gray-100">
                                        <th class="text-left text-xs font-bold text-slate-500 uppercase tracking-wide pb-3">Date</th>
                                        <th class="text-left text-xs font-bold text-slate-500 uppercase tracking-wide pb-3">Doctor</th>
                                        <th class="text-left text-xs font-bold text-slate-500 uppercase tracking-wide pb-3">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_activity)): ?>
                                        <tr>
                                            <td colspan="3" class="text-center py-8 text-slate-400">
                                                <span class="material-symbols-outlined text-4xl mb-2 block">event_busy</span>
                                                No recent activity
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_activity as $act): 
                                            $status_class = 'bg-purple-50 text-purple-700';
                                            if ($act['status'] == 'Completed') $status_class = 'bg-green-50 text-green-700';
                                            if ($act['status'] == 'Pending') $status_class = 'bg-yellow-50 text-yellow-700';
                                            if ($act['status'] == 'Cancelled') $status_class = 'bg-red-50 text-red-700';
                                        ?>
                                        <tr class="border-b border-gray-50 hover:bg-gray-50 transition-colors">
                                            <td class="py-3 font-bold text-sm"><?php echo date('M d, Y', strtotime($act['appointment_date'])); ?></td>
                                            <td class="py-3 text-sm text-slate-600"><?php echo htmlspecialchars($act['doctor_name'] ?? 'Doctor TBA'); ?></td>
                                            <td class="py-3">
                                                <span class="inline-block px-3 py-1 rounded-full text-xs font-bold <?php echo $status_class; ?>">
                                                    <?php echo $act['status']; ?>
                                                </span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Quick Actions & Info -->
                    <div class="flex flex-col gap-6">
                        <!-- Medical Records -->
                        <div class="bg-royal-purple rounded-2xl shadow-sm p-6 text-white">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <p class="text-xs font-bold text-white/70 uppercase tracking-wide mb-2">Medical Files</p>
                                    <h3 class="text-3xl font-bold"><?php echo $files_count; ?></h3>
                                </div>
                                <div class="w-12 h-12 rounded-xl bg-white/10 flex items-center justify-center">
                                    <span class="material-symbols-outlined text-2xl">folder_open</span>
                                </div>
                            </div>
                            <a href="client_medical_records.php" class="text-xs font-bold text-clinic-gold hover:underline">View Records →</a>
                        </div>

                        <!-- Emergency Contact -->
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <div class="flex items-center gap-3 mb-3">
                                <div class="w-10 h-10 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                                    <span class="material-symbols-outlined">emergency</span>
                                </div>
                                <div>
                                    <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">Emergency</p>
                                    <p class="text-sm font-bold text-royal-purple">0912-345-6789</p>
                                </div>
                            </div>
                            <p class="text-xs text-slate-500">Available 24/7 for urgent dental care</p>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

<!-- Clinic Policy Modal -->
<div id="policyModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl max-h-[85vh] overflow-hidden">
        <div class="bg-gradient-to-r from-amber-500 to-amber-600 p-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="material-symbols-outlined text-white text-4xl">shield</span>
                <h2 class="text-2xl font-black text-white">Clinic Appointment Policy</h2>
            </div>
            <button onclick="closePolicyModal()" class="text-white hover:bg-white/20 rounded-full p-2 transition-colors">
                <span class="material-symbols-outlined text-3xl">close</span>
            </button>
        </div>
        <div class="p-6 overflow-y-auto max-h-[calc(85vh-100px)]">
            <div class="space-y-6">
                <!-- One Appointment Rule -->
                <div class="border-l-4 border-purple-500 pl-4">
                    <h3 class="text-lg font-bold text-purple-900 mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined">event</span>
                        One Appointment Per Patient
                    </h3>
                    <p class="text-slate-700">Each patient may only have <strong>one active appointment</strong> at a time. You must cancel your existing appointment before booking a new one.</p>
                </div>

                <!-- Payment Priority -->
                <div class="border-l-4 border-amber-500 pl-4">
                    <h3 class="text-lg font-bold text-amber-900 mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined">payments</span>
                        Payment Priority System
                    </h3>
                    <p class="text-slate-700 mb-2"><strong>Pending (Unpaid) Appointments:</strong></p>
                    <ul class="list-disc list-inside text-slate-700 space-y-1 ml-4">
                        <li>Are <strong>NOT protected</strong> and may be overridden</li>
                        <li>Can be taken by other patients who complete payment first</li>
                        <li>You will receive email notification if your appointment is cancelled</li>
                    </ul>
                    <p class="text-slate-700 mt-3 mb-2"><strong>Booked (Paid) Appointments:</strong></p>
                    <ul class="list-disc list-inside text-slate-700 space-y-1 ml-4">
                        <li>Are <strong>fully protected</strong> and cannot be overridden</li>
                        <li>Your time slot is secured and guaranteed</li>
                    </ul>
                </div>

                <!-- Clinic Hours -->
                <div class="border-l-4 border-blue-500 pl-4">
                    <h3 class="text-lg font-bold text-blue-900 mb-2 flex items-center gap-2">
                        <span class="material-symbols-outlined">schedule</span>
                        Operating Hours
                    </h3>
                    <p class="text-slate-700">All appointments must be scheduled within clinic operating hours: <strong>9:00 AM - 7:00 PM</strong></p>
                    <p class="text-slate-600 text-sm mt-2">Time slots shown are based on both clinic hours and your selected doctor's availability.</p>
                </div>

                <!-- Recommendation -->
                <div class="bg-green-50 border border-green-200 rounded-lg p-4">
                    <div class="flex items-start gap-3">
                        <span class="material-symbols-outlined text-green-600 text-2xl">lightbulb</span>
                        <div>
                            <h4 class="font-bold text-green-900 mb-1">Recommendation</h4>
                            <p class="text-green-800 text-sm">To secure your appointment and avoid cancellation, please complete payment as soon as possible after booking.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Notification System
let notificationDropdownOpen = false;

function toggleNotifications() {
    const dropdown = document.getElementById('notificationDropdown');
    notificationDropdownOpen = !notificationDropdownOpen;
    
    if (notificationDropdownOpen) {
        dropdown.classList.remove('hidden');
        loadNotifications();
    } else {
        dropdown.classList.add('hidden');
    }
}

function loadNotifications() {
    fetch('../api/api_notifications.php?action=get_notifications&limit=20')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                renderNotifications(data.notifications);
            }
        })
        .catch(e => console.error('Error loading notifications:', e));
}

function renderNotifications(notifications) {
    const list = document.getElementById('notificationList');
    
    if (notifications.length === 0) {
        list.innerHTML = `
            <div class="p-8 text-center text-gray-400">
                <span class="material-symbols-outlined text-4xl mb-2">notifications_off</span>
                <p>No notifications</p>
            </div>
        `;
        return;
    }

    list.innerHTML = notifications.map(notif => {
        const isUnread = notif.is_read == 0;
        const iconMap = {
            'appointment': 'event',
            'payment': 'payments',
            'general': 'info'
        };
        const colorMap = {
            'appointment': 'purple',
            'payment': 'green',
            'general': 'blue'
        };
        const icon = iconMap[notif.type] || 'info';
        const color = colorMap[notif.type] || 'blue';
        
        return `
            <div class="p-4 border-b border-gray-100 hover:bg-gray-50 transition-colors ${isUnread ? 'bg-purple-50' : ''}" onclick="markAsRead(${notif.notification_id})">
                <div class="flex gap-3">
                    <div class="w-10 h-10 rounded-full bg-${color}-100 flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-${color}-600 text-lg">${icon}</span>
                    </div>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-start justify-between gap-2 mb-1">
                            <h4 class="font-bold text-sm text-gray-800 ${isUnread ? 'text-purple-900' : ''}">${notif.title}</h4>
                            ${isUnread ? '<span class="w-2 h-2 bg-red-500 rounded-full flex-shrink-0 mt-1"></span>' : ''}
                        </div>
                        <p class="text-xs text-gray-600 mb-2">${notif.message}</p>
                        <p class="text-xs text-gray-400">${formatNotificationTime(notif.created_at)}</p>
                    </div>
                </div>
            </div>
        `;
    }).join('');
}

function formatNotificationTime(timestamp) {
    const date = new Date(timestamp);
    const now = new Date();
    const diff = now - date;
    const minutes = Math.floor(diff / 60000);
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes}m ago`;
    if (hours < 24) return `${hours}h ago`;
    if (days < 7) return `${days}d ago`;
    return date.toLocaleDateString();
}

function markAsRead(notificationId) {
    fetch('../api/api_notifications.php?action=mark_read', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({notification_id: notificationId})
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
            updateNotificationBadge();
        }
    });
}

function markAllAsRead() {
    fetch('../api/api_notifications.php?action=mark_all_read', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'}
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            loadNotifications();
            updateNotificationBadge();
        }
    });
}

function updateNotificationBadge() {
    fetch('../api/api_notifications.php?action=get_unread_count')
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const badge = document.getElementById('notificationBadge');
                if (data.count > 0) {
                    badge.textContent = data.count > 9 ? '9+' : data.count;
                    badge.classList.remove('hidden');
                } else {
                    badge.classList.add('hidden');
                }
            }
        });
}

// Close dropdown when clicking outside
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('notificationDropdown');
    const button = event.target.closest('button[onclick="toggleNotifications()"]');
    
    if (!button && !dropdown.contains(event.target) && notificationDropdownOpen) {
        toggleNotifications();
    }
});

// Update badge on page load and every 30 seconds
updateNotificationBadge();
setInterval(updateNotificationBadge, 30000);
</script>

</body>
</html>
