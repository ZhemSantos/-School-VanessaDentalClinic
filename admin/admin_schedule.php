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

// Get filters - same as admin_user.php
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query for employees with schedules
$where_conditions = [];
$params = [];

if ($role_filter !== 'all') {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Fetch employees with their schedules
$employees = [];
try {
    // Get all employees matching the filters (same query structure as admin_user.php)
    // When all filters are 'all', this should return ALL users from tbl_user
    // Use DISTINCT to ensure no duplicate user_ids are returned
    $query = "SELECT DISTINCT user_id, full_name, role, status, profile_picture, email, contact_number, last_active FROM tbl_user $where_clause ORDER BY role, full_name";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    
    // Fetch all employees and use user_id as array key to prevent duplicates
    // This ensures each user_id appears only once, even if the query somehow returns duplicates
    $employees_by_id = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $user_id = (int)$row['user_id'];
        // Only add if we haven't seen this user_id before (double safety check)
        if (!isset($employees_by_id[$user_id])) {
            $employees_by_id[$user_id] = $row;
        }
    }
    
    // Convert to indexed array for iteration
    $employees = array_values($employees_by_id);
    
    // Fetch schedules for each employee (using index to avoid reference issues)
    foreach ($employees as $index => $employee) {
        $schedule_stmt = $pdo->prepare("
            SELECT day_of_week, start_time, end_time 
            FROM tbl_doctor_schedule 
            WHERE doctor_id = ? 
            ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
        ");
        $schedule_stmt->execute([$employee['user_id']]);
        $employees[$index]['schedules'] = $schedule_stmt->fetchAll();
    }
} catch (PDOException $e) {
    error_log('Error fetching employees: ' . $e->getMessage());
}

$days_of_week = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Employee Schedules - Vanessa Nicolas Dental Clinic</title>
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
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary transition-all" href="admin_schedule.php">
<span class="material-symbols-outlined fill-1">schedule</span>
<p class="text-sm font-bold leading-normal">Schedules</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_service.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">medical_services</span>
<p class="text-sm font-medium leading-normal">Services</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_reports.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">description</span>
<p class="text-sm font-medium leading-normal">Reports</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl text-text-main dark:text-gray-300 hover:bg-[#f3f0f4] dark:hover:bg-gray-700/50 transition-all group" href="admin_settings.php">
<span class="material-symbols-outlined group-hover:text-primary transition-colors">settings</span>
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
<h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Employee Schedules</h2>
</div>
<div class="flex items-center gap-6">
<div class="flex gap-3">
<button class="btn-notifications relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
<span class="material-symbols-outlined text-[22px]">notifications</span>
<span class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700"></span>
</button>
</div>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8 scroll-smooth">
<div class="max-w-[1400px] mx-auto flex flex-col gap-6">
<!-- Filters -->
<div class="bg-surface-light dark:bg-surface-dark p-4 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm">
<div class="flex items-center gap-4 flex-wrap">
<form method="GET" action="admin_schedule.php" class="flex items-center gap-3">
<input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
<input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
<div class="flex items-center gap-2 px-3 py-2 bg-[#f3f0f4] dark:bg-gray-800 rounded-lg min-w-[140px]">
<span class="material-symbols-outlined text-text-sub text-[20px]">filter_alt</span>
<select name="role" class="bg-transparent border-none text-sm font-medium text-text-main dark:text-white p-0 pr-8 focus:ring-0 cursor-pointer" onchange="this.form.submit()">
<option value="all" <?php echo $role_filter === 'all' ? 'selected' : ''; ?>>All Roles</option>
<option value="Dentist" <?php echo $role_filter === 'Dentist' ? 'selected' : ''; ?>>Dentist</option>
<option value="Dental Assistant" <?php echo $role_filter === 'Dental Assistant' ? 'selected' : ''; ?>>Dental Assistant</option>
<option value="Receptionist" <?php echo $role_filter === 'Receptionist' ? 'selected' : ''; ?>>Receptionist</option>
<option value="Admin" <?php echo $role_filter === 'Admin' ? 'selected' : ''; ?>>Admin</option>
</select>
</div>
</form>
<form method="GET" action="admin_schedule.php" class="flex items-center gap-3">
<input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
<input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>">
<div class="flex items-center gap-2 px-3 py-2 bg-[#f3f0f4] dark:bg-gray-800 rounded-lg min-w-[140px]">
<span class="material-symbols-outlined text-text-sub text-[20px]">toggle_on</span>
<select name="status" class="bg-transparent border-none text-sm font-medium text-text-main dark:text-white p-0 pr-8 focus:ring-0 cursor-pointer" onchange="this.form.submit()">
<option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Status</option>
<option value="Active" <?php echo $status_filter === 'Active' ? 'selected' : ''; ?>>Active</option>
<option value="Inactive" <?php echo $status_filter === 'Inactive' ? 'selected' : ''; ?>>Inactive</option>
</select>
</div>
</form>
<form method="GET" action="admin_schedule.php" class="flex items-center gap-3 flex-1 min-w-[300px]">
<input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>">
<input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
<div class="flex items-center w-full h-10 bg-[#f3f0f4] dark:bg-gray-800 rounded-xl px-3 gap-2 group focus-within:ring-2 ring-primary/50 transition-all">
<span class="material-symbols-outlined text-text-sub">search</span>
<input class="w-full bg-transparent border-none text-sm text-text-main dark:text-white placeholder-text-sub focus:ring-0 p-0" name="search" placeholder="Search employee..." value="<?php echo htmlspecialchars($search_query); ?>"/>
</div>
<button type="submit" class="px-4 py-2 bg-primary text-white rounded-xl hover:bg-primary/90 font-bold text-sm transition-all whitespace-nowrap">
Search
</button>
</form>
</div>
</div>
<!-- Schedule Cards -->
<div class="grid gap-6">
<?php if (empty($employees)): ?>
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-12 text-center border border-gray-100 dark:border-gray-800">
<span class="material-symbols-outlined text-6xl text-text-sub mb-4 block">schedule</span>
<p class="text-text-sub text-lg">No employees found</p>
</div>
<?php else: ?>
<?php foreach ($employees as $employee): 
    $emp_default_photo = getDefaultProfilePhoto($employee['full_name']);
    $role_colors = [
        'Dentist' => ['bg' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300', 'icon' => 'dentistry'],
        'Dental Assistant' => ['bg' => 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300', 'icon' => 'clean_hands'],
        'Receptionist' => ['bg' => 'bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-300', 'icon' => 'support_agent'],
        'Admin' => ['bg' => 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300', 'icon' => 'admin_panel_settings'],
    ];
    $role_style = $role_colors[$employee['role']] ?? $role_colors['Receptionist'];
    
    // Determine status using same logic as admin_user.php
    $is_currently_active = isUserActive($employee['last_active'] ?? null);
    $display_status = $is_currently_active ? 'Online' : ($employee['status'] === 'Active' ? 'Away' : 'Inactive');
    
    $status_colors = [
        'Online' => ['bg' => 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400', 'dot' => 'bg-green-500', 'pulse' => 'animate-pulse'],
        'Away' => ['bg' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400', 'dot' => 'bg-yellow-500', 'pulse' => ''],
        'Inactive' => ['bg' => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400', 'dot' => 'bg-gray-400', 'pulse' => ''],
    ];
    $status_style = $status_colors[$display_status] ?? $status_colors['Inactive'];
?>
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 border border-gray-100 dark:border-gray-800 shadow-sm">
<div class="flex items-center gap-4 mb-6 pb-4 border-b border-gray-100 dark:border-gray-800">
<?php if ($employee['profile_picture'] && file_exists('../' . $employee['profile_picture'])): ?>
<div class="w-14 h-14 rounded-full bg-cover bg-center ring-2 ring-white dark:ring-gray-800 shadow-sm" style="background-image: url('<?php echo htmlspecialchars('../' . $employee['profile_picture']); ?>');"></div>
<?php else: ?>
<div class="w-14 h-14 rounded-full flex items-center justify-center text-white font-bold ring-2 ring-white dark:ring-gray-800 shadow-sm" style="background-color: <?php echo $emp_default_photo['bg_color']; ?>; color: <?php echo $emp_default_photo['text_color']; ?>;">
<?php echo htmlspecialchars($emp_default_photo['initials']); ?>
</div>
<?php endif; ?>
<div class="flex-1">
<h3 class="text-lg font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($employee['full_name']); ?></h3>
<div class="flex items-center gap-2 mt-1">
<div class="flex items-center gap-1.5 px-2.5 py-1 w-fit rounded-lg <?php echo $role_style['bg']; ?>">
<span class="material-symbols-outlined text-[14px]"><?php echo $role_style['icon']; ?></span>
<span class="text-xs font-bold"><?php echo htmlspecialchars($employee['role']); ?></span>
</div>
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold <?php echo $status_style['bg']; ?>" data-last-active="<?php echo htmlspecialchars($employee['last_active'] ?? ''); ?>" data-account-status="<?php echo htmlspecialchars($employee['status']); ?>" data-user-id="<?php echo $employee['user_id']; ?>">
<span class="w-1.5 h-1.5 rounded-full <?php echo $status_style['dot']; ?> <?php echo $status_style['pulse']; ?>"></span>
<span class="status-text"><?php echo htmlspecialchars($display_status); ?></span>
</span>
</div>
</div>
<a href="admin_edit_schedule.php?id=<?php echo $employee['user_id']; ?>" class="px-4 py-2 bg-primary/10 text-primary rounded-xl hover:bg-primary/20 font-bold text-sm transition-all">
Edit Schedule
</a>
</div>
<!-- Schedule Grid -->
<div class="grid grid-cols-7 gap-2">
<?php foreach ($days_of_week as $day): 
    $schedule = null;
    foreach ($employee['schedules'] as $sched) {
        if ($sched['day_of_week'] === $day) {
            $schedule = $sched;
            break;
        }
    }
?>
<div class="p-3 rounded-lg border border-gray-200 dark:border-gray-700 <?php echo $schedule ? 'bg-primary/5 border-primary/20' : 'bg-gray-50 dark:bg-gray-800/50'; ?>">
<div class="text-xs font-bold text-text-sub mb-2"><?php echo substr($day, 0, 3); ?></div>
<?php if ($schedule): ?>
<div class="text-xs font-medium text-primary">
<?php echo date('g:i A', strtotime($schedule['start_time'])); ?> - <?php echo date('g:i A', strtotime($schedule['end_time'])); ?>
</div>
<?php else: ?>
<div class="text-xs text-text-sub">Off</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</div>
</main>
</div>
<script>
// Real-time status updates (same as admin_user.php)
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
                    // Default styles (Inactive)
                    let newBg = 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
                    let newDot = 'bg-gray-400';
                    let newPulse = '';
                    
                   if (userInfo.is_online) {
                        newStatus = 'Online';
                        newBg = 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400';
                        newDot = 'bg-green-500';
                        newPulse = 'animate-pulse';
                    } else if (userInfo.status === 'Active') {
                        newStatus = 'Away';
                        newBg = 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400';
                        newDot = 'bg-yellow-500';
                        newPulse = '';
                    } else {
                        newStatus = 'Inactive';
                        newBg = 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400';
                        newDot = 'bg-gray-400';
                        newPulse = '';
                    }
                    
                    // Update only if changed
                    const statusText = element.querySelector('.status-text');
                    if (statusText && statusText.textContent.trim() !== newStatus) {
                        element.className = 'inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ' + newBg;
                        
                        const dot = element.querySelector('span:first-child');
                        if (dot) {
                             dot.className = 'w-1.5 h-1.5 rounded-full ' + newDot + ' ' + newPulse;
                        }
                        statusText.textContent = newStatus;
                    }
                });
            })
            .catch(e => console.error(e));
    }
    
    // Initial call and interval
    setInterval(updateStatusIndicators, 10000); // 10 seconds
})();
</script>
    <script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body>
</html>

