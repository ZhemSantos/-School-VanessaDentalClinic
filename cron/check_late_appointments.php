<?php
/**
 * Cron Job: Check for Late Appointments
 * This script should be run every minute via cron job
 * 
 * Cron setup (Linux):
 * * * * * * /usr/bin/php /path/to/check_late_appointments.php
 * 
 * For Windows Task Scheduler or manual execution:
 * php check_late_appointments.php
 */

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/email_notifications.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

// Grace period in minutes (15 minutes)
define('LATE_GRACE_PERIOD', 15);

try {
    // Get current date and time
    $now = new DateTime();
    $current_date = $now->format('Y-m-d');
    $current_time = $now->format('H:i:s');
    
    // Calculate the cutoff time (15 minutes ago)
    $cutoff_time = clone $now;
    $cutoff_time->modify('-' . LATE_GRACE_PERIOD . ' minutes');
    $cutoff_date = $cutoff_time->format('Y-m-d');
    $cutoff_datetime = $cutoff_time->format('Y-m-d H:i:s');
    
    // Find appointments that are:
    // 1. Today or in the past
    // 2. Appointment time has passed by more than 15 minutes
    // 3. Status is 'Pending' or 'Booked' (not already cancelled/completed)
    // 4. Not already marked as cancelled due to late arrival
    
    $query = "
        SELECT a.*, 
               p.first_name, p.last_name, p.email,
               GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
               u.full_name as doctor_name
        FROM tbl_appointment a
        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.status IN ('Pending', 'Booked')
          AND (
              (a.appointment_date < ?)
              OR (
                  a.appointment_date = ? 
                  AND CONCAT(a.appointment_date, ' ', a.time_slot) < ?
              )
          )
          AND a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        GROUP BY a.appointment_id
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        $current_date,
        $current_date,
        $cutoff_datetime
    ]);
    
    $late_appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $cancelled_count = 0;
    $email_sent_count = 0;
    
    foreach ($late_appointments as $appointment) {
        // Calculate how late the patient is
        $appt_datetime = new DateTime($appointment['appointment_date'] . ' ' . $appointment['time_slot']);
        $minutes_late = round(($now->getTimestamp() - $appt_datetime->getTimestamp()) / 60);
        
        // Only cancel if more than 15 minutes late
        if ($minutes_late > LATE_GRACE_PERIOD) {
            try {
                // Determine new status and reason based on current status
                $new_status = 'Cancelled';
                $reason = "Late Arrival (More than " . LATE_GRACE_PERIOD . " minutes late)";
                
                if ($appointment['status'] === 'Booked') {
                    $new_status = 'No Show';
                    $reason = "Marked as No-Show due to Late Arrival (More than " . LATE_GRACE_PERIOD . " minutes late)";
                }

                // Update appointment status
                $update_stmt = $pdo->prepare("UPDATE tbl_appointment SET status = ? WHERE appointment_id = ?");
                $update_stmt->execute([$new_status, $appointment['appointment_id']]);
                
                $cancelled_count++;
                
                // Send cancellation/no-show email
                if (!empty($appointment['email'])) {
                    // We reuse the cancellation email function but with the specific reason
                    // For 'No Show', the email subject inside the function is 'Appointment Cancelled'. 
                    // Ideally we might want a specific 'No Show' email content, but the user requested "this will come with the cancellation email", so using the existing templated email with a specific reason is a safe start.
                    $email_sent = sendAppointmentCancellation(
                        $appointment['appointment_id'],
                        $appointment['patient_id'],
                        $appointment['appointment_date'],
                        $appointment['time_slot'],
                        $appointment['service_name'],
                        $reason
                    );
                    
                    if ($email_sent) {
                        $email_sent_count++;
                    }
                }
                
                error_log("Updated late appointment ID: " . $appointment['appointment_id'] . " to '$new_status' (Patient: " . $appointment['first_name'] . " " . $appointment['last_name'] . ", " . $minutes_late . " minutes late)");
                
            } catch (PDOException $e) {
                error_log("Error updating late appointment ID " . $appointment['appointment_id'] . ": " . $e->getMessage());
            }
        }
    }
    
    // Log summary
    if ($cancelled_count > 0) {
        error_log("Late appointment check completed: $cancelled_count appointment(s) cancelled, $email_sent_count email(s) sent");
    }
    
    // Return summary for manual execution
    if (php_sapi_name() === 'cli') {
        echo "Late Appointment Check Summary:\n";
        echo "Checked: " . count($late_appointments) . " appointment(s)\n";
        echo "Cancelled: $cancelled_count appointment(s)\n";
        echo "Emails sent: $email_sent_count\n";
    }
    
} catch (PDOException $e) {
    error_log("Error checking late appointments: " . $e->getMessage());
    if (php_sapi_name() === 'cli') {
        echo "Error: " . $e->getMessage() . "\n";
    }
    exit(1);
}

exit(0);

