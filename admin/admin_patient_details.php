<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

configureSecureSession();
requireRole('Admin');
updateUserActivity();

$patient_id = $_GET['id'] ?? 0;
if(!$patient_id) { header("Location: admin_patients.php"); exit; }

// Fetch Patient Details
$stmt = $pdo->prepare("SELECT p.*, TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
                       gp.first_name as guardian_first, gp.last_name as guardian_last, 
                       gp.email as guardian_email, gp.phone_number as guardian_phone
                       FROM tbl_patient p
                       LEFT JOIN tbl_dependencies d ON p.patient_id = d.sub_patient_id
                       LEFT JOIN tbl_patient gp ON gp.account_id = d.guardian_account_id
                       WHERE p.patient_id = ?");
$stmt->execute([$patient_id]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$p) { header("Location: admin_patients.php"); exit; }

// Fetch Appointment History
$stmt = $pdo->prepare("
    SELECT a.*, u.full_name as doctor_name, 
           (SELECT GROUP_CONCAT(s.service_name SEPARATOR ', ') FROM tbl_appointment_service tas JOIN tbl_service s ON tas.service_id = s.service_id WHERE tas.appointment_id = a.appointment_id) as services
    FROM tbl_appointment a
    LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
    WHERE a.patient_id = ?
    ORDER BY a.appointment_date DESC
");
$stmt->execute([$patient_id]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for Profile Photo
function getDefaultProfilePhoto($full_name) {
    if(empty($full_name)) $full_name = "User";
    $initials = strtoupper(substr($full_name,0,1));
    return ['initials'=>$initials, 'bg_color'=>'#8a11d4', 'text_color'=>'#fff'];
}
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $stmt = $pdo->prepare("SELECT user_id, full_name, role, profile_picture FROM tbl_user WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $stmt->fetch();
    } catch (PDOException $e) {}
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title><?php echo htmlspecialchars($p['first_name']); ?> - Patient Details</title>
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
        <header class="h-20 flex items-center justify-between px-8 bg-surface-light dark:bg-surface-dark border-b border-gray-100 dark:border-gray-800 z-10">
            <div class="flex items-center gap-4">
                <a href="admin_patients.php" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 text-text-sub transition"><span class="material-symbols-outlined">arrow_back</span></a>
                <h2 class="text-xl font-bold text-text-main dark:text-white">Patient Profile</h2>
            </div>
            <div class="text-sm font-bold text-primary bg-primary/10 px-4 py-2 rounded-xl">ID: <?php echo str_pad($p['patient_id'], 5, '0', STR_PAD_LEFT); ?></div>
        </header>

        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-[1400px] mx-auto grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Left Column: Demographics -->
                <div class="space-y-6">
                    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-800">
                        <div class="flex flex-col items-center mb-6">
                            <div class="w-24 h-24 rounded-full bg-primary/10 text-primary flex items-center justify-center text-4xl font-bold mb-4">
                                <?php echo strtoupper(substr($p['first_name'],0,1)); ?>
                            </div>
                            <h3 class="text-xl font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></h3>
                            <p class="text-text-sub"><?php echo $p['age']; ?> Years Old</p>
                        </div>
                        
                        <div class="space-y-4">
                            <div>
                                <label class="text-xs font-bold text-text-sub uppercase">Date of Birth</label>
                                <p class="text-sm font-semibold text-text-main dark:text-white"><?php echo date('F d, Y', strtotime($p['dob'])); ?></p>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-text-sub uppercase">Gender</label>
                                <p class="text-sm font-semibold text-text-main dark:text-white capitalize"><?php echo htmlspecialchars($p['gender']); ?></p>
                            </div>
                            
                            <!-- Guardian Info for Dependents -->
                            <?php if(!empty($p['guardian_first'])): ?>
                                <div class="pt-2 border-t border-gray-100 dark:border-gray-800">
                                    <label class="text-xs font-bold text-primary uppercase">Guardian Contact</label>
                                    <p class="text-sm font-bold text-text-main dark:text-white"><?php echo htmlspecialchars($p['guardian_first'].' '.$p['guardian_last']); ?></p>
                                    <div class="flex flex-col mt-0.5">
                                        <?php if(!empty($p['guardian_phone'])): ?>
                                            <p class="text-xs text-text-sub flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">call</span> <?php echo htmlspecialchars($p['guardian_phone']); ?></p>
                                        <?php endif; ?>
                                        <?php if(!empty($p['guardian_email'])): ?>
                                            <p class="text-xs text-text-sub flex items-center gap-1"><span class="material-symbols-outlined text-[12px]">mail</span> <?php echo htmlspecialchars($p['guardian_email']); ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <div>
                                <label class="text-xs font-bold text-text-sub uppercase">Contact Number</label>
                                <p class="text-sm font-semibold text-text-main dark:text-white"><?php echo htmlspecialchars($p['contact_number']); ?></p>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-text-sub uppercase">Email Address</label>
                                <p class="text-sm font-semibold text-text-main dark:text-white truncate"><?php echo htmlspecialchars($p['email']); ?></p>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-text-sub uppercase">Address</label>
                                <p class="text-sm font-semibold text-text-main dark:text-white"><?php echo htmlspecialchars($p['address']); ?></p>
                            </div>
                            <div>
                                <label class="text-xs font-bold text-text-sub uppercase">Registered On</label>
                                <p class="text-sm font-semibold text-text-main dark:text-white"><?php echo date('M d, Y', strtotime($p['created_at'])); ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column: Appointment History -->
                <div class="lg:col-span-2 space-y-6">
                    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl p-6 shadow-sm border border-gray-100 dark:border-gray-800 h-full">
                        <h3 class="text-lg font-bold text-text-main dark:text-white mb-6">Appointment History</h3>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead class="bg-gray-50 dark:bg-gray-800/50">
                                    <tr>
                                        <th class="p-4 text-xs font-bold text-text-sub uppercase">Date & Time</th>
                                        <th class="p-4 text-xs font-bold text-text-sub uppercase">Doctor & Service</th>
                                        <th class="p-4 text-xs font-bold text-text-sub uppercase text-right">Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php if(empty($appointments)): ?>
                                        <tr><td colspan="3" class="p-8 text-center text-text-sub">No appointment history found.</td></tr>
                                    <?php else: ?>
                                        <?php foreach($appointments as $a): 
                                            $status_colors = [
                                                'Completed' => 'bg-green-100 text-green-700',
                                                'Pending' => 'bg-yellow-100 text-yellow-700',
                                                'Booked' => 'bg-blue-100 text-blue-700',
                                                'Cancelled' => 'bg-red-100 text-red-700',
                                                'No-Show' => 'bg-gray-100 text-gray-700'
                                            ];
                                            $st_color = $status_colors[$a['status']] ?? 'bg-gray-100';
                                        ?>
                                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/20">
                                            <td class="p-4">
                                                <p class="font-bold text-sm text-text-main dark:text-white"><?php echo date('M d, Y', strtotime($a['appointment_date'])); ?></p>
                                                <p class="text-xs text-text-sub"><?php echo date('h:i A', strtotime($a['time_slot'])); ?></p>
                                            </td>
                                            <td class="p-4">
                                                <p class="font-bold text-sm text-text-main dark:text-white"><?php echo htmlspecialchars($a['doctor_name'] ?? 'Unassigned'); ?></p>
                                                <p class="text-xs text-text-sub truncate max-w-[200px]"><?php echo htmlspecialchars($a['services'] ?? 'General Checkup'); ?></p>
                                            </td>
                                            <td class="p-4 text-right">
                                                <span class="px-2 py-1 rounded text-xs font-bold <?php echo $st_color; ?>"><?php echo htmlspecialchars($a['status']); ?></span>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>
</body>
</html>
