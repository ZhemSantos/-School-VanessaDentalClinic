<?php
/**
 * Auto-Cancel Late Appointments
 * This script should be run every 5 minutes via cron job or task scheduler
 * Cancels appointments where patient didn't arrive within 15-minute grace period
 */

require_once '../db.php';
require_once '../api/appointment_email_helper.php';

try {
    // Find appointments that are late (past appointment time + 15 minute grace period)
    // Only cancel Booked/Pending appointments, not those already Arrived or In-Procedure
    $stmt = $pdo->prepare("
        SELECT a.appointment_id, a.patient_id, a.appointment_date, a.time_slot,
               p.email, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
               u.full_name as doctor_name
        FROM tbl_appointment a
        JOIN tbl_patient p ON a.patient_id = p.patient_id
        JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.status IN ('Booked', 'Pending')
          AND CONCAT(a.appointment_date, ' ', a.time_slot) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute();
    $lateAppointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $cancelledCount = 0;
    
    foreach ($lateAppointments as $appt) {
        // Update appointment status to 'No-Show'
        $updateStmt = $pdo->prepare("
            UPDATE tbl_appointment 
            SET status = 'No-Show' 
            WHERE appointment_id = ?
        ");
        $updateStmt->execute([$appt['appointment_id']]);
        
        // Create notification for patient
        $notifStmt = $pdo->prepare("
            INSERT INTO tbl_notifications (patient_id, title, message, type, created_at)
            VALUES (?, ?, ?, 'appointment', NOW())
        ");
        
        $title = "Appointment Marked as No-Show";
        $message = "Your appointment on " . date('M d, Y', strtotime($appt['appointment_date'])) . 
                   " at " . date('g:i A', strtotime($appt['time_slot'])) . 
                   " with Dr. " . $appt['doctor_name'] . 
                   " has been marked as No-Show due to late arrival (15-minute grace period exceeded).";
        
        $notifStmt->execute([$appt['patient_id'], $title, $message]);
        
        // Send email notification
        if ($appt['email']) {
            sendAppointmentNoShowEmail([
                'patient_name' => $appt['patient_name'],
                'email' => $appt['email'],
                'date' => $appt['appointment_date'],
                'time' => $appt['time_slot'],
                'doctor_name' => $appt['doctor_name']
            ]);
        }
        
        $cancelledCount++;
    }
    
    if ($cancelledCount > 0) {
        error_log("Auto-cancelled $cancelledCount late appointments at " . date('Y-m-d H:i:s'));
    }
    
} catch (Exception $e) {
    error_log("Error in auto-cancel script: " . $e->getMessage());
}
?>
