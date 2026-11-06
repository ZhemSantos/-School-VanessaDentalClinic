<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once '../includes/notification_helper.php';
require_once '../includes/auto_cancel.php';

// Configure secure session
configureSecureSession();

// Require Dentist role to access this page
requireRole('Dentist');

// Update current user's activity
updateUserActivity();

// Get logged-in user information
$doctor_id = $_SESSION['user_id'] ?? null;
$logged_in_user = [
    'full_name' => $_SESSION['full_name'] ?? 'Dr. User',
    'username' => $_SESSION['username'] ?? 'user'
];

// Extract first name for greeting
$first_name = explode(' ', $logged_in_user['full_name'])[0];

// Fetch today's appointments for this doctor
$today_appointments = [];
$today_count = 0;
$current_date = date('Y-m-d');
$current_time = date('H:i:s');

if ($doctor_id) {
    try {
        // Get count of today's appointments (excluding cancelled and no-show)
        $count_stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM tbl_appointment
            WHERE doctor_id = ? 
              AND appointment_date = ?
              AND status IN ('Pending', 'Booked', 'Arrived', 'In-Procedure', 'On-Hold', 'Completed')
        ");
        $count_stmt->execute([$doctor_id, $current_date]);
        $count_result = $count_stmt->fetch();
        $today_count = $count_result['total'] ?? 0;
        
        // Get today's appointments ordered by time (including completed for review)
        $appt_stmt = $pdo->prepare("
            SELECT a.*, 
                   p.patient_id, p.first_name, p.last_name, p.gender, p.dob, p.phone_number, p.email, p.medical_history, p.blood_type,
                   GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name
            FROM tbl_appointment a
            INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
            INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
            INNER JOIN tbl_service s ON tas.service_id = s.service_id
            WHERE a.doctor_id = ? 
              AND a.appointment_date = ?
              AND a.status IN ('Pending', 'Booked', 'Arrived', 'In-Procedure', 'On-Hold', 'Completed')
            GROUP BY a.appointment_id
            ORDER BY a.time_slot ASC
        ");
        $appt_stmt->execute([$doctor_id, $current_date]);
        $today_appointments = $appt_stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Error fetching today\'s appointments: ' . $e->getMessage());
    }
}

// Calculate Available Time for "Add Service" checks
$available_minutes = 480; // Default to 8 hours if no data
$next_appt_display = "End of Day";
$current_timestamp = time();

if ($doctor_id) {
    // 1. Find next appointment time
    $next_start_time = null;
    foreach ($today_appointments as $appt) {
        $appt_time = strtotime($appt['appointment_date'] . ' ' . $appt['time_slot']);
        // Only consider future appointments that aren't cancelled or completed
        // CRITICAL FIX: Exclude the CURRENTLY selected appointment (don't conflict with self)
        if ($appt_time > $current_timestamp && 
            !in_array($appt['status'], ['Cancelled', 'No-Show', 'Completed']) &&
            $appt['appointment_id'] != $selected_appointment_id) {
            
            $next_start_time = $appt_time;
            $next_appt_display = date('g:i A', $appt_time) . " (Next Patient)";
            break;
        }
    }

    // 2. If no next appointment, find shift end time
    if (!$next_start_time) {
        try {
            $day_of_week = date('l');
            $sched_stmt = $pdo->prepare("SELECT end_time FROM tbl_doctor_schedule WHERE doctor_id = ? AND day_of_week = ?");
            $sched_stmt->execute([$doctor_id, $day_of_week]);
            $schedule = $sched_stmt->fetch();
            
            if ($schedule) {
                $next_start_time = strtotime(date('Y-m-d') . ' ' . $schedule['end_time']);
                $next_appt_display = date('g:i A', $next_start_time) . " (End of Shift)";
            } else {
                // Fallback to clinic closing/generic 6 PM
                $next_start_time = strtotime(date('Y-m-d') . ' 18:00:00'); 
                $next_appt_display = "6:00 PM (Clinic Closing)";
            }
        } catch (Exception $e) {
             $next_start_time = strtotime('+4 hours'); // Fallback
        }
    }

    // 3. Calculate gap
    if ($next_start_time > $current_timestamp) {
        $available_minutes = floor(($next_start_time - $current_timestamp) / 60);
    } else {
        $available_minutes = 0; // Overtime or late
    }
}

// Get selected appointment (from GET parameter or first "In-Procedure" appointment)
$selected_appointment = null;
$selected_appointment_id = isset($_GET['appointment_id']) ? intval($_GET['appointment_id']) : null;

if ($selected_appointment_id && $doctor_id) {
    try {
        $select_stmt = $pdo->prepare("
            SELECT a.*, 
                   p.patient_id, p.first_name, p.last_name, p.gender, p.dob, p.phone_number, p.email, p.medical_history, p.blood_type,
                   GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                   mr.record_id, mr.diagnosis, mr.treatment_notes, mr.tooth_numbers
            FROM tbl_appointment a
            INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
            INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
            INNER JOIN tbl_service s ON tas.service_id = s.service_id
            LEFT JOIN tbl_medical_record mr ON mr.appointment_id = a.appointment_id
            WHERE a.appointment_id = ? AND a.doctor_id = ?
            GROUP BY a.appointment_id
            LIMIT 1
        ");
        $select_stmt->execute([$selected_appointment_id, $doctor_id]);
        $selected_appointment = $select_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching selected appointment: ' . $e->getMessage());
    }
} elseif (empty($selected_appointment) && !empty($today_appointments)) {
    // If no appointment selected, try to find first "In-Procedure" appointment, then first "Booked"
    // If no appointment selected, try to find first "In-Procedure" appointment, then "On-Hold", then first "Booked" or "Arrived"
    $priority_statuses = ['In-Procedure', 'On-Hold', 'Arrived', 'Booked', 'Pending'];
    foreach ($priority_statuses as $priority_status) {
        foreach ($today_appointments as $appt) {
            if ($appt['status'] === $priority_status) {
                $selected_appointment_id = $appt['appointment_id'];
                try {
                    $select_stmt = $pdo->prepare("
                        SELECT a.*, 
                               p.patient_id, p.first_name, p.last_name, p.gender, p.dob, p.phone_number, p.email, p.medical_history, p.blood_type,
                               GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
                               CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                               mr.record_id, mr.diagnosis, mr.treatment_notes, mr.tooth_numbers
                        FROM tbl_appointment a
                        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                        INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                        INNER JOIN tbl_service s ON tas.service_id = s.service_id
                        LEFT JOIN tbl_medical_record mr ON mr.appointment_id = a.appointment_id
                        WHERE a.appointment_id = ? AND a.doctor_id = ?
                        GROUP BY a.appointment_id
                        LIMIT 1
                    ");
                    $select_stmt->execute([$selected_appointment_id, $doctor_id]);
                    $selected_appointment = $select_stmt->fetch();
                } catch (PDOException $e) {
                    error_log('Error fetching selected appointment: ' . $e->getMessage());
                }
                break 2; // Break out of both loops
            }
        }
    }
}

// Use current_service_ids to track what's already added (for duplicate prevention)
$current_service_ids = [];

// Fetch individual services for the selected appointment (if any)
if ($selected_appointment) {
    try {
        $services_list_stmt = $pdo->prepare("
            SELECT tas.appointment_service_id, tas.status, s.service_id, s.service_name, s.estimated_duration_mins
            FROM tbl_appointment_service tas
            JOIN tbl_service s ON tas.service_id = s.service_id
            WHERE tas.appointment_id = ?
        ");
        $services_list_stmt->execute([$selected_appointment['appointment_id']]);
        $selected_appointment['services'] = $services_list_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Populate ID list
        foreach ($selected_appointment['services'] as $svc) {
            $current_service_ids[] = $svc['service_id'];
        }
    } catch (PDOException $e) {
        // Fallback: Try fetching without 'status' column (if migration pending)
        try {
             $services_list_stmt = $pdo->prepare("
                SELECT tas.appointment_service_id, 'Pending' as status, s.service_id, s.service_name, s.estimated_duration_mins
                FROM tbl_appointment_service tas
                JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE tas.appointment_id = ?
            ");
            $services_list_stmt->execute([$selected_appointment['appointment_id']]);
            $selected_appointment['services'] = $services_list_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Populate ID list fallback
            foreach ($selected_appointment['services'] as $svc) {
                $current_service_ids[] = $svc['service_id'];
            }
        } catch (PDOException $ex) {
             $selected_appointment['services'] = [];
             error_log('Error fetching services details: ' . $ex->getMessage());
        }
    }
}

// Check for success message from redirect
$start_message = '';
$start_type = '';
$save_message = '';
$save_type = '';

if (isset($_GET['started']) && $_GET['started'] == '1') {
    $start_message = 'Procedure started successfully!';
    $start_type = 'success';
}

if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    $save_message = 'Treatment notes saved and procedure completed successfully!';
    $save_type = 'success';
}

if (isset($_GET['removed']) && $_GET['removed'] == '1') {
    $save_message = 'Service removed successfully.';
    $save_type = 'success';
}

if (isset($_GET['duplicate']) && $_GET['duplicate'] == '1') {
    $save_message = 'Error: That service has already been added.';
    $save_type = 'error';
}

// Handle Remove Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_service'])) {
    $service_entry_id = intval($_POST['service_entry_id'] ?? 0);
    $appointment_id = intval($_POST['appointment_id'] ?? 0);

    if ($service_entry_id > 0 && $doctor_id) {
         try {
            // Verify ownership
            $verify_stmt = $pdo->prepare("
                SELECT tas.appointment_service_id 
                FROM tbl_appointment_service tas
                JOIN tbl_appointment a ON tas.appointment_id = a.appointment_id
                WHERE tas.appointment_service_id = ? AND a.doctor_id = ?
            ");
            $verify_stmt->execute([$service_entry_id, $doctor_id]);
            
            if ($verify_stmt->fetch()) {
                $del_stmt = $pdo->prepare("DELETE FROM tbl_appointment_service WHERE appointment_service_id = ?");
                $del_stmt->execute([$service_entry_id]);
                header("Location: doctor_dashboard.php?appointment_id=" . $appointment_id . "&removed=1");
                exit;
            }
         } catch (PDOException $e) {
             error_log('Error removing service: ' . $e->getMessage());
         }
    }
}



// Handle start procedure
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['start_procedure'])) {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    
    if ($appointment_id > 0 && $doctor_id) {
        try {
            // Verify appointment belongs to this doctor AND check status and payment
            $verify_stmt = $pdo->prepare("
                SELECT appointment_id, status, appointment_fee_paid, appointment_type
                FROM tbl_appointment 
                WHERE appointment_id = ? AND doctor_id = ?
            ");
            $verify_stmt->execute([$appointment_id, $doctor_id]);
            $appt = $verify_stmt->fetch();
            
            if ($appt) {
                // Check if appointment fee has been paid (skip for walk-ins)
                if ($appt['appointment_fee_paid'] <= 0 && $appt['appointment_type'] !== 'Walk-in') {
                    $start_message = 'Cannot start procedure: Appointment fee has not been paid yet.';
                    $start_type = 'error';
                } 
                // Check if status is valid for starting
                // Walk-ins can start with 'Arrived' status, others need 'Booked' or 'On-Hold'
                elseif ($appt['appointment_type'] === 'Walk-in' && !in_array($appt['status'], ['Booked', 'On-Hold', 'Arrived'])) {
                    $start_message = 'Cannot start procedure: Invalid status for walk-in. Current status: ' . $appt['status'];
                    $start_type = 'error';
                }
                elseif ($appt['appointment_type'] !== 'Walk-in' && !in_array($appt['status'], ['On-Hold', 'Arrived'])) {
                    $start_message = 'Cannot start procedure: Appointment status must be "Arrived". Current status: ' . $appt['status'];
                    $start_type = 'error';
                } 
                else {
                    // Update appointment status to "In-Procedure"
                    $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = 'In-Procedure' WHERE appointment_id = ? AND doctor_id = ?");
                    $update_stmt->execute([$appointment_id, $doctor_id]);
                    
                    $start_message = 'Procedure started successfully!';
                    $start_type = 'success';
                    
                    // Refresh page
                    header("Location: doctor_dashboard.php?appointment_id=" . $appointment_id . "&started=1");
                    exit;
                }
            } else {
                $start_message = 'Appointment not found or access denied.';
                $start_type = 'error';
            }
        } catch (PDOException $e) {
            error_log('Error starting procedure: ' . $e->getMessage());
            $start_message = 'Failed to start procedure.';
            $start_type = 'error';
        }
    } else {
        $start_message = 'Invalid appointment ID.';
        $start_type = 'error';
    }
}

// Handle pause procedure
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pause_procedure'])) {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    
    if ($appointment_id > 0 && $doctor_id) {
        try {
            // Verify appointment belongs to this doctor
            $verify_stmt = $pdo->prepare("SELECT appointment_id FROM tbl_appointment WHERE appointment_id = ? AND doctor_id = ?");
            $verify_stmt->execute([$appointment_id, $doctor_id]);
            
            if ($verify_stmt->fetch()) {
                // Update appointment status to "On-Hold"
                $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = 'On-Hold' WHERE appointment_id = ? AND doctor_id = ?");
                $update_stmt->execute([$appointment_id, $doctor_id]);
                
                $start_message = 'Procedure put on hold.';
                $start_type = 'warning';
                
                // Refresh page
                header("Location: doctor_dashboard.php?appointment_id=" . $appointment_id . "&paused=1");
                exit;
            }
        } catch (PDOException $e) {
            error_log('Error pausing procedure: ' . $e->getMessage());
            $start_message = 'Failed to pause procedure.';
            $start_type = 'error';
        }
    }
}

// Handle resume procedure (same as start but explicit for clarity)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resume_procedure'])) {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    
    if ($appointment_id > 0 && $doctor_id) {
        try {
            $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = 'In-Procedure' WHERE appointment_id = ? AND doctor_id = ?");
            $update_stmt->execute([$appointment_id, $doctor_id]);
            header("Location: doctor_dashboard.php?appointment_id=" . $appointment_id . "&resumed=1");
            exit;
        } catch (PDOException $e) {
            error_log('Error resuming procedure: ' . $e->getMessage());
        }
    }
}

// Handle Service Status Update (Toggle Complete/Pending)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_service_status'])) {
    $service_entry_id = intval($_POST['service_entry_id'] ?? 0);
    $new_status = $_POST['new_status'] === 'Completed' ? 'Completed' : 'Pending';
    $redirect_appt_id = intval($_POST['appointment_id'] ?? 0);

    if ($service_entry_id > 0 && $doctor_id) {
        try {
            // Verify doctor owns the appointment associated with this service
            $verify_stmt = $pdo->prepare("
                SELECT tas.appointment_service_id 
                FROM tbl_appointment_service tas
                JOIN tbl_appointment a ON tas.appointment_id = a.appointment_id
                WHERE tas.appointment_service_id = ? AND a.doctor_id = ?
            ");
            $verify_stmt->execute([$service_entry_id, $doctor_id]);
            
            if ($verify_stmt->fetch()) {
                $update_stmt = $pdo->prepare("UPDATE tbl_appointment_service SET status = ? WHERE appointment_service_id = ?");
                $update_stmt->execute([$new_status, $service_entry_id]);
                
                // Redirect to avoid resubmission
                header("Location: doctor_dashboard.php?appointment_id=" . $redirect_appt_id);
                exit;
            }
        } catch (PDOException $e) {
            error_log('Error updating service status: ' . $e->getMessage());
        }
    }
}

// Handle treatment notes save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_treatment_notes'])) {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatment_notes = trim($_POST['treatment_notes'] ?? '');
    $tooth_numbers = trim($_POST['tooth_numbers'] ?? '');
    $mark_followup = isset($_POST['mark_followup']) ? 1 : 0;
    
    if ($appointment_id > 0 && $doctor_id && !empty($diagnosis)) {
        try {
            $pdo->beginTransaction();
            
            // Get appointment details
            $appt_stmt = $pdo->prepare("
                SELECT a.*, p.patient_id, p.first_name, p.last_name,
                       (SELECT service_id FROM tbl_appointment_service WHERE appointment_id = a.appointment_id LIMIT 1) as service_id
                FROM tbl_appointment a
                INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                WHERE a.appointment_id = ? AND a.doctor_id = ?
            ");
            $appt_stmt->execute([$appointment_id, $doctor_id]);
            $appt = $appt_stmt->fetch();
            
            if ($appt) {
                // Check if medical record already exists
                $record_stmt = $pdo->prepare("
                    SELECT record_id FROM tbl_medical_record
                    WHERE appointment_id = ?
                    LIMIT 1
                ");
                $record_stmt->execute([$appointment_id]);
                $existing_record = $record_stmt->fetch();
                
                if ($existing_record) {
                    // Update existing record
                    $update_stmt = $pdo->prepare("
                        UPDATE tbl_medical_record
                        SET diagnosis = ?, treatment_notes = ?, tooth_numbers = ?
                        WHERE record_id = ?
                    ");
                    $update_stmt->execute([$diagnosis, $treatment_notes, $tooth_numbers, $existing_record['record_id']]);
                } else {
                    // Create new medical record - use PHP date to ensure timezone consistency
                    $current_datetime = date('Y-m-d H:i:s');
                    // We must provide a service_id (FK), so use the one fetched, or 0 if none (though query ensures one)
                    $service_id_to_store = $appt['service_id'] ?: 1; 

                    $insert_stmt = $pdo->prepare("
                        INSERT INTO tbl_medical_record (patient_id, doctor_id, service_id, appointment_id, date_of_treatment, diagnosis, treatment_notes, tooth_numbers)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert_stmt->execute([$appt['patient_id'], $doctor_id, $service_id_to_store, $appointment_id, $current_datetime, $diagnosis, $treatment_notes, $tooth_numbers]);
                }
                
                // Update appointment status to "Completed" - this completes the procedure
                $status_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = 'Completed' WHERE appointment_id = ?");
                $status_stmt->execute([$appointment_id]);
                
                // Create system notification for Receptionists (Payment ready)
                try {
                    $pat_name = $appt['first_name'] . ' ' . $appt['last_name'];
                    $notif_message = "Procedure completed for {$pat_name}. Ready for payment.";
                    $notif_link = "receptionist_payment.php?patient_id=" . $appt['patient_id'];
                    
                    // Broadcast to all receptionists (user_id = null)
                    createNotification($pdo, null, 'Receptionist', 'Payment', 'Procedure Completed', $notif_message, $notif_link);
                } catch (Exception $e) {
                    error_log("Error creating completion notification: " . $e->getMessage());
                }

                $pdo->commit();
                $save_message = 'Treatment notes saved and procedure completed successfully!';
                $save_type = 'success';
                
                // Refresh selected appointment
                header("Location: doctor_dashboard.php?appointment_id=" . $appointment_id . "&saved=1");
                exit;
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error saving treatment notes: ' . $e->getMessage());
            $save_message = 'Failed to save treatment notes.';
            $save_type = 'error';
        }
    } else {
        $save_message = 'Please fill in all required fields.';
        $save_type = 'error';
    }
}

// Calculate patient age
function calculateAge($dob) {
    if (!$dob) return null;
    $birth = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

// Fetch all services for the "Add Service" dropdown
$all_services_list = [];
try {
    $services_stmt = $pdo->query("SELECT * FROM tbl_service ORDER BY service_name");
    $all_services_list = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Error fetching services: ' . $e->getMessage());
}

// Handle Add Additional Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_additional_service'])) {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $service_id = intval($_POST['service_id'] ?? 0);
    $consent_verified = isset($_POST['consent_verified']) ? 1 : 0;
    
    if ($appointment_id > 0 && $doctor_id && $service_id > 0 && $consent_verified) {
        try {
            $pdo->beginTransaction();
            
            // Verify appointment belongs to doctor
            $verify_stmt = $pdo->prepare("SELECT appointment_id FROM tbl_appointment WHERE appointment_id = ? AND doctor_id = ?");
            $verify_stmt->execute([$appointment_id, $doctor_id]);
            
            if ($verify_stmt->fetch()) {
                 // Check Duplicate
                $check = $pdo->prepare("SELECT COUNT(*) FROM tbl_appointment_service WHERE appointment_id = ? AND service_id = ?");
                $check->execute([$appointment_id, $service_id]);
                if ($check->fetchColumn() > 0) {
                     header("Location: doctor_dashboard.php?appointment_id=" . $appointment_id . "&duplicate=1");
                     exit;
                }

                // Insert new service
                $insert_service = $pdo->prepare("
                    INSERT INTO tbl_appointment_service (appointment_id, service_id, price_at_booking)
                    SELECT ?, ?, base_price FROM tbl_service WHERE service_id = ?
                ");
                $insert_service->execute([$appointment_id, $service_id, $service_id]);
                
                // Optional: Update invoice if exists (assuming simple logic for now, receptionist usually finalizes)
                // In this system, invoices might be generated later. If one exists, we should probably update it.
                // For now, let's just log it in the treatment notes automatically or system log.
                
                $pdo->commit();
                $save_message = 'Additional service added successfully!';
                $save_type = 'success';
                
                // Refresh
                header("Location: doctor_dashboard.php?appointment_id=" . $appointment_id . "&saved=1");
                exit;
            } else {
                throw new Exception("Appointment not found or access denied.");
            }
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Error adding service: ' . $e->getMessage());
            $save_message = 'Failed to add service: ' . $e->getMessage();
            $save_type = 'error';
        }
    } else {
        $save_message = 'Please select a service and verify patient consent.';
        $save_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Dentist Dashboard - Vanessa Nicolas Dental Clinic</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4", // Royal Purple
                        "secondary": "#FFD700", // Gold
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
<style>.clinical-scroll::-webkit-scrollbar {
            width: 8px;
        }
        .clinical-scroll::-webkit-scrollbar-track {
            background: #f1f1f1; 
            border-radius: 4px;
        }
        .clinical-scroll::-webkit-scrollbar-thumb {
            background: #d1d5db; 
            border-radius: 4px;
        }
        .clinical-scroll::-webkit-scrollbar-thumb:hover {
            background: #9ca3af; 
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-[#151118] overflow-hidden">
<div class="flex h-screen w-full overflow-hidden">
<aside class="flex w-72 flex-col border-r border-[#e2dbe6] bg-white h-full hidden lg:flex flex-shrink-0 z-20 shadow-sm">
<div class="flex flex-col h-full justify-between p-6">
<div class="flex flex-col gap-8">
<div class="flex gap-3 items-center">
<div class="bg-center bg-no-repeat bg-cover rounded-xl size-12 shrink-0 shadow-md border-2 border-primary/10" data-alt="Logo showing a stylized tooth with a purple gradient background" style='background-image: url("https://lh3.googleusercontent.com/aida-public/AB6AXuDVLK7T7qRCxOBG-KaXUiV7VW5S5DR2zmQCVv6YRrNOrUS38YGhDstNQjB3IS0WM1pZK9Wo4EHloZ0lx0FQGmj-rZI4gSVFeJTXSjATIJ_tYqqzdo5pdNJ1pksXN6aRO3aeGfXmoEGKDAHJBMYm7CCJnmtPX1pi-uNNh_4RwjGbI6iAxHe6j1e3lNXsLFupAxH4fZU835iEQPEfoQiIO8RNnlF9NY6cD7wJbJ6d_j2M9IrAx20oMjkbfg8QwQadAORe5tubT-S5tKg");'></div>
<div class="flex flex-col">
<h1 class="text-[#151118] text-lg font-bold leading-tight">Vanessa Nicolas</h1>
<p class="text-[#7a6189] text-xs font-medium uppercase tracking-wider">Dental Clinic</p>
</div>
</div>
<nav class="flex flex-col gap-2">
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 border-l-4 border-primary shadow-sm group transition-all" href="doctor_dashboard.php">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform">dashboard</span>
<p class="text-primary text-sm font-bold leading-normal">Dashboard</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_patients.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">groups</span>
<p class="text-sm font-medium leading-normal">My Patients</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_records.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">folder_shared</span>
<p class="text-sm font-medium leading-normal">Medical Records</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_schedule.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">calendar_month</span>
<p class="text-sm font-medium leading-normal">My Schedule</p>
</a>
</nav>
</div>
<div class="flex flex-col gap-4">
<div class="p-4 bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl border border-primary/10">
<div class="flex items-center gap-3 mb-2">
<span class="material-symbols-outlined text-secondary text-2xl">workspace_premium</span>
<p class="text-xs font-bold text-primary uppercase">Staff Portal</p>
</div>
<p class="text-xs text-[#7a6189]">Logged in securely. Session active.</p>
</div>
<a href="../logout.php" class="flex items-center gap-3 px-4 py-2 text-[#7a6189] hover:text-red-500 transition-colors">
<span class="material-symbols-outlined">logout</span>
<span class="text-sm font-medium">Log Out</span>
</a>
</div>
</div>
</aside>
<main class="flex-1 flex flex-col h-full relative overflow-hidden">
<header class="h-16 flex items-center justify-between px-8 bg-white border-b border-[#f3f0f4] shrink-0 z-10">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-[#7a6189]">menu_open</span>
<span class="text-[#e2dbe6] mx-2">|</span>
<h2 class="text-[#151118] text-base font-bold">Clinical Dashboard</h2>
</div>
<div class="flex items-center gap-6">
<button class="btn-notifications relative p-2 text-[#7a6189] hover:bg-[#f3f0f4] rounded-full transition-colors">
<span class="material-symbols-outlined">notifications</span>
<span class="absolute top-2 right-2 size-2 bg-red-500 rounded-full border border-white"></span>
</button>
<a href="doctor_current_user.php" class="flex items-center gap-3 pl-6 border-l border-[#f3f0f4] cursor-pointer hover:opacity-80 transition-opacity">
<div class="flex flex-col items-end">
<p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($logged_in_user['full_name']); ?></p>
<p class="text-xs text-[#7a6189]">Dentist</p>
</div>
<?php
// Fetch user profile picture from database
$current_profile = null;
if ($doctor_id) {
    try {
        $profile_stmt = $pdo->prepare("SELECT profile_picture FROM tbl_user WHERE user_id = ?");
        $profile_stmt->execute([$doctor_id]);
        $profile_result = $profile_stmt->fetch();
        $current_profile = $profile_result['profile_picture'] ?? null;
    } catch (PDOException $e) {
        error_log('Error fetching profile picture: ' . $e->getMessage());
    }
}
$current_name = $logged_in_user['full_name'];

function getDefaultProfilePhoto($full_name) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    $colors = [
        ['bg' => '#8a11d4', 'text' => '#ffffff'],
        ['bg' => '#6A1B9A', 'text' => '#ffffff'],
        ['bg' => '#FFD700', 'text' => '#151118'],
        ['bg' => '#10b981', 'text' => '#ffffff'],
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    return ['initials' => $initials, 'bg_color' => $color['bg'], 'text_color' => $color['text']];
}

if ($current_profile && file_exists('../' . $current_profile)) {
    echo '<div class="bg-center bg-no-repeat bg-cover rounded-full size-10 ring-2 ring-primary/20" style="background-image: url(\'../' . htmlspecialchars($current_profile) . '\');"></div>';
} else {
    $default_photo = getDefaultProfilePhoto($current_name);
    echo '<div class="rounded-full size-10 ring-2 ring-primary/20 flex items-center justify-center text-white font-bold" style="background-color: ' . $default_photo['bg_color'] . '; color: ' . $default_photo['text_color'] . ';">' . htmlspecialchars($default_photo['initials']) . '</div>';
}
?>
</a>
</div>
</header>
<div class="flex-1 overflow-y-auto bg-background-light p-6 lg:p-8">
<div class="max-w-[1600px] mx-auto flex flex-col gap-6">
    
    <!-- Holiday Banner -->
    <div id="holidayBanner" class="hidden">
        <div id="bannerContent" class="rounded-xl border p-4 flex items-start gap-4 shadow-sm bg-purple-50 border-purple-100">
            <div id="bannerIconContainer" class="p-2 rounded-full bg-purple-100 text-purple-700 flex-shrink-0 flex items-center justify-center">
                <span id="bannerIcon" class="material-symbols-outlined text-xl">event</span>
            </div>
            <div class="flex-1">
                <h4 class="font-bold text-sm mb-1 text-purple-900" id="bannerTitle">Clinic Holiday Notice</h4>
                <div id="holidayList" class="text-sm space-y-1 text-purple-800"></div>
            </div>
            <button onclick="document.getElementById('holidayBanner').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 transition-colors">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            fetch('../api/get_upcoming_holidays.php?t=' + Date.now())
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.holidays.length > 0) {
                        const list = document.getElementById('holidayList');
                        const banner = document.getElementById('holidayBanner');
                        const content = document.getElementById('bannerContent');
                        const iconContainer = document.getElementById('bannerIconContainer');
                        const icon = document.getElementById('bannerIcon');
                        const title = document.getElementById('bannerTitle');
                        
                        const now = new Date();
                        now.setHours(0,0,0,0);
                        let minDiff = 999;
                        const msPerDay = 1000 * 60 * 60 * 24;
                        const visibleHolidays = [];

                        data.holidays.forEach(h => {
                            const dateParts = h.block_date.split('-');
                            const hDate = new Date(dateParts[0], dateParts[1]-1, dateParts[2]);
                            const diffTime = hDate - now;
                            const diffDays = Math.ceil(diffTime / msPerDay);
                            
                            if (diffDays >= -1) {
                                if (diffDays < minDiff) minDiff = diffDays;
                                visibleHolidays.push({...h, diffDays});
                            }
                        });
                        
                        if (visibleHolidays.length > 0) {
                            // Color Logic
                            let theme = {
                                bg: 'bg-purple-50', border: 'border-purple-100', 
                                text: 'text-purple-800', title: 'text-purple-900',
                                iconBg: 'bg-purple-100', iconText: 'text-purple-700', icon: 'event'
                            };

                            if (minDiff <= 2) { // Red
                                theme = {
                                    bg: 'bg-red-50', border: 'border-red-100', 
                                    text: 'text-red-800', title: 'text-red-900',
                                    iconBg: 'bg-red-100', iconText: 'text-red-700', icon: 'warning'
                                };
                            } else if (minDiff <= 5) { // Orange
                                theme = {
                                    bg: 'bg-orange-50', border: 'border-orange-100', 
                                    text: 'text-orange-800', title: 'text-orange-900',
                                    iconBg: 'bg-orange-100', iconText: 'text-orange-700', icon: 'priority_high'
                                };
                            }

                            // Apply Theme
                            content.className = `rounded-xl border p-4 flex items-start gap-4 shadow-sm ${theme.bg} ${theme.border}`;
                            iconContainer.className = `p-2 rounded-full ${theme.iconBg} ${theme.iconText} flex-shrink-0 flex items-center justify-center`;
                            icon.textContent = theme.icon;
                            title.className = `font-bold text-sm mb-1 ${theme.title}`;
                            list.className = `text-sm space-y-1 ${theme.text}`;

                            // Render Items
                            visibleHolidays.forEach(h => {
                                const div = document.createElement('div');
                                div.innerHTML = `<span class="font-bold">${h.formatted_date}:</span> ${h.reason} <span class="bg-white/50 px-1.5 py-0.5 rounded text-xs font-bold ml-1">${h.display_time}</span>`;
                                list.appendChild(div);
                            });
                            
                            banner.classList.remove('hidden');
                        }
                    }
                })
                .catch(e => console.error(e));
        });
    </script>
<div class="flex flex-col lg:flex-row gap-6 justify-between items-end">
<div class="flex flex-col gap-1">
<h1 class="text-2xl md:text-3xl font-black text-[#151118] tracking-tight">Good Morning, <?php echo htmlspecialchars($first_name); ?></h1>
<p class="text-[#7a6189] text-base font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-sm">event_available</span>
                                You have <span class="font-bold text-primary"><?php echo $today_count; ?> appointment<?php echo $today_count != 1 ? 's' : ''; ?></span> today.
                            </p>
</div>
<div class="w-full lg:w-[480px]">
<div class="relative group">
<div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
<span class="material-symbols-outlined text-[#7a6189] group-focus-within:text-primary transition-colors">search</span>
</div>
<input class="block w-full pl-12 pr-4 py-3 bg-white border border-[#e2dbe6] rounded-xl text-sm placeholder-[#7a6189] focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all shadow-sm" placeholder="Search patient name, ID, or mobile number..." type="text"/>
<div class="absolute inset-y-0 right-0 pr-3 flex items-center">
<kbd class="hidden sm:inline-block px-2 py-1 bg-[#f3f0f4] border border-[#e2dbe6] rounded-lg text-xs font-semibold text-[#7a6189]">CMD+K</kbd>
</div>
</div>
</div>
</div>
<div class="grid grid-cols-12 gap-6 h-[calc(100vh-280px)] min-h-[600px]">
<div class="col-span-12 xl:col-span-4 flex flex-col gap-6 h-full">
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col h-full overflow-hidden">
<div class="p-4 border-b border-[#f3f0f4] flex justify-between items-center bg-white sticky top-0 z-10">
<h3 class="font-bold text-lg text-[#151118] flex items-center gap-2">
<span class="material-symbols-outlined text-primary">calendar_today</span>
                                        Today's Schedule
                                    </h3>
<span class="px-2.5 py-1 rounded-lg bg-primary/10 text-primary text-xs font-bold"><?php echo date('M j'); ?></span>
</div>
<div class="flex-1 overflow-y-auto p-2 space-y-2 clinical-scroll">
<?php if (empty($today_appointments)): ?>
<div class="p-6 text-center text-[#7a6189]">
<span class="material-symbols-outlined text-4xl mb-2 opacity-50">event_busy</span>
<p class="text-sm font-medium">No appointments scheduled for today</p>
</div>
<?php else: ?>
<?php foreach ($today_appointments as $index => $appt): 
    $time = new DateTime($appt['time_slot']);
    $time_formatted = $time->format('g:i');
    $time_period = $time->format('A');
    $is_selected = $selected_appointment && $selected_appointment['appointment_id'] == $appt['appointment_id'];
    $is_current = $appt['status'] === 'In-Procedure';
    $is_past = $appt['status'] === 'Completed' || ($appt['appointment_date'] == $current_date && $appt['time_slot'] < $current_time);
    
    $status_colors = [
        'Pending' => 'bg-gray-100 text-gray-500',
        'Booked' => 'bg-yellow-100 text-yellow-700',
        'Arrived' => 'bg-emerald-100 text-emerald-700',
        'In-Procedure' => 'bg-blue-100 text-blue-700',
        'On-Hold' => 'bg-orange-100 text-orange-700',
        'Completed' => 'bg-green-100 text-green-700',
        'Cancelled' => 'bg-red-100 text-red-700',
        'No-Show' => 'bg-gray-100 text-gray-500'
    ];
    $status_label = [
        'Pending' => 'PENDING',
        'Booked' => 'BOOKED',
        'Arrived' => 'ARRIVED',
        'In-Procedure' => 'IN CHAIR',
        'On-Hold' => 'ON HOLD',
        'Completed' => 'DONE',
        'Cancelled' => 'CANCELLED',
        'No-Show' => 'NO-SHOW'
    ];
    $status_class = $status_colors[$appt['status']] ?? 'bg-gray-100 text-gray-500';
    $status_text = $status_label[$appt['status']] ?? $appt['status'];
?>
<div class="p-3 rounded-xl <?php echo $is_selected ? 'bg-primary/5 border border-primary/20 shadow-sm' : ($is_current ? 'bg-primary/5 border border-primary/20' : 'hover:bg-[#f8f9fa] border border-transparent hover:border-[#f3f0f4]'); ?> group transition-all cursor-pointer <?php echo $is_past ? 'opacity-60' : ''; ?>" onclick="window.location.href='doctor_dashboard.php?appointment_id=<?php echo $appt['appointment_id']; ?>'">
<?php if ($is_selected || $is_current): ?>
<div class="absolute left-0 top-0 bottom-0 w-1 bg-primary"></div>
<?php endif; ?>
<div class="flex items-start gap-3">
<div class="flex flex-col items-center min-w-[60px] pt-1">
<span class="text-xs <?php echo $is_selected || $is_current ? 'font-bold text-primary' : 'font-medium text-[#151118]'; ?>"><?php echo $time_formatted; ?></span>
<span class="text-xs <?php echo $is_selected || $is_current ? 'font-bold text-primary' : 'font-bold text-[#7a6189]'; ?>"><?php echo $time_period; ?></span>
<?php if ($index < count($today_appointments) - 1): ?>
<div class="h-full w-px <?php echo $is_selected || $is_current ? 'bg-primary/20' : 'bg-[#e2dbe6]'; ?> mt-2"></div>
<?php endif; ?>
</div>
<div class="flex-1">
<div class="flex justify-between items-start">
<h4 class="font-bold text-[#151118] <?php echo $is_past ? 'line-through decoration-[#7a6189]' : ''; ?>"><?php echo htmlspecialchars($appt['patient_name']); ?></h4>
<span class="text-[10px] font-bold px-2 py-0.5 rounded-full <?php echo $status_class; ?> <?php echo $is_current ? 'animate-pulse' : ''; ?>"><?php echo $status_text; ?></span>
</div>
<p class="text-xs <?php echo $is_selected || $is_current ? 'text-[#151118] font-medium' : 'text-[#7a6189]'; ?> mt-1"><?php echo htmlspecialchars($appt['service_name']); ?></p>
</div>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</div>
<div class="col-span-12 xl:col-span-8 flex flex-col gap-4 h-full">
<?php if ($selected_appointment): 
    $patient_age = calculateAge($selected_appointment['dob']);
    $time_obj = new DateTime($selected_appointment['time_slot']);
    $time_display = $time_obj->format('g:i A');
    
    // Get last visit date
    $last_visit = null;
    try {
        $current_datetime = date('Y-m-d H:i:s');
        $last_visit_stmt = $pdo->prepare("
            SELECT MAX(date_of_treatment) as last_visit
            FROM tbl_medical_record
            WHERE patient_id = ? AND date_of_treatment < ?
        ");
        $last_visit_stmt->execute([$selected_appointment['patient_id'], $current_datetime]);
        $last_visit_result = $last_visit_stmt->fetch();
        if ($last_visit_result && $last_visit_result['last_visit']) {
            $last_visit = date('M d, Y', strtotime($last_visit_result['last_visit']));
        }
    } catch (PDOException $e) {
        error_log('Error fetching last visit: ' . $e->getMessage());
    }
?>
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col relative overflow-hidden shrink-0">
<div class="absolute top-0 left-0 w-1.5 h-full bg-primary"></div>
<div class="p-5 flex flex-wrap justify-between items-center gap-4">
<div class="flex items-center gap-4">
<div class="bg-center bg-no-repeat bg-cover rounded-full size-12 ring-2 ring-[#e2dbe6] bg-gray-200 flex items-center justify-center text-primary font-bold">
<?php 
$initials = strtoupper(substr($selected_appointment['first_name'], 0, 1) . substr($selected_appointment['last_name'], 0, 1));
echo htmlspecialchars($initials);
?>
</div>
<div>
<h2 class="text-xl font-black text-[#151118] flex items-center gap-2">
                                                <?php echo htmlspecialchars($selected_appointment['patient_name']); ?> 
                                                <span class="text-sm font-normal text-[#7a6189]">(<?php echo htmlspecialchars($selected_appointment['gender'] ?? 'N/A'); ?><?php echo $patient_age ? ', ' . $patient_age : ''; ?>)</span>
</h2>
<div class="flex items-center gap-3 mt-1">
<p class="text-xs text-[#7a6189]">ID: <span class="font-mono text-[#151118]">#P-<?php echo str_pad($selected_appointment['patient_id'], 5, '0', STR_PAD_LEFT); ?></span></p>
<?php if ($last_visit): ?>
<span class="w-1 h-1 rounded-full bg-[#e2dbe6]"></span>
<p class="text-xs text-[#7a6189]">Last Visit: <span class="text-[#151118]"><?php echo $last_visit; ?></span></p>
<?php endif; ?>
</div>
</div>
</div>
<div class="flex items-center gap-3 flex-wrap">
<?php if (!empty($selected_appointment['medical_history'])): 
    // Check for allergies in medical history
    $medical_history_lower = strtolower($selected_appointment['medical_history']);
    if (stripos($medical_history_lower, 'allergy') !== false || stripos($medical_history_lower, 'allergic') !== false):
?>
<div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-red-50 border border-red-100">
<span class="material-symbols-outlined text-red-500 text-lg">warning</span>
<span class="text-xs font-bold text-red-600">See Medical History</span>
</div>
                    <?php endif; endif; ?>
                    <?php
                    // Walk-in appointments can start without payment
                    if ($selected_appointment['appointment_type'] === 'Walk-in' &&
                        $selected_appointment['status'] !== 'In-Procedure' &&
                        $selected_appointment['status'] !== 'Completed' &&
                        $selected_appointment['status'] !== 'On-Hold' &&
                        in_array($selected_appointment['status'], ['Pending', 'Booked', 'Arrived'])):
                    ?>
                    <form method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>" class="inline">
                        <input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
                        <button type="submit" name="start_procedure" class="flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-xl text-sm font-bold shadow-md shadow-primary/20 transition-all">
                            <span class="material-symbols-outlined text-lg">play_arrow</span>
                            Start Procedure
                        </button>
                    </form>
                    <?php
                    // Only show Start Procedure button if appointment is Booked (fee paid) and not in other states
                    elseif ($selected_appointment['status'] !== 'In-Procedure' &&
                        $selected_appointment['status'] !== 'Completed' &&
                        $selected_appointment['status'] !== 'On-Hold' &&
                        $selected_appointment['status'] !== 'On-Hold' &&
                        in_array($selected_appointment['status'], ['Arrived']) &&
                        $selected_appointment['appointment_fee_paid'] > 0):
                    ?>
                    <form method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>" class="inline">
                        <input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
                        <button type="submit" name="start_procedure" class="flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-xl text-sm font-bold shadow-md shadow-primary/20 transition-all">
                            <span class="material-symbols-outlined text-lg">play_arrow</span>
                            Start Procedure
                        </button>
                    </form>
                    <?php elseif (($selected_appointment['status'] === 'Pending' || $selected_appointment['appointment_fee_paid'] <= 0) && $selected_appointment['appointment_type'] !== 'Walk-in'): ?>
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-yellow-50 border border-yellow-100">
                        <span class="material-symbols-outlined text-yellow-600 text-lg">payments</span>
                        <span class="text-xs font-bold text-yellow-700">Waiting for Payment</span>
                    </div>
                    <?php elseif ($selected_appointment['status'] === 'Booked' && $selected_appointment['appointment_type'] !== 'Walk-in'): ?>
                    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-purple-50 border border-purple-100">
                        <span class="material-symbols-outlined text-purple-600 text-lg">event_available</span>
                        <span class="text-xs font-bold text-purple-700">Waiting for Arrival</span>
                    </div>
                    <?php elseif ($selected_appointment['status'] === 'On-Hold'): ?>
<div class="flex items-center gap-3">
    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-orange-50 border border-orange-100">
        <span class="material-symbols-outlined text-orange-500 text-lg">pause_circle</span>
        <span class="text-xs font-bold text-orange-600">Procedure On-Hold</span>
    </div>
    <form method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>" class="inline">
        <input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
        <button type="submit" name="resume_procedure" class="flex items-center gap-2 px-4 py-2 bg-primary hover:bg-primary/90 text-white rounded-xl text-sm font-bold shadow-md shadow-primary/20 transition-all">
            <span class="material-symbols-outlined text-lg">play_arrow</span>
            Resume
        </button>
    </form>
</div>
<?php elseif ($selected_appointment['status'] === 'In-Procedure'): ?>
<div class="flex items-center gap-3">
    <div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-blue-50 border border-blue-100">
        <span class="material-symbols-outlined text-blue-500 text-lg">medical_services</span>
        <span class="text-xs font-bold text-blue-600">Procedure In Progress</span>
    </div>
    <form method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>" class="inline">
        <input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
        <button type="submit" name="pause_procedure" class="flex items-center gap-2 px-4 py-2 bg-orange-500 hover:bg-orange-600 text-white rounded-xl text-sm font-bold shadow-md shadow-orange-500/20 transition-all">
            <span class="material-symbols-outlined text-lg">pause</span>
            Stop Procedure
        </button>
    </form>
</div>
<?php elseif ($selected_appointment['status'] === 'Completed'): ?>
<div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-green-50 border border-green-100">
<span class="material-symbols-outlined text-green-500 text-lg">check_circle</span>
<span class="text-xs font-bold text-green-600">Procedure Completed</span>
</div>
<?php endif; ?>
</div>
</div>
<div class="px-5 py-3 bg-[#fcfcfd] border-t border-[#f3f0f4] flex items-center justify-between">
<div class="flex items-center gap-3">
<div class="size-8 rounded-lg bg-primary/10 flex items-center justify-center text-primary">
<span class="material-symbols-outlined text-lg">medical_services</span>
</div>
<div>
<p class="text-[10px] font-bold text-[#7a6189] uppercase tracking-wider mb-2">Current Services</p>
    <div class="flex flex-col gap-2">
    <?php 
    if (!empty($selected_appointment['services'])):
        foreach ($selected_appointment['services'] as $svc):
            $is_completed = ($svc['status'] ?? 'Pending') === 'Completed';
    ?>
    <div class="flex items-center gap-2 group">
        <!-- Status Toggle Form -->
        <form method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>" class="flex-1">
            <input type="hidden" name="toggle_service_status" value="1">
            <input type="hidden" name="service_entry_id" value="<?php echo $svc['appointment_service_id']; ?>">
            <input type="hidden" name="new_status" value="<?php echo $is_completed ? 'Pending' : 'Completed'; ?>">
            <input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
            
            <button type="submit" class="w-full flex items-center justify-between p-2.5 rounded-lg border transition-all <?php echo $is_completed ? 'bg-gray-50 border-gray-200' : 'bg-white border-[#e2dbe6] hover:border-primary/50 hover:shadow-sm'; ?>">
                <div class="flex items-center gap-3">
                    <div class="size-5 rounded border flex items-center justify-center transition-colors <?php echo $is_completed ? 'bg-green-500 border-green-500 text-white' : 'border-gray-300 text-transparent hover:border-primary'; ?>">
                        <span class="material-symbols-outlined text-sm font-bold">check</span>
                    </div>
                    <div class="flex flex-col items-start">
                        <span class="text-sm font-bold <?php echo $is_completed ? 'text-gray-400 line-through' : 'text-[#151118]'; ?>">
                            <?php echo htmlspecialchars($svc['service_name']); ?>
                        </span>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-medium text-[#7a6189] bg-gray-100 px-1.5 py-0.5 rounded"><?php echo $svc['estimated_duration_mins']; ?>m</span>
                    <?php if ($is_completed): ?>
                        <span class="text-[10px] font-bold px-1.5 py-0.5 rounded bg-green-100 text-green-700">DONE</span>
                    <?php endif; ?>
                </div>
            </button>
        </form>
        
        <!-- Remove Button (Only for Pending) -->
        <?php if (!$is_completed): ?>
        <form method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>" onsubmit="confirmRemoval(event, this);">
            <input type="hidden" name="remove_service" value="1">
            <input type="hidden" name="service_entry_id" value="<?php echo $svc['appointment_service_id']; ?>">
            <input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
            <button type="submit" class="p-2.5 rounded-lg text-red-400 hover:text-red-600 hover:bg-red-50 transition-colors" title="Remove Service">
                <span class="material-symbols-outlined text-lg">delete</span>
            </button>
        </form>
        <?php endif; ?>
    </div>
    <?php endforeach; else: ?>
    <p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($selected_appointment['service_name']); ?></p>
    <?php endif; ?>
    
    <?php if ($selected_appointment['status'] === 'In-Procedure'): ?>
    <div class="mt-2">
        <button onclick="document.getElementById('addServiceModal').classList.remove('hidden')" class="w-full py-2 rounded-xl text-xs font-bold bg-primary/10 text-primary hover:bg-primary/20 transition-colors flex items-center justify-center gap-1">
            <span class="material-symbols-outlined text-sm">add</span> Add Another Service
        </button>
    </div>
    <?php endif; ?>
</div>
</div>
</div>
<div class="flex items-center gap-8">
<div class="flex flex-col items-end hidden sm:flex">
<p class="text-[10px] font-medium text-[#7a6189]">Appointment Time</p>
<p class="text-xs font-bold text-[#151118]"><?php echo $time_display; ?></p>
</div>
</div>
</div>
</div>
<!-- Add Service Modal -->
<!-- Add Service Modal -->
<div id="addServiceModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl w-full max-w-2xl shadow-2xl transform transition-all flex flex-col max-h-[90vh]">
        <!-- Fixed Header -->
        <div class="p-6 border-b border-[#f3f0f4] flex justify-between items-center bg-white rounded-t-2xl z-10 shrink-0">
            <h3 class="text-lg font-bold text-[#151118] flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">medical_services</span>
                Add Additional Service
            </h3>
            <button onclick="document.getElementById('addServiceModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-600 transition-colors">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        
        <!-- Scrollable Body -->
        <div class="p-6 flex-1 overflow-y-auto bg-[#fcfcfd] clinical-scroll">
            <form id="addServiceForm" method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>" class="flex flex-col gap-6">
                <input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
                <input type="hidden" name="add_additional_service" value="1">
                <input type="hidden" name="service_id" id="selected_service_id" required>

                <!-- Search Bar -->
                <div class="relative">
                    <span class="absolute left-4 top-3 material-symbols-outlined text-gray-400">search</span>
                    <input type="text" id="serviceSearchInput" placeholder="Search service name, category, or tag..." 
                           class="w-full pl-11 pr-4 py-2.5 bg-white border border-[#e2dbe6] rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all shadow-sm">
                </div>

                <!-- Service Grid -->
                <!-- Removed internal scroll, now flows with body -->
                <div id="servicesGrid" class="grid grid-cols-1 md:grid-cols-2 gap-3">
                    <?php foreach ($all_services_list as $srv): 
                        // Format Duration
                        $hours = floor($srv['estimated_duration_mins'] / 60);
                        $mins = $srv['estimated_duration_mins'] % 60;
                        $duration = "";
                        if ($hours > 0) $duration .= $hours . "h ";
                        if ($mins > 0) $duration .= $mins . "m";
                        
                        // Check if already added
                        $is_added = in_array($srv['service_id'], $current_service_ids ?? []);
                        
                        // Prepare search keywords
                        $keywords = strtolower($srv['service_name'] . ' ' . $srv['category']);
                    ?>
                    <div class="service-card group rounded-xl border transition-all relative <?php echo $is_added ? 'bg-gray-50 border-gray-200 opacity-60 cursor-not-allowed' : 'bg-white border-[#e2dbe6] hover:border-primary hover:shadow-md cursor-pointer'; ?>"
                         data-id="<?php echo $srv['service_id']; ?>"
                         data-name="<?php echo htmlspecialchars($srv['service_name']); ?>"
                         data-price="<?php echo $srv['base_price']; ?>"
                         data-duration="<?php echo $srv['estimated_duration_mins']; ?>"
                         data-requires-xray="<?php echo $srv['requires_xray']; ?>"
                         data-requires-checkup="<?php echo $srv['requires_checkup']; ?>"
                         data-keywords="<?php echo htmlspecialchars($keywords); ?>"
                         data-added="<?php echo $is_added ? '1' : '0'; ?>"
                         onclick="<?php echo $is_added ? '' : 'selectService(this)'; ?>">
                         
                        <?php if ($is_added): ?>
                        <div class="absolute top-2 right-2 bg-gray-200 text-gray-600 text-[10px] font-bold px-2 py-0.5 rounded-full z-10">
                            ADDED
                        </div>
                        <?php endif; ?>
                        
                        <div class="flex justify-between items-start mb-2 p-4 pb-0">
                            <h4 class="font-bold text-[#151118] text-sm leading-tight group-hover:text-primary transition-colors">
                                <?php echo htmlspecialchars($srv['service_name']); ?>
                            </h4>
                            <span class="font-bold text-primary text-sm whitespace-nowrap">₱<?php echo number_format($srv['base_price'], 2); ?></span>
                        </div>
                        
                        <div class="p-4 pt-2">
                            <div class="flex flex-wrap gap-1.5 mb-3">
                                <?php if (!empty($srv['category'])): ?>
                                <span class="px-2 py-0.5 rounded-md bg-gray-100 text-[#7a6189] text-[10px] font-bold uppercase tracking-wider">
                                    <?php echo htmlspecialchars($srv['category']); ?>
                                </span>
                                <?php endif; ?>
                                
                                <span class="px-2 py-0.5 rounded-md bg-blue-50 text-blue-600 text-[10px] font-bold flex items-center gap-1">
                                    <span class="material-symbols-outlined text-[10px]">schedule</span> <?php echo $duration; ?>
                                </span>
                            </div>

                            <!-- Expandable Details -->
                            <div class="text-xs text-[#7a6189] space-y-1">
                                <?php if ($srv['is_installment_available']): ?>
                                <div class="flex items-center gap-1.5 text-green-600 font-medium">
                                    <span class="material-symbols-outlined text-[14px]">credit_score</span> Installment Available
                                </div>
                                <?php endif; ?>
                                
                                <?php if ($srv['requires_checkup']): ?>
                                <div class="flex items-center gap-1.5 text-orange-600 font-medium">
                                    <span class="material-symbols-outlined text-[14px]">medical_information</span> Check-up Required
                                </div>
                                <?php endif; ?>

                                 <?php if ($srv['requires_xray']): ?>
                                <div class="flex items-center gap-1.5 text-orange-600 font-medium">
                                    <span class="material-symbols-outlined text-[14px]">radiology</span> X-Ray Required
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Selected Indicator -->
                        <div class="absolute top-2 right-2 w-5 h-5 bg-primary rounded-full items-center justify-center text-white hidden selected-indicator">
                            <span class="material-symbols-outlined text-sm">check</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Empty State -->
                <div id="noResults" class="hidden flex-col items-center justify-center h-40 text-[#7a6189]">
                    <span class="material-symbols-outlined text-4xl mb-2 opacity-30">search_off</span>
                    <p class="text-sm">No services found.</p>
                </div>
            
                <!-- Warnings Container (Dynamic) -->
                <div id="warningsContainer" class="hidden flex-col gap-2">
                    <!-- Time Warning -->
                    <div id="timeWarning" class="hidden p-3 bg-red-50 border border-red-100 rounded-xl flex gap-3 text-red-800">
                        <span class="material-symbols-outlined text-red-500 shrink-0">timer_off</span>
                        <div class="text-xs">
                            <p class="font-bold">Time Conflict Warning</p>
                            <p>This service takes <span id="warnServiceTime" class="font-bold">0</span> mins, but you only have <span class="font-bold"><?php echo $available_minutes; ?></span> mins before <span class="font-bold"><?php echo $next_appt_display; ?></span>.</p>
                        </div>
                    </div>
                    
                    <!-- Prerequisite Warning -->
                    <div id="reqWarning" class="hidden p-3 bg-orange-50 border border-orange-100 rounded-xl flex gap-3 text-orange-800">
                        <span class="material-symbols-outlined text-orange-500 shrink-0">warning</span>
                        <div class="text-xs">
                            <p class="font-bold">Prerequisite Required</p>
                            <p>This service requires: <span id="warnReqList" class="font-bold"></span>.</p> 
                            <p class="opacity-80 mt-1">Please ensure valid records exist before proceeding.</p>
                        </div>
                    </div>
                </div>

                <div class="p-4 bg-yellow-50 rounded-xl border border-yellow-100 flex gap-3">
                    <input type="checkbox" name="consent_verified" id="consent_check" required class="mt-0.5 w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary shrink-0">
                    <label for="consent_check" class="text-xs text-yellow-800 cursor-pointer">
                        <strong>Patient Consent Verified:</strong> I confirm that the patient has agreed to this additional service and understands the extra cost.
                    </label>
                </div>
            </form>
        </div>
        
        <!-- Fixed Footer -->
        <div class="p-4 border-t border-[#f3f0f4] bg-white rounded-b-2xl flex justify-end gap-3 shrink-0 z-10">
            <button type="button" onclick="document.getElementById('addServiceModal').classList.add('hidden')" class="px-5 py-2.5 rounded-xl border border-[#e2dbe6] text-[#7a6189] hover:bg-gray-50 font-bold text-sm transition-colors">Cancel</button>
            <button type="submit" form="addServiceForm" id="submitBtn" disabled class="px-5 py-2.5 rounded-xl bg-primary text-white hover:bg-primary/90 font-bold text-sm transition-colors shadow-md shadow-primary/20 disabled:opacity-50 disabled:cursor-not-allowed">Add Service</button>
        </div>
    </div>
</div>

<script>
    <?php
    // Calculate Remaining Time of CURRENT Procedure
    // New Logic: Sum of PENDING services only.
    $remaining_current_mins = 0;
    if ($selected_appointment && !empty($selected_appointment['services'])) {
        foreach ($selected_appointment['services'] as $svc) {
            if (($svc['status'] ?? 'Pending') !== 'Completed') {
                $remaining_current_mins += intval($svc['estimated_duration_mins']);
            }
        }
    } elseif ($selected_appointment) {
        // Fallback if services array missing (legacy)
        $sched_time = strtotime($selected_appointment['appointment_date'] . ' ' . $selected_appointment['time_slot']);
        $total_duration = intval($selected_appointment['total_duration'] ?? 30);
        $elapsed = floor((time() - $sched_time) / 60);
        $elapsed = max(0, $elapsed);
        $remaining_current_mins = max(0, $total_duration - $elapsed);
    }
    ?>
    // Constants from PHP
    const AVAILABLE_MINUTES = <?php echo max(0, $available_minutes); ?>;
    const REMAINING_CURRENT_MINS = <?php echo $remaining_current_mins; ?>;
    
    // Search Functionality
    const searchInput = document.getElementById('serviceSearchInput');
    const serviceCards = document.querySelectorAll('.service-card');
    const noResults = document.getElementById('noResults');

    searchInput.addEventListener('input', function(e) {
        const term = e.target.value.toLowerCase();
        let hasVisible = false;

        serviceCards.forEach(card => {
            const keywords = card.dataset.keywords;
            if (keywords.includes(term)) {
                card.classList.remove('hidden');
                hasVisible = true;
            } else {
                card.classList.add('hidden');
            }
        });

        if (!hasVisible) {
            noResults.classList.remove('hidden');
            noResults.classList.add('flex');
        } else {
            noResults.classList.add('hidden');
            noResults.classList.remove('flex');
        }
    });

    // Selection Logic
    function selectService(cardElement) {
        // Deselect all
        document.querySelectorAll('.service-card').forEach(c => {
            c.classList.remove('ring-2', 'ring-primary', 'bg-primary/5');
            c.querySelector('.selected-indicator').classList.add('hidden');
        });

        // Select clicked
        cardElement.classList.add('ring-2', 'ring-primary', 'bg-primary/5');
        cardElement.querySelector('.selected-indicator').classList.remove('hidden');
        
        // Update input
        const id = cardElement.dataset.id;
        document.getElementById('selected_service_id').value = id;
        
        // Run Checks
        runServiceChecks(cardElement);
        
        // Enable submit button checks
        checkSubmit();
    }
    
    function runServiceChecks(card) {
        const warningsContainer = document.getElementById('warningsContainer');
        const timeWarning = document.getElementById('timeWarning');
        const reqWarning = document.getElementById('reqWarning');
        const warnServiceTime = document.getElementById('warnServiceTime');
        const warnReqList = document.getElementById('warnReqList');
        
        let hasWarnings = false;
        
        // 1. Time Check (Cumulative)
        const newDuration = parseInt(card.dataset.duration) || 0;
        const totalNeeded = newDuration + REMAINING_CURRENT_MINS;
        
        if (totalNeeded > AVAILABLE_MINUTES) {
            timeWarning.classList.remove('hidden');
            // Detailed message
            warnServiceTime.innerHTML = `${newDuration} mins <span class='text-gray-500 font-normal'>+ ${REMAINING_CURRENT_MINS}m current</span> = ${totalNeeded}`;
            hasWarnings = true;
        } else {
            timeWarning.classList.add('hidden');
        }
        
        // 2. Prerequisite Check
        const reqXray = card.dataset.requiresXray == '1';
        const reqCheckup = card.dataset.requiresCheckup == '1';
        let reqs = [];
        if (reqXray) reqs.push("X-Ray");
        if (reqCheckup) reqs.push("Check-up");
        
        if (reqs.length > 0) {
            reqWarning.classList.remove('hidden');
            warnReqList.textContent = reqs.join(" & ");
            hasWarnings = true;
        } else {
            reqWarning.classList.add('hidden');
        }
        
        // Toggle Main Container
        if (hasWarnings) {
            warningsContainer.classList.remove('hidden');
            warningsContainer.classList.add('flex');
        } else {
            warningsContainer.classList.add('hidden');
            warningsContainer.classList.remove('flex');
        }
    }

    const consentCheck = document.getElementById('consent_check');
    const submitBtn = document.getElementById('submitBtn');
    const hiddenInput = document.getElementById('selected_service_id');

    function checkSubmit() {
        if (hiddenInput.value && consentCheck.checked) {
            submitBtn.disabled = false;
        } else {
            submitBtn.disabled = true;
        }
    }

    consentCheck.addEventListener('change', checkSubmit);
    
    // Focus search on open
    const modalObserver = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.target.classList.contains('hidden') === false) {
                 setTimeout(() => searchInput.focus(), 100);
            }
        });
    });
    modalObserver.observe(document.getElementById('addServiceModal'), { attributes: true, attributeFilter: ['class'] });
</script>
<?php else: ?>
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col relative overflow-hidden shrink-0 items-center justify-center p-8">
<div class="text-center text-[#7a6189]">
<span class="material-symbols-outlined text-6xl mb-4 opacity-50">person_search</span>
<p class="text-base font-medium">Select an appointment to view patient information</p>
</div>
</div>
<?php endif; ?>
<?php if ($selected_appointment): ?>
<div class="flex-1 bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col overflow-hidden min-h-0">
<?php if ($save_message || $start_message): ?>
<div class="px-5 py-3 bg-<?php echo ($save_type === 'success' || $start_type === 'success') ? 'green' : 'red'; ?>-50 border-b border-<?php echo ($save_type === 'success' || $start_type === 'success') ? 'green' : 'red'; ?>-200">
<p class="text-sm font-medium text-<?php echo ($save_type === 'success' || $start_type === 'success') ? 'green' : 'red'; ?>-700"><?php echo htmlspecialchars($save_message ?: $start_message); ?></p>
</div>
<?php endif; ?>
<form method="POST" action="doctor_dashboard.php?appointment_id=<?php echo $selected_appointment['appointment_id']; ?>">
<input type="hidden" name="appointment_id" value="<?php echo $selected_appointment['appointment_id']; ?>">
<div class="px-5 py-3 border-b border-[#f3f0f4] flex justify-between items-center bg-white">
<h3 class="font-bold text-[#151118] text-sm">Treatment Notes</h3>
<div class="text-xs text-[#7a6189] font-mono"><?php echo date('g:i A'); ?></div>
</div>
<div class="px-4 py-2 bg-[#fcfcfd] border-b border-[#f3f0f4] flex items-center gap-1 overflow-x-auto">
<button type="button" class="p-1.5 rounded hover:bg-[#e2dbe6] text-[#151118]"><span class="material-symbols-outlined text-[18px]">format_bold</span></button>
<button type="button" class="p-1.5 rounded hover:bg-[#e2dbe6] text-[#151118]"><span class="material-symbols-outlined text-[18px]">format_italic</span></button>
<div class="w-px h-4 bg-[#e2dbe6] mx-1"></div>
<button type="button" class="p-1.5 rounded hover:bg-[#e2dbe6] text-[#151118]"><span class="material-symbols-outlined text-[18px]">format_list_bulleted</span></button>
<div class="w-px h-4 bg-[#e2dbe6] mx-1"></div>
<div class="flex-1"></div>
</div>
<?php if ($selected_appointment['status'] === 'In-Procedure' || $selected_appointment['status'] === 'Completed'): ?>
<div class="flex-1 p-5 flex flex-col gap-4 overflow-y-auto clinical-scroll">
<div>
<label class="text-xs font-bold text-[#7a6189] uppercase mb-2 block">Diagnosis *</label>
<input type="text" name="diagnosis" required class="w-full px-4 py-2 border border-[#e2dbe6] rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary <?php echo $selected_appointment['status'] === 'Completed' ? 'bg-gray-50 cursor-not-allowed' : ''; ?>" placeholder="Enter diagnosis..." value="<?php echo htmlspecialchars($selected_appointment['diagnosis'] ?? ''); ?>" <?php echo $selected_appointment['status'] === 'Completed' ? 'readonly' : ''; ?>>
</div>
<div>
<label class="text-xs font-bold text-[#7a6189] uppercase mb-2 block">Tooth Numbers (if applicable)</label>
<input type="text" name="tooth_numbers" class="w-full px-4 py-2 border border-[#e2dbe6] rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary <?php echo $selected_appointment['status'] === 'Completed' ? 'bg-gray-50 cursor-not-allowed' : ''; ?>" placeholder="e.g., 12, 13, 14" value="<?php echo htmlspecialchars($selected_appointment['tooth_numbers'] ?? ''); ?>" <?php echo $selected_appointment['status'] === 'Completed' ? 'readonly' : ''; ?>>
</div>
<div class="flex-1">
<label class="text-xs font-bold text-[#7a6189] uppercase mb-2 block">Treatment Notes</label>
<textarea name="treatment_notes" class="w-full h-full min-h-[300px] p-4 resize-none border border-[#e2dbe6] rounded-xl focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary text-sm leading-relaxed text-[#151118] placeholder-[#e2dbe6] clinical-scroll <?php echo $selected_appointment['status'] === 'Completed' ? 'bg-gray-50 cursor-not-allowed' : ''; ?>" placeholder="Start typing examination notes here..." <?php echo $selected_appointment['status'] === 'Completed' ? 'readonly' : ''; ?>><?php echo htmlspecialchars($selected_appointment['treatment_notes'] ?? ''); ?></textarea>
</div>
</div>
<div class="p-4 border-t border-[#f3f0f4] bg-white flex justify-between items-center">
<div class="flex items-center gap-2">
<label class="flex items-center gap-2 cursor-pointer">
<input type="checkbox" name="mark_followup" class="form-checkbox rounded text-primary border-[#e2dbe6] focus:ring-primary" value="1" <?php echo $selected_appointment['status'] === 'Completed' ? 'disabled' : ''; ?>/>
<span class="text-xs font-medium text-[#151118]">Mark for follow-up</span>
</label>
</div>
<?php if ($selected_appointment['status'] === 'In-Procedure'): ?>
<button type="submit" name="save_treatment_notes" class="bg-primary hover:bg-primary/90 text-white px-6 py-2 rounded-xl text-sm font-bold shadow-md shadow-primary/20 transition-all flex items-center gap-2">
<span class="material-symbols-outlined text-lg">check_circle</span>
                                        Save &amp; Complete
                                    </button>
<?php else: ?>
<button type="button" disabled class="bg-gray-300 text-gray-500 px-6 py-2 rounded-xl text-sm font-bold cursor-not-allowed flex items-center gap-2">
<span class="material-symbols-outlined text-lg">check_circle</span>
                                        Procedure Completed
                                    </button>
<?php endif; ?>
</div>
<?php else: ?>
<div class="flex-1 p-5 flex flex-col items-center justify-center text-center text-[#7a6189]">
<span class="material-symbols-outlined text-6xl mb-4 opacity-50">play_circle</span>
<p class="text-base font-medium mb-2">Click "Start Procedure" to begin treatment</p>
<p class="text-sm">Once started, you can add diagnosis and treatment notes</p>
</div>
<?php endif; ?>
</form>
</div>
<?php else: ?>
<div class="flex-1 bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col overflow-hidden min-h-0 items-center justify-center p-8">
<div class="text-center text-[#7a6189]">
<span class="material-symbols-outlined text-6xl mb-4 opacity-50">note_add</span>
<p class="text-base font-medium">Select an appointment to add treatment notes</p>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
</main>
</div>

<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>