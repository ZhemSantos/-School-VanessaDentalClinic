<?php
session_start();
require_once 'db.php';
require_once 'includes/security.php';
require_once 'includes/email_config.php';

// Configure secure session
configureSecureSession();

// Function to generate OTP
function generateOTP() {
    return str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
}

// Function to send verification email
function sendVerificationEmail($email, $otp, $name) {
    $subject = "Verify Your Email - Vanessa Nicolas Dental Clinic";
    $message = "
        <p>Dear " . htmlspecialchars($name) . ",</p>
        <p>Thank you for registering with Vanessa Nicolas Dental Clinic. Please use the following One-Time Password (OTP) to verify your account:</p>
        <div style='background-color: #f8f9fa; padding: 15px; text-align: center; border-radius: 5px; margin: 20px 0;'>
            <h1 style='color: #8a11d4; letter-spacing: 5px; margin: 0; font-size: 32px;'>" . htmlspecialchars($otp) . "</h1>
        </div>
        <p>This code is valid for 10 minutes.</p>
        <p>If you did not create an account, please ignore this email.</p>
    ";
    
    return sendEmail($email, $subject, $message);
}

// Function to calculate age
function calculateAge($dob) {
    if (empty($dob)) return 0;
    $birthDate = new DateTime($dob);
    $today = new DateTime('today');
    return $birthDate->diff($today)->y;
}

$error_message = '';
$success_message = '';
$csrf_token = generateCSRFToken();
$initialStep = 1; // Default step

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!verifyCSRFToken($submitted_token)) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        // Sanitize and Retrieve Input
        $firstName = sanitizeInput($_POST['first_name'] ?? '');
        $middleName = sanitizeInput($_POST['middle_name'] ?? '');
        $lastName = sanitizeInput($_POST['last_name'] ?? '');
        $dob = sanitizeInput($_POST['dob'] ?? '');
        $sex = sanitizeInput($_POST['sex'] ?? '');
        $contact = sanitizeInput($_POST['contact'] ?? '');
        $address = sanitizeInput($_POST['address'] ?? '');
        
        // New Fields
        $bloodType = sanitizeInput($_POST['blood_type'] ?? 'Unknown');
        $civilStatus = sanitizeInput($_POST['civil_status'] ?? '');
        $occupation = sanitizeInput($_POST['occupation'] ?? '');
        $medicalHistory = sanitizeInput($_POST['medical_history'] ?? '');
        
        $username = sanitizeInput($_POST['username'] ?? '');
        $email = sanitizeInput($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        $agree_terms = isset($_POST['agree_terms']);

        $errors = [];

        // Validation
        if (empty($firstName) || empty($lastName) || empty($dob) || empty($email) || empty($password) || empty($username) || empty($contact) || empty($address) || empty($sex)) {
            $errors[] = "All required fields must be filled out.";
        }

        if ($password !== $confirm_password) {
            $errors[] = "Passwords do not match.";
        }

        if (strlen($password) < 8) {
            $errors[] = "Password must be at least 8 characters long.";
        }

        if (!$agree_terms) {
            $errors[] = "You must agree to the terms of service.";
        }

        // Age Check (Server-side enforcement)
        $age = calculateAge($dob);
        if ($age < 18) {
            $errors[] = "Minors (under 18) cannot create an account directly. Please ask a parent or guardian to create an account.";
        }

        // Check availability if no basic format errors
        if (empty($errors)) {
            try {
                // Check username in tbl_patient_accounts
                $stmt = $pdo->prepare("SELECT account_id FROM tbl_patient_accounts WHERE username = ?");
                $stmt->execute([$username]);
                if ($stmt->fetch()) {
                    $errors[] = "Username already exists.";
                    $_POST['username'] = ''; // Clear value
                }

                // Check email in tbl_patient
                $stmt = $pdo->prepare("SELECT patient_id FROM tbl_patient WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $errors[] = "Email address already registered.";
                    $_POST['email'] = ''; // Clear value
                }
            } catch (PDOException $e) {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }

        // Determine which step to show if there are errors
        if (!empty($errors)) {
            $initialStep = 2; // Default to step 2 if there are errors
            
            // But if the error is about Email (Step 1), go back to Step 1
            foreach ($errors as $err) {
                if (strpos($err, 'Email') !== false || strpos($err, 'required fields') !== false || strpos($err, 'Minors') !== false) {
                    $initialStep = 1;
                    break;
                }
            }
        }

        // Process Registration
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                // 1. Create Patient Account
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                
                // Generate OTP
                $otp = generateOTP();
                $otpExpiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));
                
                // Insert Account with OTP
                $stmt = $pdo->prepare("INSERT INTO tbl_patient_accounts (username, password_hash, is_verified, otp, otp_expiry) VALUES (?, ?, 0, ?, ?)");
                $stmt->execute([$username, $passwordHash, $otp, $otpExpiry]);
                $accountId = $pdo->lastInsertId();

                // 2. Create Patient Profile
                $ageCategory = ($age >= 60) ? 'Senior' : 'Adult'; 
                
                // Format Names
                $firstName = ucfirst(strtolower($firstName));
                $middleName = ucfirst(strtolower($middleName));
                $lastName = ucfirst(strtolower($lastName));

                $stmt = $pdo->prepare("
                    INSERT INTO tbl_patient (
                        account_id, first_name, middle_name, last_name, 
                        dob, age, age_category,
                        gender, phone_number, email, address, 
                        blood_type, civil_status, occupation, medical_history,
                        registration_date
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $accountId, $firstName, $middleName, $lastName,
                    $dob, $age, $ageCategory,
                    $sex, $contact, $email, $address,
                    $bloodType, $civilStatus, $occupation, $medicalHistory
                ]);

                // 3. Send Verification Email
                $emailSent = sendVerificationEmail($email, $otp, "$firstName $lastName");
                
                // 4. Commit
                $pdo->commit();

                // Redirect to OTP verification page
                $_SESSION['verify_email'] = $email;
                $_SESSION['success_message'] = "Registration successful! A verification code has been sent to your email.";
                
                header("Location: verify_otp.php");
                exit();

            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = "Registration failed: " . $e->getMessage();
            }
        }
    }
}
// Pass errors to $error_message if needed or keep as array
if (!empty($errors)) {
    // If we have an array of errors, we might want to display the first one or join them 
    // IF the existing template uses $error_message string.
    // However, looking at the template, it iterates over $errors array if it exists.
    // But lines 25-26 init $error_message string.
    // Line 288 iterates $errors. So we are good with $errors array.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account - Vanessa Nicolas Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#8a11d4',
                        secondary: '#eddafc',
                        background: {
                            light: '#fdfbff',
                            dark: '#151118'
                        }
                    },
                    fontFamily: {
                        display: ['Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-background-light dark:bg-background-dark font-display min-h-screen flex items-center justify-center p-4 text-[#151118] dark:text-white">
    <!-- Background Image -->
    <div class="fixed inset-0 z-0 opacity-15 pointer-events-none bg-center bg-cover" style="background-image: url('images/pic1.jpg');"></div>

    <!-- Header -->
    <nav class="fixed top-0 left-0 w-full z-50 bg-white border-b border-[#e2dbe6]">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <div class="flex-shrink-0 flex items-center">
                    <a href="index.php">
                        <img class="h-16 w-auto" src="images/logo/logo.png" alt="Vanessa Nicolas Dental Clinic">
                    </a>
                </div>
                <div class="flex items-center gap-4">
                    <a href="index.php" class="hidden md:flex items-center gap-2 text-primary hover:text-[#6A1B9A] transition-colors font-medium">
                        <span class="material-symbols-outlined">arrow_back</span>
                        Back to Home
                    </a>
                    <a href="landing.php" class="bg-primary text-white px-6 py-2.5 rounded-full hover:bg-[#6A1B9A] transition-colors font-medium shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 duration-200">
                        Log In
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <div class="mt-24 w-full max-w-6xl grid grid-cols-1 md:grid-cols-2 gap-0 bg-white dark:bg-[#1e1924] rounded-2xl shadow-2xl overflow-hidden border border-[#e2dbe6] dark:border-[#382bf0]/20">
        
        <!-- Left Panel: Info (Hidden on mobile to focus on form) -->
        <div class="hidden md:flex flex-col justify-center p-12 bg-purple-50 relative overflow-hidden">
            <div class="absolute top-0 right-0 w-64 h-64 bg-primary/5 rounded-full blur-3xl -translate-y-1/2 translate-x-1/2"></div>
            <div class="absolute bottom-0 left-0 w-64 h-64 bg-[#6A1B9A]/5 rounded-full blur-3xl translate-y-1/2 -translate-x-1/2"></div>

            <div class="relative z-10">
                <h2 class="text-3xl font-bold mb-6 text-primary">Join Our Family</h2>
                <div class="space-y-6">
                    <div class="flex items-start gap-4">
                        <div class="p-3 rounded-xl bg-white shadow-sm text-primary">
                            <span class="material-symbols-outlined">family_restroom</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg mb-1">Family Accounts</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Manage appointments for yourself and your dependents.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-4">
                        <div class="p-3 rounded-xl bg-white shadow-sm text-primary">
                            <span class="material-symbols-outlined">health_and_safety</span>
                        </div>
                        <div>
                            <h3 class="font-bold text-lg mb-1">Comprehensive Care</h3>
                            <p class="text-sm text-gray-600 dark:text-gray-400">Track your medical history and dental records securely.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel: Registration Form -->
        <div class="p-8 md:p-12 overflow-y-auto max-h-[85vh] relative bg-white dark:bg-[#1e1924]">
            <div class="mb-6">
                <h2 class="text-2xl font-bold mb-2">Create an Account</h2>
                <p class="text-gray-600 dark:text-gray-400 text-sm">Step <span id="step-count"><?php echo $initialStep; ?></span> of 2</p>
                <!-- Progress Bar -->
                <div class="w-full bg-gray-200 rounded-full h-1.5 mt-2">
                    <div class="bg-primary h-1.5 rounded-full transition-all duration-300" id="progress-bar" style="width: <?php echo ($initialStep === 2) ? '100%' : '50%'; ?>"></div>
                </div>
            </div>

            <?php if (!empty($errors)): ?>
                <div class="mb-6 bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg text-sm">
                    <ul class="list-disc list-inside">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-5" onsubmit="return validateForm()" novalidate>
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- STEP 1: Personal Information -->
                <div id="step-1" class="space-y-5 fade-in <?php echo ($initialStep === 2) ? 'hidden' : ''; ?>">
                    <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider border-b pb-2">Personal Information</h3>
                    
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">First Name *</label>
                            <input type="text" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Middle Name</label>
                            <input type="text" name="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Last Name *</label>
                            <input type="text" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Date of Birth *</label>
                            <input type="date" id="dob" name="dob" required onchange="calculateAge()" value="<?php echo htmlspecialchars($_POST['dob'] ?? ''); ?>" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                            <p id="age-msg" class="text-xs mt-1 hidden"></p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Sex *</label>
                            <select name="sex" required class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                                <option value="">Select Sex</option>
                                <option value="Male" <?php echo (isset($_POST['sex']) && $_POST['sex'] === 'Male') ? 'selected' : ''; ?>>Male</option>
                                <option value="Female" <?php echo (isset($_POST['sex']) && $_POST['sex'] === 'Female') ? 'selected' : ''; ?>>Female</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                         <div>
                            <label class="block text-sm font-medium mb-1">Civil Status *</label>
                            <select name="civil_status" required class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                                <option value="">Select Status</option>
                                <option value="Single">Single</option>
                                <option value="Married">Married</option>
                                <option value="Widowed">Widowed</option>
                                <option value="Separated">Separated</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Blood Type *</label>
                            <select name="blood_type" required class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                                <option value="">Select Blood Type</option>
                                <option value="Unknown">Unknown</option>
                                <option value="A+">A+</option>
                                <option value="A-">A-</option>
                                <option value="B+">B+</option>
                                <option value="B-">B-</option>
                                <option value="O+">O+</option>
                                <option value="O-">O-</option>
                                <option value="AB+">AB+</option>
                                <option value="AB-">AB-</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Occupation (Optional)</label>
                        <input type="text" name="occupation" value="<?php echo htmlspecialchars($_POST['occupation'] ?? ''); ?>" placeholder="e.g. Teacher, Engineer" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">Medical History / Allergies (Optional)</label>
                        <textarea name="medical_history" rows="3" placeholder="Please list any allergies, past surgeries, or medical conditions..." class="w-full p-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50 resize-none"><?php echo htmlspecialchars($_POST['medical_history'] ?? ''); ?></textarea>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Contact Number *</label>
                            <input type="tel" name="contact" required value="<?php echo htmlspecialchars($_POST['contact'] ?? ''); ?>" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Email Address *</label>
                            <input type="email" name="email" required value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                        </div>
                    </div>

                    <!-- Address Section with API -->
                    <div class="space-y-4">
                        <label class="block text-sm font-semibold text-gray-500 uppercase tracking-wider border-b pb-2">Address Information</label>
                        
                        <!-- Hidden concatenated address field for backend compatibility -->
                        <input type="hidden" name="address" id="full_address">

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Province *</label>
                                <select id="province" required onchange="fetchCities()" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                                    <option value="">Select Province</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">City / Municipality *</label>
                                <select id="city" required onchange="fetchBarangays()" disabled class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50 disabled:bg-gray-200 disabled:cursor-not-allowed">
                                    <option value="">Select City/Municipality</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium mb-1">Barangay *</label>
                                <select id="barangay" required onchange="updateFullAddress()" disabled class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50 disabled:bg-gray-200 disabled:cursor-not-allowed">
                                    <option value="">Select Barangay</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium mb-1">Street / House No. *</label>
                                <input type="text" id="street" required oninput="updateFullAddress()" placeholder="Lot 1 Blk 2, Street Name" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                            </div>
                        </div>
                    </div>

                    <div class="pt-4 flex justify-end">
                        <button type="button" onclick="nextStep()" class="bg-primary text-white px-8 py-3 rounded-xl font-bold hover:bg-[#6A1B9A] transition-all flex items-center gap-2">
                            Next: Credentials
                            <span class="material-symbols-outlined">arrow_forward</span>
                        </button>
                    </div>
                </div>

                <!-- STEP 2: Credentials -->
                <div id="step-2" class="space-y-5 fade-in <?php echo ($initialStep === 1) ? 'hidden' : ''; ?>">
                    <h3 class="text-sm font-semibold text-gray-500 uppercase tracking-wider border-b pb-2">Account Credentials</h3>
                    
                    <div>
                        <label class="block text-sm font-medium mb-1">Username *</label>
                        <input type="text" name="username" required value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">Password *</label>
                            <input type="password" name="password" required class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">Confirm Password *</label>
                            <input type="password" name="confirm_password" required class="w-full h-11 px-4 rounded-xl border border-gray-200 focus:border-primary focus:ring-2 focus:ring-primary/20 transition-all bg-gray-50">
                        </div>
                    </div>

                    <div class="flex items-center gap-3 pt-4">
                        <input type="checkbox" name="agree_terms" id="agree_terms" required class="w-5 h-5 rounded border-gray-300 text-primary focus:ring-primary">
                        <label for="agree_terms" class="text-sm text-gray-600 dark:text-gray-400">
                            I agree to the <a href="#" class="text-primary hover:underline">Terms of Service</a> and <a href="#" class="text-primary hover:underline">Privacy Policy</a>
                        </label>
                    </div>

                    <div class="pt-6 flex gap-4">
                         <button type="button" onclick="prevStep()" class="flex-1 bg-gray-100 text-gray-700 h-12 rounded-xl font-bold hover:bg-gray-200 transition-all flex items-center justify-center gap-2">
                            <span class="material-symbols-outlined">arrow_back</span>
                            Back
                        </button>
                        <button type="submit" id="submit-btn" class="flex-[2] bg-primary text-white h-12 rounded-xl font-bold shadow-lg hover:shadow-xl hover:bg-[#6A1B9A] transition-all transform hover:-translate-y-0.5">
                            Create Account
                        </button>
                    </div>
                </div>


                <p class="text-center text-sm text-gray-600 dark:text-gray-400">
                    Already have an account? 
                    <a href="landing.php" class="text-primary font-bold hover:underline">Log In</a>
                </p>
            </form>
        </div>
    </div>

    </div>

    <!-- Custom Modal -->
    <div id="custom-modal" class="fixed inset-0 z-[100] hidden">
        <div class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity" onclick="closeModal()"></div>
        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 bg-white rounded-2xl shadow-2xl p-6 w-full max-w-sm animate-[popIn_0.3s_ease-out]">
            <div class="flex flex-col items-center text-center">
                <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mb-4 text-red-600">
                    <span class="material-symbols-outlined text-3xl">priority_high</span>
                </div>
                <h3 class="text-xl font-bold mb-2 text-gray-800">Attention Needed</h3>
                <p id="modal-message" class="text-gray-600 mb-6 font-medium"></p>
                <button onclick="closeModal()" class="w-full bg-primary text-white py-3 rounded-xl font-bold hover:bg-[#6A1B9A] transition-colors">
                    Understood
                </button>
            </div>
        </div>
    </div>

    <style>
        @keyframes popIn {
            from { opacity: 0; transform: translate(-50%, -40%); }
            to { opacity: 1; transform: translate(-50%, -50%); }
        }
    </style>

    <script>
        function showModal(message) {
            document.getElementById('modal-message').textContent = message;
            document.getElementById('custom-modal').classList.remove('hidden');
        }

        function closeModal() {
            document.getElementById('custom-modal').classList.add('hidden');
        }

        function nextStep() {
            // Clear previous error styles
            document.querySelectorAll('.border-red-500').forEach(el => el.classList.remove('border-red-500'));
            
            let valid = true;
            let firstErrorInput = null;

            // 1. Check Required Fields in Step 1
            const step1Inputs = document.querySelectorAll('#step-1 input[required], #step-1 select[required]');
            step1Inputs.forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('border-red-500');
                    valid = false;
                    if (!firstErrorInput) firstErrorInput = input;
                }
            });

            // 2. Validate Age (Strict Calculation: Must be 18+)
            const dobInput = document.getElementById('dob');
            if (dobInput.value) {
                const dobDate = new Date(dobInput.value);
                const todayDate = new Date();
                let age = todayDate.getFullYear() - dobDate.getFullYear();
                const m = todayDate.getMonth() - dobDate.getMonth();
                if (m < 0 || (m === 0 && todayDate.getDate() < dobDate.getDate())) {
                    age--;
                }

                if (age < 18) {
                    dobInput.classList.add('border-red-500');
                    showModal("You must be 18 or older to create an account. Please ask a parent or guardian.");
                    valid = false;
                    if (!firstErrorInput) firstErrorInput = dobInput;
                }
            } else {
                 // DOB is empty (handled by required check, but good to be safe)
                 dobInput.classList.add('border-red-500');
                 valid = false;
                 if (!firstErrorInput) firstErrorInput = dobInput;
            }

            // 3. Validate Email Format
            const emailInput = document.querySelector('input[name="email"]');
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (emailInput.value && !emailRegex.test(emailInput.value)) {
                emailInput.classList.add('border-red-500');
            if (emailInput.value && !emailRegex.test(emailInput.value)) {
                emailInput.classList.add('border-red-500');
                showModal("Please enter a valid email address.");
                valid = false;
                if (!firstErrorInput) firstErrorInput = emailInput;
            }
                valid = false;
                if (!firstErrorInput) firstErrorInput = emailInput;
            }

            // 4. Validate Contact Number (Simple check)
            const contactInput = document.querySelector('input[name="contact"]');
            if (contactInput.value && contactInput.value.length < 10) {
                 contactInput.classList.add('border-red-500');
                 valid = false;
                 if (!firstErrorInput) firstErrorInput = contactInput;
            }

            // 5. Validate Address (Ensure dropdowns are selected)
            const province = document.getElementById('province');
            const city = document.getElementById('city');
            const barangay = document.getElementById('barangay');
            
            if (!province.value || !city.value || !barangay.value) {
                valid = false;
                if (!province.value) province.classList.add('border-red-500');
                if (!city.value) city.classList.add('border-red-500');
                if (!barangay.value) barangay.classList.add('border-red-500');
                if (!firstErrorInput) firstErrorInput = province;
            }


            if (valid) {
                document.getElementById('step-1').classList.add('hidden');
                document.getElementById('step-2').classList.remove('hidden');
                document.getElementById('progress-bar').style.width = '100%';
                document.getElementById('step-count').innerText = '2';
                window.scrollTo(0, 0);
            } else {
                // Focus the first invalid field
                if (firstErrorInput) {
                    firstErrorInput.focus();
                    firstErrorInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                // Optional: Show a toast or generic alert if specific alerts weren't triggered
                // alert("Please correct the highlighted fields before proceeding.");
            }
        }

        function prevStep() {
            document.getElementById('step-2').classList.add('hidden');
            document.getElementById('step-1').classList.remove('hidden');
            document.getElementById('progress-bar').style.width = '50%';
            document.getElementById('step-count').innerText = '1';
        }

        function validateForm() {
            // Re-run nextStep validation to ensure Step 1 is still valid (in case manipulated)
            // Note: Since we are in Step 2, we assume Step 1 is fine or we can re-check simple required fields if needed.
            // But let's focus on Step 2 validation mainly + Password Match

            const password = document.querySelector('input[name="password"]');
            const confirm = document.querySelector('input[name="confirm_password"]');
            const username = document.querySelector('input[name="username"]');
            const agree = document.querySelector('input[name="agree_terms"]');

            if (!username.value.trim()) {
                username.classList.add('border-red-500');
                showModal("Please enter a username.");
                return false;
            }

            if (!password.value || !confirm.value) {
                if (!password.value) password.classList.add('border-red-500');
                if (!confirm.value) confirm.classList.add('border-red-500');
                showModal("Please fill out both password fields.");
                return false;
            }

            if (password.value.length < 8) {
                password.classList.add('border-red-500');
                showModal("Password must be at least 8 characters long.");
                return false;
            }

            if (password.value !== confirm.value) {
                confirm.classList.add('border-red-500');
                showModal("Passwords do not match.");
                return false;
            }

            if (!agree.checked) {
                showModal("You must agree to the Terms of Service.");
                return false;
            }

            return true;
        }
        
        // ... (API scripts remain same)
        // Address API Integration
        const psgcBaseUrl = 'https://psgc.gitlab.io/api';

        async function fetchProvinces() {
            try {
                const response = await fetch(`${psgcBaseUrl}/provinces/`);
                const data = await response.json();
                const sortedData = data.sort((a, b) => a.name.localeCompare(b.name));
                
                const provinceSelect = document.getElementById('province');
                sortedData.forEach(prov => {
                    const option = document.createElement('option');
                    option.value = prov.code;
                    option.textContent = prov.name;
                    // Auto-select Bulacan if easier
                    // if (prov.name === 'Bulacan') option.selected = true; 
                    provinceSelect.appendChild(option);
                });
            } catch (error) {
                console.error('Error fetching provinces:', error);
            }
        }

        async function fetchCities() {
            const provinceCode = document.getElementById('province').value;
            const citySelect = document.getElementById('city');
            const barangaySelect = document.getElementById('barangay');
            
            citySelect.innerHTML = '<option value="">Select City/Municipality</option>';
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            citySelect.disabled = true;
            barangaySelect.disabled = true;

            if (!provinceCode) return;

            try {
                const response = await fetch(`${psgcBaseUrl}/provinces/${provinceCode}/cities-municipalities/`);
                const data = await response.json();
                const sortedData = data.sort((a, b) => a.name.localeCompare(b.name));

                citySelect.disabled = false;
                sortedData.forEach(city => {
                    const option = document.createElement('option');
                    option.value = city.code;
                    option.textContent = city.name;
                    citySelect.appendChild(option);
                });
                updateFullAddress();
            } catch (error) {
                console.error('Error fetching cities:', error);
            }
        }

        async function fetchBarangays() {
            const cityCode = document.getElementById('city').value;
            const barangaySelect = document.getElementById('barangay');
            
            barangaySelect.innerHTML = '<option value="">Select Barangay</option>';
            barangaySelect.disabled = true;

            if (!cityCode) return;

            try {
                // Determine if it's a city or municipality endpoint - simpler to just try cities-municipalities endpoint
                // PSGC API has a generic endpoint for both: /cities-municipalities/{code}/barangays
                const response = await fetch(`${psgcBaseUrl}/cities-municipalities/${cityCode}/barangays/`);
                const data = await response.json();
                const sortedData = data.sort((a, b) => a.name.localeCompare(b.name));

                barangaySelect.disabled = false;
                sortedData.forEach(brgy => {
                    const option = document.createElement('option');
                    option.value = brgy.name; // Use name directly for address string
                    option.textContent = brgy.name;
                    barangaySelect.appendChild(option);
                });
                updateFullAddress();
            } catch (error) {
                console.error('Error fetching barangays:', error);
            }
        }

        function updateFullAddress() {
            const provinceSelect = document.getElementById('province');
            const citySelect = document.getElementById('city');
            const barangaySelect = document.getElementById('barangay');
            const streetInput = document.getElementById('street');

            const province = provinceSelect.options[provinceSelect.selectedIndex]?.text || '';
            const city = citySelect.options[citySelect.selectedIndex]?.text || '';
            const barangay = barangaySelect.value || '';
            const street = streetInput.value || '';

            // Concatenate: "Street, Barangay, City, Province"
            let fullAddress = '';
            if (street) fullAddress += street + ', ';
            if (barangay) fullAddress += 'Brgy. ' + barangay + ', ';
            if (city) fullAddress += city + ', ';
            if (province && province !== 'Select Province') fullAddress += province;

            // Remove trailing comma/space
            document.getElementById('full_address').value = fullAddress.replace(/,\s*$/, "");
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', () => {
             fetchProvinces();
        });

        // Existing Age Calculation
        function calculateAge() {
            const dobInput = document.getElementById('dob');
            const ageMsg = document.getElementById('age-msg');
            const submitBtn = document.getElementById('submit-btn');
            
            if (!dobInput.value) return;

            const dob = new Date(dobInput.value);
            const today = new Date();
            // ... (rest of age logic is fine, let's keep it consistent with what we replaced)
            const current = new Date();
            let age = current.getFullYear() - dob.getFullYear();
            const m = current.getMonth() - dob.getMonth();
            
            if (m < 0 || (m === 0 && current.getDate() < dob.getDate())) {
                age--;
            }

            if (age < 18) {
                ageMsg.textContent = "You must be 18 or older to create an account. Please ask a parent/guardian.";
                ageMsg.className = "text-xs mt-1 text-red-600 font-bold";
                ageMsg.classList.remove('hidden');
                submitBtn.disabled = true;
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            } else {
                ageMsg.textContent = "Age: " + age + " (Adult)";
                ageMsg.className = "text-xs mt-1 text-green-600";
                ageMsg.classList.remove('hidden');
                submitBtn.disabled = false;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }
    </script>
</body>
</html>
