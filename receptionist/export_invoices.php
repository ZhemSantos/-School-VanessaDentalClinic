<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role to access this page
requireRole('Receptionist');

// Get filter parameters
$search_query = sanitizeInput($_GET['search'] ?? '');
$status_filter = sanitizeInput($_GET['status'] ?? 'all');
$date_filter = sanitizeInput($_GET['date_filter'] ?? 'all');
$date_from = sanitizeInput($_GET['date_from'] ?? '');
$date_to = sanitizeInput($_GET['date_to'] ?? '');

// Build query for invoices (same logic as receptionist_payment.php)
$where_conditions = [];
$params = [];

if (!empty($search_query)) {
    $where_conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR i.reference_number LIKE ? OR i.invoice_id = ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = is_numeric($search_query) ? intval($search_query) : 0;
}

if ($status_filter !== 'all' && in_array($status_filter, ['Paid', 'Pending', 'Failed'])) {
    $where_conditions[] = "i.payment_status = ?";
    $params[] = $status_filter;
}

// Date filtering
$today_date = date('Y-m-d');
if ($date_filter === 'today') {
    $where_conditions[] = "DATE(COALESCE(i.paid_at, i.created_at, mr.date_of_treatment)) = ?";
    $params[] = $today_date;
} elseif ($date_filter === 'range' && !empty($date_from) && !empty($date_to)) {
    $where_conditions[] = "DATE(COALESCE(i.paid_at, i.created_at, mr.date_of_treatment)) BETWEEN ? AND ?";
    $params[] = $date_from;
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get all invoices (no pagination for export)
try {
    $sql = "
        SELECT i.*, 
               mr.patient_id, mr.date_of_treatment,
               p.first_name, p.last_name, p.phone_number, p.email,
               s.service_name, s.base_price,
               a.appointment_fee_paid
        FROM tbl_invoice i
        INNER JOIN tbl_medical_record mr ON i.record_id = mr.record_id
        INNER JOIN tbl_patient p ON mr.patient_id = p.patient_id
        INNER JOIN tbl_service s ON mr.service_id = s.service_id
        LEFT JOIN tbl_appointment a ON mr.appointment_id = a.appointment_id
        " . ($where_clause ? $where_clause . " " : "") . "
        ORDER BY i.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $invoices = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Error fetching invoices for export: ' . $e->getMessage());
    $invoices = [];
}

// Set headers for CSV download
$filename = 'invoices_' . date('Y-m-d_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

// Open output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8 (helps Excel recognize encoding)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Write CSV header
fputcsv($output, [
    'Invoice ID',
    'Reference Number',
    'Patient Name',
    'Patient ID',
    'Phone Number',
    'Email',
    'Service/Treatment',
    'Date of Treatment',
    'Service/Treatment',
    'Date of Treatment',
    'Total Amount (Gross)',
    'Appointment Fee',
    'Net Payable',
    'Amount Paid',
    'Payment Method',
    'Payment Status',
    'Paid At',
    'Created At'
]);

// Write data rows
foreach ($invoices as $invoice) {
    $invoice_id_display = $invoice['reference_number'] ?: 'INV-' . str_pad($invoice['invoice_id'], 4, '0', STR_PAD_LEFT);
    $patient_name = $invoice['first_name'] . ' ' . $invoice['last_name'];
    $patient_id_display = 'P-' . str_pad($invoice['patient_id'], 4, '0', STR_PAD_LEFT);
    
    $date_obj = new DateTime($invoice['date_of_treatment']);
    $date_formatted = $date_obj->format('Y-m-d H:i:s');
    
    $paid_at = '';
    if (!empty($invoice['paid_at'])) {
        $paid_at_obj = new DateTime($invoice['paid_at']);
        $paid_at = $paid_at_obj->format('Y-m-d H:i:s');
    }
    
    $created_at = '';
    if (!empty($invoice['created_at'])) {
        $created_at_obj = new DateTime($invoice['created_at']);
        $created_at = $created_at_obj->format('Y-m-d H:i:s');
    }
    
    fputcsv($output, [
        $invoice['invoice_id'],
        $invoice_id_display,
        $patient_name,
        $patient_id_display,
        $invoice['phone_number'] ?? '',
        $invoice['email'] ?? '',
        $invoice['service_name'],
        $date_formatted,
        number_format($invoice['total_amount'], 2),
        number_format($invoice['appointment_fee_paid'] ?? 0, 2),
        number_format($invoice['total_amount'] - ($invoice['appointment_fee_paid'] ?? 0) - ($invoice['discount_amount'] ?? 0), 2),
        number_format($invoice['amount_paid'], 2),
        $invoice['payment_method'] ?? '',
        $invoice['payment_status'],
        $paid_at,
        $created_at
    ]);
}

// Close output stream
fclose($output);
exit;
?>

