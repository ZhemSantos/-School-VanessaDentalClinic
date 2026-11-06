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
    error_log('Error fetching invoices for PDF export: ' . $e->getMessage());
    $invoices = [];
}

// Generate filter description
$filter_desc = 'All Invoices';
if ($date_filter === 'today') {
    $filter_desc = 'Today\'s Invoices';
} elseif ($date_filter === 'range' && !empty($date_from) && !empty($date_to)) {
    $filter_desc = 'Invoices from ' . date('M d, Y', strtotime($date_from)) . ' to ' . date('M d, Y', strtotime($date_to));
}
if ($status_filter !== 'all') {
    $filter_desc .= ' - Status: ' . $status_filter;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Invoice Report - Vanessa Nicolas Dental Clinic</title>
    <style>
        @media print {
            @page {
                margin: 1cm;
                size: A4 landscape;
            }
            body {
                margin: 0;
                padding: 20px;
                font-family: Arial, sans-serif;
                font-size: 10px;
            }
            .no-print {
                display: none !important;
            }
        }
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
        }
        h1 {
            color: #6A1B9A;
            margin-bottom: 5px;
        }
        .meta {
            color: #666;
            margin-bottom: 20px;
            font-size: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        th {
            background-color: #6A1B9A;
            color: white;
            padding: 8px;
            text-align: left;
            font-size: 10px;
            border: 1px solid #ddd;
        }
        td {
            padding: 6px;
            border: 1px solid #ddd;
            font-size: 9px;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 9px;
            color: #666;
        }
        .download-pdf-btn {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 12px 24px;
            background: #6A1B9A;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: bold;
            z-index: 10000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        .download-pdf-btn:hover {
            background: #8a11d4;
        }
        @media print {
            .download-pdf-btn {
                display: none;
            }
        }
    </style>
    <script>
        window.onload = function() {
            // Add download button
            const downloadBtn = document.createElement('button');
            downloadBtn.textContent = 'Print / Save as PDF';
            downloadBtn.className = 'download-pdf-btn no-print';
            downloadBtn.onclick = function() {
                window.print();
            };
            document.body.appendChild(downloadBtn);
        };
    </script>
</head>
<body>
    <h1>Invoice Report - Vanessa Nicolas Dental Clinic</h1>
    <div class="meta">
        Generated: <?php echo date('F j, Y g:i A'); ?><br>
        Filter: <?php echo htmlspecialchars($filter_desc); ?><br>
        Total Invoices: <?php echo count($invoices); ?>
    </div>
    <table>
        <thead>
            <tr>
                <th>Invoice ID</th>
                <th>Reference</th>
                <th>Patient Name</th>
                <th>Patient ID</th>
                <th>Service</th>
                <th>Date</th>
                <th>Gross</th>
                <th>Fee</th>
                <th>Net Due</th>
                <th>Paid</th>
                <th>Method</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($invoices)): ?>
            <tr>
                <td colspan="9" style="text-align: center; padding: 20px;">No invoices found</td>
            </tr>
            <?php else: ?>
            <?php 
            $total_amount = 0;
            foreach ($invoices as $invoice): 
                $invoice_id_display = $invoice['reference_number'] ?: 'INV-' . str_pad($invoice['invoice_id'], 4, '0', STR_PAD_LEFT);
                $patient_name = $invoice['first_name'] . ' ' . $invoice['last_name'];
                $patient_id_display = 'P-' . str_pad($invoice['patient_id'], 4, '0', STR_PAD_LEFT);
                
                $date_obj = new DateTime($invoice['date_of_treatment']);
                $date_formatted = $date_obj->format('M d, Y g:i A');
                
                $total_amount += floatval($invoice['total_amount']);
            ?>
            <tr>
                <td><?php echo $invoice['invoice_id']; ?></td>
                <td><?php echo htmlspecialchars($invoice_id_display); ?></td>
                <td><?php echo htmlspecialchars($patient_name); ?></td>
                <td><?php echo htmlspecialchars($patient_id_display); ?></td>
                <td><?php echo htmlspecialchars($invoice['service_name']); ?></td>
                <td><?php echo htmlspecialchars($date_formatted); ?></td>
                <td>$<?php echo number_format($invoice['total_amount'], 2); ?></td>
                <td>$<?php echo number_format($invoice['appointment_fee_paid'] ?? 0, 2); ?></td>
                <td>$<?php echo number_format($invoice['total_amount'] - ($invoice['appointment_fee_paid'] ?? 0), 2); ?></td>
                <td>$<?php echo number_format($invoice['amount_paid'], 2); ?></td>
                <td><?php echo htmlspecialchars($invoice['payment_method'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($invoice['payment_status']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr style="background-color: #f0f0f0; font-weight: bold;">
                <td colspan="6" style="text-align: right;">Total Gross:</td>
                <td>$<?php echo number_format($total_amount, 2); ?></td>
                <td colspan="2"></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <div class="footer">
        <p>This is a computer-generated report from Vanessa Nicolas Dental Clinic</p>
    </div>
</body>
</html>

