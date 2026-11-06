<?php
// Email notification helper for appointment cancellations

function sendAppointmentCancellationEmail($patientInfo) {
    require_once __DIR__ . '/../includes/SMTPMailer.php';
    
    $subject = "Appointment Cancelled - Please Reschedule";
    $message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2 style='color: #6A1B9A;'>Appointment Cancellation Notice</h2>
            <p>Dear {$patientInfo['full_name']},</p>
            <p>We regret to inform you that your appointment scheduled for:</p>
            <ul>
                <li><strong>Date:</strong> " . date('F j, Y', strtotime($patientInfo['date'])) . "</li>
                <li><strong>Time:</strong> " . date('g:i A', strtotime($patientInfo['time'])) . "</li>
            </ul>
            <p>has been <strong>cancelled</strong> because the time slot was booked by another patient who completed payment first.</p>
            <p>We sincerely apologize for any inconvenience this may cause.</p>
            <p><strong>Next Steps:</strong></p>
            <ol>
                <li>Log in to your patient portal</li>
                <li>Book a new appointment at your convenience</li>
            </ol>
            <p>If you have any questions or concerns, please don't hesitate to contact us.</p>
            <p>Best regards,<br><strong>Vanessa Clinic</strong></p>
        </body>
        </html>
    ";
    
    try {
        sendEmail($patientInfo['email'], $subject, $message);
    } catch (Exception $e) {
        error_log('Failed to send cancellation email: ' . $e->getMessage());
    }
}

function sendAppointmentNoShowEmail($patientInfo) {
    require_once __DIR__ . '/../includes/SMTPMailer.php';
    
    $subject = "Appointment Marked as No-Show";
    $message = "
        <html>
        <body style='font-family: Arial, sans-serif;'>
            <h2 style='color: #FF6B6B;'>Appointment No-Show Notice</h2>
            <p>Dear {$patientInfo['patient_name']},</p>
            <p>Your appointment scheduled for:</p>
            <ul>
                <li><strong>Date:</strong> " . date('F j, Y', strtotime($patientInfo['date'])) . "</li>
                <li><strong>Time:</strong> " . date('g:i A', strtotime($patientInfo['time'])) . "</li>
                <li><strong>Doctor:</strong> Dr. {$patientInfo['doctor_name']}</li>
            </ul>
            <p>has been marked as <strong>No-Show</strong> because you did not arrive within the 15-minute grace period.</p>
            <p><strong>Important:</strong></p>
            <ul>
                <li>If you paid the appointment fee, it has been forfeited</li>
                <li>You may book a new appointment through the patient portal</li>
                <li>Please arrive on time for future appointments</li>
            </ul>
            <p>If you believe this was an error or had an emergency, please contact us immediately.</p>
            <p>Best regards,<br><strong>Vanessa Clinic</strong></p>
        </body>
        </html>
    ";
    
    try {
        sendEmail($patientInfo['email'], $subject, $message);
    } catch (Exception $e) {
        error_log('Failed to send no-show email: ' . $e->getMessage());
    }
}
?>
