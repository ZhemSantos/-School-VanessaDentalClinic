<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role
requireRole('Receptionist');

// Update activity
updateUserActivity();

// Fetch current user
$user_id = $_SESSION['user_id'];
try {
    $stmt = $pdo->prepare("SELECT * FROM tbl_user WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    die("Error fetching profile.");
}

$is_online = isUserActive($user['last_active'] ?? null);
$display_status = $is_online ? 'Online' : ($user['status'] === 'Active' ? 'Away' : 'Inactive');

// Prepare variables for Sidebar
$current_name = $user['full_name'];
$current_role = $user['role'];
$current_profile = $user['profile_picture'];

// Helper for default photo (Logic from dashboard)
$names_split = explode(' ', trim($current_name));
$initials = strtoupper(substr($names_split[0], 0, 1) . substr($names_split[count($names_split) - 1], 0, 1));
$bg_colors = ['#8a11d4', '#6A1B9A', '#FFD700', '#10b981'];
$bg_color = $bg_colors[abs(crc32($current_name)) % count($bg_colors)];
$default_photo = ['bg_color' => $bg_color, 'text_color' => '#ffffff', 'initials' => $initials];
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>My Profile - Receptionist</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4", 
                        "royal-purple": "#6A1B9A",
                        "clinic-gold": "#FFD700",
                        "accent": "#FFD700",
                        "background-light": "#f7f6f8", "background-dark": "#1b1022",
                        "surface-light": "#ffffff", "surface-dark": "#2d1b36",
                        "text-main": "#151118", "text-sub": "#7a6189",
                    },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
        }
    </script>
    <style>
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #d1c4d9; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #8a11d4; }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-text-main dark:text-white font-display overflow-hidden">

<div class="flex h-screen w-full">
    <!-- RECEPTIONIST SIDEBAR -->
    <!-- RECEPTIONIST SIDEBAR -->
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
        
        <!-- Navigation -->
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
             <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_doctor_schedule.php">
                <span class="material-symbols-outlined">perm_contact_calendar</span>
                <span class="font-medium">Doctor Schedules</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_queue.php">
                <span class="material-symbols-outlined">monitor_heart</span>
                <span class="font-medium">Queue</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="receptionist_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-medium">Payments</span>
            </a>
        </nav>

        <div class="mt-auto pt-6 border-t border-white/10 p-4">
            <a href="../logout.php" class="flex w-full cursor-pointer items-center justify-start gap-3 px-4 py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors">
                <span class="material-symbols-outlined text-[20px]">logout</span>
                <span class="text-sm font-medium">Logout</span>
            </a>
        </div>
    </aside>

    <!-- CONTENT WRAPPER -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light dark:bg-background-dark relative">
        <!-- HEADER -->
        <header class="h-20 flex items-center justify-between px-8 bg-surface-light dark:bg-surface-dark border-b border-[#f3f0f4] dark:border-gray-800 flex-shrink-0 z-20">
            <div class="flex items-center gap-4">
                <h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">My Profile</h2>
            </div>
            <div class="flex items-center gap-6">
                 <a href="receptionist_edit_profile.php" class="flex items-center gap-2 px-4 py-2 bg-primary/10 hover:bg-primary/20 text-primary rounded-xl font-bold transition-colors text-sm">
                    <span class="material-symbols-outlined text-[20px]">edit</span>
                    <span>Edit</span>
                </a>
                <button class="btn-notifications relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                    <span class="material-symbols-outlined text-[22px]">notifications</span>
                    <span class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700"></span>
                </button>
            </div>
        </header>

        <!-- SCROLLABLE CONTENT -->
        <div class="flex-1 overflow-y-auto p-8 scroll-smooth">
            <div class="max-w-[1000px] mx-auto flex flex-col gap-8 pb-10">
                <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-8 shadow-sm border border-gray-100 dark:border-gray-800">
                    <div class="flex flex-col items-center mb-8 pb-8 border-b border-gray-200 dark:border-gray-700">
                        <?php
                        if ($user['profile_picture'] && file_exists('../' . $user['profile_picture'])) {
                            echo '<div class="w-32 h-32 rounded-full bg-cover bg-center border-4 border-primary mb-4 shadow-lg" style="background-image: url(\'../' . htmlspecialchars($user['profile_picture']) . '\');"></div>';
                        } else {
                            echo '<div class="w-32 h-32 rounded-full border-4 border-primary flex items-center justify-center text-white font-bold text-4xl mb-4 shadow-lg" style="background-color: ' . $bg_color . '; color: #fff;">' . $initials . '</div>';
                        }
                        ?>
                        <h3 class="text-2xl font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($user['full_name']); ?></h3>
                        <p class="text-sm text-primary font-semibold uppercase tracking-wider mt-2"><?php echo htmlspecialchars($user['role']); ?></p>
                        
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
                    </div>
                    
                    <div class="mt-8 pt-8 border-t border-gray-200 dark:border-gray-700 flex gap-4">
                        <a href="receptionist_edit_profile.php" class="flex-1 flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-primary hover:bg-primary/90 text-white text-sm font-bold shadow-lg shadow-primary/30 transition-all">
                            <span class="material-symbols-outlined text-[20px]">edit</span>
                            Edit Profile
                        </a>
                        <a href="receptionist_dashboard.php" class="flex items-center justify-center gap-2 px-6 py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-text-sub hover:text-text-main hover:bg-gray-50 dark:hover:bg-gray-800 text-sm font-bold transition-colors">
                            <span class="material-symbols-outlined text-[20px]">arrow_back</span>
                            Back to Dashboard
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>
</body>

