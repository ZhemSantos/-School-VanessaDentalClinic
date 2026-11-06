<?php
session_start();
require_once '../db.php';

// Prevent Caching
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Check Login
if (!isset($_SESSION['patient_id'])) {
    header("Location: ../landing.php");
    exit();
}

$patient_id = $_SESSION['patient_id'];
$patient_name = $_SESSION['patient_name'] ?? 'Patient';

// Fetch Patient Details
$patient = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pa.username, pa.created_at as account_created
        FROM tbl_patient p
        LEFT JOIN tbl_patient_accounts pa ON p.account_id = pa.account_id
        WHERE p.patient_id = ?
    ");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch();
} catch (Exception $e) {
    // Handle error
}

// Calculate Age if missing
if (empty($patient['age']) && !empty($patient['dob'])) {
    $patient['age'] = date_diff(date_create($patient['dob']), date_create('today'))->y;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Vanessa Nicolas Dental Clinic</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4",
                        "royal-purple": "#6A1B9A",
                        "clinic-gold": "#FFD700",
                        "background-light": "#f7f6f8",
                        "background-dark": "#1b1022",
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
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
    </style>
</head>
<body class="bg-background-light font-display antialiased overflow-hidden">

<div class="flex h-screen w-full">
    <!-- Sidebar -->
    <aside class="w-72 bg-royal-purple text-white flex flex-col flex-shrink-0 h-full shadow-2xl z-20 relative">
        <div class="p-8 pb-4">
            <a href="client_dashboard.php" class="flex flex-col gap-1">
                <h1 class="text-xl font-bold leading-tight tracking-wide">Vanessa Nicolas</h1>
                <p class="text-white/70 text-sm font-medium">Dental Clinic System</p>
            </a>
        </div>
        
        <!-- User Widget -->
        <div class="px-8 py-4 mb-2">
            <div class="flex items-center gap-3 bg-white/10 p-3 rounded-xl border border-white/10 backdrop-blur-sm">
                <div class="w-10 h-10 rounded-full bg-clinic-gold text-royal-purple flex items-center justify-center font-bold text-lg flex-shrink-0">
                    <?php echo strtoupper(substr($patient_name, 0, 1)); ?>
                </div>
                <div class="flex flex-col overflow-hidden">
                    <span class="text-sm font-bold truncate"><?php echo htmlspecialchars($patient_name); ?></span>
                    <span class="text-xs text-white/70 truncate">ID: <?php echo str_pad($patient_id, 4, '0', STR_PAD_LEFT); ?></span>
                </div>
            </div>
        </div>
        
        <!-- Navigation -->
        <nav class="flex-1 px-4 gap-2 flex flex-col overflow-y-auto py-4">
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_dashboard.php">
                <span class="material-symbols-outlined">dashboard</span>
                <span class="font-medium">Dashboard</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_appointment.php">
                <span class="material-symbols-outlined">calendar_month</span>
                <span class="font-medium">Appointments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_payment.php">
                <span class="material-symbols-outlined">payments</span>
                <span class="font-medium">Payments</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_feedback.php">
                <span class="material-symbols-outlined">reviews</span>
                <span class="font-medium">Feedback</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_medical_records.php">
                <span class="material-symbols-outlined">medical_information</span>
                <span class="font-medium">Medical Records</span>
            </a>
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl hover:bg-white/10 text-white/90 hover:text-white transition-all group" href="client_dependencies.php">
                <span class="material-symbols-outlined">group</span>
                <span class="font-medium">My Dependents</span>
            </a>
        </nav>
        
        <!-- Footer -->
        <div class="p-6">
            <div class="text-xs text-white/40 text-center font-medium">v2.0.0 • Client Portal</div>
            <a href="../logout.php" class="flex items-center justify-center gap-2 mt-4 w-full py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium">
                <span class="material-symbols-outlined text-[18px]">logout</span>
                Logout
            </a>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
        <!-- Header -->
        <header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
            <div class="flex flex-col gap-1">
                <h2 class="text-3xl font-black text-[#151118] tracking-tight">My Profile</h2>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">person</span>
                    View and manage your personal information
                </p>
            </div>
            <div class="flex items-center gap-4">
                <a href="client_update_info.php" class="h-12 px-6 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
                    <span class="material-symbols-outlined">edit</span>
                    <span>Edit Profile</span>
                </a>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-[1200px] mx-auto flex flex-col gap-6">
                
                <!-- Profile Header Card -->
                <div class="bg-gradient-to-br from-royal-purple to-purple-800 rounded-2xl shadow-lg p-8 text-white">
                    <div class="flex items-center gap-6">
                        <div class="w-24 h-24 rounded-full bg-clinic-gold text-royal-purple flex items-center justify-center font-bold text-4xl flex-shrink-0 shadow-xl">
                            <?php echo strtoupper(substr($patient['first_name'], 0, 1)); ?>
                        </div>
                        <div class="flex-1">
                            <h1 class="text-3xl font-bold mb-2"><?php echo htmlspecialchars($patient['first_name'] . ' ' . ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . $patient['last_name']); ?></h1>
                            <div class="flex items-center gap-4 text-sm">
                                <span class="bg-white/20 px-3 py-1 rounded-full">Patient ID: VN-<?php echo str_pad($patient['patient_id'], 4, '0', STR_PAD_LEFT); ?></span>
                                <span class="bg-white/20 px-3 py-1 rounded-full">Member since <?php echo date('M Y', strtotime($patient['account_created'] ?? $patient['created_at'])); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Information Grid -->
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    <!-- Personal Information -->
                    <div class="lg:col-span-2 bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                        <h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
                                <span class="material-symbols-outlined text-sm">badge</span>
                            </span>
                            Personal Information
                        </h3>
                        
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Full Name</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['first_name'] . ' ' . ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . $patient['last_name']); ?></p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Date of Birth</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo date('F d, Y', strtotime($patient['dob'])); ?> (<?php echo $patient['age']; ?> yrs)</p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Gender</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['gender']); ?></p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Civil Status</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['civil_status'] ?? 'Not specified'); ?></p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Occupation</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['occupation'] ?? 'Not specified'); ?></p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Blood Type</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['blood_type'] ?? 'Unknown'); ?></p>
                            </div>
                        </div>

                        <hr class="my-6 border-gray-200">

                        <h4 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
                            <span class="material-symbols-outlined text-royal-purple">contact_phone</span>
                            Contact Information
                        </h4>
                        
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Mobile Number</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['phone_number']); ?></p>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Email Address</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['email'] ?? 'Not provided'); ?></p>
                            </div>
                            <div class="col-span-2">
                                <p class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-1">Home Address</p>
                                <p class="text-sm font-medium text-slate-900"><?php echo htmlspecialchars($patient['address']); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Medical Information -->
                    <div class="flex flex-col gap-6">
                        <div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
                            <h3 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-red-600">medical_information</span>
                                Medical History
                            </h3>
                            <div class="bg-red-50 border border-red-100 rounded-xl p-4">
                                <p class="text-sm text-slate-700 whitespace-pre-wrap">
                                    <?php echo !empty($patient['medical_history']) ? nl2br(htmlspecialchars($patient['medical_history'])) : 'No major known allergies or conditions recorded.'; ?>
                                </p>
                            </div>
                        </div>

                        <div class="bg-blue-50 border border-blue-100 rounded-2xl p-6">
                            <div class="flex items-start gap-3">
                                <span class="material-symbols-outlined text-blue-600 text-2xl">info</span>
                                <div>
                                    <p class="text-sm font-bold text-blue-900 mb-1">Need to Update?</p>
                                    <p class="text-xs text-blue-700">Contact the reception desk or call us to update your medical history and contact details.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </main>
</div>

</body>
</html>
