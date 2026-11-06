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

$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Admin User';
$current_role = $current_user['role'] ?? 'Admin';
$current_profile = $current_user['profile_picture'] ?? null;
$default_photo = getDefaultProfilePhoto($current_name);

// --- AUTO-MIGRATION: Create tbl_settings if not exists ---
try {
    $check = $pdo->query("SHOW TABLES LIKE 'tbl_settings'");
    if($check->rowCount() == 0) {
        $pdo->exec("CREATE TABLE tbl_settings (
            setting_id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) NOT NULL UNIQUE,
            setting_value TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_key (setting_key)
        )");
        $pdo->exec("INSERT INTO tbl_settings (setting_key, setting_value) VALUES ('appointment_fee', '200.00')");
    }
} catch (Exception $e) { /* Ignore */ }

// Handle General Settings Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings'])) {
    try {
        $fee = $_POST['appointment_fee'] ?? '200.00';
        $overtime_fee = $_POST['appointment_overtime_fee'] ?? '50.00';
        $overtime_interval = $_POST['appointment_overtime_interval_mins'] ?? '15';
        
        // Update all three settings
        $stmt = $pdo->prepare("INSERT INTO tbl_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        $stmt->execute(['appointment_fee', $fee]);
        $stmt->execute(['appointment_overtime_fee', $overtime_fee]);
        $stmt->execute(['appointment_overtime_interval_mins', $overtime_interval]);
        
        $message = 'Appointment fee settings updated successfully!';
        $message_type = 'success';
    } catch (Exception $e) {
        $message = 'Failed to update settings.';
        $message_type = 'error';
    }
}

// Fetch Settings
$settings = [];
try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM tbl_settings");
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
} catch(Exception $e) {}

$appointment_fee = $settings['appointment_fee'] ?? '200.00';
$appointment_overtime_fee = $settings['appointment_overtime_fee'] ?? '50.00';
$appointment_overtime_interval = $settings['appointment_overtime_interval_mins'] ?? '15';

// Handle Schedule Updates
$message = $message ?? ''; // Preserve message from above if set
$message_type = $message_type ?? '';
 
 if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_schedule'])) {
    try {
        $days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $pdo->beginTransaction();
        
        foreach ($days_of_week as $day) {
            // Check if day is active (checkbox)
            $is_open = isset($_POST[$day . '_active']) ? 1 : 0;
            $start_time = $_POST[$day . '_start'] ?? null;
            $end_time = $_POST[$day . '_end'] ?? null;

            if ($is_open && (empty($start_time) || empty($end_time))) {
                throw new InvalidArgumentException("$day requires both opening and closing times.");
            }

            if (!preg_match('/^\d{2}:\d{2}$/', (string)$start_time) ||
                !preg_match('/^\d{2}:\d{2}$/', (string)$end_time)) {
                $start_time = '09:00';
                $end_time = '17:00';
            }

            if ($is_open && $end_time <= $start_time) {
                throw new InvalidArgumentException("$day closing time must be later than opening time.");
            }

            $stmt = $pdo->prepare("INSERT INTO tbl_clinic_schedule (day_of_week, start_time, end_time, is_open) 
                                   VALUES (?, ?, ?, ?) 
                                   ON DUPLICATE KEY UPDATE 
                                   start_time = VALUES(start_time), 
                                   end_time = VALUES(end_time), 
                                   is_open = VALUES(is_open)");
            $stmt->execute([$day, $start_time, $end_time, $is_open]);
        }
        
        $pdo->commit();
        $message = 'Clinic schedule updated successfully!';
        $message_type = 'success';
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log("Error updating schedule: " . $e->getMessage());
        $message = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : 'Failed to update schedule. Please try again.';
        $message_type = 'error';
    }
}

// Fetch Current Schedule
$schedule_data = [];
try {
    $stmt = $pdo->query("SELECT * FROM tbl_clinic_schedule");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $schedule_data[$row['day_of_week']] = $row;
    }
} catch (Exception $e) {
    error_log("Error fetching schedule: " . $e->getMessage());
}

// Ensure all days exist in array for display
$days_list = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
foreach ($days_list as $day) {
    if (!isset($schedule_data[$day])) {
        $schedule_data[$day] = [
            'day_of_week' => $day,
            'start_time' => '',
            'end_time' => '',
            'is_open' => 0
        ];
    }
}

// Helper for time input value
function formatTimeInput($time) {
    if (empty($time)) return '';
    return date('H:i', strtotime($time));
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Settings - Vanessa Nicolas Dental Clinic</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
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
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_dashboard.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">dashboard</span>
<p class="text-sm font-medium leading-normal">Dashboard</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_user.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">group</span>
<p class="text-sm font-medium leading-normal">Users</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_patients.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">accessibility_new</span>
<p class="text-sm font-medium leading-normal">Patients</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_schedule.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">schedule</span>
<p class="text-sm font-medium leading-normal">Schedules</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_service.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">medical_services</span>
<p class="text-sm font-medium leading-normal">Services</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_reports.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">description</span>
<p class="text-sm font-medium leading-normal">Reports</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary transition-all" href="admin_settings.php">
<span class="material-symbols-outlined fill-1">settings</span>
<p class="text-sm font-bold leading-normal">Settings</p>
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
<main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
<header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
<div class="flex flex-col gap-1">
<h2 class="text-3xl font-black text-text-main tracking-tight">Settings</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">settings</span>
Manage your preferences and system settings
</p>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8 scroll-smooth">
<div class="max-w-[1000px] mx-auto flex flex-col gap-6">
<!-- Clinic Hours Settings -->
<?php if ($message): ?>
<div class="mb-6 p-4 rounded-xl flex items-center gap-3 <?php echo $message_type === 'success' ? 'bg-green-50 text-green-800 border border-green-200' : 'bg-red-50 text-red-800 border border-red-200'; ?>">
    <span class="material-symbols-outlined"><?php echo $message_type === 'success' ? 'check_circle' : 'error'; ?></span>
    <p class="text-sm font-medium"><?php echo htmlspecialchars($message); ?></p>
</div>
<?php endif; ?>

<!-- General Settings -->
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 border border-gray-100 dark:border-gray-800 shadow-sm mb-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h3 class="text-lg font-bold text-text-main dark:text-white">General Settings</h3>
            <p class="text-sm text-text-sub">Manage global configurations for the clinic.</p>
        </div>
        <div class="bg-primary/10 p-3 rounded-full text-primary">
            <span class="material-symbols-outlined">tune</span>
        </div>
    </div>

    <form method="POST" action="admin_settings.php">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Base Appointment Fee -->
            <div class="flex flex-col gap-2">
                <label class="text-sm font-bold text-text-main dark:text-white">Base Appointment Fee (PHP)</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold">₱</span>
                    <input type="number" name="appointment_fee" step="0.01" min="0" value="<?php echo htmlspecialchars($appointment_fee); ?>" 
                           class="w-full pl-8 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-bold focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                </div>
                <p class="text-xs text-text-sub">Covers first 30 minutes of appointment</p>
            </div>
            
            <!-- Overtime Fee -->
            <div class="flex flex-col gap-2">
                <label class="text-sm font-bold text-text-main dark:text-white">Overtime Fee (PHP)</label>
                <div class="relative">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 font-bold">₱</span>
                    <input type="number" name="appointment_overtime_fee" step="0.01" min="0" value="<?php echo htmlspecialchars($appointment_overtime_fee); ?>" 
                           class="w-full pl-8 pr-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-bold focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                </div>
                <p class="text-xs text-text-sub">Additional fee per overtime interval</p>
            </div>
            
            <!-- Overtime Interval -->
            <div class="flex flex-col gap-2">
                <label class="text-sm font-bold text-text-main dark:text-white">Overtime Interval (Minutes)</label>
                <div class="relative">
                    <input type="number" name="appointment_overtime_interval_mins" min="1" step="1" value="<?php echo htmlspecialchars($appointment_overtime_interval); ?>" 
                           class="w-full px-4 py-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm font-bold focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 text-xs font-bold">min</span>
                </div>
                <p class="text-xs text-text-sub">Time interval for overtime charges</p>
            </div>
            
            <!-- Example Calculation -->
            <div class="flex flex-col gap-2 bg-purple-50 dark:bg-purple-900/20 p-4 rounded-xl border border-purple-200 dark:border-purple-800">
                <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-primary text-lg">calculate</span>
                    <label class="text-sm font-bold text-text-main dark:text-white">Example Calculation</label>
                </div>
                <div class="text-xs text-text-sub space-y-1">
                    <p>• 30 min = ₱<span id="example-30">200.00</span></p>
                    <p>• 45 min = ₱<span id="example-45">250.00</span></p>
                    <p>• 60 min = ₱<span id="example-60">300.00</span></p>
                </div>
            </div>
        </div>
        <div class="mt-6 flex justify-end">
            <button type="submit" name="update_settings" class="px-6 py-2.5 bg-primary hover:bg-primary/90 text-white text-sm font-bold rounded-xl shadow-lg shadow-primary/30 transition-all transform hover:scale-[1.02]">
                Save Changes
            </button>
        </div>
    </form>
</div>

<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 border border-gray-100 dark:border-gray-800 shadow-sm">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h3 class="text-lg font-bold text-text-main dark:text-white">Clinic Hours</h3>
            <p class="text-sm text-text-sub">Set the operating hours for the clinic. These will be displayed on the website.</p>
        </div>
        <div class="bg-primary/10 p-3 rounded-full text-primary">
            <span class="material-symbols-outlined">access_time</span>
        </div>
    </div>

    <form method="POST" action="admin_settings.php">
        <div class="space-y-4">
            <?php foreach ($days_list as $day): 
                $day_data = $schedule_data[$day];
                $is_active = $day_data['is_open'];
            ?>
            <div class="flex flex-col sm:flex-row sm:items-center gap-4 p-4 rounded-xl border border-gray-100 dark:border-gray-700 bg-gray-50/50 dark:bg-gray-800/20 hover:border-primary/30 transition-colors">
                <!-- Day & Toggle -->
                <div class="min-w-[140px] flex items-center gap-3">
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="<?php echo $day; ?>_active" class="sr-only peer day-toggle" data-day="<?php echo $day; ?>" <?php echo $is_active ? 'checked' : ''; ?>>
                        <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-primary"></div>
                    </label>
                    <span class="font-bold text-text-main dark:text-white"><?php echo $day; ?></span>
                </div>

                <!-- Time Inputs -->
                <div class="flex-1 flex items-center gap-2 time-inputs-<?php echo $day; ?> <?php echo !$is_active ? 'opacity-50 pointer-events-none' : ''; ?>">
                    <div class="flex-1">
                        <input type="time" name="<?php echo $day; ?>_start" value="<?php echo formatTimeInput($day_data['start_time']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                    </div>
                    <span class="text-text-sub text-sm font-medium">to</span>
                    <div class="flex-1">
                        <input type="time" name="<?php echo $day; ?>_end" value="<?php echo formatTimeInput($day_data['end_time']); ?>" class="w-full px-3 py-2 rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 text-sm focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all">
                    </div>
                </div>

                <!-- Status Text -->
                <div class="min-w-[80px] text-right">
                    <span class="text-xs font-bold px-2 py-1 rounded status-badge-<?php echo $day; ?> <?php echo $is_active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500'; ?>">
                        <?php echo $is_active ? 'OPEN' : 'CLOSED'; ?>
                    </span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-8 flex justify-end pt-6 border-t border-gray-100 dark:border-gray-800">
            <button type="submit" name="update_schedule" class="px-8 py-3 bg-primary hover:bg-primary/90 text-white font-bold rounded-xl shadow-lg shadow-primary/30 transition-all transform hover:-translate-y-1 flex items-center gap-2">
                <span class="material-symbols-outlined">save</span>
                Save Schedule
            </button>
        </div>
    </form>
</div>

<!-- Clinic Holidays Card -->
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 border border-gray-100 dark:border-gray-800 shadow-sm mt-6">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h3 class="text-lg font-bold text-text-main dark:text-white">Clinic Holidays / Block Dates</h3>
            <p class="text-sm text-text-sub">Block schedules for all active doctors (e.g., Holidays).</p>
        </div>
        <div class="bg-primary/10 p-3 rounded-full text-primary">
            <span class="material-symbols-outlined">event_busy</span>
        </div>
    </div>

    <form id="holidayForm" class="space-y-4">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold text-text-main dark:text-white mb-1">Start Date</label>
                <input type="date" name="start_date" required class="w-full px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 focus:ring-primary focus:border-primary">
            </div>
            <div>
                <label class="block text-sm font-bold text-text-main dark:text-white mb-1">End Date</label>
                <input type="date" name="end_date" required class="w-full px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 focus:ring-primary focus:border-primary">
            </div>
        </div>
        
        <div class="flex items-center gap-2 py-2">
            <input type="checkbox" id="wholeDayCheck" name="is_whole_day" value="1" class="rounded border-gray-300 text-primary focus:ring-primary size-5">
            <label for="wholeDayCheck" class="text-sm font-bold text-text-main dark:text-white cursor-pointer">Whole Day Block</label>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 transition-all duration-300" id="holidayTimeInputs">
            <div>
                <label class="block text-sm font-bold text-text-main dark:text-white mb-1">Start Time</label>
                <input type="time" name="start_time" class="w-full px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 focus:ring-primary focus:border-primary">
            </div>
            <div>
                <label class="block text-sm font-bold text-text-main dark:text-white mb-1">End Time</label>
                <input type="time" name="end_time" class="w-full px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 focus:ring-primary focus:border-primary">
            </div>
        </div>

            <label class="block text-sm font-bold text-text-main dark:text-white mb-2">Reason</label>
            <div class="space-y-2 mb-3">
                <label class="flex items-center gap-2 p-3 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="reasonType" value="Regular Holiday" class="text-primary focus:ring-primary bg-transparent" onchange="toggleHolidayReasonInput()">
                    <span class="text-sm font-medium text-text-main dark:text-white">Regular Holiday</span>
                </label>
                <label class="flex items-center gap-2 p-3 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="reasonType" value="Special Non-Working Holiday" class="text-primary focus:ring-primary bg-transparent" onchange="toggleHolidayReasonInput()">
                    <span class="text-sm font-medium text-text-main dark:text-white">Special Non-Working Holiday</span>
                </label>
                <label class="flex items-center gap-2 p-3 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="reasonType" value="Emergency" class="text-primary focus:ring-primary bg-transparent" onchange="toggleHolidayReasonInput()">
                    <span class="text-sm font-medium text-text-main dark:text-white">Emergency</span>
                </label>
                 <label class="flex items-center gap-2 p-3 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="reasonType" value="Religious holidays" class="text-primary focus:ring-primary bg-transparent" onchange="toggleHolidayReasonInput()">
                    <span class="text-sm font-medium text-text-main dark:text-white">Religious holidays</span>
                </label>
                <label class="flex items-center gap-2 p-3 border border-gray-200 dark:border-gray-700 rounded-xl cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                    <input type="radio" name="reasonType" value="Other" class="text-primary focus:ring-primary bg-transparent" onchange="toggleHolidayReasonInput()">
                    <span class="text-sm font-medium text-text-main dark:text-white">Other</span>
                </label>
            </div>
            <input type="text" id="otherHolidayInput" name="other_reason" class="hidden w-full px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 focus:ring-primary focus:border-primary placeholder-gray-400" placeholder="Please specify reason...">
        </div>

        <div class="flex justify-end pt-4">
            <button id="btnBlockDates" type="submit" class="px-6 py-2.5 bg-red-500 hover:bg-red-600 text-white font-bold rounded-xl shadow-lg shadow-red-500/30 transition-all transform hover:scale-[1.02] flex items-center gap-2">
                <span class="material-symbols-outlined">block</span>
                Block Dates
            </button>
        </div>
    </form>
    
    <!-- Holiday Preview Section -->
    <div class="mt-10 border-t border-gray-200 dark:border-gray-700 pt-8">
        <div class="flex flex-col md:flex-row md:items-center justify-between mb-6 gap-4">
            <div>
                <h3 class="text-xl font-bold text-gray-800 dark:text-white">Active Blocked Dates</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Manage scheduled holidays (Grouped by Date).</p>
            </div>
            
            <div class="flex items-center gap-3">
                <select id="holidayRoleFilter" onchange="renderHolidays()" class="py-2 pl-3 pr-8 text-sm border border-gray-200 dark:border-gray-700 rounded-lg focus:ring-2 focus:ring-primary/50 outline-none bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200">
                    <option value="">All Roles</option>
                    <option value="Dentist">Dentist</option>
                    <option value="Receptionist">Receptionist</option>
                </select>

                <!-- Search/Filter Input -->
                <div class="relative">
                    <span class="material-symbols-outlined absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm">search</span>
                    <input type="text" id="holidaySearchInfo" onkeyup="renderHolidays()" placeholder="Search..." 
                           class="pl-9 pr-4 py-2 text-sm border border-gray-200 dark:border-gray-700 rounded-lg focus:ring-2 focus:ring-primary/50 outline-none bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 w-48">
                </div>
                
                <button onclick="loadHolidays()" class="text-primary hover:bg-purple-50 dark:hover:bg-purple-900/20 p-2 rounded-lg transition-colors" title="Refresh List">
                    <span class="material-symbols-outlined text-lg">refresh</span>
                </button>
            </div>
        </div>
        
        <div id="holidayListContainer" class="space-y-6">
            <!-- Dynamic Date Groups will be injected here -->
            <div class="text-center py-12 text-gray-400">Loading schedule...</div>
        </div>
    </div>
</div>
            
<script>
document.addEventListener('DOMContentLoaded', function() {
    let allHolidays = [];

    // Load Holidays
    window.loadHolidays = function() { // Made global for refresh button
        fetch('../api/get_admin_holidays.php')
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    allHolidays = data.holidays;
                    console.log('Fetched Holidays:', allHolidays); // Debugging
                    renderHolidays();
                } else {
                    document.getElementById('holidayListContainer').innerHTML = `<div class="text-center text-red-500 py-8">Error: ${data.message}</div>`;
                }
            })
            .catch(err => console.error('Failed to load holidays:', err));
    }
    
    // Render Groups
    window.renderHolidays = function() {
        const query = document.getElementById('holidaySearchInfo').value.toLowerCase();
        const roleFilter = document.getElementById('holidayRoleFilter').value;
        const container = document.getElementById('holidayListContainer');
        container.innerHTML = '';
        
        if (allHolidays.length === 0) {
            container.innerHTML = `
                <div class="flex flex-col items-center justify-center py-12 text-gray-400 border-2 border-dashed border-gray-200 dark:border-gray-800 rounded-2xl">
                    <span class="material-symbols-outlined text-4xl mb-2 opacity-20">event_busy</span>
                    <p class="text-sm">No scheduled blocks found.</p>
                </div>`;
            return;
        }

        // 1. Group by Date
        const groups = {};
        allHolidays.forEach(h => {
            // Text Filter
            if (query && !h.doctor_name.toLowerCase().includes(query) && !h.reason?.toLowerCase().includes(query)) {
                return;
            }
            
            // Role Filter
            const role = h.user_role || h.role; // Support both just in case
            if (roleFilter && role !== roleFilter) {
                return;
            }
            
            if (!groups[h.block_date]) {
                groups[h.block_date] = {
                    date: h.block_date,
                    formatted: h.formatted_date,
                    items: []
                };
            }
            groups[h.block_date].items.push(h);
        });
        
        const sortedDates = Object.keys(groups).sort();
        
        if (sortedDates.length === 0) {
            container.innerHTML = `<div class="text-center py-8 text-gray-500">No matches found for "${query}"</div>`;
            return;
        }

        // 2. Render Each Group
        sortedDates.forEach(dateKey => {
            const group = groups[dateKey];
            
            const groupDiv = document.createElement('div');
            groupDiv.className = 'bg-white dark:bg-gray-800 rounded-2xl border border-gray-100 dark:border-gray-700 shadow-sm overflow-hidden';
            
            // Header
            groupDiv.innerHTML = `
                <div onclick="toggleDateGroup('${dateKey}')" class="bg-gray-50 dark:bg-gray-900/50 px-6 py-3 border-b border-gray-100 dark:border-gray-800 flex items-center justify-between cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors select-none">
                    <div class="flex items-center gap-3">
                        <span id="icon-${dateKey}" class="material-symbols-outlined text-gray-400 transition-transform duration-200">expand_more</span>
                        <h4 class="font-bold text-gray-800 dark:text-white">${group.formatted}</h4>
                        <span class="bg-gray-200 dark:bg-gray-700 text-gray-600 dark:text-gray-300 text-xs px-2 py-0.5 rounded-full">${group.items.length} Blocks</span>
                    </div>
                    <button onclick="event.stopPropagation(); deleteDate('${group.date}')" class="text-xs font-bold text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-900/20 px-3 py-1.5 rounded-lg transition-colors flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">delete_sweep</span>
                        Clear Day
                    </button>
                </div>
                <div id="group-${dateKey}" class="hidden divide-y divide-gray-50 dark:divide-gray-800 transition-all">
                    ${group.items.map(h => renderRow(h)).join('')}
                </div>
            `;
            
            container.appendChild(groupDiv);
        });
    }

    // Toggle Function
    window.toggleDateGroup = function(dateKey) {
        const content = document.getElementById(`group-${dateKey}`);
        const icon = document.getElementById(`icon-${dateKey}`);
        
        if (content.classList.contains('hidden')) {
            content.classList.remove('hidden');
            icon.style.transform = 'rotate(180deg)';
        } else {
            content.classList.add('hidden');
            icon.style.transform = 'rotate(0deg)';
        }
    }
    
    function renderRow(h) {
        const reasonText = h.reason || 'Blocked';
        const reasonLower = reasonText.toLowerCase();
        
        // Badge
        let badgeClass = 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300';
        if(reasonLower.includes('holiday')) badgeClass = 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-300';
        if(reasonLower.includes('emergency')) badgeClass = 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300';
        
        return `
            <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50 dark:hover:bg-gray-800/50 transition duration-150 group">
                <div class="flex items-center gap-6">
                    <div class="w-48">
                        <div class="flex items-center gap-2">
                             <div class="h-6 w-6 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center text-xs font-bold text-gray-500">
                                ${h.doctor_name.charAt(0)}
                            </div>
                            <span class="text-sm font-medium text-gray-700 dark:text-gray-200">${h.doctor_name}</span>
                            <span class="ml-2 text-[10px] uppercase font-bold text-gray-400 border border-gray-200 dark:border-gray-600 px-1.5 py-0.5 rounded">${h.user_role || h.role || 'N/A'}</span>
                        </div>
                    </div>
                    
                    <div class="w-32">
                        <span class="px-2.5 py-1 rounded-full text-xs font-semibold ${badgeClass}">
                            ${reasonText}
                        </span>
                    </div>
                    
                    <div class="flex items-center gap-1 text-gray-500 dark:text-gray-400 text-sm">
                        <span class="material-symbols-outlined text-sm">schedule</span>
                        ${h.display_time}
                    </div>
                </div>
                
                <button onclick="deleteHoliday(${h.block_id})" class="text-gray-400 hover:text-red-500 transition-colors p-1" title="Remove Individual">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
        `;
    }

    // Individual Delete
    window.deleteHoliday = function(id) {
        Swal.fire({
            title: 'Unblock this slot?',
            text: "This action cannot be undone immediately.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, unblock it!'
        }).then((result) => {
            if (result.isConfirmed) {
                apiDelete({block_id: id});
            }
        });
    };
    
    // Bulk Delete
    window.deleteDate = function(dateStr) {
        Swal.fire({
            title: 'Clear entire day?',
            text: `This will remove ALL blocks for ${dateStr}. Are you sure?`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'Yes, clear all!'
        }).then((result) => {
            if (result.isConfirmed) {
                apiDelete({block_date: dateStr});
            }
        });
    };
    
    function apiDelete(payload) {
        // Show loading
        Swal.fire({
            title: 'Processing...',
            text: 'Please wait',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });

        fetch('../api/delete_holiday.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload)
        })
        .then(r => r.json())
        .then(data => {
            if(data.success) {
                Swal.fire({
                    title: 'Deleted!',
                    text: 'The block has been removed.',
                    icon: 'success',
                    timer: 1500,
                    showConfirmButton: false
                });
                loadHolidays(); // Reload info
            } else {
                Swal.fire('Error', data.message || 'Unknown error', 'error');
            }
        })
        .catch(err => {
             Swal.fire('Error', 'Network error occurred', 'error');
        });
    }

    // Initial Load
    loadHolidays();


    // Day toggle functionality
    const toggles = document.querySelectorAll('.day-toggle');
// ... (skip lines 526-623 to keep context minimal/clean in replace) ...
// ACTUALLY, I can't skip lines easily in Replace unless I make multiple replaces or match a large block.
// I will just replace the button HTML and the JS selector part in two chunks or one large chunk if they were close.
// They are separated by ~100 lines. I must use MultiReplace or separate calls.
// Using Replace for the button HTML first.

    
    toggles.forEach(toggle => {
        toggle.addEventListener('change', function() {
            const day = this.dataset.day;
            const container = document.querySelector(`.time-inputs-${day}`);
            const badge = document.querySelector(`.status-badge-${day}`);
            
            if (this.checked) {
                container.classList.remove('opacity-50', 'pointer-events-none');
                badge.classList.remove('bg-gray-200', 'text-gray-500');
                badge.classList.add('bg-green-100', 'text-green-700');
                badge.textContent = 'OPEN';
            } else {
                container.classList.add('opacity-50', 'pointer-events-none');
                badge.classList.remove('bg-green-100', 'text-green-700');
                badge.classList.add('bg-gray-200', 'text-gray-500');
                badge.textContent = 'CLOSED';
            }
        });
    });
    
    // Appointment fee calculator
    const baseFeeInput = document.querySelector('input[name="appointment_fee"]');
    const overtimeFeeInput = document.querySelector('input[name="appointment_overtime_fee"]');
    const overtimeIntervalInput = document.querySelector('input[name="appointment_overtime_interval_mins"]');
    
    function updateExamples() {
        const baseFee = parseFloat(baseFeeInput.value) || 0;
        const overtimeFee = parseFloat(overtimeFeeInput.value) || 0;
        const interval = parseInt(overtimeIntervalInput.value) || 15;
        
        // 30 min (base only)
        document.getElementById('example-30').textContent = baseFee.toFixed(2);
        
        // 45 min (base + 1 overtime)
        const overtime45 = 45 - 30;
        const increments45 = Math.ceil(overtime45 / interval);
        const total45 = baseFee + (increments45 * overtimeFee);
        document.getElementById('example-45').textContent = total45.toFixed(2);
        
        // 60 min (base + 2 overtime)
        const overtime60 = 60 - 30;
        const increments60 = Math.ceil(overtime60 / interval);
        const total60 = baseFee + (increments60 * overtimeFee);
        document.getElementById('example-60').textContent = total60.toFixed(2);
    }
    
    // Update examples on input change
    baseFeeInput.addEventListener('input', updateExamples);
    overtimeFeeInput.addEventListener('input', updateExamples);
    overtimeIntervalInput.addEventListener('input', updateExamples);
    
    // Initial calculation
    updateExamples();
    
    // --- HOLIDAY FORM LOGIC ---
    const wholeDayCheck = document.getElementById('wholeDayCheck');
    const timeInputs = document.getElementById('holidayTimeInputs');
    const holidayForm = document.getElementById('holidayForm');
    
    // Toggle Time Inputs
    wholeDayCheck.addEventListener('change', function() {
        if(this.checked) {
            timeInputs.classList.add('opacity-50', 'pointer-events-none');
            timeInputs.querySelectorAll('input').forEach(i => i.required = false);
        } else {
            timeInputs.classList.remove('opacity-50', 'pointer-events-none');
            timeInputs.querySelectorAll('input').forEach(i => i.required = true);
        }
    });

    // Default: not checked, so required
    timeInputs.querySelectorAll('input').forEach(i => i.required = true);
    
    // Toggle Other Reason
    window.toggleHolidayReasonInput = function() {
        const otherInput = document.getElementById('otherHolidayInput');
        const selected = document.querySelector('input[name="reasonType"]:checked');
        if (selected && selected.value === 'Other') {
            otherInput.classList.remove('hidden');
            otherInput.required = true;
            otherInput.focus();
        } else {
            otherInput.classList.add('hidden');
            otherInput.required = false;
        }
    };
    
    // Submit Holiday Form
    holidayForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Custom Validation for Reason
        const selectedReason = document.querySelector('input[name="reasonType"]:checked');
        if (!selectedReason) {
            Swal.fire('Error', 'Please select a reason.', 'error');
            return;
        }
        
        const btn = document.getElementById('btnBlockDates');
        const origText = btn.innerHTML;
        btn.innerHTML = '<span class="material-symbols-outlined animate-spin">refresh</span> Processing...';
        btn.disabled = true;
        
        const formData = new FormData(this);
        const data = Object.fromEntries(formData.entries());
        data.is_whole_day = wholeDayCheck.checked; // Ensure boolean
        
        // Handle Reason
        if (data.reasonType === 'Other') {
            data.reason = data.other_reason;
        } else {
            data.reason = data.reasonType;
        }
        
        fetch('../api/set_holiday.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(res => res.json())
        .then(res => {
            if(res.success) {
                let msg = res.message;
                // If there are conflicts, append them
                if (res.details && res.details.length > 0) {
                    msg += '<br><br><div class="text-xs text-left max-h-40 overflow-auto bg-gray-100 p-2 rounded text-red-600 font-mono">' + res.details.join('<br>') + '</div>';
                }
                
                Swal.fire({
                    title: res.details && res.details.length > 0 ? 'Completed with Conflicts' : 'Success!', 
                    html: msg, // Use html instead of text
                    icon: res.details && res.details.length > 0 ? 'warning' : 'success',
                    confirmButtonColor: '#8a11d4'
                });
                this.reset();
                // Reset Reason UI
                document.getElementById('otherHolidayInput').classList.add('hidden');
                timeInputs.classList.remove('opacity-50', 'pointer-events-none');
            } else {
                Swal.fire({
                    title: 'Error',
                    html: res.message + (res.details ? '<br><br><div class="text-xs text-left max-h-40 overflow-auto bg-gray-100 p-2 rounded">' + res.details.join('<br>') + '</div>' : ''),
                    icon: 'error'
                });
            }
        })
        .catch(err => Swal.fire('Error', 'Network error occurred.', 'error'))
        .finally(() => {
            btn.innerHTML = origText;
            btn.disabled = false;
        });
    });
});
</script>
</div>
</div>
</main>
</div>

</body>
</html>
