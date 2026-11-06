<?php
/**
 * Email Notification Functions
 * Handles all email notifications for the clinic system
 */

require_once __DIR__ . '/email_config.php';
require_once __DIR__ . '/../db.php';

/**
 * Send appointment confirmation email
 */
function sendAppointmentConfirmation($appointment_id, $patient_id, $appointment_date, $appointment_time, $service_name, $doctor_name) {
    global $pdo;
    
    try {
        // Get patient details
        $patient_stmt = $pdo->prepare("SELECT first_name, last_name, email FROM tbl_patient WHERE patient_id = ?");
        $patient_stmt->execute([$patient_id]);
        $patient = $patient_stmt->fetch();
        
        if (!$patient || empty($patient['email'])) {
            error_log("Patient not found or no email for appointment ID: $appointment_id");
            return false;
        }
        
        $patient_name = $patient['first_name'] . ' ' . $patient['last_name'];
        $to = $patient['email'];
        
        // Format date and time
        $date_obj = new DateTime($appointment_date);
        $formatted_date = $date_obj->format('F d, Y');
        $time_obj = new DateTime($appointment_time);
        $formatted_time = $time_obj->format('g:i A');
        
        $subject = "Appointment Confirmed - Vanessa Nicolas Dental Clinic";
        
        $content = '
            <h2>Appointment Confirmed</h2>
            <p>Dear ' . htmlspecialchars($patient_name) . ',</p>
            <p>Your appointment has been confirmed. Please find the details below:</p>
            
            <div class="alert alert-info">
                <strong>Appointment Details:</strong><br>
                <strong>Date:</strong> ' . htmlspecialchars($formatted_date) . '<br>
                <strong>Time:</strong> ' . htmlspecialchars($formatted_time) . '<br>
                <strong>Service:</strong> ' . htmlspecialchars($service_name) . '<br>
                <strong>Doctor:</strong> ' . htmlspecialchars($doctor_name) . '<br>
                <strong>Appointment ID:</strong> #' . $appointment_id . '
            </div>
            
            <div class="alert alert-warning">
                <strong>Important Reminders:</strong>
                <ul>
                    <li>Please arrive 10-15 minutes before your scheduled time</li>
                    <li>Bring a valid ID and any relevant medical records</li>
                    <li>If you need to reschedule or cancel, please contact us at least 24 hours in advance</li>
                    <li>Late arrivals (more than 15 minutes) may result in appointment cancellation</li>
                </ul>
            </div>
            
            <p>We look forward to seeing you!</p>
            <p>Best regards,<br>Vanessa Nicolas Dental Clinic</p>
        ';
        
        return sendEmail($to, $subject, $content);
        
    } catch (PDOException $e) {
        error_log("Error sending appointment confirmation email: " . $e->getMessage());
        return false;
    }
}

/**
 * Send appointment cancellation email (for late arrivals)
 */
function sendAppointmentCancellation($appointment_id, $patient_id, $appointment_date, $appointment_time, $service_name, $reason = 'Late Arrival') {
    global $pdo;
    
    try {
        // Get patient details
        $patient_stmt = $pdo->prepare("SELECT first_name, last_name, email FROM tbl_patient WHERE patient_id = ?");
        $patient_stmt->execute([$patient_id]);
        $patient = $patient_stmt->fetch();
        
        if (!$patient || empty($patient['email'])) {
            error_log("Patient not found or no email for appointment ID: $appointment_id");
            return false;
        }
        
        $patient_name = $patient['first_name'] . ' ' . $patient['last_name'];
        $to = $patient['email'];
        
        // Format date and time
        $date_obj = new DateTime($appointment_date);
        $formatted_date = $date_obj->format('F d, Y');
        $time_obj = new DateTime($appointment_time);
        $formatted_time = $time_obj->format('g:i A');
        
        $subject = "Appointment Cancelled - Vanessa Nicolas Dental Clinic";
        
        $content = '
            <h2>Appointment Cancelled</h2>
            <p>Dear ' . htmlspecialchars($patient_name) . ',</p>
            <p>We regret to inform you that your appointment has been cancelled.</p>
            
            <div class="alert alert-warning">
                <strong>Cancelled Appointment Details:</strong><br>
                <strong>Date:</strong> ' . htmlspecialchars($formatted_date) . '<br>
                <strong>Time:</strong> ' . htmlspecialchars($formatted_time) . '<br>
                <strong>Service:</strong> ' . htmlspecialchars($service_name) . '<br>
                <strong>Appointment ID:</strong> #' . $appointment_id . '<br>
                <strong>Reason:</strong> ' . htmlspecialchars($reason) . '
            </div>
            
            <p><strong>What happened?</strong></p>
            <p>Your appointment was cancelled because you arrived more than 15 minutes after your scheduled appointment time. Our clinic policy requires patients to arrive on time to ensure efficient scheduling for all our patients.</p>
            
            <p><strong>What can you do?</strong></p>
            <p>Please contact us to reschedule your appointment at your earliest convenience. We apologize for any inconvenience this may cause.</p>
            
            <p>To reschedule, please call us or visit our clinic during business hours.</p>
            
            <p>Best regards,<br>Vanessa Nicolas Dental Clinic</p>
        ';
        
        return sendEmail($to, $subject, $content);
        
    } catch (PDOException $e) {
        error_log("Error sending appointment cancellation email: " . $e->getMessage());
        return false;
    }
}

/**
 * Send payment confirmation email
 */
function sendPaymentConfirmation($invoice_id, $patient_id, $total_amount, $payment_method, $reference_number) {
    global $pdo;
    
    try {
        // Get patient details
        $patient_stmt = $pdo->prepare("SELECT first_name, last_name, email FROM tbl_patient WHERE patient_id = ?");
        $patient_stmt->execute([$patient_id]);
        $patient = $patient_stmt->fetch();
        
        if (!$patient || empty($patient['email'])) {
            error_log("Patient not found or no email for invoice ID: $invoice_id");
            return false;
        }
        
        $patient_name = $patient['first_name'] . ' ' . $patient['last_name'];
        $to = $patient['email'];
        
        $subject = "Payment Confirmation - Vanessa Nicolas Dental Clinic";
        
        $content = '
            <h2>Payment Confirmed</h2>
            <p>Dear ' . htmlspecialchars($patient_name) . ',</p>
            <p>Thank you for your payment. Your transaction has been successfully processed.</p>
            
            <div class="alert alert-success">
                <strong>Payment Details:</strong><br>
                <strong>Invoice Reference:</strong> ' . htmlspecialchars($reference_number) . '<br>
                <strong>Amount Paid:</strong> $' . number_format($total_amount, 2) . '<br>
                <strong>Payment Method:</strong> ' . htmlspecialchars($payment_method) . '<br>
                <strong>Transaction Date:</strong> ' . date('F d, Y g:i A') . '
            </div>
            
            <p>This email serves as your receipt. Please keep it for your records.</p>
            
            <p>If you have any questions about this payment, please contact us.</p>
            
            <p>Best regards,<br>Vanessa Nicolas Dental Clinic</p>
        ';
        
        return sendEmail($to, $subject, $content);
        
    } catch (PDOException $e) {
        error_log("Error sending payment confirmation email: " . $e->getMessage());
        return false;
    }
}

/**
 * Send appointment scheduled email (when new appointment is created)
 */
function sendAppointmentScheduled($appointment_id, $patient_id, $appointment_date, $appointment_time, $service_name, $doctor_name) {
    global $pdo;
    
    try {
        // Get patient details
        $patient_stmt = $pdo->prepare("SELECT first_name, last_name, email FROM tbl_patient WHERE patient_id = ?");
        $patient_stmt->execute([$patient_id]);
        $patient = $patient_stmt->fetch();
        
        if (!$patient || empty($patient['email'])) {
            error_log("Patient not found or no email for appointment ID: $appointment_id");
            return false;
        }
        
        $patient_name = $patient['first_name'] . ' ' . $patient['last_name'];
        $to = $patient['email'];
        
        // Format date and time
        $date_obj = new DateTime($appointment_date);
        $formatted_date = $date_obj->format('F d, Y');
        $time_obj = new DateTime($appointment_time);
        $formatted_time = $time_obj->format('g:i A');
        
        $subject = "New Appointment Scheduled - Vanessa Nicolas Dental Clinic";
        
        $content = '
            <h2>Appointment Scheduled</h2>
            <p>Dear ' . htmlspecialchars($patient_name) . ',</p>
            <p>Your appointment has been successfully scheduled. Please find the details below:</p>
            
            <div class="alert alert-info">
                <strong>Appointment Details:</strong><br>
                <strong>Date:</strong> ' . htmlspecialchars($formatted_date) . '<br>
                <strong>Time:</strong> ' . htmlspecialchars($formatted_time) . '<br>
                <strong>Service:</strong> ' . htmlspecialchars($service_name) . '<br>
                <strong>Doctor:</strong> ' . htmlspecialchars($doctor_name) . '<br>
                <strong>Appointment ID:</strong> #' . $appointment_id . '<br>
                <strong>Status:</strong> Pending Confirmation
            </div>
            
            <div class="alert alert-warning">
                <strong>Important Reminders:</strong>
                <ul>
                    <li>Please arrive 10-15 minutes before your scheduled time</li>
                    <li>Late arrivals (more than 15 minutes) may result in appointment cancellation</li>
                    <li>You will receive a confirmation email once your appointment is confirmed</li>
                    <li>If you need to reschedule or cancel, please contact us at least 24 hours in advance</li>
                </ul>
            </div>
            
            <p>We look forward to seeing you!</p>
            <p>Best regards,<br>Vanessa Nicolas Dental Clinic</p>
        ';
        
        return sendEmail($to, $subject, $content);
        
    } catch (PDOException $e) {
        error_log("Error sending appointment scheduled email: " . $e->getMessage());
        return false;
    }
}


/**
 * Send new account credentials to patient
 */
function sendNewAccountCredentials($patient_id, $username, $password, $email) {
    global $pdo;

    if (empty($email)) {
        return false;
    }
    
    try {
        // Get patient name just in case (though we likely have it, good to verify)
        if (empty($patient_id)) { 
             // If patient_id is not yet available but we have email, we can proceed with generic greeting or pass name as arg
             // check caller context. Caller `receptionist_registration.php` has patient_id.
             // But let's fetch to be safe and consistent with other functions
        }
        
        $stmt = $pdo->prepare("SELECT first_name, last_name FROM tbl_patient WHERE patient_id = ?");
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch();
        $patient_name = $patient ? ($patient['first_name'] . ' ' . $patient['last_name']) : 'Patient';

        $to = $email;
        $subject = "Welcome to Vanessa Nicolas Dental Clinic - Account Credentials";
        
        $content = '
            <h2>Welcome to Vanessa Nicolas Dental Clinic!</h2>
            <p>Dear ' . htmlspecialchars($patient_name) . ',</p>
            <p>Your patient account has been successfully created.</p>
            
            <div class="alert alert-success">
                <strong>Your Login Credentials:</strong><br>
                <strong>Username:</strong> <span style="font-family: monospace; font-size: 1.2em;">' . htmlspecialchars($username) . '</span><br>
                <strong>Temporary Password:</strong> <span style="font-family: monospace; font-size: 1.2em;">' . htmlspecialchars($password) . '</span>
            </div>
            
            <p>Please login to our patient portal to view your appointments and medical records.</p>
            <p><strong>Note:</strong> You will be prompted to change your password upon your first login.</p>
            
            <p>Best regards,<br>Vanessa Nicolas Dental Clinic</p>
        ';
        
        return sendEmail($to, $subject, $content);
        
    } catch (Exception $e) {
        error_log("Error sending credential email: " . $e->getMessage());
        return false;
    }
}
