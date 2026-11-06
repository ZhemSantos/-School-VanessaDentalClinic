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
        $user_details_stmt = $pdo->prepare("SELECT user_id, username, full_name, role, email, contact_number, blood_type, status, profile_picture, created_at, last_active FROM tbl_user WHERE user_id = ? LIMIT 1");
        $user_details_stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $user_details_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching user details: ' . $e->getMessage());
        header('Location: admin_dashboard.php?error=profile_error');
        exit;
    }
}

if (!$current_user_details) {
    header('Location: admin_dashboard.php?error=user_not_found');
    exit;
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

$user = $current_user_details;
$default_photo = getDefaultProfilePhoto($user['full_name']);
$is_online = isUserActive($user['last_active'] ?? null);
$display_status = $is_online ? 'Online' : ($user['status'] === 'Active' ? 'Away' : 'Inactive');
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>My Profile - Vanessa Nicolas Dental Clinic</title>
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
</head>
<body class="bg-background-light dark:bg-background-dark text-text-main dark:text-white font-display overflow-hidden">
<div class="flex h-screen w-full">
<aside class="w-72 flex-shrink-0 flex flex-col bg-surface-light dark:bg-surface-dark border-r border-[#f3f0f4] dark:border-gray-800 transition-colors duration-200">
<div class="flex flex-col h-full p-4">
<div class="flex gap-3 mb-8 items-center p-2">
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
</div>
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
<h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">My Profile</h2>
</div>
<div class="flex items-center gap-6">
    <a href="admin_edit_user.php?id=<?php echo $user['user_id']; ?>" class="flex items-center gap-2 px-4 py-2 bg-primary/10 hover:bg-primary/20 text-primary rounded-xl font-bold transition-colors text-sm">
        <span class="material-symbols-outlined text-[20px]">edit</span>
        <span>Edit</span>
    </a>
    <div class="flex gap-3">
<button class="btn-notifications relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
<span class="material-symbols-outlined text-[22px]">notifications</span>
<span class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700"></span>
</button>
</div>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8 scroll-smooth">
<div class="max-w-[1000px] mx-auto flex flex-col gap-8 pb-10">
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-8 shadow-sm border border-gray-100 dark:border-gray-800">
<div class="flex flex-col items-center mb-8 pb-8 border-b border-gray-200 dark:border-gray-700">
<?php
if ($user['profile_picture'] && file_exists('../' . $user['profile_picture'])) {
    echo '<div class="w-32 h-32 rounded-full bg-cover bg-center border-4 border-primary mb-4 shadow-lg" style="background-image: url(\'../' . htmlspecialchars($user['profile_picture']) . '\');"></div>';
} else {
    echo '<div class="w-32 h-32 rounded-full border-4 border-primary flex items-center justify-center text-white font-bold text-4xl mb-4 shadow-lg" style="background-color: ' . $default_photo['bg_color'] . '; color: ' . $default_photo['text_color'] . ';">' . htmlspecialchars($default_photo['initials']) . '</div>';
}
?>
<h3 class="text-2xl font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($user['full_name']); ?></h3>
<p class="text-sm text-primary font-semibold uppercase tracking-wider mt-2"><?php echo htmlspecialchars($user['role']); ?></p>
<p class="text-xs text-text-sub mt-2">EMP-<?php echo str_pad($user['user_id'], 3, '0', STR_PAD_LEFT); ?></p>
<div class="mt-4">
<?php
$status_colors = [
    'Online' => ['bg' => 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400', 'dot' => 'bg-green-500', 'pulse' => 'animate-pulse'],
    'Away' => ['bg' => 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/20 dark:text-yellow-400', 'dot' => 'bg-yellow-500', 'pulse' => ''],
    'Inactive' => ['bg' => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400', 'dot' => 'bg-gray-400', 'pulse' => ''],
];
$status_style = $status_colors[$display_status] ?? $status_colors['Inactive'];
?>
<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-sm font-bold <?php echo $status_style['bg']; ?>">
<span class="w-2 h-2 rounded-full <?php echo $status_style['dot']; ?> <?php echo $status_style['pulse']; ?>"></span>
<?php echo htmlspecialchars($display_status); ?>
</span>
</div>
</div>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
<div>
<label class="text-xs font-bold text-text-sub uppercase">Username</label>
<p class="text-sm text-text-main dark:text-white mt-2 font-medium"><?php echo htmlspecialchars($user['username']); ?></p>
</div>
<?php if (!empty($user['email'])): ?>
<div>
<label class="text-xs font-bold text-text-sub uppercase">Email Address</label>
<p class="text-sm text-text-main dark:text-white mt-2 font-medium"><?php echo htmlspecialchars($user['email']); ?></p>
</div>
<?php endif; ?>
<?php if (!empty($user['contact_number'])): ?>
<div>
<label class="text-xs font-bold text-text-sub uppercase">Contact Number</label>
<p class="text-sm text-text-main dark:text-white mt-2 font-medium"><?php echo htmlspecialchars($user['contact_number']); ?></p>
</div>
<?php endif; ?>
<?php if (!empty($user['blood_type']) && $user['blood_type'] !== 'Unknown'): ?>
<div>
<label class="text-xs font-bold text-text-sub uppercase">Blood Type</label>
<p class="text-sm text-text-main dark:text-white mt-2 font-medium"><?php echo htmlspecialchars($user['blood_type']); ?></p>
</div>
<?php endif; ?>
<div>
<label class="text-xs font-bold text-text-sub uppercase">Account Status</label>
<p class="text-sm mt-2">
<span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-bold <?php echo $user['status'] === 'Active' ? 'bg-green-100 text-green-700 dark:bg-green-900/20 dark:text-green-400' : 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'; ?>">
<span class="w-1.5 h-1.5 rounded-full <?php echo $user['status'] === 'Active' ? 'bg-green-500' : 'bg-gray-400'; ?>"></span>
<?php echo htmlspecialchars($user['status']); ?>
</span>
</p>
</div>
<?php if (!empty($user['created_at'])): ?>
<div>
<label class="text-xs font-bold text-text-sub uppercase">Member Since</label>
<p class="text-sm text-text-main dark:text-white mt-2 font-medium"><?php echo date('F j, Y', strtotime($user['created_at'])); ?></p>
</div>
<?php endif; ?>
<?php if (!empty($user['last_active'])): ?>
<div>
<label class="text-xs font-bold text-text-sub uppercase">Last Active</label>
<p class="text-sm text-text-main dark:text-white mt-2 font-medium"><?php echo formatLastActive($user['last_active']); ?></p>
</div>
<?php endif; ?>
</div>
<div class="mt-8 pt-8 border-t border-gray-200 dark:border-gray-700 flex gap-4">
<a href="admin_edit_user.php?id=<?php echo $user['user_id']; ?>" class="flex-1 flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-primary hover:bg-primary/90 text-white text-sm font-bold shadow-lg shadow-primary/30 transition-all">
<span class="material-symbols-outlined text-[20px]">edit</span>
Edit Profile
</a>
<a href="admin_dashboard.php" class="flex items-center justify-center gap-2 px-6 py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-text-sub hover:text-text-main hover:bg-gray-50 dark:hover:bg-gray-800 text-sm font-bold transition-colors">
<span class="material-symbols-outlined text-[20px]">arrow_back</span>
Back to Dashboard
</a>
</div>
</div>
</div>
</div>
</main>
</div>

<script>
// Real-time activity tracking
(function() {
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
    
    updateActivity();
    setInterval(updateActivity, 120000); // 2 minutes
})();
</script>

    <script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>

