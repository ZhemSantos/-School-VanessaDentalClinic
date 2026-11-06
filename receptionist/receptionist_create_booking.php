<?php
session_start();
ob_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/notification_helper.php';

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
        $service_stmt = $pdo->prepare("SELECT service_id, service_name, category, base_price, estimated_duration_mins, is_installment_available, installment_down_payment, installment_duration_months, installment_interest_percent, installment_monthly_payment, requires_checkup, requires_xray FROM tbl_service ORDER BY service_name");
        $service_stmt->execute();
        $services = $service_stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Error fetching services: ' . $e->getMessage());
    }

// Handle form submission
$success_message = '';
$error_message = '';
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_booking'])) {
    // Verify CSRF token
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token. Please try again.';
    } else {
        // Get form data
        $patient_id = !empty($_POST['patient_id']) ? intval($_POST['patient_id']) : 0;
        $doctor_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : 0;
        $service_ids = !empty($_POST['service_ids']) ? $_POST['service_ids'] : '';
        // New: Capture strictly selected installment IDs
        $installment_selected_ids_str = $_POST['installment_selected_ids'] ?? '';
        $installment_selected_ids = $installment_selected_ids_str ? explode(',', $installment_selected_ids_str) : [];
        
        $appointment_date = $_POST['appointment_date'] ?? '';
        $appointment_time = $_POST['appointment_time'] ?? '';
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
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
        
        // --- CONSENT VERIFICATION (Minors) ---
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
                // Optional: $errors[] = "System error checking consents.";
            }
        }
        // --- END CONSENT VERIFICATION ---
        if ($patient_id > 0 && !empty($appointment_date)) {
            try {
                // 1. Check No-Shows for the Target Date (Reset Rule)
                $stmt_today_ns = $pdo->prepare("SELECT COUNT(*) FROM tbl_appointment WHERE patient_id = ? AND appointment_date = ? AND status = 'No-Show'");
                $stmt_today_ns->execute([$patient_id, $appointment_date]);
                $today_no_show_count = $stmt_today_ns->fetchColumn();

                // 2. Check Lifetime No-Shows (2-Strike Rule)
                $stmt_lifetime_ns = $pdo->prepare("SELECT COUNT(*) FROM tbl_appointment WHERE patient_id = ? AND status = 'No-Show'");
                $stmt_lifetime_ns->execute([$patient_id]);
                $lifetime_no_show_count = $stmt_lifetime_ns->fetchColumn();

                // Rule: If Missed Today (>=1) AND History is Bad (>=2) => BLOCK
                if ($today_no_show_count >= 1 && $lifetime_no_show_count >= 2) {
                    $errors[] = "Booking Blocked: Patient has missed an appointment on this date (" . date('M d', strtotime($appointment_date)) . ") and has a history of No-Shows (2nd Offense). No more bookings allowed for this date.";
                }
            } catch (PDOException $e) {
                error_log("Error validating no-show policy: " . $e->getMessage());
                // Fail safe: don't block if DB error, but log it
            }
        }
        // ---------------------------------
        
        // Validate date is not in the past
        if (!empty($appointment_date)) {
            $appt_datetime = new DateTime($appointment_date . ' ' . $appointment_time);
            $now = new DateTime();
            if ($appt_datetime < $now) {
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
        
        // 1. Single Active Appointment Check
        if (empty($errors) && $patient_id > 0) {
            try {
                $active_check = $pdo->prepare("
                    SELECT appointment_id, appointment_date, time_slot 
                    FROM tbl_appointment 
                    WHERE patient_id = ? 
                      AND status NOT IN ('Cancelled', 'No-Show', 'Completed', 'Done')
                ");
                $active_check->execute([$patient_id]);
                $existing = $active_check->fetch();
                
                if ($existing) {
                    $errors[] = 'This patient already has an active appointment on ' . date('M d, Y', strtotime($existing['appointment_date'])) . ' at ' . date('g:i A', strtotime($existing['time_slot'])) . '. Patients can only have one active appointment at a time.';
                }
            } catch (PDOException $e) {
                error_log('Error checking active appointments: ' . $e->getMessage());
                $errors[] = 'Error validating patient appointments.';
            }
        }
        
        // Check for time conflicts with existing appointments
        $conflicting_appointment_to_cancel = null;

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
                
                // Check for conflicts with existing appointments and walk-ins
                // Logic Info: If conflict is 'Pending' (Unpaid Fee), allow overwrite (Cancel old). If 'Booked' (Paid), Block.
                if (empty($errors)) {
                    $conflict_stmt = $pdo->prepare("
                        SELECT a.appointment_id, a.time_slot, a.status,
                               SUM(s.estimated_duration_mins) as total_duration
                        FROM tbl_appointment a
                        JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                        JOIN tbl_service s ON tas.service_id = s.service_id
                        WHERE a.doctor_id = ? 
                          AND a.appointment_date = ?
                          AND a.status NOT IN ('Cancelled', 'No-Show', 'Completed', 'Done')
                        GROUP BY a.appointment_id
                    ");
                    $conflict_stmt->execute([
                        $doctor_id,
                        $appointment_date
                    ]);
                    
                    $all_appointments = $conflict_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Check each appointment for overlap
                    $conflicts = [];
                    foreach ($all_appointments as $appt) {
                        $existing_start = new DateTime($appointment_date . ' ' . $appt['time_slot']);
                        $existing_end = clone $existing_start;
                        $existing_end->modify('+' . $appt['total_duration'] . ' minutes');
                        
                        // Check for overlap using proper interval logic
                        // Overlap occurs if: (new_start < existing_end) AND (new_end > existing_start)
                        if ($start_time < $existing_end && $end_time > $existing_start) {
                            $conflicts[] = $appt;
                        }
                    }
                    
                    if ($conflicts) {
                        foreach ($conflicts as $conflict) {
                            if ($conflict['status'] === 'Pending') {
                                // Allow overlap! Multiple pending appointments can exist.
                                continue;
                            } else {
                                // Old appointment is Confirmed/Booked (Paid), so we CANNOT overwrite.
                                $errors[] = 'This time slot is taken by a confirmed (paid) appointment.';
                                break; // Stop checking, hard block
                            }
                        }
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
                
                // Cancel existing unpaid appointment logic removed (allowing overlaps)
                
                $appointment_ids = [];
                $service_names = [];
                
                // Create ONE appointment for all services
                $insert_appt = $pdo->prepare("
                    INSERT INTO tbl_appointment (patient_id, doctor_id, appointment_date, time_slot, appointment_type, status)
                    VALUES (?, ?, ?, ?, 'Appointment', 'Pending')
                ");
                $insert_appt->execute([
                    $patient_id,
                    $doctor_id,
                    $appointment_date,
                    $appointment_time
                ]);
                
                $appointment_id = $pdo->lastInsertId();
                $appointment_ids[] = $appointment_id; // Keep as array for compatibility with existing email code logic

                // Insert services into link table
                $insert_service = $pdo->prepare("
                    INSERT INTO tbl_appointment_service (appointment_id, service_id, price_at_booking)
                    VALUES (?, ?, ?)
                ");

                foreach ($service_id_array as $service_id) {
                    // Find price
                    $price = 0;
                    $service_name = '';
                    foreach ($services as $s) {
                        if ($s['service_id'] == $service_id) {
                            $price = $s['base_price'];
                            $service_name = $s['service_name'];
                            break;
                        }
                    }
                    
                    $insert_service->execute([$appointment_id, $service_id, $price]);
                    $service_names[] = $service_name;
                }
                
                // Get doctor name for email
                $doctor_name = '';
                foreach ($dentists as $dentist) {
                    if ($dentist['user_id'] == $doctor_id) {
                        $doctor_name = $dentist['full_name'];
                        break;
                    }
                }
                
                // Send appointment scheduled email notification (one email with all services)
                require_once '../includes/email_notifications.php';
                try {
                    $service_name_display = implode(', ', $service_names);
                    sendAppointmentScheduled(
                        $appointment_ids[0], // Use first appointment ID
                        $patient_id,
                        $appointment_date,
                        $appointment_time,
                        $service_name_display,
                        $doctor_name
                    );
                } catch (Exception $e) {
                    error_log("Error sending appointment scheduled email: " . $e->getMessage());
                    // Don't fail the appointment creation if email fails
                }

                // Create notification
                try {
                    // Fetch patient name for the notification
                    $pat_stmt = $pdo->prepare("SELECT first_name, last_name FROM tbl_patient WHERE patient_id = ?");
                    $pat_stmt->execute([$patient_id]);
                    $pat = $pat_stmt->fetch();
                    $pat_name = $pat ? ($pat['first_name'] . ' ' . $pat['last_name']) : 'Patient';

                    $notif_message = "New appointment scheduled with {$pat_name} on " . date('M d', strtotime($appointment_date)) . " at " . date('g:i A', strtotime($appointment_time));
                    $notif_link = "doctor_dashboard.php?appointment_id={$appointment_ids[0]}";
                    
                    createNotification($pdo, $doctor_id, 'Dentist', 'Appointment', 'New Appointment', $notif_message, $notif_link);
                } catch (Exception $e) {
                    error_log("Error creating notification: " . $e->getMessage());
                }

                // No Payment or Invoice creation here.
                // Payment will be handled in the Payment Module.
                
                $pdo->commit();
                
                // Redirect to self with success flag to show the modal
                header("Location: receptionist_create_booking.php?success=1&appt_id=" . $appointment_ids[0]);
                exit;
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                
                // Log detailed error information
                $error_details = [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'patient_id' => $patient_id,
                    'doctor_id' => $doctor_id,
                    'appointment_date' => $appointment_date,
                    'appointment_time' => $appointment_time,
                    'services' => implode(',', $service_id_array)
                ];
                error_log('Error creating appointment: ' . json_encode($error_details));
                
                // Provide detailed error message to user (helpful for debugging)
                $error_message = 'Failed to create appointment. ';
                
                // Check for common database errors
                if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $error_message .= 'Duplicate appointment detected. ';
                } elseif (strpos($e->getMessage(), 'foreign key constraint') !== false) {
                    $error_message .= 'Invalid patient or doctor selected. ';
                } elseif (strpos($e->getMessage(), 'cannot be null') !== false) {
                    $error_message .= 'Missing required information. ';
                }
                
                // Add technical details for debugging
                $error_message .= 'Error: ' . $e->getMessage() . ' (Code: ' . $e->getCode() . ')';
                
                $form_data = $_POST;
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Unexpected error creating appointment: ' . $e->getMessage());
                $error_message = 'Unexpected error: ' . $e->getMessage();
                $form_data = $_POST;
            }
        } else {
            $error_message = implode(' ', $errors);
            $form_data = $_POST;
        }
    }
}

// Get patient ID from URL if provided
$selected_patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : 0;
// Installment Rules (Category-Based) - Copied from Admin
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
$selected_patient = null;

if ($selected_patient_id > 0) {
    try {
        $patient_stmt = $pdo->prepare("SELECT * FROM tbl_patient WHERE patient_id = ? LIMIT 1");
        $patient_stmt->execute([$selected_patient_id]);
        $selected_patient = $patient_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching patient: ' . $e->getMessage());
    }
}

// Generate CSRF token
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Create Booking - Receptionist Portal</title>
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
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_booking.php' || $current_page === 'receptionist_create_booking.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_booking.php">
<span class="material-symbols-outlined">calendar_month</span>
<span class="<?php echo ($current_page === 'receptionist_booking.php' || $current_page === 'receptionist_create_booking.php') ? 'font-bold' : 'font-medium'; ?>">Bookings</span>
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
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Create New Booking</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">calendar_add_on</span>
                    Schedule an appointment for a patient
                </p>
</div>
                <div class="flex items-center gap-4">
                    <button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
                        <span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full hidden"></span>
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
<form action="receptionist_create_booking.php" method="POST" class="flex flex-col gap-6">
<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
<input type="hidden" name="create_booking" value="1">
<div class="grid grid-cols-1 xl:grid-cols-12 gap-6">
<div class="xl:col-span-8 flex flex-col gap-6">
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-8 relative overflow-hidden">
<div class="absolute top-0 left-0 w-1 h-full bg-royal-purple"></div>
<h3 class="text-xl font-bold text-[#151118] mb-6 flex items-center gap-2">
<span class="w-8 h-8 rounded-lg bg-purple-50 text-royal-purple flex items-center justify-center">
<span class="material-symbols-outlined text-sm">person</span>
</span>
                                    Patient Selection
                                </h3>
<div class="flex flex-col gap-4">
<div>
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Select Patient <span class="text-red-500">*</span></label>
<button type="button" onclick="openPatientModal()" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold text-slate-600 hover:bg-gray-100 hover:border-royal-purple transition-all flex items-center justify-center gap-2">
<span class="material-symbols-outlined">person_search</span>
<span id="selectPatientButtonText"><?php echo $selected_patient ? htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']) : 'Select Patient...'; ?></span>
</button>
<input type="hidden" name="patient_id" id="selectedPatientId" value="<?php echo $selected_patient ? $selected_patient['patient_id'] : (isset($form_data['patient_id']) ? $form_data['patient_id'] : ''); ?>" required>
</div>
<div id="selectedPatientDisplay" class="<?php echo $selected_patient ? '' : 'hidden'; ?> p-4 bg-purple-50 rounded-xl border border-purple-100 flex items-center gap-3">
<?php if ($selected_patient): 
    $patient_initials = strtoupper(substr($selected_patient['first_name'], 0, 1) . substr($selected_patient['last_name'], 0, 1));
    $patient_id_display = 'P-' . str_pad($selected_patient['patient_id'], 4, '0', STR_PAD_LEFT);
?>
<div class="w-10 h-10 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-lg"><?php echo $patient_initials; ?></div>
<div class="flex-1">
<p class="text-sm font-bold text-royal-purple" id="selectedPatientName"><?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']); ?></p>
<p class="text-xs text-slate-500" id="selectedPatientInfo"><?php echo $patient_id_display; ?> • <?php echo htmlspecialchars($selected_patient['phone_number']); ?></p>
</div>
<button type="button" class="text-slate-400 hover:text-red-500" onclick="clearSelectedPatient()"><span class="material-symbols-outlined text-sm">close</span></button>
<?php else: ?>
<div class="w-10 h-10 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-lg" id="selectedPatientInitials">--</div>
<div class="flex-1">
<p class="text-sm font-bold text-royal-purple" id="selectedPatientName">--</p>
<p class="text-xs text-slate-500" id="selectedPatientInfo">--</p>
</div>
<button type="button" class="text-slate-400 hover:text-red-500" onclick="clearSelectedPatient()"><span class="material-symbols-outlined text-sm">close</span></button>
<?php endif; ?>
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
<select class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none cursor-pointer" name="doctor_id" id="doctor_id" required>
<option value="">Select Dentist...</option>
<?php foreach ($dentists as $dentist): ?>
<option value="<?php echo $dentist['user_id']; ?>" <?php echo (isset($form_data['doctor_id']) && $form_data['doctor_id'] == $dentist['user_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($dentist['full_name']); ?></option>
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
                    data-category="<?php echo htmlspecialchars($service['category']); ?>"
                    data-installment="<?php echo $service['is_installment_available']; ?>"
                    data-min-dp="<?php echo $service['installment_down_payment']; ?>"
                    data-months="<?php echo $service['installment_duration_months']; ?>"
                    data-interest="<?php echo $service['installment_interest_percent']; ?>"
                    data-monthly="<?php echo $service['installment_monthly_payment']; ?>"
                    data-requires-checkup="<?php echo $service['requires_checkup']; ?>"
                    data-requires-xray="<?php echo $service['requires_xray']; ?>"
                >
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
<div id="selectedServicesList" class="mt-3 space-y-2 max-h-48 overflow-y-auto">
<!-- Selected services will appear here -->
</div>
<input type="hidden" name="service_ids" id="service_ids" value="" required>
<input type="hidden" name="installment_selected_ids" id="installment_selected_ids" value="">
<p class="text-xs text-slate-400 mt-2">Select services from the dropdown and click "Add Service" to include multiple services</p>
</div>
<div>
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Appointment Date <span class="text-red-500">*</span></label>
<input class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none" name="appointment_date" value="<?php echo htmlspecialchars($form_data['appointment_date'] ?? date('Y-m-d')); ?>" type="date" min="<?php echo date('Y-m-d'); ?>" required/>
</div>
<div>
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Appointment Time <span class="text-red-500">*</span></label>
<?php echo generateTimeDropdown('appointment_time', $form_data['appointment_time'] ?? '', '09:00', '19:00', 'w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none', false, true, 'onchange="updateAppointmentDuration()"', 'appointment_time'); ?>
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
<div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Notes (Optional)</label>
                            <textarea class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none resize-none" placeholder="Additional notes or special instructions..." name="notes" rows="3"><?php echo htmlspecialchars($form_data['notes'] ?? ''); ?></textarea>
                        </div>

                        <!-- Customize Plan (Toggle Installment per Service) -->
                        <div class="md:col-span-2 border-t border-gray-100 pt-6 mt-2 hidden transition-all duration-300" id="customizePlanContainer">
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

                        <!-- Payment Info Preview (No Inputs) -->
                        <div class="md:col-span-2 mt-4 hidden transition-all duration-300" id="paymentPreviewContainer">
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
</div>
</div>
</div>
<div class="xl:col-span-4 flex flex-col gap-6">
<div class="bg-green-50 border border-green-100 rounded-2xl p-6">
<div class="flex items-start gap-4">
<div class="w-10 h-10 rounded-full bg-green-100 text-green-600 flex items-center justify-center flex-shrink-0">
<span class="material-symbols-outlined">event_available</span>
</div>
<div>
<h4 class="font-bold text-green-900 text-lg">New Appointment</h4>
<p class="text-sm text-green-800/70 mt-1 leading-snug">This appointment will be created with "Pending" status and can be confirmed later.</p>
</div>
</div>
</div>
<div class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6">
<h3 class="text-lg font-bold text-[#151118] mb-4">Quick Actions</h3>
<div class="flex flex-col gap-3">
<a href="receptionist_registration.php" class="flex items-center gap-3 p-3 bg-purple-50 hover:bg-purple-100 rounded-xl text-royal-purple font-bold text-sm transition-colors">
<span class="material-symbols-outlined">person_add</span>
Register New Patient
</a>
<a href="patient_records.php" class="flex items-center gap-3 p-3 bg-gray-50 hover:bg-gray-100 rounded-xl text-slate-600 font-bold text-sm transition-colors">
<span class="material-symbols-outlined">folder_shared</span>
View Patient Records
</a>
</div>
</div>
<div id="doctorScheduleWidget" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-6 hidden">
<h3 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
<span class="material-symbols-outlined text-royal-purple">schedule</span>
Weekly Schedule
</h3>
<div id="doctorScheduleContent" class="space-y-2">
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
<span class="material-symbols-outlined">calendar_add_on</span>
<span>Create Appointment</span>
</button>
</div>
</div>
</form>
</div>
</div>
</main>
</div>

<!-- Patient Selection Modal -->
<div id="patientModal" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-4xl w-full max-h-[90vh] flex flex-col">
        <div class="p-6 border-b border-gray-200 flex items-center justify-between">
            <div>
                <h3 class="text-2xl font-bold text-royal-purple">Select Patient</h3>
                <p class="text-sm text-slate-500 mt-1">Search and filter to find the patient</p>
            </div>
            <button onclick="closePatientModal()" class="w-10 h-10 flex items-center justify-center rounded-xl hover:bg-gray-100 text-slate-400 hover:text-slate-600 transition-colors">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 flex-1 overflow-hidden flex flex-col gap-4">
            <!-- Search and Filter Bar -->
            <div class="flex flex-col md:flex-row gap-3">
                <div class="flex-1 relative">
                    <span class="absolute left-3 top-3 text-slate-400 material-symbols-outlined text-sm">search</span>
                    <input id="modalPatientSearch" class="w-full pl-10 pr-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple placeholder:text-slate-400" placeholder="Search patients..." type="text" autocomplete="off" onkeypress="if(event.key === 'Enter') searchPatients()"/>
                </div>
                <select id="modalFilterType" class="px-4 py-2.5 bg-gray-50 border border-gray-200 rounded-xl text-sm font-medium focus:outline-none focus:ring-2 focus:ring-royal-purple/20 focus:border-royal-purple cursor-pointer">
                    <option value="all">All Fields</option>
                    <option value="name">Name</option>
                    <option value="phone">Phone</option>
                    <option value="email">Email</option>
                    <option value="id">Patient ID</option>
                </select>
                <button onclick="searchPatients()" class="px-6 py-2.5 bg-royal-purple hover:bg-purple-800 text-white rounded-xl font-bold text-sm transition-colors flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">search</span>
                    <span>Search</span>
                </button>
            </div>
            <!-- Patient List -->
            <div class="flex-1 overflow-y-auto border border-gray-200 rounded-xl">
                <div id="patientModalList" class="p-4">
                    <div class="text-center py-12 text-slate-400">
                        <span class="material-symbols-outlined text-4xl mb-2 block">person_search</span>
                        <p class="text-sm font-medium">Click "Search" to load patients</p>
                    </div>
                </div>
            </div>
            <!-- Pagination -->
            <div id="patientModalPagination" class="flex items-center justify-between pt-4 border-t border-gray-200 hidden">
                <button onclick="loadPreviousPage()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-slate-600 rounded-xl font-bold text-sm transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed" id="prevPageBtn" disabled>
                    <span class="material-symbols-outlined text-lg">chevron_left</span>
                    <span>Previous</span>
                </button>
                <span class="text-sm text-slate-600 font-medium" id="pageInfo">Page 1 of 1</span>
                <button onclick="loadNextPage()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-slate-600 rounded-xl font-bold text-sm transition-colors flex items-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed" id="nextPageBtn" disabled>
                    <span>Next</span>
                    <span class="material-symbols-outlined text-lg">chevron_right</span>
                </button>
            </div>
        </div>
    </div>
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

// Patient Modal functionality
let currentPatientPage = 1;
let totalPatientPages = 1;

const selectedPatientDisplay = document.getElementById('selectedPatientDisplay');
const selectedPatientId = document.getElementById('selectedPatientId');
const selectedPatientInitials = document.getElementById('selectedPatientInitials');
const selectedPatientName = document.getElementById('selectedPatientName');
const selectedPatientInfo = document.getElementById('selectedPatientInfo');
const selectPatientButtonText = document.getElementById('selectPatientButtonText');

window.openPatientModal = function() {
    const modal = document.getElementById('patientModal');
    if (modal) {
        modal.classList.remove('hidden');
        // Load all patients on open
        searchPatients();
    }
};

window.closePatientModal = function() {
    const modal = document.getElementById('patientModal');
    modal.classList.add('hidden');
}

window.searchPatients = function() {
    const searchInput = document.getElementById('modalPatientSearch');
    const filterType = document.getElementById('modalFilterType');
    const patientList = document.getElementById('patientModalList');
    const pagination = document.getElementById('patientModalPagination');
    
    const searchQuery = searchInput.value.trim();
    const filter = filterType.value;
    
    // Reset to page 1
    currentPatientPage = 1;
    
    // Show loading
    patientList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block animate-spin">hourglass_empty</span><p class="text-sm font-medium">Loading patients...</p></div>';
    
    const url = `../api/search_patients_modal.php?search=${encodeURIComponent(searchQuery)}&filter_type=${encodeURIComponent(filter)}&page=${currentPatientPage}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.patients) {
                displayPatientResults(data.patients, data.pagination);
            } else {
                patientList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block">person_off</span><p class="text-sm font-medium">No patients found</p></div>';
                pagination.classList.add('hidden');
            }
        })
        .catch(error => {
            console.error('Error searching patients:', error);
            patientList.innerHTML = '<div class="text-center py-12 text-red-400"><span class="material-symbols-outlined text-4xl mb-2 block">error</span><p class="text-sm font-medium">Error loading patients. Please try again.</p></div>';
            pagination.classList.add('hidden');
        });
}


    


window.selectPatientFromModal = function(patientId, firstName, lastName, patientIdDisplay, phoneNumber, email) {
    const initials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();
    
    selectedPatientId.value = patientId;
    selectedPatientInitials.textContent = initials;
    selectedPatientName.textContent = firstName + ' ' + lastName;
    selectPatientButtonText.textContent = firstName + ' ' + lastName;
    
    const infoParts = [patientIdDisplay];
    if (phoneNumber) infoParts.push(phoneNumber);
    if (email) infoParts.push(email);
    selectedPatientInfo.textContent = infoParts.join(' • ');
    
    selectedPatientDisplay.classList.remove('hidden');
    closePatientModal();
};

window.clearSelectedPatient = function() {
    selectedPatientDisplay.classList.add('hidden');
    selectedPatientId.value = '';
    selectedPatientInitials.textContent = '--';
    selectedPatientName.textContent = '--';
    selectedPatientInfo.textContent = '--';
    selectPatientButtonText.textContent = 'Select Patient...';
}

window.loadPreviousPage = function() {
    if (currentPatientPage > 1) {
        currentPatientPage--;
        loadPatientPage();
    }
};

window.loadNextPage = function() {
    if (currentPatientPage < totalPatientPages) {
        currentPatientPage++;
        loadPatientPage();
    }
};

window.loadPatientPage = function() {
    const searchInput = document.getElementById('modalPatientSearch');
    const filterType = document.getElementById('modalFilterType');
    const patientList = document.getElementById('patientModalList');
    
    const searchQuery = searchInput.value.trim();
    const filter = filterType.value;
    
    // Show loading
    patientList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block animate-spin">hourglass_empty</span><p class="text-sm font-medium">Loading patients...</p></div>';
    
    const url = `../api/search_patients_modal.php?search=${encodeURIComponent(searchQuery)}&filter_type=${encodeURIComponent(filter)}&page=${currentPatientPage}`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.patients) {
                displayPatientResults(data.patients, data.pagination);
            } else {
                patientList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block">person_search</span><p class="text-sm font-medium">No patients found</p></div>';
                document.getElementById('patientModalPagination').classList.add('hidden');
            }
        })
        .catch(error => {
            console.error('Error loading patients:', error);
            patientList.innerHTML = '<div class="text-center py-12 text-red-400"><span class="material-symbols-outlined text-4xl mb-2 block">error</span><p class="text-sm font-medium">Error loading patients</p></div>';
        });
};

function displayPatientResults(patients, pagination) {
    const patientList = document.getElementById('patientModalList');
    const paginationDiv = document.getElementById('patientModalPagination');
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPageBtn');
    const nextBtn = document.getElementById('nextPageBtn');
    
    if (patients.length === 0) {
        patientList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block">person_search</span><p class="text-sm font-medium">No patients found</p></div>';
        paginationDiv.classList.add('hidden');
        return;
    }
    
    // Update pagination info
    currentPatientPage = pagination.current_page;
    totalPatientPages = pagination.total_pages;
    pageInfo.textContent = `Page ${currentPatientPage} of ${totalPatientPages} (${pagination.total_patients} total)`;
    
    // Enable/disable pagination buttons
    prevBtn.disabled = currentPatientPage <= 1;
    nextBtn.disabled = currentPatientPage >= totalPatientPages;
    
    // Show pagination if more than one page
    if (totalPatientPages > 1) {
        paginationDiv.classList.remove('hidden');
    } else {
        paginationDiv.classList.add('hidden');
    }
    
    // Group patients
    const adults = patients.filter(p => p.age >= 18);
    const minors = patients.filter(p => p.age < 18);
    
    let html = '';
    
    // Render Adults
    if (adults.length > 0) {
        html += '<div class="mb-4">';
        html += '<h4 class="px-4 py-2 text-xs font-bold text-slate-500 uppercase tracking-wide bg-gray-50 border-y border-gray-100 mb-2">Adults</h4>';
        html += adults.map(renderPatientItem).join('');
        html += '</div>';
    }
    
    // Render Minors
    if (minors.length > 0) {
        html += '<div class="mb-4">';
        html += '<h4 class="px-4 py-2 text-xs font-bold text-orange-600 uppercase tracking-wide bg-orange-50 border-y border-orange-100 mb-2 flex items-center gap-2"><span class="material-symbols-outlined text-sm">child_care</span> Minors (Under 18)</h4>';
        html += minors.map(renderPatientItem).join('');
        html += '</div>';
    }
    
    patientList.innerHTML = html;
}

function renderPatientItem(patient) {
    const initials = (patient.first_name.charAt(0) + patient.last_name.charAt(0)).toUpperCase();
    const patientName = `${escapeHtml(patient.first_name)} ${escapeHtml(patient.last_name)}`;
    const patientId = 'P-' + String(patient.patient_id).padStart(4, '0');
    const phoneDisplay = patient.phone_number ? ' • ' + escapeHtml(patient.phone_number) : '';
    const emailDisplay = patient.email ? ' • ' + escapeHtml(patient.email) : '';
    const ageInfo = patient.age ? `<span class="px-2 py-0.5 rounded text-[10px] font-bold ${patient.age < 18 ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600'} ml-2">${patient.age} yo</span>` : '';

    return `
        <div class="px-4 py-3 hover:bg-purple-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors group" onclick="selectPatientFromModal(${patient.patient_id}, '${escapeHtml(patient.first_name)}', '${escapeHtml(patient.last_name)}', '${patientId}', '${escapeHtml(patient.phone_number || '')}', '${escapeHtml(patient.email || '')}')">
            <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-sm flex-shrink-0">${initials}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center">
                        <p class="font-bold text-sm text-[#151118] group-hover:text-royal-purple transition-colors truncate">${patientName}</p>
                        ${ageInfo}
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">${patientId}${phoneDisplay}${emailDisplay}</p>
                </div>
                <span class="material-symbols-outlined text-slate-300 group-hover:text-royal-purple transition-colors">chevron_right</span>
            </div>
        </div>
    `;
}


function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Patient Modal Functions
function openPatientModal() {
    const modal = document.getElementById('patientModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    }
}

function closePatientModal() {
    const modal = document.getElementById('patientModal');
    if (modal) {
        modal.classList.add('hidden');
        document.body.style.overflow = '';
    }
}

function selectPatientFromModal(id, firstName, lastName, patientCode, phone, email) {
    const hiddenInput = document.getElementById('selectedPatientId');
    const displayDiv = document.getElementById('selectedPatientDisplay');
    const placeholder = document.getElementById('patientSelectPlaceholder');
    const nameEl = document.getElementById('selectedPatientName');
    const detailsEl = document.getElementById('selectedPatientDetails');
    
    if (hiddenInput) hiddenInput.value = id;
    
    if (placeholder) placeholder.classList.add('hidden');
    if (displayDiv) displayDiv.classList.remove('hidden');
    
    if (nameEl) nameEl.textContent = `${firstName} ${lastName}`;
    
    if (detailsEl) {
        let details = patientCode;
        if (phone) details += ` • ${phone}`;
        if (email) details += ` • ${email}`;
        detailsEl.textContent = details;
    }
    
    closePatientModal();
}

// Close modal when clicking outside
document.getElementById('patientModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closePatientModal();
    }
});

// Form validation
document.querySelector('form')?.addEventListener('submit', function(e) {
    const patientId = document.getElementById('selectedPatientId').value;
    const doctorId = document.querySelector('select[name="doctor_id"]').value;
    // service_ids input is populated by updateServiceIdsInput
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
    if (appointmentDateTime < now) {
        e.preventDefault();
        showError('Appointment date and time cannot be in the past.', 'Invalid Date/Time');
        return false;
    }
    
    // Validate doctor availability (client-side check - will be double-checked server-side)
    if (doctorId && appointmentDate && appointmentTime) {
        // Get total service duration from selected services
        const totalDuration = selectedServices.reduce((sum, service) => sum + service.duration, 0);
        
        if (totalDuration === 0) {
            e.preventDefault();
            showError('Please select at least one service.', 'No Services Selected');
            return false;
        }
        
        // Calculate end time
        const endTime = new Date(appointmentDateTime);
        endTime.setMinutes(endTime.getMinutes() + totalDuration);
        
        // Check if end time exceeds clinic hours
        const endHour = endTime.getHours();
        const endMinute = endTime.getMinutes();
        const endTimeInMinutes = endHour * 60 + endMinute;
        
        if (endTimeInMinutes > clinicClose) {
            e.preventDefault();
            showError(`The total service duration (${totalDuration} minutes) would extend beyond clinic closing time (7:00 PM). Please choose an earlier time or remove some services.`, 'Time Exceeds Clinic Hours');
            return false;
        }
    }
});

<?php if ($selected_patient): ?>
// Auto-populate patient if provided in URL
document.addEventListener('DOMContentLoaded', function() {
    selectPatientFromModal(
        <?php echo $selected_patient['patient_id']; ?>,
        '<?php echo addslashes($selected_patient['first_name']); ?>',
        '<?php echo addslashes($selected_patient['last_name']); ?>',
        '<?php echo 'P-' . str_pad($selected_patient['patient_id'], 4, '0', STR_PAD_LEFT); ?>',
        '<?php echo addslashes($selected_patient['phone_number'] ?? ''); ?>',
        '<?php echo addslashes($selected_patient['email'] ?? ''); ?>'
    );
});
<?php endif; ?>

// Multiple Services Management
let selectedServices = []; // Array of {id, name, duration, price}
let allServices = []; // Store all available services for restoration

// Initialize all services from dropdown options
// Initialize all services from dropdown options
function initializeAllServices() {
    const dropdown = document.getElementById('service_dropdown');
    allServices = [];
    for (let i = 1; i < dropdown.options.length; i++) { // Skip first option (placeholder)
        const option = dropdown.options[i];
        // Note: Default to installment=true if available
        const isInstallment = option.dataset.installment === '1';
        
        allServices.push({
            id: parseInt(option.value),
            name: option.dataset.name,
            duration: parseInt(option.dataset.duration || 30),
            price: parseFloat(option.dataset.price || 0),
            installment: isInstallment,
            minDpPercent: parseFloat(option.dataset.minDp || 0),
            months: parseInt(option.dataset.months || 0),
            interest: parseFloat(option.dataset.interest || 0),
            monthlyPayment: parseFloat(option.dataset.monthly || 0),
            requiresCheckup: option.dataset.requiresCheckup === '1',
            requiresXray: option.dataset.requiresXray === '1',
            useInstallment: isInstallment, // Default to true if available
            optionElement: option.cloneNode(true) // Clone for restoration
        });
    }
}

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
    const isInstallmentAvailable = selectedOption.dataset.installment === '1';
    const requiresCheckup = selectedOption.dataset.requiresCheckup === '1';
    const requiresXray = selectedOption.dataset.requiresXray === '1';
    
    // Check if service is already added
    if (selectedServices.find(s => s.id === serviceId)) {
        showError('This service is already added.', 'Service Already Added');
        return;
    }
    
    // Automated validation for requirements
    if (requiresCheckup || requiresXray) {
        const patientId = document.getElementById('selectedPatientId').value;
        
        if (!patientId) {
            showError('Please select a patient first before adding services.', 'No Patient Selected');
            dropdown.selectedIndex = 0;
            return;
        }
        
        try {
            // Call API to check if patient has completed required services
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
                // Block the service - requirements not met
                showError(
                    result.message + '\n\nThe patient must first complete and pay for these services before booking "' + serviceName + '".',
                    'Requirements Not Met'
                );
                dropdown.selectedIndex = 0;
                return; // Block adding the service
            }
            
            // Requirements met, continue to add service
            
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
        category: selectedOption.dataset.category || 'General Dentistry',
        installment: isInstallmentAvailable,
        minDpPercent: parseFloat(selectedOption.dataset.minDp || 0),
        months: parseInt(selectedOption.dataset.months || 0),
        interest: parseFloat(selectedOption.dataset.interest || 0),
        monthlyPayment: parseFloat(selectedOption.dataset.monthly || 0),
        requiresCheckup: requiresCheckup,
        requiresXray: requiresXray,
        useInstallment: isInstallmentAvailable // Default to true if available
    });
    
    updateSelectedServicesList();
    updateServiceIdsInput();
    
    // Remove from dropdown
    selectedOption.remove();
    dropdown.selectedIndex = 0; // Reset to placeholder
    
    // Update Payment Preview
    renderCustomizePlan();
    updatePaymentOptions();
}

function removeService(serviceId) {
    // Find the service to restore to dropdown
    const serviceToRemove = selectedServices.find(s => s.id === serviceId);
    if (!serviceToRemove) return;
    
    // Remove from selected services
    selectedServices = selectedServices.filter(s => s.id !== serviceId);
    
    updateSelectedServicesList();
    updateServiceIdsInput();
    
    // Restore to dropdown (in original order if possible, or just append)
    const dropdown = document.getElementById('service_dropdown');
    
    // Find original data from allServices
    const originalServiceData = allServices.find(s => s.id === serviceId);
    if (originalServiceData) {
        const option = originalServiceData.optionElement.cloneNode(true);
        dropdown.add(option);
        
        // Sort options by text (optional but good for UX)
        // Array.from(dropdown.options).sort((a, b) => a.text.localeCompare(b.text)).forEach(option => dropdown.add(option));
    }
    
    // Update Payment Preview
    renderCustomizePlan();
    updatePaymentOptions();
}

function updateSelectedServicesList() {
    const listContainer = document.getElementById('selectedServicesList');
    const totalDurationText = document.getElementById('serviceDurationText');
    const endTimeText = document.getElementById('estimatedEndTime');
    const appointmentTimeSelect = document.querySelector('select[name="appointment_time"]');
    
    listContainer.innerHTML = '';
    
    let totalDuration = 0;
    
    selectedServices.forEach(service => {
        totalDuration += service.duration;
        
        let badges = '';
        if (service.requiresCheckup) {
            badges += `<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-800 mr-1">Check-up Required</span>`;
        }
        if (service.requiresXray) {
            badges += `<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-800 mr-1">X-ray Required</span>`;
        }
        
        const item = document.createElement('div');
        item.className = 'flex items-center justify-between p-3 bg-purple-50 border border-purple-100 rounded-xl animate-fade-in';
        item.innerHTML = `
            <div>
                <span class="block font-bold text-sm text-royal-purple mb-1">${escapeHtml(service.name)}</span>
                <div class="flex flex-wrap gap-1 mb-1">
                    ${badges}
                </div>
                <span class="text-xs text-slate-500">${service.duration} mins • ₱${service.price.toFixed(2)}</span>
            </div>
            <button type="button" onclick="removeService(${service.id})" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-white hover:shadow-sm text-slate-400 hover:text-red-500 transition-all">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        `;
        listContainer.appendChild(item);
    });
    
    // Update duration text
    if (selectedServices.length > 0) {
        totalDurationText.textContent = `Total duration: ${totalDuration} minutes`;
    } else {
        totalDurationText.textContent = 'Service duration: -- minutes';
    }
    
    // Recalculate end time if start time is selected
    if (appointmentTimeSelect && appointmentTimeSelect.value && selectedServices.length > 0) {
        calculateEndTime(appointmentTimeSelect.value, totalDuration);
    } else {
        endTimeText.textContent = '--';
    }
    
    updateAppointmentDuration();
}

function updateServiceIdsInput() {
    const serviceIdsInput = document.getElementById('service_ids');
    const serviceIds = selectedServices.map(s => s.id).join(',');
    serviceIdsInput.value = serviceIds;
    
    // Update required validation
    if (serviceIds) {
        serviceIdsInput.removeAttribute('required');
    } else {
        serviceIdsInput.setAttribute('required', 'required');
    }
}

function calculateEndTime(startTime, durationMinutes) {
    if (!startTime || durationMinutes <= 0) return;
    
    const [hours, minutes] = startTime.split(':').map(Number);
    const date = new Date();
    date.setHours(hours, minutes, 0, 0);
    date.setMinutes(date.getMinutes() + durationMinutes);
    
    const endHours = date.getHours();
    const endMinutes = date.getMinutes();
    
    const formatted = formatTime(endHours, endMinutes);
    document.getElementById('estimatedEndTime').textContent = formatted;
}

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
        calculateEndTime(timeValue, totalDuration);
        
        // Update display
        const serviceCount = selectedServices.length;
        const serviceText = serviceCount === 1 ? 'service' : 'services';
        serviceDurationText.textContent = `Total duration: ${totalDuration} minutes (${serviceCount} ${serviceText})`;
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
        const [hours, minutes] = timeValue.split(':').map(Number);
        const currentTimeInMinutes = hours * 60 + minutes;
        
        if (currentTimeInMinutes > latestStartMinutes) {
            appointmentTime.setCustomValidity(`Appointment time must allow ${totalDuration} minutes before clinic closes at 7:00 PM. Latest start time: ${formatTime(latestStartHour, latestStartMin)}`);
        } else {
            appointmentTime.setCustomValidity('');
        }
    } else {
        // Only hide if no services or no time
        if (selectedServices.length === 0) {
             durationInfo.classList.add('hidden');
        }
        
        appointmentTime.setAttribute('max', '19:00'); // Reset to default
        appointmentTime.setCustomValidity('');
    }
}

function formatTime(hours, minutes) {
    const period = hours >= 12 ? 'PM' : 'AM';
    const displayHours = hours > 12 ? hours - 12 : (hours === 0 ? 12 : hours);
    const displayMinutes = String(minutes).padStart(2, '0');
    return `${displayHours}:${displayMinutes} ${period}`;
}

// Doctor Schedule Widget
const doctorScheduleWidget = document.getElementById('doctorScheduleWidget');
const doctorScheduleContent = document.getElementById('doctorScheduleContent');
const doctorSelect = document.getElementById('doctor_id');

// Load doctor schedule when doctor is selected
if (doctorSelect) {
    doctorSelect.addEventListener('change', function() {
        const doctorId = this.value;
        if (doctorId) {
            loadDoctorScheduleWeek(doctorId);
        } else {
            doctorScheduleWidget.classList.add('hidden');
        }
    });
}

function loadDoctorScheduleWeek(doctorId) {
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

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all services list
    initializeAllServices();
    
    updateSelectedServicesList();
    updateServiceIdsInput();
    updateAppointmentDuration();
    updatePaymentOptions();
    
    // Update duration when time changes
    document.getElementById('appointment_time')?.addEventListener('change', updateAppointmentDuration);
});




// Toggle Installment for a service
function toggleServiceInstallment(serviceId) {
    const service = selectedServices.find(s => s.id === serviceId);
    if (service && service.installment) {
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
        if (service.minDpPercent > 0) {
            dpAmount = service.minDpPercent;
        } else {
            const rule = installmentRules[service.category];
            const dpPercent = rule ? rule.min_dp : 20;
            dpAmount = service.price * (dpPercent / 100);
        }
        
        let controlHtml = '';
        let statusColor = '';
        
        if (service.installment) {
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

const installmentRules = <?php echo json_encode($installment_rules ?? []); ?>;

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
    let maxMonths = 0;
    let hasInstallmentService = false;
    let estTotalInterest = 0;
    let totalMonthlyPayment = 0;

    // Calculate Totals
    selectedServices.forEach(s => {
        totalAmount += s.price;
        
        // Only treat as installment if capability exists AND toggled on
        if (s.installment && s.useInstallment) {
            hasInstallmentService = true;
            
            // DP Calculation
            // Priority: 1. DB Value (Amount) 2. Category Rule (Percent) 3. Default 20%
            let dpAmount = 0;
            
            // If DB has a value > 0, treat it as the FIXED Amount
            if (s.minDpPercent > 0) {
                dpAmount = s.minDpPercent;
            } else {
                // Fallback to Category Rule (Percentage of Price)
                const rule = installmentRules[s.category];
                const dpPercent = rule ? rule.min_dp : 20;
                dpAmount = s.price * (dpPercent / 100);
            }
            
            minDpForInstallments += dpAmount;
            
            // Monthly Payment Calculation
            if (s.monthlyPayment > 0) {
                // Use Fixed DB Monthly Payment
                totalMonthlyPayment += s.monthlyPayment;
                // For max months, estimation:
                if (s.months > maxMonths) maxMonths = s.months;
            } else {
                // Approximate Dynamic Calculation
                const interestAmount = s.price * ((s.interest || 0) / 100);
                estTotalInterest += interestAmount;
                
                const totalWithInterest = s.price + interestAmount;
                const balance = totalWithInterest - dpAmount;
                
                // If months is 0, default to 6 for calculation safety
                const serviceMonths = s.months > 0 ? s.months : 6;
                totalMonthlyPayment += (balance / serviceMonths);
                
                // Max Months
                if (s.months > maxMonths) maxMonths = s.months;
            }
        } else {
             // Non-installment services OR Installment service toggled to Full Pay
             minDpForInstallments += s.price; 
        }
    });

    // Final UI Update Logic (Simplified)
    // Note: We sum up totalMonthlyPayment directly now.


    // Update UI Elements
    document.getElementById('estTotalAmount').textContent = '₱' + totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    const badge = document.getElementById('estInstallmentBadge');
    if (hasInstallmentService) {
        badge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-green-100 text-green-700';
        badge.innerHTML = '<span class="material-symbols-outlined text-[16px]">check_circle</span> Yes';
        
        document.getElementById('estMinDP').textContent = '₱' + minDpForInstallments.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('estMonthlyVal').textContent = '₱' + totalMonthlyPayment.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('estMaxMonths').textContent = maxMonths + ' Months';
    } else {
        badge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-gray-200 text-gray-600';
        badge.innerHTML = 'No';
        
        document.getElementById('estMinDP').textContent = '₱' + totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2}); // Full Amount
        document.getElementById('estMonthlyVal').textContent = '₱0.00';
        document.getElementById('estMaxMonths').textContent = '0 Months';
    }
}

// Success Modal & Redirect Logic
document.addEventListener('DOMContentLoaded', function() {
    // Check URL params
    const urlParams = new URLSearchParams(window.location.search);
    const success = urlParams.get('success');
    const apptId = urlParams.get('appt_id');

    if (success === '1' && apptId) {
        showConfirm(
            "Booking created successfully! The appointment status is 'Pending'.\n\nDo you want to proceed to payment now?", 
            "Booking Successful"
        ).then(result => {
            if (result) {
                // Yes -> Go to Process Payment (Unified Interface)
                window.location.href = `receptionist_process_payment.php`;
            } else {
                // No -> Go to Booking List
                window.location.href = 'receptionist_booking.php';
            }
        });
    }

    // Initialize all services list
    initializeAllServices();
    
    updateSelectedServicesList();
    updateServiceIdsInput();
    updateAppointmentDuration();
    renderCustomizePlan();
    updatePaymentOptions();
    
    // Update duration when time changes
    document.getElementById('appointment_time')?.addEventListener('change', updateAppointmentDuration);
});

// Remove unused functions
// window.togglePaymentDetails = null; 
</script>
<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>
