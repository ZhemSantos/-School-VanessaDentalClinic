<?php
// admin/admin_export_category.php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once 'report_logic.php';

configureSecureSession();
requireRole('Admin');

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$report_date = $_GET['report_date'] ?? date('Y-m-d');
$category   = $_GET['category'] ?? '';

if (empty($category)) die("No category specified.");

// Map Category to Report Keys
    'financial' => ['financial_transactions', 'daily_sales', 'payment_methods', 'accounts_receivable', 'discounts'],
    'clinical'  => ['treatment_log', 'attendance', 'productivity'],
    'installment' => ['active_installments', 'installments_due', 'completed_installments'],
    'management' => ['top_services', 'feedback']
];

if (!isset($category_map[$category])) die("Invalid category.");

$report_keys = $category_map[$category];
$data = getReportsData($pdo, $start_date, $end_date, $report_date);

// Create ZIP
$zip = new ZipArchive();
$zip_filename = "temp_" . uniqid() . ".zip";
$zip_path = sys_get_temp_dir() . "/" . $zip_filename;

if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
    die("Cannot create zip.");
}

foreach ($report_keys as $key) {
    if (isset($data[$key])) {
        // Create CSV String
        $csv_string = "";
        $rows = $data[$key];
        
        if (!empty($rows)) {
            $fh = fopen('php://temp', 'rw');
            // Header
            fputcsv($fh, array_keys($rows[0]));
            // Rows
            foreach ($rows as $row) {
                fputcsv($fh, $row);
            }
            rewind($fh);
            $csv_string = stream_get_contents($fh);
            fclose($fh);
        } else {
            $csv_string = "No data for this period.";
        }
        
        $zip->addFromString($key . ".csv", $csv_string);
    }
}

$zip->close();

// Download
$download_name = ucfirst($category) . "_Reports_" . date('Ymd_His') . ".zip";
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $download_name . '"');
header('Content-Length: ' . filesize($zip_path));
readfile($zip_path);

// Cleanup
unlink($zip_path);
exit;
?>
