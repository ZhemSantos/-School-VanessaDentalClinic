<?php
// includes/auto_cancel.php
// Logic to automatically cancel appointments that are >15 mins late

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure dependencies are available. 
// We use __DIR__ to find them relative to this file.
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/notification_helper.php';
require_once __DIR__ . '/email_notifications.php';

if (isset($pdo)) {
    try {
        // Set Timezone explicitly to Asia/Manila for InfinityFree compatibility
        date_default_timezone_set('Asia/Manila');
        
        // 1. Find appointments that need cancellation
        // Status: Pending or Booked
        // Criteria: Current Time > (Appointment Time + 15 mins)
        
        $current_date = date('Y-m-d');
        $current_time = date('H:i:s');
        
        // We use PHP variables to ensure timezone consistency
        $sql = "SELECT a.*, 
                       p.first_name, p.last_name, p.email,
                       GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name
                FROM tbl_appointment a
                INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE a.status IN ('Pending', 'Booked') 
                  AND a.appointment_date <= ? 
                  AND (
                      a.appointment_date < ? 
                      OR 
                      (a.appointment_date = ? AND ADDTIME(a.time_slot, '00:15:00') < ?)
                  )
                GROUP BY a.appointment_id";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$current_date, $current_date, $current_date, $current_time]);
        $late_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Remove debug logging to clean up
        // $debug_file = __DIR__ . '/debug_cancel.log'; ...
        
        if (!empty($late_appointments)) {
            $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = ? WHERE appointment_id = ?");
            
            foreach ($late_appointments as $appt) {
                // Determine new status and reason
                $new_status = 'Cancelled';
                $reason = "Late Arrival (More than 15 minutes late)"; // Hardcoded 15 here as per query
                
                if ($appt['status'] === 'Booked') {
                    $new_status = 'No-Show'; // Fixed: Must match ENUM ('No-Show')
                    $reason = "Marked as No-Show due to Late Arrival (More than 15 minutes late)";
                }

                // Update Status
                $update_stmt->execute([$new_status, $appt['appointment_id']]);
                
                // Also Cancel any Pending Installments linked to this appointment
                $cancel_inst_stmt = $pdo->prepare("UPDATE tbl_installment SET status = 'Cancelled' WHERE appointment_id = ? AND status = 'Pending'");
                $cancel_inst_stmt->execute([$appt['appointment_id']]);
                
                // Create Notification for Doctor
                $patient_name = $appt['first_name'] . ' ' . $appt['last_name'];
                $time = date('g:i A', strtotime($appt['time_slot']));
                $message = "Appointment with {$patient_name} at {$time} was automatically marked as {$new_status} due to late arrival (15+ mins).";
                
                createNotification(
                    $pdo, 
                    $appt['doctor_id'], 
                    'Dentist', 
                    'Appointment', 
                    'Auto-Cancellation', 
                    $message, 
                    "doctor_dashboard.php?date=" . date('Y-m-d')
                );

                // Send Email to Patient
                if (!empty($appt['email'])) {
                    sendAppointmentCancellation(
                        $appt['appointment_id'],
                        $appt['patient_id'],
                        $appt['appointment_date'],
                        $appt['time_slot'],
                        $appt['service_name'],
                        $reason
                    );
                }
            }
        }
        
    } catch (Exception $e) {
        // Silent fail to not disrupt the page
        error_log("Auto-cancel error: " . $e->getMessage());
    }
}
?>
