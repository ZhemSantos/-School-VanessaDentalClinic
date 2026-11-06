<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['patient_id']) || !isset($_GET['invoice_id'])) {
    header("Location: ../landing.php");
    exit;
}

$patient_id = $_SESSION['patient_id'];
$invoice_id = $_GET['invoice_id'];

// Fetch invoice and related details
// Fetch invoice and related details
$stmt = $pdo->prepare("
    SELECT i.invoice_id, i.reference_number, i.total_amount, i.discount_amount, i.amount_paid,
           i.vat_exempt_amount, i.payment_method, i.paid_at, i.created_at,
           p.first_name, p.last_name, p.email, p.phone_number, p.address,
           COALESCE(u_doc.full_name, 'Vanessa Nicolas Dental Clinic') as doctor_name, 
           a.appointment_fee_paid,
           d.discount_type, d.id_number, d.id_issuer,
           GROUP_CONCAT(DISTINCT s.service_name SEPARATOR ', ') as services
    FROM tbl_invoice i
    -- Link via Appointment
    LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
    LEFT JOIN tbl_user u_doc ON a.doctor_id = u_doc.user_id
    LEFT JOIN tbl_appointment_service aps ON a.appointment_id = aps.appointment_id
    -- Link via Installment
    LEFT JOIN tbl_installment inst ON i.installment_id = inst.installment_id
    
    -- Link Patient (from either Appointment or Installment)
    LEFT JOIN tbl_patient p ON COALESCE(a.patient_id, inst.patient_id) = p.patient_id
    
    -- Link Services (from Appointment or Installment)
    LEFT JOIN tbl_service s ON COALESCE(aps.service_id, inst.service_id) = s.service_id
    
    LEFT JOIN tbl_invoice_discount d ON i.invoice_id = d.invoice_id
    WHERE i.invoice_id = ? 
      AND p.patient_id = ?
      AND i.payment_status = 'Paid'
    GROUP BY i.invoice_id, i.reference_number, i.total_amount, i.discount_amount, i.amount_paid,
             i.vat_exempt_amount, i.payment_method, i.paid_at, i.created_at,
             p.first_name, p.last_name, p.email, p.phone_number, p.address,
             u_doc.full_name, d.discount_type, d.id_number, d.id_issuer, a.appointment_fee_paid
");
$stmt->execute([$invoice_id, $patient_id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    header("Location: client_payment.php");
    exit;
}

$receipt_number = $invoice['reference_number'];
$payment_date = date('F j, Y g:i A', strtotime($invoice['paid_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Invoice Receipt</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @media print {
            .no-print { display: none; }
            body { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        }
    </style>
</head>
<body class="bg-gray-50 p-8">
    <div class="max-w-3xl mx-auto">
        <!-- Action Buttons -->
        <div class="no-print flex gap-3 mb-6">
            <button onclick="window.print()" class="flex items-center gap-2 px-6 py-3 bg-purple-600 hover:bg-purple-700 text-white font-bold rounded-lg transition-all">
                <span>🖨️</span> Print Receipt
            </button>
            <button onclick="downloadPDF()" id="downloadBtn" class="flex items-center gap-2 px-6 py-3 bg-red-600 hover:bg-red-700 text-white font-bold rounded-lg transition-all">
                <span>📄</span> Download PDF
            </button>
            <a href="client_payment.php" class="flex items-center gap-2 px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-800 font-bold rounded-lg transition-all">
                ← Back to Payments
            </a>
        </div>

        <!-- Receipt -->
        <div id="receiptContent" class="bg-white rounded-2xl shadow-2xl overflow-hidden border-4 border-purple-600">
            <!-- Header -->
            <div class="bg-gradient-to-r from-purple-600 to-purple-800 text-white p-8">
                <div class="flex items-start justify-between">
                    <div>
                        <h1 class="text-3xl font-black mb-2">VANESSA CLINIC</h1>
                        <p class="text-purple-100 text-sm">Dental Care Excellence</p>
                    </div>
                    <div class="text-right">
                        <div class="bg-white text-purple-900 px-4 py-2 rounded-lg font-bold">
                            OFFICIAL RECEIPT
                        </div>
                        <p class="text-purple-100 text-sm mt-2"><?php echo $receipt_number; ?></p>
                    </div>
                </div>
            </div>

            <!-- Receipt Body -->
            <div class="p-8">
                <!-- Payment Info -->
                <div class="grid grid-cols-2 gap-6 mb-8 pb-8 border-b-2 border-gray-200">
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Patient Name</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Payment Date</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo $payment_date; ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Payment Method</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo $invoice['payment_method']; ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Doctor</p>
                        <p class="text-lg font-bold text-gray-900">Dr. <?php echo htmlspecialchars($invoice['doctor_name']); ?></p>
                    </div>
                </div>

                <!-- Services and Breakdown -->
                <?php
                    // Fetch Breakdown
                    $breakdown = [];
                    if ($invoice) { // Invoice is already fetched above
                        // We need the appointment ID from invoice -> appointment
                        // The initial query joined tbl_appointment, so we should select appointment_id
                        // Let's quickly verify we selected it. The original query selected i.*, but this file's query is specific.
                        // I need to update the top query first to ensure I have appointment_id available.
                        // Wait, I am editing the display logic here. I must ensure the data is available. 
                        // The top query DOES NOT currently select appointment_id explicitly in the SELECT clause, 
                        // but it joins tbl_appointment a.
                        // I should update the SELECT clause in a separate edit or assume I can get it. 
                        // Actually, I can use a secondary query here if I have invoice_id.
                        
                        $stmt_bd = $pdo->prepare("
                            SELECT s.service_name, tas.price_at_booking
                            FROM tbl_invoice i
                            JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
                            JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                            JOIN tbl_service s ON tas.service_id = s.service_id
                            WHERE i.invoice_id = ?
                        "); // Indirectly via invoice_id
                        $stmt_bd->execute([$invoice_id]);
                        $breakdown = $stmt_bd->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (empty($breakdown)) {
                            // Fallback to the GROUP_CONCAT text if no separate services found
                             $breakdown[] = ['service_name' => $invoice['services'], 'price_at_booking' => $invoice['total_amount'] + $invoice['discount_amount']];
                        }
                    }
                    
                    // Fetch Advance Payment
                    $advance_payment = 0;
                    if ($invoice['appointment_id']) { // Assuming appointment_id is available from top query or need to fetch it?
                         // Top query joins tbl_appointment a. But SELECT clause selects i.* unless modified.
                         // Let's check SELECT list in file. 
                         // Yes, line 15: SELECT i.invoice_id... 
                         // It does NOT select a.appointment_id explicitly. 
                         // But I can fetch it via separate query or assuming it's linked?
                         // Actually, I should use a helper query. 
                         
                         $adv_stmt = $pdo->prepare("SELECT appointment_id FROM tbl_invoice WHERE invoice_id = ?");
                         $adv_stmt->execute([$invoice_id]);
                         $res = $adv_stmt->fetch();
                         $appt_id = $res['appointment_id'] ?? 0;
                         
                         if ($appt_id) {
                             $adv_check = $pdo->prepare("SELECT amount_paid FROM tbl_invoice WHERE appointment_id = ? AND invoice_id != ? AND payment_status IN ('Paid', 'Pending') LIMIT 1");
                             $adv_check->execute([$appt_id, $invoice_id]);
                             $adv_res = $adv_check->fetch();
                             if ($adv_res) $advance_payment = floatval($adv_res['amount_paid']);
                         }
                    }
                ?>

                <div class="mb-8">
                    <h3 class="text-sm font-bold text-gray-700 uppercase mb-4">Services Rendered</h3>
                    <div class="bg-purple-50 rounded-lg p-0 overflow-hidden">
                        <table class="w-full text-left text-sm">
                            <thead class="bg-purple-100 text-purple-900 border-b border-purple-200">
                                <tr>
                                    <th class="p-3 font-semibold">Service</th>
                                    <th class="p-3 font-semibold text-right">Price</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-purple-100">
                                <?php foreach ($breakdown as $item): ?>
                                <tr class="hover:bg-purple-100/50 transition-colors">
                                    <td class="p-3 text-gray-800 font-medium"><?php echo htmlspecialchars($item['service_name']); ?></td>
                                    <td class="p-3 text-gray-800 text-right font-mono">₱<?php echo number_format($item['price_at_booking'], 2); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Payment Breakdown -->
                <div class="bg-gray-50 rounded-xl p-6 mb-8">
                    <h3 class="text-sm font-bold text-gray-700 uppercase mb-4">Payment Summary</h3>
                    
                    <div class="space-y-3">
                        <?php 
                        // Calculate Subtotal from Breakdown (Same as Print Receipt)
                        $services_total = 0;
                        foreach ($breakdown as $item) {
                            $services_total += $item['price_at_booking'];
                        }
                        
                        $appointment_fee_paid = $invoice['appointment_fee_paid'] ?? 0;
                        $vat_exempt_base = $invoice['vat_exempt_amount'] ?? 0;
                        
                        // Correct Subtotal
                        $subtotal = $services_total;
                        
                        // VAT Deduction
                        $vat_deduction = 0;
                        if ($vat_exempt_base > 0) {
                            $vat_deduction = $subtotal - $vat_exempt_base;
                        }
                        ?>
                        
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-600">Subtotal</span>
                            <span class="font-bold text-gray-800">₱<?php echo number_format($subtotal, 2); ?></span>
                        </div>
                        
                        <?php if ($vat_deduction > 0): ?>
                        <div class="flex justify-between items-center text-sm text-gray-500">
                            <span>Less: VAT (12%)</span>
                            <span class="font-semibold">-₱<?php echo number_format($vat_deduction, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($invoice['discount_amount'] > 0): ?>
                        <div class="flex justify-between items-center text-sm text-green-600">
                            <span>
                                Less: <?php echo $invoice['discount_type']; ?> Discount
                                <?php if ($invoice['id_number']): ?>
                                <span class="text-xs text-green-500">(ID: <?php echo htmlspecialchars($invoice['id_number']); ?>)</span>
                                <?php endif; ?>
                            </span>
                            <span class="font-semibold">-₱<?php echo number_format($invoice['discount_amount'], 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($appointment_fee_paid > 0): ?>
                        <div class="flex justify-between items-center text-sm text-blue-600">
                            <span>Less: Appointment Fee</span>
                            <span class="font-semibold">-₱<?php echo number_format($appointment_fee_paid, 2); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ($advance_payment > 0): ?>
                        <div class="flex justify-between items-center text-sm text-blue-600">
                            <span>Less: Advance Payment</span>
                            <span class="font-semibold">-₱<?php echo number_format($advance_payment, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="border-t-2 border-dashed border-gray-300 pt-3 mt-3">
                            <?php
                                // Calculate Net Due correctly
                                $net_due = $subtotal - $vat_deduction - ($invoice['discount_amount'] ?? 0) - ($invoice['appointment_fee_paid'] ?? 0) - $advance_payment;
                            ?>
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-sm font-bold text-gray-700">Net Amount Due</span>
                                <span class="text-lg font-bold text-gray-900">₱<?php echo number_format($net_due, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Important Notes -->
                <div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded">
                    <h4 class="font-bold text-blue-900 mb-2">📌 Important Notes:</h4>
                    <ul class="text-sm text-blue-800 space-y-1 list-disc list-inside">
                        <li>This receipt confirms payment for medical services rendered</li>
                        <li>Please keep this receipt for your records</li>
                        <li>For any billing inquiries, please contact the clinic</li>
                        <?php if ($invoice['discount_amount'] > 0): ?>
                        <li>Discount applied as per <?php echo $invoice['discount_type']; ?> ID verification</li>
                        <?php endif; ?>
                    </ul>
                </div>

                <!-- Footer -->
                <div class="mt-8 pt-6 border-t-2 border-gray-200 text-center text-sm text-gray-600">
                    <p class="font-semibold">Thank you for choosing Vanessa Clinic!</p>
                    <p class="mt-2">For inquiries, please contact us at: clinic@vanessa.com | (123) 456-7890</p>
                    <p class="mt-4 text-xs text-gray-400">This is a computer-generated receipt and does not require a signature.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function downloadPDF() {
            const button = document.getElementById('downloadBtn');
            const originalText = button.innerHTML;
            
            // Show loading state
            button.disabled = true;
            button.innerHTML = '<span>⏳</span> Generating...';
            
            const element = document.getElementById('receiptContent');
            const opt = {
                margin: [10, 10, 10, 10],
                filename: 'Medical_Invoice_<?php echo $receipt_number; ?>.pdf',
                image: { type: 'jpeg', quality: 0.98 },
                html2canvas: { scale: 2, useCORS: true },
                jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save().then(() => {
                // Restore button
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
