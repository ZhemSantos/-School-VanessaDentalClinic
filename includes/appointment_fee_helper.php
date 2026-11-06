<?php
/**
 * Appointment Fee Calculation Helper
 * Calculates appointment fee based on total duration with 15-minute overtime rule
 */

require_once __DIR__ . '/../db.php';

/**
 * Get setting value from tbl_settings
 */
function getSetting($key, $default = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM tbl_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (PDOException $e) {
        error_log("Error fetching setting $key: " . $e->getMessage());
        return $default;
    }
}

/**
 * Calculate appointment fee based on total duration
 * Uses existing 'appointment_fee' as base fee (covers first 30 minutes)
 * Overtime charges apply after 30 minutes
 * 
 * @param int $total_duration_mins Total duration in minutes
 * @return float Calculated appointment fee
 */
function calculateAppointmentFee($total_duration_mins) {
    // Get settings
    $base_fee = floatval(getSetting('appointment_fee', 200)); // Covers first 30 minutes
    $overtime_fee = floatval(getSetting('appointment_overtime_fee', 50));
    $overtime_interval = intval(getSetting('appointment_overtime_interval_mins', 15));
    
    // Base fee covers first 30 minutes
    $fee = $base_fee;
    
    // Calculate overtime only if duration exceeds 30 minutes
    if ($total_duration_mins > 30 && $overtime_interval > 0) {
        $overtime_mins = $total_duration_mins - 30; // Overtime starts after 30 min
        $overtime_increments = ceil($overtime_mins / $overtime_interval);
        $fee += ($overtime_increments * $overtime_fee);
    }
    
    return $fee;
}

/**
 * Get total duration for an appointment from all services
 * 
 * @param int $appointment_id
 * @return int Total duration in minutes
 */
function getAppointmentTotalDuration($appointment_id) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            SELECT SUM(s.estimated_duration_mins) as total_duration
            FROM tbl_appointment_service tas
            INNER JOIN tbl_service s ON tas.service_id = s.service_id
            WHERE tas.appointment_id = ?
        ");
        $stmt->execute([$appointment_id]);
        $result = $stmt->fetchColumn();
        return intval($result ?? 0);
    } catch (PDOException $e) {
        error_log("Error getting appointment duration: " . $e->getMessage());
        return 0;
    }
}

/**
 * Calculate appointment fee for a specific appointment
 * 
 * @param int $appointment_id
 * @return array ['duration' => int, 'fee' => float, 'breakdown' => string]
 */
function calculateAppointmentFeeForAppointment($appointment_id) {
    $total_duration = getAppointmentTotalDuration($appointment_id);
    $fee = calculateAppointmentFee($total_duration);
    
    $base_fee = floatval(getSetting('appointment_fee', 200));
    $overtime_fee = floatval(getSetting('appointment_overtime_fee', 50));
    $overtime_interval = intval(getSetting('appointment_overtime_interval_mins', 15));
    
    // Create breakdown
    $breakdown = "Base fee (first 30 min): ₱" . number_format($base_fee, 2);
    
    if ($total_duration > 30 && $overtime_interval > 0) {
        $overtime_mins = $total_duration - 30; // Overtime starts after 30 min
        $overtime_increments = ceil($overtime_mins / $overtime_interval);
        $overtime_total = $overtime_increments * $overtime_fee;
        $breakdown .= "\nOvertime (" . $overtime_mins . " min = " . $overtime_increments . " × " . $overtime_interval . " min): ₱" . number_format($overtime_total, 2);
    }
    
    return [
        'duration' => $total_duration,
        'fee' => $fee,
        'breakdown' => $breakdown
    ];
}
?>
