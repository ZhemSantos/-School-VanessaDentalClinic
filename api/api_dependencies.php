<?php
// Temporary: Enable error display for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? '';
$guardian_id = $_SESSION['patient_id'];
$account_id = $_SESSION['user_id']; // For dependency table logic if it uses account_id

try {
    switch ($action) {
        case 'list':
            getDependents($pdo, $account_id);
            break;
        case 'create_dependent':
            createDependent($pdo, $guardian_id, $account_id);
            break;
        case 'update_dependent':
            updateDependent($pdo, $guardian_id);
            break;
        case 'generate_consent':
            generateConsentData($pdo, $guardian_id);
            break;
        default:
             echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function getDependents($pdo, $account_id) {
    // Assuming tbl_dependencies links via guardian_account_id -> sub_patient_id
    $stmt = $pdo->prepare("
        SELECT p.*, d.relationship, d.access_verified,
               TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age
        FROM tbl_dependencies d
        JOIN tbl_patient p ON d.sub_patient_id = p.patient_id
        WHERE d.guardian_account_id = ?
    ");
    $stmt->execute([$account_id]);
    echo json_encode(['success' => true, 'dependents' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function createDependent($pdo, $guardian_id, $account_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Guardian Validation (Must be 18+)
    $gStmt = $pdo->prepare("SELECT dob FROM tbl_patient WHERE patient_id = ?");
    $gStmt->execute([$guardian_id]);
    $gDob = $gStmt->fetchColumn();
    $age = date_diff(date_create($gDob), date_create('today'))->y;
    
    if ($age < 18) throw new Exception('Only adults (18+) can register dependents.');
    
    $fname = trim($input['first_name'] ?? '');
    $mname = trim($input['middle_name'] ?? '');
    $lname = trim($input['last_name'] ?? '');
    $dob = $input['dob'] ?? '';
    $gender = $input['gender'] ?? '';
    $phone = trim($input['phone_number'] ?? '');
    $email = trim($input['email'] ?? '');
    $address = trim($input['address'] ?? '');
    $blood_type = $input['blood_type'] ?? 'Unknown';
    $civil_status = $input['civil_status'] ?? 'Single';
    $relationship = $input['relationship'] ?? 'Child';
    
    // Validation
    if (!$fname || !$lname || !$dob || !$gender) {
        throw new Exception('First name, last name, date of birth, and gender are required');
    }
    
    // Phone validation (10-15 digits)
    if ($phone && !preg_match('/^[0-9]{10,15}$/', $phone)) {
        throw new Exception('Invalid phone number format (must be 10-15 digits)');
    }
    
    // Email validation
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Invalid email format');
    }
    
    // Calculate age and category for dependent
    $dep_dob = new DateTime($dob);
    $dep_age = $dep_dob->diff(new DateTime())->y;
    $age_category = 'Minor';
    if ($dep_age >= 60) $age_category = 'Senior';
    elseif ($dep_age >= 18) $age_category = 'Adult';
    
    $pdo->beginTransaction();
    
    // If dependent is using parent's email, set to NULL to avoid unique constraint
    // The parent's email will be used for notifications via the dependency relationship
    $guardianStmt = $pdo->prepare("SELECT email, first_name, last_name FROM tbl_patient WHERE patient_id = ?");
    $guardianStmt->execute([$guardian_id]);
    $guardianInfo = $guardianStmt->fetch(PDO::FETCH_ASSOC);
    $guardianEmail = $guardianInfo['email'] ?? '';
    $guardianName = ($guardianInfo['first_name'] ?? '') . ' ' . ($guardianInfo['last_name'] ?? '');
    
    $emailToSend = $email; // Store original email for sending
    if ($email && $guardianEmail && strtolower($email) === strtolower($guardianEmail)) {
        $email = null; // Use NULL to avoid duplicate email constraint
    }
    
    // Create Patient Record for dependent
    $ins = $pdo->prepare("
        INSERT INTO tbl_patient 
        (first_name, middle_name, last_name, dob, age, age_category, gender, 
         phone_number, email, address, blood_type, civil_status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ins->execute([
        $fname, $mname, $lname, $dob, $dep_age, $age_category, $gender,
        $phone ?: 'Linked-Guardian', 
        $email, 
        $address ?: 'Linked', 
        $blood_type, 
        $civil_status
    ]);
    $sub_patient_id = $pdo->lastInsertId();
    
    // Generate username and password
    // Username: firstname + 3 random numbers
    // Password: lastname + 6 random numbers
    $username = strtolower($fname) . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT);
    $password = $lname . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT);
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    
    // Create Patient Account
    $accIns = $pdo->prepare("
        INSERT INTO tbl_patient_accounts (patient_id, username, password_hash, is_verified, created_at)
        VALUES (?, ?, ?, 1, NOW())
    ");
    $accIns->execute([$sub_patient_id, $username, $hashedPassword]);
    
    // Determine if verification is needed (only for non-child relationships)
    $needsVerification = (strtolower($relationship) !== 'child');
    $verificationToken = null;
    $tokenExpires = null;
    
    if ($needsVerification) {
        // Generate verification token (valid for 24 hours)
        $verificationToken = bin2hex(random_bytes(32));
        $tokenExpires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    }
    
    // Link Dependency with verification status
    $link = $pdo->prepare("
        INSERT INTO tbl_dependencies 
        (guardian_account_id, sub_patient_id, relationship, access_verified, verification_token, token_expires) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $link->execute([
        $account_id, 
        $sub_patient_id, 
        $relationship, 
        !$needsVerification, // Auto-verify for children
        $verificationToken,
        $tokenExpires
    ]);
    
    $pdo->commit();
    
    // Send credentials email
    try {
        require_once '../includes/SMTPMailer.php';
        
        // Determine recipient email
        $recipientEmail = $emailToSend ?: $guardianEmail;
        $recipientName = $emailToSend ? "$fname $lname" : $guardianName;
        
        if ($recipientEmail) {
            $mailer = new SMTPMailer(
                'smtp.gmail.com:587',
                587,
                'vanessadentalclinictesting@gmail.com', // Replace with your SMTP username
                'jkme rgbx nfoj iamr' // Replace with your app password
            );
            
            // Email subject and body
            $subject = "Account Credentials - Vanessa Nicolas Dental Clinic";
            
            $body = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #6A1B9A, #8a11d4); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 10px 10px; }
                    .credentials { background: white; padding: 20px; border-left: 4px solid #FFD700; margin: 20px 0; }
                    .button { display: inline-block; background: #FFD700; color: #6A1B9A; padding: 12px 30px; text-decoration: none; border-radius: 5px; font-weight: bold; margin-top: 20px; }
                    .footer { text-align: center; margin-top: 20px; font-size: 12px; color: #666; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Welcome to VN Dental Clinic!</h1>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>$recipientName</strong>,</p>
                        <p>A new patient account has been created for <strong>$fname $lname</strong>.</p>
                        
                        <div class='credentials'>
                            <h3>Login Credentials:</h3>
                            <p><strong>Username:</strong> $username</p>
                            <p><strong>Password:</strong> $password</p>
                        </div>
                        
                        <p><strong>Important:</strong> Please change your password after first login for security.</p>
                        
                        <a href='http://localhost/landing.php' class='button'>Login Now</a>
                        
                        " . ($needsVerification ? "
                        <div style='background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin-top: 20px;'>
                            <h4 style='margin-top: 0;'>⚠️ Verification Required</h4>
                            <p>Since the relationship is '<strong>$relationship</strong>', the guardian requires your permission to access this account.</p>
                            <p><a href='http://localhost/verify_access.php?token=$verificationToken' style='color: #6A1B9A; font-weight: bold;'>Click here to verify access</a></p>
                            <p style='font-size: 12px; color: #666;'>This link expires in 24 hours.</p>
                        </div>
                        " : "") . "
                        
                        <div class='footer'>
                            <p>Vanessa Nicolas Dental Clinic</p>
                            <p>This is an automated message. Please do not reply.</p>
                        </div>
                    </div>
                </div>
            </body>
            </html>
            ";
            
            $mailer->send(
                $recipientEmail,
                $subject,
                $body,
                'VN Dental Clinic',
                'vanessadentalclinictesting@gmail.com' // Must match SMTP username
            );
        }
    } catch (Exception $e) {
        error_log("Failed to send credentials email: " . $e->getMessage());
        // Don't fail the whole operation if email fails
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Dependent account created successfully!',
        'needs_verification' => $needsVerification,
        'credentials_sent_to' => $recipientEmail
    ]);
}

function generateConsentData($pdo, $guardian_id) {
    $dependent_id = $_GET['dependent_id'] ?? 0;
    
    // Verify Link
    // We need to join via accounts table logic or just check general linkage?
    // tbl_dependencies uses account_id.
    // Let's get account_id of guardian from session (passed as $account_id in global scope, but need here)
    // Actually we can just query using sub_patient_id if we trust the link exists?
    // Better to verify.
    
    $actId = $_SESSION['user_id'];
    
    $stmt = $pdo->prepare("
        SELECT 
            g.first_name as g_fname, g.last_name as g_lname, g.address as g_address,
            d.first_name as d_fname, d.last_name as d_lname, d.dob as d_dob
        FROM tbl_dependencies dep
        JOIN tbl_patient g ON g.patient_id = ? -- current logged in guardian patient
        JOIN tbl_patient d ON dep.sub_patient_id = d.patient_id
        WHERE dep.sub_patient_id = ? AND dep.guardian_account_id = ?
    ");
    $stmt->execute([$guardian_id, $dependent_id, $actId]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$data) throw new Exception('Dependency record not found');
    
    echo json_encode(['success' => true, 'consent_data' => $data]);
}

function updateDependent($pdo, $guardian_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    $input = json_decode(file_get_contents('php://input'), true);
    
    $patient_id = $input['patient_id'] ?? null;
    if (!$patient_id) throw new Exception('Patient ID required');
    
    // Verify this dependent belongs to the current guardian
    $verifyStmt = $pdo->prepare("
        SELECT d.* FROM tbl_dependencies d
        WHERE d.sub_patient_id = ? AND d.guardian_account_id = (
            SELECT account_id FROM tbl_patient_accounts WHERE patient_id = ?
        )
    ");
    $verifyStmt->execute([$patient_id, $guardian_id]);
    if (!$verifyStmt->fetch()) {
        throw new Exception('Unauthorized: This dependent does not belong to you');
    }
    
    // Update patient information
    $updateStmt = $pdo->prepare("
        UPDATE tbl_patient SET
            first_name = ?,
            middle_name = ?,
            last_name = ?,
            dob = ?,
            gender = ?,
            civil_status = ?,
            blood_type = ?,
            phone_number = ?,
            email = ?,
            address = ?,
            medical_history = ?,
            age = TIMESTAMPDIFF(YEAR, ?, CURDATE())
        WHERE patient_id = ?
    ");
    
    $updateStmt->execute([
        $input['first_name'],
        $input['middle_name'] ?? null,
        $input['last_name'],
        $input['dob'],
        $input['gender'],
        $input['civil_status'] ?? null,
        $input['blood_type'] ?? 'Unknown',
        $input['phone_number'] ?? null,
        $input['email'] ?? null,
        $input['address'] ?? null,
        $input['medical_history'] ?? null,
        $input['dob'], // For age calculation
        $patient_id
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Dependent updated successfully']);
}
?>
