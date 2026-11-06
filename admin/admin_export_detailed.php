<?php
// admin/admin_export_detailed.php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once 'report_logic.php';

configureSecureSession();
requireRole('Admin');

// Filters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$report_date = $_GET['report_date'] ?? date('Y-m-d');
$report_key = $_GET['report_key'] ?? ''; // Which report to download
// We might support 'all_financial', etc later if needed, but for now specific key.

if (empty($report_key)) {
    die("No report specified.");
}

// Fetch all data (optimized later to fetch only needed if performance is issue, but reuse is simpler)
$data = getReportsData($pdo, $start_date, $end_date, $report_date);

if (!isset($data[$report_key])) {
    die("Invalid report key.");
}

$rows = $data[$report_key];
$filename = $report_key . "_" . date('Ymd_His') . ".csv";

// Clean Headers
if (empty($rows)) {
    // Empty CSV
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "No data found for this period.";
    exit;
}

// Generate CSV
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// Header Row (from keys of first row)
fputcsv($output, array_keys($rows[0]));

// Data Rows
foreach ($rows as $row) {
    fputcsv($output, $row);
}

fclose($output);
exit;
?>
