<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['patient_id']) || !isset($_GET['appointment_id'])) {
    header("Location: ../landing.php");
    exit;
}

$patient_id = $_SESSION['patient_id'];
$appointment_id = $_GET['appointment_id'];

// Fetch appointment and payment details
$stmt = $pdo->prepare("
    SELECT a.appointment_id, a.appointment_date, a.time_slot, a.appointment_fee_paid, a.created_at as booked_at,
           p.first_name, p.last_name, p.email, p.phone_number, p.address,
           u.full_name as doctor_name,
           GROUP_CONCAT(s.service_name SEPARATOR ', ') as services,
           SUM(s.estimated_duration_mins) as total_duration
    FROM tbl_appointment a
    JOIN tbl_patient p ON a.patient_id = p.patient_id
    JOIN tbl_user u ON a.doctor_id = u.user_id
    LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
    LEFT JOIN tbl_service s ON tas.service_id = s.service_id
    WHERE a.appointment_id = ? AND a.patient_id = ?
    GROUP BY a.appointment_id
");
$stmt->execute([$appointment_id, $patient_id]);
$appointment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$appointment || $appointment['appointment_fee_paid'] <= 0) {
    header("Location: client_payment.php");
    exit;
}

$receipt_number = 'APT-' . str_pad($appointment_id, 6, '0', STR_PAD_LEFT);
$payment_date = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Appointment Fee Receipt</title>
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
                        <p class="text-lg font-bold text-gray-900"><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Payment Date</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo $payment_date; ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Appointment Date</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo date('F j, Y', strtotime($appointment['appointment_date'])); ?></p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Appointment Time</p>
                        <p class="text-lg font-bold text-gray-900"><?php echo date('g:i A', strtotime($appointment['time_slot'])); ?></p>
                    </div>
                    <div class="col-span-2">
                        <p class="text-xs text-gray-500 uppercase font-semibold mb-1">Doctor</p>
                        <p class="text-lg font-bold text-gray-900">Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></p>
                    </div>
                </div>

                <!-- Services -->
                <div class="mb-8">
                    <h3 class="text-sm font-bold text-gray-700 uppercase mb-4">Selected Services</h3>
                    <div class="bg-purple-50 rounded-lg p-4">
                        <p class="text-gray-800"><?php echo htmlspecialchars($appointment['services'] ?: 'Services to be determined'); ?></p>
                        <?php if ($appointment['total_duration']): ?>
                        <p class="text-sm text-gray-600 mt-2">Estimated Duration: <?php echo $appointment['total_duration']; ?> minutes</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Breakdown -->
                <div class="bg-gray-50 rounded-xl p-6 mb-8">
                    <h3 class="text-sm font-bold text-gray-700 uppercase mb-4">Payment Breakdown</h3>
                    
                    <?php
                    $duration = (int)$appointment['total_duration'];
                    $baseFee = 200.00;
                    $overtimeFee = 50.00;
                    $overtimeInterval = 15;
                    
                    $fee = $baseFee;
                    $overtimeCharge = 0;
                    
                    if ($duration > 30) {
                        $overtimeMinutes = $duration - 30;
                        $overtimeIntervals = ceil($overtimeMinutes / $overtimeInterval);
                        $overtimeCharge = $overtimeFee * $overtimeIntervals;
                        $fee += $overtimeCharge;
                    }
                    ?>
                    
                    <div class="space-y-3">
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Base Appointment Fee (First 30 min)</span>
                            <span class="font-semibold text-gray-900">₱<?php echo number_format($baseFee, 2); ?></span>
                        </div>
                        
                        <?php if ($overtimeCharge > 0): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Overtime Fee (<?php echo $duration - 30; ?> min @ ₱<?php echo $overtimeFee; ?>/15min)</span>
                            <span class="font-semibold text-gray-900">₱<?php echo number_format($overtimeCharge, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <?php 
                        $totalCalculatedFee = $fee;
                        $totalPaid = floatval($appointment['appointment_fee_paid']);
                        $advancePayment = $totalPaid - $totalCalculatedFee;
                        ?>
                        
                        <?php if ($advancePayment > 0.01): ?>
                        <div class="border-t border-gray-200 pt-2">
                            <div class="flex justify-between items-center">
                                <span class="text-gray-700 font-semibold">Subtotal (Appointment Fee)</span>
                                <span class="font-semibold text-gray-900">₱<?php echo number_format($totalCalculatedFee, 2); ?></span>
                            </div>
                        </div>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-700">Advance Payment (Applied to Services)</span>
                            <span class="font-semibold text-green-600">₱<?php echo number_format($advancePayment, 2); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="border-t-2 border-gray-300 pt-3 mt-3">
                            <div class="flex justify-between items-center">
                                <span class="text-lg font-bold text-gray-900">TOTAL PAID</span>
                                <span class="text-2xl font-black text-purple-600">₱<?php echo number_format($totalPaid, 2); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Important Notes -->
                <div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded">
                    <h4 class="font-bold text-amber-900 mb-2">📌 Important Notes:</h4>
                    <ul class="text-sm text-amber-800 space-y-1 list-disc list-inside">
                        <li>This receipt confirms your appointment fee payment</li>
                        <li>Please arrive 10 minutes before your scheduled time</li>
                        <li>Late arrivals beyond 15 minutes may result in appointment cancellation</li>
                        <li>Appointment fee is non-refundable for no-shows</li>
                        <li>Medical services will be billed separately after your appointment</li>
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
                filename: 'Appointment_Receipt_<?php echo $receipt_number; ?>.pdf',
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
