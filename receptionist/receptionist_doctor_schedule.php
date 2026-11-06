<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Receptionist role
requireRole('Receptionist');

// Update current user's activity
updateUserActivity();

// Get logged-in user information (Receptionist)
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $user_details_stmt = $pdo->prepare("SELECT user_id, username, full_name, role, email, profile_picture FROM tbl_user WHERE user_id = ? LIMIT 1");
        $user_details_stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $user_details_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching user details: ' . $e->getMessage());
    }
}

// Helper function for profile photo
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
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    return ['initials' => $initials, 'bg_color' => $color['bg'], 'text_color' => $color['text']];
}

$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Receptionist';
$current_role = $current_user['role'] ?? 'Receptionist';
$current_profile = $current_user['profile_picture'] ?? null;
$current_page = 'receptionist_doctor_schedule.php'; // Active link
$default_photo = getDefaultProfilePhoto($current_name);


// --- DOCTOR SELECTION LOGIC ---
// Fetch all active dentists
$dentists = [];
try {
    $stmt = $pdo->query("SELECT user_id, full_name, profile_picture FROM tbl_user WHERE role='Dentist' AND status='Active' ORDER BY full_name ASC");
    $dentists = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching dentists: " . $e->getMessage());
}

// Determine selected doctor
$selected_doctor_id = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;

// Default to first doctor if none select or invalid
if ($selected_doctor_id === 0 && !empty($dentists)) {
    $selected_doctor_id = $dentists[0]['user_id'];
}
$selected_doctor = null;
foreach ($dentists as $d) {
    if ($d['user_id'] == $selected_doctor_id) {
        $selected_doctor = $d;
        break;
    }
}


// --- CALENDAR LOGIC (Same as Doctor Schedule, but using $selected_doctor_id) ---
$view_mode = isset($_GET['view']) ? sanitizeInput($_GET['view']) : 'week';
$timezone = new DateTimeZone('Asia/Manila');
$current_date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');

try {
    $date_obj = new DateTime($current_date . ' 12:00:00', $timezone);
} catch (Exception $e) {
    $date_obj = new DateTime('now', $timezone);
    $current_date = $date_obj->format('Y-m-d');
}

// Calculate Date Ranges
$day_of_week = (int)$date_obj->format('w');
$week_start = clone $date_obj;
if ($day_of_week != 0) $week_start->modify('-' . $day_of_week . ' days');
$week_start->setTime(12, 0, 0);
$week_end = clone $week_start;
$week_end->modify('+6 days');
$week_end->setTime(23, 59, 59);

$month_start = clone $date_obj;
$month_start->modify('first day of this month');
$month_end = clone $date_obj;
$month_end->modify('last day of this month');

$month_year = $date_obj->format('F Y');
$month_num = $date_obj->format('m');
$year_num = $date_obj->format('Y');

// Fetch Appointments for Selected Doctor
$appointments = [];
if ($selected_doctor_id) {
    try {
        $sql = "
            SELECT a.*, 
                   p.first_name, p.last_name,
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                   GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
            FROM tbl_appointment a
            INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
            LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
            LEFT JOIN tbl_service s ON tas.service_id = s.service_id
            WHERE a.doctor_id = ? 
              AND a.status IN ('Pending', 'Booked', 'In-Procedure', 'Completed')
        ";
        
        $params = [$selected_doctor_id];
        
        if ($view_mode === 'day') {
            $sql .= " AND a.appointment_date = ?";
            $params[] = $current_date;
        } elseif ($view_mode === 'week') {
            $sql .= " AND a.appointment_date BETWEEN ? AND ?";
            $params[] = $week_start->format('Y-m-d');
            $params[] = $week_end->format('Y-m-d');
        } else {
            $sql .= " AND a.appointment_date BETWEEN ? AND ?";
            $params[] = $month_start->format('Y-m-d');
            $params[] = $month_end->format('Y-m-d');
        }
        
        $sql .= " GROUP BY a.appointment_id ORDER BY a.appointment_date ASC, a.time_slot ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Fetch Blocked Time
        $block_sql = "SELECT block_id, block_date, start_time, end_time, reason FROM tbl_blocked_time WHERE doctor_id = ?";
        $b_params = [$selected_doctor_id];
        
        if ($view_mode === 'day') {
            $block_sql .= " AND block_date = ?";
            $b_params[] = $current_date;
        } elseif ($view_mode === 'week') {
            $block_sql .= " AND block_date BETWEEN ? AND ?";
            $b_params[] = $week_start->format('Y-m-d');
            $b_params[] = $week_end->format('Y-m-d');
        } else {
            $block_sql .= " AND block_date BETWEEN ? AND ?";
            $b_params[] = $month_start->format('Y-m-d');
            $b_params[] = $month_end->format('Y-m-d');
        }
        
        $blkStmt = $pdo->prepare($block_sql);
        $blkStmt->execute($b_params);
        $blocks = $blkStmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($blocks as $blk) {
            $start = strtotime($blk['start_time']);
            $end = strtotime($blk['end_time']);
            $duration = ($end - $start) / 60;
            
            $appointments[] = [
                'appointment_id' => $blk['block_id'],
                'appointment_date' => $blk['block_date'],
                'time_slot' => $blk['start_time'],
                'service_name' => 'Blocked Time',
                'patient_name' => $blk['reason'] ?: 'Unavailable',
                'status' => 'Blocked',
                'type' => 'blocked_time',
                'first_name' => '', 'last_name' => '',
                'duration_mins' => $duration
            ];
        }
        
        // Sort merged array
        usort($appointments, function($a, $b) {
            $t1 = strtotime($a['appointment_date'] . ' ' . $a['time_slot']);
            $t2 = strtotime($b['appointment_date'] . ' ' . $b['time_slot']);
            return $t1 - $t2;
        });

    } catch (PDOException $e) {
        error_log("Error fetching schedule data: " . $e->getMessage());
    }
}

// Organize by day
$appointments_by_day = [];
foreach ($appointments as $appt) {
    $day = $appt['appointment_date'];
    if (!isset($appointments_by_day[$day])) $appointments_by_day[$day] = [];
    $appointments_by_day[$day][] = $appt;
}

// Month Logic
$month_calendar = [];
if ($view_mode === 'month') {
    $first_day = new DateTime("$year_num-$month_num-01", $timezone);
    $first_day->setTime(12,0,0);
    $last_day = clone $first_day;
    $last_day->modify('last day of this month');
    
    $start_padding = (int)$first_day->format('w');
    $days_in_month = (int)$last_day->format('j');
    
    // Padding
    for($i=0; $i<$start_padding; $i++) $month_calendar[] = ['day' => '', 'date' => null];
    
    // Days
    for($d=1; $d<=$days_in_month; $d++) {
        $d_str = sprintf('%04d-%02d-%02d', $year_num, $month_num, $d);
        $month_calendar[] = [
            'day' => $d,
            'date' => $d_str,
            'is_today' => ($d_str === date('Y-m-d')),
            'is_selected' => ($d_str === $current_date),
            'appt_count' => count($appointments_by_day[$d_str] ?? [])
        ];
    }
    
    // Trailing
    while(count($month_calendar) % 7 != 0) $month_calendar[] = ['day' => '', 'date' => null];
}

// Week View Headers
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

function getAppointmentStyle($service_name, $status) {
    // Check for Holiday specifically (often stored as "Blocked Time" but maybe reason is Holiday)
    // Or just generic Blocked Time
    if ($status === 'Blocked' || strpos($service_name, 'Blocked') !== false) {
        return ['bg'=>'bg-red-50', 'border'=>'border-red-400', 'text'=>'text-red-800', 'text_light'=>'text-red-700'];
    }
    
    $lower = strtolower($service_name);
    if (strpos($lower, 'cleaning')!==false) return ['bg'=>'bg-blue-50', 'border'=>'border-blue-500', 'text'=>'text-blue-800', 'text_light'=>'text-blue-700'];
    if (strpos($lower, 'consult')!==false) return ['bg'=>'bg-yellow-50', 'border'=>'border-yellow-400', 'text'=>'text-yellow-800', 'text_light'=>'text-yellow-700'];
    
    return ['bg'=>'bg-primary/10', 'border'=>'border-primary', 'text'=>'text-primary', 'text_light'=>'text-[#7a6189]'];
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Doctor Schedules - Receptionist</title>
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
        .clinical-scroll::-webkit-scrollbar { width: 8px; height: 8px; }
        .clinical-scroll::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .clinical-scroll::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
        .clinical-scroll::-webkit-scrollbar-thumb:hover { background: #9ca3af; }
    </style>
</head>
<body class="bg-background-light text-[#151118] font-display antialiased overflow-hidden">
<div class="flex h-screen w-full">

    <!-- RECEPTIONIST SIDEBAR -->
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
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_dashboard.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="font-medium">Dashboard</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_registration.php">
                <span class="material-symbols-outlined">person_add</span>
                <span class="font-medium">Patient Registration</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="patient_records.php">
                <span class="material-symbols-outlined">folder_shared</span>
                <span class="font-medium">Patient Records</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_booking.php">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="font-medium">Bookings</span>
            </a>
            
            <!-- NEW: DOCTOR SCHEDULE LINK -->
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl bg-white text-royal-purple shadow-lg transition-all group" href="receptionist_doctor_schedule.php">
                <span class="material-symbols-outlined">perm_contact_calendar</span>
                <span class="font-bold">Doctor Schedules</span>
            </a>

            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_queue.php">
                <span class="material-symbols-outlined">monitor_heart</span>
                <span class="font-medium">Queue Monitor</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-medium">Payments</span>
            </a>
        </nav>
        <div class="p-6">
            <a href="../logout.php" class="flex items-center justify-center gap-2 mt-4 w-full py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium">
                <span class="material-symbols-outlined text-[18px]">logout</span>
                Logout
            </a>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="flex-1 flex flex-col h-full overflow-hidden">
        <!-- HEADER -->
        <header class="h-16 flex items-center justify-between px-8 bg-white border-b border-[#f3f0f4] shrink-0 z-10">
            <h2 class="text-[#151118] text-base font-bold flex items-center gap-2">
                <span class="material-symbols-outlined text-[#7a6189]">perm_contact_calendar</span>
                Doctor Schedules
            </h2>
            <div class="text-sm font-bold text-primary">
                <?php echo date('F j, Y'); ?>
            </div>
        </header>
        
        <!-- MAIN BODY - SWAPPED LAYOUT -->
        <div class="flex-1 flex overflow-hidden">
            
            <!-- LEFT PANEL: DOCTOR LIST -->
            <div class="w-80 bg-white border-r border-[#e2dbe6] flex flex-col shrink-0 z-10">
                <div class="p-4 border-b border-[#e2dbe6]">
                    <h3 class="text-xs font-bold text-[#7a6189] uppercase tracking-wider mb-4">Select Doctor</h3>
                    
                    <div class="flex flex-col gap-2 overflow-y-auto max-h-[calc(100vh-200px)] pr-2 clinical-scroll">
                        <?php foreach ($dentists as $doc): 
                            $isSelected = ($doc['user_id'] == $selected_doctor_id);
                            $docClasses = $isSelected 
                                ? 'bg-primary/10 border-primary shadow-sm' 
                                : 'bg-white border-transparent hover:bg-gray-50';
                            
                            $docPhoto = $doc['profile_picture'] && file_exists('../'.$doc['profile_picture']) 
                                ? '../'.$doc['profile_picture'] 
                                : null;
                            $defPhoto = getDefaultProfilePhoto($doc['full_name']);
                        ?>
                        <a href="?doctor_id=<?php echo $doc['user_id']; ?>&view=<?php echo $view_mode; ?>&date=<?php echo $current_date; ?>" 
                           class="flex items-center gap-3 p-3 rounded-xl border transition-all <?php echo $docClasses; ?>">
                            
                            <?php if ($docPhoto): ?>
                                <div class="w-10 h-10 rounded-full bg-cover bg-center shrink-0 border border-gray-100" style="background-image: url('<?php echo htmlspecialchars($docPhoto); ?>');"></div>
                            <?php else: ?>
                                <div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-sm shrink-0" 
                                     style="background-color: <?php echo $defPhoto['bg_color']; ?>; color: <?php echo $defPhoto['text_color']; ?>;">
                                    <?php echo htmlspecialchars($defPhoto['initials']); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-[#151118] truncate"><?php echo htmlspecialchars($doc['full_name']); ?></p>
                                <p class="text-xs text-[#7a6189]">Dentist</p>
                            </div>
                            
                            <?php if ($isSelected): ?>
                                <span class="material-symbols-outlined text-primary text-xl">check_circle</span>
                            <?php endif; ?>
                        </a>
                        <?php endforeach; ?>
                        
                        <?php if (empty($dentists)): ?>
                            <div class="p-4 text-center text-slate-500 text-sm">No active dentists found.</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Legend -->
                <div class="p-4 mt-auto border-t border-[#e2dbe6]">
                     <h3 class="text-xs font-bold text-[#7a6189] uppercase tracking-wider mb-2">Legend</h3>
                     <div class="space-y-2">
                        <div class="flex items-center gap-2"><div class="w-3 h-3 rounded bg-blue-100 border border-blue-400"></div><span class="text-xs">Cleaning</span></div>
                        <div class="flex items-center gap-2"><div class="w-3 h-3 rounded bg-yellow-100 border border-yellow-400"></div><span class="text-xs">Consultation</span></div>
                        <div class="flex items-center gap-2"><div class="w-3 h-3 rounded bg-primary/10 border border-primary"></div><span class="text-xs">Treatment</span></div>
                        <div class="flex items-center gap-2"><div class="w-3 h-3 rounded bg-gray-100 border border-gray-400"></div><span class="text-xs">Blocked</span></div>
                     </div>
                </div>
            </div>
            
            <!-- RIGHT/MAIN: CALENDAR -->
            <div class="flex-1 flex flex-col min-w-0 bg-[#f7f6f8] relative">
            
                <?php if (!$selected_doctor_id): ?>
                    <div class="flex-1 flex items-center justify-center flex-col text-slate-400">
                        <span class="material-symbols-outlined text-6xl mb-4">perm_contact_calendar</span>
                        <p>Select a doctor to view their schedule</p>
                    </div>
                <?php else: ?>
                    
                    <!-- Calendar Controls -->
                    <div class="p-4 flex items-center justify-between shrink-0 bg-white border-b border-[#e2dbe6]">
                        <div class="flex items-center gap-4">
                            <h2 class="text-lg font-bold text-[#151118]">
                                <?php if($view_mode==='day') echo date('F j, Y', strtotime($current_date));
                                      elseif($view_mode==='week') echo $week_start->format('M j') . ' - ' . $week_end->format('M j, Y');
                                      else echo $month_year; ?>
                            </h2>
                            <div class="flex items-center bg-gray-100 rounded-lg p-1">
                                <button onclick="window.location.href='?view=day&doctor_id=<?php echo $selected_doctor_id; ?>&date=<?php echo $current_date; ?>'" 
                                        class="px-3 py-1 rounded text-sm font-bold <?php echo $view_mode==='day' ? 'bg-white shadow-sm text-primary' : 'text-slate-500 hover:text-primary'; ?>">Day</button>
                                <button onclick="window.location.href='?view=week&doctor_id=<?php echo $selected_doctor_id; ?>&date=<?php echo $current_date; ?>'" 
                                        class="px-3 py-1 rounded text-sm font-bold <?php echo $view_mode==='week' ? 'bg-white shadow-sm text-primary' : 'text-slate-500 hover:text-primary'; ?>">Week</button>
                                <button onclick="window.location.href='?view=month&doctor_id=<?php echo $selected_doctor_id; ?>&date=<?php echo $current_date; ?>'" 
                                        class="px-3 py-1 rounded text-sm font-bold <?php echo $view_mode==='month' ? 'bg-white shadow-sm text-primary' : 'text-slate-500 hover:text-primary'; ?>">Month</button>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                             <input type="date" value="<?php echo $current_date; ?>" 
                                    onchange="window.location.href='?doctor_id=<?php echo $selected_doctor_id; ?>&view=<?php echo $view_mode; ?>&date='+this.value"
                                    class="border-gray-200 rounded-lg text-sm focus:ring-primary">
                        </div>
                    </div>
                    
                    <!-- CALENDAR GRID -->
                    <div class="flex-1 overflow-y-auto clinical-scroll relative bg-white">
                        <?php if ($view_mode === 'day' || $view_mode === 'week'): ?>
                             <!-- Time Grid (Same structure as doctor_schedule logic) -->
                             <div class="relative min-h-[1040px]">
                                <!-- Y-Axis Time Labels -->
                                <div class="absolute left-0 top-10 bottom-0 w-16 border-r border-gray-100 bg-gray-50 z-10 text-xs text-slate-400 font-medium text-center pt-2">
                                    <?php for($h=8; $h<=19; $h++): ?>
                                        <div class="h-[80px] -mt-2"><?php echo $h>12 ? ($h-12).' PM' : ($h==12?'12 PM':$h.' AM'); ?></div>
                                    <?php endfor; ?>
                                </div>
                                
                                <!-- Background Lines -->
                                <div class="ml-16 mt-10">
                                    <?php for($h=8; $h<=19; $h++): ?>
                                        <div class="h-[80px] border-b border-gray-100 w-full"></div>
                                    <?php endfor; ?>
                                </div>
                                
                                <!-- Columns -->
                                <div class="absolute inset-0 ml-16 flex">
                                    <?php 
                                    $col_days = ($view_mode==='day') ? [[ 'date' => new DateTime($current_date) ]] : $week_days;
                                    $col_width = (100 / count($col_days)) . '%';
                                    
                                    foreach ($col_days as $day_col) {
                                        $d_str = $day_col['date']->format('Y-m-d');
                                        $day_appts = $appointments_by_day[$d_str] ?? [];
                                        
                                        echo "<div class='border-r border-gray-100 h-full relative' style='width: $col_width;'>";
                                        
                                        // Header inside column logic
                                        echo "<div class='h-10 border-b border-gray-200 bg-gray-50 flex items-center justify-center absolute -top-10 w-full font-bold text-sm text-slate-700'>";
                                        echo $day_col['date']->format('D, M j');
                                        echo "</div>";
                                        
                                        foreach ($day_appts as $appt) {
                                            $parsed_time = strtotime($appt['time_slot']);
                                            $hour = (int)date('G', $parsed_time);
                                            $minute = (int)date('i', $parsed_time);
                                            
                                            $start_min = ($hour * 60) + $minute;
                                            $end_min = $start_min + ($appt['duration_mins'] ?? 60);
                                            
                                            // Define visible range (8:00 AM to 8:00 PM)
                                            $visible_start = 8 * 60;
                                            $visible_end = 20 * 60;

                                            // Skip if completely outside
                                            if ($end_min <= $visible_start || $start_min >= $visible_end) continue;

                                            // Clamp visual start/end
                                            $visual_start = max($start_min, $visible_start);
                                            $visual_end = min($end_min, $visible_end);
                                            
                                            // Calculate position
                                            $top_pos = (($visual_start - $visible_start) / 60) * 80;
                                            $height = (($visual_end - $visual_start) / 60) * 80;
                                            
                                            $style = getAppointmentStyle($appt['service_name'], $appt['status']);
                                            
                                            echo "<div class='absolute left-1 right-1 rounded p-1 border-l-4 text-[10px] overflow-hidden hover:z-20 hover:shadow-lg transition-all cursor-pointer {$style['bg']} {$style['border']} {$style['text']}' 
                                                       style='top: {$top_pos}px; height: {$height}px;'
                                                       title='{$appt['patient_name']} - {$appt['service_name']}'>
                                                    <div class='font-bold truncate'>{$appt['service_name']}</div>
                                                    <div class='truncate font-medium'>{$appt['patient_name']}</div>
                                                    <div class='truncate opacity-75'>" . date('g:i A', strtotime($appt['time_slot'])) . "</div>
                                                  </div>";
                                        }
                                        echo "</div>";
                                    }
                                    ?>
                                </div>
                             </div>
                        
                        <?php elseif ($view_mode === 'month'): ?>
                             <div class="grid grid-cols-7 h-full auto-rows-fr border-l border-t border-gray-200">
                                 <!-- Week Headers -->
                                 <div class="text-center font-bold text-slate-500 py-1 border-b border-r border-gray-200 bg-gray-50">Sun</div>
                                 <div class="text-center font-bold text-slate-500 py-1 border-b border-r border-gray-200 bg-gray-50">Mon</div>
                                 <div class="text-center font-bold text-slate-500 py-1 border-b border-r border-gray-200 bg-gray-50">Tue</div>
                                 <div class="text-center font-bold text-slate-500 py-1 border-b border-r border-gray-200 bg-gray-50">Wed</div>
                                 <div class="text-center font-bold text-slate-500 py-1 border-b border-r border-gray-200 bg-gray-50">Thu</div>
                                 <div class="text-center font-bold text-slate-500 py-1 border-b border-r border-gray-200 bg-gray-50">Fri</div>
                                 <div class="text-center font-bold text-slate-500 py-1 border-b border-r border-gray-200 bg-gray-50">Sat</div>
                                 
                                 <?php foreach ($month_calendar as $cell): 
                                     $cellClass = $cell['date'] ? 'bg-white hover:bg-gray-50' : 'bg-gray-50';
                                     $currentClass = $cell['is_today'] ? 'bg-primary/5' : '';
                                     $selectedClass = $cell['is_selected'] ? 'ring-2 ring-inset ring-primary' : '';
                                 ?>
                                     <div class="border-b border-r border-gray-200 p-1 min-h-[100px] flex flex-col <?php echo "$cellClass $currentClass $selectedClass"; ?>"
                                          <?php if($cell['date']) echo "onclick=\"window.location.href='?view=day&doctor_id=$selected_doctor_id&date={$cell['date']}'\" style='cursor:pointer'"; ?>>
                                         
                                         <div class="text-right text-xs font-bold <?php echo $cell['is_today']?'text-primary':'text-slate-400'; ?> mb-1">
                                             <?php echo $cell['day']; ?>
                                         </div>
                                         
                                         <div class="flex-1 flex flex-col gap-1 overflow-hidden">
                                             <?php 
                                             if ($cell['date']) {
                                                 $d_appts = $appointments_by_day[$cell['date']] ?? [];
                                                 $count = 0;
                                                 foreach ($d_appts as $appt) {
                                                     if ($count++ >= 3) {
                                                         echo "<div class='text-[9px] text-center font-bold text-slate-500'>+" . (count($d_appts)-3) . " more</div>";
                                                         break;
                                                     }
                                                     $style = getAppointmentStyle($appt['service_name'], $appt['status']);
                                                     echo "<div class='truncate text-[9px] px-1 rounded {$style['bg']} {$style['text']}'>" . substr($appt['service_name'],0,10) . "</div>";
                                                 }
                                             }
                                             ?>
                                         </div>
                                     </div>
                                 <?php endforeach; ?>
                             </div>
                        <?php endif; ?>
                    </div>
                
                <?php endif; ?>
            
            </div>
        </div>
    </main>
</div>
</body>
</html>
