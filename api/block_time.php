<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/notification_helper.php';

// Configure secure session
configureSecureSession();

// Require Dentist role to access this API
requireRole('Dentist');

header('Content-Type: application/json');

// Get logged-in doctor
$doctor_id = $_SESSION['user_id'] ?? null;

if (!$doctor_id) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);
$block_date = isset($input['block_date']) ? sanitizeInput($input['block_date']) : '';
$start_time = isset($input['start_time']) ? sanitizeInput($input['start_time']) : '';
$end_time = isset($input['end_time']) ? sanitizeInput($input['end_time']) : '';
$reason = isset($input['reason']) ? sanitizeInput($input['reason']) : '';

// Validate inputs
if (empty($block_date) || empty($start_time) || empty($end_time)) {
    echo json_encode(['success' => false, 'message' => 'Date, start time, and end time are required']);
    exit;
}

// Validate time range
if ($start_time >= $end_time) {
    echo json_encode(['success' => false, 'message' => 'End time must be after start time']);
    exit;
}

try {
    // Check for conflicts with existing appointments
    $start_datetime = new DateTime($block_date . ' ' . $start_time);
    $end_datetime = new DateTime($block_date . ' ' . $end_time);
    
    $conflict_stmt = $pdo->prepare("
        SELECT appointment_id, time_slot
        FROM tbl_appointment 
        WHERE doctor_id = ? 
          AND appointment_date = ? 
          AND status NOT IN ('Cancelled', 'No-Show', 'Completed')
          AND (
              (time_slot >= ? AND time_slot < ?)
              OR (ADDTIME(time_slot, '01:00:00') > ? AND ADDTIME(time_slot, '01:00:00') <= ?)
          )
        LIMIT 1
    ");
    $conflict_stmt->execute([
        $doctor_id,
        $block_date,
        $start_time,
        $end_time,
        $start_time,
        $end_time
    ]);
    
    if ($conflict_stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Cannot block time: There are existing appointments during this period.'
        ]);
        exit;
    }
    
    // Check for overlapping blocked time
    $overlap_stmt = $pdo->prepare("
        SELECT block_id
        FROM tbl_blocked_time
        WHERE doctor_id = ?
          AND block_date = ?
          AND (
              (start_time < ? AND end_time > ?)
              OR (start_time < ? AND end_time > ?)
              OR (start_time >= ? AND end_time <= ?)
          )
        LIMIT 1
    ");
    $overlap_stmt->execute([
        $doctor_id,
        $block_date,
        $end_time, $start_time,
        $end_time, $start_time,
        $start_time, $end_time
    ]);
    
    if ($overlap_stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'This time period overlaps with an existing blocked time.'
        ]);
        exit;
    }
    
    // Insert blocked time into tbl_blocked_time
    $insert_stmt = $pdo->prepare("
        INSERT INTO tbl_blocked_time (doctor_id, block_date, start_time, end_time, reason)
        VALUES (?, ?, ?, ?, ?)
    ");
    $insert_stmt->execute([
        $doctor_id,
        $block_date,
        $start_time . ':00', // Ensure TIME format
        $end_time . ':00',
        $reason ?: null
    ]);
    
    $block_id = $pdo->lastInsertId();
    
    // --- Notify Receptionists ---
    try {
        // Get doctor name
        $docStmt = $pdo->prepare("SELECT full_name FROM tbl_user WHERE user_id = ?");
        $docStmt->execute([$doctor_id]);
        $doctor_name = $docStmt->fetchColumn() ?: 'A Doctor';
        
        $recStmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE role = 'Receptionist' AND status = 'Active'");
        $recStmt->execute();
        $receptionists = $recStmt->fetchAll(PDO::FETCH_COLUMN);
        
        $msg = "Dr. $doctor_name has blocked time on " . date('M d', strtotime($block_date)) . " (" . date('g:i A', strtotime($start_time)) . " - " . date('g:i A', strtotime($end_time)) . ").";
        
        foreach ($receptionists as $rec_id) {
            createNotification(
                $pdo,
                $rec_id,
                'Receptionist',
                'System',
                "Schedule Blocked",
                $msg,
                "receptionist_booking.php"
            );
        }

        // --- HOLIDAY EMAIL BLAST (If Reason is 'Holiday') ---
        if (stripos($reason, 'Holiday') !== false) {
             require_once __DIR__ . '/../includes/SMTPMailer.php';
             
             // Fetch ALL employees (everything except 'Patient')
             $staffStmt = $pdo->query("SELECT email, full_name, role FROM tbl_user WHERE role != 'Patient' AND status = 'Active' AND email IS NOT NULL AND email != ''");
             $staff_members = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

             $h_date = date('F j, Y', strtotime($block_date));
             $subject = "Clinic Holiday Announcement - $h_date";
             
             foreach ($staff_members as $staff) {
                 $body = "
                    <html>
                    <body style='font-family: Arial, sans-serif; color: #333;'>
                        <div style='max-width: 600px; margin: 0 auto; background: #fff; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                            <h2 style='color: #8a11d4; margin-bottom: 20px;'>Holiday Reminder</h2>
                            <p>Dear <strong>" . htmlspecialchars($staff['full_name']) . "</strong>,</p>
                            <p>This is a reminder that <strong>Vanessa Nicolas Dental Clinic</strong> will be closed on:</p>
                            <div style='background: #f9f9f9; padding: 15px; border-left: 4px solid #FFD700; margin: 20px 0;'>
                                <p style='margin: 0; font-size: 16px; font-weight: bold;'>" . $h_date . "</p>
                                <p style='margin: 5px 0 0; color: #666;'>" . htmlspecialchars($reason) . "</p>
                            </div>
                            <p>Please ensure your appointments are managed accordingly.</p>
                            <p style='margin-top: 30px; font-size: 12px; color: #999;'>Management System Notification</p>
                        </div>
                    </body>
                    </html>
                 ";
                 
                 try {
                     sendEmail($staff['email'], $subject, $body);
                 } catch (Exception $e) {
                     error_log("Failed to send holiday email to {$staff['email']}: " . $e->getMessage());
                 }
             }
        }
        // --- END HOLIDAY EMAIL ---

    } catch (Exception $e) {
        error_log("Notification error: " . $e->getMessage());
        // Continue, don't fail the block
    }
    // --- End Notification ---
    
    echo json_encode([
        'success' => true,
        'message' => 'Time blocked successfully',
        'block_id' => $block_id
    ]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Error blocking time: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error blocking time. Please try again.'
    ]);
}
?>
