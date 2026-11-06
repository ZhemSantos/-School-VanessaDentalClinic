<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['patient_id'])) {
    die('Unauthorized');
}

$patient_id = $_SESSION['patient_id'];
$start_date = $_GET['start_date'] ?? null;
$end_date = $_GET['end_date'] ?? null;

// Fetch patient info
$stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) AS name, phone_number, email FROM tbl_patient WHERE patient_id = ?");
$stmt->execute([$patient_id]);
$patient = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch payment history (same query as API)
$sql = "SELECT 
            i.reference_number,
            i.total_amount,
            i.discount_amount,
            i.vat_exempt_amount,
            i.payment_method,
            i.paid_at,
            u.full_name AS doctor_name,
            GROUP_CONCAT(DISTINCT COALESCE(s.service_name, s_legacy.service_name) SEPARATOR '<br>• ') AS service_names
        FROM tbl_invoice i
        LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        LEFT JOIN tbl_patient p ON a.patient_id = p.patient_id
        LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service aps ON a.appointment_id = aps.appointment_id
        LEFT JOIN tbl_service s ON aps.service_id = s.service_id
        LEFT JOIN tbl_medical_record m ON a.appointment_id = m.appointment_id
        LEFT JOIN tbl_service s_legacy ON m.service_id = s_legacy.service_id
        WHERE p.patient_id = ? AND i.payment_status = 'Paid'";

$params = [$patient_id];

if ($start_date && $end_date) {
    $sql .= " AND DATE(i.paid_at) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

$sql .= " GROUP BY i.invoice_id ORDER BY i.paid_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total = 0;
$total_discount = 0;
foreach ($history as $row) {
    $total += $row['total_amount'];
    $total_discount += $row['discount_amount'] ?? 0;
}

// Generate HTML for PDF
$html = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Payment History</title>
    <script src='https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js'></script>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 12px; 
            margin: 20px;
            color: #333;
        }
        h1 { 
            text-align: center; 
            color: #6A1B9A; 
            margin-bottom: 5px;
        }
        .header { 
            text-align: center; 
            margin-bottom: 30px; 
            padding-bottom: 15px;
            border-bottom: 2px solid #6A1B9A;
        }
        .patient-info {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 20px; 
        }
        th, td { 
            border: 1px solid #ddd; 
            padding: 10px; 
            text-align: left; 
        }
        th { 
            background-color: #6A1B9A; 
            color: white; 
            font-weight: bold; 
            font-size: 11px;
            text-transform: uppercase;
        }
        .total-row { 
            font-weight: bold; 
            background-color: #f9f9f9;
        }
        .amount { 
            text-align: right;
            font-weight: bold; 
            color: #16a34a; 
        }
        .discount {
            color: #9333ea;
            font-size: 10px;
        }
        .footer { 
            margin-top: 30px; 
            text-align: center; 
            font-size: 10px; 
            color: #666; 
            border-top: 1px solid #ddd;
            padding-top: 15px;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .actions {
            text-align: center;
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            margin: 0 5px;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            border-radius: 8px;
            cursor: pointer;
            border: none;
            transition: all 0.3s;
        }
        .btn-download {
            background: linear-gradient(to right, #dc2626, #b91c1c);
            color: white;
        }
        .btn-download:hover {
            background: linear-gradient(to right, #b91c1c, #991b1b);
        }
        .btn-back {
            background: #e5e7eb;
            color: #1f2937;
        }
        .btn-back:hover {
            background: #d1d5db;
        }
        @media print {
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class='actions'>
        <button onclick='downloadPDF()' id='downloadBtn' class='btn btn-download'>📄 Download PDF</button>
        <a href='../client/client_payment.php' class='btn btn-back'>← Back to Payments</a>
    </div>
    
    <div id='pdfContent'>
        <div class='header'>
            <h1>Vanessa Nicolas Dental Clinic</h1>
            <p style='font-size: 14px; font-weight: bold; margin: 5px 0;'>Payment History Report</p>
            " . ($start_date && $end_date ? "<p style='margin: 5px 0;'>Period: " . date('F d, Y', strtotime($start_date)) . " to " . date('F d, Y', strtotime($end_date)) . "</p>" : "<p style='margin: 5px 0;'>All Time</p>") . "
            <p style='font-size: 10px; color: #666; margin: 5px 0;'>Generated: " . date('F d, Y h:i A') . "</p>
        </div>
        
        <div class='patient-info'>
            <strong>Patient:</strong> {$patient['name']}<br>
            <strong>Contact:</strong> {$patient['phone_number']} | {$patient['email']}
        </div>";

if (empty($history)) {
    $html .= "<div class='no-data'>No payment records found for the selected period.</div>";
} else {
    $html .= "
        <table>
            <thead>
                <tr>
                    <th style='width: 15%;'>Invoice #</th>
                    <th style='width: 12%;'>Date</th>
                    <th style='width: 30%;'>Services</th>
                    <th style='width: 18%;'>Doctor</th>
                    <th style='width: 10%;'>Method</th>
                    <th style='width: 15%; text-align: right;'>Amount</th>
                </tr>
            </thead>
            <tbody>";

    foreach ($history as $row) {
        $html .= "
        <tr>
            <td style='font-family: monospace; font-size: 11px;'>{$row['reference_number']}</td>
            <td>" . date('M d, Y', strtotime($row['paid_at'])) . "<br><span style='font-size: 9px; color: #666;'>" . date('h:i A', strtotime($row['paid_at'])) . "</span></td>
            <td style='font-size: 11px;'>{$row['service_names']}</td>
            <td>Dr. {$row['doctor_name']}</td>
            <td><span style='background: #e0f2fe; padding: 2px 6px; border-radius: 3px; font-size: 10px;'>{$row['payment_method']}</span></td>
            <td class='amount'>₱" . number_format($row['total_amount'], 2) . 
            ($row['discount_amount'] > 0 ? "<br><span class='discount'>-₱" . number_format($row['discount_amount'], 2) . " disc</span>" : "") . 
            "</td>
        </tr>";
    }

    $html .= "
            </tbody>
            <tfoot>
                <tr class='total-row'>
                    <td colspan='5' style='text-align: right; padding-right: 15px;'>Total Payments:</td>
                    <td class='amount' style='font-size: 14px;'>₱" . number_format($total, 2) . "</td>
                </tr>";
    
    if ($total_discount > 0) {
        $html .= "
                <tr>
                    <td colspan='5' style='text-align: right; padding-right: 15px; color: #9333ea;'>Total Discounts Applied:</td>
                    <td class='amount' style='color: #9333ea;'>-₱" . number_format($total_discount, 2) . "</td>
                </tr>";
    }
    
    $html .= "
            </tfoot>
        </table>";
}

$html .= "
        <div class='footer'>
            <p><strong>Vanessa Nicolas Dental Clinic</strong></p>
            <p>This is a computer-generated document. No signature required.</p>
            <p>For inquiries, please contact the clinic during business hours.</p>
        </div>
    </div>
    
    <script>
        function downloadPDF() {
            const button = document.getElementById('downloadBtn');
            const originalText = button.innerHTML;
            
            // Show loading state
            button.disabled = true;
            button.innerHTML = '⏳ Generating...';
            
            const element = document.getElementById('pdfContent');
            const dateRange = '" . ($start_date && $end_date ? date('Ymd', strtotime($start_date)) . '_to_' . date('Ymd', strtotime($end_date)) : 'All_Time') . "';
            
            const opt = {
                margin: [10, 10, 10, 10],
                filename: 'Payment_History_' + dateRange + '.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save().then(() => {
                button.disabled = false;
                button.innerHTML = originalText;
            }).catch((error) => {
                console.error('PDF generation error:', error);
                button.disabled = false;
                button.innerHTML = originalText;
                alert('Error generating PDF. Please try again.');
            });
        }
    </script>
</body>
</html>
";

// Output as HTML
header('Content-Type: text/html; charset=utf-8');
echo $html;
?>
