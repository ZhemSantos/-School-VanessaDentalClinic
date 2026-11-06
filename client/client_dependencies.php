<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['patient_id'])) {
    header("Location: ../landing.php");
    exit;
}

$patient_name = $_SESSION['patient_name'] ?? 'Patient';
$patient_id = $_SESSION['patient_id'] ?? 0;


// Check if current user is a dependent
$isDependent = false;
try {
    $depCheckStmt = $pdo->prepare("
        SELECT d.*, g.first_name as guardian_fname, g.last_name as guardian_lname, g.patient_id as guardian_patient_id
        FROM tbl_dependencies d
        JOIN tbl_patient_accounts pa ON d.guardian_account_id = pa.account_id
        JOIN tbl_patient g ON pa.patient_id = g.patient_id
        WHERE d.sub_patient_id = ?
    ");
    $depCheckStmt->execute([$patient_id]);
    $isDependent = $depCheckStmt->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table doesn't exist yet or query failed - user is not a dependent
    $isDependent = false;
}

// Get parent's contact info for optional use by dependents
$stmt = $pdo->prepare("SELECT phone_number, email, address FROM tbl_patient WHERE patient_id = ?");
$stmt->execute([$patient_id]);
$parent_info = $stmt->fetch(PDO::FETCH_ASSOC);
$parent_phone = $parent_info['phone_number'] ?? '';
$parent_email = $parent_info['email'] ?? '';
$parent_address = $parent_info['address'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Dependents - Vanessa Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "royal-purple": "#6A1B9A",
                        "clinic-gold": "#FFD700",
                        "background-light": "#f7f6f8",
                    },
                    fontFamily: {
                        "display": ["Manrope", "sans-serif"]
                    },
                },
            },
        }
    </script>
    <style>
        .scrollbar-hide::-webkit-scrollbar { display: none; }
        .scrollbar-hide { -ms-overflow-style: none; scrollbar-width: none; }
        
        @media print {
            body * { visibility: hidden; }
            #consentPrintArea, #consentPrintArea * { visibility: visible; }
            #consentPrintArea { position: absolute; left: 0; top: 0; width: 100%; padding: 40px; }
        }
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
            <a href="client_profile_info.php" class="block">
                <div class="flex items-center gap-3 bg-white/10 p-3 rounded-xl border border-white/10 backdrop-blur-sm hover:bg-white/20 transition-all cursor-pointer">
                    <div class="w-10 h-10 rounded-full bg-clinic-gold text-royal-purple flex items-center justify-center font-bold text-lg flex-shrink-0">
                        <?php echo strtoupper(substr($patient_name, 0, 1)); ?>
                    </div>
                <div class="flex flex-col overflow-hidden">
                    <span class="text-sm font-bold truncate"><?php echo htmlspecialchars($patient_name); ?></span>
                    <span class="text-xs text-white/70 truncate">ID: <?php echo str_pad($patient_id, 4, '0', STR_PAD_LEFT); ?></span>
                </div>
            </div>
            </a>
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
            <a class="flex items-center gap-4 px-4 py-3 rounded-xl bg-white text-royal-purple shadow-lg transition-all group" href="client_dependencies.php">
                <span class="material-symbols-outlined">group</span>
                <span class="font-bold">My Dependents</span>
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
                <h2 class="text-3xl font-black text-[#151118] tracking-tight">
                    <?php echo $isDependent ? 'My Guardian' : 'My Dependents'; ?>
                </h2>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">
                        <?php echo $isDependent ? 'shield_person' : 'group'; ?>
                    </span>
                    <?php echo $isDependent ? 'View your guardian information.' : 'Manage family members and children.'; ?>
                </p>
            </div>
            <?php if (!$isDependent): ?>
            <button onclick="openAddModal()" class="h-12 px-6 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
                <span class="material-symbols-outlined">person_add</span>
                <span>Add Dependent</span>
            </button>
            <?php endif; ?>
        </header>

        <!-- Content Area -->
        <div class="flex-1 overflow-y-auto px-8 py-6 scrollbar-hide">
            <?php if ($isDependent): ?>
                <!-- Guardian Information Card -->
                <div class="max-w-2xl mx-auto">
                    <div class="bg-white p-8 rounded-2xl border border-gray-100 shadow-lg">
                        <div class="flex items-center gap-4 mb-6">
                            <div class="w-16 h-16 rounded-full bg-gradient-to-br from-purple-500 to-blue-500 text-white flex items-center justify-center font-bold text-2xl shadow-lg">
                                <?php echo strtoupper(substr($isDependent['guardian_fname'], 0, 1)); ?>
                            </div>
                            <div>
                                <h3 class="text-2xl font-bold text-slate-900">
                                    <?php echo htmlspecialchars($isDependent['guardian_fname'] . ' ' . $isDependent['guardian_lname']); ?>
                                </h3>
                                <p class="text-purple-600 font-bold uppercase text-sm tracking-wide">Your Guardian</p>
                            </div>
                        </div>
                        
                        <div class="space-y-4">
                            <div class="p-4 bg-slate-50 rounded-xl">
                                <p class="text-xs text-slate-500 mb-1">Relationship</p>
                                <p class="font-bold text-slate-900"><?php echo htmlspecialchars($isDependent['relationship']); ?></p>
                            </div>
                            
                            <?php if (!$isDependent['access_verified']): ?>
                            <div class="p-4 bg-yellow-50 border border-yellow-200 rounded-xl">
                                <p class="text-sm text-yellow-800">
                                    <strong>⚠️ Pending Verification:</strong> Your guardian's access to your account requires your verification. Please check your email for the verification link.
                                </p>
                            </div>
                            <?php else: ?>
                            <div class="p-4 bg-green-50 border border-green-200 rounded-xl">
                                <p class="text-sm text-green-800">
                                    <strong>✓ Verified:</strong> Your guardian has access to manage your account.
                                </p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <!-- Dependents List -->
                <div id="dependentContainer" class="grid gap-6 md:grid-cols-2 lg:grid-cols-3 mb-8">
                    <div class="animate-pulse bg-white h-40 rounded-2xl"></div>
                </div>
                
                <!-- Warning -->
                <div class="p-4 bg-gradient-to-r from-orange-50 to-amber-50 border border-orange-100 rounded-2xl flex items-start gap-4">
                    <div class="w-12 h-12 bg-orange-100 text-orange-600 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-2xl">info</span>
                    </div>
                    <div>
                        <h3 class="font-bold text-orange-900 mb-1">Important Note</h3>
                        <p class="text-sm text-orange-800">
                            You must be at least 18 years old to add dependents. Creating a dependent account requires you to sign a Parent Consent Form, which you can generate and print below.
                        </p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- Add Modal -->
<div id="addModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeAddModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-2xl max-h-[90vh] rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <div class="p-6 border-b border-gray-100 bg-gradient-to-r from-royal-purple to-purple-700 flex justify-between items-center">
            <div>
                <h2 class="text-xl font-black text-white">Add New Dependent</h2>
                <p class="text-sm text-white/80">Complete all required fields</p>
            </div>
            <button onclick="closeAddModal()" class="w-8 h-8 rounded-full bg-white/20 text-white hover:bg-white/30 flex items-center justify-center transition-all">
                <span class="material-symbols-outlined text-lg">close</span>
            </button>
        </div>
        
        <form id="addForm" onsubmit="event.preventDefault(); submitDependent();" class="flex-1 overflow-y-auto p-6 space-y-6 scrollbar-hide">
            <!-- Name Fields -->
            <div class="grid grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">First Name *</label>
                    <input type="text" id="fname" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Middle Name</label>
                    <input type="text" id="mname" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Last Name *</label>
                    <input type="text" id="lname" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                </div>
            </div>

            <!-- DOB & Sex -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Date of Birth *</label>
                    <input type="date" id="dob" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Sex *</label>
                    <select id="gender" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                        <option value="">Select Sex</option>
                        <option value="Male">Male</option>
                        <option value="Female">Female</option>
                    </select>
                </div>
            </div>

            <!-- Civil Status & Blood Type -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Civil Status *</label>
                    <select id="civil_status" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                        <option value="">Select Status</option>
                        <option value="Single">Single</option>
                        <option value="Married">Married</option>
                        <option value="Widowed">Widowed</option>
                        <option value="Separated">Separated</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Blood Type *</label>
                    <select id="blood_type" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                        <option value="">Select Blood Type</option>
                        <option value="A+">A+</option>
                        <option value="A-">A-</option>
                        <option value="B+">B+</option>
                        <option value="B-">B-</option>
                        <option value="O+">O+</option>
                        <option value="O-">O-</option>
                        <option value="AB+">AB+</option>
                        <option value="AB-">AB-</option>
                        <option value="Unknown">Unknown</option>
                    </select>
                </div>
            </div>

            <!-- Medical History -->
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Medical History / Allergies (Optional)</label>
                <textarea id="medical_history" rows="3" placeholder="Please list any allergies, past surgeries, or medical conditions..." class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-medium focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10"></textarea>
            </div>

            <!-- Contact Info -->
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Contact Number *</label>
                    <input type="tel" id="phone" required pattern="[0-9]{10,15}" placeholder="09XXXXXXXXX" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                    <label class="flex items-center gap-2 mt-2 cursor-pointer">
                        <input type="checkbox" id="useParentPhone" onchange="toggleParentPhone()" class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                        <span class="text-xs text-slate-600 font-medium">Use my contact number</span>
                    </label>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Email Address *</label>
                    <input type="email" id="email" required placeholder="email@example.com" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                    <label class="flex items-center gap-2 mt-2 cursor-pointer">
                        <input type="checkbox" id="useParentEmail" onchange="toggleParentEmail()" class="w-4 h-4 text-purple-600 border-gray-300 rounded focus:ring-purple-500">
                        <span class="text-xs text-slate-600 font-medium">Use my email</span>
                    </label>
                </div>
            </div>

            <!-- Address Section -->
            <div class="pt-4 border-t border-slate-100">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xs font-bold text-slate-500 uppercase">Address Information</h3>
                    <button type="button" onclick="useParentAddress()" class="px-3 py-1.5 bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-bold rounded-lg transition-colors flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">home</span>
                        Use My Address
                    </button>
                </div>
                
                <div class="grid grid-cols-2 gap-4 mb-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Province *</label>
                        <select id="province" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10" onchange="loadCities()">
                            <option value="">Select Province</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">City / Municipality *</label>
                        <select id="city" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10" onchange="loadBarangays()" disabled>
                            <option value="">Select City/Municipality</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Barangay *</label>
                        <select id="barangay" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10" disabled>
                            <option value="">Select Barangay</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Street / House No. *</label>
                        <input type="text" id="street" required placeholder="Lot 1 Blk 2, Street Name" class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                    </div>
                </div>
            </div>

            <!-- Relationship -->
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Relationship to You *</label>
                <select id="rel" required class="w-full px-4 py-3 bg-white border border-slate-300 rounded-xl font-bold text-slate-700 focus:outline-none focus:border-purple-500 focus:ring-4 focus:ring-purple-500/10">
                    <option value="">Select Relationship</option>
                    <option value="Child">Child</option>
                    <option value="Sibling">Sibling</option>
                    <option value="Spouse">Spouse</option>
                    <option value="Parent">Parent</option>
                    <option value="Other">Other</option>
                </select>
            </div>
        </form>

        <div class="p-6 border-t border-gray-100 bg-white">
            <button onclick="submitDependent()" id="btnAdd" class="w-full py-4 bg-clinic-gold hover:bg-yellow-400 text-royal-purple font-bold rounded-xl shadow-lg shadow-yellow-400/20 transition-all">Create Account</button>
        </div>
    </div>
</div>

<!-- Consent Form Modal -->
<div id="consentModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/90 backdrop-blur-sm" onclick="closeConsentModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-3xl h-[90vh] rounded-xl shadow-2xl overflow-hidden flex flex-col">
        <div class="p-4 border-b flex justify-between items-center bg-gray-50">
            <h3 class="font-bold text-gray-800">Parent Consent Form</h3>
            <div class="flex gap-2">
                <button onclick="window.print()" class="px-4 py-2 bg-blue-600 text-white rounded-lg font-bold text-sm flex items-center gap-2">
                    <span class="material-symbols-outlined text-sm">print</span> Print
                </button>
                <button onclick="saveConsentToRecords()" class="px-4 py-2 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white rounded-lg font-bold text-sm flex items-center gap-2 shadow-lg shadow-purple-500/20 transition-all">
                    <span class="material-symbols-outlined text-sm">save</span> Save to Records
                </button>
                <button onclick="closeConsentModal()" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold text-sm">Close</button>
            </div>
        </div>
        
        <div class="flex-1 overflow-y-auto p-8 bg-white scrollbar-hide" id="consentPrintArea">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold uppercase tracking-wide">Parental Consent for Dental Treatment</h1>
                <p class="text-sm text-gray-500 mt-2">Vanessa Nicolas Dental Clinic</p>
            </div>
            
            <div class="space-y-6 text-gray-800 leading-relaxed font-serif">
                <p>
                    I, <b id="cfGuardian" class="uppercase border-b border-black">--</b>, 
                    of legal age, residing at <b id="cfAddress" class="uppercase border-b border-black">--</b>, 
                    being the parent/legal guardian of the patient described below:
                </p>
                
                <div class="pl-8 border-l-4 border-gray-100 py-2">
                    <p>Name of Minor: <b id="cfChild" class="uppercase">--</b></p>
                    <p>Date of Birth: <b id="cfChildDob">--</b></p>
                </div>
                
                <p>
                    Hereby grant permission to the dentists and staff of Vanessa Nicolas Dental Clinic to perform necessary dental examinations, 
                    treatments, diagnostics (including X-rays), and procedures deemed appropriate for the minor's dental health.
                </p>
                
                <p>
                    I understand that I will be informed of any major procedures before they are performed, except in emergency cases.
                </p>
                
                <div class="mt-12 grid grid-cols-2 gap-12">
                    <div>
                        <div class="border-b border-black mb-2"></div>
                        <p class="text-xs uppercase font-bold">Signature of Parent/Guardian</p>
                    </div>
                    <div>
                        <div class="border-b border-black mb-2 text-center" id="cfDate"></div>
                        <p class="text-xs uppercase font-bold text-center">Date</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Consent Options Modal -->
<div id="consentOptionsModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeConsentOptions()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
        <div class="p-6 border-b border-gray-100 bg-gradient-to-r from-purple-600 to-purple-700">
            <h3 class="text-xl font-black text-white">Select Consent Type</h3>
            <p class="text-sm text-white/80">Choose the type of consent form to generate</p>
        </div>
        
        <div class="p-6 space-y-3">
            <button onclick="generateGeneralConsent()" class="w-full p-4 bg-blue-50 hover:bg-blue-100 border-2 border-blue-200 rounded-xl text-left transition-all group">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-blue-500 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-white text-2xl">description</span>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-blue-900">General Consent</h4>
                        <p class="text-xs text-blue-700">For general dental treatment and procedures</p>
                    </div>
                    <span class="material-symbols-outlined text-blue-500 group-hover:translate-x-1 transition-transform">arrow_forward</span>
                </div>
            </button>

            <button onclick="showServiceSelection()" class="w-full p-4 bg-purple-50 hover:bg-purple-100 border-2 border-purple-200 rounded-xl text-left transition-all group">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 bg-purple-500 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-white text-2xl">medical_services</span>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-purple-900">Service-Specific Consent</h4>
                        <p class="text-xs text-purple-700">For specific dental services or procedures</p>
                    </div>
                    <span class="material-symbols-outlined text-purple-500 group-hover:translate-x-1 transition-transform">arrow_forward</span>
                </div>
            </button>
        </div>

        <div class="p-4 border-t border-gray-100">
            <button onclick="closeConsentOptions()" class="w-full py-2 text-slate-600 hover:text-slate-800 font-medium text-sm">Cancel</button>
        </div>
    </div>
</div>

<!-- Service Selection Modal -->
<div id="serviceSelectionModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="closeServiceSelection()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-2xl max-h-[80vh] rounded-2xl shadow-2xl overflow-hidden flex flex-col">
        <div class="p-6 border-b border-gray-100 bg-gradient-to-r from-purple-600 to-purple-700">
            <h3 class="text-xl font-black text-white">Select Service</h3>
            <p class="text-sm text-white/80">Choose the service for consent form</p>
        </div>
        
        <div class="flex-1 overflow-y-auto p-6 scrollbar-hide">
            <div id="servicesContainer" class="grid gap-3">
                <div class="animate-pulse bg-slate-100 h-16 rounded-xl"></div>
                <div class="animate-pulse bg-slate-100 h-16 rounded-xl"></div>
                <div class="animate-pulse bg-slate-100 h-16 rounded-xl"></div>
            </div>
        </div>

        <div class="p-4 border-t border-gray-100">
            <button onclick="closeServiceSelection()" class="w-full py-2 text-slate-600 hover:text-slate-800 font-medium text-sm">Cancel</button>
        </div>
    </div>
</div>

<!-- Dependent Info/Edit Modal -->
    <div id="dependentInfoModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden">
            <!-- Header with Purple Gradient -->
            <div class="bg-gradient-to-r from-purple-600 to-purple-700 p-6 flex items-center justify-between">
                <div>
                    <h3 class="text-2xl font-black text-white" id="depInfoName">Dependent Information</h3>
                    <p class="text-purple-100 text-sm">View and manage dependent details</p>
                </div>
                <button onclick="closeDependentInfo()" class="text-white/80 hover:text-white hover:bg-white/10 p-2 rounded-lg transition-colors">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </div>
            
            <!-- Content -->
            <div id="depInfoContent" class="p-6 overflow-y-auto max-h-[calc(90vh-200px)]">
                <!-- Content will be populated by JavaScript -->
            </div>
            
            <!-- Action Buttons -->
            <div class="p-6 bg-slate-50 border-t border-slate-200 flex gap-3">
                <button onclick="openAccountForDependent()" class="flex-1 h-12 bg-gradient-to-r from-green-500 to-green-600 hover:from-green-600 hover:to-green-700 text-white font-bold rounded-xl flex items-center justify-center gap-2 shadow-lg shadow-green-500/20 transition-all">
                    <span class="material-symbols-outlined">login</span>
                    Open Account
                </button>
                <button onclick="editDependent()" class="flex-1 h-12 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-xl flex items-center justify-center gap-2 shadow-lg shadow-purple-500/20 transition-all">
                    <span class="material-symbols-outlined">edit</span>
                    Edit Info
                </button>
            </div>
        </div>
    </div>

<script>
    const API_URL = '../api/api_dependencies.php';
    const modal = document.getElementById('addModal');
    const consentModal = document.getElementById('consentModal');

    // Parent's contact info from PHP
    const PARENT_PHONE = '<?php echo addslashes($parent_phone); ?>';
    const PARENT_EMAIL = '<?php echo addslashes($parent_email); ?>';
    const PARENT_ADDRESS = '<?php echo addslashes($parent_address); ?>';

    // PSGC API for Philippine Addresses
    const PSGC_API = 'https://psgc.gitlab.io/api';
    let provincesData = [];
    let citiesData = [];
    let barangaysData = [];

    document.addEventListener('DOMContentLoaded', () => {
        loadDependents();
        loadProvinces();
    });

    // Toggle parent's phone number
    function toggleParentPhone() {
        const checkbox = document.getElementById('useParentPhone');
        const phoneInput = document.getElementById('phone');
        
        if (checkbox.checked) {
            phoneInput.value = PARENT_PHONE;
            phoneInput.readOnly = true;
            phoneInput.classList.add('bg-slate-100');
        } else {
            phoneInput.value = '';
            phoneInput.readOnly = false;
            phoneInput.classList.remove('bg-slate-100');
        }
    }

    // Toggle parent's email
    function toggleParentEmail() {
        const checkbox = document.getElementById('useParentEmail');
        const emailInput = document.getElementById('email');
        
        if (checkbox.checked) {
            emailInput.value = PARENT_EMAIL;
            emailInput.readOnly = true;
            emailInput.classList.add('bg-slate-100');
        } else {
            emailInput.value = '';
            emailInput.readOnly = false;
            emailInput.classList.remove('bg-slate-100');
        }
    }

    // Use parent's address
    async function useParentAddress() {
        if (!PARENT_ADDRESS) {
            Swal.fire('No Address', 'Your address information is not available.', 'info');
            return;
        }

        // Parse address: "Street, Barangay, City, Province"
        const parts = PARENT_ADDRESS.split(',').map(p => p.trim());
        
        if (parts.length >= 4) {
            const street = parts[0];
            let barangay = parts[1];
            const city = parts[2];
            const province = parts[3];
            
            // Remove "Brgy." prefix if present (common in Philippine addresses)
            barangay = barangay.replace(/^Brgy\.?\s*/i, '').trim();
            
            // Fill street field directly
            document.getElementById('street').value = street;
            
            // Find and select province
            const provinceSelect = document.getElementById('province');
            let provinceFound = false;
            
            for (let i = 0; i < provinceSelect.options.length; i++) {
                const optionName = provinceSelect.options[i].getAttribute('data-name');
                if (optionName && optionName.toLowerCase() === province.toLowerCase()) {
                    provinceSelect.value = provinceSelect.options[i].value;
                    provinceFound = true;
                    
                    // Load cities and wait for completion
                    await new Promise(resolve => {
                        loadCities();
                        setTimeout(resolve, 1000); // Wait for cities to load
                    });
                    
                    // Find and select city
                    const citySelect = document.getElementById('city');
                    let cityFound = false;
                    
                    for (let j = 0; j < citySelect.options.length; j++) {
                        const cityOptionName = citySelect.options[j].getAttribute('data-name');
                        if (cityOptionName && cityOptionName.toLowerCase() === city.toLowerCase()) {
                            citySelect.value = citySelect.options[j].value;
                            cityFound = true;
                            
                            // Load barangays and wait for completion
                            await new Promise(resolve => {
                                loadBarangays();
                                setTimeout(resolve, 1000); // Wait for barangays to load
                            });
                            
                            // Find and select barangay
                            const barangaySelect = document.getElementById('barangay');
                            let barangayFound = false;
                            
                            for (let k = 0; k < barangaySelect.options.length; k++) {
                                const brgyOptionName = barangaySelect.options[k].getAttribute('data-name');
                                if (brgyOptionName && brgyOptionName.toLowerCase() === barangay.toLowerCase()) {
                                    barangaySelect.value = barangaySelect.options[k].value;
                                    barangayFound = true;
                                    break;
                                }
                            }
                            
                            if (!barangayFound) {
                                console.log('Barangay not found:', barangay);
                            }
                            break;
                        }
                    }
                    
                    if (!cityFound) {
                        console.log('City not found:', city);
                    }
                    break;
                }
            }
            
            if (!provinceFound) {
                console.log('Province not found:', province);
                Swal.fire('Partial Fill', 'Could not find matching province. Please select manually.', 'warning');
            } else {
                Swal.fire('Success', 'Address filled with your information!', 'success');
            }
        } else {
            // If address format is different, just fill street field
            document.getElementById('street').value = PARENT_ADDRESS;
            Swal.fire('Partial Fill', 'Please select Province, City, and Barangay manually.', 'info');
        }
    }

    // Load Provinces
    function loadProvinces() {
        fetch(`${PSGC_API}/provinces/`)
            .then(r => r.json())
            .then(data => {
                provincesData = data;
                const select = document.getElementById('province');
                select.innerHTML = '<option value="">Select Province</option>';
                data.forEach(prov => {
                    select.innerHTML += `<option value="${prov.code}" data-name="${prov.name}">${prov.name}</option>`;
                });
            })
            .catch(e => console.error('Error loading provinces:', e));
    }

    // Load Cities/Municipalities
    function loadCities() {
        const provinceSelect = document.getElementById('province');
        const provinceCode = provinceSelect.value;
        const citySelect = document.getElementById('city');
        const barangaySelect = document.getElementById('barangay');
        
        if (!provinceCode) {
            citySelect.disabled = true;
            barangaySelect.disabled = true;
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            return;
        }

        fetch(`${PSGC_API}/provinces/${provinceCode}/cities-municipalities/`)
            .then(r => r.json())
            .then(data => {
                citiesData = data;
                citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
                data.forEach(city => {
                    citySelect.innerHTML += `<option value="${city.code}" data-name="${city.name}">${city.name}</option>`;
                });
                citySelect.disabled = false;
                barangaySelect.disabled = true;
                barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            })
            .catch(e => console.error('Error loading cities:', e));
    }

    // Load Barangays
    function loadBarangays() {
        const citySelect = document.getElementById('city');
        const cityCode = citySelect.value;
        const barangaySelect = document.getElementById('barangay');
        
        if (!cityCode) {
            barangaySelect.disabled = true;
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            return;
        }

        fetch(`${PSGC_API}/cities-municipalities/${cityCode}/barangays/`)
            .then(r => r.json())
            .then(data => {
                barangaysData = data;
                barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
                data.forEach(brgy => {
                    barangaySelect.innerHTML += `<option value="${brgy.code}" data-name="${brgy.name}">${brgy.name}</option>`;
                });
                barangaySelect.disabled = false;
            })
            .catch(e => console.error('Error loading barangays:', e));
    }

    function loadDependents() {
        fetch(`${API_URL}?action=list`)
            .then(r => r.json())
            .then(d => {
                if(d.success) renderDependents(d.dependents);
            });
    }

    function renderDependents(list) {
        const container = document.getElementById('dependentContainer');
        if(!list || list.length === 0) {
            container.innerHTML = `
                <div class="col-span-full p-12 bg-white rounded-2xl border border-dashed border-slate-200 text-center">
                    <span class="material-symbols-outlined text-6xl text-slate-300 mb-3">group_off</span>
                    <p class="text-slate-400 font-medium">No dependents added yet.</p>
                </div>`;
            return;
        }
        container.innerHTML = list.map(p => `
            <div class="bg-white p-6 rounded-2xl border border-gray-100 shadow-sm hover:shadow-md transition-all relative group">
                <div class="flex items-center gap-4 mb-4">
                    <div class="w-14 h-14 rounded-full bg-gradient-to-br from-orange-400 to-pink-500 text-white flex items-center justify-center font-bold text-xl shadow-lg">
                        ${p.first_name[0]}
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-900 text-lg leading-tight">${p.first_name} ${p.last_name}</h3>
                        <p class="text-xs text-purple-600 font-bold uppercase tracking-wide">${p.relationship}</p>
                    </div>
                </div>
                
                <div class="space-y-2 text-sm text-slate-600 pt-4 border-t border-slate-100 mb-4">
                    <div class="flex justify-between">
                        <span>Age:</span>
                        <span class="font-bold text-slate-800">${p.age} years</span>
                    </div>
                    <div class="flex justify-between">
                        <span>Gender:</span>
                        <span class="font-bold text-slate-800">${p.gender}</span>
                    </div>
                    <div class="flex justify-between">
                        <span>DOB:</span>
                        <span class="font-bold text-slate-800">${new Date(p.dob).toLocaleDateString()}</span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="grid grid-cols-2 gap-2">
                    <button onclick="openDependentInfo(${p.patient_id})" class="px-3 py-2 bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-bold rounded-lg transition-colors flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-sm">info</span>
                        View Info
                    </button>
                    <button onclick="showConsentOptions(${p.patient_id})" class="px-3 py-2 bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-bold rounded-lg transition-colors flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-sm">description</span>
                        Consent
                    </button>
                </div>
            </div>
        `).join('');
    }

    function openAddModal() { modal.classList.remove('hidden'); }
    function closeAddModal() { modal.classList.add('hidden'); }

    function submitDependent() {
        const btn = document.getElementById('btnAdd');
        btn.innerHTML = 'Creating...';
        btn.disabled = true;

        // Get selected text values for address
        const provinceSelect = document.getElementById('province');
        const citySelect = document.getElementById('city');
        const barangaySelect = document.getElementById('barangay');
        
        const province = provinceSelect.options[provinceSelect.selectedIndex]?.getAttribute('data-name') || '';
        const city = citySelect.options[citySelect.selectedIndex]?.getAttribute('data-name') || '';
        const barangay = barangaySelect.options[barangaySelect.selectedIndex]?.getAttribute('data-name') || '';
        const street = document.getElementById('street').value;
        const fullAddress = `${street}, ${barangay}, ${city}, ${province}`;

        fetch(`${API_URL}?action=create_dependent`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                first_name: document.getElementById('fname').value,
                middle_name: document.getElementById('mname').value,
                last_name: document.getElementById('lname').value,
                dob: document.getElementById('dob').value,
                gender: document.getElementById('gender').value,
                civil_status: document.getElementById('civil_status').value,
                blood_type: document.getElementById('blood_type').value,
                medical_history: document.getElementById('medical_history').value,
                phone_number: document.getElementById('phone').value,
                email: document.getElementById('email').value,
                address: fullAddress,
                relationship: document.getElementById('rel').value
            })
        })
        .then(r => r.json())
        .then(d => {
            btn.innerHTML = 'Create Account';
            btn.disabled = false;
            
            if(d.success) {
                closeAddModal();
                Swal.fire('Success', 'Dependent account created successfully!', 'success');
                loadDependents();
                document.getElementById('addForm').reset();
                // Reset address dropdowns
                document.getElementById('city').disabled = true;
                document.getElementById('barangay').disabled = true;
                // Reset contact checkboxes and readonly states
                document.getElementById('useParentPhone').checked = false;
                document.getElementById('useParentEmail').checked = false;
                document.getElementById('phone').readOnly = false;
                document.getElementById('email').readOnly = false;
                document.getElementById('phone').classList.remove('bg-slate-100');
                document.getElementById('email').classList.remove('bg-slate-100');
            } else {
                Swal.fire('Error', d.message, 'error');
            }
        });
    }
    
    function viewConsent(dependentId) {
        fetch(`${API_URL}?action=generate_consent&dependent_id=${dependentId}`)
            .then(r => r.json())
            .then(d => {
                if(d.success && d.consent_data) {
                    const data = d.consent_data;
                    document.getElementById('cfGuardian').textContent = `${data.g_fname} ${data.g_lname}`;
                    document.getElementById('cfAddress').textContent = data.g_address || '[Address on File]';
                    document.getElementById('cfChild').textContent = `${data.d_fname} ${data.d_lname}`;
                    document.getElementById('cfChildDob').textContent = new Date(data.d_dob).toLocaleDateString();
                    document.getElementById('cfDate').textContent = new Date().toLocaleDateString();
                    
                    consentModal.classList.remove('hidden');
                } else {
                    Swal.fire('Error', 'Could not load consent data.', 'error');
                }
            });
    }
    
    // ===== NEW CONSENT & INFO FUNCTIONS =====
    
    let currentDependentId = null;
    let selectedServiceId = null;
    
    // Show consent options modal
    function showConsentOptions(patientId) {
        currentDependentId = patientId;
        document.getElementById('consentOptionsModal').classList.remove('hidden');
    }
    
    function closeConsentOptions() {
        document.getElementById('consentOptionsModal').classList.add('hidden');
    }
    
    // Generate general consent (existing consent form)
    function generateGeneralConsent() {
        closeConsentOptions();
        
        // Fetch dependent info
        fetch(`${API_URL}?action=list`)
            .then(r => r.json())
            .then(data => {
                const dep = data.dependents.find(d => d.patient_id === currentDependentId);
                if (!dep) return;
                
                const guardianName = '<?php echo $_SESSION["patient_name"]; ?>';
                const guardianAddress = PARENT_ADDRESS || 'N/A';
                const childName = `${dep.first_name} ${dep.last_name}`;
                const childDob = new Date(dep.dob).toLocaleDateString();
                const today = new Date().toLocaleDateString();
                
                // Create general consent form with same layout
                const consentHTML = `
                    <div class="text-center mb-8">
                        <h1 class="text-2xl font-bold uppercase tracking-wide">Parental Consent for Dental Treatment</h1>
                        <p class="text-sm text-gray-500 mt-2">Vanessa Nicolas Dental Clinic</p>
                    </div>
                    
                    <div class="space-y-6 text-gray-800 leading-relaxed">
                        <p class="font-serif">
                            I, <b class="uppercase border-b border-black">${guardianName}</b>, 
                            of legal age, residing at <b class="uppercase border-b border-black">${guardianAddress}</b>, 
                            being the parent/legal guardian of the patient described below:
                        </p>
                        
                        <div class="pl-8 border-l-4 border-gray-100 py-2">
                            <p>Name of Minor: <b class="uppercase">${childName}</b></p>
                            <p>Date of Birth: <b>${childDob}</b></p>
                        </div>
                        
                        <p class="font-serif">
                            Hereby grant permission to the dentists and staff of Vanessa Nicolas Dental Clinic to perform 
                            necessary dental examinations, treatments, diagnostics (including X-rays), and procedures deemed 
                            appropriate for the minor's dental health.
                        </p>
                        
                        <p class="font-serif">
                            I understand that I will be informed of any major procedures before they are performed, except in emergency cases.
                        </p>
                        
                        <p class="font-serif">
                            I acknowledge that I have been informed of:
                        </p>
                        <ul class="list-disc list-inside pl-4 space-y-1 font-serif">
                            <li>The general nature of dental procedures that may be performed</li>
                            <li>Potential risks and benefits of dental treatment</li>
                            <li>My right to ask questions and receive answers about treatment</li>
                            <li>My right to refuse or consent to specific procedures</li>
                        </ul>
                        
                        <div class="mt-12 grid grid-cols-2 gap-12">
                            <div class="border-t-2 border-black pt-2">
                                <p class="text-sm font-bold">Guardian Signature</p>
                                <p class="text-xs text-gray-500 mt-1">${guardianName}</p>
                            </div>
                            <div class="border-t-2 border-black pt-2">
                                <p class="text-sm font-bold">Date Created</p>
                                <p class="text-xs text-gray-500 mt-1">${today}</p>
                            </div>
                        </div>
                    </div>
                `;
                
                // Update consent print area
                const printArea = document.getElementById('consentPrintArea');
                if (printArea) {
                    printArea.innerHTML = consentHTML;
                }
                
                // Show consent modal
                const consentModal = document.getElementById('consentModal');
                if (consentModal) {
                    consentModal.classList.remove('hidden');
                }
            })
            .catch(err => {
                console.error('Error generating consent:', err);
                Swal.fire({
                    title: 'Error',
                    text: 'Failed to generate consent form',
                    icon: 'error',
                    confirmButtonColor: '#6A1B9A'
                });
            });
    }
    
    // Show service selection modal
    function showServiceSelection() {
        closeConsentOptions();
        document.getElementById('serviceSelectionModal').classList.remove('hidden');
        loadServices();
    }
    
    function closeServiceSelection() {
        document.getElementById('serviceSelectionModal').classList.add('hidden');
    }
    
    // Load services from API
    function loadServices() {
        // Fetch services directly from database - only those requiring SSCF
        fetch('../api/get_services.php?sscf_only=true')
            .then(r => r.json())
            .then(data => {
                if (data.success && data.services) {
                    renderServices(data.services);
                } else {
                    document.getElementById('servicesContainer').innerHTML = '<p class="text-center text-slate-500 py-8">No services available</p>';
                }
            })
            .catch(e => {
                console.error('Error loading services:', e);
                document.getElementById('servicesContainer').innerHTML = '<p class="text-center text-red-500 py-8">Error loading services</p>';
            });
    }
    
    // Render services
    function renderServices(services) {
        const container = document.getElementById('servicesContainer');
        container.innerHTML = services.map(s => `
            <button onclick="selectService(${s.service_id}, '${s.service_name.replace(/'/g, "\\'")}')" class="p-4 bg-white border-2 border-slate-200 hover:border-purple-500 hover:bg-purple-50 rounded-xl text-left transition-all group">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center flex-shrink-0">
                        <span class="material-symbols-outlined text-purple-600">medical_services</span>
                    </div>
                    <div class="flex-1">
                        <h4 class="font-bold text-slate-900">${s.service_name}</h4>
                        <p class="text-xs text-slate-500">₱${parseFloat(s.base_price).toLocaleString()}</p>
                    </div>
                    <span class="material-symbols-outlined text-slate-400 group-hover:text-purple-500 transition-colors">arrow_forward</span>
                </div>
            </button>
        `).join('');
    }
    
    // Select service and show details
    let selectedServiceData = null;
    
    function selectService(serviceId, serviceName) {
        selectedServiceId = serviceId;
        
        // Fetch full service details
        fetch(`../api/get_services.php`)
            .then(r => r.json())
            .then(data => {
                const service = data.services.find(s => s.service_id == serviceId);
                if (service) {
                    selectedServiceData = service;
                    showServiceDetails(service);
                }
            })
            .catch(err => console.error('Error loading service details:', err));
    }
    
    function showServiceDetails(service) {
        closeServiceSelection();
        
        // Create modal if it doesn't exist
        if (!document.getElementById('serviceDetailsModal')) {
            const modalHTML = `
                <div id="serviceDetailsModal" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
                    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden">
                        <div class="bg-gradient-to-r from-purple-600 to-purple-700 p-6 flex items-center justify-between">
                            <div>
                                <h3 class="text-2xl font-black text-white" id="serviceDetailName">Service Details</h3>
                                <p class="text-purple-100 text-sm">Review service information and installment options</p>
                            </div>
                            <button onclick="closeServiceDetails()" class="text-white/80 hover:text-white hover:bg-white/10 p-2 rounded-lg transition-colors">
                                <span class="material-symbols-outlined">close</span>
                            </button>
                        </div>
                        <div class="p-6 overflow-y-auto max-h-[calc(90vh-250px)]" id="serviceDetailsContent"></div>
                        <div class="p-6 bg-slate-50 border-t border-slate-200 flex gap-3">
                            <button onclick="closeServiceDetails()" class="flex-1 h-12 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded-xl transition-colors">Back</button>
                            <button onclick="generateServiceConsent()" class="flex-1 h-12 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-xl shadow-lg shadow-purple-500/20 transition-all flex items-center justify-center gap-2">
                                <span class="material-symbols-outlined">description</span>
                                Generate Consent Form
                            </button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHTML);
        }
        
        // Populate content
        document.getElementById('serviceDetailName').textContent = service.service_name;
        const content = document.getElementById('serviceDetailsContent');
        
        const installmentAvailable = service.is_installment_available == 1;
        const downPayment = parseFloat(service.installment_down_payment || 0);
        const basePrice = parseFloat(service.base_price);
        
        content.innerHTML = `
            <div class="space-y-6">
                <!-- Service Info -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Service Information</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-4 bg-purple-50 rounded-xl border border-purple-100">
                            <p class="text-xs text-purple-600 font-bold mb-1">Base Price</p>
                            <p class="text-2xl font-black text-purple-700">₱${basePrice.toLocaleString()}</p>
                        </div>
                        <div class="p-4 bg-slate-50 rounded-xl border border-slate-200">
                            <p class="text-xs text-slate-600 font-bold mb-1">Duration</p>
                            <p class="text-2xl font-black text-slate-700">${service.estimated_duration_mins} min</p>
                        </div>
                    </div>
                    ${service.category ? `<p class="text-sm text-slate-600 mt-3"><span class="font-bold">Category:</span> ${service.category}</p>` : ''}
                </div>
                
                <!-- Installment Info -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Payment Options</h4>
                    ${installmentAvailable ? `
                        <div class="p-5 bg-gradient-to-br from-amber-50 to-yellow-50 rounded-xl border-2 border-amber-200">
                            <div class="flex items-start gap-3 mb-4">
                                <div class="w-10 h-10 bg-amber-500 rounded-lg flex items-center justify-center flex-shrink-0">
                                    <span class="material-symbols-outlined text-white">payments</span>
                                </div>
                                <div>
                                    <h5 class="font-black text-amber-900 text-lg">Installment Available!</h5>
                                    <p class="text-sm text-amber-700">This service can be paid in installments</p>
                                </div>
                            </div>
                            <div class="space-y-2">
                                <div class="flex justify-between items-center p-3 bg-white/60 rounded-lg">
                                    <span class="text-sm font-bold text-amber-900">Down Payment Required:</span>
                                    <span class="text-lg font-black text-amber-700">₱${downPayment.toLocaleString()}</span>
                                </div>
                                <div class="flex justify-between items-center p-3 bg-white/60 rounded-lg">
                                    <span class="text-sm font-bold text-amber-900">Remaining Balance:</span>
                                    <span class="text-lg font-black text-amber-700">₱${(basePrice - downPayment).toLocaleString()}</span>
                                </div>
                                <p class="text-xs text-amber-600 mt-2">💡 The remaining balance can be paid in flexible installments as agreed with the clinic.</p>
                            </div>
                        </div>
                    ` : `
                        <div class="p-5 bg-slate-50 rounded-xl border border-slate-200">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-slate-400">info</span>
                                <p class="text-sm text-slate-600">This service requires <span class="font-bold">full payment</span> at the time of service.</p>
                            </div>
                        </div>
                    `}
                </div>
                
                <!-- Note -->
                <div class="p-4 bg-purple-50 rounded-xl border border-purple-100">
                    <p class="text-xs text-purple-700"><span class="font-bold">Note:</span> By generating the consent form, you acknowledge understanding of the service details and payment terms.</p>
                </div>
            </div>
        `;
        
        document.getElementById('serviceDetailsModal').classList.remove('hidden');
    }
    
    function closeServiceDetails() {
        const modal = document.getElementById('serviceDetailsModal');
        if (modal) modal.classList.add('hidden');
    }
    
    // Generate service-specific consent
    function generateServiceConsent() {
        if (!selectedServiceData || !currentDependentId) return;
        
        const service = selectedServiceData;
        
        // Close service details modal
        closeServiceDetails();
        
        // Fetch dependent info
        fetch(`${API_URL}?action=list`)
            .then(r => r.json())
            .then(data => {
                const dep = data.dependents.find(d => d.patient_id === currentDependentId);
                if (!dep) return;
                
                const guardianName = '<?php echo $_SESSION["patient_name"]; ?>';
                const guardianAddress = PARENT_ADDRESS || 'N/A';
                const childName = `${dep.first_name} ${dep.last_name}`;
                const childDob = new Date(dep.dob).toLocaleDateString();
                const today = new Date().toLocaleDateString();
                
                const installmentAvailable = service.is_installment_available == 1;
                const downPayment = parseFloat(service.installment_down_payment || 0);
                const basePrice = parseFloat(service.base_price);
                
                // Create service-specific consent form
                const consentHTML = `
                    <div class="text-center mb-8">
                        <h1 class="text-2xl font-bold uppercase tracking-wide">Service-Specific Consent Form</h1>
                        <p class="text-sm text-gray-500 mt-2">Vanessa Nicolas Dental Clinic</p>
                    </div>
                    
                    <div class="space-y-6 text-gray-800 leading-relaxed">
                        <!-- Service Information Box -->
                        <div class="p-5 bg-purple-50 border-2 border-purple-200 rounded-lg">
                            <h3 class="font-bold text-purple-900 text-lg mb-3">Service to be Performed</h3>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <p class="text-xs text-purple-600 font-bold">Service Name</p>
                                    <p class="text-lg font-black text-purple-900">${service.service_name}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-purple-600 font-bold">Category</p>
                                    <p class="text-lg font-black text-purple-900">${service.category || 'General'}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-purple-600 font-bold">Base Price</p>
                                    <p class="text-lg font-black text-purple-900">₱${basePrice.toLocaleString()}</p>
                                </div>
                                <div>
                                    <p class="text-xs text-purple-600 font-bold">Estimated Duration</p>
                                    <p class="text-lg font-black text-purple-900">${service.estimated_duration_mins} minutes</p>
                                </div>
                            </div>
                        </div>
                        
                        ${installmentAvailable ? `
                            <div class="p-5 bg-amber-50 border-2 border-amber-200 rounded-lg">
                                <h3 class="font-bold text-amber-900 text-lg mb-3">Payment Terms - Installment Available</h3>
                                <div class="space-y-2">
                                    <div class="flex justify-between">
                                        <span class="font-bold text-amber-900">Down Payment:</span>
                                        <span class="font-black text-amber-700">₱${downPayment.toLocaleString()}</span>
                                    </div>
                                    <div class="flex justify-between">
                                        <span class="font-bold text-amber-900">Remaining Balance:</span>
                                        <span class="font-black text-amber-700">₱${(basePrice - downPayment).toLocaleString()}</span>
                                    </div>
                                    <p class="text-xs text-amber-700 mt-2">The remaining balance may be paid in installments as agreed with the clinic.</p>
                                </div>
                            </div>
                        ` : `
                            <div class="p-5 bg-slate-100 border-2 border-slate-300 rounded-lg">
                                <h3 class="font-bold text-slate-900 text-lg mb-2">Payment Terms</h3>
                                <p class="text-sm text-slate-700">Full payment of <span class="font-black">₱${basePrice.toLocaleString()}</span> is required at the time of service.</p>
                            </div>
                        `}
                        
                        <p class="font-serif">
                            I, <b class="uppercase border-b border-black">${guardianName}</b>, 
                            of legal age, residing at <b class="uppercase border-b border-black">${guardianAddress}</b>, 
                            being the parent/legal guardian of the patient described below:
                        </p>
                        
                        <div class="pl-8 border-l-4 border-gray-100 py-2">
                            <p>Name of Minor: <b class="uppercase">${childName}</b></p>
                            <p>Date of Birth: <b>${childDob}</b></p>
                        </div>
                        
                        <p class="font-serif">
                            Hereby grant permission to the dentists and staff of Vanessa Nicolas Dental Clinic to perform the 
                            <b class="text-purple-700">${service.service_name}</b> procedure on the above-named minor.
                        </p>
                        
                        <p class="font-serif">
                            I acknowledge that I have been informed of:
                        </p>
                        <ul class="list-disc list-inside pl-4 space-y-1 font-serif">
                            <li>The nature of the ${service.service_name} procedure</li>
                            <li>The estimated cost of ₱${basePrice.toLocaleString()}</li>
                            ${installmentAvailable ? `<li>The installment payment option with ₱${downPayment.toLocaleString()} down payment</li>` : ''}
                            <li>Potential risks and benefits of the procedure</li>
                            <li>Alternative treatment options, if any</li>
                        </ul>
                        
                        <p class="font-serif">
                            I understand that I will be informed of any changes to the treatment plan or additional procedures 
                            that may be necessary during the course of treatment.
                        </p>
                        
                        <div class="mt-12 grid grid-cols-2 gap-12">
                            <div class="border-t-2 border-black pt-2">
                                <p class="text-sm font-bold">Guardian Signature</p>
                                <p class="text-xs text-gray-500 mt-1">${guardianName}</p>
                            </div>
                            <div class="border-t-2 border-black pt-2">
                                <p class="text-sm font-bold">Date Created</p>
                                <p class="text-xs text-gray-500 mt-1">${today}</p>
                            </div>
                        </div>
                    </div>
                `;
                
                // Update consent print area
                const printArea = document.getElementById('consentPrintArea');
                if (printArea) {
                    printArea.innerHTML = consentHTML;
                }
                
                // Show consent modal
                const consentModal = document.getElementById('consentModal');
                if (consentModal) {
                    consentModal.classList.remove('hidden');
                }
            })
            .catch(err => {
                console.error('Error generating consent:', err);
                Swal.fire({
                    title: 'Error',
                    text: 'Failed to generate consent form',
                    icon: 'error',
                    confirmButtonColor: '#6A1B9A'
                });
            });
    }
    
    // Open dependent info modal
    function openDependentInfo(patientId) {
        currentDependentId = patientId;
        
        fetch(`${API_URL}?action=list`)
            .then(r => r.json())
            .then(data => {
                const dep = data.dependents.find(d => d.patient_id === patientId);
                if (dep) {
                    currentDependentData = dep; // Store for edit function
                    displayDependentInfo(dep);
                    document.getElementById('dependentInfoModal').classList.remove('hidden');
                }
            })
            .catch(err => console.error('Error loading dependent:', err));
    }
    
    function closeDependentInfo() {
        document.getElementById('dependentInfoModal').classList.add('hidden');
    }
    
    // Display dependent information
    function displayDependentInfo(dep) {
        document.getElementById('depInfoName').textContent = `${dep.first_name} ${dep.last_name}`;
        
        const content = document.getElementById('depInfoContent');
        content.innerHTML = `
            <div class="space-y-6">
                <!-- Personal Information -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Personal Information</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Full Name</p>
                            <p class="font-bold text-slate-900">${dep.first_name} ${dep.middle_name || ''} ${dep.last_name}</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Date of Birth</p>
                            <p class="font-bold text-slate-900">${new Date(dep.dob).toLocaleDateString()}</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Age</p>
                            <p class="font-bold text-slate-900">${dep.age} years old</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Gender</p>
                            <p class="font-bold text-slate-900">${dep.gender}</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Civil Status</p>
                            <p class="font-bold text-slate-900">${dep.civil_status || 'N/A'}</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Blood Type</p>
                            <p class="font-bold text-slate-900">${dep.blood_type || 'Unknown'}</p>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Contact Information</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Phone Number</p>
                            <p class="font-bold text-slate-900">${dep.phone_number || 'N/A'}</p>
                        </div>
                        <div class="p-3 bg-slate-50 rounded-lg">
                            <p class="text-xs text-slate-500 mb-1">Email</p>
                            <p class="font-bold text-slate-900">${dep.email || 'Using parent\'s email'}</p>
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Address</h4>
                    <div class="p-3 bg-slate-50 rounded-lg">
                        <p class="font-medium text-slate-900">${dep.address || 'N/A'}</p>
                    </div>
                </div>

                <!-- Medical History -->
                ${dep.medical_history ? `
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Medical History</h4>
                    <div class="p-3 bg-slate-50 rounded-lg">
                        <p class="text-slate-700">${dep.medical_history}</p>
                    </div>
                </div>
                ` : ''}

                <!-- Relationship -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Relationship</h4>
                    <div class="p-3 bg-purple-50 rounded-lg border border-purple-200">
                        <p class="font-bold text-purple-900">${dep.relationship}</p>
                    </div>
                </div>
            </div>
        `;
    }
    
    // Edit dependent (full implementation)
    let isEditMode = false;
    let currentDependentData = null;
    
    function editDependent() {
        if (!currentDependentData) return;
        
        isEditMode = true;
        const dep = currentDependentData;
        
        // Create editable form
        const content = document.getElementById('depInfoContent');
        content.innerHTML = `
            <form id="editDependentForm" class="space-y-6">
                <!-- Personal Information -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Personal Information</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">First Name *</label>
                            <input type="text" name="first_name" value="${dep.first_name}" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Middle Name</label>
                            <input type="text" name="middle_name" value="${dep.middle_name || ''}" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Last Name *</label>
                            <input type="text" name="last_name" value="${dep.last_name}" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Date of Birth *</label>
                            <input type="date" name="dob" value="${dep.dob}" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Gender *</label>
                            <select name="gender" required class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                                <option value="Male" ${dep.gender === 'Male' ? 'selected' : ''}>Male</option>
                                <option value="Female" ${dep.gender === 'Female' ? 'selected' : ''}>Female</option>
                                <option value="Other" ${dep.gender === 'Other' ? 'selected' : ''}>Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Civil Status</label>
                            <select name="civil_status" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                                <option value="Single" ${dep.civil_status === 'Single' ? 'selected' : ''}>Single</option>
                                <option value="Married" ${dep.civil_status === 'Married' ? 'selected' : ''}>Married</option>
                                <option value="Widowed" ${dep.civil_status === 'Widowed' ? 'selected' : ''}>Widowed</option>
                                <option value="Separated" ${dep.civil_status === 'Separated' ? 'selected' : ''}>Separated</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Blood Type</label>
                            <select name="blood_type" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                                <option value="Unknown" ${!dep.blood_type || dep.blood_type === 'Unknown' ? 'selected' : ''}>Unknown</option>
                                <option value="A+" ${dep.blood_type === 'A+' ? 'selected' : ''}>A+</option>
                                <option value="A-" ${dep.blood_type === 'A-' ? 'selected' : ''}>A-</option>
                                <option value="B+" ${dep.blood_type === 'B+' ? 'selected' : ''}>B+</option>
                                <option value="B-" ${dep.blood_type === 'B-' ? 'selected' : ''}>B-</option>
                                <option value="O+" ${dep.blood_type === 'O+' ? 'selected' : ''}>O+</option>
                                <option value="O-" ${dep.blood_type === 'O-' ? 'selected' : ''}>O-</option>
                                <option value="AB+" ${dep.blood_type === 'AB+' ? 'selected' : ''}>AB+</option>
                                <option value="AB-" ${dep.blood_type === 'AB-' ? 'selected' : ''}>AB-</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Contact Information -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Contact Information</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Phone Number</label>
                            <input type="tel" name="phone_number" value="${dep.phone_number || ''}" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1">Email</label>
                            <input type="email" name="email" value="${dep.email || ''}" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">
                        </div>
                    </div>
                </div>

                <!-- Address -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Address</h4>
                    <textarea name="address" rows="2" class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">${dep.address || ''}</textarea>
                </div>

                <!-- Medical History -->
                <div>
                    <h4 class="text-sm font-bold text-slate-500 uppercase mb-3">Medical History</h4>
                    <textarea name="medical_history" rows="3" placeholder="Allergies, conditions, medications..." class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:border-purple-500 focus:ring-2 focus:ring-purple-500/20">${dep.medical_history || ''}</textarea>
                </div>

                <!-- Action Buttons -->
                <div class="flex gap-3 pt-4">
                    <button type="button" onclick="cancelEdit()" class="flex-1 h-11 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded-xl transition-colors">
                        Cancel
                    </button>
                    <button type="submit" class="flex-1 h-11 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-xl shadow-lg shadow-purple-500/20 transition-all">
                        Save Changes
                    </button>
                </div>
            </form>
        `;
        
        // Add form submit handler
        document.getElementById('editDependentForm').addEventListener('submit', saveDependent);
    }
    
    function cancelEdit() {
        isEditMode = false;
        if (currentDependentData) {
            displayDependentInfo(currentDependentData);
        }
    }
    
    function saveDependent(e) {
        e.preventDefault();
        const form = e.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData);
        data.patient_id = currentDependentId;
        
        // Show loading
        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'Saving...';
        submitBtn.disabled = true;
        
        fetch(`${API_URL}?action=update_dependent`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                Swal.fire({
                    title: 'Success!',
                    text: 'Dependent information updated successfully',
                    icon: 'success',
                    confirmButtonColor: '#6A1B9A'
                });
                isEditMode = false;
                closeDependentInfo();
                loadDependents(); // Refresh list
            } else {
                throw new Error(result.message || 'Update failed');
            }
        })
        .catch(err => {
            Swal.fire({
                title: 'Error',
                text: err.message,
                icon: 'error',
                confirmButtonColor: '#6A1B9A'
            });
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        });
    }
    
    function openAccountForDependent() {
        if (!currentDependentId) return;
        
        // Fetch dependent details to check relationship and verification
        fetch(`${API_URL}?action=list`)
            .then(r => r.json())
            .then(data => {
                const dep = data.dependents.find(d => d.patient_id === currentDependentId);
                if (!dep) return;
                
                // Check if verification is needed
                const isChild = dep.relationship.toLowerCase() === 'child';
                const isVerified = dep.access_verified;
                
                if (!isChild && !isVerified) {
                    Swal.fire({
                        title: 'Verification Required',
                        html: `Access to <strong>${dep.first_name}'s</strong> account requires their verification.<br><br>An email has been sent to them with a verification link.`,
                        icon: 'warning',
                        confirmButtonColor: '#6A1B9A'
                    });
                    return;
                }
                
                Swal.fire({
                    title: 'Switch to Dependent Account?',
                    text: `You will be logged in as ${dep.first_name} ${dep.last_name}. Continue?`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#10b981',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Yes, switch account',
                    cancelButtonText: 'Cancel'
                }).then((result) => {
                    if (result.isConfirmed) {
                        window.location.href = `../switch_account.php?patient_id=${currentDependentId}`;
                    }
                });
            });
    }
    function closeConsentModal() {
        consentModal.classList.add('hidden');
    }
    
    // Save consent form to medical records
    // Save consent form to medical records
    function saveConsentToRecords(isOverwrite = false) {
        if (!currentDependentId) {
            Swal.fire({
                title: 'Error',
                text: 'No dependent selected',
                icon: 'error',
                confirmButtonColor: '#6A1B9A'
            });
            return;
        }
        
        const consentHTML = document.getElementById('consentPrintArea').innerHTML;
        const consentTitle = document.querySelector('#consentPrintArea h1')?.textContent || 'Consent Form';
        
        if (!isOverwrite) {
            Swal.fire({
                title: 'Saving...',
                text: 'Saving consent form to medical records',
                allowOutsideClick: false,
                didOpen: () => { Swal.showLoading(); }
            });
        }
        
        fetch('../api/save_consent.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                patient_id: currentDependentId,
                consent_title: consentTitle,
                consent_html: consentHTML,
                service_id: selectedServiceId || null,
                overwrite: isOverwrite
            })
        })
        .then(r => r.json())
        .then(result => {
            if (result.success) {
                Swal.fire('Success', 'Consent form saved successfully!', 'success')
                    .then(() => {
                        closeConsentModal();
                    });
            } else if (result.requires_confirmation) {
                Swal.fire({
                    title: 'Existing Consent Found',
                    text: result.message,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, Update it',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#6A1B9A'
                }).then((r) => {
                    if (r.isConfirmed) {
                        saveConsentToRecords(true); // Retry with overwrite
                    }
                });
            } else {
                Swal.fire('Error', result.message, 'error');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'An unexpected error occurred', 'error');
        });
    }
    
    function closeConsentModal() {
        const modal = document.getElementById('consentModal');
        if (modal) modal.classList.add('hidden');
    }
</script>
</body>
</html>
