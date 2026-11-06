<?php
session_start();
require_once 'db.php';

$token = $_GET['token'] ?? null;
$success = false;
$message = '';

if (!$token) {
    $message = "Invalid verification link.";
} else {
    // Find the dependency with this token
    $stmt = $pdo->prepare("
        SELECT d.*, p.first_name, p.last_name, g.first_name as guardian_fname, g.last_name as guardian_lname
        FROM tbl_dependencies d
        JOIN tbl_patient p ON d.sub_patient_id = p.patient_id
        JOIN tbl_patient_accounts pa ON d.guardian_account_id = pa.account_id
        JOIN tbl_patient g ON pa.patient_id = g.patient_id
        WHERE d.verification_token = ? AND d.token_expires > NOW()
    ");
    $stmt->execute([$token]);
    $dependency = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$dependency) {
        $message = "This verification link is invalid or has expired.";
    } elseif ($dependency['access_verified']) {
        $success = true;
        $message = "Access has already been verified.";
    } else {
        // Verify the access
        $updateStmt = $pdo->prepare("
            UPDATE tbl_dependencies 
            SET access_verified = TRUE, verification_token = NULL, token_expires = NULL
            WHERE verification_token = ?
        ");
        $updateStmt->execute([$token]);
        
        $success = true;
        $guardianName = $dependency['guardian_fname'] . ' ' . $dependency['guardian_lname'];
        $message = "Access verified successfully! {$guardianName} can now access your account.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Access - VN Dental Clinic</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
    <style>
        body { font-family: 'Manrope', sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-purple-50 to-blue-50 min-h-screen flex items-center justify-center p-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-2xl overflow-hidden">
        <div class="bg-gradient-to-r from-purple-600 to-purple-700 p-8 text-center">
            <div class="w-20 h-20 bg-white/20 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="material-symbols-outlined text-white text-5xl">
                    <?php echo $success ? 'check_circle' : 'error'; ?>
                </span>
            </div>
            <h1 class="text-2xl font-black text-white">
                <?php echo $success ? 'Verification Successful!' : 'Verification Failed'; ?>
            </h1>
        </div>
        
        <div class="p-8">
            <p class="text-slate-700 text-center mb-6 leading-relaxed">
                <?php echo htmlspecialchars($message); ?>
            </p>
            
            <?php if ($success): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-4 mb-6">
                    <p class="text-sm text-green-800">
                        <strong>What's next?</strong><br>
                        Your guardian can now access your account through the dependencies module.
                    </p>
                </div>
            <?php endif; ?>
            
            <a href="landing.php" class="block w-full py-3 bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold rounded-xl text-center transition-all">
                Go to Login
            </a>
        </div>
    </div>
</body>
</html>
