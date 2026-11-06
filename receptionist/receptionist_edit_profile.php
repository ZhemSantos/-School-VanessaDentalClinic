<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role
requireRole('Receptionist');
updateUserActivity();

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// Fetch current user
try {
    $stmt = $pdo->prepare("SELECT * FROM tbl_user WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    // Prepare variables for Sidebar (shared logic)
    $current_name = $user['full_name'];
    $current_role = $user['role'];
    $current_profile = $user['profile_picture'];
    
    $names_split = explode(' ', trim($current_name));
    $initials = strtoupper(substr($names_split[0], 0, 1) . substr($names_split[count($names_split) - 1], 0, 1));
    $bg_colors = ['#8a11d4', '#6A1B9A', '#FFD700', '#10b981'];
    $bg_color = $bg_colors[abs(crc32($current_name)) % count($bg_colors)];
    $default_photo = ['bg_color' => $bg_color, 'text_color' => '#ffffff', 'initials' => $initials];

} catch (PDOException $e) {
    die("Error fetching profile.");
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name = sanitizeInput($_POST['full_name']);
    $username = sanitizeInput($_POST['username']);
    $email = sanitizeInput($_POST['email']);
    $contact_number = sanitizeInput($_POST['contact_number']);
    
    // Validation
    if (empty($full_name) || empty($username)) {
        $error = "Name and Username are required.";
    } else {
        try {
            // Check for duplicate username/email excluding current user
            $check = $pdo->prepare("SELECT user_id FROM tbl_user WHERE (username = ? OR email = ?) AND user_id != ?");
            $check->execute([$username, $email, $user_id]);
            if ($check->rowCount() > 0) {
                $error = "Username or Email already exists.";
            } else {
                // Update Base Info
                $sql = "UPDATE tbl_user SET full_name = ?, username = ?, email = ?, contact_number = ? WHERE user_id = ?";
                $params = [$full_name, $username, $email, $contact_number, $user_id];
                $update_stmt = $pdo->prepare($sql);
                $update_stmt->execute($params);

                // Handle Profile Picture
                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/profiles/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    
                    $fileExt = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                    $validExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    
                    if (in_array($fileExt, $validExts)) {
                        $newFileName = 'profile_' . $user_id . '_' . time() . '.' . $fileExt;
                        $destPath = $uploadDir . $newFileName;
                        
                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $destPath)) {
                            // Update DB path (relative)
                            $dbPath = 'uploads/profiles/' . $newFileName;
                            $pic_stmt = $pdo->prepare("UPDATE tbl_user SET profile_picture = ? WHERE user_id = ?");
                            $pic_stmt->execute([$dbPath, $user_id]);
                            
                            // Delete old if exists
                            if (!empty($user['profile_picture']) && file_exists('../' . $user['profile_picture'])) {
                                unlink('../' . $user['profile_picture']);
                            }
                            $user['profile_picture'] = $dbPath; // Update local var for display
                        }
                    } else {
                        $error = "Invalid image format.";
                    }
                }

                if (empty($error)) {
                    $success = "Profile updated successfully!";
                    // Refresh user data
                    $stmt->execute([$user_id]);
                    $user = $stmt->fetch();
                }
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Edit Profile - Receptionist</title>
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
                <a href="receptionist_current_profile.php" class="p-2 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub transition-colors">
                     <span class="material-symbols-outlined">arrow_back</span>
                </a>
                <h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Edit Profile</h2>
            </div>
            <div class="flex items-center gap-6">
                <!-- Notifications (Static) -->
                <button class="btn-notifications relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
                    <span class="material-symbols-outlined text-[22px]">notifications</span>
                    <span class="absolute top-2 right-2.5 w-2 h-2 bg-red-500 rounded-full border-2 border-white dark:border-gray-700"></span>
                </button>
            </div>
        </header>

        <!-- SCROLLABLE CONTENT -->
        <div class="flex-1 overflow-y-auto p-8 scroll-smooth">
            <div class="max-w-4xl mx-auto flex flex-col gap-8 pb-10">
                
                <header class="flex items-center justify-between">
                    <div>
                        <h1 class="text-3xl font-bold text-text-main dark:text-white">Update Info</h1>
                        <p class="text-text-sub">Manage your personal details</p>
                    </div>
                </header>

                <?php if ($error): ?>
                    <div class="p-4 rounded-xl bg-red-50 text-red-700 border border-red-200 flex items-center gap-3 animate-pulse">
                        <span class="material-symbols-outlined">error</span>
                        <span class="font-bold"><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="p-4 rounded-xl bg-green-50 text-green-700 border border-green-200 flex items-center gap-3">
                        <span class="material-symbols-outlined">check_circle</span>
                        <span class="font-bold"><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="bg-surface-light dark:bg-surface-dark rounded-2xl p-8 shadow-sm border border-gray-100 dark:border-gray-800 grid grid-cols-1 md:grid-cols-3 gap-8">
                    
                    <!-- Profile Picture Col -->
                    <div class="col-span-1 flex flex-col items-center pt-4">
                        <div class="relative group mb-6">
                            <?php if ($user['profile_picture'] && file_exists('../' . $user['profile_picture'])): ?>
                                <div class="w-48 h-48 rounded-full bg-cover bg-center border-4 border-primary/20 shadow-2xl" style="background-image: url('../<?php echo htmlspecialchars($user['profile_picture']); ?>');"></div>
                            <?php else: ?>
                                <div class="w-48 h-48 rounded-full bg-primary/5 flex items-center justify-center text-primary border-4 border-primary/10 shadow-2xl">
                                    <span class="material-symbols-outlined text-[80px]">person</span>
                                </div>
                            <?php endif; ?>
                            
                            <label class="absolute bottom-2 right-2 w-12 h-12 flex items-center justify-center bg-primary text-white rounded-full cursor-pointer hover:bg-primary/90 transition-all shadow-lg hover:scale-105 active:scale-95 border-4 border-white dark:border-surface-dark">
                                <span class="material-symbols-outlined text-xl">photo_camera</span>
                                <input type="file" name="profile_picture" accept="image/*" class="hidden" onchange="document.querySelector('form').submit()">
                            </label>
                        </div>
                        <p class="text-xs text-center text-text-sub font-medium uppercase tracking-wide">Allowed: JPG, PNG, WEBP<br>Max 5MB</p>
                    </div>

                    <!-- Fields Col -->
                    <div class="col-span-1 md:col-span-2 space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                             <div class="space-y-2">
                                <label class="text-xs font-bold text-text-sub uppercase tracking-wider">Full Name</label>
                                <input type="text" name="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" 
                                       class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-text-main dark:text-white font-bold focus:border-primary focus:ring-primary focus:bg-white transition-all">
                            </div>
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-text-sub uppercase tracking-wider">Username</label>
                                <input type="text" name="username" value="<?php echo htmlspecialchars($user['username']); ?>" 
                                       class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-text-main dark:text-white font-bold focus:border-primary focus:ring-primary focus:bg-white transition-all">
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-text-sub uppercase tracking-wider">Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" 
                                       class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-text-main dark:text-white font-bold focus:border-primary focus:ring-primary focus:bg-white transition-all">
                            </div>
                            <div class="space-y-2">
                                <label class="text-xs font-bold text-text-sub uppercase tracking-wider">Contact Number</label>
                                <input type="text" name="contact_number" value="<?php echo htmlspecialchars($user['contact_number']); ?>" 
                                       class="w-full px-4 py-3 rounded-xl border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/50 text-text-main dark:text-white font-bold focus:border-primary focus:ring-primary focus:bg-white transition-all">
                            </div>
                        </div>

                        <div class="pt-8 border-t border-gray-100 dark:border-gray-800 flex justify-end gap-3">
                            <a href="receptionist_current_profile.php" class="px-6 py-3 rounded-xl border border-gray-200 dark:border-gray-700 text-text-sub font-bold hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">Cancel</a>
                            <button type="submit" class="px-8 py-3 rounded-xl bg-primary text-white font-bold hover:bg-primary/90 shadow-lg shadow-primary/30 transition-all transform hover:-translate-y-0.5 active:translate-y-0">
                                Save Changes
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>
</body>
</html>
