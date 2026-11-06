<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Dentist role
requireRole('Dentist');
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
                        }
                    } else {
                        $error = "Invalid image format.";
                    }
                }
                
                // Handle PRC License File
                if (isset($_FILES['prc_license_file']) && $_FILES['prc_license_file']['error'] === UPLOAD_ERR_OK) {
                    $licenseDir = '../uploads/licenses/';
                    if (!is_dir($licenseDir)) mkdir($licenseDir, 0755, true);
                    
                    $fileExt = strtolower(pathinfo($_FILES['prc_license_file']['name'], PATHINFO_EXTENSION));
                    $validExts = ['jpg', 'jpeg', 'png', 'pdf', 'webp'];
                    
                    if (in_array($fileExt, $validExts)) {
                        $newFileName = 'license_' . $user_id . '_' . time() . '.' . $fileExt;
                        $destPath = $licenseDir . $newFileName;
                        
                        if (move_uploaded_file($_FILES['prc_license_file']['tmp_name'], $destPath)) {
                            $dbPath = 'uploads/licenses/' . $newFileName;
                            // Update prc_license_number column with the file path
                            $lic_stmt = $pdo->prepare("UPDATE tbl_user SET prc_license_number = ? WHERE user_id = ?");
                            $lic_stmt->execute([$dbPath, $user_id]);
                            
                             // Delete old if exists (and is a valid file, not just 'UPLOADED' string)
                            if (!empty($user['prc_license_number']) && file_exists('../' . $user['prc_license_number'])) {
                                unlink('../' . $user['prc_license_number']);
                            }
                        }
                    } else {
                        $error = "Invalid license file format (JPG, PNG, PDF only).";
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
    <title>Edit Profile - Doctor</title>
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
                        "secondary": "#FFD700",
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
    <!-- DOCTOR SIDEBAR -->
    <aside class="w-72 flex-shrink-0 flex flex-col bg-white dark:bg-gray-800 border-r border-[#e2dbe6] dark:border-gray-700 transition-colors duration-200">
        <div class="flex flex-col h-full p-6">
            <div class="flex gap-3 items-center">
                <div class="bg-center bg-no-repeat bg-cover rounded-xl size-12 shrink-0 shadow-md border-2 border-primary/10" style='background-image: url("https://lh3.googleusercontent.com/aida-public/AB6AXuDVLK7T7qRCxOBG-KaXUiV7VW5S5DR2zmQCVv6YRrNOrUS38YGhDstNQjB3IS0WM1pZK9Wo4EHloZ0lx0FQGmj-rZI4gSVFeJTXSjATIJ_tYqqzdo5pdNJ1pksXN6aRO3aeGfXmoEGKDAHJBMYm7CCJnmtPX1pi-uNNh_4RwjGbI6iAxHe6j1e3lNXsLFupAxH4fZU835iEQPEfoQiIO8RNnlF9NY6cD7wJbJ6d_j2M9IrAx20oMjkbfg8QwQadAORe5tubT-S5tKg");'></div>
                <div class="flex flex-col">
                    <h1 class="text-[#151118] text-lg font-bold leading-tight">Vanessa Nicolas</h1>
                    <p class="text-[#7a6189] text-xs font-medium uppercase tracking-wider">Dental Clinic</p>
                </div>
            </div>
            
            <nav class="flex flex-col gap-2 flex-1 mt-6">
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-[#7a6189] dark:text-gray-300 hover:bg-[#f8f9fa] dark:hover:bg-gray-700/50 transition-all group" href="doctor_dashboard.php">
                    <span class="material-symbols-outlined group-hover:text-primary transition-colors">dashboard</span>
                    <p class="text-sm font-medium leading-normal">Dashboard</p>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-[#7a6189] dark:text-gray-300 hover:bg-[#f8f9fa] dark:hover:bg-gray-700/50 transition-all group" href="doctor_patients.php">
                    <span class="material-symbols-outlined group-hover:text-primary transition-colors">groups</span>
                    <p class="text-sm font-medium leading-normal">My Patients</p>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-[#7a6189] dark:text-gray-300 hover:bg-[#f8f9fa] dark:hover:bg-gray-700/50 transition-all group" href="doctor_records.php">
                    <span class="material-symbols-outlined group-hover:text-primary transition-colors">folder_shared</span>
                    <p class="text-sm font-medium leading-normal">Medical Records</p>
                </a>
                <a class="flex items-center gap-3 px-4 py-3 rounded-xl text-[#7a6189] dark:text-gray-300 hover:bg-[#f8f9fa] dark:hover:bg-gray-700/50 transition-all group" href="doctor_schedule.php">
                    <span class="material-symbols-outlined group-hover:text-primary transition-colors">calendar_month</span>
                    <p class="text-sm font-medium leading-normal">My Schedule</p>
                </a>
            </nav>
            
            <div class="mt-auto pt-6 border-t border-gray-100 dark:border-gray-700 flex flex-col gap-4">
                <a href="../logout.php" class="flex w-full cursor-pointer items-center justify-start gap-3 px-4 py-2 rounded-xl text-[#7a6189] dark:text-gray-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                    <span class="material-symbols-outlined text-[20px]">logout</span>
                    <span class="text-sm font-medium">Logout</span>
                </a>
            </div>
        </div>
    </aside>

    <!-- CONTENT WRAPPER -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light dark:bg-background-dark relative">
        <!-- HEADER -->
        <header class="h-20 flex items-center justify-between px-8 bg-surface-light dark:bg-surface-dark border-b border-[#f3f0f4] dark:border-gray-800 flex-shrink-0 z-20">
            <div class="flex items-center gap-4">
                <a href="doctor_current_user.php" class="p-2 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-800 text-text-sub transition-colors">
                     <span class="material-symbols-outlined">arrow_back</span>
                </a>
                <h2 class="text-text-main dark:text-white text-xl font-bold leading-tight tracking-tight">Edit Profile</h2>
            </div>
            <div class="flex items-center gap-6">
                <!-- Notifications -->
                <button class="relative flex items-center justify-center w-10 h-10 rounded-xl bg-surface-light dark:bg-gray-700 border border-gray-200 dark:border-gray-600 text-text-main dark:text-white hover:bg-gray-50 dark:hover:bg-gray-600 transition-colors">
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
                        
                        <!-- Doctor Specific: PRC License -->
                        <div class="space-y-4 border-t border-gray-100 dark:border-gray-700 pt-6">
                            <h4 class="text-sm font-bold text-primary uppercase tracking-wider flex items-center gap-2">
                                <span class="material-symbols-outlined">badge</span> Professional License
                            </h4>

                            <!-- PRC License Number Input Removed -->

                            <div class="space-y-2">
                                <label class="text-xs font-bold text-text-sub uppercase tracking-wider">License ID / Proof (Image)</label>
                                <div class="flex items-center gap-4">
                                    <?php if (!empty($user['prc_license_number']) && file_exists('../' . $user['prc_license_number'])): ?>
                                        <div class="relative group">
                                            <a href="../<?php echo htmlspecialchars($user['prc_license_number']); ?>" target="_blank" class="block w-20 h-20 rounded-xl bg-cover bg-center border-2 border-gray-200 dark:border-gray-700 shadow-sm transition-transform hover:scale-105" style="background-image: url('../<?php echo htmlspecialchars($user['prc_license_number']); ?>');"></a>
                                            <div class="absolute -top-2 -right-2 bg-green-500 text-white text-[10px] font-bold px-2 py-0.5 rounded-full shadow-sm">UPLOADED</div>
                                        </div>
                                    <?php elseif ($user['prc_license_number'] === 'UPLOADED'): ?>
                                        <div class="flex items-center gap-2 px-3 py-2 bg-blue-50 text-blue-600 rounded-lg border border-blue-100">
                                            <span class="material-symbols-outlined text-lg">verified</span>
                                            <span class="text-xs font-bold">Status: UPLOADED</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="flex-1">
                                        <input type="file" name="prc_license_file" accept=".jpg,.jpeg,.png,.pdf" 
                                               class="w-full text-sm text-text-sub file:mr-4 file:py-2.5 file:px-4 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-primary/10 file:text-primary hover:file:bg-primary/20 cursor-pointer border border-dashed border-gray-300 dark:border-gray-700 rounded-xl p-2 transition-colors hover:border-primary hover:bg-gray-50 dark:hover:bg-gray-800/50">
                                        <p class="text-[10px] text-text-sub mt-1.5 ml-1">Upload recent ID scan. Expires every 3 years.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end pt-4">
                            <button type="submit" class="px-8 py-3 bg-primary hover:bg-primary/90 text-white font-bold rounded-xl shadow-lg shadow-primary/30 transition-all flex items-center gap-2">
                                <span class="material-symbols-outlined">save</span>
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
