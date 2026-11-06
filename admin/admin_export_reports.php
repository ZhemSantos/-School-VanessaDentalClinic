<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

configureSecureSession();
requireRole('Admin');

// 1. Get Filter Parameters
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$doctor_id  = $_GET['doctor_id'] ?? 'all';

// Headers for Download
$filename = "report_" . date('Y-m-d') . ".csv";
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="' . $filename . '"');

$output = fopen('php://output', 'w');

// --- SECTION 1: REPORT SUMMARY ---
fputcsv($output, ['VANESSA NICOLAS DENTAL CLINIC - ADMIN REPORT']);
fputcsv($output, ['Period:', $start_date . ' to ' . $end_date]);
if ($doctor_id !== 'all') {
    fputcsv($output, ['Filter:', 'Doctor ID: ' . $doctor_id]);
}
fputcsv($output, []); // Empty line

// Calc Totals
$total_revenue = 0;
$invoices_issued = 0;
$appointments_count = 0;

$date_clause = " BETWEEN ? AND ? ";
$params = [$start_date, $end_date];
$inv_sql = "SELECT SUM(i.total_amount) as rev FROM tbl_invoice i LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id WHERE i.issued_date $date_clause AND i.payment_status = 'Paid'";
$apt_sql = "SELECT COUNT(*) FROM tbl_appointment a WHERE a.appointment_date $date_clause";

if ($doctor_id !== 'all') {
    $inv_sql .= " AND a.doctor_id = ?";
    $apt_sql .= " AND a.doctor_id = ?";
    $params[] = $doctor_id;
}

try {
    $stmt = $pdo->prepare($inv_sql);
    $stmt->execute($params);
    $total_revenue = $stmt->fetchColumn() ?: 0;
    
    $stmt = $pdo->prepare($apt_sql);
    $stmt->execute($params);
    $appointments_count = $stmt->fetchColumn();
} catch (Exception $e) {}


fputcsv($output, ['SUMMARY METRICS']);
fputcsv($output, ['Total Revenue', number_format($total_revenue, 2)]);
fputcsv($output, ['Total Appointments', $appointments_count]);
fputcsv($output, []);
fputcsv($output, []);

// --- SECTION 2: APPOINTMENTS ---
fputcsv($output, ['APPOINTMENT LIST LOG']);
fputcsv($output, ['Date', 'Time', 'Patient', 'Doctor', 'Status']);

try {
    $sql = "SELECT a.appointment_date, a.time_slot, CONCAT(p.first_name, ' ', p.last_name) as patient, CONCAT(d.full_name) as doctor, a.status 
            FROM tbl_appointment a
            LEFT JOIN tbl_patient p ON a.patient_id = p.patient_id
            LEFT JOIN tbl_user d ON a.doctor_id = d.user_id
            WHERE a.appointment_date BETWEEN ? AND ?";
    if ($doctor_id !== 'all') {
        $sql .= " AND a.doctor_id = ?";
    }
    $sql .= " ORDER BY a.appointment_date ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
} catch (Exception $e) { fputcsv($output, ['Error fetching appointments']); }

fputcsv($output, []);
fputcsv($output, []);


// --- SECTION 3: TRANSACTIONS ---
fputcsv($output, ['TRANSACTION LOG']);
fputcsv($output, ['Date', 'Invoice #', 'Patient', 'Amount', 'Status', 'Method']);

try {
    $sql = "SELECT i.created_at, i.reference_number, CONCAT(p.first_name, ' ', p.last_name) as patient, i.total_amount, i.payment_status, i.payment_method
            FROM tbl_invoice i
            LEFT JOIN tbl_patient p ON i.patient_id = p.patient_id
            LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
            WHERE i.created_at BETWEEN ? AND ?";
    if ($doctor_id !== 'all') {
        $sql .= " AND a.doctor_id = ?";
    }
    $sql .= " ORDER BY i.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $row);
    }
} catch (Exception $e) { fputcsv($output, ['Error fetching transactions']); }

fclose($output);
?>
