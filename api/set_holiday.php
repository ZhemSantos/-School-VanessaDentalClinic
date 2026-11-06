<?php
// 1. Output Buffering to prevent stray whitespace/warnings from breaking JSON
ob_start();

// 2. Error Reporting
ini_set('display_errors', 0); // Don't print to screen, we will return JSON
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// 3. Fatal Error Handler (Must be defined before anything else)
register_shutdown_function(function() {
    $error = error_get_last();
    // Check if it's a fatal error
    if ($error && ($error['type'] === E_ERROR || $error['type'] === E_PARSE || $error['type'] === E_COMPILE_ERROR || $error['type'] === E_CORE_ERROR)) {
        // Discard any partial output (warnings, HTML, etc)
        ob_clean(); 
        http_response_code(500); // Optional, but good practice
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false, 
            'message' => 'Fatal Error: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line']
        ]);
        exit;
    }
});

// 4. File Existence Check
$required = [
    '../db.php', 
    '../includes/security.php', 
    '../includes/notification_helper.php'
];
foreach($required as $f) {
    if(!file_exists(__DIR__ . '/' . $f)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => "Missing File: " . basename($f)]);
        exit;
    }
}

session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/notification_helper.php';
require_once '../includes/email_config.php'; // Defines sendEmail()

configureSecureSession();
requireRole('Admin'); // Admin only

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$start_date = isset($input['start_date']) ? sanitizeInput($input['start_date']) : '';
$end_date = isset($input['end_date']) ? sanitizeInput($input['end_date']) : '';
$reason = isset($input['reason']) ? sanitizeInput($input['reason']) : 'Holiday';
$is_whole_day = isset($input['is_whole_day']) ? (bool)$input['is_whole_day'] : false;

$start_time = isset($input['start_time']) ? sanitizeInput($input['start_time']) : '';
$end_time = isset($input['end_time']) ? sanitizeInput($input['end_time']) : '';

// Validation
if (empty($start_date) || empty($end_date)) {
    echo json_encode(['success' => false, 'message' => 'Start and End dates are required.']);
    exit;
}

if ($start_date > $end_date) {
    echo json_encode(['success' => false, 'message' => 'Start date cannot be after End date.']);
    exit;
}

// Set Times
if ($is_whole_day) {
    $start_time = '00:00';
    $end_time = '23:59';
} else {
    if (empty($start_time) || empty($end_time)) {
        echo json_encode(['success' => false, 'message' => 'Time range is required if not whole day.']);
        exit;
    }
    if ($start_time >= $end_time) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
        exit;
    }
}

try {
    // 1. Fetch Active Staff (Dentists & Receptionists)
    $stmt = $pdo->prepare("SELECT user_id, full_name, role FROM tbl_user WHERE role IN ('Dentist', 'Receptionist') AND status = 'Active'");
    $stmt->execute();
    $dentists = $stmt->fetchAll(PDO::FETCH_ASSOC); // Variable name kept as $dentists for minimal diff, or rename to $staff

    if (empty($dentists)) {
        echo json_encode(['success' => false, 'message' => 'No active dentists found to apply blocks to.']);
        exit;
    }

    // 2. Iterate Dates
    $current = new DateTime($start_date);
    $end = new DateTime($end_date);
    $end->modify('+1 day'); // Include end date in loop
    $interval = DateInterval::createFromDateString('1 day');
    $period = new DatePeriod($current, $interval, $end);

    $success_count = 0;
    $conflict_count = 0;
    $errors = [];

    // Prepare Statements
    $conflict_stmt = $pdo->prepare("
        SELECT appointment_id 
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

    $insert_stmt = $pdo->prepare("
        INSERT INTO tbl_blocked_time (doctor_id, block_date, start_time, end_time, reason)
        VALUES (?, ?, ?, ?, ?)
    ");

    foreach ($period as $dt) {
        $date_str = $dt->format('Y-m-d');
        
        foreach ($dentists as $doctor) {
            $doc_id = $doctor['user_id'];
            
            // Check Conflict (Just for reporting, DO NOT SKIP)
            $conflict_stmt->execute([
                $doc_id, $date_str, 
                $start_time, $end_time, 
                $start_time, $end_time
            ]);
            
            if ($conflict_stmt->fetch()) {
                $conflict_count++;
                // $errors[] = "Conflict for Dr. {$doctor['full_name']} on $date_str (Auto-Rescheduling)";
            }
            
            // Insert Block (Always proceed)
            $insert_stmt->execute([
                $doc_id, $date_str, 
                $start_time . ':00', 
                $end_time . ':00', 
                $reason
            ]);
            $success_count++;
        }

        // --- RESCHEDULE APPOINTMENTS (Next Available Slot with strict duration check) ---
        // Find appointments that need moving and get Patient/Guardian Email + Duration
        $apptStmt = $pdo->prepare("
            SELECT a.appointment_id, a.patient_id, a.doctor_id, a.time_slot, 
                   p.email AS patient_email, p.first_name, p.last_name,
                   g.email AS guardian_email,
                   COALESCE(SUM(s.estimated_duration_mins), 30) as duration_mins
            FROM tbl_appointment a
            JOIN tbl_patient p ON a.patient_id = p.patient_id
            LEFT JOIN tbl_dependencies d ON p.patient_id = d.sub_patient_id
            LEFT JOIN tbl_patient g ON d.guardian_account_id = g.account_id
            LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
            LEFT JOIN tbl_service s ON tas.service_id = s.service_id
            WHERE a.appointment_date = ? 
              AND a.status IN ('Pending', 'Booked', 'In-Procedure')
            GROUP BY a.appointment_id
        ");
        $apptStmt->execute([$date_str]);
        $affected_appointments = $apptStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!empty($affected_appointments)) {
             require_once __DIR__ . '/../includes/SMTPMailer.php';
             
             // Check Helper Statement for finding new slot (Dynamic Duration)
             // Params: [doc, date, exclude_id, start_buf, start_buf, doc, date, start_buf, start_buf]
             // We use ADDTIME(?, ?) where second ? is duration string
             $checkStmt = $pdo->prepare("
                SELECT 1 FROM tbl_appointment 
                WHERE doctor_id = ? AND appointment_date = ? 
                  AND status NOT IN ('Cancelled', 'No-Show', 'Completed')
                  AND appointment_id != ?
                  AND (
                      (time_slot < ADDTIME(?, ?) AND ADDTIME(time_slot, SEC_TO_TIME(estimated_duration_mins*60)) > ?)
                  )
                UNION
                SELECT 1 FROM tbl_blocked_time
                WHERE doctor_id = ? AND block_date = ?
                  AND (
                      (start_time < ADDTIME(?, ?) AND end_time > ?)
                  )
                LIMIT 1
             ");
             
             $updateStmt = $pdo->prepare("UPDATE tbl_appointment SET appointment_date = ?, time_slot = ? WHERE appointment_id = ?");

             foreach ($affected_appointments as $appt) {
                 // Calculate Duration String (e.g., '00:30:00')
                 $dur_mins = $appt['duration_mins'];
                 $dur_str = sprintf('00:%02d:00', $dur_mins);
                 
                 // Start searching from Next Day at SAME TIME
                 $base_time_str = $appt['time_slot']; // e.g., '10:00:00'
                 $current_search_date = new DateTime($date_str);
                 $current_search_date->modify('+1 day');
                 
                 // Limit search to 30 days
                 $days_checked = 0;
                 $found_slot = false;
                 $final_date = '';
                 $final_time = '';
                 
                 while ($days_checked < 30) {
                     $search_date_str = $current_search_date->format('Y-m-d');
                     
                     // 1. Generate Candidates
                     // Priority: Original Time -> Then every 15 mins from 9am-5pm
                     $candidates = [$base_time_str];
                     
                     $start_hr = 9; $end_hr = 17; // 9AM to 5PM
                     for ($h = $start_hr; $h < $end_hr; $h++) {
                         foreach(['00', '15', '30', '45'] as $m) {
                             $candidates[] = sprintf("%02d:%02d:00", $h, $m);
                         }
                     }
                     $candidates = array_unique($candidates); // Dedup
                     
                     // 2. Check Candidates
                     foreach ($candidates as $time_candidate) {
                         // Check params:
                         // 1. Appt Conflict: Doc, Date, SelfID, CandidateStart, DurationStr, CandidateStart
                         // 2. Block Conflict: Doc, Date, CandidateStart, DurationStr, CandidateStart
                         
                         // Wait, query above is: 
                         // Appt: time_slot < End(Candidate) AND End(Existing) > Candidate
                         // Block: start_time < End(Candidate) AND end_time > Candidate
                         
                         // Params needed in order:
                         // Doc, Date, SelfID,  Candidate, Duration, Candidate
                         // Doc, Date,          Candidate, Duration, Candidate
                         
                         $checkStmt->execute([
                             $appt['doctor_id'], $search_date_str, $appt['appointment_id'],
                             $time_candidate, $dur_str, $time_candidate, 
                             $appt['doctor_id'], $search_date_str,
                             $time_candidate, $dur_str, $time_candidate
                         ]);
                         
                         if (!$checkStmt->fetch()) {
                             // No conflict found!
                             $found_slot = true;
                             $final_date = $search_date_str;
                             $final_time = $time_candidate;
                             break 2; // Found, exit both loops
                         }
                     }
                     
                     // Not found on this day, move to next
                     $current_search_date->modify('+1 day');
                     $days_checked++;
                 } 
                 
                 if ($found_slot) {
                     // Update DB
                     $updateStmt->execute([$final_date, $final_time, $appt['appointment_id']]);
                     
                     // Notify Logic: Use patient email, if not exists, use guardian email
                     $target_email = !empty($appt['patient_email']) ? $appt['patient_email'] : $appt['guardian_email'];
                     $patient_name = $appt['first_name'] . ' ' . $appt['last_name'];
                     $old_date_fmt = date('F j, Y', strtotime($date_str));
                     $new_date_fmt = date('F j, Y', strtotime($final_date));
                     $time_fmt = date('g:i A', strtotime($final_time));
                     
                     if ($target_email) {
                         $subj = "Appointment Rescheduled - Vanessa Nicolas Dental Clinic";
                         $msg = "
                            <html>
                            <body style='font-family: Arial, sans-serif;'>
                                <div style='max-width: 600px; margin: 0 auto; background: #fff; padding: 20px; border: 1px solid #e0e0e0; border-radius: 8px;'>
                                    <h2 style='color: #d32f2f;'>Important: Appointment Rescheduled</h2>
                                    <p>Dear <strong>$patient_name</strong>,</p>
                                    <p>Due to a declared holiday (<strong>$reason</strong>) on <strong>$old_date_fmt</strong>, your appointment has been automatically moved to the nearest available slot (with a safety buffer).</p>
                                    
                                    <div style='background: #f5f5f5; padding: 15px; border-left: 4px solid #8a11d4; margin: 15px 0;'>
                                        <p style='font-size: 14px; color: #555; margin-bottom: 5px;'>New Schedule:</p>
                                        <p style='color: #8a11d4; font-size: 20px; font-weight: bold; margin: 0;'>$new_date_fmt</p>
                                        <p style='font-size: 16px; font-weight: bold; margin: 5px 0 0;'>$time_fmt</p>
                                    </div>
                                    
                                    <p>If this new schedule does not work for you, please login to your portal to reschedule or contact us immediately.</p>
                                    <p>We apologize for the inconvenience.</p>
                                    <p style='color: #888; font-size: 12px; margin-top: 20px;'>Vanessa Nicolas Dental Clinic</p>
                                </div>
                            </body>
                            </html>
                         ";
                         try {
                             sendEmail($target_email, $subj, $msg);
                         } catch (Exception $e) { error_log("Failed to send reschedule email to $target_email: " . $e->getMessage()); }
                     }
                 } else {
                     error_log("Could not find slot for Appt ID {$appt['appointment_id']} within 30 days.");
                 }
             }
        }
        // --- END RESCHEDULE ---

    }
    
    // --- NOTIFY STAFF (EMAIL BLAST) ---
    // Fetch ALL employees (everything except 'Patient')
    $staffStmt = $pdo->query("SELECT email, full_name, role FROM tbl_user WHERE role != 'Patient' AND status = 'Active' AND email IS NOT NULL AND email != ''");
    $staff_members = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($success_count > 0 && !empty($staff_members)) {
         require_once __DIR__ . '/../includes/SMTPMailer.php';

         $h_start_date = date('F j, Y', strtotime($start_date));
         $h_end_date = date('F j, Y', strtotime($end_date));
         $date_display = ($start_date === $end_date) ? $h_start_date : "$h_start_date to $h_end_date";
         
         $subject = "Clinic Holiday Announcement - $date_display";
         
         foreach ($staff_members as $staff) {
             $body = "
                <html>
                <body style='font-family: Arial, sans-serif; color: #333;'>
                    <div style='max-width: 600px; margin: 0 auto; background: #fff; padding: 20px; border: 1px solid #eee; border-radius: 8px;'>
                        <h2 style='color: #8a11d4; margin-bottom: 20px;'>Clinic Holiday Announcement</h2>
                        <p>Dear <strong>" . htmlspecialchars($staff['full_name']) . "</strong>,</p>
                        <p>This is to inform you that <strong>Vanessa Nicolas Dental Clinic</strong> will be closed for a holiday/block period.</p>
                        
                        <div style='background: #f9f9f9; padding: 15px; border-left: 4px solid #FFD700; margin: 20px 0;'>
                            <p style='margin: 0; font-size: 16px; font-weight: bold;'>$date_display</p>
                            <p style='margin: 5px 0 0; color: #666;'>" . htmlspecialchars($reason) . "</p>
                            " . (!$is_whole_day ? "<p style='margin: 5px 0 0; font-size:12px; color:#888;'>Time: " . date('g:i A', strtotime($start_time)) . " - " . date('g:i A', strtotime($end_time)) . "</p>" : "<p style='margin: 5px 0 0; font-size:12px; color:#888;'>Whole Day</p>") . "
                        </div>
                        
                        <p>Please be guided accordingly.</p>
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
    // --- END EMAIL BLAST ---

    echo json_encode([
        'success' => true, 
        'message' => "Successfully blocked $success_count slots. Found $conflict_count conflicts.",
        'details' => $errors
    ]);

} catch (PDOException $e) {
    error_log("Set Holiday Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
