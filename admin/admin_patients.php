<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

configureSecureSession();
requireRole('Admin');
updateUserActivity();

// Fetch current user details sidebar
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT user_id, full_name, role, profile_picture FROM tbl_user WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $stmt->fetch();
    } catch (PDOException $e) {}
}

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
        ['bg' => '#3b82f6', 'text' => '#ffffff'], 
        ['bg' => '#f59e0b', 'text' => '#ffffff'], 
        ['bg' => '#ef4444', 'text' => '#ffffff'], 
        ['bg' => '#8b5cf6', 'text' => '#ffffff'], 
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    
    return ['initials'=>$initials, 'bg_color'=>$color['bg'], 'text_color'=>$color['text']];
}

// Search & Pagination & Filters
$search = $_GET['search'] ?? '';
$gender_filter = $_GET['gender'] ?? '';
$age_filter = $_GET['age_range'] ?? '';
$role_filter = $_GET['role_type'] ?? '';
$sort_order = $_GET['sort'] ?? 'newest';

$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

$where = "WHERE 1=1";
$params = [];

if (!empty($search)) {
    $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.patient_id LIKE ?)";
    $term = "%$search%";
    $params = array_merge($params, [$term, $term, $term, $search]);
}

if (!empty($gender_filter)) {
    $where .= " AND p.gender = ?";
    $params[] = $gender_filter;
}

if (!empty($age_filter)) {
    // Calculate age using TIMESTAMPDIFF
    if ($age_filter === 'minor') {
        $where .= " AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) < 18";
    } elseif ($age_filter === 'young_adult') {
        $where .= " AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 18 AND 30";
    } elseif ($age_filter === 'adult') {
        $where .= " AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) BETWEEN 31 AND 50";
    } elseif ($age_filter === 'senior') {
        $where .= " AND TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) >= 51";
    }
}

if (!empty($role_filter)) {
    if ($role_filter === 'provider') {
        $where .= " AND p.account_id IN (SELECT guardian_account_id FROM tbl_dependencies)";
    } elseif ($role_filter === 'dependent') {
        $where .= " AND p.patient_id IN (SELECT sub_patient_id FROM tbl_dependencies)";
    }
}

// Sorting
$order_clause = "ORDER BY p.registration_date DESC"; // Default
if ($sort_order === 'oldest') {
    $order_clause = "ORDER BY p.registration_date ASC";
} elseif ($sort_order === 'name_asc') {
    $order_clause = "ORDER BY p.last_name ASC, p.first_name ASC";
} elseif ($sort_order === 'name_desc') {
    $order_clause = "ORDER BY p.last_name DESC, p.first_name DESC";
}

// Count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_patient p $where");
$count_stmt->execute($params);
$total_patients = $count_stmt->fetchColumn();
$total_pages = ceil($total_patients / $per_page);

// Fetch Patients
$sql = "SELECT p.*, TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
        gp.first_name as guardian_first, gp.last_name as guardian_last, 
        gp.email as guardian_email, gp.phone_number as guardian_phone
        FROM tbl_patient p
        LEFT JOIN tbl_dependencies d ON p.patient_id = d.sub_patient_id
        LEFT JOIN tbl_patient gp ON gp.account_id = d.guardian_account_id
        $where 
        $order_clause 
        LIMIT $per_page OFFSET $offset";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Patient Management - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: { colors: { "primary": "#8a11d4", "background-light": "#f7f6f8", "background-dark": "#1b1022", "surface-light": "#ffffff", "surface-dark": "#2d1b36", "text-main": "#151118", "text-sub": "#7a6189" }, fontFamily: { "display": ["Manrope", "sans-serif"] } },
            },
        }
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark text-text-main dark:text-white font-display overflow-hidden">
<div class="flex h-screen w-full">
    <!-- Sidebar -->
    <aside class="w-72 flex-shrink-0 flex flex-col bg-surface-light dark:bg-surface-dark border-r border-[#f3f0f4] dark:border-gray-800">
        <div class="flex flex-col h-full p-4">
             <a href="admin_current_user.php" class="flex gap-3 mb-8 items-center p-2 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 transition">
                <?php 
                $u = $current_user_details ?? ['full_name'=>'Admin', 'role'=>'Admin'];
                $pic = $u['profile_picture'] ?? null;
                if($pic && file_exists('../'.$pic)) echo "<img src='../$pic' class='w-12 h-12 rounded-full border-2 border-primary object-cover'>";
                else {
                    $def = getDefaultProfilePhoto($u['full_name']);
                    echo "<div class='w-12 h-12 rounded-full flex items-center justify-center text-white font-bold border-2 border-primary' style='background:{$def['bg_color']}'>{$def['initials']}</div>";
                }
                ?>
                <div><h1 class="font-bold text-sm"><?php echo htmlspecialchars($u['full_name']); ?></h1><p class="text-xs text-primary font-bold uppercase"><?php echo htmlspecialchars($u['role']); ?></p></div>
            </a>
            
            <nav class="flex flex-col gap-2 flex-1">
                <a href="admin_dashboard.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub hover:text-text-main"><span class="material-symbols-outlined">dashboard</span><span class="text-sm font-medium">Dashboard</span></a>
                <a href="admin_user.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub hover:text-text-main"><span class="material-symbols-outlined">group</span><span class="text-sm font-medium">Users</span></a>
                <a href="admin_patients.php" class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 text-primary font-bold"><span class="material-symbols-outlined fill-1">accessibility_new</span><span class="text-sm">Patients</span></a>
                <a href="admin_schedule.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub hover:text-text-main"><span class="material-symbols-outlined">schedule</span><span class="text-sm font-medium">Schedules</span></a>
                <a href="admin_service.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub hover:text-text-main"><span class="material-symbols-outlined">medical_services</span><span class="text-sm font-medium">Services</span></a>
                <a href="admin_reports.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub hover:text-text-main"><span class="material-symbols-outlined">description</span><span class="text-sm font-medium">Reports</span></a>
                <a href="admin_settings.php" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub hover:text-text-main"><span class="material-symbols-outlined">settings</span><span class="text-sm font-medium">Settings</span></a>
            </nav>
            <div class="mt-auto pt-6 border-t border-gray-100 dark:border-gray-800">
                <a href="../logout.php" class="flex items-center gap-3 px-4 py-2 text-text-sub hover:text-red-500"><span class="material-symbols-outlined">logout</span><span class="text-sm font-medium">Logout</span></a>
            </div>
        </div>
    </aside>

    <main class="flex-1 flex flex-col h-full overflow-hidden relative">
        <header class="h-auto py-6 flex flex-col gap-4 px-8 bg-surface-light dark:bg-surface-dark border-b border-gray-100 dark:border-gray-800 z-10">
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-bold text-text-main dark:text-white">Patient Database</h2>
                <form class="bg-gray-100 dark:bg-gray-800 rounded-xl px-4 py-2 flex items-center w-80 focus-within:ring-2 ring-primary/50 transition">
                    <span class="material-symbols-outlined text-text-sub">search</span>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search patients..." class="bg-transparent border-none text-sm w-full focus:ring-0 text-text-main dark:text-white">
                </form>
            </div>
            
            <!-- Filters -->
            <form method="GET" class="flex flex-wrap gap-2 items-center">
                <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                
                <select name="role_type" onchange="this.form.submit()" class="bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-lg text-sm font-medium text-text-main dark:text-white focus:ring-primary focus:border-primary">
                    <option value="">All Account Types</option>
                    <option value="provider" <?php echo $role_filter=='provider'?'selected':''; ?>>Provider (Has Dependents)</option>
                    <option value="dependent" <?php echo $role_filter=='dependent'?'selected':''; ?>>Dependent (Has Guardian)</option>
                </select>

                <select name="age_range" onchange="this.form.submit()" class="bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-lg text-sm font-medium text-text-main dark:text-white focus:ring-primary focus:border-primary">
                    <option value="">All Ages</option>
                    <option value="minor" <?php echo $age_filter=='minor'?'selected':''; ?>>Minors (&lt;18)</option>
                    <option value="young_adult" <?php echo $age_filter=='young_adult'?'selected':''; ?>>Young Adult (18-30)</option>
                    <option value="adult" <?php echo $age_filter=='adult'?'selected':''; ?>>Adult (31-50)</option>
                    <option value="senior" <?php echo $age_filter=='senior'?'selected':''; ?>>Senior (51+)</option>
                </select>

                <select name="gender" onchange="this.form.submit()" class="bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-lg text-sm font-medium text-text-main dark:text-white focus:ring-primary focus:border-primary">
                    <option value="">All Genders</option>
                    <option value="Male" <?php echo $gender_filter=='Male'?'selected':''; ?>>Male</option>
                    <option value="Female" <?php echo $gender_filter=='Female'?'selected':''; ?>>Female</option>
                    <option value="Other" <?php echo $gender_filter=='Other'?'selected':''; ?>>Other</option>
                </select>

                <select name="sort" onchange="this.form.submit()" class="bg-gray-50 dark:bg-gray-800 border-gray-200 dark:border-gray-700 rounded-lg text-sm font-medium text-text-main dark:text-white focus:ring-primary focus:border-primary">
                    <option value="newest" <?php echo $sort_order=='newest'?'selected':''; ?>>Newest First</option>
                    <option value="oldest" <?php echo $sort_order=='oldest'?'selected':''; ?>>Oldest First</option>
                    <option value="name_asc" <?php echo $sort_order=='name_asc'?'selected':''; ?>>Name (A-Z)</option>
                    <option value="name_desc" <?php echo $sort_order=='name_desc'?'selected':''; ?>>Name (Z-A)</option>
                </select>

                <?php if(!empty($age_filter) || !empty($gender_filter) || $sort_order !== 'newest' || !empty($search) || !empty($role_filter)): ?>
                    <a href="admin_patients.php" class="px-3 py-2 text-xs font-bold text-red-500 hover:bg-red-50 rounded-lg transition">Clear Filters</a>
                <?php endif; ?>
            </form>
        </header>

        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-[1400px] mx-auto">
                <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-gray-50 dark:bg-gray-800/50 border-b border-gray-100 dark:border-gray-800">
                            <tr>
                                <th class="p-4 text-xs font-bold text-text-sub uppercase">Patient Info</th>
                                <th class="p-4 text-xs font-bold text-text-sub uppercase">Age / Gender</th>
                                <th class="p-4 text-xs font-bold text-text-sub uppercase">Contact</th>
                                <th class="p-4 text-xs font-bold text-text-sub uppercase">Registered</th>
                                <th class="p-4 text-xs font-bold text-text-sub uppercase text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            <?php if(empty($patients)): ?>
                                <tr><td colspan="5" class="p-8 text-center text-text-sub">No patients found.</td></tr>
                            <?php else: ?>
                                <?php foreach($patients as $p): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/20 transition">
                                    <td class="p-4">
                                        <div>
                                            <p class="font-bold text-sm text-text-main dark:text-white"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></p>
                                            <p class="text-xs text-text-sub font-mono">ID: <?php echo str_pad($p['patient_id'], 5, '0', STR_PAD_LEFT); ?></p>
                                        </div>
                                    </td>
                                    <td class="p-4">
                                        <p class="text-sm font-medium text-text-main dark:text-white"><?php echo $p['age']; ?> yrs</p>
                                        <p class="text-xs text-text-sub capitalize"><?php echo htmlspecialchars($p['gender']); ?></p>
                                    </td>
                                    <td class="p-4">
                                        <?php if($p['age'] < 18 && !empty($p['guardian_first'])): ?>
                                            <!-- Minor with Guardian -->
                                            <div class="flex flex-col gap-0.5">
                                                <p class="text-xs font-bold text-primary flex items-center gap-1">
                                                    <span class="material-symbols-outlined text-[14px]">family_star</span> Guardian
                                                </p>
                                                <p class="text-sm text-text-main dark:text-white"><?php echo htmlspecialchars($p['guardian_first'].' '.$p['guardian_last']); ?></p>
                                                <p class="text-xs text-text-sub truncate max-w-[200px]"><?php echo htmlspecialchars($p['guardian_phone'] ?? $p['guardian_email'] ?? 'No contact'); ?></p>
                                            </div>
                                        <?php else: ?>
                                            <!-- Standard Contact -->
                                            <p class="text-sm text-text-main dark:text-white"><?php echo htmlspecialchars($p['contact_number']); ?></p>
                                            <p class="text-xs text-text-sub truncate max-w-[200px]"><?php echo htmlspecialchars($p['email']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-4 text-sm text-text-sub">
                                        <?php echo date('M d, Y', strtotime($p['registration_date'])); ?>
                                    </td>
                                    <td class="p-4 text-right">
                                        <a href="admin_patient_details.php?id=<?php echo $p['patient_id']; ?>" class="inline-flex items-center justify-center p-2 rounded-lg bg-primary/10 text-primary hover:bg-primary hover:text-white transition font-bold text-xs gap-1">
                                            <span class="material-symbols-outlined text-[18px]">visibility</span> View
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <!-- Pagination -->
                    <?php if($total_pages > 1): ?>
                    <div class="p-4 border-t border-gray-100 dark:border-gray-800 flex justify-between items-center">
                        <span class="text-xs text-text-sub">Page <?php echo $page; ?> of <?php echo $total_pages; ?></span>
                        <div class="flex gap-2">
                            <a href="?page=<?php echo max(1, $page-1); ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 text-xs font-bold border border-gray-200 rounded hover:bg-gray-50 <?php echo $page<=1?'pointer-events-none opacity-50':''; ?>">Prev</a>
                            <a href="?page=<?php echo min($total_pages, $page+1); ?>&search=<?php echo urlencode($search); ?>" class="px-3 py-1 text-xs font-bold border border-gray-200 rounded hover:bg-gray-50 <?php echo $page>=$total_pages?'pointer-events-none opacity-50':''; ?>">Next</a>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>
</body>
</html>
