<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Set content type
header('Content-Type: application/json');

// Configure secure session
configureSecureSession();

// Require receptionist role to access this API
requireRole('Receptionist');

// Get parameters
$doctor_id = isset($_GET['doctor_id']) ? intval($_GET['doctor_id']) : 0;
$day = isset($_GET['day']) ? sanitizeInput($_GET['day']) : '';
$week = isset($_GET['week']) && $_GET['week'] === 'true';

if ($doctor_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid doctor ID']);
    exit;
}

try {
    if ($week) {
        // Fetch full week schedule
        $stmt = $pdo->prepare("
            SELECT day_of_week, start_time, end_time
            FROM tbl_doctor_schedule
            WHERE doctor_id = ?
            ORDER BY FIELD(day_of_week, 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday')
        ");
        $stmt->execute([$doctor_id]);
        $schedules = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'schedules' => $schedules
        ]);
    } else {
        // Fetch schedule for specific day
        if (empty($day)) {
            echo json_encode(['success' => false, 'message' => 'Day parameter required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            SELECT day_of_week, start_time, end_time
            FROM tbl_doctor_schedule
            WHERE doctor_id = ? AND day_of_week = ?
            LIMIT 1
        ");
        $stmt->execute([$doctor_id, $day]);
        $schedule = $stmt->fetch();
        
        if ($schedule) {
            echo json_encode([
                'success' => true,
                'schedule' => $schedule
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'message' => 'No schedule found for this day'
            ]);
        }
    }
} catch (PDOException $e) {
    error_log('Error fetching doctor schedule: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching schedule'
    ]);
}

