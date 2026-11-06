<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role to access this page
requireRole('Receptionist');

// Update current user's activity
updateUserActivity();

// Fetch current user's full details from database
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $user_details_stmt = $pdo->prepare("SELECT user_id, username, full_name, role, email, contact_number, blood_type, status, profile_picture, created_at FROM tbl_user WHERE user_id = ? LIMIT 1");
        $user_details_stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $user_details_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching user details: ' . $e->getMessage());
    }
}

// Helper function to generate time dropdown with 15-minute intervals
function generateTimeDropdown($name, $value = '', $min_time = '00:00', $max_time = '23:45', $classes = '', $disabled = false, $required = false, $data_attr = '', $id = '') {
    $options = [];
    
    // Normalize the value to HH:MM format (round to nearest 15 minutes if needed)
    $normalized_value = '';
    if (!empty($value)) {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
            $hour = (int)$matches[1];
            $min = (int)$matches[2];
            // Round to nearest 15 minutes
            $min = round($min / 15) * 15;
            if ($min >= 60) {
                $min = 0;
                $hour++;
            }
            $normalized_value = sprintf('%02d:%02d', $hour, $min);
        }
    }
    
    // Parse min and max times
    list($min_hour, $min_min) = explode(':', $min_time);
    list($max_hour, $max_min) = explode(':', $max_time);
    $min_total = (int)$min_hour * 60 + (int)$min_min;
    $max_total = (int)$max_hour * 60 + (int)$max_min;
    
    // Generate time options in 15-minute intervals
    for ($hour = 0; $hour < 24; $hour++) {
        for ($min = 0; $min < 60; $min += 15) {
            $time_total = $hour * 60 + $min;
            if ($time_total >= $min_total && $time_total <= $max_total) {
                $time_str = sprintf('%02d:%02d', $hour, $min);
                $time_display = date('g:i A', strtotime($time_str));
                $options[] = [
                    'value' => $time_str,
                    'display' => $time_display,
                    'selected' => ($normalized_value === $time_str)
                ];
            }
        }
    }
    
    $disabled_attr = $disabled ? 'disabled' : '';
    $required_attr = $required ? 'required' : '';
    $data_attr_html = $data_attr ? ' ' . $data_attr : '';
    $id_attr = $id ? ' id="' . htmlspecialchars($id) . '"' : '';
    
    $html = '<select name="' . htmlspecialchars($name) . '"' . $id_attr . ' class="' . htmlspecialchars($classes) . '" ' . $disabled_attr . ' ' . $required_attr . $data_attr_html . '>';
    $html .= '<option value="">Select time...</option>';
    foreach ($options as $option) {
        $selected = $option['selected'] ? 'selected' : '';
        $html .= '<option value="' . htmlspecialchars($option['value']) . '" ' . $selected . '>' . htmlspecialchars($option['display']) . '</option>';
    }
    $html .= '</select>';
    
    return $html;
}

// Helper function to get default profile photo
function getDefaultProfilePhoto($full_name, $size = 40) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    
    $colors = [
        ['bg' => '#FFD700', 'text' => '#6A1B9A'],
        ['bg' => '#6A1B9A', 'text' => '#ffffff'],
        ['bg' => '#8a11d4', 'text' => '#ffffff'],
        ['bg' => '#10b981', 'text' => '#ffffff'],
        ['bg' => '#3b82f6', 'text' => '#ffffff'],
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    
    return [
        'initials' => $initials,
        'bg_color' => $color['bg'],
        'text_color' => $color['text']
    ];
}

$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Receptionist';
$current_role = $current_user['role'] ?? 'Receptionist';
$current_profile = $current_user['profile_picture'] ?? null;
$default_photo = getDefaultProfilePhoto($current_name);

// Get current page for active navigation state
$current_page = basename($_SERVER['PHP_SELF']);

// Get appointment ID from URL
$appointment_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($appointment_id <= 0) {
    header("Location: receptionist_booking.php");
    exit;
}

// Fetch appointment details
$appointment = null;
try {
    $appt_stmt = $pdo->prepare("
        SELECT a.*, 
               p.first_name, p.last_name, p.patient_id, p.email, p.phone_number,
               GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
               GROUP_CONCAT(s.service_id) as current_service_ids,
               SUM(s.estimated_duration_mins) as total_duration,
               u.full_name as doctor_name
        FROM tbl_appointment a
        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.appointment_id = ?
        GROUP BY a.appointment_id
    ");
    $appt_stmt->execute([$appointment_id]);
    $appointment = $appt_stmt->fetch();
    
    if (!$appointment) {
        header("Location: receptionist_booking.php?error=Appointment not found");
        exit;
    }
} catch (PDOException $e) {
    error_log('Error fetching appointment: ' . $e->getMessage());
    header("Location: receptionist_booking.php?error=Error loading appointment");
    exit;
}

// Fetch dentists for dropdown
$dentists = [];
try {
    $dentist_stmt = $pdo->prepare("SELECT user_id, full_name FROM tbl_user WHERE role = 'Dentist' AND status = 'Active' ORDER BY full_name");
    $dentist_stmt->execute();
    $dentists = $dentist_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching dentists: ' . $e->getMessage());
}

// Fetch services for dropdown
$services = [];
try {
    $service_stmt = $pdo->prepare("SELECT service_id, service_name, base_price, estimated_duration_mins, category, is_installment_available, installment_down_payment, installment_duration_months, installment_interest_percent, requires_checkup, requires_xray FROM tbl_service ORDER BY service_name");
    $service_stmt->execute();
    $services = $service_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching services: ' . $e->getMessage());
}

// Fetch existing installment details (if any)
$existing_installments = [];
$active_installment_ids = [];
$is_on_installment = false;
$existing_dp_total = 0;
$existing_months = 0;
try {
    $inst_stmt = $pdo->prepare("SELECT * FROM tbl_installment WHERE appointment_id = ? AND status != 'Cancelled'");
    $inst_stmt->execute([$appointment_id]);
    $existing_installments = $inst_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($existing_installments) > 0) {
        $is_on_installment = true;
        foreach ($existing_installments as $inst) {
            $active_installment_ids[] = $inst['service_id'];
            $existing_months = $inst['months_total']; 
            $existing_dp_total += floatval($inst['down_payment_paid']);
        }
    }
} catch (PDOException $e) {
    error_log('Error fetching installments: ' . $e->getMessage());
}

// Fetch Installment Rules (Category-Based) - Same as creation pages
$installment_rules = [
    'General Dentistry' => ['max_months' => 6, 'min_dp' => 20],
    'Orthodontics' => ['max_months' => 24, 'min_dp' => 20],
    'Pediatric Dentistry (Pedodontics)' => ['max_months' => 6, 'min_dp' => 20],
    'Periodontics' => ['max_months' => 12, 'min_dp' => 20],
    'Prosthodontics' => ['max_months' => 12, 'min_dp' => 30],
    'Endodontics' => ['max_months' => 6, 'min_dp' => 20],
    'Oral and Maxillofacial Surgery' => ['max_months' => 6, 'min_dp' => 20],
    'Oral and Maxillofacial Pathology' => ['max_months' => 3, 'min_dp' => 20],
    'Oral and Maxillofacial Radiology' => ['max_months' => 0, 'min_dp' => 0],
    'Dental Anesthesiology' => ['max_months' => 0, 'min_dp' => 0],
    'Dental Public Health' => ['max_months' => 0, 'min_dp' => 0],
    'Oral Medicine' => ['max_months' => 6, 'min_dp' => 20],
    'Special Needs Dentistry' => ['max_months' => 12, 'min_dp' => 20],
    'Implantology' => ['max_months' => 18, 'min_dp' => 30]
];


// Handle form submission
$success_message = '';
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_appointment'])) {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        // Get form data
        $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        $doctor_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        $service_ids = !empty($_POST['service_ids']) ? $_POST['service_ids'] : '';
        $appointment_date = $_POST['appointment_date'] ?? '';
        $appointment_time = $_POST['appointment_time'] ?? '';
        $status = $_POST['status'] ?? 'Pending';
        
        // Parse service IDs (comma-separated)
        $service_id_array = [];
        if (!empty($service_ids)) {
            $service_id_array = array_filter(array_map('intval', explode(',', $service_ids)));
            $service_id_array = array_unique($service_id_array); // Remove duplicates
        }
        
        // Validation
        $errors = [];
        
        if ($patient_id <= 0) $errors[] = 'Please select a patient.';
        if ($doctor_id <= 0) $errors[] = 'Please select a dentist.';
        if (empty($service_id_array)) $errors[] = 'Please select at least one service.';
        if (empty($appointment_date)) $errors[] = 'Appointment date is required.';
        if (empty($appointment_time)) $errors[] = 'Appointment time is required.';
        
        if (empty($appointment_time)) $errors[] = 'Appointment time is required.';
        
        // --- CONSENT VERIFICATION (Minors) - Check on Update too ---
        if (empty($errors)) {
            try {
                // 1. Check Age
                $dobStmt = $pdo->prepare("SELECT dob FROM tbl_patient WHERE patient_id = ?");
                $dobStmt->execute([$patient_id]);
                $dob = $dobStmt->fetchColumn();
                
                if ($dob) {
                    $age = (new DateTime($dob))->diff(new DateTime())->y;
                    
                    if ($age < 18) {
                        // 2. Fetch Requirements
                        // Create placeholders for IN clause
                        $placeholders = str_repeat('?,', count($service_id_array) - 1) . '?';
                        $reqSql = "
                            SELECT s.service_name, s.category, 
                                   COALESCE(cr.requires_gncf, 0) as requires_gncf, 
                                   COALESCE(cr.requires_sscf, 0) as requires_sscf
                            FROM tbl_service s
                            LEFT JOIN tbl_consent_requirement cr ON s.category = cr.category_name
                            WHERE s.service_id IN ($placeholders)
                        ";
                        $reqStmt = $pdo->prepare($reqSql);
                        $reqStmt->execute($service_id_array);
                        $serviceReqs = $reqStmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        $checkedCategories = [];
                        $gncfChecked = false;
                        
                        foreach ($serviceReqs as $req) {
                            // Check GNCF
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
                                    $errors[] = "Missing 'General Parent Consent Form' for minor patient. Please upload/sign in Patient Records.";
                                }
                                $gncfChecked = true;
                            }
                            
                            // Check SSCF
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
                                    $errors[] = "Missing 'Consent for " . $req['category'] . "' for minor patient. Please upload/sign in Patient Records.";
                                }
                                $checkedCategories[] = $req['category'];
                            }
                        }
                    }
                }
            } catch (Exception $e) {
                error_log("Consent Check Error: " . $e->getMessage());
            }
        }
        // --- END CONSENT VERIFICATION ---
        
        // Validate date is not in the past (unless it's today and time hasn't passed)
        if (!empty($appointment_date)) {
            $appt_datetime = new DateTime($appointment_date . ' ' . $appointment_time);
            $now = new DateTime();
            // Allow editing if it's the same appointment (for rescheduling)
            $original_datetime = new DateTime($appointment['appointment_date'] . ' ' . $appointment['time_slot']);
            if ($appt_datetime < $now && $appt_datetime != $original_datetime) {
                $errors[] = 'Appointment date and time cannot be in the past.';
            }
        }
        
        // Validate clinic operating hours (9:00 AM - 7:00 PM)
        if (!empty($appointment_time)) {
            $time_obj = new DateTime($appointment_time);
            $hour = (int)$time_obj->format('H');
            $minute = (int)$time_obj->format('i');
            $time_in_minutes = $hour * 60 + $minute;
            $clinic_open = 9 * 60; // 9:00 AM in minutes
            $clinic_close = 19 * 60; // 7:00 PM (19:00) in minutes
            
            if ($time_in_minutes < $clinic_open || $time_in_minutes >= $clinic_close) {
                $errors[] = 'Appointment time must be between 9:00 AM and 7:00 PM (clinic operating hours).';
            } else {
                // Calculate total service duration from all selected services
                $total_service_duration = 0;
                foreach ($service_id_array as $sid) {
                    foreach ($services as $service) {
                        if ($service['service_id'] == $sid) {
                            $total_service_duration += (int)$service['estimated_duration_mins'];
                            break;
                        }
                    }
                }
                
                if ($total_service_duration == 0) {
                    $total_service_duration = 30; // Default 30 minutes if no services found
                }
                
                $appt_end_time = clone $time_obj;
                $appt_end_time->modify('+' . $total_service_duration . ' minutes');
                $end_hour = (int)$appt_end_time->format('H');
                $end_minute = (int)$appt_end_time->format('i');
                $end_time_in_minutes = $end_hour * 60 + $end_minute;
                
                if ($end_time_in_minutes > $clinic_close) {
                    $errors[] = 'The total service duration (' . $total_service_duration . ' minutes) would extend beyond clinic closing time (7:00 PM). Please choose an earlier time or remove some services.';
                }
            }
        }
        
        // Check doctor's schedule availability
        if (empty($errors) && $doctor_id > 0 && !empty($appointment_date) && !empty($appointment_time)) {
            try {
                // Get day of week from appointment date
                $date_obj = new DateTime($appointment_date);
                $day_of_week = $date_obj->format('l'); // e.g., "Monday", "Tuesday"
                
                // Check if doctor has schedule for this day
                $schedule_stmt = $pdo->prepare("
                    SELECT start_time, end_time
                    FROM tbl_doctor_schedule
                    WHERE doctor_id = ? AND day_of_week = ?
                ");
                $schedule_stmt->execute([$doctor_id, $day_of_week]);
                $doctor_schedule = $schedule_stmt->fetch();
                
                if (!$doctor_schedule) {
                    $errors[] = 'The selected doctor is not available on ' . $day_of_week . '. Please choose a different date or doctor.';
                } else {
                    // Check if appointment time falls within doctor's schedule
                    $appt_time = new DateTime($appointment_time);
                    $doctor_start = new DateTime($doctor_schedule['start_time']);
                    $doctor_end = new DateTime($doctor_schedule['end_time']);
                    
                    // Calculate total service duration from all selected services
                    $total_service_duration = 0;
                    foreach ($service_id_array as $sid) {
                        foreach ($services as $service) {
                            if ($service['service_id'] == $sid) {
                                $total_service_duration += (int)$service['estimated_duration_mins'];
                                break;
                            }
                        }
                    }
                    
                    if ($total_service_duration == 0) {
                        $total_service_duration = 30; // Default 30 minutes if no services found
                    }
                    
                    // Calculate appointment end time
                    $appt_end_time = clone $appt_time;
                    $appt_end_time->modify('+' . $total_service_duration . ' minutes');
                    
                    // Check if appointment start time is before doctor's start time
                    if ($appt_time < $doctor_start) {
                        $errors[] = 'Appointment time is before the doctor\'s available hours. Doctor is available from ' . $doctor_start->format('g:i A') . ' to ' . $doctor_end->format('g:i A') . ' on ' . $day_of_week . '.';
                    }
                    
                    // Check if appointment end time is after doctor's end time
                    if ($appt_end_time > $doctor_end) {
                        $errors[] = 'Appointment end time exceeds the doctor\'s available hours. Doctor is available until ' . $doctor_end->format('g:i A') . ' on ' . $day_of_week . '.';
                    }
                }
            } catch (PDOException $e) {
                error_log('Error checking doctor schedule: ' . $e->getMessage());
                $errors[] = 'Error checking doctor availability. Please try again.';
            }
        }
        
        // Check for time conflicts with existing appointments (excluding current appointment)
        if (empty($errors) && $doctor_id > 0 && !empty($appointment_date) && !empty($appointment_time)) {
            try {
                // Calculate total service duration from all selected services
                $total_service_duration = 0;
                foreach ($service_id_array as $sid) {
                    foreach ($services as $service) {
                        if ($service['service_id'] == $sid) {
                            $total_service_duration += (int)$service['estimated_duration_mins'];
                            break;
                        }
                    }
                }
                
                if ($total_service_duration == 0) {
                    $total_service_duration = 30; // Default 30 minutes if no services found
                }
                
                // Calculate end time
                $start_time = new DateTime($appointment_date . ' ' . $appointment_time);
                $end_time = clone $start_time;
                $end_time->modify('+' . $total_service_duration . ' minutes');
                
                // Check for conflicts with blocked time first
                $blocked_time_stmt = $pdo->prepare("
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
                $blocked_time_stmt->execute([
                    $doctor_id,
                    $appointment_date,
                    $end_time->format('H:i:s'), $appointment_time,
                    $end_time->format('H:i:s'), $appointment_time,
                    $appointment_time, $end_time->format('H:i:s')
                ]);
                
                if ($blocked_time_stmt->fetch()) {
                    $errors[] = 'This time slot conflicts with blocked time. Please choose a different time.';
                }
                
                // Check for conflicts with existing appointments (excluding current appointment)
                if (empty($errors)) {
                    $conflict_stmt = $pdo->prepare("
                        SELECT a.appointment_id, a.time_slot, 
                               COALESCE(SUM(s.estimated_duration_mins), 30) as estimated_duration_mins
                        FROM tbl_appointment a
                        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                        WHERE a.doctor_id = ? 
                          AND a.appointment_date = ?
                          AND a.appointment_id != ?
                          AND a.status IN ('Booked', 'Arrived', 'In-Procedure', 'Pending')
                        GROUP BY a.appointment_id
                        HAVING (
                               time_slot < ? 
                               AND 
                               ADDTIME(time_slot, SEC_TO_TIME(estimated_duration_mins * 60)) > ?
                           )
                    ");
                    $conflict_stmt->execute([
                        $doctor_id,
                        $appointment_date,
                        $appointment_id,
                        $end_time->format('H:i:s'), // New End Time
                        $appointment_time           // New Start Time
                    ]);
                    
                    if ($conflict_stmt->fetch()) {
                        $errors[] = 'This time slot conflicts with an existing appointment. Please choose a different time.';
                    }
                }
            } catch (PDOException $e) {
                error_log('Error checking conflicts: ' . $e->getMessage());
                $errors[] = 'Error checking appointment availability. Please try again.';
            }
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                $service_names = [];
                $first_service_id = $service_id_array[0];
                
                
                // Update existing appointment
                // Note: We don't rely on 'service_id' in tbl_appointment for M:N, but we can set it to the first service if needed for legacy compatibility.
                $update_stmt = $pdo->prepare("
                    UPDATE tbl_appointment 
                    SET patient_id = ?, doctor_id = ?, appointment_date = ?, time_slot = ?, status = ?
                    WHERE appointment_id = ?
                ");
                $update_stmt->execute([
                    $patient_id,
                    $doctor_id,
                    $appointment_date,
                    $appointment_time,
                    $status,
                    $appointment_id
                ]);
                
                // Update Services (Delete all and Re-insert)
                $delete_services = $pdo->prepare("DELETE FROM tbl_appointment_service WHERE appointment_id = ?");
                $delete_services->execute([$appointment_id]);

                $insert_service_stmt = $pdo->prepare("INSERT INTO tbl_appointment_service (appointment_id, service_id, price_at_booking) VALUES (?, ?, ?)");
                
                foreach ($service_id_array as $sid) {
                    // Find price
                    $price = 0;
                    foreach ($services as $s) {
                        if ($s['service_id'] == $sid) {
                            $price = $s['base_price'];
                            break;
                        }
                    }
                    $insert_service_stmt->execute([$appointment_id, $sid, $price]);
                    
                    // Collect service name
                    foreach ($services as $service) {
                        if ($service['service_id'] == $sid) {
                            $service_names[] = $service['service_name'];
                            break;
                        }
                    }
                }

                // Get doctor name for email
                $doctor_name = '';
                foreach ($dentists as $dentist) {
                    if ($dentist['user_id'] == $doctor_id) {
                        $doctor_name = $dentist['full_name'];
                        break;
                    }
                }
                
                // Send email notification if appointment details changed
                $changed = false;
                if ($appointment['appointment_date'] != $appointment_date || 
                    $appointment['time_slot'] != $appointment_time ||
                    $appointment['doctor_id'] != $doctor_id ||
                    $appointment['service_id'] != $first_service_id ||
                    count($service_id_array) > 1) {
                    $changed = true;
                }
                
                if ($changed) {
                    // require_once '../includes/email_notifications.php'; // Optional for walkin, but good to have if email exists
                    // Walk-ins might not have emails, but if they do, same logic applies.
                }
                
                // Auto-cancel conflicting Pending appointments if this appointment is Booked
                if ($status === 'Booked') {
                    // Check for conflicting pending appointments and cancel them
                    $checkPending = $pdo->prepare("
                        SELECT a.appointment_id, a.time_slot, SUM(s.estimated_duration_mins) as duration
                        FROM tbl_appointment a
                        JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                        JOIN tbl_service s ON tas.service_id = s.service_id
                        WHERE a.doctor_id = ? 
                          AND a.appointment_date = ?
                          AND a.status = 'Pending'
                          AND a.appointment_id != ?
                        GROUP BY a.appointment_id
                    ");
                    $checkPending->execute([$doctor_id, $appointment_date, $appointment_id]);
                    $pendings = $checkPending->fetchAll();
                    
                    $thisStart = new DateTime($appointment_date . ' ' . $appointment_time);
                    $thisEnd = clone $thisStart;
                     $thisDuration = 0;
                     foreach ($service_id_array as $sid) {
                        foreach ($services as $s) {
                            if ($s['service_id'] == $sid) {
                                $thisDuration += $s['estimated_duration_mins'];
                                break;
                            }
                        }
                    }
                    if($thisDuration == 0) $thisDuration = 30;
                    $thisEnd->modify("+$thisDuration minutes");
                    
                    foreach ($pendings as $p) {
                         $pStart = new DateTime($appointment_date . ' ' . $p['time_slot']);
                         $pEnd = clone $pStart;
                         $pEnd->modify("+" . ($p['duration']?:30) . " minutes");
                         
                         if ($thisStart < $pEnd && $thisEnd > $pStart) {
                             $pdo->prepare("UPDATE tbl_appointment SET status = 'Cancelled' WHERE appointment_id = ?")->execute([$p['appointment_id']]);
                         }
                    }
                }
                
                $pdo->commit();
                
                // Redirect
                header("Location: receptionist_edit_walkin.php?id=" . $appointment_id . "&success=walkin_updated");
                exit;
                
            } catch (PDOException $e) {
                error_log('Error updating appointment: ' . $e->getMessage());
                $error_message = 'Failed to update appointment. Please try again.';
                $form_data = $_POST;
            }
        } else {
            $error_message = implode(' ', $errors);
            $form_data = $_POST;
        }
    }
}

// Prepare patient data for display
$selected_patient = [
    'patient_id' => $appointment['patient_id'],
    'first_name' => $appointment['first_name'],
    'last_name' => $appointment['last_name'],
    'phone_number' => $appointment['phone_number'],
    'email' => $appointment['email']
];

// Generate CSRF token
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Edit Walk-In - Receptionist Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
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
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-[#151118] font-display antialiased overflow-hidden">
<div class="flex h-screen w-full">
<aside class="w-72 bg-royal-purple text-white flex flex-col flex-shrink-0 h-full shadow-2xl z-20 relative">
<div class="p-8 pb-4">
<div class="flex flex-col gap-1">
<h1 class="text-xl font-bold leading-tight tracking-wide">Vanessa Nicolas</h1>
<p class="text-white/70 text-sm font-medium">Dental Clinic System</p>
</div>
</div>
<div class="px-8 py-4 mb-2">
<a href="receptionist_current_profile.php" class="flex items-center gap-3 bg-white/10 p-3 rounded-xl border border-white/10 backdrop-blur-sm hover:bg-white/20 transition-all cursor-pointer group">
<?php if ($current_profile && file_exists('../' . $current_profile)): ?>
<div class="w-10 h-10 rounded-full bg-cover bg-center flex-shrink-0 border-2 border-transparent group-hover:border-white/50 transition-all" style="background-image: url('../<?php echo htmlspecialchars($current_profile); ?>');"></div>
<?php else: ?>
<div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-lg flex-shrink-0 border-2 border-transparent group-hover:border-white/50 transition-all" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;">
<?php echo htmlspecialchars($default_photo['initials']); ?>
</div>
<?php endif; ?>
<div class="flex flex-col overflow-hidden">
<span class="text-sm font-bold truncate group-hover:text-white transition-colors"><?php echo htmlspecialchars($current_name); ?></span>
<span class="text-xs text-white/70 truncate group-hover:text-white/90 transition-colors"><?php echo htmlspecialchars($current_role); ?></span>
</div>
</a>
</div>
<nav class="flex-1 px-4 gap-2 flex flex-col overflow-y-auto py-4">
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_dashboard.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_dashboard.php">
<span class="material-symbols-outlined">dashboard</span>
<span class="<?php echo ($current_page === 'receptionist_dashboard.php') ? 'font-bold' : 'font-medium'; ?>">Dashboard</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_registration.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_registration.php">
<span class="material-symbols-outlined">person_add</span>
<span class="<?php echo ($current_page === 'receptionist_registration.php') ? 'font-bold' : 'font-medium'; ?>">Patient Registration</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="patient_records.php">
<span class="material-symbols-outlined">folder_shared</span>
<span class="<?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'font-bold' : 'font-medium'; ?>">Patient Records</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_booking.php' || $current_page === 'receptionist_create_booking.php' || $current_page === 'receptionist_edit_appointment.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_booking.php">
<span class="material-symbols-outlined">calendar_month</span>
<span class="<?php echo ($current_page === 'receptionist_booking.php' || $current_page === 'receptionist_create_booking.php' || $current_page === 'receptionist_edit_appointment.php') ? 'font-bold' : 'font-medium'; ?>">Bookings</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_doctor_schedule.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_doctor_schedule.php">
    <span class="material-symbols-outlined">perm_contact_calendar</span>
    <span class="<?php echo ($current_page === 'receptionist_doctor_schedule.php') ? 'font-bold' : 'font-medium'; ?>">Doctor Schedules</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_queue.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_queue.php">
<span class="material-symbols-outlined">monitor_heart</span>
<span class="<?php echo ($current_page === 'receptionist_queue.php') ? 'font-bold' : 'font-medium'; ?>">Queue Monitor</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_payment.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_payment.php">
<span class="material-symbols-outlined">payments</span>
<span class="<?php echo ($current_page === 'receptionist_payment.php') ? 'font-bold' : 'font-medium'; ?>">Payments</span>
</a>
</nav>
<div class="p-6">
<div class="text-xs text-white/40 text-center font-medium">v2.4.0 • Reception Portal</div>
<a href="../logout.php" class="flex items-center justify-center gap-2 mt-4 w-full py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium">
<span class="material-symbols-outlined text-[18px]">logout</span>
Logout
</a>
</div>
</aside>
<main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
<header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
<div class="flex flex-col gap-1">
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Edit Walk-In</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">edit_calendar</span>
                    Update walk-in details and services
                </p>
</div>
<div class="flex items-center gap-4">
<button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
<span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full"></span>
<span class="material-symbols-outlined">notifications</span>
</button>
<a href="receptionist_booking.php" class="h-12 px-6 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all">
<span class="material-symbols-outlined">arrow_back</span>
<span>Back to Bookings</span>
</a>
</div>
</header>
<div class="flex-1 overflow-y-auto p-8">
<div class="max-w-[1200px] mx-auto flex flex-col gap-6">
<div class="flex items-center justify-between">
<a class="flex items-center gap-2 text-slate-500 hover:text-royal-purple font-bold text-sm transition-colors" href="receptionist_booking.php">
<span class="material-symbols-outlined text-lg">arrow_back</span>
                        Back to Bookings
                    </a>
</div>
<?php if ($error_message): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-6">
<div class="flex items-center gap-3">
<span class="material-symbols-outlined text-red-600">error</span>
<p class="text-red-800 font-medium"><?php echo htmlspecialchars($error_message); ?></p>
</div>
</div>
<?php endif; ?>
<form action="" method="POST" class="flex flex-col gap-6">
<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
<input type="hidden" name="update_appointment" value="1">
<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
<div class="xl:col-span-8 flex flex-col gap-6">
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 relative overflow-hidden">
<div class="absolute top-0 left-0 w-1 h-full bg-royal-purple"></div>
<h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
<span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined text-sm">person</span>
</span>
                                    Patient Information
                                </h3>
<div class="flex flex-col gap-4">
<div>
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Patient</label>
<div class="p-4 bg-purple-50 rounded-xl border border-purple-100 flex items-center gap-3">
<?php 
$patient_initials = strtoupper(substr($selected_patient['first_name'], 0, 1) . substr($selected_patient['last_name'], 0, 1));
$patient_id_display = 'P-' . str_pad($selected_patient['patient_id'], 4, '0', STR_PAD_LEFT);
?>
<div class="w-10 h-10 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-lg"><?php echo $patient_initials; ?></div>
<div class="flex-1">
<p class="text-sm font-bold text-royal-purple"><?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']); ?></p>
<p class="text-xs text-slate-500"><?php echo $patient_id_display; ?> • <?php echo htmlspecialchars($selected_patient['phone_number']); ?></p>
</div>
</div>
<input type="hidden" name="patient_id" value="<?php echo $selected_patient['patient_id']; ?>">
</div>
</div>
</div>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 relative">
<h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
<span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined text-sm">calendar_month</span>
</span>
                                    Appointment Details
                                </h3>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
<div>
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Assigned Dentist <span class="text-red-500">*</span></label>
<select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none cursor-pointer" name="doctor_id" id="doctor_id" required onchange="updateAppointmentDuration()">
<option value="">Select Dentist...</option>
<?php foreach ($dentists as $dentist): ?>
<option value="<?php echo $dentist['user_id']; ?>" <?php echo (isset($form_data['doctor_id']) ? ($form_data['doctor_id'] == $dentist['user_id']) : ($appointment['doctor_id'] == $dentist['user_id'])) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dentist['full_name']); ?></option>
<?php endforeach; ?>
</select>
</div>
<div class="md:col-span-2">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Services / Treatments <span class="text-red-500">*</span></label>
<div class="flex gap-3">
<select class="flex-1 bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none cursor-pointer" id="service_dropdown" onchange="addService()">
<option value="">Select Service to Add...</option>
<?php foreach ($services as $service): ?>
<option value="<?php echo $service['service_id']; ?>" 
        data-name="<?php echo htmlspecialchars($service['service_name']); ?>" 
        data-duration="<?php echo $service['estimated_duration_mins']; ?>" 
        data-price="<?php echo $service['base_price']; ?>"
        data-category="<?php echo htmlspecialchars($service['category'] ?? 'General Dentistry'); ?>"
        data-max-months="<?php echo htmlspecialchars($service['installment_duration_months'] ?? 6); ?>"
        data-min-dp="<?php echo htmlspecialchars($service['installment_down_payment'] ?? 0); ?>"
        data-interest-percent="<?php echo htmlspecialchars($service['installment_interest_percent'] ?? 0); ?>"
        data-monthly-payment="<?php echo htmlspecialchars($service['installment_monthly_payment'] ?? 0); ?>"
        data-is-installment="<?php echo htmlspecialchars($service['is_installment_available']); ?>"
        data-requires-checkup="<?php echo $service['requires_checkup']; ?>"
        data-requires-xray="<?php echo $service['requires_xray']; ?>">
    <?php echo htmlspecialchars($service['service_name'] . ' (₱' . number_format($service['base_price'], 2) . ')'); ?>
</option>
<?php endforeach; ?>
</select>
<button type="button" onclick="addService()" class="px-4 py-3 bg-royal-purple text-white rounded-xl text-sm font-bold hover:bg-purple-800 transition-colors flex items-center gap-2 whitespace-nowrap">
<span class="material-symbols-outlined text-lg">add</span>
Add Service
</button>
</div>
<div id="selectedServicesList" class="mt-3 space-y-2 max-h-48 overflow-y-auto">
<!-- Selected services will appear here -->
</div>
<input type="hidden" name="service_ids" id="service_ids" value="" required>
<p class="text-xs text-slate-400 mt-2">Select services from the dropdown and click "Add Service" to include multiple services</p>
</div>

                        <!-- Date, Time, and Status Fields (Moved up to match Create Walk-in) -->
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Appointment Date <span class="text-red-500">*</span></label>
                            <input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" name="appointment_date" value="<?php echo htmlspecialchars($form_data['appointment_date'] ?? $appointment['appointment_date']); ?>" type="date" min="<?php echo date('Y-m-d'); ?>" required/>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Appointment Time <span class="text-red-500">*</span></label>
                            <?php 
                            $appointment_time_value = $form_data['appointment_time'] ?? date('H:i', strtotime($appointment['time_slot']));
                            echo generateTimeDropdown('appointment_time', $appointment_time_value, '09:00', '19:00', 'w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none', false, true, 'onchange="updateAppointmentDuration()"', 'appointment_time'); 
                            ?>
                            <div class="mt-2 space-y-1">
                                <p class="text-xs text-slate-400">Clinic hours: 9:00 AM - 7:00 PM</p>
                                <div id="appointmentDurationInfo" class="hidden">
                                    <p class="text-xs font-medium text-royal-purple">
                                        <span class="material-symbols-outlined text-sm align-middle">schedule</span>
                                        <span id="serviceDurationText">Service duration: -- minutes</span>
                                    </p>
                                    <p class="text-xs font-bold text-slate-600 mt-1">
                                        <span>Estimated end time: </span>
                                        <span id="estimatedEndTime" class="text-royal-purple">--</span>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Status <span class="text-red-500">*</span></label>
                            <select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none cursor-pointer" name="status" required>
                                <option value="Pending" <?php echo (isset($form_data['status']) ? ($form_data['status'] == 'Pending') : ($appointment['status'] == 'Pending')) ? 'selected' : ''; ?>>Pending</option>
                                <option value="Booked" <?php echo (isset($form_data['status']) ? ($form_data['status'] == 'Booked') : ($appointment['status'] == 'Booked')) ? 'selected' : ''; ?>>Booked</option>
                                <option value="Arrived" <?php echo (isset($form_data['status']) ? ($form_data['status'] == 'Arrived') : ($appointment['status'] == 'Arrived')) ? 'selected' : ''; ?>>Arrived</option>
                                <option value="Completed" <?php echo (isset($form_data['status']) ? ($form_data['status'] == 'Completed') : ($appointment['status'] == 'Completed')) ? 'selected' : ''; ?>>Completed</option>
                                <option value="Cancelled" <?php echo (isset($form_data['status']) ? ($form_data['status'] == 'Cancelled') : ($appointment['status'] == 'Cancelled')) ? 'selected' : ''; ?>>Cancelled</option>
                                <option value="No-Show" <?php echo (isset($form_data['status']) ? ($form_data['status'] == 'No-Show') : ($appointment['status'] == 'No-Show')) ? 'selected' : ''; ?>>No-Show</option>
                            </select>
                        </div>

                        <!-- Customize Plan (Toggle Installment per Service) -->
                        <div class="md:col-span-2 border-t border-gray-100 pt-6 mt-2" id="customizePlanContainer">
                            <h4 class="text-sm font-bold text-[#151118] mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-royal-purple">tune</span>
                                Customize Plan
                            </h4>
                            
                            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                                <div id="customizeServiceList" class="divide-y divide-gray-100">
                                    <!-- Services will be toggled here -->
                                </div>
                            </div>
                        </div>

                        <!-- Estimated Payment Details (Preview) -->
                        <div class="md:col-span-2 mt-4" id="paymentPreviewContainer">
                            <h4 class="text-sm font-bold text-[#151118] mb-4 flex items-center gap-2">
                                <span class="material-symbols-outlined text-royal-purple">payments</span>
                                Estimated Payment Details
                            </h4>
                            
                            <div class="bg-gray-50 rounded-xl p-5 border border-gray-200">
                                <div class="flex justify-between items-center mb-4">
                                    <div>
                                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">Total Amount</p>
                                        <p class="text-2xl font-bold text-royal-purple" id="estTotalAmount">₱0.00</p>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs font-bold text-slate-500 uppercase tracking-wide">Installment Available?</p>
                                        <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-gray-200 text-gray-600" id="estInstallmentBadge">
                                            No
                                        </span>
                                    </div>
                                </div>
                                
                                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pt-4 border-t border-gray-200">
                                    <div>
                                        <p class="text-xs text-slate-500 mb-1">Down Payment (Min)</p>
                                        <p class="text-sm font-bold text-[#151118]" id="estMinDP">₱0.00</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 mb-1">Monthly (Est.)</p>
                                        <p class="text-sm font-bold text-[#151118]" id="estMonthlyVal">₱0.00</p>
                                    </div>
                                    <div>
                                        <p class="text-xs text-slate-500 mb-1">Duration (Max)</p>
                                        <p class="text-sm font-bold text-[#151118]" id="estMaxMonths">0 Months</p>
                                    </div>
                                </div>
                                <div class="mt-3 flex items-start gap-2 text-xs text-slate-500">
                                    <span class="material-symbols-outlined text-sm">info</span>
                                    <p>Actual payment terms will be finalized during payment processing.</p>
                                </div>
                            </div>
                        </div>

<!-- Fields moved up -->
                    </div>
                </div>
            </div>
<div class="xl:col-span-4 flex flex-col gap-6">
<div class="bg-blue-50 border border-blue-100 rounded-2xl p-6">
<div class="flex items-start gap-4">
<div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center flex-shrink-0">
<span class="material-symbols-outlined">info</span>
</div>
<div>
<h4 class="font-bold text-blue-900 text-lg">Edit Walk-In</h4>
<p class="text-sm text-blue-800/70 mt-1 leading-snug">Update walk-in details and services.</p>
</div>
</div>
</div>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
<h3 class="text-lg font-bold text-[#151118] mb-4">Quick Actions</h3>
<div class="flex flex-col gap-3">
<a href="patient_information.php?id=<?php echo $selected_patient['patient_id']; ?>" class="flex items-center gap-3 p-3 bg-purple-50 hover:bg-purple-100 rounded-xl text-royal-purple font-bold text-sm transition-colors">
<span class="material-symbols-outlined">person</span>
View Patient Profile
</a>
<a href="receptionist_booking.php?date=<?php echo $appointment['appointment_date']; ?>" class="flex items-center gap-3 p-3 bg-gray-50 hover:bg-gray-100 rounded-xl text-slate-600 font-bold text-sm transition-colors">
<span class="material-symbols-outlined">calendar_month</span>
View All Bookings
</a>
</div>
</div>
<div id="doctorScheduleWidget" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hidden">
    <h3 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
        <span class="material-symbols-outlined text-royal-purple">schedule</span>
        Weekly Schedule
    </h3>
    <div id="doctorScheduleContent">
        <!-- Schedule will be loaded here via JavaScript -->
    </div>
</div>
</div>
</div>
<div class="sticky bottom-0 bg-white/80 backdrop-blur-md p-4 -m-8 mt-4 border-t border-gray-200 flex items-center justify-between z-20 shadow-[0_-10px_40px_-15px_rgba(0,0,0,0.1)] rounded-t-2xl">
<div class="flex items-center gap-3">
</div>
<div class="flex items-center gap-4">
<a href="receptionist_booking.php" class="px-6 py-3 rounded-xl font-bold text-slate-500 hover:bg-gray-100 transition-colors">Cancel</a>
<button class="px-8 py-3 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-bold flex items-center gap-2 shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all" type="submit">
<span class="material-symbols-outlined">save</span>
<span>Update Appointment</span>
</button>
</div>
</div>
</form>
</div>
</div>
</main>
</div>

<!-- Custom Popup Modal -->
<div id="customPopup" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-2xl p-6 max-w-md w-[90%] shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="popupContent">
        <div class="flex items-center gap-4 mb-4">
            <div id="popupIcon" class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-2xl"></span>
            </div>
            <h3 id="popupTitle" class="text-lg font-bold text-[#151118] flex-1"></h3>
        </div>
        <p id="popupMessage" class="text-slate-600 mb-6 whitespace-pre-line"></p>
        <div id="popupButtons" class="flex gap-3 justify-end"></div>
    </div>
</div>

<script>
// Custom Popup System
(function() {
    const popup = document.getElementById('customPopup');
    const popupContent = document.getElementById('popupContent');
    const popupIcon = document.getElementById('popupIcon');
    const popupTitle = document.getElementById('popupTitle');
    const popupMessage = document.getElementById('popupMessage');
    const popupButtons = document.getElementById('popupButtons');
    
    let currentResolve = null;
    
    function showPopup(type, title, message) {
        return new Promise((resolve) => {
            currentResolve = resolve;
            
            const iconMap = {
                'alert': { icon: 'info', color: 'bg-purple-100 text-royal-purple' },
                'confirm': { icon: 'help', color: 'bg-yellow-100 text-yellow-700' },
                'success': { icon: 'check_circle', color: 'bg-green-100 text-green-700' },
                'error': { icon: 'error', color: 'bg-red-100 text-red-700' }
            };
            
            const config = iconMap[type] || iconMap['alert'];
            popupIcon.className = `w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ${config.color}`;
            popupIcon.querySelector('span').textContent = config.icon;
            
            popupTitle.textContent = title;
            popupMessage.textContent = message;
            
            popupButtons.innerHTML = '';
            if (type === 'confirm') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'px-4 py-2 rounded-xl bg-gray-100 text-slate-600 hover:bg-gray-200 font-bold text-sm transition-all';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.onclick = () => closePopup(false);
                
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-royal-purple text-white hover:bg-purple-800 font-bold text-sm transition-all shadow-md';
                okBtn.textContent = 'Confirm';
                okBtn.onclick = () => closePopup(true);
                
                popupButtons.appendChild(cancelBtn);
                popupButtons.appendChild(okBtn);
            } else {
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-royal-purple text-white hover:bg-purple-800 font-bold text-sm transition-all shadow-md';
                okBtn.textContent = 'OK';
                okBtn.onclick = () => closePopup(true);
                popupButtons.appendChild(okBtn);
            }
            
            popup.classList.remove('hidden');
            setTimeout(() => {
                popupContent.classList.remove('scale-95', 'opacity-0');
                popupContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        });
    }
    
    function closePopup(result) {
        popupContent.classList.remove('scale-100', 'opacity-100');
        popupContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            popup.classList.add('hidden');
            if (currentResolve) {
                currentResolve(result);
                currentResolve = null;
            }
        }, 300);
    }
    
    popup.addEventListener('click', function(e) {
        if (e.target === popup) {
            closePopup(false);
        }
    });
    
    window.showAlert = (message, title = 'Alert') => showPopup('alert', title, message);
    window.showConfirm = (message, title = 'Confirm') => showPopup('confirm', title, message);
    window.showSuccess = (message, title = 'Success') => showPopup('success', title, message);
    window.showError = (message, title = 'Error') => showPopup('error', title, message);
})();

// Multiple Services Management
let selectedServices = []; // Array of {id, name, duration, price}

// Installment Rules from PHP
const installmentRules = <?php echo json_encode($installment_rules); ?>;

// Active Installment Service IDs (for editing)
const activeInstallmentIds = <?php echo json_encode(array_map('intval', $active_installment_ids)); ?>;
const existingDpTotal = <?php echo floatval($existing_dp_total); ?>;
const existingMonths = <?php echo intval($existing_months); ?>;

async function addService() {
    const dropdown = document.getElementById('service_dropdown');
    const selectedOption = dropdown.options[dropdown.selectedIndex];
    
    if (!selectedOption || !selectedOption.value) {
        showError('Please select a service from the dropdown first.', 'No Service Selected');
        return;
    }
    
    const serviceId = parseInt(selectedOption.value);
    const serviceName = selectedOption.dataset.name;
    const serviceDuration = parseInt(selectedOption.dataset.duration || 30);
    const servicePrice = parseFloat(selectedOption.dataset.price || 0);
    
    // Check if service is already added
    if (selectedServices.find(s => s.id === serviceId)) {
        showError('This service is already added.', 'Service Already Added');
        return;
    }
    
    const serviceCategory = selectedOption.dataset.category || 'General Dentistry';
    const serviceMaxMonths = parseInt(selectedOption.dataset.maxMonths || 0);
    const serviceMinDp = parseFloat(selectedOption.dataset.minDp || 0);
    const serviceInterestPercent = parseFloat(selectedOption.dataset.interestPercent || 0);
    const serviceIsInstallment = selectedOption.dataset.isInstallment === '1';
    const requiresCheckup = selectedOption.dataset.requiresCheckup === '1';
    const requiresXray = selectedOption.dataset.requiresXray === '1';

    // Automated validation for requirements
    if (requiresCheckup || requiresXray) {
        const patientId = <?php echo $appointment['patient_id']; ?>;
        
        try {
            const formData = new FormData();
            formData.append('patient_id', patientId);
            formData.append('service_id', serviceId);
            
            const response = await fetch('../api/check_service_requirements.php', {
                method: 'POST',
                body: formData
            });
            
            const result = await response.json();
            
            if (!result.success) {
                showError('Error checking requirements: ' + result.message, 'Validation Error');
                dropdown.selectedIndex = 0;
                return;
            }
            
            if (!result.allowed) {
                showError(
                    result.message + '\n\nThe patient must first complete and pay for these services before booking "' + serviceName + '".',
                    'Requirements Not Met'
                );
                dropdown.selectedIndex = 0;
                return;
            }
        } catch (error) {
            console.error('Error validating requirements:', error);
            showError('Failed to validate service requirements. Please try again.', 'Validation Error');
            dropdown.selectedIndex = 0;
            return;
        }
    }

    // Add to selected services
    selectedServices.push({
        id: serviceId,
        name: serviceName,
        duration: serviceDuration,
        price: servicePrice,
        category: serviceCategory,
        maxMonths: serviceMaxMonths,
        minDp: serviceMinDp,
        interestPercent: serviceInterestPercent,
        monthlyPayment: parseFloat(selectedOption.dataset.monthlyPayment || 0),
        isInstallment: serviceIsInstallment,
        requiresCheckup: requiresCheckup,
        requiresXray: requiresXray,
        useInstallment: serviceIsInstallment
    });
    
    // Reset dropdown
    dropdown.selectedIndex = 0;
    
    // Update UI
    updateSelectedServicesList();
    updateServiceIdsInput();
    updateAppointmentDuration();
    
    // Update Payment Preview
    renderCustomizePlan();
    updatePaymentOptions();
}

function removeService(serviceId) {
    selectedServices = selectedServices.filter(s => s.id !== serviceId);
    updateSelectedServicesList();
    updateServiceIdsInput();
    updateAppointmentDuration();
    
    // Update Payment Preview
    renderCustomizePlan();
    updatePaymentOptions();
}

function updateSelectedServicesList() {
    const listContainer = document.getElementById('selectedServicesList');
    
    if (selectedServices.length === 0) {
        listContainer.innerHTML = '<p class="text-xs text-slate-400 italic">No services selected. Use the dropdown above to add services.</p>';
        return;
    }
    
    listContainer.innerHTML = selectedServices.map(service => {
        const totalPrice = service.price > 0 ? `₱${service.price.toFixed(2)}` : 'Price varies';

        let badges = '';
        if (service.requiresCheckup) {
            badges += `<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-800 mr-1">Check-up Required</span>`;
        }
        if (service.requiresXray) {
            badges += `<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-800 mr-1">X-ray Required</span>`;
        }

        return `
            <div class="flex items-center gap-3 p-3 bg-purple-50 border border-purple-100 rounded-xl">
                <input type="checkbox" checked onchange="removeService(${service.id})" class="w-5 h-5 rounded border-purple-300 text-royal-purple focus:ring-royal-purple cursor-pointer">
                <div class="flex-1">
                    <p class="text-sm font-bold text-royal-purple mb-1">${escapeHtml(service.name)}</p>
                    <div class="flex flex-wrap gap-1 mb-1">
                        ${badges}
                    </div>
                    <p class="text-xs text-slate-500">Duration: ${service.duration} min • ${totalPrice}</p>
                </div>
            </div>
        `;
    }).join('');
}

function updateServiceIdsInput() {
    const serviceIdsInput = document.getElementById('service_ids');
    const serviceIds = selectedServices.map(s => s.id).join(',');
    serviceIdsInput.value = serviceIds;
    
    if (serviceIds) {
        serviceIdsInput.removeAttribute('required');
    } else {
        serviceIdsInput.setAttribute('required', 'required');
    }
}

// Update appointment duration display based on selected services and time
function updateAppointmentDuration() {
    const appointmentTime = document.getElementById('appointment_time');
    const durationInfo = document.getElementById('appointmentDurationInfo');
    const serviceDurationText = document.getElementById('serviceDurationText');
    const estimatedEndTime = document.getElementById('estimatedEndTime');
    
    if (!appointmentTime || !durationInfo) return;
    
    // Calculate total duration from all selected services
    const totalDuration = selectedServices.reduce((sum, service) => sum + service.duration, 0);
    const timeValue = appointmentTime.value;
    
    if (totalDuration > 0 && timeValue) {
        // Calculate end time
        const [hours, minutes] = timeValue.split(':').map(Number);
        const startTime = new Date();
        startTime.setHours(hours, minutes, 0, 0);
        
        const endTime = new Date(startTime);
        endTime.setMinutes(endTime.getMinutes() + totalDuration);
        
        const endHours = endTime.getHours();
        const endMinutes = endTime.getMinutes();
        const endTimeFormatted = formatTime(endHours, endMinutes);
        
        // Update display
        const serviceCount = selectedServices.length;
        const serviceText = serviceCount === 1 ? 'service' : 'services';
        serviceDurationText.textContent = `Total duration: ${totalDuration} minutes (${serviceCount} ${serviceText})`;
        estimatedEndTime.textContent = endTimeFormatted;
        durationInfo.classList.remove('hidden');
        
        // Update max time for time input to prevent exceeding clinic hours
        const clinicClose = 19 * 60; // 7:00 PM in minutes
        const latestStartMinutes = clinicClose - totalDuration;
        const latestStartHour = Math.floor(latestStartMinutes / 60);
        const latestStartMin = latestStartMinutes % 60;
        const latestStartTime = String(latestStartHour).padStart(2, '0') + ':' + String(latestStartMin).padStart(2, '0');
        
        // Set max time dynamically
        appointmentTime.setAttribute('max', latestStartTime);
        
        // Check if current time exceeds the limit
        const currentTimeInMinutes = hours * 60 + minutes;
        if (currentTimeInMinutes > latestStartMinutes) {
            appointmentTime.setCustomValidity(`Appointment time must allow ${totalDuration} minutes before clinic closes at 7:00 PM. Latest start time: ${formatTime(latestStartHour, latestStartMin)}`);
        } else {
            appointmentTime.setCustomValidity('');
        }
    } else {
        durationInfo.classList.add('hidden');
        appointmentTime.setAttribute('max', '19:00'); // Reset to default
        appointmentTime.setCustomValidity('');
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatTime(hours, minutes) {
    const period = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours > 12 ? hours - 12 : (hours === 0 ? 12 : hours);
    const displayMinutes = String(minutes).padStart(2, '0');
    return `${displayHours}:${displayMinutes} ${period}`;
}

// Toggle Installment for a service
function toggleServiceInstallment(serviceId) {
    const service = selectedServices.find(s => s.id === serviceId);
    if (service && service.isInstallment) {
        service.useInstallment = !service.useInstallment;
        renderCustomizePlan();
        updatePaymentOptions();
    }
}

function renderCustomizePlan() {
    const container = document.getElementById('customizeServiceList');
    const wrapper = document.getElementById('customizePlanContainer');
    
    if (selectedServices.length === 0) {
        wrapper.classList.add('hidden');
        wrapper.classList.remove('block');
        return;
    }
    wrapper.classList.remove('hidden');
    wrapper.classList.add('block');
    
    container.innerHTML = selectedServices.map(service => {
        // Calculate DP Amount for display
        // Priority: 1. DB Value (Amount) 2. Category Rule (Percent) 3. Default 20%
        let dpAmount = 0;
        if (service.minDp > 0) {
            dpAmount = service.minDp;
        } else {
            const rule = installmentRules[service.category];
            const dpPercent = rule ? rule.min_dp : 20;
            dpAmount = service.price * (dpPercent / 100);
        }
        
        let controlHtml = '';
        let statusColor = '';
        
        if (service.isInstallment) {
            if (service.useInstallment) {
                statusColor = 'text-royal-purple';
                controlHtml = `
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-bold text-royal-purple">Installment</span>
                        <button type="button" onclick="toggleServiceInstallment(${service.id})" class="relative inline-flex h-6 w-11 items-center rounded-full bg-royal-purple transition-colors focus:outline-none focus:ring-2 focus:ring-royal-purple focus:ring-offset-2">
                            <span class="inline-block h-4 w-4 transform translate-x-6 rounded-full bg-white transition-transform"></span>
                        </button>
                    </div>
                `;
            } else {
                statusColor = 'text-slate-600';
                controlHtml = `
                    <div class="flex items-center gap-3">
                        <span class="text-xs font-medium text-slate-500">Full Payment</span>
                        <button type="button" onclick="toggleServiceInstallment(${service.id})" class="relative inline-flex h-6 w-11 items-center rounded-full bg-gray-200 transition-colors focus:outline-none focus:ring-2 focus:ring-royal-purple focus:ring-offset-2">
                            <span class="inline-block h-4 w-4 transform translate-x-1 rounded-full bg-white transition-transform"></span>
                        </button>
                    </div>
                `;
            }
        } else {
            statusColor = 'text-slate-600';
            controlHtml = `<span class="text-xs font-medium text-slate-400">Full Payment Only</span>`;
        }
        
        const priceDisplay = service.useInstallment ? 
            `(DP: ₱${dpAmount.toLocaleString(undefined, {minimumFractionDigits: 2})})` : 
            `(₱${service.price.toLocaleString(undefined, {minimumFractionDigits: 2})})`;

        return `
            <div class="p-4 flex items-center justify-between hover:bg-gray-50 transition-colors">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full flex items-center justify-center ${service.useInstallment ? 'bg-purple-100 text-royal-purple' : 'bg-gray-100 text-slate-400'}">
                        <span class="material-symbols-outlined text-sm">check_circle</span>
                    </div>
                    <div>
                        <p class="font-bold text-sm ${statusColor}">${escapeHtml(service.name)}</p>
                        <p class="text-xs text-slate-500">${priceDisplay}</p>
                    </div>
                </div>
                <div>
                    ${controlHtml}
                </div>
            </div>
        `;
    }).join('');
}

// Update Payment Preview (Read Only)
function updatePaymentOptions() {
    const container = document.getElementById('paymentPreviewContainer');
    if (!container) return;

    if (selectedServices.length === 0) {
        container.classList.add('hidden');
        return;
    }

    container.classList.remove('hidden');

    let totalAmount = 0;
    let minDpForInstallments = 0;
    let totalMonthlyPayment = 0;
    let maxMonths = 0;
    let hasInstallmentService = false;
    let estTotalInterest = 0;

    // Calculate Totals
    selectedServices.forEach(s => {
        const price = s.price || 0;
        totalAmount += price;
        
        // s.isInstallment is boolean true/false from addService/preLoad
        if (s.isInstallment && s.useInstallment) {
            hasInstallmentService = true;
            
            // DP Calculation
            // Priority: 1. DB Value (Amount) 2. Category Rule (Percent) 3. Default 20%
            let dpAmount = 0;
            
            // If DB has a value > 0, treat it as the FIXED Amount
            if (s.minDp > 0) {
                dpAmount = s.minDp;
            } else {
                // Fallback to Category Rule (Percentage of Price)
                const rule = installmentRules[s.category];
                const dpPercent = rule ? rule.min_dp : 20;
                dpAmount = price * (dpPercent / 100);
            }
            
            minDpForInstallments += dpAmount;
            
            // Monthly Payment Calculation
            if (s.monthlyPayment > 0) {
                // Use Fixed DB Monthly Payment
                totalMonthlyPayment += s.monthlyPayment;
                // For max months, estimation:
                if (s.maxMonths > maxMonths) maxMonths = s.maxMonths;
            } else {
                // Approximate Dynamic Calculation
                const interestAmount = price * ((s.interestPercent || 0) / 100);
                estTotalInterest += interestAmount;
                
                const totalWithInterest = price + interestAmount;
                const balance = totalWithInterest - dpAmount;
                
                // If months is 0, default to 6 for calculation safety
                const serviceMonths = s.maxMonths > 0 ? s.maxMonths : 6;
                totalMonthlyPayment += (balance / serviceMonths);
                
                // Max Months
                if (s.maxMonths > maxMonths) maxMonths = s.maxMonths;
            }
        } else {
             // Non-installment services add to upfront cost
             minDpForInstallments += price; 
        }
    });

    // Update UI Elements
    const totalEl = document.getElementById('estTotalAmount');
    if (totalEl) totalEl.textContent = '₱' + totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    const badge = document.getElementById('estInstallmentBadge');
    if (badge) {
        if (hasInstallmentService) {
            badge.textContent = 'Yes';
            badge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-green-100 text-green-700';
            
            document.getElementById('estMinDP').textContent = '₱' + minDpForInstallments.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('estMonthlyVal').textContent = '₱' + totalMonthlyPayment.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('estMaxMonths').textContent = maxMonths + ' Months';
        } else {
            badge.textContent = 'No';
            badge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-gray-200 text-gray-600';
            
            document.getElementById('estMinDP').textContent = '₱' + totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
            document.getElementById('estMonthlyVal').textContent = '₱0.00';
            document.getElementById('estMaxMonths').textContent = '0 Months';
        }
    }
}

// Update duration when time changes
document.getElementById('appointment_time')?.addEventListener('change', updateAppointmentDuration);

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    
    // Check Success Modal Logic first
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === 'walkin_updated') {
        const apptId = urlParams.get('id');
        showConfirm(
            "Walk-in updated successfully!",
            "Update Successful"
        ).then(result => {
             window.location.href = 'receptionist_booking.php';
        });
    }

    // Pre-populate with existing services
    <?php
    echo "const preLoadedServices = [];\n";
    if (isset($appointment['current_service_ids']) && !empty($appointment['current_service_ids'])) {
        $svc_ids = array_map('trim', explode(',', $appointment['current_service_ids']));
        foreach ($svc_ids as $sid) {
             foreach ($services as $svc) {
                 if ($svc['service_id'] == $sid) {
                     $isInst = ($svc['is_installment_available'] ? 'true' : 'false');
                     echo "preLoadedServices.push({
                        id: " . intval($svc['service_id']) . ",
                        name: '" . addslashes($svc['service_name']) . "',
                        duration: " . intval($svc['estimated_duration_mins']) . ",
                        price: " . floatval($svc['base_price']) . ",
                        category: '" . addslashes($svc['category'] ?? 'General Dentistry') . "',
                        maxMonths: " . intval($svc['installment_duration_months'] ?? 6) . ",
                        minDpPercent: " . floatval($svc['installment_down_payment'] ?? 20) . ",
                        minDp: " . floatval($svc['installment_down_payment'] ?? 0) . ",
                        interestPercent: " . floatval($svc['installment_interest_percent'] ?? 0) . ",
                        isInstallment: " . $isInst . ",
                        requiresCheckup: " . ($svc['requires_checkup'] ? 'true' : 'false') . ",
                        requiresXray: " . ($svc['requires_xray'] ? 'true' : 'false') . ",
                        useInstallment: " . $isInst . "
                     });\n";
                     break;
                 }
             }
        }
    }
    echo "selectedServices = preLoadedServices;\n";
    ?>
    
    updateSelectedServicesList();
    updateServiceIdsInput();
    updateAppointmentDuration();
    renderCustomizePlan();
    updatePaymentOptions();

    // Update duration when time changes
    document.getElementById('appointment_time')?.addEventListener('change', updateAppointmentDuration);
});

// Form validation
document.querySelector('form')?.addEventListener('submit', function(e) {
    const patientId = document.querySelector('input[name="patient_id"]').value;
    const doctorId = document.querySelector('select[name="doctor_id"]').value;
    const serviceIds = document.getElementById('service_ids').value;
    const appointmentDate = document.querySelector('input[name="appointment_date"]').value;
    const appointmentTime = document.querySelector('select[name="appointment_time"]').value;
    
    if (!patientId) {
        e.preventDefault();
        showError('Please select a patient first.', 'Patient Required');
        return false;
    }
    
    if (!doctorId) {
        e.preventDefault();
        showError('Please select a dentist.', 'Dentist Required');
        return false;
    }
    
    if (!serviceIds) {
        e.preventDefault();
        showError('Please select at least one service.', 'Service Required');
        return false;
    }
    
    if (!appointmentDate) {
        e.preventDefault();
        showError('Please select an appointment date.', 'Date Required');
        return false;
    }
    
    if (!appointmentTime) {
        e.preventDefault();
        showError('Please select an appointment time.', 'Time Required');
        return false;
    }
    
    // Validate clinic hours (9:00 AM - 7:00 PM)
    const timeParts = appointmentTime.split(':');
    const hour = parseInt(timeParts[0]);
    const minute = parseInt(timeParts[1]);
    const timeInMinutes = hour * 60 + minute;
    const clinicOpen = 9 * 60; // 9:00 AM
    const clinicClose = 19 * 60; // 7:00 PM (19:00)
    
    if (timeInMinutes < clinicOpen || timeInMinutes >= clinicClose) {
        e.preventDefault();
        showError('Appointment time must be between 9:00 AM and 7:00 PM (clinic operating hours).', 'Invalid Time');
        return false;
    }
    
    // Check if date is in the past
    const appointmentDateTime = new Date(appointmentDate + ' ' + appointmentTime);
    const now = new Date();
    // Allow editing same appointment for reschedule
    const originalDate = "<?php echo $appointment['appointment_date'] . ' ' . $appointment['time_slot']; ?>";
    const originalDateTime = new Date(originalDate);
    
    // Tolerance of 1 minute
    if (appointmentDateTime < now && Math.abs(appointmentDateTime - originalDateTime) > 60000) {
        e.preventDefault();
        showError('Appointment date and time cannot be in the past.', 'Invalid Date/Time');
        return false;
    }
    
    // Validate service duration vs clinic close
    const totalDuration = selectedServices.reduce((sum, s) => sum + s.duration, 0);
    const endTime = new Date(appointmentDateTime);
    endTime.setMinutes(endTime.getMinutes() + totalDuration);
    
    const endHour = endTime.getHours();
    const endMinute = endTime.getMinutes();
    const endTimeInMinutes = endHour * 60 + endMinute;
    
    if (endTimeInMinutes > clinicClose) {
        e.preventDefault();
        showError(`The total service duration (${totalDuration} minutes) extends past 7:00 PM. Please choose an earlier time.`, 'Time Exceeds Clinic Hours');
        return false;
    }

    // No Payment Validation needed anymore (Decoupled)
});
    

// Doctor Schedule Widget Logic
const doctorScheduleWidget = document.getElementById('doctorScheduleWidget');
const doctorScheduleContent = document.getElementById('doctorScheduleContent');
const doctorSelect = document.getElementById('doctor_id');

if (doctorSelect) {
    doctorSelect.addEventListener('change', function() {
        const doctorId = this.value;
        if (doctorId) {
            loadDoctorScheduleWeek(doctorId);
        } else {
            doctorScheduleWidget.classList.add('hidden');
        }
    });

    // Initial load if doctor is selected
    if (doctorSelect.value) {
        loadDoctorScheduleWeek(doctorSelect.value);
    }
}

function loadDoctorScheduleWeek(doctorId) {
    if (!doctorScheduleContent || !doctorScheduleWidget) return;

    // Show loading state
    doctorScheduleContent.innerHTML = '<div class="text-center py-4 text-slate-400"><span class="material-symbols-outlined text-2xl mb-2 block animate-spin">hourglass_empty</span><p class="text-xs">Loading schedule...</p></div>';
    doctorScheduleWidget.classList.remove('hidden');
    
    // Fetch full week schedule via AJAX
    fetch(`../api/get_doctor_schedule.php?doctor_id=${doctorId}&week=true`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.schedules && data.schedules.length > 0) {
                const daysOfWeek = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
                const scheduleMap = {};
                data.schedules.forEach(schedule => {
                    scheduleMap[schedule.day_of_week] = schedule;
                });
                
                doctorScheduleContent.innerHTML = daysOfWeek.map(day => {
                    const schedule = scheduleMap[day];
                    if (schedule) {
                        const startTime = new Date('2000-01-01 ' + schedule.start_time);
                        const endTime = new Date('2000-01-01 ' + schedule.end_time);
                        const startFormatted = startTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                        const endFormatted = endTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                        
                        return `
                            <div class="p-3 bg-purple-50 border border-purple-100 rounded-xl">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-royal-purple text-sm">${day}</span>
                                    <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded-lg text-xs font-bold">Available</span>
                                </div>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="material-symbols-outlined text-slate-400 text-xs">schedule</span>
                                    <span class="text-xs text-slate-600 font-medium">${startFormatted} - ${endFormatted}</span>
                                </div>
                            </div>
                        `;
                    } else {
                        return `
                            <div class="p-3 bg-gray-50 border border-gray-100 rounded-xl">
                                <div class="flex items-center justify-between">
                                    <span class="font-medium text-slate-500 text-sm">${day}</span>
                                    <span class="px-2 py-0.5 bg-red-100 text-red-700 rounded-lg text-xs font-bold">Unavailable</span>
                                </div>
                            </div>
                        `;
                    }
                }).join('');
            } else {
                doctorScheduleContent.innerHTML = `
                    <div class="p-4 bg-yellow-50 border border-yellow-100 rounded-xl">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-yellow-600 text-sm">info</span>
                            <span class="text-sm text-yellow-700 font-medium">No schedule set for this doctor</span>
                        </div>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error loading schedule:', error);
            doctorScheduleContent.innerHTML = '<div class="text-center py-4 text-red-400"><span class="material-symbols-outlined text-2xl mb-2 block">error</span><p class="text-xs">Error loading schedule</p></div>';
        });
}
</script>


<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>

