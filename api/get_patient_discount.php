<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['patient_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$patient_id = $_SESSION['patient_id'];

try {
    // Fetch patient discount information with age calculation
    $stmt = $pdo->prepare("
        SELECT 
            p.discount_type,
            p.discount_id_number,
            p.discount_id_issuer,
            p.discount_id_expiry,
            p.discount_verified_at,
            p.dob,
            TIMESTAMPDIFF(YEAR, p.dob, CURDATE()) as age,
            u.full_name as verified_by_name
        FROM tbl_patient p
        LEFT JOIN tbl_user u ON p.discount_verified_by = u.user_id
        WHERE p.patient_id = ?
    ");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        throw new Exception('Patient not found');
    }
    
    // Check if patient has a discount
    $has_discount = ($patient['discount_type'] !== 'None' && $patient['discount_type'] !== null);
    $is_valid = false;
    $validation_message = '';
    
    if ($has_discount) {
        $discount_type = $patient['discount_type'];
        
        // Validate based on discount type
        if ($discount_type === 'PWD') {
            // Check expiry date
            if (!empty($patient['discount_id_expiry'])) {
                $expiry = new DateTime($patient['discount_id_expiry']);
                $today = new DateTime();
                
                if ($expiry > $today) {
                    $is_valid = true;
                } else {
                    $validation_message = 'PWD ID has expired. Please update your ID at the clinic.';
                }
            } else {
                $validation_message = 'PWD ID expiry date not set.';
            }
        } elseif ($discount_type === 'Senior') {
            // Check age (must be 60+)
            $age = (int)$patient['age'];
            if ($age >= 60) {
                $is_valid = true;
            } else {
                $validation_message = 'Senior Citizen discount requires age 60 or above.';
                // Invalid - remove the discount
                $updateStmt = $pdo->prepare("
                    UPDATE tbl_patient 
                    SET discount_type = 'None',
                        discount_id_number = NULL,
                        discount_id_issuer = NULL,
                        discount_id_expiry = NULL
                    WHERE patient_id = ?
                ");
                $updateStmt->execute([$patient_id]);
                $has_discount = false;
            }
        }
    }
    
    // Calculate discount percentage (20% for both PWD and Senior)
    $discount_percentage = 20;
    
    // Format response
    $response = [
        'success' => true,
        'has_discount' => $has_discount,
        'has_valid_discount' => $is_valid,
        'discount_type' => $has_discount ? $patient['discount_type'] : 'None',
        'discount_percentage' => $is_valid ? $discount_percentage : 0,
        'id_number' => $patient['discount_id_number'] ?? null,
        'id_issuer' => $patient['discount_id_issuer'] ?? null,
        'expiry_date' => $patient['discount_id_expiry'] ?? null,
        'is_expired' => !$is_valid && $has_discount,
        'validation_message' => $validation_message,
        'verified_at' => $patient['discount_verified_at'] ?? null,
        'verified_by' => $patient['verified_by_name'] ?? null,
        'patient_age' => (int)$patient['age']
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    error_log('Error fetching patient discount: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
