<?php
require_once '../db.php';
header('Content-Type: text/plain');

echo "Debug Schedule Info:\n\n";

// 1. List all doctors
echo "Doctors:\n";
$stmt = $pdo->query("SELECT user_id, full_name FROM tbl_user WHERE role='Dentist'");
while ($row = $stmt->fetch()) {
    echo "ID: " . $row['user_id'] . " - " . $row['full_name'] . "\n";
}
echo "\n";

// 2. List Schedule for all doctors
echo "Schedules:\n";
$stmt = $pdo->query("SELECT * FROM tbl_doctor_schedule ORDER BY doctor_id, FIELD(day_of_week, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday')");
while ($row = $stmt->fetch()) {
    echo "Doc ID: " . $row['doctor_id'] . " | Day: " . str_pad($row['day_of_week'], 10) . " | " . $row['start_time'] . " - " . $row['end_time'] . "\n";
}

// 3. Check for Feb 1 2026 (Sunday) specifically
$date = '2026-02-01';
$day = date('l', strtotime($date));
echo "\nCheck Date: $date ($day)\n";
?>
