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

// Handle delete service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_service'])) {
    $service_id = intval($_POST['service_id']);
    
    try {
        // Check if service is used in appointments or medical records
        $check_appointments = $pdo->prepare("SELECT COUNT(*) FROM tbl_appointment WHERE service_id = ?");
        $check_appointments->execute([$service_id]);
        $appointment_count = $check_appointments->fetchColumn();
        
        $check_records = $pdo->prepare("SELECT COUNT(*) FROM tbl_medical_record WHERE service_id = ?");
        $check_records->execute([$service_id]);
        $record_count = $check_records->fetchColumn();
        
        if ($appointment_count > 0 || $record_count > 0) {
            $_SESSION['error_message'] = 'Cannot delete service. It is being used in appointments or medical records.';
        } else {
            $delete_stmt = $pdo->prepare("DELETE FROM tbl_service WHERE service_id = ?");
            $delete_stmt->execute([$service_id]);
            $_SESSION['success_message'] = 'Service deleted successfully!';
        }
    } catch (PDOException $e) {
        error_log('Error deleting service: ' . $e->getMessage());
        $_SESSION['error_message'] = 'Error deleting service. Please try again.';
    }
    
    header('Location: admin_service.php');
    exit;
}

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

// Get filter parameters
$category_filter = $_GET['category'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];

if ($category_filter !== 'all' && !empty($category_filter)) {
    $where_conditions[] = "category = ?";
    $params[] = $category_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(service_name LIKE ? OR category LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_service $where_clause");
$count_stmt->execute($params);
$total_services = $count_stmt->fetchColumn();

// Get services with pagination
$query = "SELECT service_id, service_name, category, base_price, estimated_duration_mins, created_at FROM tbl_service $where_clause ORDER BY service_id DESC LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$services = $stmt->fetchAll();

// Get unique categories for filter
$categories_stmt = $pdo->query("SELECT DISTINCT category FROM tbl_service WHERE category IS NOT NULL AND category != '' ORDER BY category");
$categories = $categories_stmt->fetchAll(PDO::FETCH_COLUMN);

$total_pages = ceil($total_services / $per_page);

// Get success/error messages
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message'], $_SESSION['error_message']);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Service Management - Vanessa Nicolas Dental Clinic</title>
    <link href="https://fonts.googleapis.com" rel="preconnect"/>
    <link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4",
                        "accent": "#FFD700",
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
        window.editService = function(serviceId) {
            window.location.href = 'admin_edit_service.php?id=' + serviceId;
        };
        
        window.deleteService = async function(serviceId, serviceName) {
            const confirmed = await window.showConfirm(
                'Are you sure you want to delete service "' + serviceName + '"? This action cannot be undone.',
                'Delete Service'
            );
            
            if (confirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = 'admin_service.php';
                
                const serviceIdInput = document.createElement('input');
                serviceIdInput.type = 'hidden';
                serviceIdInput.name = 'service_id';
                serviceIdInput.value = serviceId;
                
                const deleteInput = document.createElement('input');
                deleteInput.type = 'hidden';
                deleteInput.name = 'delete_service';
                deleteInput.value = '1';
                
                form.appendChild(serviceIdInput);
                form.appendChild(deleteInput);
                document.body.appendChild(form);
                form.submit();
            }
        };
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
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary transition-all" href="admin_service.php">
<span class="material-symbols-outlined fill-1">medical_services</span>
<p class="text-sm font-bold leading-normal">Services</p>
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
<a href="admin_registration_service.php" class="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl h-12 bg-primary hover:bg-primary/90 text-white text-sm font-bold shadow-lg shadow-primary/30 transition-all">
<span class="material-symbols-outlined text-[20px]">add</span>
<span>Add Service</span>
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
<h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Service Management</h2>
</div>
<div class="flex items-center gap-6">
<form method="GET" action="admin_service.php" class="flex items-center w-80 h-10 bg-[#f3f0f4] dark:bg-gray-800 rounded-xl px-3 gap-2 group focus-within:ring-2 ring-primary/50 transition-all">
<span class="material-symbols-outlined text-text-sub">search</span>
<input type="hidden" name="category" value="<?php echo htmlspecialchars($category_filter); ?>">
<input class="w-full bg-transparent border-none text-sm text-text-main dark:text-white placeholder-text-sub focus:ring-0 p-0" name="search" placeholder="Search services..." value="<?php echo htmlspecialchars($search_query); ?>"/>
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
<form method="GET" action="admin_service.php" class="flex items-center gap-3 w-full md:w-auto overflow-x-auto pb-2 md:pb-0">
<input type="hidden" name="search" value="<?php echo htmlspecialchars($search_query); ?>">
<div class="flex items-center gap-2 px-3 py-2 bg-[#f3f0f4] dark:bg-gray-800 rounded-lg min-w-[140px]">
<span class="material-symbols-outlined text-text-sub text-[20px]">filter_alt</span>
<select name="category" class="bg-transparent border-none text-sm font-medium text-text-main dark:text-white p-0 pr-8 focus:ring-0 cursor-pointer" onchange="this.form.submit()">
<option value="all" <?php echo $category_filter === 'all' ? 'selected' : ''; ?>>All Categories</option>
<?php foreach ($categories as $cat): ?>
<option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
<?php endforeach; ?>
</select>
</div>
</form>
<a href="admin_registration_service.php" class="flex items-center justify-center gap-2 px-6 py-2 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm shadow-lg shadow-primary/20 transition-all flex-1 md:flex-none">
<span class="material-symbols-outlined text-[20px]">add</span>
Add New Service
</a>
</div>
<div class="flex-1 bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 flex flex-col overflow-hidden">
<div class="overflow-x-auto flex-1">
<table class="w-full text-left border-collapse">
<thead>
<tr class="bg-[#f9f8fa] dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Service ID</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Service Name</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Category</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Base Price</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Duration</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider">Created</th>
<th class="p-4 text-xs font-bold text-text-sub uppercase tracking-wider text-right">Actions</th>
</tr>
</thead>
<tbody>
<?php if (empty($services)): ?>
<tr>
<td colspan="7" class="p-8 text-center text-text-sub">
<div class="flex flex-col items-center gap-2">
<span class="material-symbols-outlined text-4xl opacity-50">medical_services</span>
<p class="text-sm font-medium">No services found</p>
</div>
</td>
</tr>
<?php else: ?>
<?php foreach ($services as $service): ?>
<tr class="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors">
<td class="p-4 text-sm font-semibold text-text-main dark:text-white">#<?php echo htmlspecialchars($service['service_id']); ?></td>
<td class="p-4">
<div class="flex items-center gap-3">
<div class="w-10 h-10 rounded-lg bg-primary/10 flex items-center justify-center">
<span class="material-symbols-outlined text-primary">medical_services</span>
</div>
<div>
<p class="text-sm font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($service['service_name']); ?></p>
</div>
</div>
</td>
<td class="p-4">
<?php if ($service['category']): ?>
<span class="inline-flex items-center gap-1 px-3 py-1 rounded-lg bg-blue-100 dark:bg-blue-900/20 text-blue-700 dark:text-blue-300 text-xs font-semibold">
<span class="material-symbols-outlined text-sm">category</span>
<?php echo htmlspecialchars($service['category']); ?>
</span>
<?php else: ?>
<span class="text-xs text-text-sub">—</span>
<?php endif; ?>
</td>
<td class="p-4">
<span class="text-sm font-bold text-green-600 dark:text-green-400">₱<?php echo number_format($service['base_price'], 2); ?></span>
</td>
<td class="p-4">
<span class="text-sm text-text-main dark:text-white"><?php echo htmlspecialchars($service['estimated_duration_mins']); ?> min</span>
</td>
<td class="p-4">
<span class="text-xs text-text-sub"><?php echo date('M d, Y', strtotime($service['created_at'])); ?></span>
</td>
<td class="p-4">
<div class="flex items-center justify-end gap-2">
<button onclick="editService(<?php echo $service['service_id']; ?>)" class="p-2 rounded-lg bg-primary/10 text-primary hover:bg-primary/20 transition-colors" title="Edit Service">
<span class="material-symbols-outlined text-lg">edit</span>
</button>
<button onclick="deleteService(<?php echo $service['service_id']; ?>, '<?php echo htmlspecialchars(addslashes($service['service_name'])); ?>')" class="p-2 rounded-lg bg-red-100 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/30 transition-colors" title="Delete Service">
<span class="material-symbols-outlined text-lg">delete</span>
</button>
</div>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($total_pages > 1): ?>
<div class="p-4 border-t border-gray-100 dark:border-gray-800 flex items-center justify-between">
<div class="text-sm text-text-sub">
Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $per_page, $total_services); ?> of <?php echo $total_services; ?> services
</div>
<div class="flex items-center gap-2">
<?php if ($page > 1): ?>
<a href="?page=<?php echo $page - 1; ?>&category=<?php echo urlencode($category_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700 text-sm font-medium transition-colors">
Previous
</a>
<?php endif; ?>
<?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
<a href="?page=<?php echo $i; ?>&category=<?php echo urlencode($category_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 rounded-lg <?php echo $i === $page ? 'bg-primary text-white' : 'bg-gray-100 dark:bg-gray-800 text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700'; ?> text-sm font-medium transition-colors">
<?php echo $i; ?>
</a>
<?php endfor; ?>
<?php if ($page < $total_pages): ?>
<a href="?page=<?php echo $page + 1; ?>&category=<?php echo urlencode($category_filter); ?>&search=<?php echo urlencode($search_query); ?>" class="px-3 py-1.5 rounded-lg bg-gray-100 dark:bg-gray-800 text-text-main dark:text-white hover:bg-gray-200 dark:hover:bg-gray-700 text-sm font-medium transition-colors">
Next
</a>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
</main>
</div>

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
</body>
</html>

