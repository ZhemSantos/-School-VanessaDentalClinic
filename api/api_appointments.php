<?php
session_start();
require_once '../db.php';
require_once 'appointment_email_helper.php';

header('Content-Type: application/json');

// Check if user is logged in as patient
if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$action = $_GET['action'] ?? '';
$patient_id = $_SESSION['patient_id'];

try {
    switch ($action) {
        case 'get_doctors':
            getDoctors($pdo);
            break;
        case 'get_services':
            getServices($pdo);
            break;
        case 'get_slots':
            getAvailableSlots($pdo);
            break;
        case 'create_appointment':
            createAppointment($pdo, $patient_id);
            break;
        case 'get_appointments':
            getAppointments($pdo, $patient_id);
            break;
        case 'cancel_appointment':
            cancelAppointment($pdo, $patient_id);
            break;
        case 'update_appointment':
            updateAppointment($pdo, $patient_id);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

// ==========================================
// MIGRATION: Ensure Doctor Schedule Table Exists
// ==========================================
function ensureScheduleTableExists($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tbl_doctor_schedule (
                schedule_id INT AUTO_INCREMENT PRIMARY KEY,
                doctor_id INT NOT NULL,
                day_of_week ENUM('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') NOT NULL,
                start_time TIME NOT NULL,
                end_time TIME NOT NULL,
                is_available TINYINT(1) DEFAULT 1,
                UNIQUE KEY unique_doctor_day (doctor_id, day_of_week)
            )
        ");

        // Populate defaults for existing doctors if they have no schedule
        $stmt = $pdo->query("SELECT user_id FROM tbl_user WHERE role = 'Dentist'");
        $doctors = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
        
        foreach ($doctors as $docId) {
            $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_doctor_schedule WHERE doctor_id = ?");
            $check->execute([$docId]);
            if ($check->fetchColumn() == 0) {
                // Insert default 9-5 for Mon-Sat
                $ins = $pdo->prepare("INSERT INTO tbl_doctor_schedule (doctor_id, day_of_week, start_time, end_time) VALUES (?, ?, '09:00:00', '17:00:00')");
                foreach ($days as $day) {
                    $ins->execute([$docId, $day]);
                }
            }
        }
    } catch (Exception $e) {
        // Ignore errors
    }
}
// Run migration (safely)
ensureScheduleTableExists($pdo);

function getDoctors($pdo) {
    $stmt = $pdo->query("SELECT user_id, full_name, specialization, profile_picture FROM tbl_user WHERE role = 'Dentist' AND status = 'Active'");
    echo json_encode(['success' => true, 'doctors' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function getServices($pdo) {
    // Include prerequisite columns AND installment fields
    $stmt = $pdo->query("SELECT service_id, service_name, estimated_duration_mins, base_price, category, requires_checkup, requires_xray, is_installment_available, installment_down_payment FROM tbl_service ORDER BY category, service_name");
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cast numeric fields to proper types
    foreach ($services as &$service) {
        $service['is_installment_available'] = (int)$service['is_installment_available'];
        $service['installment_down_payment'] = (float)$service['installment_down_payment'];
        $service['base_price'] = (float)$service['base_price'];
        $service['estimated_duration_mins'] = (int)$service['estimated_duration_mins'];
        $service['requires_checkup'] = (int)$service['requires_checkup'];
        $service['requires_xray'] = (int)$service['requires_xray'];
    }
    
    echo json_encode(['success' => true, 'services' => $services]);
}

function getAvailableSlots($pdo) {
    $date = $_GET['date'] ?? date('Y-m-d');
    $doctor_id = $_GET['doctor_id'] ?? 0;
    
    if (!$doctor_id) {
        throw new Exception('Doctor is required');
    }

    // Define clinic hours (9:00 AM - 7:00 PM)
    $clinicStartTime = '09:00:00';
    $clinicEndTime = '19:00:00';
    
    // 1. Get Doctor's Schedule for the day
    $dateObj = new DateTime($date);
    $dayOfWeek = $dateObj->format('l'); // 'Monday', 'Tuesday', ...

    $schedStmt = $pdo->prepare("SELECT start_time, end_time FROM tbl_doctor_schedule WHERE doctor_id = ? AND day_of_week = ?");
    $schedStmt->execute([$doctor_id, $dayOfWeek]);
    $schedule = $schedStmt->fetch(PDO::FETCH_ASSOC);

    if (!$schedule) {
        error_log("Debug Schedule: DocID=$doctor_id, Date=$date, Day=$dayOfWeek - No schedule found.");
        echo json_encode(['success' => true, 'slots' => [], 'message' => "Doctor not available on $dayOfWeek ($date)"]); 
        return;
    }

    // Use clinic hours as base, but limit to doctor's availability
    // Start time = later of (clinic start, doctor start)
    // End time = earlier of (clinic end, doctor end)
    $doctorStart = $schedule['start_time'];
    $doctorEnd = $schedule['end_time'];
    
    $effectiveStart = (strtotime($doctorStart) > strtotime($clinicStartTime)) ? $doctorStart : $clinicStartTime;
    $effectiveEnd = (strtotime($doctorEnd) < strtotime($clinicEndTime)) ? $doctorEnd : $clinicEndTime;
    
    // If doctor's hours don't overlap with clinic hours, no slots available
    if (strtotime($effectiveStart) >= strtotime($effectiveEnd)) {
        echo json_encode(['success' => true, 'slots' => [], 'message' => 'No available slots within clinic hours']);
        return;
    }

    $startTime = strtotime($date . ' ' . $effectiveStart);
    $endTime = strtotime($date . ' ' . $effectiveEnd);

    // 2. Get Existing Appointments (Booked, Pending, etc.)
    $apptStmt = $pdo->prepare("
        SELECT time_slot, 
               (SELECT SUM(s.estimated_duration_mins) FROM tbl_appointment_service tas JOIN tbl_service s ON tas.service_id = s.service_id WHERE tas.appointment_id = a.appointment_id) as duration
        FROM tbl_appointment a
        WHERE doctor_id = ? AND appointment_date = ? AND status IN ('Booked', 'Arrived', 'In-Procedure')
    ");
    $apptStmt->execute([$doctor_id, $date]);
    $appointments = $apptStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Get Blocked Times
    $blockStmt = $pdo->prepare("SELECT start_time, end_time FROM tbl_blocked_time WHERE doctor_id = ? AND block_date = ?");
    $blockStmt->execute([$doctor_id, $date]);
    $blockedTimes = $blockStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Generate Slots (30 min intervals)
    $slots = [];
    $current = $startTime;
    $interval = 30 * 60; // 30 mins

    while ($current < $endTime) {
        $slotTime = date('H:i:s', $current);
        $isAvailable = true;

        // Check if slot overlaps with any appointment
        foreach ($appointments as $appt) {
            $apptStart = strtotime($date . ' ' . $appt['time_slot']);
            $apptDuration = ($appt['duration'] ?? 30) * 60;
            $apptEnd = $apptStart + $apptDuration;

            // If slot start is within an appointment time, it's taken
            if ($current >= $apptStart && $current < $apptEnd) {
                $isAvailable = false;
                break;
            }
        }

        // Check blocked times
        if ($isAvailable) {
            foreach ($blockedTimes as $block) {
                $blockStart = strtotime($date . ' ' . $block['start_time']);
                $blockEnd = strtotime($date . ' ' . $block['end_time']);
                
                if ($current >= $blockStart && $current < $blockEnd) {
                    $isAvailable = false;
                    break;
                }
            }
        }
        
        // Prevent booking in the past for today
        if ($date === date('Y-m-d') && $current < time()) {
             $isAvailable = false;
        }

        if ($isAvailable) {
            $slots[] = date('H:i', $current);
        }
        $current += $interval;
    }

    echo json_encode(['success' => true, 'slots' => $slots, 'clinic_hours' => ['start' => $clinicStartTime, 'end' => $clinicEndTime], 'doctor_hours' => ['start' => $doctorStart, 'end' => $doctorEnd]]);
}

function createAppointment($pdo, $patient_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    $doctor_id = $input['doctor_id'] ?? 0;
    $date = $input['date'] ?? '';
    $time = $input['time'] ?? '';
    $service_ids = $input['service_ids'] ?? []; // Array of IDs

    if (!$doctor_id || !$date || !$time || empty($service_ids)) {
        throw new Exception('Missing required fields');
    }
    
    // --- ONE APPOINTMENT PER CLIENT CHECK ---
    $existingStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM tbl_appointment 
        WHERE patient_id = ? 
          AND status NOT IN ('Cancelled', 'No-Show', 'Completed')
    ");
    $existingStmt->execute([$patient_id]);
    
    if ($existingStmt->fetchColumn() > 0) {
        echo json_encode([
            'success' => false,
            'message' => 'You already have an active appointment. Please cancel it before booking a new one.'
        ]);
        return;
    }
    // --- END ONE APPOINTMENT CHECK ---
    
    // --- Prerequisite Check ---
    // 1. Fetch requirements for selected services
    $placeholders = str_repeat('?,', count($service_ids) - 1) . '?';
    $srvStmt = $pdo->prepare("SELECT service_name, requires_checkup, requires_xray FROM tbl_service WHERE service_id IN ($placeholders)");
    $srvStmt->execute($service_ids);
    $services = $srvStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $needsCheckup = false;
    $needsXray = false;
    $serviceNames = [];
    
    foreach ($services as $svc) {
        if ($svc['requires_checkup']) $needsCheckup = true;
        if ($svc['requires_xray']) $needsXray = true;
        $serviceNames[] = $svc['service_name']; 
    }
    
    // 2. If needed, check patient history (Completed appointments within last 6 months?)
    // Note: This logic assumes 'Check-up' or 'X-ray' are service names or categories.
    // For robustness, we'd check if they have specific service IDs or just any recent 'Completed' record.
    // Simplifying: Check if they have ANY 'Completed' appointment in last 6 months for Checkup.
    // For X-ray, check if they have a file of type 'xray'? Or just a service named 'X-Ray'.
    
    $missingRequirements = [];
    
    if ($needsCheckup) {
         $chkStmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_appointment WHERE patient_id = ? AND status = 'Completed' AND appointment_date > DATE_SUB(NOW(), INTERVAL 6 MONTH)");
         $chkStmt->execute([$patient_id]);
         if ($chkStmt->fetchColumn() == 0) {
             $missingRequirements[] = "Recent Dental Check-up (within 6 months)";
         }
    }
    
    // For X-ray, it's trickier without knowing exactly which X-ray. 
    // We'll check if they have ANY file of type 'xray' or a completed service with 'X-Ray' in name.
    if ($needsXray) {
        $xrayParams = [$patient_id, $patient_id];
        $xraySql = "
            SELECT 
                (SELECT COUNT(*) FROM tbl_patient_file WHERE patient_id = ? AND file_type LIKE '%xray%') + 
                (SELECT COUNT(*) FROM tbl_appointment a JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id JOIN tbl_service s ON tas.service_id = s.service_id WHERE a.patient_id = ? AND a.status = 'Completed' AND s.service_name LIKE '%X-Ray%')
            as count
        ";
        $xrayStmt = $pdo->prepare($xraySql);
        $xrayStmt->execute($xrayParams);
        if ($xrayStmt->fetchColumn() == 0) {
             $missingRequirements[] = "Recent X-Ray Record";
        }
    }
    
    if (!empty($missingRequirements)) {
        echo json_encode([
            'success' => false, 
            'message' => 'Prerequisites missing: ' . implode(', ', $missingRequirements) . '. Please book these services first.',
            'is_prerequisite_error' => true
        ]);
        return;
    }
    
    // --- END Prerequisite Check ---

    // --- CONSENT FORM CHECK (For Minors) ---
    // 1. Calculate Patient Age
    $dobStmt = $pdo->prepare("SELECT dob FROM tbl_patient WHERE patient_id = ?");
    $dobStmt->execute([$patient_id]);
    $dob = $dobStmt->fetchColumn();
    
    if ($dob) {
        $age = (new DateTime($dob))->diff(new DateTime())->y;
        
        if ($age < 18) {
            // Minor detected: Check for required consent forms
            
            // Fetch service categories and their requirements
            // JOIN with tbl_consent_requirement to get rules efficiently
            try {
                $reqSql = "
                    SELECT s.service_name, s.category, 
                           COALESCE(cr.requires_gncf, 0) as requires_gncf, 
                           COALESCE(cr.requires_sscf, 0) as requires_sscf
                    FROM tbl_service s
                    LEFT JOIN tbl_consent_requirement cr ON s.category = cr.category_name
                    WHERE s.service_id IN ($placeholders)
                ";
                $reqStmt = $pdo->prepare($reqSql);
                $reqStmt->execute($service_ids);
                $serviceReqs = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Log the actual error for debugging
                error_log("Consent check query error: " . $e->getMessage());
                error_log("Query was: " . $reqSql);
                throw new Exception("Database error checking consent requirements: " . $e->getMessage());
            }
            
            $missingConsents = [];
            $checkedCategories = []; // To avoid checking same category multiple times for SSCF
            $gncfChecked = false;

            foreach ($serviceReqs as $req) {
                // Check GNCF (General Consent) - Check only once if any service needs it
                if ($req['requires_gncf'] && !$gncfChecked) {
                    $gncfStmt = $pdo->prepare("
                        SELECT COUNT(*) FROM tbl_patient_file 
                        WHERE patient_id = ? 
                        AND consent_type = 'GNCF' 
                        AND is_signed = 1 
                        AND (expiration_date IS NULL OR expiration_date >= CURDATE())
                    ");
                    $gncfStmt->execute([$patient_id]);
                    if ($gncfStmt->fetchColumn() == 0) {
                        $missingConsents[] = "General Parent Consent Form";
                    }
                    $gncfChecked = true;
                }
                
                // Check SSCF (Specific Service Consent) - Check per unique category
                if ($req['requires_sscf'] && !in_array($req['category'], $checkedCategories)) {
                    $sscfStmt = $pdo->prepare("
                        SELECT COUNT(*) FROM tbl_patient_file 
                        WHERE patient_id = ? 
                        AND consent_type = 'SSCF' 
                        AND consent_category = ?
                        AND is_signed = 1 
                        AND (expiration_date IS NULL OR expiration_date >= CURDATE())
                    ");
                    $sscfStmt->execute([$patient_id, $req['category']]);
                    if ($sscfStmt->fetchColumn() == 0) {
                        $missingConsents[] = "Consent for " . $req['category'];
                    }
                    $checkedCategories[] = $req['category'];
                }
            }
            
            if (!empty($missingConsents)) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Parent consent missing for minor: ' . implode(', ', $missingConsents) . '. Please ensure a parent/guardian signs these forms in the "My Dependents" section.',
                    'is_consent_error' => true
                ]);
                return;
            }
        }
    }
    // --- END CONSENT FORM CHECK ---

    // --- CONFLICT DETECTION WITH PENDING OVERRIDE ---
    // Get total service duration for this appointment
    $durationStmt = $pdo->prepare("SELECT SUM(estimated_duration_mins) FROM tbl_service WHERE service_id IN (" . str_repeat('?,', count($service_ids) - 1) . "?)");
    $durationStmt->execute($service_ids);
    $totalDuration = $durationStmt->fetchColumn() ?: 30;
    
    // Calculate appointment end time
    $startTime = new DateTime($date . ' ' . $time);
    $endTime = clone $startTime;
    $endTime->modify('+' . $totalDuration . ' minutes');
    
    // Check for conflicts with existing appointments
    $conflictStmt = $pdo->prepare("
        SELECT a.appointment_id, a.patient_id, a.status, a.time_slot,
               p.email, CONCAT(p.first_name, ' ', p.last_name) as full_name,
               SUM(s.estimated_duration_mins) as duration
        FROM tbl_appointment a
        JOIN tbl_patient p ON a.patient_id = p.patient_id
        JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.doctor_id = ? 
          AND a.appointment_date = ?
          AND a.status NOT IN ('Cancelled', 'No-Show', 'Completed')
        GROUP BY a.appointment_id
    ");
    $conflictStmt->execute([$doctor_id, $date]);
    $existingAppointments = $conflictStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pendingToCancel = null;
    $affectedPatient = null;
    
    foreach ($existingAppointments as $appt) {
        $existingStart = new DateTime($date . ' ' . $appt['time_slot']);
        $existingEnd = clone $existingStart;
        $existingEnd->modify('+' . $appt['duration'] . ' minutes');
        
        // Check for overlap: (new_start < existing_end) AND (new_end > existing_start)
        if ($startTime < $existingEnd && $endTime > $existingStart) {
            if ($appt['status'] === 'Pending') {
                // Allow conflict (multiple pending requests allowed)
                continue;
            } else {
                // Booked/Confirmed appointment - cannot override
                echo json_encode([
                    'success' => false,
                    'message' => 'This time slot is taken by a confirmed (paid) appointment. Please choose a different time.'
                ]);
                return;
            }
        }
    }
    // --- END CONFLICT DETECTION ---

    $pdo->beginTransaction();
    
    // Cancel pending appointment if conflict detected
    // Cancel pending appointment logic removed (allowing overlaps)

    // Create Appointment
    $insStmt = $pdo->prepare("INSERT INTO tbl_appointment (patient_id, doctor_id, appointment_date, time_slot, status) VALUES (?, ?, ?, ?, 'Pending')");
    $insStmt->execute([$patient_id, $doctor_id, $date, $time]);
    $appt_id = $pdo->lastInsertId();

    // Link Services
    $svcInsStmt = $pdo->prepare("INSERT INTO tbl_appointment_service (appointment_id, service_id, price_at_booking) VALUES (?, ?, (SELECT base_price FROM tbl_service WHERE service_id = ?))");
    foreach ($service_ids as $sid) {
        $svcInsStmt->execute([$appt_id, $sid, $sid]);
    }

    $pdo->commit();
    
    // Send email notification to affected patient if their appointment was cancelled
    if ($affectedPatient) {
        sendAppointmentCancellationEmail($affectedPatient);
    }
    
    // Send Email (Mock function call - implement actual mailer if needed)
    // sendAppointmentConfirmationEmail($patient_id, $appt_id);

    echo json_encode(['success' => true, 'message' => 'Appointment request submitted successfully']);
}

function getAppointments($pdo, $patient_id) {
    $stmt = $pdo->prepare("
        SELECT a.appointment_id, a.appointment_date, a.time_slot, a.status, a.appointment_fee_paid, a.doctor_id,
               u.full_name as doctor_name, 
               GROUP_CONCAT(s.service_name SEPARATOR ', ') as services
        FROM tbl_appointment a
        JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.patient_id = ?
        GROUP BY a.appointment_id
        ORDER BY a.appointment_date DESC, a.time_slot DESC
    ");
    $stmt->execute([$patient_id]);
    echo json_encode(['success' => true, 'appointments' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function cancelAppointment($pdo, $patient_id) {
    if($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    $input = json_decode(file_get_contents('php://input'), true);
    $appt_id = $input['appointment_id'];

    // Verify ownership and status
    $stmt = $pdo->prepare("SELECT status FROM tbl_appointment WHERE appointment_id = ? AND patient_id = ?");
    $stmt->execute([$appt_id, $patient_id]);
    $appt = $stmt->fetch();

    if (!$appt) throw new Exception('Appointment not found');
    if ($appt['status'] === 'Completed') throw new Exception('Cannot cancel completed appointment');

    $upd = $pdo->prepare("UPDATE tbl_appointment SET status = 'Cancelled' WHERE appointment_id = ?");
    $upd->execute([$appt_id]);

    echo json_encode(['success' => true, 'message' => 'Appointment cancelled']);
}

function updateAppointment($pdo, $patient_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $appt_id = $input['appointment_id'] ?? 0;
    $new_date = $input['date'] ?? '';
    $new_time = $input['time'] ?? '';
    
    if (!$appt_id || !$new_date || !$new_time) {
        throw new Exception('Missing required fields');
    }
    
    // Verify appointment belongs to patient and get current details
    $stmt = $pdo->prepare("
        SELECT status, doctor_id, appointment_date, time_slot 
        FROM tbl_appointment 
        WHERE appointment_id = ? AND patient_id = ?
    ");
    $stmt->execute([$appt_id, $patient_id]);
    $appt = $stmt->fetch();
    
    if (!$appt) throw new Exception('Appointment not found');
    
    // Don't allow editing completed/cancelled appointments
    if (in_array($appt['status'], ['Completed', 'Cancelled', 'No-Show'])) {
        throw new Exception('Cannot edit ' . strtolower($appt['status']) . ' appointments');
    }
    
    // Check if the new slot is available (if date/time changed)
    if ($appt['appointment_date'] != $new_date || $appt['time_slot'] != $new_time) {
        // Check for conflicts
        $conflictStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM tbl_appointment 
            WHERE doctor_id = ? 
              AND appointment_date = ? 
              AND time_slot = ?
              AND status IN ('Booked', 'Arrived', 'In-Procedure')
              AND appointment_id != ?
        ");
        $conflictStmt->execute([$appt['doctor_id'], $new_date, $new_time, $appt_id]);
        
        if ($conflictStmt->fetchColumn() > 0) {
            throw new Exception('This time slot is no longer available');
        }
    }
    
    // Update appointment (only date and time, NOT status)
    $upd = $pdo->prepare("
        UPDATE tbl_appointment 
        SET appointment_date = ?, time_slot = ? 
        WHERE appointment_id = ?
    ");
    $upd->execute([$new_date, $new_time, $appt_id]);
    
    echo json_encode(['success' => true, 'message' => 'Appointment updated successfully']);
}
?>
