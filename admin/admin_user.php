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

// Handle user deactivation (set status to Inactive instead of deleting)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deactivate_user'])) {
    $user_id = intval($_POST['user_id']);
    
    // Prevent deactivating yourself
    if ($user_id == $_SESSION['user_id']) {
        $_SESSION['error_message'] = 'You cannot deactivate your own account.';
    } else {
        try {
            $update_stmt = $pdo->prepare("UPDATE tbl_user SET status = 'Inactive' WHERE user_id = ?");
            $update_stmt->execute([$user_id]);
            $_SESSION['success_message'] = 'User account has been deactivated successfully.';
        } catch (PDOException $e) {
            error_log('Error deactivating user: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Error deactivating user. Please try again.';
        }
    }
    
    header('Location: admin_user.php');
    exit;
}

// Get success/error messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);

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

// Get filter parameters
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query
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
    $where_conditions[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR user_id LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_query;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_user $where_clause");
$count_stmt->execute($params);
$total_users = $count_stmt->fetchColumn();

// Get users with pagination (LIMIT and OFFSET must be integers, not parameters)
$query = "SELECT user_id, username, full_name, role, email, contact_number, status, profile_picture, created_at, last_active FROM tbl_user $where_clause ORDER BY user_id DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

$total_pages = ceil($total_users / $per_page);
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>User Management - Vanessa Nicolas Dental Clinic</title>
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
                        "primary": "#8a11d4", // Royal Purple
                        "accent": "#FFD700", // Gold
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
    <script>
        // Define functions immediately so they're available for onclick handlers
        window.editUser = function(userId) {
            window.location.href = 'admin_edit_user.php?id=' + userId;
        };
        
        window.deleteUser = async function(userId, userName) {
            const confirmed = await window.showConfirm(
                'Are you sure you want to deactivate user "' + userName + '"? The account will be set to Inactive status and will no longer be accessible.',
                'Deactivate User'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'admin_user.php';
                
                const userIdInput = document.createElement('input');
                userIdInput.type = 'hidden';
                userIdInput.name = 'user_id';
                userIdInput.value = userId;
                
                const deactivateInput = document.createElement('input');
                deactivateInput.type = 'hidden';
                deactivateInput.name = 'deactivate_user';
                deactivateInput.value = '1';
                
                form.appendChild(userIdInput);
                form.appendChild(deactivateInput);
                document.body.appendChild(form);
                form.submit();
            }
        };
        
        // Export dropdown toggle function
        window.toggleExportMenu = function() {
            const menu = document.getElementById('exportMenu');
            if (menu) {
                if (menu.style.display === 'none' || menu.style.display === '') {
                    menu.style.display = 'block';
                    menu.style.visibility = 'visible';
                    menu.style.opacity = '1';
                } else {
                    menu.style.display = 'none';
                }
            }
        };
        
        // Close export menu when clicking outside
        document.addEventListener('DOMContentLoaded', function() {
            document.addEventListener('click', function(e) {
                const menu = document.getElementById('exportMenu');
                const dropdown = document.getElementById('exportDropdown');
                if (menu && dropdown && menu.style.display === 'block') {
                    if (!dropdown.contains(e.target)) {
                        menu.style.display = 'none';
                    }
                }
            });
        });
    </script>
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
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary transition-all" href="admin_user.php">
<span class="material-symbols-outlined fill-1">group</span>
<p class="text-sm font-bold leading-normal">Users</p>
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
<h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Employee Management</h2>
</div>
<div class="flex items-center gap-6">
<form method="GET" action="admin_user.php" class="flex items-center w-80 h-10 bg-[#f3f0f4] dark:bg-gray-800 rounded-xl px-3 gap-2 group focus-within:ring-2 ring-primary/50 transition-all">
<span class="material-symbols-outlined text-text-sub">search</span>
<input type="hidden" name="role" value="<?php echo htmlspecialchars($role_filter); ?>">
<input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>">
<input class="w-full bg-transparent border-none text-sm text-text-main dark:text-white placeholder-text-sub focus:ring-0 p-0" name="search" placeholder="Search user by name, ID or role..." value="<?php echo htmlspecialchars($search_query); ?>"/>
</form>
<div class="flex gap-3">
<button class="btn-notifications relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
<span class="material-symbols-outlined text-[22px]">notifications</span>
<span class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700"></span>
</button>
</div>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8 scroll-smooth">
<div class="max-w-[1400px] mx-auto flex flex-col gap-6 pb-10 h-full">
<?php if ($success_message): ?>
<div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-300 px-4 py-3 rounded-xl flex items-center gap-2">
<span class="material-symbols-outlined">check_circle</span>
<span><?php echo htmlspecialchars($success_message); ?></span>
</div>
<?php endif; ?>
<?php if ($error_message): ?>
<div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-800 dark:text-red-300 px-4 py-3 rounded-xl flex items-center gap-2">
<span class="material-symbols-outlined">error</span>
<span><?php echo htmlspecialchars($error_message); ?></span>
</div>
<?php endif; ?>
<div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-4 bg-surface-light dark:bg-surface-dark p-4 rounded-2xl border border-gray-100 dark:border-gray-800 shadow-sm">
<form method="GET" action="admin_user.php" class="flex items-center gap-3 w-full md:w-auto overflow-x-auto pb-2 md:pb-0">
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
<form method="GET" action="admin_user.php" class="flex items-center gap-3 w-full md:w-auto">
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
<div class="flex gap-3 w-full md:w-auto">
<div class="relative z-50" id="exportDropdown" style="position: relative;">
<button type="button" id="exportButton" onclick="window.toggleExportMenu(); event.stopPropagation();" class="flex items-center justify-center gap-2 px-4 py-2 rounded-xl border border-gray-200 dark:border-gray-700 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-800 font-bold text-sm transition-colors flex-1 md:flex-none">
<span class="material-symbols-outlined text-[20px]">download</span>
                            Export
<span class="material-symbols-outlined text-[16px]">arrow_drop_down</span>
                        </button>
<div id="exportMenu" style="display: none;" class="absolute right-0 top-full mt-2 w-48 bg-surface-light dark:bg-surface-dark border border-gray-200 dark:border-gray-700 rounded-xl shadow-xl z-[9999] min-w-[12rem]">
<a href="export_users.php?format=excel&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors first:rounded-t-xl">
<span class="material-symbols-outlined text-[20px] text-green-600">table_chart</span>
<span class="text-sm font-medium text-text-main dark:text-white">Export to Excel</span>
</a>
<a href="export_users.php?format=pdf&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
<span class="material-symbols-outlined text-[20px] text-red-600">picture_as_pdf</span>
<span class="text-sm font-medium text-text-main dark:text-white">Export to PDF</span>
</a>
<a href="export_users.php?format=word&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="flex items-center gap-3 px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors last:rounded-b-xl">
<span class="material-symbols-outlined text-[20px] text-blue-600">description</span>
<span class="text-sm font-medium text-text-main dark:text-white">Export to Word</span>
</a>
</div>
</div>
<a href="admin_registration_user.php" class="flex items-center justify-center gap-2 px-6 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm shadow-lg shadow-primary/20 transition-all flex-1 md:flex-none">
<span class="material-symbols-outlined text-[20px]">add</span>
                            Add New User
                        </a>
</div>
</div>
<div class="flex-1 bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col overflow-hidden">
<div class="overflow-x-auto flex-1">
<table class="w-full text-left border-collapse">
<thead>
<tr class="bg-[#f9f8fa] dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
<th class="p-4 w-12 text-center">
<input class="rounded border-gray-300 text-primary focus:ring-primary/50 dark:bg-gray-700 dark:border-gray-600" type="checkbox" id="selectAll"/>
</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">User Profile</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Role</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Contact Info</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Last Active</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Status</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider text-right">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-gray-100 dark:divide-gray-800">
<?php if (empty($users)): ?>
<tr>
<td colspan="7" class="p-8 text-center text-text-sub">
    <span class="material-symbols-outlined text-4xl mb-2 block">person_off</span>
    <p class="text-sm">No users found</p>
</td>
</tr>
<?php else: ?>
<?php foreach ($users as $user): 
    $default_photo = getDefaultProfilePhoto($user['full_name']);
    $role_colors = [
        'Dentist' => ['bg' => 'bg-blue-50 text-blue-700 dark:bg-blue-900/20 dark:text-blue-300', 'icon' => 'dentistry'],
        'Dental Assistant' => ['bg' => 'bg-purple-50 text-purple-700 dark:bg-purple-900/20 dark:text-purple-300', 'icon' => 'clean_hands'],
        'Receptionist' => ['bg' => 'bg-teal-50 text-teal-700 dark:bg-teal-900/20 dark:text-teal-300', 'icon' => 'support_agent'],
        'Admin' => ['bg' => 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300', 'icon' => 'admin_panel_settings'],
    ];
    $role_style = $role_colors[$user['role']] ?? $role_colors['Receptionist'];
    // Determine if user is currently active (online) based on last_active timestamp
    $is_currently_active = isUserActive($user['last_active'] ?? null);
    $display_status = $is_currently_active ? 'Online' : ($user['status'] === 'Active' ? 'Away' : 'Inactive');
    
    $status_colors = [
        'Online' => ['bg' => 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400', 'dot' => 'bg-green-500', 'pulse' => 'animate-pulse'],
        'Away' => ['bg' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400', 'dot' => 'bg-yellow-500', 'pulse' => ''],
        'Inactive' => ['bg' => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400', 'dot' => 'bg-gray-400', 'pulse' => ''],
    ];
    $status_style = $status_colors[$display_status] ?? $status_colors['Inactive'];
    $emp_id = 'EMP-' . str_pad($user['user_id'], 3, '0', STR_PAD_LEFT);
?>
<tr class="hover:bg-gray-50 dark:hover:bg-gray-800/30 transition-colors group">
<td class="p-4 text-center">
<input class="rounded border-gray-300 text-primary focus:ring-primary/50 dark:bg-gray-700 dark:border-gray-600 user-checkbox" type="checkbox" value="<?php echo $user['user_id']; ?>"/>
</td>
<td class="p-4">
<div class="flex items-center gap-3">
<?php if ($user['profile_picture'] && file_exists('../' . $user['profile_picture'])): ?>
<div class="w-10 h-10 rounded-full bg-cover bg-center ring-2 ring-white dark:ring-gray-800 shadow-sm" style="background-image: url('<?php echo htmlspecialchars('../' . $user['profile_picture']); ?>');"></div>
<?php else: ?>
<div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-bold ring-2 ring-white dark:ring-gray-800 shadow-sm" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;">
<?php echo htmlspecialchars($default_photo['initials']); ?>
</div>
<?php endif; ?>
<div>
<p class="text-sm font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($user['full_name']); ?></p>
<p class="text-xs text-text-sub font-mono"><?php echo $emp_id; ?></p>
</div>
</div>
</td>
<td class="p-4">
<div class="flex items-center gap-1.5 px-2.5 py-1 w-fit rounded-lg <?php echo $role_style['bg']; ?>">
<span class="material-symbols-outlined text-[16px]"><?php echo $role_style['icon']; ?></span>
<span class="text-xs font-bold"><?php echo htmlspecialchars($user['role']); ?></span>
</div>
</td>
<td class="p-4">
<div class="flex flex-col gap-0.5">
<span class="text-sm text-text-main dark:text-gray-300"><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></span>
<span class="text-xs text-text-sub"><?php echo htmlspecialchars($user['contact_number'] ?? 'N/A'); ?></span>
</div>
</td>
<td class="p-4">
<span class="text-sm text-text-sub"><?php 
if ($user['created_at']) {
    $date = new DateTime($user['created_at']);
    $now = new DateTime();
    $diff = $now->diff($date);
    if ($diff->days == 0) {
        echo 'Today, ' . $date->format('h:i A');
    } elseif ($diff->days == 1) {
        echo 'Yesterday, ' . $date->format('h:i A');
    } elseif ($diff->days < 7) {
        echo $diff->days . ' days ago';
    } else {
        echo $date->format('M d, Y');
    }
} else {
    echo 'N/A';
}
?></span>
</td>
<td class="p-4" data-account-status="<?php echo htmlspecialchars($user['status']); ?>">
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold <?php echo $status_style['bg']; ?>" data-user-id="<?php echo $user['user_id']; ?>" data-last-active="<?php echo htmlspecialchars($user['last_active'] ?? ''); ?>">
<span class="w-1.5 h-1.5 rounded-full <?php echo $status_style['dot']; ?> <?php echo $status_style['pulse']; ?>"></span> <span class="status-text"><?php echo htmlspecialchars($display_status); ?></span>
                                        </span>
</td>
<td class="p-4 text-right">
<div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                        <?php if (!empty($user['email'])): ?>
                                        <button class="p-2 rounded-lg text-text-sub hover:bg-accent/20 hover:text-accent transition-colors" title="Send Password Reset Link" onclick="window.sendPasswordReset(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'])); ?>', this)">
                                            <span class="material-symbols-outlined text-[20px]">lock_reset</span>
                                        </button>
                                        <?php endif; ?>
                                        <button class="p-2 rounded-lg text-text-sub hover:bg-primary/10 hover:text-primary transition-colors edit-user-btn" title="Edit User" onclick="window.editUser(<?php echo $user['user_id']; ?>)">
                                            <span class="material-symbols-outlined text-[20px]">edit</span>
                                        </button>
<button class="p-2 rounded-lg text-text-sub hover:bg-red-50 hover:text-red-500 transition-colors delete-user-btn" title="Delete User" onclick="window.deleteUser(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars(addslashes($user['full_name'])); ?>')">
<span class="material-symbols-outlined text-[20px]">delete</span>
</button>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<div class="border-t border-gray-100 dark:border-gray-800 p-4 flex items-center justify-between">
<div class="text-sm text-text-sub">
                            Showing <span class="font-bold text-text-main dark:text-white"><?php echo $offset + 1; ?></span> to <span class="font-bold text-text-main dark:text-white"><?php echo min($offset + $per_page, $total_users); ?></span> of <span class="font-bold text-text-main dark:text-white"><?php echo $total_users; ?></span> users
                        </div>
<div class="flex gap-2">
<?php if ($page > 1): ?>
<a href="?page=<?php echo $page - 1; ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-text-main dark:text-white text-sm hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                Previous
                            </a>
<?php else: ?>
<button class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-text-sub text-sm hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 transition-colors" disabled>
                                Previous
                            </button>
<?php endif; ?>
<?php if ($page < $total_pages): ?>
<a href="?page=<?php echo $page + 1; ?>&role=<?php echo urlencode($role_filter); ?>&status=<?php echo urlencode($status_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-text-main dark:text-white text-sm hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                                Next
                            </a>
<?php else: ?>
<button class="px-3 py-1.5 rounded-lg border border-gray-200 dark:border-gray-700 text-text-sub text-sm hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 transition-colors" disabled>
                                Next
                            </button>
<?php endif; ?>
</div>
</div>
</div>
</div>
</div>
</main>
</div>

<script>
// Initialize all functionality when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    // Select all checkbox
    document.getElementById('selectAll')?.addEventListener('change', function() {
        const checkboxes = document.querySelectorAll('.user-checkbox');
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
    
    // Edit User Redirect
    window.editUser = function(userId) {
        window.location.href = `admin_edit_user.php?id=${userId}`;
    };

    // Send Password Reset Link
    window.sendPasswordReset = function(userId, userName, btnElement) {
        window.showConfirm(`Send password reset link to ${userName}?`, 'Confirm Reset')
            .then(result => {
                if (result) {
                    // Use passed button element
                    const btn = btnElement.closest('button');
                    const originalContent = btn.innerHTML;
                    btn.innerHTML = '<span class="material-symbols-outlined animate-spin text-[20px]">progress_activity</span>';
                    btn.disabled = true;

                    const formData = new FormData();
                    formData.append('user_id', userId);

                    fetch('admin_send_password_reset.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            window.showSuccess(data.message);
                        } else {
                            window.showError(data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        window.showError('An unexpected error occurred.');
                    })
                    .finally(() => {
                        btn.innerHTML = originalContent;
                        btn.disabled = false;
                    });
                }
            });
    };
    
});

// Real-time activity tracking
(function() {
    // Update current user's activity every 2 minutes
    function updateActivity() {
        fetch('../api/update_activity.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    console.log('Activity updated:', data.timestamp);
                }
            })
            .catch(error => console.error('Activity update failed:', error));
    }
    
    // Update activity immediately and then every 2 minutes
    updateActivity();
    setInterval(updateActivity, 120000); // 2 minutes
    
    // Update status indicators by fetching fresh data
    function updateStatusIndicators() {
        fetch('../api/get_users_status.php')
            .then(response => response.json())
            .then(data => {
                if (!data.success) return;
                
                const statusStyles = {
                    'Online': { bg: 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400', dot: 'bg-green-500', pulse: 'animate-pulse' },
                    'Away': { bg: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400', dot: 'bg-yellow-500', pulse: '' },
                    'Inactive': { bg: 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400', dot: 'bg-gray-400', pulse: '' }
                };
                
                // Find all status status elements
                const statusElements = document.querySelectorAll('span[data-user-id]');
                
                statusElements.forEach(element => {
                    const userId = element.getAttribute('data-user-id');
                    if (!userId || !data.users[userId]) return;
                    
                    const userInfo = data.users[userId];
                    let newStatus = 'Inactive';
                    
                    if (userInfo.is_online) {
                        newStatus = 'Online';
                    } else if (userInfo.status === 'Active') {
                        newStatus = 'Away';
                    } else {
                        newStatus = 'Inactive';
                    }
                    
                    const style = statusStyles[newStatus];
                    const textSpan = element.querySelector('.status-text');
                    const dotSpan = element.querySelector('span:first-child');
                    
                    // Update only if changed to prevent flickering
                    if (textSpan && textSpan.textContent !== newStatus) {
                        element.className = `inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold ${style.bg}`;
                        if (dotSpan) {
                            dotSpan.className = `w-1.5 h-1.5 rounded-full ${style.dot} ${style.pulse}`;
                        }
                        textSpan.textContent = newStatus;
                    }
                });
            })
            .catch(error => console.error('Status update failed:', error));
    }
    
    // Update status indicators every 30 seconds
    updateStatusIndicators();
    setInterval(updateStatusIndicators, 10000); // 10 seconds
    
    // Also update on page visibility change (when user comes back to tab)
    document.addEventListener('visibilitychange', function() {
        if (!document.hidden) {
            updateActivity();
            updateStatusIndicators();
        }
    });
    
    // Update on mouse movement (user is active)
    let activityTimeout;
    document.addEventListener('mousemove', function() {
        clearTimeout(activityTimeout);
        activityTimeout = setTimeout(updateActivity, 60000); // Update after 1 minute of activity
    });
})();
</script>

<!-- Custom Popup Modal -->
<div id="customPopup" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 max-w-md w-[90%] shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="popupContent">
        <div class="flex items-center gap-4 mb-4">
            <div id="popupIcon" class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-2xl"></span>
            </div>
            <h3 id="popupTitle" class="text-lg font-bold text-text-main dark:text-white flex-1"></h3>
        </div>
        <p id="popupMessage" class="text-text-sub dark:text-gray-300 mb-6 whitespace-pre-line"></p>
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
            
            // Set icon and colors based on type
            const iconMap = {
                'alert': { icon: 'info', color: 'bg-blue-100 text-blue-600 dark:bg-blue-900/20 dark:text-blue-400' },
                'confirm': { icon: 'help', color: 'bg-yellow-100 text-yellow-600 dark:bg-yellow-900/20 dark:text-yellow-400' },
                'success': { icon: 'check_circle', color: 'bg-green-100 text-green-600 dark:bg-green-900/20 dark:text-green-400' },
                'error': { icon: 'error', color: 'bg-red-100 text-red-600 dark:bg-red-900/20 dark:text-red-400' }
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
                cancelBtn.className = 'px-4 py-2 rounded-xl bg-gray-100 dark:bg-gray-800 text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700 font-bold text-sm transition-all';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.onclick = () => closePopup(false);
                
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm transition-all';
                okBtn.textContent = 'Confirm';
                okBtn.onclick = () => closePopup(true);
                
                popupButtons.appendChild(cancelBtn);
                popupButtons.appendChild(okBtn);
            } else {
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm transition-all';
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

    <script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>
