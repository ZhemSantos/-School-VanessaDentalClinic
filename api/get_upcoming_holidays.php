<?php
// api/get_upcoming_holidays.php
header('Content-Type: application/json');
require_once '../db.php';

try {
    // Fetch blocked dates from today onwards
    // Group by reason to simplify display (e.g., "Dec 25: Christmas", "Jan 1: New Year")
    $stmt = $pdo->prepare("SELECT block_date, start_time, end_time, reason FROM tbl_blocked_time WHERE block_date >= CURDATE() ORDER BY block_date ASC LIMIT 5");
    $stmt->execute();
    $holidays = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter duplicates (same date, same reason, different doctor = clinic wide holiday usually)
    // If it's a clinic-wide holiday, all doctors are blocked. We want to show distinct reasons/dates.
    $unique_holidays = [];
    $seen = [];

    foreach ($holidays as $h) {
        $key = $h['block_date'] . '|' . $h['reason'];
        if (!in_array($key, $seen)) {
            $seen[] = $key;
            
            // Format for display
            $dateObj = new DateTime($h['block_date']);
            $h['formatted_date'] = $dateObj->format('F j, Y');
            
            // Check if whole day (00:00 to 23:59 roughly)
            $is_whole = ($h['start_time'] == '00:00:00' && $h['end_time'] >= '23:00:00');
            $h['display_time'] = $is_whole ? 'Whole Day' : date('g:i A', strtotime($h['start_time'])) . ' - ' . date('g:i A', strtotime($h['end_time']));
            
            $unique_holidays[] = $h;
        }
    }

    echo json_encode(['success' => true, 'holidays' => $unique_holidays]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
