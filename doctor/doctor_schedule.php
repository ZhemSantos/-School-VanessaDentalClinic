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

// Get view mode (week is default)
$view_mode = isset($_GET['view']) ? sanitizeInput($_GET['view']) : 'week';

// Get current date or date from URL
$timezone = new DateTimeZone('Asia/Manila');
$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
try {
    // Create DateTime with timezone and set time to noon to avoid timezone issues
    $date_obj = new DateTime($current_date . ' 12:00:00', $timezone);
} catch (Exception $e) {
    $date_obj = new DateTime('now', $timezone);
    $current_date = $date_obj->format('Y-m-d');
}

// Calculate date ranges based on view mode
// Get day of week (0=Sunday, 6=Saturday)
$day_of_week = (int)$date_obj->format('w');
$week_start = clone $date_obj;
// If not Sunday, go back to Sunday
if ($day_of_week != 0) {
    $week_start->modify('-' . $day_of_week . ' days');
}
$week_start->setTime(12, 0, 0); // Set to noon to avoid timezone issues
$week_end = clone $week_start;
$week_end->modify('+6 days'); // Saturday (6 days after Sunday)
$week_end->setTime(23, 59, 59); // End of day

// For day view - single day
$day_start = clone $date_obj;
$day_end = clone $date_obj;

// For month view - entire month
$month_start = clone $date_obj;
$month_start->modify('first day of this month');
$month_end = clone $date_obj;
$month_end->modify('last day of this month');

// Get month for display
$month_year = $date_obj->format('F Y');
$month_num = $date_obj->format('m');
$year_num = $date_obj->format('Y');

// Fetch appointments based on view mode
$appointments = [];
if ($doctor_id) {
    try {
        if ($view_mode === 'day') {
            $appt_stmt = $pdo->prepare("
                SELECT a.*, 
                       p.first_name, p.last_name,
                       CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                       GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
                FROM tbl_appointment a
                INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE a.doctor_id = ? 
                  AND a.appointment_date = ?
                  AND a.status IN ('Pending', 'Booked', 'In-Procedure', 'Completed')
                GROUP BY a.appointment_id
                ORDER BY a.time_slot ASC
            ");
            $appt_stmt->execute([$doctor_id, $current_date]);
        } elseif ($view_mode === 'week') {
            $appt_stmt = $pdo->prepare("
                SELECT a.*, 
                       p.first_name, p.last_name,
                       CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                       GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
                FROM tbl_appointment a
                INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE a.doctor_id = ? 
                  AND a.appointment_date >= ?
                  AND a.appointment_date <= ?
                  AND a.status IN ('Pending', 'Booked', 'In-Procedure', 'Completed')
                GROUP BY a.appointment_id
                ORDER BY a.appointment_date ASC, a.time_slot ASC
            ");
            $appt_stmt->execute([$doctor_id, $week_start->format('Y-m-d'), $week_end->format('Y-m-d')]);
        } else { // month view
            $appt_stmt = $pdo->prepare("
                SELECT a.*, 
                       p.first_name, p.last_name,
                       CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                       GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
                FROM tbl_appointment a
                INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE a.doctor_id = ? 
                  AND a.appointment_date >= ?
                  AND a.appointment_date <= ?
                  AND a.status IN ('Pending', 'Booked', 'In-Procedure', 'Completed')
                GROUP BY a.appointment_id
                ORDER BY a.appointment_date ASC, a.time_slot ASC
            ");
            $appt_stmt->execute([$doctor_id, $month_start->format('Y-m-d'), $month_end->format('Y-m-d')]);
        }
        $appointments = $appt_stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Error fetching appointments: ' . $e->getMessage());
    }
}

// --- FETCH BLOCKED TIME & MERGE ---
if ($doctor_id) {
    try {
        $block_sql = "SELECT block_id, block_date, start_time, end_time, reason FROM tbl_blocked_time WHERE doctor_id = ?";
        $params = [$doctor_id];
        
        if ($view_mode === 'day') {
            $block_sql .= " AND block_date = ?";
            $params[] = $current_date;
        } elseif ($view_mode === 'week') {
            $block_sql .= " AND block_date BETWEEN ? AND ?";
            $params[] = $week_start->format('Y-m-d');
            $params[] = $week_end->format('Y-m-d');
        } else {
            $block_sql .= " AND block_date BETWEEN ? AND ?";
            $params[] = $month_start->format('Y-m-d');
            $params[] = $month_end->format('Y-m-d');
        }
        
        $blkStmt = $pdo->prepare($block_sql);
        $blkStmt->execute($params);
        $blocks = $blkStmt->fetchAll();
        
        foreach ($blocks as $blk) {
            $start = strtotime($blk['start_time']);
            $end = strtotime($blk['end_time']);
            $duration = ($end - $start) / 60;
            
            $appointments[] = [
                'appointment_id' => $blk['block_id'], // Uses block_id instead of appointment_id
                'appointment_date' => $blk['block_date'],
                'time_slot' => $blk['start_time'],
                'service_name' => 'Blocked Time',
                'patient_name' => $blk['reason'] ?: 'Personal/Unavailable',
                'status' => 'Blocked',
                'type' => 'blocked_time', // Marker
                'first_name' => '', 'last_name' => '', 
                'duration_mins' => $duration
            ];
        }
        
        // Re-sort combined array
        usort($appointments, function($a, $b) {
            $t1 = strtotime($a['appointment_date'] . ' ' . $a['time_slot']);
            $t2 = strtotime($b['appointment_date'] . ' ' . $b['time_slot']);
            return $t1 - $t2;
        });
        
    } catch (PDOException $e) {
        error_log('Error fetching blocked time: ' . $e->getMessage());
    }
}
// --- END FETCH BLOCKED TIME ---

// For backward compatibility
$week_appointments = $appointments;

// Note: Block time functionality would require a separate table for one-time blocks
// tbl_doctor_schedule is for recurring weekly schedules only

// Helper function to generate time slots in 15-minute intervals within a schedule range
function generateTimeSlots($start_time, $end_time) {
    $slots = [];
    $start = new DateTime($start_time);
    $end = new DateTime($end_time);
    
    $current = clone $start;
    while ($current < $end) {
        $time_str = $current->format('H:i');
        $time_display = $current->format('g:i A');
        $slots[] = [
            'value' => $time_str,
            'display' => $time_display
        ];
        $current->modify('+15 minutes');
    }
    
    return $slots;
}

// Helper function to get appointment styling based on service type and status
function getAppointmentStyle($service_name, $status) {
    $service_lower = strtolower($service_name);
    
    // Determine background color based on service type
    if (strpos($service_lower, 'cleaning') !== false || strpos($service_lower, 'hygiene') !== false) {
        $bg = 'bg-blue-50';
        $border = 'border-blue-500';
        $text = 'text-blue-800';
        $text_light = 'text-blue-700';
    } elseif (strpos($service_lower, 'consultation') !== false || strpos($service_lower, 'checkup') !== false) {
        $bg = 'bg-yellow-50';
        $border = 'border-yellow-400';
        $text = 'text-yellow-800';
        $text_light = 'text-yellow-700';
    } else {
        $bg = 'bg-primary/10';
        $border = 'border-primary';
        $text = 'text-primary';
        $text_light = 'text-[#7a6189]';
    }
    
    // Modify for completed status
    if ($status === 'Completed') {
        $bg .= ' opacity-70';
    } elseif ($status === 'Blocked') {
        $bg = 'bg-gray-100 diagonal-stripes'; // Add stripes class in CSS if needed, or just gray
        $border = 'border-gray-400';
        $text = 'text-gray-600';
        $text_light = 'text-gray-500';
    }
    
    return ['bg' => $bg, 'border' => $border, 'text' => $text, 'text_light' => $text_light];
}

// Generate calendar dates for mini calendar
function generateCalendarDates($month, $year, $current_date_str) {
    $timezone = new DateTimeZone('Asia/Manila');
    $first_day = new DateTime("$year-$month-01", $timezone);
    $first_day->setTime(12, 0, 0); // Set to noon to avoid timezone issues
    $last_day = clone $first_day;
    $last_day->modify('last day of this month');
    $last_day->setTime(12, 0, 0);
    
    $calendar = [];
    $start_date = clone $first_day;
    // Get day of week (0=Sunday, 6=Saturday)
    $day_of_week = (int)$start_date->format('w');
    // If not Sunday, go back to Sunday
    if ($day_of_week != 0) {
        $start_date->modify('-' . $day_of_week . ' days');
    }
    $start_date->setTime(12, 0, 0);
    
    $end_date = clone $last_day;
    // Get day of week of last day
    $last_day_of_week = (int)$end_date->format('w');
    // If not Saturday, go forward to Saturday
    if ($last_day_of_week != 6) {
        $end_date->modify('+' . (6 - $last_day_of_week) . ' days');
    }
    $end_date->setTime(12, 0, 0);
    
    $today = new DateTime('now', $timezone);
    $today->setTime(12, 0, 0);
    $today_str = $today->format('Y-m-d');
    
    $current = clone $start_date;
    while ($current <= $end_date) {
        $date_str = $current->format('Y-m-d');
        $calendar[] = [
            'date' => clone $current,
            'is_current_month' => $current->format('m') == $month,
            'is_today' => $date_str === $today_str,
            'is_selected' => $date_str === $current_date_str
        ];
        $current->modify('+1 day');
    }
    
    return $calendar;
}

$calendar_dates = generateCalendarDates($month_num, $year_num, $current_date);

// Calculate week days for display
$week_days = [];
$current_week_day = clone $week_start;
for ($i = 0; $i < 7; $i++) {
    $week_days[] = [
        'date' => clone $current_week_day,
        'day_name' => $current_week_day->format('D'),
        'day_num' => $current_week_day->format('j')
    ];
    $current_week_day->modify('+1 day');
}

// Organize appointments by day for all views
$appointments_by_day = [];
foreach ($appointments as $appt) {
    $day = $appt['appointment_date'];
    if (!isset($appointments_by_day[$day])) {
        $appointments_by_day[$day] = [];
    }
    $appointments_by_day[$day][] = $appt;
}

// For month view - get appointment counts per day
$month_appointment_counts = [];
if ($view_mode === 'month') {
    foreach ($appointments_by_day as $day => $day_appts) {
        $day_num = (int)date('j', strtotime($day));
        $month_appointment_counts[$day_num] = count($day_appts);
    }
}

// Get doctor's schedule for the current date's day of week (for Block Time modal)
$doctor_schedule_today = null;
if ($doctor_id) {
    try {
        $day_name = $date_obj->format('l'); // e.g., "Monday", "Tuesday"
        $schedule_stmt = $pdo->prepare("
            SELECT start_time, end_time
            FROM tbl_doctor_schedule
            WHERE doctor_id = ? AND day_of_week = ?
            LIMIT 1
        ");
        $schedule_stmt->execute([$doctor_id, $day_name]);
        $doctor_schedule_today = $schedule_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching doctor schedule: ' . $e->getMessage());
    }
}

// Generate time slots for Block Time modal (default to 8:00-19:00 if no schedule)
$block_time_slots = [];
if ($doctor_schedule_today) {
    $block_time_slots = generateTimeSlots($doctor_schedule_today['start_time'], $doctor_schedule_today['end_time']);
} else {
    // Default: 8:00 AM to 7:00 PM
    $block_time_slots = generateTimeSlots('08:00:00', '19:00:00');
}

// For month view - calculate calendar grid
$month_calendar = [];
if ($view_mode === 'month') {
    $timezone = new DateTimeZone('Asia/Manila');
    $first_day = new DateTime("$year_num-$month_num-01", $timezone);
    $first_day->setTime(12, 0, 0); // Set to noon to avoid timezone issues
    $last_day = clone $first_day;
    $last_day->modify('last day of this month');
    $last_day->setTime(12, 0, 0);
    
    $first_day_of_week = (int)$first_day->format('w'); // 0 = Sunday
    $days_in_month = (int)$last_day->format('j');
    
    $prev_month = clone $first_day;
    $prev_month->modify('-1 month');
    $prev_month_days = (int)$prev_month->format('t');
    
    // Previous month's trailing days - start from Sunday (0)
    for ($i = $first_day_of_week - 1; $i >= 0; $i--) {
        $day = $prev_month_days - $i;
        $month_calendar[] = ['day' => $day, 'is_current_month' => false, 'date' => null];
    }
    
    // Current month's days
    for ($day = 1; $day <= $days_in_month; $day++) {
        $date_str = sprintf('%04d-%02d-%02d', $year_num, $month_num, $day);
        $month_calendar[] = [
            'day' => $day, 
            'is_current_month' => true, 
            'date' => $date_str,
            'is_today' => $date_str === date('Y-m-d'),
            'is_selected' => $date_str === $current_date,
            'appt_count' => $month_appointment_counts[$day] ?? 0
        ];
    }
    
    // Fill remaining cells to make 42 (6 rows * 7 days)
    $remaining = 42 - count($month_calendar);
    for ($day = 1; $day <= $remaining; $day++) {
        $month_calendar[] = ['day' => $day, 'is_current_month' => false, 'date' => null];
    }
}

?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>My Schedule - Vanessa Nicolas Dental Clinic</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#6A1B9A",
                        "secondary": "#FFD700",
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
        .clinical-scroll::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .clinical-scroll::-webkit-scrollbar-track {
            background: #f1f1f1; 
            border-radius: 4px;
        }
        .clinical-scroll::-webkit-scrollbar-thumb {
            background: #d1d5db; 
            border-radius: 4px;
        }
        .clinical-scroll::-webkit-scrollbar-thumb:hover {
            background: #9ca3af; 
        }.no-scrollbar::-webkit-scrollbar {
            display: none;
        }
        .no-scrollbar {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .diagonal-stripes {
            background-image: repeating-linear-gradient(
                45deg,
                rgba(0, 0, 0, 0.05),
                rgba(0, 0, 0, 0.05) 10px,
                transparent 10px,
                transparent 20px
            );
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-[#151118] overflow-hidden">
<div class="flex h-screen w-full overflow-hidden">
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
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_patients.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">groups</span>
<p class="text-sm font-medium leading-normal">My Patients</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_records.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">folder_shared</span>
<p class="text-sm font-medium leading-normal">Medical Records</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 border-l-4 border-primary shadow-sm group transition-all" href="doctor_schedule.php">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform">calendar_month</span>
<p class="text-primary text-sm font-bold leading-normal">My Schedule</p>
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
<main class="flex-1 flex flex-col h-full relative overflow-hidden bg-background-light">
<header class="h-16 flex items-center justify-between px-8 bg-white border-b border-[#f3f0f4] shrink-0 z-10">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-[#7a6189]">menu_open</span>
<span class="text-[#e2dbe6] mx-2">|</span>
<h2 class="text-[#151118] text-base font-bold">My Schedule</h2>
</div>
<div class="flex items-center gap-6">
<button class="btn-notifications relative p-2 text-[#7a6189] hover:bg-[#f3f0f4] rounded-full transition-colors">
<span class="material-symbols-outlined">notifications</span>
<span class="absolute top-2 right-2 size-2 bg-red-500 rounded-full border border-white"></span>
</button>
<a href="doctor_current_user.php" class="flex items-center gap-3 pl-6 border-l border-[#f3f0f4] cursor-pointer hover:opacity-80 transition-opacity">
<div class="flex flex-col items-end">
<p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($logged_in_user['full_name']); ?></p>
<p class="text-xs text-[#7a6189]">Dentist</p>
</div>
<?php
// Fetch user profile picture from database
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
$current_name = $logged_in_user['full_name'];

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

if ($current_profile && file_exists('../' . $current_profile)) {
    echo '<div class="bg-center bg-no-repeat bg-cover rounded-full size-10 ring-2 ring-primary/20" style="background-image: url(\'../' . htmlspecialchars($current_profile) . '\');"></div>';
} else {
    $default_photo = getDefaultProfilePhoto($current_name);
    echo '<div class="rounded-full size-10 ring-2 ring-primary/20 flex items-center justify-center text-white font-bold" style="background-color: ' . $default_photo['bg_color'] . '; color: ' . $default_photo['text_color'] . ';">' . htmlspecialchars($default_photo['initials']) . '</div>';
}
?>
</a>
</div>
</header>
<div class="flex-1 overflow-hidden p-6 lg:p-8 flex flex-col gap-6">
<div class="flex flex-col lg:flex-row justify-between items-start lg:items-center gap-4 shrink-0">
<div class="flex items-center gap-4 bg-white p-1.5 rounded-xl border border-[#e2dbe6] shadow-sm">
<button onclick="navigateWeek(-1)" class="size-8 flex items-center justify-center rounded-lg hover:bg-[#f3f0f4] text-[#7a6189] transition-colors">
<span class="material-symbols-outlined text-lg">chevron_left</span>
</button>
<div class="flex items-center gap-2 px-2">
<span class="material-symbols-outlined text-primary text-xl">calendar_today</span>
<span class="text-base font-bold text-[#151118]" id="currentMonthYear">
<?php 
if ($view_mode === 'day') {
    echo htmlspecialchars($date_obj->format('F j, Y'));
} elseif ($view_mode === 'week') {
    echo htmlspecialchars($week_start->format('M j') . ' - ' . $week_end->format('M j, Y'));
} else {
    echo htmlspecialchars($month_year);
}
?>
</span>
</div>
<button onclick="navigateWeek(1)" class="size-8 flex items-center justify-center rounded-lg hover:bg-[#f3f0f4] text-[#7a6189] transition-colors">
<span class="material-symbols-outlined text-lg">chevron_right</span>
</button>
<div class="h-6 w-px bg-[#e2dbe6] mx-1"></div>
<button onclick="goToToday()" class="px-3 py-1.5 rounded-lg bg-[#f3f0f4] text-xs font-bold text-[#151118] border border-[#e2dbe6] hover:bg-primary/10 hover:text-primary transition-colors">Today</button>
</div>
<div class="flex items-center gap-3">
<div class="hidden sm:flex bg-white rounded-xl border border-[#e2dbe6] p-1 shadow-sm">
<button onclick="switchView('day')" class="px-3 py-1.5 rounded-lg text-xs font-bold <?php echo $view_mode === 'day' ? 'bg-primary/10 text-primary shadow-sm' : 'text-[#7a6189] hover:bg-[#f3f0f4]'; ?> transition-colors">Day</button>
<button onclick="switchView('week')" class="px-3 py-1.5 rounded-lg text-xs font-bold <?php echo $view_mode === 'week' ? 'bg-primary/10 text-primary shadow-sm' : 'text-[#7a6189] hover:bg-[#f3f0f4]'; ?> transition-colors">Week</button>
<button onclick="switchView('month')" class="px-3 py-1.5 rounded-lg text-xs font-bold <?php echo $view_mode === 'month' ? 'bg-primary/10 text-primary shadow-sm' : 'text-[#7a6189] hover:bg-[#f3f0f4]'; ?> transition-colors">Month</button>
</div>
<button onclick="openBlockTimeModal()" class="bg-primary hover:bg-primary/90 text-white px-4 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-primary/20 transition-all flex items-center gap-2">
<span class="material-symbols-outlined text-lg">add</span>
<span>Block Time</span>
</button>
</div>
</div>
<div class="flex-1 flex gap-6 min-h-0">
<div class="hidden xl:flex flex-col gap-6 w-80 shrink-0 h-full overflow-hidden">
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm p-5 shrink-0">
<div class="flex justify-between items-center mb-4">
<span class="text-sm font-bold text-[#151118]" id="miniCalendarMonth"><?php echo htmlspecialchars($month_year); ?></span>
<div class="flex gap-1">
<button onclick="navigateMiniCalendar(-1)" class="text-[#7a6189] hover:text-primary transition-colors"><span class="material-symbols-outlined text-base">chevron_left</span></button>
<button onclick="navigateMiniCalendar(1)" class="text-[#7a6189] hover:text-primary transition-colors"><span class="material-symbols-outlined text-base">chevron_right</span></button>
</div>
</div>
<div class="grid grid-cols-7 text-center gap-y-2 text-xs" id="miniCalendarGrid">
<div class="text-[#7a6189] font-medium py-1">S</div>
<div class="text-[#7a6189] font-medium py-1">M</div>
<div class="text-[#7a6189] font-medium py-1">T</div>
<div class="text-[#7a6189] font-medium py-1">W</div>
<div class="text-[#7a6189] font-medium py-1">T</div>
<div class="text-[#7a6189] font-medium py-1">F</div>
<div class="text-[#7a6189] font-medium py-1">S</div>
<?php foreach ($calendar_dates as $cal_date): 
    $date_str = $cal_date['date']->format('Y-m-d');
    $day_num = $cal_date['date']->format('j');
    $is_current = $cal_date['is_current_month'];
    $is_today = $cal_date['is_today'];
    $is_selected = $date_str === $current_date;
    $classes = 'py-1 rounded-full cursor-pointer transition-colors ';
    if (!$is_current) {
        $classes .= 'text-[#e2dbe6]';
    } elseif ($is_today) {
        $classes .= 'bg-primary text-white font-bold shadow-md shadow-primary/30';
    } elseif ($is_selected && !$is_today) {
        $classes .= 'bg-primary/10 text-primary font-bold';
    } else {
        $classes .= 'text-[#151118] hover:bg-[#f3f0f4]';
    }
?>
<div class="<?php echo $classes; ?>" onclick="selectDate('<?php echo $date_str; ?>')"><?php echo $day_num; ?></div>
<?php endforeach; ?>
</div>
</div>
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm p-5 flex flex-col gap-4 shrink-0">
<h3 class="text-xs font-bold text-[#7a6189] uppercase tracking-wider">Schedule Legend</h3>
<div class="flex flex-col gap-3">
<div class="flex items-center gap-3">
<div class="size-3 rounded bg-primary/20 border border-primary"></div>
<span class="text-sm font-medium text-[#151118]">Treatment (Tx)</span>
</div>
<div class="flex items-center gap-3">
<div class="size-3 rounded bg-blue-100 border border-blue-400"></div>
<span class="text-sm font-medium text-[#151118]">Hygiene / Cleaning</span>
</div>
<div class="flex items-center gap-3">
<div class="size-3 rounded bg-yellow-100 border border-yellow-400"></div>
<span class="text-sm font-medium text-[#151118]">Consultation</span>
</div>
<div class="flex items-center gap-3">
<div class="size-3 rounded bg-gray-100 border border-gray-400"></div>
<span class="text-sm font-medium text-[#151118]">Blocked / Personal</span>
</div>
<div class="h-px bg-[#e2dbe6] my-2"></div>
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-primary text-base">calendar_month</span>
<span class="text-sm font-medium text-[#151118]">Appointment</span>
</div>
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-orange-500 text-base">directions_walk</span>
<span class="text-sm font-medium text-[#151118]">Walk-in</span>
</div>
</div>
</div>
</div>
<div class="flex-1 bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col overflow-hidden relative">
<?php if ($view_mode === 'day'): ?>
<!-- Day View -->
<div class="flex border-b border-[#e2dbe6] bg-[#fcfcfd] pr-[8px]"> 
<div class="w-16 shrink-0 border-r border-[#f3f0f4]"></div> 
<div class="flex-1 py-3 px-2 text-center bg-primary/5 relative">
<div class="absolute top-0 left-0 w-full h-1 bg-primary"></div>
<p class="text-xs font-bold text-primary uppercase mb-0.5"><?php echo $date_obj->format('D'); ?></p>
<p class="text-lg font-black text-primary"><?php echo $date_obj->format('j'); ?></p>
</div>
</div>
<div class="flex-1 overflow-y-auto clinical-scroll relative bg-white">
<div class="absolute inset-0 flex pointer-events-none">
<div class="w-16 shrink-0 border-r border-[#f3f0f4] bg-[#fcfcfd]"></div>
<div class="flex-1 h-full bg-primary/[0.02]"></div>
</div>
<div class="relative min-h-[1040px]">
<div class="absolute left-0 top-0 bottom-0 w-16 flex flex-col items-center pt-2 bg-[#fcfcfd] border-r border-[#f3f0f4] z-10">
<?php for ($hour = 8; $hour <= 19; $hour++): ?>
<div class="h-[80px] w-full text-center"><span class="text-xs font-medium text-[#7a6189] -mt-2 block"><?php echo str_pad($hour, 2, '0', STR_PAD_LEFT); ?>:00</span></div>
<?php endfor; ?>
</div>
<div class="flex flex-col ml-16 relative">
<?php for ($hour = 8; $hour <= 19; $hour++): ?>
<div class="h-[80px] border-b border-[#f3f0f4] w-full"></div>
<?php endfor; ?>
</div>
<div class="absolute inset-0 ml-16 px-1">
<?php 
$day_appointments = $appointments_by_day[$current_date] ?? [];
foreach ($day_appointments as $appt): 
    $time_slot = new DateTime($appt['time_slot']);
    $hour = (int)$time_slot->format('G');
    $minute = (int)$time_slot->format('i');
    $top_position = (($hour - 8) * 80) + ($minute / 60 * 80);
    $duration_minutes = $appt['duration_mins'] ?? 60;
    $height = ($duration_minutes / 60) * 80;
    $style = getAppointmentStyle($appt['service_name'], $appt['status']);
    $time_formatted = date('g:i A', strtotime($appt['time_slot']));
    $end_time = clone $time_slot;
    $end_time->modify("+{$duration_minutes} minutes");
    $end_time_formatted = date('g:i A', $end_time->getTimestamp());
    
    $is_blocked = isset($appt['type']) && $appt['type'] === 'blocked_time';
    $click_action = $is_blocked ? "confirmUnblock({$appt['appointment_id']})" : "viewAppointment({$appt['appointment_id']})";
?>
<div class="absolute left-1 right-1 <?php echo $style['bg']; ?> border-l-4 <?php echo $style['border']; ?> rounded-md p-2 hover:shadow-md transition-all pointer-events-auto cursor-pointer shadow-sm <?php echo $appt['status'] === 'Completed' ? 'opacity-70' : ''; ?>" 
     style="top: <?php echo $top_position; ?>px; height: <?php echo max($height, 40); ?>px;"
     onclick="<?php echo $click_action; ?>">
<div class="flex justify-between items-start">
<div class="flex items-center gap-1.5 flex-1 min-w-0">
<?php if (isset($appt['appointment_type']) && $appt['appointment_type'] === 'Walk-in'): ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-xs" title="Walk-in">directions_walk</span>
<?php else: ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-xs" title="Appointment">calendar_month</span>
<?php endif; ?>
<p class="text-xs font-bold <?php echo $style['text']; ?> truncate"><?php echo htmlspecialchars($appt['service_name']); ?></p>
</div>
<?php if ($appt['status'] === 'Completed'): ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-sm">check_circle</span>
<?php elseif ($appt['status'] === 'In-Procedure'): ?>
<div class="size-2 rounded-full <?php echo str_replace('50', '500', $style['border']); ?> animate-pulse"></div>
<?php endif; ?>
</div>
<p class="text-[10px] <?php echo $style['text_light']; ?> font-medium mt-1 truncate"><?php echo htmlspecialchars($appt['patient_name']); ?></p>
<div class="mt-2 flex items-center gap-1">
<span class="text-[10px] bg-white/60 px-1 rounded <?php echo $style['text']; ?>"><?php echo $time_formatted; ?> - <?php echo $end_time_formatted; ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
</div>
</div>
<?php elseif ($view_mode === 'month'): ?>
<!-- Month View -->
<div class="flex-1 overflow-y-auto p-6 clinical-scroll">
<div class="grid grid-cols-7 gap-2 mb-4">
<div class="text-center text-xs font-bold text-[#7a6189] uppercase py-2">Sun</div>
<div class="text-center text-xs font-bold text-[#7a6189] uppercase py-2">Mon</div>
<div class="text-center text-xs font-bold text-[#7a6189] uppercase py-2">Tue</div>
<div class="text-center text-xs font-bold text-[#7a6189] uppercase py-2">Wed</div>
<div class="text-center text-xs font-bold text-[#7a6189] uppercase py-2">Thu</div>
<div class="text-center text-xs font-bold text-[#7a6189] uppercase py-2">Fri</div>
<div class="text-center text-xs font-bold text-[#7a6189] uppercase py-2">Sat</div>
<?php foreach ($month_calendar as $cal_day): 
    if ($cal_day['is_current_month']):
        $classes = 'min-h-[100px] p-2 rounded-lg border border-[#e2dbe6] hover:border-primary/30 transition-colors cursor-pointer ';
        if ($cal_day['is_selected']) {
            $classes .= 'bg-primary/10 border-primary/50 ring-2 ring-primary/20';
        } elseif ($cal_day['is_today']) {
            $classes .= 'bg-primary/5 border-primary/30';
        } else {
            $classes .= 'bg-white hover:bg-[#f8f9fa]';
        }
?>
<div class="<?php echo $classes; ?>" onclick="selectDate('<?php echo $cal_day['date']; ?>')">
<div class="flex justify-between items-start mb-1">
<span class="text-sm font-bold <?php echo $cal_day['is_selected'] || $cal_day['is_today'] ? 'text-primary' : 'text-[#151118]'; ?>"><?php echo $cal_day['day']; ?></span>
<?php if ($cal_day['appt_count'] > 0): ?>
<span class="text-xs bg-primary/20 text-primary px-1.5 py-0.5 rounded-full font-bold"><?php echo $cal_day['appt_count']; ?></span>
<?php endif; ?>
</div>
<div class="space-y-1 mt-2">
<?php 
$day_appts = $appointments_by_day[$cal_day['date']] ?? [];
foreach (array_slice($day_appts, 0, 3) as $appt):
    $style = getAppointmentStyle($appt['service_name'], $appt['status']);
    $time_formatted = date('g:i A', strtotime($appt['time_slot']));
?>
<div class="text-[10px] <?php echo $style['bg']; ?> <?php echo $style['border']; ?> border-l-2 rounded px-1.5 py-0.5 truncate cursor-pointer hover:shadow-sm transition-all flex items-center gap-1" onclick="event.stopPropagation(); viewAppointment(<?php echo $appt['appointment_id']; ?>)">
<?php if (isset($appt['appointment_type']) && $appt['appointment_type'] === 'Walk-in'): ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-[10px]" title="Walk-in">directions_walk</span>
<?php else: ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-[10px]" title="Appointment">calendar_month</span>
<?php endif; ?>
<span class="font-bold <?php echo $style['text']; ?>"><?php echo $time_formatted; ?></span>
<span class="<?php echo $style['text_light']; ?>"> - <?php echo htmlspecialchars($appt['patient_name']); ?></span>
</div>
<?php endforeach; ?>
<?php if (count($day_appts) > 3): ?>
<div class="text-[10px] text-primary font-bold text-center">+<?php echo count($day_appts) - 3; ?> more</div>
<?php endif; ?>
</div>
</div>
<?php else: ?>
<div class="min-h-[100px] p-2 rounded-lg text-[#e2dbe6] text-sm"><?php echo $cal_day['day']; ?></div>
<?php endif; ?>
<?php endforeach; ?>
</div>
<?php else: ?>
<!-- Week View -->
<div class="flex border-b border-[#e2dbe6] bg-[#fcfcfd] pr-[8px]"> 
<div class="w-16 shrink-0 border-r border-[#f3f0f4]"></div> 
<div class="grid grid-cols-7 w-full" id="weekHeader">
<?php foreach ($week_days as $index => $day): 
    $is_today = $day['date']->format('Y-m-d') === date('Y-m-d');
    $is_selected = $day['date']->format('Y-m-d') === $current_date;
    $classes = 'py-3 px-2 border-r border-[#f3f0f4] text-center group cursor-pointer transition-colors ';
    if ($is_selected || $is_today) {
        $classes .= 'bg-primary/5 relative';
    } else {
        $classes .= 'hover:bg-[#f3f0f4]';
    }
?>
<div class="<?php echo $classes; ?>" onclick="selectWeekDay('<?php echo $day['date']->format('Y-m-d'); ?>')">
<?php if ($is_selected || $is_today): ?><div class="absolute top-0 left-0 w-full h-1 bg-primary"></div><?php endif; ?>
<p class="text-xs font-bold <?php echo ($is_selected || $is_today) ? 'text-primary' : 'text-[#7a6189]'; ?> uppercase mb-0.5"><?php echo substr($day['day_name'], 0, 3); ?></p>
<p class="text-lg font-black <?php echo ($is_selected || $is_today) ? 'text-primary' : 'text-[#151118]'; ?>"><?php echo $day['day_num']; ?></p>
</div>
<?php endforeach; ?>
</div>
</div>
<div class="flex-1 overflow-y-auto clinical-scroll relative bg-white">
<div class="absolute inset-0 flex pointer-events-none">
<div class="w-16 shrink-0 border-r border-[#f3f0f4] bg-[#fcfcfd]"></div>
<div class="grid grid-cols-7 w-full h-full" id="weekGridBackground">
<?php foreach ($week_days as $day): 
    $is_today = $day['date']->format('Y-m-d') === date('Y-m-d');
    $classes = 'border-r border-[#f3f0f4] h-full';
    if ($is_today) {
        $classes .= ' bg-primary/[0.02]';
    }
?>
<div class="<?php echo $classes; ?>"></div>
<?php endforeach; ?>
</div>
</div>
<div class="absolute left-16 right-0 top-[275px] z-20 pointer-events-none flex items-center" id="currentTimeIndicator" style="display: none;">
<div class="size-2 rounded-full bg-red-500 -ml-1"></div>
<div class="h-px bg-red-500 w-full shadow-[0_1px_4px_rgba(239,68,68,0.4)]"></div>
</div>
<div class="relative min-h-[1040px]">
<div class="absolute left-0 top-0 bottom-0 w-16 flex flex-col items-center pt-2 bg-[#fcfcfd] border-r border-[#f3f0f4] z-10">
<?php for ($hour = 8; $hour <= 19; $hour++): ?>
<div class="h-[80px] w-full text-center"><span class="text-xs font-medium text-[#7a6189] -mt-2 block"><?php echo str_pad($hour, 2, '0', STR_PAD_LEFT); ?>:00</span></div>
<?php endfor; ?>
</div>
<div class="flex flex-col ml-16 relative">
<?php for ($hour = 8; $hour <= 19; $hour++): ?>
<div class="h-[80px] border-b border-[#f3f0f4] w-full"></div>
<?php endfor; ?>
</div>
<div class="absolute inset-0 ml-16 grid grid-cols-7 pointer-events-none" id="appointmentsContainer">
<?php foreach ($week_days as $col_index => $day): 
    $day_str = $day['date']->format('Y-m-d');
    $day_appointments = $appointments_by_day[$day_str] ?? [];
?>
<div class="relative h-full px-1">
<?php foreach ($day_appointments as $appt): 
    $time_slot = new DateTime($appt['time_slot']);
    $hour = (int)$time_slot->format('G');
    $minute = (int)$time_slot->format('i');
    
    // Calculate position (assuming 80px per hour, starting at 8:00)
    $top_position = (($hour - 8) * 80) + ($minute / 60 * 80);
    
    // Estimate duration (default 60 minutes, could be from service or estimated)
    $duration_minutes = $appt['duration_mins'] ?? 60;
    $height = ($duration_minutes / 60) * 80;
    
    $style = getAppointmentStyle($appt['service_name'], $appt['status']);
    $patient_initials = strtoupper(substr($appt['first_name'], 0, 1) . substr($appt['last_name'], 0, 1));
    $time_formatted = date('g:i A', strtotime($appt['time_slot']));
    
    // Calculate end time for display
    $end_time = clone $time_slot;
    $end_time->modify("+{$duration_minutes} minutes");
    $end_time_formatted = date('g:i A', $end_time->getTimestamp());
    
    $is_blocked = isset($appt['type']) && $appt['type'] === 'blocked_time';
    $click_action = $is_blocked ? "confirmUnblock({$appt['appointment_id']})" : "viewAppointment({$appt['appointment_id']})";
?>
<div class="absolute left-1 right-1 <?php echo $style['bg']; ?> border-l-4 <?php echo $style['border']; ?> rounded-md p-2 hover:shadow-md transition-all pointer-events-auto cursor-pointer shadow-sm <?php echo $appt['status'] === 'Completed' ? 'opacity-70' : ''; ?>" 
     style="top: <?php echo $top_position; ?>px; height: <?php echo max($height, 40); ?>px;"
     onclick="<?php echo $click_action; ?>">
<div class="flex justify-between items-start">
<div class="flex items-center gap-1.5 flex-1 min-w-0">
<?php if (isset($appt['appointment_type']) && $appt['appointment_type'] === 'Walk-in'): ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-xs" title="Walk-in">directions_walk</span>
<?php else: ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-xs" title="Appointment">calendar_month</span>
<?php endif; ?>
<p class="text-xs font-bold <?php echo $style['text']; ?> truncate"><?php echo htmlspecialchars($appt['service_name']); ?></p>
</div>
<?php if ($appt['status'] === 'Completed'): ?>
<span class="material-symbols-outlined <?php echo $style['text']; ?> text-sm">check_circle</span>
<?php elseif ($appt['status'] === 'In-Procedure'): ?>
<div class="size-2 rounded-full <?php echo str_replace('50', '500', $style['border']); ?> animate-pulse"></div>
<?php endif; ?>
</div>
<p class="text-[10px] <?php echo $style['text_light']; ?> font-medium mt-1 truncate"><?php echo htmlspecialchars($appt['patient_name']); ?></p>
<div class="mt-2 flex items-center gap-1">
<span class="text-[10px] bg-white/60 px-1 rounded <?php echo $style['text']; ?>"><?php echo $time_formatted; ?> - <?php echo $end_time_formatted; ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
</div>
</main>
</div>

<!-- Notification Toast -->
<div id="notificationToast" class="fixed top-4 right-4 z-[100] transform translate-x-full transition-transform duration-300">
<div class="bg-white rounded-2xl shadow-2xl border border-[#e2dbe6] p-4 flex items-center gap-4 min-w-[320px] max-w-md">
<div id="notificationIcon" class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0">
<span class="material-symbols-outlined text-lg"></span>
</div>
<div class="flex-1">
<p id="notificationMessage" class="text-sm font-medium text-[#151118]"></p>
</div>
<button onclick="hideNotification()" class="text-[#7a6189] hover:text-[#151118] transition-colors">
<span class="material-symbols-outlined text-lg">close</span>
</button>
</div>
</div>

<!-- Block Time Modal -->
<div id="blockTimeModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6">
<div class="flex justify-between items-center mb-4">
<h3 class="text-xl font-bold text-[#151118]">Block Time</h3>
<button onclick="closeBlockTimeModal()" class="text-[#7a6189] hover:text-[#151118] transition-colors">
<span class="material-symbols-outlined">close</span>
</button>
</div>
<form id="blockTimeForm" class="space-y-4">
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">Date</label>
<input type="date" id="blockDate" name="block_date" required class="w-full p-2 border border-[#e2dbe6] rounded-xl" value="<?php echo $current_date; ?>"/>
</div>
<div class="grid grid-cols-2 gap-4">
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">Start Time</label>
<select id="blockStartTime" name="start_time" required class="w-full p-2 border border-[#e2dbe6] rounded-xl">
<option value="">Select start time</option>
<?php foreach ($block_time_slots as $slot): ?>
<option value="<?php echo htmlspecialchars($slot['value']); ?>"><?php echo htmlspecialchars($slot['display']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">End Time</label>
<select id="blockEndTime" name="end_time" required class="w-full p-2 border border-[#e2dbe6] rounded-xl">
<option value="">Select end time</option>
<?php foreach ($block_time_slots as $slot): ?>
<option value="<?php echo htmlspecialchars($slot['value']); ?>"><?php echo htmlspecialchars($slot['display']); ?></option>
<?php endforeach; ?>
</select>
</div>
</div>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">Reason (Optional)</label>
<input type="text" id="blockReason" name="reason" placeholder="e.g., Lunch, Personal" class="w-full p-2 border border-[#e2dbe6] rounded-xl"/>
</div>
<div class="flex gap-3 justify-end">
<button type="button" onclick="closeBlockTimeModal()" class="px-4 py-2 border border-[#e2dbe6] rounded-xl text-sm font-medium hover:bg-[#f8f9fa] transition-colors">Cancel</button>
<button type="submit" class="px-4 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary/90 transition-colors">Block Time</button>
</div>
</form>
</div>
</div>

</div>
</div>

<!-- Appointment Details Modal -->
<div id="appointmentModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6 relative animate-[fadeIn_0.2s_ease-out]">
        <button onclick="closeAppointmentModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 transition-colors">
            <span class="material-symbols-outlined">close</span>
        </button>
        <div class="flex flex-col items-center text-center gap-2 mb-6">
             <div class="w-16 h-16 rounded-full bg-primary/10 flex items-center justify-center text-primary mb-2">
                <span class="material-symbols-outlined text-3xl">calendar_month</span>
             </div>
             <h3 class="text-xl font-bold text-[#151118]" id="modalServiceName">Service Name</h3>
             <p class="text-sm font-medium text-slate-500" id="modalPatientName">Patient Name</p>
        </div>
        
        <div class="space-y-4 mb-6">
            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                 <span class="material-symbols-outlined text-primary">schedule</span>
                 <div class="text-left">
                     <p class="text-xs text-slate-500 font-bold uppercase">Time</p>
                     <p class="text-sm font-bold text-[#151118]" id="modalTime">00:00 - 00:00</p>
                 </div>
            </div>
            <div class="flex items-center gap-3 p-3 bg-gray-50 rounded-xl">
                 <span class="material-symbols-outlined text-primary">info</span>
                 <div class="text-left">
                     <p class="text-xs text-slate-500 font-bold uppercase">Status</p>
                     <p class="text-sm font-bold text-[#151118]" id="modalStatus">Pending</p>
                 </div>
            </div>
        </div>
        
        <button id="modalDashboardBtn" onclick="" class="w-full py-3 bg-primary text-white rounded-xl font-bold hover:bg-primary/90 transition-colors flex items-center justify-center gap-2">
            <span class="material-symbols-outlined">dashboard</span>
            Go to Dashboard
        </button>
    </div>
</div>

<script>
const appointmentsData = <?php echo json_encode($appointments) ?: '[]'; ?>;
const currentDate = new Date('<?php echo $current_date; ?>');
const currentView = '<?php echo $view_mode; ?>';

// Notification Toast System

function showNotification(type, message) {
    const toast = document.getElementById('notificationToast');
    const icon = document.getElementById('notificationIcon');
    const iconSpan = icon.querySelector('span');
    const messageEl = document.getElementById('notificationMessage');
    
    // Set icon and colors based on type
    const configs = {
        'success': { icon: 'check_circle', color: 'bg-green-100 text-green-700' },
        'error': { icon: 'error', color: 'bg-red-100 text-red-700' },
        'info': { icon: 'info', color: 'bg-primary/10 text-primary' },
        'warning': { icon: 'warning', color: 'bg-yellow-100 text-yellow-700' }
    };
    
    const config = configs[type] || configs['info'];
    icon.className = `w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0 ${config.color}`;
    iconSpan.textContent = config.icon;
    messageEl.textContent = message;
    
    // Show toast
    toast.classList.remove('translate-x-full');
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        hideNotification();
    }, 5000);
}

function hideNotification() {
    const toast = document.getElementById('notificationToast');
    toast.classList.add('translate-x-full');
}

function navigateWeek(direction) {
    const newDate = new Date(currentDate);
    if (currentView === 'day') {
        newDate.setDate(newDate.getDate() + direction);
    } else if (currentView === 'week') {
        newDate.setDate(newDate.getDate() + (direction * 7));
    } else if (currentView === 'month') {
        newDate.setMonth(newDate.getMonth() + direction);
    }
    const dateStr = newDate.toISOString().split('T')[0];
    window.location.href = `?date=${dateStr}&view=${currentView}`;
}

function goToToday() {
    const today = new Date().toISOString().split('T')[0];
    window.location.href = `?date=${today}&view=${currentView}`;
}

function switchView(view) {
    window.location.href = `?date=<?php echo $current_date; ?>&view=${view}`;
}

function navigateMiniCalendar(direction) {
    const newDate = new Date(currentDate);
    newDate.setMonth(newDate.getMonth() + direction);
    const dateStr = newDate.toISOString().split('T')[0];
    window.location.href = `?date=${dateStr}&view=${currentView}`;
}

function selectDate(date) {
    window.location.href = `?date=${date}&view=${currentView}`;
}

function selectWeekDay(date) {
    window.location.href = `?date=${date}&view=${currentView}`;
}

function openBlockTimeModal() {
    document.getElementById('blockTimeModal').classList.remove('hidden');
    // Update time slots based on current date
    updateBlockTimeSlots();
}

function closeBlockTimeModal() {
    document.getElementById('blockTimeModal').classList.add('hidden');
    document.getElementById('blockTimeForm').reset();
}

function updateBlockTimeSlots() {
    const dateInput = document.getElementById('blockDate');
    const startSelect = document.getElementById('blockStartTime');
    const endSelect = document.getElementById('blockEndTime');
    const selectedDate = dateInput.value || '<?php echo $current_date; ?>';
    
    // Disable selects while loading
    startSelect.disabled = true;
    endSelect.disabled = true;
    
    // Fetch time slots for the selected date
    fetch(`../api/get_block_time_slots.php?date=${selectedDate}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    throw new Error('Invalid response from server');
                }
            });
        })
        .then(data => {
            if (data.success && data.slots) {
                // Clear existing options (except the first "Select" option)
                const startFirstOption = startSelect.options[0];
                const endFirstOption = endSelect.options[0];
                startSelect.innerHTML = '';
                endSelect.innerHTML = '';
                startSelect.appendChild(startFirstOption);
                endSelect.appendChild(endFirstOption);
                
                // Add new options
                data.slots.forEach(slot => {
                    const startOption = document.createElement('option');
                    startOption.value = slot.value;
                    startOption.textContent = slot.display;
                    startSelect.appendChild(startOption);
                    
                    const endOption = document.createElement('option');
                    endOption.value = slot.value;
                    endOption.textContent = slot.display;
                    endSelect.appendChild(endOption);
                });
            }
        })
        .catch(error => {
            console.error('Error fetching time slots:', error);
        })
        .finally(() => {
            startSelect.disabled = false;
            endSelect.disabled = false;
        });
}

function viewAppointment(appointmentId) {
    const appt = appointmentsData.find(a => a.appointment_id == appointmentId);
    if (!appt) return;
    
    document.getElementById('modalServiceName').textContent = appt.service_name;
    document.getElementById('modalPatientName').textContent = appt.patient_name || 'N/A';
    
    // Format Time
    const startTime = new Date('1970-01-01T' + appt.time_slot);
    const endTime = new Date(startTime.getTime() + (appt.duration_mins * 60000));
    const timeStr = startTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'}) + ' - ' + 
                    endTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit'});
                    
    document.getElementById('modalTime').textContent = timeStr;
    document.getElementById('modalStatus').textContent = appt.status;
    
    // Status Color
    const statusEl = document.getElementById('modalStatus');
    if (appt.status === 'Completed') statusEl.className = "text-sm font-bold text-green-600";
    else if (appt.status === 'Cancelled') statusEl.className = "text-sm font-bold text-red-600";
    else statusEl.className = "text-sm font-bold text-[#151118]";

    document.getElementById('modalDashboardBtn').onclick = function() {
        window.location.href = `doctor_dashboard.php?appointment_id=${appointmentId}`;
    };
    
    document.getElementById('appointmentModal').classList.remove('hidden');
}

function closeAppointmentModal() {
    document.getElementById('appointmentModal').classList.add('hidden');
}

function confirmUnblock(blockId) {
    Swal.fire({
        title: 'Unblock this time?',
        text: "This will remove the blocked slot and make it available for booking.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#6A1B9A',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, unblock it!'
    }).then((result) => {
        if (result.isConfirmed) {
            fetch('../api/unblock_time.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ block_id: blockId })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    Swal.fire(
                        'Unblocked!',
                        'The time slot has been unblocked.',
                        'success'
                    ).then(() => {
                        window.location.reload();
                    });
                } else {
                    Swal.fire(
                        'Error!',
                        data.message || 'Failed to unblock.',
                        'error'
                    );
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire('Error!', 'Something went wrong.', 'error');
            });
        }
    });
}


// Handle block time form submission
document.getElementById('blockTimeForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    const form = e.target;
    const formData = {
        block_date: document.getElementById('blockDate').value,
        start_time: document.getElementById('blockStartTime').value,
        end_time: document.getElementById('blockEndTime').value,
        reason: document.getElementById('blockReason').value || ''
    };
    
    // Validate
    if (!formData.block_date || !formData.start_time || !formData.end_time) {
        showNotification('error', 'Please fill in all required fields.');
        return;
    }
    
    if (formData.start_time >= formData.end_time) {
        showNotification('error', 'End time must be after start time.');
        return;
    }
    
    // Disable submit button
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn.textContent;
    submitBtn.disabled = true;
    submitBtn.textContent = 'Blocking...';
    
    // Submit via AJAX
    fetch('../api/block_time.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(formData)
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.text().then(text => {
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON response:', text);
                throw new Error('Invalid response from server');
            }
        });
    })
    .then(data => {
        if (data.success) {
            showNotification('success', 'Time blocked successfully!');
            setTimeout(() => {
                window.location.reload();
            }, 1000);
        } else {
            showNotification('error', data.message || 'Error blocking time. Please try again.');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showNotification('error', 'Error blocking time. Please try again.');
        submitBtn.disabled = false;
        submitBtn.textContent = originalText;
    });
});

// Update time slots when date changes
document.getElementById('blockDate')?.addEventListener('change', function() {
    updateBlockTimeSlots();
    // Reset time selections when date changes
    document.getElementById('blockStartTime').value = '';
    document.getElementById('blockEndTime').value = '';
});

// Close modal on background click
document.getElementById('blockTimeModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeBlockTimeModal();
    }
});
</script>



</body></html>
