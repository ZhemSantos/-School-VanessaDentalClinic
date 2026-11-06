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

$message = '';
$message_type = '';
$csrf_token = generateCSRFToken();

// Common service categories
$service_categories = [
    'General Dentistry',
    'Orthodontics',
    'Pediatric Dentistry (Pedodontics)',
    'Periodontics',
    'Prosthodontics',
    'Endodontics',
    'Oral and Maxillofacial Surgery',
    'Oral and Maxillofacial Pathology',
    'Oral and Maxillofacial Radiology',
    'Dental Anesthesiology',
    'Dental Public Health',
    'Oral Medicine',
    'Special Needs Dentistry',
    'Implantology'
];

// Installment Rules (Category-Based)
$installment_rules = [
    'General Dentistry' => ['max_months' => 6, 'min_dp' => 20],
    'Orthodontics' => ['max_months' => 24, 'min_dp' => 20],
    'Pediatric Dentistry (Pedodontics)' => ['max_months' => 6, 'min_dp' => 20],
    'Periodontics' => ['max_months' => 12, 'min_dp' => 20],
    'Prosthodontics' => ['max_months' => 12, 'min_dp' => 30],
    'Endodontics' => ['max_months' => 6, 'min_dp' => 20],
    'Oral and Maxillofacial Surgery' => ['max_months' => 6, 'min_dp' => 20],
    'Oral and Maxillofacial Pathology' => ['max_months' => 3, 'min_dp' => 20],
    'Oral and Maxillofacial Radiology' => ['max_months' => 0, 'min_dp' => 0],
    'Dental Anesthesiology' => ['max_months' => 0, 'min_dp' => 0],
    'Dental Public Health' => ['max_months' => 0, 'min_dp' => 0],
    'Oral Medicine' => ['max_months' => 6, 'min_dp' => 20],
    'Special Needs Dentistry' => ['max_months' => 12, 'min_dp' => 20],
    'Implantology' => ['max_months' => 18, 'min_dp' => 30]
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_service'])) {
    // Verify CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submitted_token)) {
        $message = 'Invalid security token. Please refresh the page and try again.';
        $message_type = 'error';
    } else {
        // Sanitize and validate inputs
        $service_name = sanitizeInput($_POST['service_name'] ?? '');
        $category = sanitizeInput($_POST['category'] ?? '');
        $base_price = $_POST['base_price'] ?? '';
        $estimated_duration_mins = $_POST['estimated_duration_mins'] ?? '';
        
        // Comprehensive validation
        $errors = [];
        
        // Required fields
        if (empty($service_name)) {
            $errors[] = 'Service name is required.';
        } elseif (strlen($service_name) > 100) {
            $errors[] = 'Service name must be less than 100 characters.';
        }
        
        if (empty($base_price)) {
            $errors[] = 'Base price is required.';
        } elseif (!is_numeric($base_price) || floatval($base_price) < 0) {
            $errors[] = 'Base price must be a valid positive number.';
        } elseif (floatval($base_price) > 999999.99) {
            $errors[] = 'Base price is too large.';
        }
        
        if (empty($estimated_duration_mins)) {
            $errors[] = 'Estimated duration is required.';
        } elseif (!is_numeric($estimated_duration_mins) || intval($estimated_duration_mins) < 1) {
            $errors[] = 'Estimated duration must be a valid positive number (in minutes).';
        } elseif (intval($estimated_duration_mins) > 1440) {
            $errors[] = 'Estimated duration cannot exceed 1440 minutes (24 hours).';
        }
        
        // Category validation (optional but if provided, should not exceed 50 chars)
        if (!empty($category) && strlen($category) > 50) {
            $errors[] = 'Category must be less than 50 characters.';
        }
        
        if (empty($errors)) {
            try {
                // Check if service name already exists
                $check_stmt = $pdo->prepare("SELECT service_id FROM tbl_service WHERE service_name = ? LIMIT 1");
                $check_stmt->execute([$service_name]);
                if ($check_stmt->fetch()) {
                    $errors[] = 'A service with this name already exists.';
                } else {
                    // Insert new service
                    $insert_stmt = $pdo->prepare("INSERT INTO tbl_service (service_name, category, base_price, estimated_duration_mins, is_installment_available, installment_down_payment, installment_duration_months, installment_interest_percent, installment_monthly_payment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $insert_stmt->execute([
                        $service_name,
                        !empty($category) ? $category : null,
                        floatval($base_price),
                        intval($estimated_duration_mins),
                        isset($_POST['is_installment_available']) ? 1 : 0,
                        floatval($_POST['installment_down_payment'] ?? 0),
                        intval($_POST['installment_duration_months'] ?? 0),
                        floatval($_POST['installment_interest_percent'] ?? 0),
                        floatval($_POST['installment_monthly_payment'] ?? 0)
                    ]);
                    
                    $_SESSION['success_message'] = 'Service registered successfully!';
                    header('Location: admin_service.php');
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Error registering service: ' . $e->getMessage());
                $errors[] = 'Database error. Please try again.';
            }
        }
        
        if (!empty($errors)) {
            $message = implode(' ', $errors);
            $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Add Service - Vanessa Nicolas Dental Clinic</title>
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
<h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Add New Service</h2>
</div>
<div class="flex items-center gap-6">
<div class="flex gap-3">
<button class="relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
<span class="material-symbols-outlined text-[22px]">notifications</span>
<span class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700"></span>
</button>
</div>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8 scroll-smooth">
<div class="max-w-[1000px] mx-auto flex flex-col gap-8 pb-10">
<?php if ($message): ?>
<div class="bg-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-50 dark:bg-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-900/20 border border-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-200 dark:border-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-800 rounded-xl p-4 flex items-center gap-3">
<span class="material-symbols-outlined text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-600 dark:text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-400"><?php echo $message_type === 'success' ? 'check_circle' : 'error'; ?></span>
<span class="text-sm font-medium text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-800 dark:text-<?php echo $message_type === 'success' ? 'green' : 'red'; ?>-300"><?php echo htmlspecialchars($message); ?></span>
</div>
<?php endif; ?>
<div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-8 shadow-sm border border-gray-100 dark:border-gray-800">
<div class="flex items-center justify-between mb-8 border-b border-gray-100 dark:border-gray-800 pb-6">
<div>
<h2 class="text-xl font-bold text-text-main dark:text-white">Service Information</h2>
<p class="text-sm text-text-sub mt-1">Enter the details for the new dental service.</p>
</div>
<div class="hidden sm:block">
<div class="h-10 w-10 rounded-full bg-primary/10 flex items-center justify-center text-primary">
<span class="material-symbols-outlined">medical_services</span>
</div>
</div>
</div>
<form class="flex flex-col gap-8" method="POST" action="admin_registration_service.php">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
<div class="col-span-1 md:col-span-2">
<h4 class="text-sm font-bold text-primary uppercase tracking-wider mb-4 flex items-center gap-2">
<span class="material-symbols-outlined text-lg">info</span> Service Details
</h4>
</div>
<div class="flex flex-col gap-1.5">
<label class="text-xs font-bold text-text-sub uppercase">Service Name <span class="text-red-500">*</span></label>
<input class="h-12 w-full bg-[#f7f6f8] dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl px-4 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all placeholder:text-gray-400" placeholder="e.g. Teeth Cleaning" type="text" name="service_name" value="<?php echo htmlspecialchars($_POST['service_name'] ?? ''); ?>" maxlength="100" required/>
</div>
<div class="flex flex-col gap-1.5">
<label class="text-xs font-bold text-text-sub uppercase">Category</label>
<select class="h-12 w-full bg-[#f7f6f8] dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl px-4 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all" name="category">
<option value="">Select a category (optional)</option>
<?php foreach ($service_categories as $cat): ?>
<option value="<?php echo htmlspecialchars($cat); ?>" <?php echo (isset($_POST['category']) && $_POST['category'] === $cat) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="flex flex-col gap-1.5">
<label class="text-xs font-bold text-text-sub uppercase">Base Price (₱) <span class="text-red-500">*</span></label>
<input class="h-12 w-full bg-[#f7f6f8] dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl px-4 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all placeholder:text-gray-400" placeholder="0.00" type="number" name="base_price" value="<?php echo htmlspecialchars($_POST['base_price'] ?? ''); ?>" min="0" step="0.01" max="999999.99" required/>
<p class="text-xs text-text-sub mt-1">Enter the base price in Philippine Peso</p>
</div>
<div class="flex flex-col gap-1.5">
<label class="text-xs font-bold text-text-sub uppercase">Estimated Duration <span class="text-red-500">*</span></label>
<select class="h-12 w-full bg-[#f7f6f8] dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-xl px-4 text-sm text-text-main dark:text-white focus:ring-2 focus:ring-primary/50 focus:border-primary transition-all" name="estimated_duration_mins" required>
<option value="">Select duration...</option>
<?php
$selected_duration = $_POST['estimated_duration_mins'] ?? '';
for ($i = 15; $i <= 480; $i += 15) {
    $hours = floor($i / 60);
    $mins = $i % 60;
    $label_parts = [];
    if ($hours > 0) $label_parts[] = $hours . "hr" . ($hours > 1 ? "s" : "");
    if ($mins > 0) $label_parts[] = $mins . "mins";
    
    $human_readable = implode(" ", $label_parts);
    if (!empty($human_readable)) {
        $label = $i . " (" . $human_readable . ")";
    } else {
        $label = $i;
    }
    
    $selected = ($selected_duration == $i) ? 'selected' : '';
    echo "<option value=\"$i\" $selected>$label</option>";
}
?>
</select>
<p class="text-xs text-text-sub mt-1">Select typical duration (15 min intervals)</p>
</div>
                            <div id="installment_checkbox_wrapper" class="md:col-span-2 mt-4 transition-all duration-300">
                                <label class="flex items-center gap-3 p-4 border border-gray-200 rounded-xl bg-gray-50 cursor-pointer hover:bg-white hover:border-royal-purple transition-all">
                                    <input type="checkbox" name="is_installment_available" id="is_installment_available" class="w-5 h-5 rounded text-royal-purple focus:ring-royal-purple border-gray-300">
                                    <span class="font-bold text-sm text-[#151118]">Enable Installment Plan for this Service</span>
                                </label>
                            </div>

                            <div id="installment_fields" class="md:col-span-2 grid grid-cols-1 md:grid-cols-3 gap-6 p-6 bg-purple-50 rounded-xl border border-purple-100 hidden">
                                <div class="col-span-3 pb-2 border-b border-purple-100 mb-2">
                                    <h4 class="font-bold text-royal-purple">Installment Configuration</h4>
                                    <p class="text-xs text-slate-500">Define the payment structure for patients</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Interest Premium (%)</label>
                                    <input type="number" step="0.01" name="installment_interest_percent" id="installment_interest_percent" value="0" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 outline-none transition-all" oninput="calculateMonthly()">
                                    <p class="text-[10px] text-slate-500 mt-1">Add markup for installments (e.g. 10%)</p>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Down Payment (₱)</label>
                                    <input type="number" step="0.01" name="installment_down_payment" id="installment_down_payment" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 outline-none transition-all" oninput="calculateMonthly()">
                                    <div class="flex items-center gap-1 mt-1 relative group">
                                        <p id="dp_hint" class="text-[10px] text-orange-500 font-bold hidden">Recommended: 20%+</p>
                                        <span id="dp_tooltip_icon" class="material-symbols-outlined text-[18px] text-accent cursor-help hidden">help</span>
                                        <!-- Custom Tooltip -->
                                        <div id="dp_tooltip_content" class="absolute bottom-full left-0 mb-2 w-64 p-3 bg-gray-900 text-white text-xs rounded-xl shadow-xl z-50 hidden group-hover:block pointer-events-none after:content-[''] after:absolute after:top-full after:left-4 after:border-8 after:border-transparent after:border-t-gray-900">
                                            This is a recommended minimum based on the category.
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Duration (Months)</label>
                                    <input type="number" name="installment_duration_months" id="installment_duration_months" class="w-full bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 outline-none transition-all" oninput="calculateMonthly()">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Monthly Payment (₱)</label>
                                    <input type="number" step="0.01" name="installment_monthly_payment" id="installment_monthly_payment" class="w-full bg-purple-100 border border-purple-200 rounded-xl px-4 py-3 text-sm font-bold text-royal-purple focus:outline-none cursor-not-allowed" readonly>
                                    <p class="text-[10px] text-slate-500 mt-1">Auto-calculated: (Total - Down) / Months</p>
                                </div>
                            </div>
                            
                            <script>
                            const installmentRules = <?php echo json_encode($installment_rules); ?>;

                            // Explicit list of categories that allow installments
                            const allowedInstallmentCategories = [
                                'Orthodontics',
                                'Prosthodontics',
                                'Oral and Maxillofacial Surgery',
                                'Implantology',
                                'Special Needs Dentistry'
                            ];

                            function checkCategoryEligibility() {
                                const categorySelect = document.querySelector('select[name="category"]');
                                const selectedCategory = categorySelect.value;
                                const installmentCheckboxWrapper = document.getElementById('installment_checkbox_wrapper'); // We need to add this ID to the HTML above
                                const installmentCheckbox = document.getElementById('is_installment_available');
                                const installmentSection = document.getElementById('installment_fields');

                                if (allowedInstallmentCategories.includes(selectedCategory)) {
                                    // Show the checkbox option
                                    installmentCheckboxWrapper.classList.remove('hidden');
                                } else {
                                    // Hide the checkbox option
                                    installmentCheckboxWrapper.classList.add('hidden');
                                    
                                    // Reset and hide the inner installment configuration if it was open
                                    if (installmentCheckbox.checked) {
                                        installmentCheckbox.checked = false;
                                        toggleInstallmentFields(); // This will hide the fields and reset values
                                    }
                                }
                                
                                // Apply rules if visible and checked
                                if (!installmentCheckboxWrapper.classList.contains('hidden') && installmentCheckbox.checked) {
                                    applyCategoryRules(selectedCategory);
                                }
                            }

                            function toggleInstallmentFields() {
                                const checkbox = document.getElementById('is_installment_available');
                                const fields = document.getElementById('installment_fields');
                                const cat = document.querySelector('select[name="category"]').value;
                                
                                if (checkbox.checked) {
                                    fields.classList.remove('hidden');
                                    fields.classList.add('grid');
                                    if(cat) applyCategoryRules(cat);
                                } else {
                                    fields.classList.add('hidden');
                                    fields.classList.remove('grid');
                                    
                                    // Clear fields when unchecked
                                    document.getElementById('installment_down_payment').value = '';
                                    document.getElementById('installment_duration_months').value = '';
                                    document.getElementById('installment_monthly_payment').value = '';
                                    document.getElementById('installment_interest_percent').value = '0';
                                }
                            }

                            function applyCategoryRules(category) {
                                const durationInput = document.getElementById('installment_duration_months');
                                
                                if (!installmentRules[category]) return;

                                const rules = installmentRules[category];
                                const maxMonths = rules.max_months;
                                
                                if (maxMonths > 0) {
                                    durationInput.max = maxMonths;
                                    durationInput.placeholder = "Max: " + maxMonths + " months";
                                    
                                    // Update DP Hint
                                    const dpHint = document.getElementById('dp_hint');
                                    const dpIcon = document.getElementById('dp_tooltip_icon');
                                    
                                    dpHint.textContent = "Recommended: " + rules.min_dp + "%";
                                    dpHint.classList.remove('hidden');
                                    dpIcon.classList.remove('hidden');
                                    
                                    // Store min dp pct in data attribute for calculation
                                    document.getElementById('installment_down_payment').dataset.minPct = rules.min_dp;
                                }
                                calculateMonthly();
                            }

                            function calculateMonthly() {
                                const basePrice = parseFloat(document.querySelector('input[name="base_price"]').value) || 0;
                                const interestPct = parseFloat(document.getElementById('installment_interest_percent').value) || 0;
                                const downPaymentInput = document.getElementById('installment_down_payment');
                                const down = parseFloat(downPaymentInput.value) || 0;
                                const months = parseInt(document.getElementById('installment_duration_months').value) || 0;
                                
                                // Calculate Installment Total
                                const totalInstallmentPrice = basePrice * (1 + (interestPct / 100));
                                
                                // Validate Down Payment
                                const minPct = parseFloat(downPaymentInput.dataset.minPct) || 0;
                                const minDpAmount = totalInstallmentPrice * (minPct / 100);
                                const dpHint = document.getElementById('dp_hint');
                                const dpContent = document.getElementById('dp_tooltip_content');
                                
                                if (minPct > 0) {
                                    // Update tooltip with computation
                                    dpContent.innerHTML = `<strong>Recommendation Breakdown</strong><br/>Min ${minPct}% of ₱${totalInstallmentPrice.toLocaleString()} = ₱${minDpAmount.toLocaleString()}.<br/><span class="text-gray-400 text-[10px]">You may set a lower amount if necessary.</span>`;
                                    
                                    if (down < minDpAmount) {
                                        dpHint.classList.add('text-orange-500');
                                        dpHint.classList.remove('text-green-500');
                                        dpHint.textContent = `Recommended: ₱${minDpAmount.toFixed(2)} (${minPct}%)`;
                                    } else {
                                        dpHint.classList.remove('text-orange-500');
                                        dpHint.classList.add('text-green-500');
                                        dpHint.textContent = `Recommended: ₱${minDpAmount.toFixed(2)} (${minPct}%)`;
                                    }
                                }

                                if (totalInstallmentPrice > 0 && months > 0) {
                                    const monthly = (totalInstallmentPrice - down) / months;
                                    document.getElementById('installment_monthly_payment').value = monthly.toFixed(2);
                                } else {
                                    document.getElementById('installment_monthly_payment').value = '';
                                }
                            }
                            
                            // Listeners
                            document.querySelector('input[name="base_price"]').addEventListener('input', calculateMonthly);
                            document.querySelector('select[name="category"]').addEventListener('change', function() {
                                checkCategoryEligibility();
                            });
                            document.getElementById('is_installment_available').addEventListener('change', toggleInstallmentFields);
                            
                            // Initial call
                            document.addEventListener('DOMContentLoaded', function() {
                                checkCategoryEligibility();
                                toggleInstallmentFields();
                            });
                            </script>

</div>
<div class="flex items-center justify-end gap-4 pt-6 border-t border-gray-100 dark:border-gray-800">
<a href="admin_service.php" class="px-6 py-2.5 rounded-xl border border-gray-200 dark:border-gray-700 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-800 text-sm font-bold transition-colors">
Cancel
</a>
<button type="submit" name="register_service" class="px-6 py-2.5 rounded-xl bg-primary hover:bg-primary/90 text-white text-sm font-bold shadow-lg shadow-primary/30 transition-all">
<span class="flex items-center gap-2">
<span class="material-symbols-outlined text-[20px]">add</span>
Register Service
</span>
</button>
</div>
</form>
</div>
</div>
</div>
</main>
</div>
    <script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body>
</html>

