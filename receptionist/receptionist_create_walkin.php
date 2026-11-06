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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_walkin'])) {
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
        
        $notes = sanitizeInput($_POST['notes'] ?? '');
        
        // Parse service IDs (comma-separated)
        $service_id_array = [];
        if (!empty($service_ids)) {
            $service_id_array = array_filter(array_map('intval', explode(',', $service_ids)));
            $service_id_array = array_unique($service_id_array); // Remove duplicates
        }
        
        // Get current date and time for walk-in
        $walkin_date = date('Y-m-d');
        // Support specific time request (e.g. from wait-list resolution), otherwise use current time
        $walkin_time = isset($_POST['time']) && !empty($_POST['time']) ? $_POST['time'] : date('H:i:s');
        $walkin_datetime = new DateTime();
        
        // Validation
        $errors = [];
        
        if ($patient_id <= 0) $errors[] = 'Please select a patient.';
        if ($doctor_id <= 0) $errors[] = 'Please select a dentist.';
        if (empty($service_id_array)) $errors[] = 'Please select at least one service.';
        
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
            }
        }
        // --- END CONSENT VERIFICATION ---
        
        // Check if doctor is available today (has schedule)
        if (empty($errors) && $doctor_id > 0) {
            try {
                $day_of_week = $walkin_datetime->format('l'); // e.g., "Monday", "Tuesday"
                
                // Check if doctor has schedule for today
                $schedule_stmt = $pdo->prepare("
                    SELECT start_time, end_time
                    FROM tbl_doctor_schedule
                    WHERE doctor_id = ? AND day_of_week = ?
                ");
                $schedule_stmt->execute([$doctor_id, $day_of_week]);
                $doctor_schedule = $schedule_stmt->fetch();
                
                if (!$doctor_schedule) {
                    $errors[] = 'The selected doctor is not available today (' . $day_of_week . '). Please choose a different doctor.';
                } else {
                    // Check if current time is within doctor's schedule
                    $current_time_obj = new DateTime($walkin_time);
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
                    
                    // Calculate walk-in end time
                    $walkin_end_time = clone $current_time_obj;
                    $walkin_end_time->modify('+' . $total_service_duration . ' minutes');
                    
                    // Check if current time is before doctor's start time
                    if ($current_time_obj < $doctor_start) {
                        $errors[] = 'Current time is before the doctor\'s available hours. Doctor is available from ' . $doctor_start->format('g:i A') . ' to ' . $doctor_end->format('g:i A') . ' today.';
                    }
                    
                    // Check if walk-in end time is after doctor's end time
                    if ($walkin_end_time > $doctor_end) {
                        $errors[] = 'The total service duration (' . $total_service_duration . ' minutes) would extend beyond the doctor\'s available hours. Doctor is available until ' . $doctor_end->format('g:i A') . ' today.';
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
                    $errors[] = 'This patient already has an active appointment/walk-in. Patients can only have one active booking at a time.';
                }
            } catch (PDOException $e) {
                error_log('Error checking active appointments: ' . $e->getMessage());
                $errors[] = 'Error validating patient appointments.';
            }
        }
        
        // Check for conflicts with existing appointments (APPOINTMENTS TAKE PRIORITY)
        if (empty($errors) && $doctor_id > 0) {
            try {
                // Calculate total service duration
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
                    $total_service_duration = 30; // Default 30 minutes
                }
                
                // Calculate end time
                $start_time = new DateTime($walkin_date . ' ' . $walkin_time);
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
                    $walkin_date,
                    $end_time->format('H:i:s'), $walkin_time,
                    $end_time->format('H:i:s'), $walkin_time,
                    $walkin_time, $end_time->format('H:i:s')
                ]);
                
                if ($blocked_time_stmt->fetch()) {
                    $errors[] = 'Cannot create walk-in: This time slot conflicts with blocked time. Please wait or choose a different doctor.';
                }

                // Conflict resolution variables
                $suggested_time = null;
                $conflict_detected = false;
                
                // Check for conflicts with existing APPOINTMENTS
                // Logic: Walk-ins (Paid/Immed) take priority over PENDING (Unpaid) appointments.
                // But blocked by CONFIRMED/BOOKED appointments.
                $conflicting_appointment_to_cancel = null;
                
                if (empty($errors)) {
                    $appointment_conflict_stmt = $pdo->prepare("
                        SELECT a.appointment_id, a.time_slot, a.status,
                               SUM(s.estimated_duration_mins) as total_duration
                        FROM tbl_appointment a
                        JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                        JOIN tbl_service s ON tas.service_id = s.service_id
                        WHERE a.doctor_id = ? 
                          AND a.appointment_date = ?
                          AND a.appointment_type = 'Appointment'
                          AND a.status NOT IN ('Cancelled', 'No-Show', 'Completed')
                        GROUP BY a.appointment_id
                        HAVING (
                               a.time_slot < ? -- Existing Start < New End
                               AND 
                               ADDTIME(a.time_slot, SEC_TO_TIME(total_duration * 60)) > ? -- Existing End > New Start
                           )
                    ");
                    $appointment_conflict_stmt->execute([
                        $doctor_id,
                        $walkin_date,
                        $end_time->format('H:i:s'), // New End
                        $walkin_time // New Start
                    ]);
                    
                    $conflicts = $appointment_conflict_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if ($conflicts) {
                        foreach ($conflicts as $conflict) {
                            if ($conflict['status'] === 'Pending') {
                                // Walk-in overwrites Pending Appointment
                                $conflicting_appointment_to_cancel = $conflict['appointment_id'];
                            } else {
                                // Blocked by Booked/Confirmed Appointment
                                // Calculate suggested time (End of current + 10 mins)
                                $conflict_start = new DateTime($walkin_date . ' ' . $conflict['time_slot']);
                                $duration = ceil((float)$conflict['total_duration']);
                                if ($duration == 0) $duration = 30; // Default fallback
                                
                                $conflict_end = clone $conflict_start;
                                $conflict_end->modify('+' . $duration . ' minutes');
                                
                                $suggested_start = clone $conflict_end;
                                $suggested_start->modify('+10 minutes');
                                
                                $suggested_time = $suggested_start->format('H:i');
                                $conflict_doctor_name = "this doctor"; // Could fetch name if needed
                                $conflict_detected = true;
                                break; 
                            }
                        }
                    }
                }
            } catch (PDOException $e) {
                error_log('Error checking appointment conflicts: ' . $e->getMessage());
                $errors[] = 'Error checking appointment availability. Please try again.';
            }
        }
        
        // Check for conflicts with other walk-ins (but allow if there's enough time)
        if (empty($errors) && $doctor_id > 0) {
            try {
                // Calculate total service duration
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
                    $total_service_duration = 30; // Default 30 minutes
                }
                
                // Calculate end time
                $start_time = new DateTime($walkin_date . ' ' . $walkin_time);
                $end_time = clone $start_time;
                $end_time->modify('+' . $total_service_duration . ' minutes');
                
                // Check for conflicts with other walk-ins
                $walkin_conflict_stmt = $pdo->prepare("
                    SELECT a.appointment_id, a.time_slot,
                           SUM(s.estimated_duration_mins) as total_duration
                    FROM tbl_appointment a
                    JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                    JOIN tbl_service s ON tas.service_id = s.service_id
                    WHERE a.doctor_id = ? 
                      AND a.appointment_date = ?
                      AND a.appointment_type = 'Walk-in'
                      AND a.status NOT IN ('Cancelled', 'No-Show', 'Completed')
                    GROUP BY a.appointment_id
                    HAVING (
                          a.time_slot < ? -- Existing Start < New End
                          AND 
                          ADDTIME(a.time_slot, SEC_TO_TIME(total_duration * 60)) > ? -- Existing End > New Start
                      )
                ");
                $walkin_conflict_stmt->execute([
                    $doctor_id,
                    $walkin_date,
                    $end_time->format('H:i:s'), // New End
                    $walkin_time // New Start
                ]);
                
                $walkin_conflict = $walkin_conflict_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($walkin_conflict) {
                    // Calculate suggested time (End of current walk-in + 10 mins)
                    $conflict_start = new DateTime($walkin_date . ' ' . $walkin_conflict['time_slot']);
                    $duration = ceil((float)$walkin_conflict['total_duration']);
                    if ($duration == 0) $duration = 30; // Default fallback
                    
                    $conflict_end = clone $conflict_start;
                    $conflict_end->modify('+' . $duration . ' minutes');
                    
                    $suggested_start = clone $conflict_end;
                    $suggested_start->modify('+10 minutes');
                    
                    $suggested_time = $suggested_start->format('H:i');
                    $conflict_detected = true;
                    // Do NOT add to errors, allowing the modal to show
                    //$errors[] = 'This time slot conflicts with another walk-in. Please wait a moment or choose a different doctor.';
                }
            } catch (PDOException $e) {
                error_log('Error checking walk-in conflicts: ' . $e->getMessage());
                $errors[] = 'Error checking walk-in availability. Please try again.';
            }
        }
        
        if (empty($errors) && !$conflict_detected) {
            try {
                $pdo->beginTransaction();

                // Cancel conflicting pending appointment
                if ($conflicting_appointment_to_cancel) {
                    $cancel_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = 'Cancelled' WHERE appointment_id = ?");
                    $cancel_stmt->execute([$conflicting_appointment_to_cancel]);
                }
                
                $appointment_ids = [];
                $service_names = [];
                
                // Create ONE appointment for all services (marked as Walk-in and Confirmed)
                // Note: service_id column exists in legacy schema but we use M:N table. Passing first service_id to satisfy constraint.
                $primary_service_id = !empty($service_id_array) ? reset($service_id_array) : 1;
                
                $insert_appt = $pdo->prepare("
                    INSERT INTO tbl_appointment (patient_id, doctor_id, appointment_date, time_slot, appointment_type, status, service_id)
                    VALUES (?, ?, ?, ?, 'Walk-in', 'Arrived', ?)
                ");
                $insert_appt->execute([
                    $patient_id,
                    $doctor_id,
                    $walkin_date,
                    $walkin_time,
                    $primary_service_id
                ]);
                
                $appointment_id = $pdo->lastInsertId();
                $appointment_ids[] = $appointment_id;

                // Insert services
                $insert_service = $pdo->prepare("
                    INSERT INTO tbl_appointment_service (appointment_id, service_id, price_at_booking)
                    VALUES (?, ?, ?)
                ");

                foreach ($service_id_array as $service_id) {
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
                

                


                $pdo->commit();
                
                // Redirect to Booking List
                header("Location: receptionist_booking.php");
                exit;
                
            } catch (PDOException $e) {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Error creating walk-in: ' . $e->getMessage());
                $error_message = 'Failed to create walk-in. Please try again.';
                $form_data = $_POST;
            }
        } else {
            $error_message = implode(' ', $errors);
            $form_data = $_POST;
        }
    }
}

// Get patient ID from URL or Form Data
$selected_patient_id = 0;
if (isset($_POST['patient_id']) && !empty($_POST['patient_id'])) {
    $selected_patient_id = intval($_POST['patient_id']);
} elseif (isset($_GET['patient_id'])) {
    $selected_patient_id = intval($_GET['patient_id']);
}
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
<title>Create Walk-In - Receptionist Portal</title>
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
<style>.scrollbar-hide::-webkit-scrollbar {
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
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_booking.php' || $current_page === 'receptionist_create_booking.php' || $current_page === 'receptionist_create_walkin.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_booking.php">
<span class="material-symbols-outlined">calendar_month</span>
<span class="<?php echo ($current_page === 'receptionist_booking.php' || $current_page === 'receptionist_create_booking.php' || $current_page === 'receptionist_create_walkin.php') ? 'font-bold' : 'font-medium'; ?>">Bookings</span>
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
<h2 class="text-3xl font-black text-[#151118] tracking-tight">Create Walk-In</h2>
<p class="text-slate-500 font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-lg">directions_walk</span>
                    Walk-in booking for immediate service
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
<form action="receptionist_create_walkin.php" method="POST" class="flex flex-col gap-6">
<input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
<input type="hidden" name="create_walkin" value="1">
<!-- Hidden time input to allow overriding current time (e.g. when resolving conflicts) -->
<input type="hidden" name="time" id="time" value="<?php echo isset($_POST['time']) ? htmlspecialchars($_POST['time']) : ''; ?>">
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
<span class="material-symbols-outlined text-sm">directions_walk</span>
</span>
                                    Walk-In Details
                                </h3>
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
<div class="md:col-span-2">
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
<div class="md:col-span-2">
<label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Current Time</label>
<div class="px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm font-bold text-royal-purple">
<?php echo date('g:i A'); ?> • <?php echo date('M d, Y'); ?>
</div>
<p class="text-xs text-slate-400 mt-2">Walk-in will be created for the current time</p>
</div>
<div class="md:col-span-2">
                            <label class="block text-xs font-bold text-slate-500 uppercase tracking-wide mb-2">Notes (Optional)</label>
                            <textarea class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:bg-white focus:border-royal-purple focus:ring-4 focus:ring-purple-500/10 transition-all outline-none resize-none" placeholder="Additional notes or special instructions..." name="notes" rows="3"><?php echo htmlspecialchars($form_data['notes'] ?? ''); ?></textarea>
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
</div>
</div>
</div>
<div class="xl:col-span-4 flex flex-col gap-6">
<div class="bg-orange-50 border border-orange-100 rounded-2xl p-6">
<div class="flex items-start gap-4">
<div class="w-10 h-10 rounded-full bg-orange-100 text-orange-600 flex items-center justify-center flex-shrink-0">
<span class="material-symbols-outlined">info</span>
</div>
<div>
<h4 class="font-bold text-orange-900 text-lg">Walk-In Booking</h4>
<p class="text-sm text-orange-800/70 mt-1 leading-snug">This walk-in will be created immediately with "Booked" status. Appointments take priority over walk-ins.</p>
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
<span class="material-symbols-outlined">check_circle</span>
<span>Create Walk-In</span>
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

<script>
// Patient Selection Modal
let currentPage = 1;
let totalPages = 1;

window.openPatientModal = function() {
    const modal = document.getElementById('patientModal');
    if (modal) {
        modal.classList.remove('hidden');
        searchPatients();
    }
};

window.closePatientModal = function() {
    const modal = document.getElementById('patientModal');
    modal.classList.add('hidden');
}

window.searchPatients = function() {
    const searchInput = document.getElementById('modalPatientSearch');
    const filterSelect = document.getElementById('modalFilterType');
    const patientList = document.getElementById('patientModalList');
    const pagination = document.getElementById('patientModalPagination');

    const searchQuery = searchInput.value.trim();
    const filterType = filterSelect.value;

    currentPage = 1;

    patientList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block animate-spin">hourglass_empty</span><p class="text-sm font-medium">Loading patients...</p></div>';

    const url = `../api/search_patients_modal.php?search=${encodeURIComponent(searchQuery)}&filter=${encodeURIComponent(filterType)}&page=${currentPage}`;

    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.patients) {
                displayPatientResults(data.patients, data.pagination);
            } else {
                patientList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block">person_search</span><p class="text-sm font-medium">No patients found</p></div>';
                pagination.classList.add('hidden');
            }
        })
        .catch(error => {
            console.error('Error searching patients:', error);
            patientList.innerHTML = '<div class="text-center py-12 text-red-400"><span class="material-symbols-outlined text-4xl mb-2 block">error</span><p class="text-sm font-medium">Error loading patients. Please try again.</p></div>';
            pagination.classList.add('hidden');
        });
}

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

    currentPage = pagination.current_page;
    totalPages = pagination.total_pages;
    pageInfo.textContent = `Page ${currentPage} of ${totalPages} (${pagination.total_patients} total)`;

    prevBtn.disabled = currentPage <= 1;
    nextBtn.disabled = currentPage >= totalPages;

    if (totalPages > 1) {
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
    const phoneNumber = escapeHtml(patient.phone_number || 'N/A');
    const email = escapeHtml(patient.email || 'N/A');
    const ageInfo = patient.age ? `<span class="px-2 py-0.5 rounded text-[10px] font-bold ${patient.age < 18 ? 'bg-orange-100 text-orange-700' : 'bg-gray-100 text-gray-600'} ml-2">${patient.age} yo</span>` : '';

    return `
        <div class="px-4 py-3 hover:bg-purple-50 cursor-pointer border-b border-gray-100 last:border-b-0 transition-colors group" onclick="selectPatientFromModal(${patient.patient_id}, '${patient.first_name}', '${patient.last_name}', '${patientId}', '${phoneNumber}')">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-sm flex-shrink-0">${initials}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center">
                        <p class="font-bold text-sm text-[#151118] group-hover:text-royal-purple transition-colors truncate">${patientName}</p>
                        ${ageInfo}
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">${patientId} • ${phoneNumber}</p>
                    ${email !== 'N/A' ? `<p class="text-[10px] text-slate-400 truncate">${email}</p>` : ''}
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

window.selectPatientFromModal = function(patientId, firstName, lastName, patientIdDisplay, phoneNumber) {
    document.getElementById('selectedPatientId').value = patientId;
    document.getElementById('selectedPatientInitials').textContent = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();
    document.getElementById('selectedPatientName').textContent = firstName + ' ' + lastName;
    document.getElementById('selectedPatientInfo').textContent = patientIdDisplay + ' • ' + phoneNumber;
    document.getElementById('selectPatientButtonText').textContent = firstName + ' ' + lastName;
    document.getElementById('selectedPatientDisplay').classList.remove('hidden');
    closePatientModal();
};

window.clearSelectedPatient = function() {
    document.getElementById('selectedPatientDisplay').classList.add('hidden');
    document.getElementById('selectedPatientId').value = '';
    document.getElementById('selectedPatientInitials').textContent = '--';
    document.getElementById('selectedPatientName').textContent = '--';
    document.getElementById('selectedPatientInfo').textContent = '--';
    document.getElementById('selectPatientButtonText').textContent = 'Select Patient...';
}

window.loadPreviousPage = function() {
    if (currentPage > 1) {
        currentPage--;
        searchPatients();
    }
};

window.loadNextPage = function() {
    if (currentPage < totalPages) {
        currentPage++;
        searchPatients();
    }
};

document.getElementById('patientModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closePatientModal();
    }
});

// Doctor Schedule Widget
const doctorScheduleContent = document.getElementById('doctorScheduleContent');
const doctorSelect = document.getElementById('doctor_id');
const doctorScheduleWidget = document.getElementById('doctorScheduleWidget');

if (doctorSelect) {
    doctorSelect.addEventListener('change', function() {
        const doctorId = this.value;
        if (doctorId) {
            loadDoctorSchedule(doctorId);
        } else {
            doctorScheduleWidget.classList.add('hidden');
        }
    });
}

function loadDoctorSchedule(doctorId) {
    // Show loading
    doctorScheduleContent.innerHTML = '<div class="text-center py-4 text-slate-400"><span class="material-symbols-outlined text-2xl mb-2 block animate-spin">hourglass_empty</span><p class="text-xs">Loading schedule...</p></div>';
    doctorScheduleWidget.classList.remove('hidden');

    // Fetch today's schedule
    const date = new Date().toISOString().split('T')[0];
    fetch(`../api/get_doctor_schedule.php?doctor_id=${doctorId}&date=${date}`)
        .then(response => response.json())
        .then(data => {
            const days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            const dayOfWeek = days[new Date().getDay()];

            if (data.success && data.schedule) {
                const startTime = new Date('2000-01-01 ' + data.schedule.start_time);
                const endTime = new Date('2000-01-01 ' + data.schedule.end_time);
                const startFormatted = startTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
                const endFormatted = endTime.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });

                doctorScheduleContent.innerHTML = `
                    <div class="p-4 bg-purple-50 border border-purple-100 rounded-xl">
                        <div class="flex items-center justify-between mb-2">
                            <span class="font-bold text-royal-purple text-sm">${dayOfWeek}</span>
                            <span class="px-2 py-1 bg-green-100 text-green-700 rounded-lg text-xs font-bold">Available</span>
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <span class="material-symbols-outlined text-slate-400 text-sm">schedule</span>
                            <span class="text-sm text-slate-600 font-medium">${startFormatted} - ${endFormatted}</span>
                        </div>
                    </div>
                `;
            } else {
                doctorScheduleContent.innerHTML = `
                    <div class="p-4 bg-red-50 border border-red-100 rounded-xl">
                        <div class="flex items-center gap-2">
                            <span class="material-symbols-outlined text-red-600 text-sm">event_busy</span>
                            <span class="text-sm text-red-700 font-medium">Not available today</span>
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

// Service Selection
const installmentRules = <?php echo json_encode($installment_rules ?? []); ?>;
let selectedServices = [];
<?php if (!empty($service_id_array)): ?>
    <?php foreach ($service_id_array as $sid): ?>
        <?php
            foreach ($services as $s) {
                if ($s['service_id'] == $sid) {
                    $s_name = addslashes($s['service_name']);
                    // Note: Default to installment=true if available
                    $isInstallment = $s['is_installment_available'] == 1 ? 'true' : 'false';
                    echo "selectedServices.push({id: {$s['service_id']}, name: '{$s_name}', duration: {$s['estimated_duration_mins']}, price: {$s['base_price']}, installment: {$s['is_installment_available']} == 1, minDpPercent: {$s['installment_down_payment']}, months: {$s['installment_duration_months']}, interest: {$s['installment_interest_percent']}, useInstallment: {$isInstallment}});\n";
                    break;
                }
            }
        ?>
    <?php endforeach; ?>
    document.addEventListener('DOMContentLoaded', renderServices);
<?php endif; ?>

async function addService() {
    const dropdown = document.getElementById('service_dropdown');
    const selectedOption = dropdown.options[dropdown.selectedIndex];
    
    if (!selectedOption.value) return;
    
    const serviceId = parseInt(selectedOption.value);
    const serviceName = selectedOption.getAttribute('data-name');
    const serviceDuration = parseInt(selectedOption.getAttribute('data-duration'));
    const servicePrice = parseFloat(selectedOption.getAttribute('data-price'));
    const serviceCategory = selectedOption.dataset.category || 'General Dentistry';
    const serviceMaxMonths = parseInt(selectedOption.dataset.maxMonths || 0);
    const serviceMinDp = parseFloat(selectedOption.dataset.minDp || 0);
    const serviceInterestPercent = parseFloat(selectedOption.dataset.interestPercent || 0);
    const serviceIsInstallment = selectedOption.dataset.isInstallment === '1';
    const requiresCheckup = selectedOption.dataset.requiresCheckup === '1';
    const requiresXray = selectedOption.dataset.requiresXray === '1';

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
        category: serviceCategory,
        maxMonths: serviceMaxMonths,
        minDp: serviceMinDp,
        interestPercent: serviceInterestPercent,
        monthlyPayment: parseFloat(selectedOption.dataset.monthlyPayment || 0),
        isInstallment: serviceIsInstallment,
        requiresCheckup: requiresCheckup,
        requiresXray: requiresXray,
        useInstallment: serviceIsInstallment // Default to true if available
    });
    
    renderServices();
    dropdown.selectedIndex = 0;
    
    // Update Customize Plan and Payment Preview
    renderCustomizePlan();
    updatePaymentOptions();
}

function removeService(serviceId) {
    selectedServices = selectedServices.filter(s => s.id !== serviceId);
    renderServices();
    
    // Update Customize Plan and Payment Preview
    renderCustomizePlan();
    updatePaymentOptions();
}

function renderServices() {
    const container = document.getElementById('selectedServicesList');
    const serviceIdsInput = document.getElementById('service_ids');
    
    if (selectedServices.length === 0) {
        container.innerHTML = '';
        serviceIdsInput.value = '';
        return;
    }
    
    container.innerHTML = selectedServices.map(service => {
        let badges = '';
        if (service.requiresCheckup) {
            badges += `<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-800 mr-1">Check-up Required</span>`;
        }
        if (service.requiresXray) {
            badges += `<span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-800 mr-1">X-ray Required</span>`;
        }

        return `
        <div class="flex items-center justify-between p-3 bg-purple-50 border border-purple-100 rounded-xl">
            <div class="flex-1">
                <p class="font-bold text-sm text-royal-purple mb-1">${escapeHtml(service.name)}</p>
                <div class="flex flex-wrap gap-1 mb-1">
                    ${badges}
                </div>
                <p class="text-xs text-slate-500">Duration: ${service.duration} min • Price: ₱${service.price.toFixed(2)}</p>
            </div>
            <button type="button" onclick="removeService(${service.id})" class="text-slate-400 hover:text-red-500 transition-colors">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        </div>
    `;
    }).join('');
    
    serviceIdsInput.value = selectedServices.map(s => s.id).join(',');
}

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
    let maxMonths = 0;
    let hasInstallmentService = false;
    let estTotalInterest = 0;
    let totalMonthlyPayment = 0;

    // Calculate Totals
    selectedServices.forEach(s => {
        totalAmount += s.price;
        
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
             // Non-installment services: Full price is required initially
             minDpForInstallments += s.price; 
        }
    });

    // Update UI Elements
    // Update UI Elements
    document.getElementById('estTotalAmount').textContent = '₱' + totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    const badge = document.getElementById('estInstallmentBadge');
    if (hasInstallmentService) {
        badge.textContent = 'Yes';
        badge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-green-100 text-green-700';
        badge.innerHTML = '<span class="material-symbols-outlined text-[16px]">check_circle</span> Yes';
        
        document.getElementById('estMinDP').textContent = '₱' + minDpForInstallments.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('estMonthlyVal').textContent = '₱' + totalMonthlyPayment.toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('estMaxMonths').textContent = maxMonths + ' Months';
    } else {
        badge.textContent = 'No';
        badge.className = 'inline-flex items-center gap-1 px-2.5 py-1 rounded-lg text-xs font-bold bg-gray-200 text-gray-600';
        badge.innerHTML = 'No';
        
        document.getElementById('estMinDP').textContent = '₱' + totalAmount.toLocaleString(undefined, {minimumFractionDigits: 2}); // Full Amount
        document.getElementById('estMonthlyVal').textContent = '₱0.00';
        document.getElementById('estMaxMonths').textContent = '0 Months';
    }
}

// Success Modal Logic
// Success Modal Logic Removed as per request (Direct redirect to booking)

// Custom Popup Functions
function showPopup(title, message, iconConfig, buttons) {
    const popup = document.getElementById('customPopup');
    const content = document.getElementById('popupContent');
    const iconDiv = document.getElementById('popupIcon');
    const titleEl = document.getElementById('popupTitle');
    const msgEl = document.getElementById('popupMessage');
    const btnsEl = document.getElementById('popupButtons');

    // Reset classes
    popup.classList.remove('hidden');
    setTimeout(() => {
        content.classList.remove('scale-95', 'opacity-0');
        content.classList.add('scale-100', 'opacity-100');
    }, 10);

    // Set Content
    titleEl.textContent = title;
    msgEl.textContent = message;

    // Set Icon
    iconDiv.className = `w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ${iconConfig.bg}`;
    iconDiv.innerHTML = `<span class="material-symbols-outlined text-2xl ${iconConfig.text}">${iconConfig.icon}</span>`;

    // Set Buttons
    btnsEl.innerHTML = buttons;
}

function closePopup() {
    const popup = document.getElementById('customPopup');
    const content = document.getElementById('popupContent');
    
    content.classList.remove('scale-100', 'opacity-100');
    content.classList.add('scale-95', 'opacity-0');
    
    setTimeout(() => {
        popup.classList.add('hidden');
    }, 300);
}

function showConfirm(message, title = "Confirm Action") {
    return new Promise((resolve) => {
        const buttons = `
            <button onclick="window.popupResolve(false)" class="px-5 py-2.5 rounded-xl border border-gray-200 text-slate-600 font-bold hover:bg-gray-50 transition-colors">Back to List</button>
            <button onclick="window.popupResolve(true)" class="px-5 py-2.5 rounded-xl bg-royal-purple text-white font-bold hover:bg-purple-800 shadow-lg shadow-purple-200 transition-all flex items-center gap-2">
                <span class="material-symbols-outlined">payments</span> Proceed to Payment
            </button>
        `;
        
        window.popupResolve = (result) => {
            closePopup();
            resolve(result);
        };

        showPopup(title, message, { icon: 'check_circle', bg: 'bg-green-100', text: 'text-green-600' }, buttons);
    });
}

function showError(message, title = "Error") {
    const buttons = `
        <button onclick="closePopup()" class="px-5 py-2.5 rounded-xl bg-gray-100 text-slate-700 font-bold hover:bg-gray-200 transition-colors">OK</button>
    `;
    showPopup(title, message, { icon: 'error', bg: 'bg-red-100', text: 'text-red-600' }, buttons);
}

// Doctor Schedule Widget Logic
// Doctor Schedule Widget Logic
// doctorScheduleWidget is already declared above
if (doctorSelect) {

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
}

// Patient Modal Logic
let currentPatientPage = 1;
let totalPatientPages = 1;

window.closePatientModal = function() {
    const modal = document.getElementById('patientModal');
    modal.classList.add('hidden');
    document.body.style.overflow = '';
}

window.openPatientModal = function() {
    const modal = document.getElementById('patientModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
        // Auto-load patients
        window.searchPatients();
    }
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
    
    // Selected Patient Display Elements
    const hiddenInput = document.querySelector('input[name="patient_id"]'); // This exists in PHP section
    const displayDiv = document.getElementById('selectedPatientDisplay');
    const placeholder = document.getElementById('patientSelectPlaceholder');
    const initialsEl = document.getElementById('selectedPatientInitials');
    const nameEl = document.getElementById('selectedPatientName');
    const infoEl = document.getElementById('selectedPatientInfo');
    const btnText = document.getElementById('selectPatientButtonText');
    
    if (hiddenInput) hiddenInput.value = patientId;
    if (initialsEl) initialsEl.textContent = initials;
    if (nameEl) nameEl.textContent = firstName + ' ' + lastName;
    if (btnText) btnText.textContent = firstName + ' ' + lastName;
    
    const infoParts = [patientIdDisplay];
    if (phoneNumber) infoParts.push(phoneNumber);
    if (email) infoParts.push(email);
    if (infoEl) infoEl.textContent = infoParts.join(' • ');
    
    if (placeholder) placeholder.classList.add('hidden');
    if (displayDiv) displayDiv.classList.remove('hidden');
    
    closePatientModal();
};

window.clearSelectedPatient = function() {
    const hiddenInput = document.querySelector('input[name="patient_id"]');
    const displayDiv = document.getElementById('selectedPatientDisplay');
    const placeholder = document.getElementById('patientSelectPlaceholder');
    const initialsEl = document.getElementById('selectedPatientInitials');
    const nameEl = document.getElementById('selectedPatientName');
    const infoEl = document.getElementById('selectedPatientInfo');
    const btnText = document.getElementById('selectPatientButtonText');

    if (displayDiv) displayDiv.classList.add('hidden');
    if (placeholder) placeholder.classList.remove('hidden');
    if (hiddenInput) hiddenInput.value = '';
    if (initialsEl) initialsEl.textContent = '--';
    if (nameEl) nameEl.textContent = '--';
    if (infoEl) infoEl.textContent = '--';
    if (btnText) btnText.textContent = 'Select Patient...';
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
                    <p class="text-xs text-slate-500 mt-0.5 flex items-center gap-1">
                        <span class="font-medium text-slate-600">${patientId}</span>
                        ${phoneDisplay}
                    </p>
                </div>
                <span class="material-symbols-outlined text-slate-300 group-hover:text-royal-purple transition-colors">chevron_right</span>
            </div>
        </div>
    `;
}
</script>
<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
<!-- Conflict Resolution Modal -->
<div id="conflictModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-black/50 backdrop-blur-sm">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 transform transition-all scale-100">
        <div class="flex items-center gap-4 mb-4">
            <div class="w-12 h-12 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center">
                <span class="material-symbols-outlined text-2xl">schedule</span>
            </div>
            <div>
                <h3 class="text-xl font-bold text-gray-900">Time Slot Taken</h3>
                <p class="text-sm text-gray-500">Wait list opportunity available</p>
            </div>
        </div>
        
        <p class="text-gray-600 mb-6">
            The doctor has a booked appointment at this time. <br>
            Would the patient be willing to wait? The next available slot is at <span class="font-bold text-royal-purple" id="suggestedTimeDisplay"></span> (10 mins after current appointment).
        </p>
        
        <div class="flex gap-3 justify-end">
            <button type="button" onclick="closeConflictModal()" class="px-5 py-2.5 rounded-xl border border-gray-200 text-gray-700 font-bold hover:bg-gray-50 transition-colors">
                Cancel
            </button>
            <button type="button" onclick="acceptSuggestedTime()" class="px-5 py-2.5 rounded-xl bg-royal-purple text-white font-bold hover:bg-purple-800 shadow-lg shadow-purple-200 transition-all">
                Wait & Book at <span id="suggestedTimeBtn"></span>
            </button>
        </div>
    </div>
</div>

<script>
    function closeConflictModal() {
        document.getElementById('conflictModal').classList.add('hidden');
    }

    function acceptSuggestedTime() {
        // Update time input hidden in the JS or Form
        // Since the form uses PHP variables for value, we need to update the actual input if it exists, 
        // OR submit a hidden form, OR update the main form and resubmit.
        // The time input is likely 'walkin_time'.
        const newTime = "<?php echo $suggested_time ?? ''; ?>";
        if (!newTime) return;

        // Try to find the time input
        const timeInput = document.getElementById('time'); 
        if (timeInput) {
            timeInput.value = newTime;
            // Submit the form
            document.querySelector('form').submit();
        }
    }

    // Trigger modal if PHP detected conflict
    <?php if ($conflict_detected && $suggested_time): ?>
    document.addEventListener('DOMContentLoaded', function() {
        document.getElementById('suggestedTimeDisplay').textContent = "<?php echo date('g:i A', strtotime($suggested_time)); ?>";
        document.getElementById('suggestedTimeBtn').textContent = "<?php echo date('g:i A', strtotime($suggested_time)); ?>";
        document.getElementById('conflictModal').classList.remove('hidden');
    });
    <?php endif; ?>
</script>
<!-- Custom Popup System -->
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
</body></html>

