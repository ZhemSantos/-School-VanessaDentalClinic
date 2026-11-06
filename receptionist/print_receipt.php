<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role
requireRole('Receptionist');

// Get invoice ID
$invoice_id = isset($_GET['invoice_id']) ? intval($_GET['invoice_id']) : 0;

if ($invoice_id <= 0) {
    die('Invalid invoice ID');
}

// Fetch invoice details
try {
    // Polymorphic Query to handle Medical, Appointment Fee, and Installment Invoices
    $stmt = $pdo->prepare("
        SELECT i.*, 
               -- Determine Patient Details
               COALESCE(p_mr.patient_id, p_app.patient_id, p_inst.patient_id) as patient_id,
               COALESCE(p_mr.first_name, p_app.first_name, p_inst.first_name) as first_name,
               COALESCE(p_mr.last_name, p_app.last_name, p_inst.last_name) as last_name,
               COALESCE(p_mr.phone_number, p_app.phone_number, p_inst.phone_number) as phone_number,
               COALESCE(p_mr.email, p_app.email, p_inst.email) as email,
               COALESCE(p_mr.address, p_app.address, p_inst.address) as address,
               
               -- Determine Invoice Type Context
               mr.record_id, mr.date_of_treatment, mr.diagnosis,
               s_mr.service_name as medical_service_name,
               
               a.appointment_id, a.appointment_date, a.time_slot, a.appointment_fee_paid as appt_fee_record,
               u_app.full_name as appointment_doctor,
               
               inst.installment_id, inst.months_paid, inst.months_total, inst.balance_remaining,
               s_inst.service_name as installment_service_name,
               
               -- Fallback Doctor
               COALESCE(u_mr.full_name, u_app.full_name) as doctor_name

        FROM tbl_invoice i
        -- 1. Medical Record Link
        LEFT JOIN tbl_medical_record mr ON i.record_id = mr.record_id
        LEFT JOIN tbl_patient p_mr ON mr.patient_id = p_mr.patient_id
        LEFT JOIN tbl_service s_mr ON mr.service_id = s_mr.service_id
        LEFT JOIN tbl_user u_mr ON mr.doctor_id = u_mr.user_id
        
        -- 2. Appointment Link (For Fees or linked to medical record)
        LEFT JOIN tbl_appointment a ON (i.appointment_id = a.appointment_id OR mr.appointment_id = a.appointment_id)
        LEFT JOIN tbl_patient p_app ON a.patient_id = p_app.patient_id
        LEFT JOIN tbl_user u_app ON a.doctor_id = u_app.user_id
        
        -- 3. Installment Link
        LEFT JOIN tbl_installment inst ON i.installment_id = inst.installment_id
        LEFT JOIN tbl_patient p_inst ON inst.patient_id = p_inst.patient_id
        LEFT JOIN tbl_service s_inst ON inst.service_id = s_inst.service_id
        
        WHERE i.invoice_id = ?
        LIMIT 1
    ");
    $stmt->execute([$invoice_id]);
    $invoice = $stmt->fetch();
    
    if (!$invoice) {
        die('Invoice not found');
    }

    // Determine Receipt Type & Prepare Data
    $receipt_type = 'Medical';
    $primary_service_name = 'Service';
    
    if (!empty($invoice['installment_id'])) {
        $receipt_type = 'Installment';
        $primary_service_name = $invoice['installment_service_name'];
    } elseif (empty($invoice['record_id']) && !empty($invoice['appointment_id'])) {
        $receipt_type = 'Appointment Fee';
    }

    // Fetch Services Breakdown / Details based on Type
    $services_breakdown = [];
    
    if ($receipt_type === 'Medical') {
        // ... Existing Medical Logic ...
        if (!empty($invoice['appointment_id'])) {
            try {
                $svc_stmt = $pdo->prepare("
                    SELECT s.service_name, tas.price_at_booking
                    FROM tbl_appointment_service tas
                    JOIN tbl_service s ON tas.service_id = s.service_id
                    WHERE tas.appointment_id = ?
                ");
                $svc_stmt->execute([$invoice['appointment_id']]);
                $services_breakdown = $svc_stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) {}
        }
        
        // Fallback for Legacy Medical Data
        if (empty($services_breakdown)) {
            $services_breakdown[] = [
                'service_name' => $invoice['medical_service_name'],
                'price_at_booking' => $invoice['total_amount'] + ($invoice['discount_amount'] ?? 0) // Approximation
            ];
        }
    } elseif ($receipt_type === 'Installment') {
        // Installment Details
        $services_breakdown[] = [
            'service_name' => $invoice['installment_service_name'] . ' (Installment Payment)',
            'price_at_booking' => $invoice['total_amount']
        ];
        // Add progress info as a "service" line (hacky but works for display)
        $prog_str = "Month " . ($invoice['months_paid'] + 1) . " of " . $invoice['months_total'];
        // We can display this in the body instead of here, or append it to name
    } elseif ($receipt_type === 'Appointment Fee') {
         // Appointment Fee Details
         $services_breakdown[] = [
            'service_name' => 'Appointment Fee',
            'price_at_booking' => 200.00 // Base Fee
         ];
         // Check for Overtime
         if ($invoice['total_amount'] > 200) {
             $ot_amount = $invoice['total_amount'] - 200;
             $services_breakdown[] = [
                'service_name' => 'Overtime Charge',
                'price_at_booking' => $ot_amount
             ];
         }
    }

    // Fetch Advance Payment (Separate Invoice for same appointment)
    $advance_payment = 0;
    if ($receipt_type === 'Medical' && !empty($invoice['appointment_id'])) {
        try {
            $adv_stmt = $pdo->prepare("
                SELECT amount_paid FROM tbl_invoice 
                WHERE appointment_id = ? 
                  AND invoice_id != ? 
                  AND payment_status IN ('Paid', 'Pending')
                LIMIT 1
            ");
            $adv_stmt->execute([$invoice['appointment_id'], $invoice_id]);
            $adv_inv = $adv_stmt->fetch();
            if ($adv_inv) {
                $advance_payment = floatval($adv_inv['amount_paid']);
            }
        } catch (Exception $e) {}
    }

} catch (PDOException $e) {
    error_log('Error fetching invoice: ' . $e->getMessage());
    die('Error loading invoice: ' . $e->getMessage());
}

// Format data for Display
$invoice_id_display = $invoice['reference_number'] ?: 'INV-' . str_pad($invoice['invoice_id'], 4, '0', STR_PAD_LEFT);
$patient_name = $invoice['first_name'] . ' ' . $invoice['last_name'];
$patient_id_display = 'P-' . str_pad($invoice['patient_id'], 4, '0', STR_PAD_LEFT);

// Date Handling
$date_source = $invoice['date_of_treatment'] ?? $invoice['appointment_date'] ?? $invoice['created_at'];
$date_obj = new DateTime($date_source);
$date_formatted = $date_obj->format('F j, Y g:i A');

$paid_at_formatted = '';
if (!empty($invoice['paid_at'])) {
    $paid_at_obj = new DateTime($invoice['paid_at']);
    $paid_at_formatted = $paid_at_obj->format('F j, Y g:i A');
} else {
    $paid_at_formatted = $date_formatted;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $receipt_type; ?> Receipt - <?php echo htmlspecialchars($invoice_id_display); ?></title>
    <style>
        /* ... Existing Styles ... */
        @media print {
            @page { size: A4; margin: 1cm; }
            body { margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .print-break { page-break-after: always; }
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body { font-family: 'Arial', sans-serif; background: #f5f5f5; padding: 20px; color: #333; }
        
        .receipt-container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; box-shadow: 0 0 20px rgba(0,0,0,0.1); }
        .header { text-align: center; border-bottom: 3px solid #6A1B9A; padding-bottom: 20px; margin-bottom: 30px; }
        .clinic-name { font-size: 28px; font-weight: bold; color: #6A1B9A; margin-bottom: 5px; }
        .clinic-subtitle { font-size: 14px; color: #666; margin-bottom: 10px; }
        .receipt-title { font-size: 20px; font-weight: bold; color: #333; margin-top: 10px; text-transform: uppercase; }
        
        .receipt-info { display: flex; justify-content: space-between; margin-bottom: 30px; padding: 15px; background: #f9f9f9; border-radius: 5px; }
        .info-section { flex: 1; }
        .info-label { font-size: 11px; color: #666; text-transform: uppercase; margin-bottom: 5px; }
        .info-value { font-size: 14px; font-weight: bold; color: #333; }
        
        .section { margin-bottom: 25px; }
        .section-title { font-size: 14px; font-weight: bold; color: #6A1B9A; margin-bottom: 10px; text-transform: uppercase; border-bottom: 1px solid #eee; padding-bottom: 5px; }
        
        .patient-info, .service-info { padding: 15px; background: #f9f9f9; border-radius: 5px; margin-bottom: 15px; }
        .info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 13px; }
        .info-row:last-child { margin-bottom: 0; }
        .info-row-label { color: #666; font-weight: normal; }
        .info-row-value { color: #333; font-weight: bold; }
        
        .amount-section { margin-top: 30px; padding-top: 20px; border-top: 2px solid #6A1B9A; }
        .amount-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 14px; }
        .amount-row.total { font-size: 20px; font-weight: bold; color: #6A1B9A; margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd; }
        
        .footer { margin-top: 40px; text-align: center; padding-top: 20px; border-top: 1px solid #eee; font-size: 11px; color: #666; }
        .print-button { position: fixed; top: 20px; right: 20px; padding: 12px 24px; background: #6A1B9A; color: white; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; box-shadow: 0 2px 10px rgba(0,0,0,0.2); z-index: 1000; }
        .print-button:hover { background: #8a11d4; }
        
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
        .status-paid { background: #d4edda; color: #155724; }
        .status-pending { background: #fff3cd; color: #856404; }
    </style>
</head>
<body>
    <button class="print-button no-print" onclick="window.print()">Print Receipt</button>
    
    <div class="receipt-container">
        <div class="header">
            <div class="clinic-name">Vanessa Nicolas Dental Clinic</div>
            <div class="clinic-subtitle">Professional Dental Care Services</div>
            <div class="receipt-title"><?php echo $receipt_type; ?> RECEIPT</div>
        </div>
        
        <div class="receipt-info">
            <div class="info-section">
                <div class="info-label">Receipt Number</div>
                <div class="info-value"><?php echo htmlspecialchars($invoice_id_display); ?></div>
            </div>
            <div class="info-section">
                <div class="info-label">Date</div>
                <div class="info-value"><?php echo htmlspecialchars($paid_at_formatted); ?></div>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Patient Information</div>
            <div class="patient-info">
                <div class="info-row">
                    <span class="info-row-label">Name:</span>
                    <span class="info-row-value"><?php echo htmlspecialchars($patient_name); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-row-label">Patient ID:</span>
                    <span class="info-row-value"><?php echo htmlspecialchars($patient_id_display); ?></span>
                </div>
                <?php if (!empty($invoice['phone_number'])): ?>
                <div class="info-row">
                    <span class="info-row-label">Phone:</span>
                    <span class="info-row-value"><?php echo htmlspecialchars($invoice['phone_number']); ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($invoice['email'])): ?>
                <div class="info-row">
                    <span class="info-row-label">Email:</span>
                    <span class="info-row-value"><?php echo htmlspecialchars($invoice['email']); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="section">
            <div class="section-title">Service Details</div>
            <div class="service-info">
                <?php foreach ($services_breakdown as $service): ?>
                <div class="info-row" style="margin-bottom: 2px;">
                    <span class="info-row-label">- <?php echo htmlspecialchars($service['service_name']); ?></span>
                    <span class="info-row-value">₱<?php echo number_format($service['price_at_booking'], 2); ?></span>
                </div>
                <?php endforeach; ?>
                
                <hr style="margin: 10px 0; border: 0; border-top: 1px dashed #ddd;">
                
                <?php if ($receipt_type === 'Medical' || $receipt_type === 'Appointment Fee'): ?>
                <div class="info-row">
                    <span class="info-row-label"><?php echo $receipt_type === 'Medical' ? 'Treatment Date:' : 'Appointment Date:'; ?></span>
                    <span class="info-row-value"><?php echo htmlspecialchars($date_formatted); ?></span>
                </div>
                
                <?php 
                    $doctor_to_show = $invoice['doctor_name']; // Already coalesced in SQL
                    if (!empty($doctor_to_show)): 
                ?>
                <div class="info-row">
                    <span class="info-row-label">Doctor:</span>
                    <span class="info-row-value">Dr. <?php echo htmlspecialchars($doctor_to_show); ?></span>
                </div>
                <?php endif; ?>
                
                <?php if ($receipt_type === 'Medical' && !empty($invoice['diagnosis'])): ?>
                <div class="info-row">
                    <span class="info-row-label">Diagnosis:</span>
                    <span class="info-row-value"><?php echo htmlspecialchars($invoice['diagnosis']); ?></span>
                </div>
                <?php endif; ?>
                
                <?php elseif ($receipt_type === 'Installment'): ?>
                <div class="info-row">
                    <span class="info-row-label">Payment For:</span>
                    <span class="info-row-value"><?php echo htmlspecialchars($invoice['installment_service_name']); ?> Installment</span>
                </div>
                <div class="info-row">
                    <span class="info-row-label">Progress:</span>
                    <span class="info-row-value">Month <?php echo $invoice['months_paid'] + 1; ?> of <?php echo $invoice['months_total']; ?></span>
                </div>
                <!-- Calculate Remaining Balance after this payment -->
                <?php 
                    // balance_remaining in DB is current. If status is paid, it might be updated already.
                    // Let's just show the current DB balance.
                ?>
                <div class="info-row">
                    <span class="info-row-label">Remaining Balance:</span>
                    <span class="info-row-value">₱<?php echo number_format($invoice['balance_remaining'], 2); ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="amount-section">
            <?php 
            $services_total = 0;
            foreach ($services_breakdown as $s) {
                $services_total += $s['price_at_booking'];
            }
            
            $discount_amount = $invoice['discount_amount'];
            $vat_exempt_base = $invoice['vat_exempt_amount'] ?? 0;
            $appointment_fee_paid = $invoice['appt_fee_record'] ?? 0;
            $grand_total = $invoice['total_amount']; // This is what was PAID
            
            // LOGIC SPLIT PER TYPE
            if ($receipt_type === 'Medical') {
                // Correct Subtotal: Sum of Services (Gross)
                $subtotal_display = $services_total;
                
                // VAT Deduction
                $vat_deduction = 0;
                if ($vat_exempt_base > 0) {
                    $vat_deduction = $subtotal_display - $vat_exempt_base;
                }
                
                // Calculate Net Due (Grand Total)
                // Total (Gross) - VAT Ded - Discount - Fee
                // Note: Advance Payment is part of "Amount Paid", not a deduction from the Bill value itself usually.
                // But user wants to see it deducted?
                // If we deduct Advance, then "Amount Due" becomes "Remaining Payable".
                // If we treat Advance as Payment, it's in the Paid section.
                // Let's stick to standard Receipt:
                // Total Due = Net of Fee.
                // Paid = Advance + Final.
                
                $grand_total = $subtotal_display - $vat_deduction - ($discount_amount ?? 0) - $appointment_fee_paid - $advance_payment;
                
            } else {
                // For Installment and Appt Fee, Subtotal is just the Total
                $subtotal_display = $grand_total;
                $vat_deduction = 0; 
                $appointment_fee_paid = 0; // Don't deduct fee from fee receipt
            }
            ?>
            
            <div class="amount-row">
                <span>Subtotal:</span>
                <span>₱<?php echo number_format($subtotal_display, 2); ?></span>
            </div>
            
            <?php if ($vat_deduction > 0): ?>
            <div class="amount-row" style="color: #666;">
                <span>Less: VAT (12%):</span>
                <span>-₱<?php echo number_format($vat_deduction, 2); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($discount_amount > 0): ?>
            <div class="amount-row" style="color: #155724;">
                <span>Less: Discount:</span>
                <span>-₱<?php echo number_format($discount_amount, 2); ?></span>
            </div>
            <?php endif; ?>
            
            <?php if ($receipt_type === 'Medical' && $appointment_fee_paid > 0): ?>
            <div class="amount-row" style="color: #0d47a1;">
                <span>Less: Appointment Fee:</span>
                <span>-₱<?php echo number_format($appointment_fee_paid, 2); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($advance_payment > 0): ?>
            <div class="amount-row" style="color: #0d47a1;">
                <span>Less: Advance Payment:</span>
                <span>-₱<?php echo number_format($advance_payment, 2); ?></span>
            </div>
            <?php endif; ?>
            
            <div class="amount-row total">
                <span>Net Amount Due:</span>
                <span>₱<?php echo number_format($grand_total, 2); ?></span>
            </div>
            
            
            <!-- Amount Paid removed as per request. Net Amount Due reflects payment required/made at counter. -->
        </div>
        
        <div class="section">
            <div class="section-title">Payment Information</div>
            <div class="service-info">
                <div class="info-row">
                    <span class="info-row-label">Payment Method:</span>
                    <span class="info-row-value"><?php echo htmlspecialchars($invoice['payment_method'] ?? 'N/A'); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-row-label">Payment Status:</span>
                    <span class="info-row-value">
                        <span class="status-badge <?php echo $invoice['payment_status'] === 'Paid' ? 'status-paid' : 'status-pending'; ?>">
                            <?php echo htmlspecialchars($invoice['payment_status']); ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>
        
        <div class="footer">
            <p>Thank you for choosing Vanessa Nicolas Dental Clinic!</p>
            <p style="margin-top: 5px;">This is a computer-generated receipt. No signature required.</p>
            <p style="margin-top: 10px; font-size: 10px;">Generated on <?php echo date('F j, Y \a\t g:i A'); ?></p>
        </div>
    </div>
    
    <script>
        // Auto-print on load (optional - can be removed if you want manual print)
        // window.onload = function() {
        //     window.print();
        // };
    </script>
</body>
</html>

