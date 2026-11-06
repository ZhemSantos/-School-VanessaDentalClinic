<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/notification_helper.php';
require_once '../includes/auto_cancel.php'; // Run auto-cancellation check

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
        ['bg' => '#FFD700', 'text' => '#6A1B9A'], // Gold with purple text
        ['bg' => '#6A1B9A', 'text' => '#ffffff'], // Royal purple
        ['bg' => '#8a11d4', 'text' => '#ffffff'], // Primary purple
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
    if (isset($_POST['cancel_appointment'])) {
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        if ($appointment_id > 0) {
            try {
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
                
                $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = 'Cancelled' WHERE appointment_id = ?");
                $update_stmt->execute([$appointment_id]);
                
                // Send cancellation email
                if ($appointment) {
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
                    }

                    // Create system notification for the doctor
                    try {
                        $pat_name = $appointment['first_name'] . ' ' . $appointment['last_name'];
                        $appt_date = date('M d', strtotime($appointment['appointment_date']));
                        $appt_time = date('g:i A', strtotime($appointment['time_slot']));
                        $notif_message = "Appointment with {$pat_name} on {$appt_date} at {$appt_time} has been cancelled.";
                        
                        // Notify the doctor
                        createNotification($pdo, $appointment['doctor_id'], 'Dentist', 'Appointment', 'Appointment Cancelled', $notif_message, 'doctor_dashboard.php');
                    } catch (Exception $e) {
                        error_log("Error creating cancellation notification: " . $e->getMessage());
                    }
                }
                
                $action_message = 'Appointment cancelled successfully!';
                $action_type = 'success';
            } catch (PDOException $e) {
                error_log('Error cancelling appointment: ' . $e->getMessage());
                $action_message = 'Failed to cancel appointment.';
                $action_type = 'error';
            }
        }
    }
}

// Fetch dashboard statistics
$stats = [
    'patients_waiting' => 0,
    'today_appointments' => 0,
    'pending_payments' => 0
];

try {
    $today_date = date('Y-m-d');
    
    // Count patients waiting (appointments with status Arrived for today)
    $waiting_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_appointment WHERE appointment_date = ? AND status = 'Arrived'");
    $waiting_stmt->execute([$today_date]);
    $stats['patients_waiting'] = $waiting_stmt->fetchColumn();
    
    // Count today's appointments
    $today_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_appointment WHERE appointment_date = ?");
    $today_stmt->execute([$today_date]);
    $stats['today_appointments'] = $today_stmt->fetchColumn();
    
    // Count pending payments
    $pending_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_invoice WHERE payment_status = 'Pending'");
    $pending_stmt->execute();
    $stats['pending_payments'] = $pending_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Error fetching dashboard stats: ' . $e->getMessage());
}

// Fetch live queue (today's appointments)
$queue_items = [];
try {
    $today_date = date('Y-m-d');
    $queue_stmt = $pdo->prepare("
        SELECT a.appointment_id, a.time_slot, a.status, 
               p.patient_id, p.first_name, p.last_name,
               u.full_name as doctor_name,
               GROUP_CONCAT(DISTINCT s.service_name SEPARATOR ', ') as service_name,
               (SELECT Payment_Status FROM tbl_invoice WHERE appointment_id = a.appointment_id OR record_id IN (SELECT record_id FROM tbl_medical_record WHERE appointment_id = a.appointment_id) ORDER BY invoice_id DESC LIMIT 1) as payment_status
        FROM tbl_appointment a
        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        INNER JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.appointment_date = ?
        GROUP BY a.appointment_id
        ORDER BY a.time_slot ASC
        LIMIT 20
    ");
    $queue_stmt->execute([$today_date]);
    $queue_items = $queue_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching queue: ' . $e->getMessage());
}

// Fetch dentist availability
$dentists = [];
try {
    $today_date = date('Y-m-d');
    $dentist_stmt = $pdo->prepare("
        SELECT u.user_id, u.full_name, u.profile_picture,
               COUNT(a.appointment_id) as active_appointments
        FROM tbl_user u
        LEFT JOIN tbl_appointment a ON u.user_id = a.doctor_id 
            AND a.appointment_date = ? 
            AND a.status IN ('Pending', 'Booked')
        WHERE u.role = 'Dentist' AND u.status = 'Active'
        GROUP BY u.user_id, u.full_name, u.profile_picture
    ");
    $dentist_stmt->execute([$today_date]);
    $dentists = $dentist_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching dentists: ' . $e->getMessage());
}
?>
<!DOCTYPE html>

<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Receptionist Dashboard - Vanessa Nicolas Dental</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
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
        /* Custom Scrollbar for the table area */
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
<!-- Sidebar Navigation -->
<aside class="w-72 bg-royal-purple text-white flex flex-col flex-shrink-0 h-full shadow-2xl z-20 relative">
<div class="p-8 pb-4">
<div class="flex flex-col gap-1">
<h1 class="text-xl font-bold leading-tight tracking-wide">Vanessa Nicolas</h1>
<p class="text-white/70 text-sm font-medium">Dental Clinic System</p>
</div>
</div>
<!-- Profile Snippet -->
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
<!-- Main Content Area -->
<main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
<!-- Top Header & Actions -->
<header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
<div class="flex flex-col gap-1">
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Good <?php echo date('H') < 12 ? 'Morning' : (date('H') < 18 ? 'Afternoon' : 'Evening'); ?>, <?php echo htmlspecialchars(explode(' ', $current_name)[0]); ?></h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">schedule</span>
                        <?php echo date('l, M j') . ' • ' . date('g:i A'); ?>
                    </p>
</div>
<div class="flex items-center gap-4">
<!-- Search Bar -->
<label class="relative hidden lg:flex items-center h-12 w-80">
<div class="absolute left-4 text-slate-400 flex items-center justify-center">
<span class="material-symbols-outlined">search</span>
</div>
<input class="w-full h-full pl-12 pr-4 bg-white border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400 shadow-sm" placeholder="Search patient by name, ID..."/>
</label>
<!-- Notifications -->
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
<!-- Primary Action Button -->
<a href="receptionist_registration.php" class="h-12 px-6 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
<span class="material-symbols-outlined">add_circle</span>
<span>Quick Register</span>
</a>
</div>
</header>
<!-- Scrollable Content -->
<div class="flex-1 overflow-y-auto p-8">
<div class="max-w-[1600px] mx-auto flex flex-col gap-6">
    
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
<?php if ($action_message): ?>
<div class="bg-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-50 border border-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-200 rounded-xl p-4 mb-6">
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-600"><?php echo $action_type === 'success' ? 'check_circle' : 'error'; ?></span>
<p class="text-<?php echo $action_type === 'success' ? 'green' : 'red'; ?>-800 font-medium"><?php echo htmlspecialchars($action_message); ?></p>
</div>
</div>
<?php endif; ?>
<!-- Stats Row -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
<!-- Stat Card 1 -->
<div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group">
<div class="absolute right-0 top-0 w-24 h-24 bg-green-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
<div class="relative z-10 flex justify-between items-start">
<div>
<p class="text-slate-500 font-medium text-sm mb-1">Patients Waiting</p>
<h3 class="text-4xl font-bold text-[#151118]"><?php echo $stats['patients_waiting']; ?></h3>
</div>
<div class="w-10 h-10 rounded-full bg-green-100 text-green-700 flex items-center justify-center">
<span class="material-symbols-outlined">hourglass_top</span>
</div>
</div>
<div class="relative z-10 flex items-center gap-1 mt-auto">
<span class="text-green-600 text-xs font-bold bg-green-50 px-2 py-1 rounded-full">+2 new</span>
<span class="text-slate-400 text-xs">since last hour</span>
</div>
</div>
<!-- Stat Card 2 -->
<div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group">
<div class="absolute right-0 top-0 w-24 h-24 bg-purple-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
<div class="relative z-10 flex justify-between items-start">
<div>
<p class="text-slate-500 font-medium text-sm mb-1">Today's Appointments</p>
<h3 class="text-4xl font-bold text-[#151118]"><?php echo $stats['today_appointments']; ?></h3>
</div>
<div class="w-10 h-10 rounded-full bg-purple-100 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined">event_available</span>
</div>
</div>
<div class="relative z-10 flex items-center gap-1 mt-auto">
<span class="text-slate-500 text-xs">Next at 09:00 AM</span>
</div>
</div>
<!-- Stat Card 3 -->
<div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex flex-col justify-between h-32 relative overflow-hidden group">
<div class="absolute right-0 top-0 w-24 h-24 bg-yellow-50 rounded-bl-full -mr-4 -mt-4 transition-transform group-hover:scale-110"></div>
<div class="relative z-10 flex justify-between items-start">
<div>
<p class="text-slate-500 font-medium text-sm mb-1">Pending Payments</p>
<h3 class="text-4xl font-bold text-[#151118]"><?php echo $stats['pending_payments']; ?></h3>
</div>
<div class="w-10 h-10 rounded-full bg-yellow-100 text-yellow-700 flex items-center justify-center">
<span class="material-symbols-outlined">receipt_long</span>
</div>
</div>
<div class="relative z-10 flex items-center gap-1 mt-auto">
<span class="text-slate-500 text-xs font-medium cursor-pointer hover:text-royal-purple underline decoration-dotted">View all pending</span>
</div>
</div>
</div>
<!-- Split Layout -->
<div class="flex flex-col xl:flex-row gap-6 h-full min-h-[500px]">
<!-- Left: Live Queue (Major Widget) -->
<div id="queue" class="flex-1 bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden">
<div class="p-6 border-b border-gray-100 flex justify-between items-center bg-gray-50/50">
<div class="flex items-center gap-2">
<div class="h-2 w-2 rounded-full bg-green-500 animate-pulse"></div>
<h3 class="font-bold text-lg text-royal-purple">Live Queue Monitor</h3>
</div>
<div class="flex gap-2">
<a href="receptionist_queue.php" class="px-3 py-1.5 text-xs font-bold text-royal-purple bg-purple-50 rounded-lg hover:bg-purple-100 transition-colors flex items-center gap-1">
<span class="material-symbols-outlined text-sm">open_in_new</span>
Expand
</a>
</div>
</div>
<div class="overflow-x-auto flex-1">
<table class="w-full text-left text-sm whitespace-nowrap">
<thead class="bg-gray-50 text-slate-500 font-medium border-b border-gray-100">
<tr>
<th class="px-6 py-4">Token</th>
<th class="px-6 py-4">Patient Name</th>
<th class="px-6 py-4">Assigned Dentist</th>
<th class="px-6 py-4">Status</th>
<th class="px-6 py-4">Wait Time</th>
<th class="px-6 py-4 text-right">Action</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100">
<?php if (empty($queue_items)): ?>
<tr>
<td colspan="6" class="px-6 py-8 text-center text-slate-400">
<span class="material-symbols-outlined text-4xl mb-2 block">event_busy</span>
<p class="text-sm">No appointments scheduled for today</p>
</td>
</tr>
<?php else: ?>
<?php 
foreach ($queue_items as $item): 
    $patient_initials = strtoupper(substr($item['first_name'], 0, 1) . substr($item['last_name'], 0, 1));
    $status_colors = [
        'Pending' => ['bg' => 'bg-yellow-100', 'text' => 'text-yellow-800', 'dot' => 'bg-yellow-600'],
        'Booked' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'dot' => 'bg-green-500'],
        'Arrived' => ['bg' => 'bg-emerald-100', 'text' => 'text-emerald-700', 'dot' => 'bg-emerald-500'],
        'Confirmed' => ['bg' => 'bg-green-100', 'text' => 'text-green-700', 'dot' => 'bg-green-500'],
        'In-Procedure' => ['bg' => 'bg-blue-100', 'text' => 'text-blue-700', 'dot' => 'bg-blue-500'],
        'On-Hold' => ['bg' => 'bg-orange-100', 'text' => 'text-orange-700', 'dot' => 'bg-orange-500'],
        'Completed' => ['bg' => 'bg-green-50', 'text' => 'text-green-700', 'dot' => ''],
        'Cancelled' => ['bg' => 'bg-gray-100', 'text' => 'text-gray-500', 'dot' => 'bg-gray-400'],
    ];
    $status_style = $status_colors[$item['status']] ?? $status_colors['Pending'];
    $time = date('g:i A', strtotime($item['time_slot']));
?>
<tr class="hover:bg-gray-50/50 transition-colors group <?php echo $item['status'] === 'Pending' ? 'bg-yellow-50/30 border-l-4 border-l-clinic-gold' : ''; ?>">
<td class="px-6 py-4 font-mono font-bold <?php echo $item['status'] === 'Pending' ? 'text-royal-purple' : 'text-slate-400'; ?>">#<?php echo str_pad($item['appointment_id'], 3, '0', STR_PAD_LEFT); ?></td>
<td class="px-6 py-4">
<div class="flex items-center gap-3">
<div class="w-8 h-8 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center font-bold text-xs"><?php echo $patient_initials; ?></div>
<span class="font-bold text-[#151118]"><?php echo htmlspecialchars($item['first_name'] . ' ' . $item['last_name']); ?></span>
</div>
</td>
<td class="px-6 py-4 text-slate-600"><?php echo htmlspecialchars($item['doctor_name']); ?></td>
<td class="px-6 py-4">
<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-bold <?php echo $status_style['bg'] . ' ' . $status_style['text']; ?>">
<?php if ($status_style['dot']): ?>
<span class="w-1.5 h-1.5 rounded-full <?php echo $status_style['dot']; ?>"></span>
<?php endif; ?>
<?php echo htmlspecialchars($item['status']); ?>
</span>
</td>
<td class="px-6 py-4 text-slate-500 font-medium"><?php echo $time; ?></td>
<td class="px-6 py-4 text-right">
<?php if ($item['status'] === 'Completed' && $item['payment_status'] !== 'Paid'): ?>
<a href="receptionist_payment.php?patient_id=<?php echo $item['patient_id']; ?>" class="text-clinic-gold bg-[#151118] hover:bg-black px-3 py-1.5 rounded-lg text-xs font-bold flex items-center gap-1 ml-auto inline-flex">
<span class="material-symbols-outlined text-[14px]">credit_card</span>
Pay
</a>
<?php elseif ($item['status'] === 'Pending'): ?>
<a href="receptionist_process_payment.php" class="bg-royal-purple text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-md hover:shadow-lg transition-all">Pay</a>
<?php endif; ?>
<?php if ($item['status'] !== 'Cancelled' && $item['status'] !== 'Completed'): ?>
<a href="receptionist_edit_appointment.php?id=<?php echo $item['appointment_id']; ?>" class="text-royal-purple hover:bg-purple-50 px-3 py-1.5 rounded-lg text-xs font-bold border border-transparent hover:border-purple-100 inline-block ml-2">Edit</a>
<form method="POST" action="" class="inline-block ml-2" id="cancelForm<?php echo $item['appointment_id']; ?>">
<input type="hidden" name="appointment_id" value="<?php echo $item['appointment_id']; ?>">
<input type="hidden" name="cancel_appointment" value="1">
<button type="button" onclick="confirmCancelAppointment(<?php echo $item['appointment_id']; ?>)" class="text-red-500 hover:bg-red-50 px-3 py-1.5 rounded-lg text-xs font-bold border border-transparent hover:border-red-100">Cancel</button>
</form>
<?php endif; ?>
<?php if ($item['status'] === 'Completed' || $item['status'] === 'Cancelled'): ?>
<a href="patient_information.php?id=<?php echo $item['patient_id']; ?>" class="text-royal-purple hover:bg-purple-50 px-3 py-1.5 rounded-lg text-xs font-bold border border-transparent hover:border-purple-100">View Details</a>
<?php endif; ?>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
<!-- Right: Side Widgets -->
<div class="w-full xl:w-96 flex flex-col gap-6">
<!-- Payment Terminal Shortcut -->
<div class="bg-gradient-to-br from-[#151118] to-royal-purple rounded-2xl p-6 text-white shadow-lg relative overflow-hidden group cursor-pointer">
<div class="absolute -right-4 -top-4 w-24 h-24 bg-white/10 rounded-full blur-2xl group-hover:bg-white/20 transition-all"></div>
<div class="flex justify-between items-start mb-4">
<div class="p-3 bg-white/10 rounded-xl backdrop-blur-sm">
<span class="material-symbols-outlined text-clinic-gold">point_of_sale</span>
</div>
<span class="text-xs font-bold bg-clinic-gold text-royal-purple px-2 py-1 rounded">Quick Action</span>
</div>
<h3 class="text-xl font-bold mb-1">Create Invoice</h3>
<p class="text-white/60 text-sm mb-4">Process payment for walk-in or appointment.</p>
<a href="receptionist_payment.php" class="w-full py-3 bg-white text-royal-purple font-bold rounded-xl hover:bg-gray-50 transition-colors flex justify-center items-center gap-2">
                                    Proceed to Terminal
                                    <span class="material-symbols-outlined text-sm">arrow_forward</span>
</a>
</div>
<!-- Dentist Availability -->
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col">
<div class="p-5 border-b border-gray-100">
<h3 class="font-bold text-[#151118]">Dentist Availability</h3>
</div>
<div class="p-4 flex flex-col gap-3">
<?php if (empty($dentists)): ?>
<p class="text-sm text-slate-400 text-center py-4">No dentists available</p>
<?php else: ?>
<?php foreach ($dentists as $dentist): 
    $dentist_initials = getDefaultProfilePhoto($dentist['full_name']);
    $is_busy = $dentist['active_appointments'] > 0;
?>
<div class="flex items-center justify-between p-3 rounded-xl <?php echo $is_busy ? 'bg-gray-50' : 'bg-white hover:border-green-200'; ?> border border-gray-100 transition-colors">
<div class="flex items-center gap-3">
<?php if ($dentist['profile_picture'] && file_exists('../' . $dentist['profile_picture'])): ?>
<img class="w-10 h-10 rounded-full object-cover" src="../<?php echo htmlspecialchars($dentist['profile_picture']); ?>" alt="<?php echo htmlspecialchars($dentist['full_name']); ?>"/>
<?php else: ?>
<div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-xs" style="background-color: <?php echo $dentist_initials['bg_color']; ?>; color: <?php echo $dentist_initials['text_color']; ?>;">
<?php echo htmlspecialchars($dentist_initials['initials']); ?>
</div>
<?php endif; ?>
<div>
<p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($dentist['full_name']); ?></p>
<p class="text-xs text-slate-500">Dentist</p>
</div>
</div>
<div class="flex flex-col items-end">
<span class="w-3 h-3 rounded-full <?php echo $is_busy ? 'bg-red-500' : 'bg-green-500'; ?> border-2 border-white shadow-sm mb-1" title="<?php echo $is_busy ? 'Busy' : 'Available'; ?>"></span>
<span class="text-[10px] font-bold <?php echo $is_busy ? 'text-red-500' : 'text-green-600'; ?> uppercase tracking-wider"><?php echo $is_busy ? 'Busy' : 'Free'; ?></span>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<div class="p-4 pt-0">
<button class="w-full py-2 text-xs font-bold text-slate-400 hover:text-royal-purple transition-colors">View Weekly Schedule</button>
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
        const path = window.location.pathname.replace('/receptionist/receptionist_dashboard.php', '/check-in.php');
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