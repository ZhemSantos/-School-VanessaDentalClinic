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

// Handle Form Submission
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    
    // Sanitize Inputs
    $first_name = trim($_POST['first_name'] ?? '');
    $middle_name = trim($_POST['middle_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $dob = $_POST['dob'] ?? '';
    $gender = $_POST['gender'] ?? '';
    $civil_status = $_POST['civil_status'] ?? '';
    $occupation = trim($_POST['occupation'] ?? '');
    $blood_type = $_POST['blood_type'] ?? '';
    $phone_number = trim($_POST['phone_number'] ?? '');
    // Remove non-numeric chars for storage if preferred, or keep format. Let's keep consistent with other forms (usually simplified)
    // But here we just trim.
    
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $medical_history = trim($_POST['medical_history'] ?? '');

    // Basic Validation
    if (empty($first_name) || empty($last_name) || empty($dob) || empty($phone_number) || empty($address)) {
         $error_message = 'Please fill in all required fields marked with *.';
    } else {
         // Age Calculation
         $today = new DateTime();
         $diff = $today->diff(new DateTime($dob));
         $age = $diff->y;
         $age_category = ($age >= 60) ? 'Senior' : (($age < 18) ? 'Minor' : 'Adult');

         try {
            $sql = "UPDATE tbl_patient SET 
                    first_name=?, middle_name=?, last_name=?, dob=?, age=?, age_category=?, gender=?, 
                    phone_number=?, email=?, medical_history=?, blood_type=?, civil_status=?, 
                    occupation=?, address=?
                    WHERE patient_id=?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $first_name, $middle_name, $last_name, $dob, $age, $age_category, $gender,
                $phone_number, $email, $medical_history, $blood_type, $civil_status,
                $occupation, $address, $patient_id
            ]);

            $success_message = "Your profile information has been updated successfully.";
            
            // Update Session Name if changed
            $_SESSION['patient_name'] = $first_name . ' ' . $last_name;
            $patient_name = $_SESSION['patient_name'];

         } catch (Exception $e) {
             $error_message = 'Database error: ' . $e->getMessage();
         }
    }
}

// Fetch Patient Data (After update to show latest)
$patient = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM tbl_patient WHERE patient_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch();
    if (!$patient) {
        die("Patient record not found.");
    }
} catch (Exception $e) {
    die("Error loading profile.");
}

function isSelected($val, $current) { return ($val === $current) ? 'selected' : ''; }

// UI Helpers
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Update Profile - Vanessa Nicolas Dental Clinic</title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
                <h2 class="text-3xl font-black text-[#151118] tracking-tight">Update My Profile</h2>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">edit</span>
                    Modify your personal information
                </p>
            </div>
            <div class="flex items-center gap-4">
                <a href="client_profile_info.php" class="h-12 px-6 bg-gray-100 hover:bg-gray-200 text-slate-700 rounded-xl font-bold flex items-center gap-2 transition-all">
                    <span class="material-symbols-outlined">arrow_back</span>
                    <span>Back to Profile</span>
                </a>
            </div>
        </header>

        <!-- Content -->
        <div class="flex-1 overflow-y-auto p-8">
            <div class="max-w-4xl mx-auto flex flex-col gap-6">
                
                <?php if ($success_message): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-4 flex items-center gap-3">
                    <span class="material-symbols-outlined text-green-600">check_circle</span>
                    <p class="text-green-800 font-medium"><?php echo htmlspecialchars($success_message); ?></p>
                </div>
                <?php endif; ?>

                <?php if ($error_message): ?>
                <div class="bg-red-50 border border-red-200 rounded-xl p-4 flex items-center gap-3">
                    <span class="material-symbols-outlined text-red-600">error</span>
                    <p class="text-red-800 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
                </div>
                <?php endif; ?>

                <!-- Update Form -->
                <form method="POST" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8">
                    <input type="hidden" name="update_profile" value="1">

                    <!-- Personal Info -->
                    <div class="mb-8">
                         <h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
                                <span class="material-symbols-outlined text-sm">badge</span>
                            </span>
                            Personal Information
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">First Name *</label>
                                <input type="text" name="first_name" required value="<?php echo htmlspecialchars($patient['first_name']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Middle Name</label>
                                <input type="text" name="middle_name" value="<?php echo htmlspecialchars($patient['middle_name']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Last Name *</label>
                                <input type="text" name="last_name" required value="<?php echo htmlspecialchars($patient['last_name']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Date of Birth *</label>
                                <input type="date" name="dob" required value="<?php echo htmlspecialchars($patient['dob']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                             <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Gender</label>
                                <select name="gender" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                                    <option value="Male" <?php echo isSelected('Male', $patient['gender']); ?>>Male</option>
                                    <option value="Female" <?php echo isSelected('Female', $patient['gender']); ?>>Female</option>
                                    <option value="Other" <?php echo isSelected('Other', $patient['gender']); ?>>Other</option>
                                </select>
                            </div>
                             <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Civil Status</label>
                                <select name="civil_status" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                                    <option value="Single" <?php echo isSelected('Single', $patient['civil_status']); ?>>Single</option>
                                    <option value="Married" <?php echo isSelected('Married', $patient['civil_status']); ?>>Married</option>
                                    <option value="Widowed" <?php echo isSelected('Widowed', $patient['civil_status']); ?>>Widowed</option>
                                    <option value="Divorced" <?php echo isSelected('Divorced', $patient['civil_status']); ?>>Divorced</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Occupation</label>
                                <input type="text" name="occupation" value="<?php echo htmlspecialchars($patient['occupation']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Blood Type</label>
                                <select name="blood_type" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                                    <option value="Unknown" <?php echo isSelected('Unknown', $patient['blood_type']); ?>>Unknown</option>
                                    <option value="A+" <?php echo isSelected('A+', $patient['blood_type']); ?>>A+</option>
                                    <option value="A-" <?php echo isSelected('A-', $patient['blood_type']); ?>>A-</option>
                                    <option value="B+" <?php echo isSelected('B+', $patient['blood_type']); ?>>B+</option>
                                    <option value="B-" <?php echo isSelected('B-', $patient['blood_type']); ?>>B-</option>
                                    <option value="O+" <?php echo isSelected('O+', $patient['blood_type']); ?>>O+</option>
                                    <option value="O-" <?php echo isSelected('O-', $patient['blood_type']); ?>>O-</option>
                                    <option value="AB+" <?php echo isSelected('AB+', $patient['blood_type']); ?>>AB+</option>
                                    <option value="AB-" <?php echo isSelected('AB-', $patient['blood_type']); ?>>AB-</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="border-gray-100 my-8">

                    <!-- Contact Info -->
                    <div class="mb-8">
                         <h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
                                <span class="material-symbols-outlined text-sm">contact_phone</span>
                            </span>
                            Contact Information
                        </h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Mobile Number *</label>
                                <input type="tel" name="phone_number" required value="<?php echo htmlspecialchars($patient['phone_number']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Email Address</label>
                                <input type="email" name="email" value="<?php echo htmlspecialchars($patient['email']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Home Address *</label>
                                <input type="text" name="address" required value="<?php echo htmlspecialchars($patient['address']); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none">
                            </div>
                        </div>
                    </div>

                    <hr class="border-gray-100 my-8">

                    <!-- Medical Info -->
                    <div>
                         <h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
                            <span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
                                <span class="material-symbols-outlined text-sm">medical_services</span>
                            </span>
                            Medical Information
                        </h3>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Medical History / Allergies</label>
                            <textarea name="medical_history" rows="4" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none resize-y" placeholder="List any known allergies or medical conditions..."><?php echo htmlspecialchars($patient['medical_history']); ?></textarea>
                        </div>
                    </div>

                    <div class="mt-8 flex items-center justify-end gap-4">
                        <a href="client_profile_info.php" class="px-6 py-3 rounded-xl font-bold text-slate-500 hover:bg-gray-100 transition-colors">
                            Cancel
                        </a>
                        <button type="submit" class="px-8 py-3 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
                            <span class="material-symbols-outlined">save</span>
                            <span>Save Changes</span>
                        </button>
                    </div>
                </form>

            </div>
        </div>
    </main>
</div>

<?php if ($success_message): ?>
<script>
    Swal.fire({
        icon: 'success',
        title: 'Updated!',
        text: '<?php echo addslashes($success_message); ?>',
        confirmButtonColor: '#6A1B9A'
    });
</script>
<?php endif; ?>

</body>
</html>
