<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Dentist role to access this API
requireRole('Dentist');

// Set headers after session is started
header('Content-Type: application/json');

// Get parameters
$doctor_id = $_SESSION['user_id'] ?? null;
$date = isset($_GET['date']) ? sanitizeInput($_GET['date']) : '';

if (!$doctor_id || empty($date)) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    // Get day of week from date
    $date_obj = new DateTime($date, new DateTimeZone('Asia/Manila'));
    $day_name = $date_obj->format('l'); // e.g., "Monday", "Tuesday"
    
    // Get doctor's schedule for this day
    $schedule_stmt = $pdo->prepare("
        SELECT start_time, end_time
        FROM tbl_doctor_schedule
        WHERE doctor_id = ? AND day_of_week = ?
        LIMIT 1
    ");
    $schedule_stmt->execute([$doctor_id, $day_name]);
    $schedule = $schedule_stmt->fetch();
    
    // Helper function to generate time slots
    function generateTimeSlots($start_time, $end_time) {
        $slots = [];
        $start = new DateTime($start_time);
        $end = new DateTime($end_time);
        
        $current = clone $start;
        while ($current < $end) {
            $time_str = $current->format('H:i');
            $time_display = $current->format('g:i A');
            $slots[] = [
                'value' => $time_str,
                'display' => $time_display
            ];
            $current->modify('+15 minutes');
        }
        
        return $slots;
    }
    
    if ($schedule) {
        $time_slots = generateTimeSlots($schedule['start_time'], $schedule['end_time']);
        echo json_encode([
            'success' => true,
            'slots' => $time_slots
        ]);
    } else {
        // Default: 8:00 AM to 7:00 PM if no schedule
        $time_slots = generateTimeSlots('08:00:00', '19:00:00');
        echo json_encode([
            'success' => true,
            'slots' => $time_slots,
            'message' => 'No schedule found, using default hours'
        ]);
    }
} catch (PDOException $e) {
    error_log('Error fetching block time slots: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching schedule'
    ]);
    exit;
} catch (Exception $e) {
    error_log('Error in get_block_time_slots: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid date format'
    ]);
    exit;
}
