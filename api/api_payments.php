<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ==========================================
// MIGRATION: Ensure Patient Discount Columns Exist
// ==========================================
try {
    $col_check = $pdo->query("SHOW COLUMNS FROM tbl_patient LIKE 'discount_type'");
    if ($col_check->rowCount() == 0) {
        $pdo->exec("
            ALTER TABLE tbl_patient 
            ADD COLUMN discount_type ENUM('None', 'PWD', 'Senior') DEFAULT 'None',
            ADD COLUMN discount_id_number VARCHAR(100) NULL,
            ADD COLUMN discount_id_issuer VARCHAR(100) NULL,
            ADD COLUMN discount_id_image_path VARCHAR(255) NULL,
            ADD COLUMN discount_id_expiry DATE NULL,
            ADD COLUMN discount_verified_by INT NULL,
            ADD COLUMN discount_verified_at TIMESTAMP NULL
        ");
    }
} catch (Exception $e) {
    // Ignore migration errors (likely already exists)
}

$action = $_GET['action'] ?? '';
$patient_id = $_SESSION['patient_id'];

try {
    switch ($action) {
        case 'unpaid':
            getUnpaidItems($pdo, $patient_id);
            break;
        case 'pay':
            processPayment($pdo, $patient_id);
            break;
        case 'get_payment_history':
            getPaymentHistory($pdo, $patient_id);
            break;
        default:
             echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function getUnpaidItems($pdo, $patient_id) {
    // 0. Fetch Verified Discount Profile
    $discStmt = $pdo->prepare("SELECT discount_type, discount_id_number, discount_id_issuer, discount_id_expiry, discount_verified_at FROM tbl_patient WHERE patient_id = ?");
    $discStmt->execute([$patient_id]);
    $discountProfile = $discStmt->fetch(PDO::FETCH_ASSOC);
    
    // Validate Expiry for PWD
    if ($discountProfile) {
        $discountProfile['has_valid_discount'] = false;
        
        if (in_array($discountProfile['discount_type'], ['PWD', 'Senior'])) {
            $discountProfile['has_valid_discount'] = true;
            
            // Check Expiry
            if (!empty($discountProfile['discount_id_expiry']) && strtotime($discountProfile['discount_id_expiry']) < time()) {
                 $discountProfile['discount_type'] = 'None';
                 $discountProfile['is_expired'] = true;
                 $discountProfile['has_valid_discount'] = false;
            }
        }
    } else {
        $discountProfile = ['has_valid_discount' => false];
    }
    
    // 1. Appointment Fees (Status Booked/Pending, fee not paid)
    // Calculate fee based on total service duration with overtime charges
    $apptStmt = $pdo->prepare("
        SELECT a.appointment_id, a.appointment_date, a.time_slot, 
               u.full_name as doctor_name,
                COALESCE(
                   (SELECT SUM(s.estimated_duration_mins) 
                    FROM tbl_appointment_service tas 
                    JOIN tbl_service s ON tas.service_id = s.service_id 
                    WHERE tas.appointment_id = a.appointment_id), 
                   30
               ) as total_duration_mins,
               COALESCE(
                   (SELECT SUM(tas.price_at_booking) 
                    FROM tbl_appointment_service tas 
                    WHERE tas.appointment_id = a.appointment_id),
                   0
               ) as total_service_amount,
               COALESCE(
                   (SELECT GROUP_CONCAT(s.service_name SEPARATOR ', ')
                    FROM tbl_appointment_service tas 
                    JOIN tbl_service s ON tas.service_id = s.service_id 
                    WHERE tas.appointment_id = a.appointment_id),
                   'General Consultation'
               ) as service_names,
               (SELECT setting_value FROM tbl_settings WHERE setting_key = 'appointment_fee') as base_fee,
               (SELECT setting_value FROM tbl_settings WHERE setting_key = 'appointment_overtime_fee') as overtime_fee,
               (SELECT setting_value FROM tbl_settings WHERE setting_key = 'appointment_overtime_interval_mins') as overtime_interval
        FROM tbl_appointment a
        JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE a.patient_id = ? 
          AND a.status IN ('Booked', 'Pending') 
          AND (a.appointment_fee_paid IS NULL OR a.appointment_fee_paid = 0)
    ");
    $apptStmt->execute([$patient_id]);
    $apptFees = $apptStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate the actual fee for each appointment based on duration
    foreach ($apptFees as &$appt) {
        $duration = (int)$appt['total_duration_mins'];
        $baseFee = (float)$appt['base_fee'];
        $overtimeFee = (float)$appt['overtime_fee'];
        $overtimeInterval = (int)$appt['overtime_interval'];
        
        // Base fee covers first 30 minutes
        $fee = $baseFee;
        
        // Calculate overtime if duration exceeds 30 minutes
        if ($duration > 30) {
            $overtimeMinutes = $duration - 30;
            $overtimeIntervals = ceil($overtimeMinutes / $overtimeInterval);
            $fee += ($overtimeFee * $overtimeIntervals);
        }
        
        $appt['fee'] = number_format($fee, 2, '.', '');
        $appt['fee_numeric'] = $fee; // Keep numeric version for max calculation
        
        // Clean up temporary fields
        unset($appt['base_fee'], $appt['overtime_fee'], $appt['overtime_interval']);
    }

    // 2. Unpaid/Partial Invoices
    $invStmt = $pdo->prepare("
        SELECT i.invoice_id, i.reference_number, 
               COALESCE(SUM(tas.price_at_booking), i.total_amount) as total_amount, 
               i.discount_amount, i.vat_exempt_amount,
               i.amount_paid, i.payment_status, i.created_at, 'Medical Invoice' as type,
               u.full_name as doctor_name, a.appointment_fee_paid,
               GROUP_CONCAT(DISTINCT s.service_name SEPARATOR ', ') as service_names
        FROM tbl_invoice i 
        LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE EXISTS (SELECT 1 FROM tbl_appointment a2 WHERE a2.appointment_id = i.appointment_id AND a2.patient_id = ?)
          AND i.payment_status != 'Paid'
          AND (a.status IS NULL OR a.status != 'Cancelled')
        GROUP BY i.invoice_id
    ");
    // Only fetch invoices linked to patient's appointments
    $invStmt->execute([$patient_id]);
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Active Installments
    $instStmt = $pdo->prepare("
        SELECT i.installment_id, i.service_id, i.monthly_amount, i.next_payment_date, i.balance_remaining,
               i.penalty_accumulated, i.penalty_paid,
               s.service_name,
               u.full_name as doctor_name
        FROM tbl_installment i
        JOIN tbl_service s ON i.service_id = s.service_id
        LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
        WHERE i.patient_id = ? AND i.status = 'Active'
    ");
    $instStmt->execute([$patient_id]);
    $installments = $instStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'appointment_fees' => $apptFees,
        'invoices' => $invoices,
        'installments' => $installments,
        'patient_discount_profile' => $discountProfile
    ]);
}

function processPayment($pdo, $patient_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    $input = json_decode(file_get_contents('php://input'), true);
    
    $type = $input['type'] ?? ''; // 'invoice', 'installment', 'appointment_fee'
    $item_id = $input['id'] ?? 0;
    $amount = (float)($input['amount'] ?? 0);
    $reference = $input['gcash_reference'] ?? '';
    
    if (empty($reference)) throw new Exception('GCash reference number required');
    if ($amount <= 0) throw new Exception('Invalid amount');

    // Basic Ref validation
    if (!preg_match('/^[0-9]{10,}$/', $reference) && strlen($reference) < 8) {
         throw new Exception('Invalid GCash reference number format');
    }

    $pdo->beginTransaction();

    // FETCH PATIENT DISCOUNT INFO
    $discStmt = $pdo->prepare("SELECT discount_type, discount_id_number, discount_id_issuer, discount_id_image_path FROM tbl_patient WHERE patient_id = ?");
    $discStmt->execute([$patient_id]);
    $discountProfile = $discStmt->fetch(PDO::FETCH_ASSOC);
    
    // DISCOUNTS ONLY APPLY TO 'invoice' TYPE usually (Medical Procedures)
    // Appointment fees are generally standard, unless specified. For now sticking to Invoice.

    if ($type === 'invoice') {
        // ... (Invoice Logic) ...
        $chk = $pdo->prepare("
            SELECT i.invoice_id, i.record_id, i.total_amount, i.amount_paid, 
                   COUNT(*) as count 
            FROM tbl_invoice i 
            JOIN tbl_appointment a ON i.appointment_id = a.appointment_id 
            WHERE i.invoice_id = ? AND a.patient_id = ?
        ");
        $chk->execute([$item_id, $patient_id]);
        $inv = $chk->fetch();
        
        if (!$inv || $inv['count'] == 0) {
            throw new Exception('Invoice not found or access denied');
        }
        
        // Calculate Expected Amount
        $total_bill = (float)$inv['total_amount'];
        $paid_so_far = (float)$inv['amount_paid'];
        $remaining = $total_bill - $paid_so_far; // Standard
        
        $discount_amount = 0;
        $vat_exempt_amount = 0;
        $apply_discount = false;
        
        // Auto-Apply Logic: If patient has Verified PWD/Senior profile
        if (isset($discountProfile['discount_type']) && in_array($discountProfile['discount_type'], ['PWD', 'Senior'])) {
            // Recalculate Logic
            // Formula: VAT Exempt = Total / 1.12
            // Discount = VAT Exempt * 0.20
            // Net = VAT Exempt - Discount
            
            $vat_exempt_amount = $total_bill / 1.12; 
            $discount_amount = $vat_exempt_amount * 0.20;
            $discounted_total = $vat_exempt_amount - $discount_amount;
            
            $remaining_discounted = max(0, $discounted_total - $paid_so_far);
            
            // Allow payment of EITHER standard remaining OR discounted remaining
            // Since we want to update the invoice to reflect discount, if the user pays the discounted amount, we assume they used it.
            
            // Tolerance logic
            if (abs($amount - $remaining_discounted) < 1.00) { 
                $apply_discount = true;
            }
        }
        
        // Perform Update
        // FIX: Use PHP date() for consistent timezone
        $sql = "UPDATE tbl_invoice SET payment_method = 'GCash', payment_reference = ?, payment_status = 'Pending', paid_at = ?, amount_paid = amount_paid + ?";
        $params = [$reference, date('Y-m-d H:i:s'), $amount];
        
        if ($apply_discount) {
            $sql .= ", discount_amount = ?, vat_exempt_amount = ?";
            $params[] = $discount_amount;
            $params[] = $vat_exempt_amount;
        }
        
        $sql .= " WHERE invoice_id = ?";
        $params[] = $item_id;
        
        $upd = $pdo->prepare($sql);
        $upd->execute($params);
        
        // If discount applied, record it in tbl_invoice_discount
        if ($apply_discount) {
            // Check if exists
            $chkDisc = $pdo->prepare("SELECT discount_id FROM tbl_invoice_discount WHERE invoice_id = ?");
            $chkDisc->execute([$item_id]);
            if ($chkDisc->rowCount() == 0) {
                $insDisc = $pdo->prepare("
                    INSERT INTO tbl_invoice_discount 
                    (invoice_id, discount_type, id_number, id_issuer, id_image_path, date_verified) 
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                $insDisc->execute([
                    $item_id,
                    $discountProfile['discount_type'],
                    $discountProfile['discount_id_number'],
                    $discountProfile['discount_id_issuer'],
                    $discountProfile['discount_id_image_path'] ?? ''
                ]);
            }
        }

    } elseif ($type === 'appointment_fee') {
        // Pay appointment fee / Advance Payment
        // 1. Verify appointment and calculate actual fee
        $chk = $pdo->prepare("
            SELECT a.appointment_fee_paid, a.status,
                   COALESCE((SELECT SUM(tas.price_at_booking) FROM tbl_appointment_service tas WHERE tas.appointment_id = a.appointment_id), 0) as total_service_cost,
                   COALESCE((SELECT SUM(s.estimated_duration_mins) FROM tbl_appointment_service tas JOIN tbl_service s ON tas.service_id = s.service_id WHERE tas.appointment_id = a.appointment_id), 30) as total_duration_mins,
                   (SELECT setting_value FROM tbl_settings WHERE setting_key = 'appointment_fee') as base_fee,
                   (SELECT setting_value FROM tbl_settings WHERE setting_key = 'appointment_overtime_fee') as overtime_fee,
                   (SELECT setting_value FROM tbl_settings WHERE setting_key = 'appointment_overtime_interval_mins') as overtime_interval
            FROM tbl_appointment a
            WHERE a.appointment_id = ? AND a.patient_id = ?
        ");
        $chk->execute([$item_id, $patient_id]);
        $appt = $chk->fetch();
        if (!$appt) throw new Exception('Appointment not found');
        if ($appt['appointment_fee_paid'] > 0) throw new Exception('Fee already paid');

        // Calculate the actual appointment fee
        $duration = (int)$appt['total_duration_mins'];
        $baseFee = (float)$appt['base_fee'];
        $overtimeFee = (float)$appt['overtime_fee'];
        $overtimeInterval = (int)$appt['overtime_interval'];
        
        $calculatedFee = $baseFee;
        if ($duration > 30) {
            $overtimeMinutes = $duration - 30;
            $overtimeIntervals = ceil($overtimeMinutes / $overtimeInterval);
            $calculatedFee += ($overtimeFee * $overtimeIntervals);
        }

        // Validate Amount: Must match calculated fee exactly (No advance payment on fees)
        if (abs($amount - $calculatedFee) > 0.01) {
             throw new Exception('Payment amount must be exactly ₱' . number_format($calculatedFee, 2));
        }

        // 2. Update appointment to Booked
        $updAppt = $pdo->prepare("
            UPDATE tbl_appointment 
            SET appointment_fee_paid = ?, 
                status = 'Booked'
            WHERE appointment_id = ?
        ");
        $updAppt->execute([$amount, $item_id]);
        
        // 3. Cancel conflicting appointments (same doctor, date, time but different appointment_id)
        // Get the doctor, date, and time from the booked appointment
        $getAppointmentInfo = $pdo->prepare("
            SELECT doctor_id, appointment_date, time_slot 
            FROM tbl_appointment 
            WHERE appointment_id = ?
        ");
        $getAppointmentInfo->execute([$item_id]);
        $apptInfo = $getAppointmentInfo->fetch();
        
        if ($apptInfo) {
            // Cancel all other PENDING appointments with same doctor/date/time
            $cancelConflicts = $pdo->prepare("
                UPDATE tbl_appointment 
                SET status = 'Cancelled'
                WHERE doctor_id = ? 
                  AND appointment_date = ? 
                  AND time_slot = ?
                  AND appointment_id != ?
                  AND status = 'Pending'
            ");
            $cancelConflicts->execute([
                $apptInfo['doctor_id'],
                $apptInfo['appointment_date'],
                $apptInfo['time_slot'],
                $item_id
            ]);
        }
        
        // 4. AUTOMATICALLY CREATE MEDICAL INVOICE (For Advance Payment)
        // Check if invoice exists
        $checkInv = $pdo->prepare("SELECT invoice_id FROM tbl_invoice WHERE appointment_id = ?");
        $checkInv->execute([$item_id]);
        if ($checkInv->rowCount() == 0) {
            $totalServiceCost = (float)$appt['total_service_cost'];
            if ($totalServiceCost > 0) {
                // Determine if discount applies to the Advance Invoice immediately?
                // For now, create standard invoice. Discount applied at final payment.
                
                $refNum = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));
                $insInv = $pdo->prepare("
                    INSERT INTO tbl_invoice (
                        appointment_id, 
                        reference_number, 
                        total_amount, 
                        amount_paid, 
                        payment_status, 
                        created_at
                    ) VALUES (?, ?, ?, 0.00, 'Unpaid', ?)
                ");
                $insInv->execute([$item_id, $refNum, $totalServiceCost, date('Y-m-d H:i:s')]);
            }
        }
        
        $pdo->commit(); // COMMIT BEFORE RETURN

        echo json_encode([
            'success' => true, 
            'message' => 'Payment submitted successfully',
            'appointment_id' => $item_id
        ]);
        return;

    } elseif ($type === 'installment') {
        // Installment Payment Processing
        $instChk = $pdo->prepare("SELECT patient_id, balance_remaining, monthly_amount, penalty_accumulated, penalty_paid, months_paid, months_total, next_payment_date FROM tbl_installment WHERE installment_id = ?");
        $instChk->execute([$item_id]);
        $inst = $instChk->fetch();
        
        if (!$inst || $inst['patient_id'] != $patient_id) throw new Exception('Installment not found');

        // Calculate Max Payment (Balance + Unpaid Penalties)
        $penalty_due = max(0, ($inst['penalty_accumulated'] ?? 0) - ($inst['penalty_paid'] ?? 0));
        $total_outstanding = $inst['balance_remaining'] + $penalty_due;

        if ($amount > ($total_outstanding + 0.01)) {
            throw new Exception('Payment exceeds total outstanding balance (₱' . number_format($total_outstanding, 2) . ')');
        }

        // Create invoice for this installment payment
        // FIX: Set status to 'Paid' immediately because we successfully reduced the balance above.
        // Also record amount_paid.
        // FIX: Use PHP date() instead of NOW() to ensure correct timezone
        $currentDateTime = date('Y-m-d H:i:s');
        $invRef = 'INST-' . date('Ymd') . '-' . $item_id . '-' . time();
        $insInv = $pdo->prepare("INSERT INTO tbl_invoice (reference_number, total_amount, amount_paid, payment_method, payment_reference, payment_status, paid_at, installment_id) VALUES (?, ?, ?, 'GCash', ?, 'Paid', ?, ?)");
        $insInv->execute([$invRef, $amount, $amount, $reference, $currentDateTime, $item_id]);
        
        // Update installment record immediately (don't wait for receptionist approval)
        // Apply payment: first to penalties, then to balance
        $amountRemaining = $amount;
        $newPenaltyPaid = $inst['penalty_paid'];
        $newBalanceRemaining = $inst['balance_remaining'];
        $newMonthsPaid = $inst['months_paid'];
        
        // ... (existing logic) ...
        
        // Pay off penalties first
        if ($penalty_due > 0) {
            $penaltyPayment = min($amountRemaining, $penalty_due);
            $newPenaltyPaid += $penaltyPayment;
            $amountRemaining -= $penaltyPayment;
        }
        
        // Apply remaining amount to balance
        if ($amountRemaining > 0) {
            $newBalanceRemaining -= $amountRemaining;
            
            // Calculate how many months this payment covers
            $monthlyAmount = floatval($inst['monthly_amount']);
            if ($monthlyAmount > 0) {
                $monthsCovered = floor($amountRemaining / $monthlyAmount);
                $newMonthsPaid += max(1, $monthsCovered); // At least 1 month
            }
        }
        
        // Determine new status and next payment date
        $newStatus = 'Active';
        $nextPaymentDate = $inst['next_payment_date'];
        
        if ($newBalanceRemaining <= 0.01) {
            // Fully paid
            $newStatus = 'Completed';
            $nextPaymentDate = null;
        } else {
            // Calculate next payment date (add 1 month)
            $currentNext = new DateTime($inst['next_payment_date']);
            $currentNext->modify('+1 month');
            $nextPaymentDate = $currentNext->format('Y-m-d');
        }
        
        // Update the installment record
        $updInst = $pdo->prepare("
            UPDATE tbl_installment 
            SET balance_remaining = ?,
                penalty_paid = ?,
                months_paid = ?,
                next_payment_date = ?,
                status = ?
            WHERE installment_id = ?
        ");
        $updInst->execute([
            max(0, $newBalanceRemaining),
            $newPenaltyPaid,
            min($newMonthsPaid, $inst['months_total']),
            $nextPaymentDate,
            $newStatus,
            $item_id
        ]);
        
    } else {
        throw new Exception('Invalid payment type');
    }

    $pdo->commit();
    
    // For non-appointment-fee payments
    if ($type !== 'appointment_fee') {
        echo json_encode(['success' => true, 'message' => 'Payment processed successfully!']);
    }
}

function getPaymentHistory($pdo, $patient_id) {
    // Get optional date filters
    $start_date = $_GET['start_date'] ?? null;
    $end_date = $_GET['end_date'] ?? null;
    
    $history = [];

    // 1. Paid Appointment Fees
    $apptSql = "
        SELECT a.appointment_id, a.appointment_date, a.time_slot, a.appointment_fee_paid as amount,
               u.full_name as doctor_name,
               GROUP_CONCAT(s.service_name SEPARATOR ', ') as services,
               'Appointment Fee' as type,
               'Paid' as status,
               a.appointment_date as date_created,
               a.appointment_date as paid_at
        FROM tbl_appointment a
        JOIN tbl_user u ON a.doctor_id = u.user_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        WHERE a.patient_id = ? 
          AND a.appointment_fee_paid > 0";
    
    $params = [$patient_id];
    
    if ($start_date && $end_date) {
        $apptSql .= " AND DATE(a.appointment_date) BETWEEN ? AND ?";
        $params[] = $start_date;
        $params[] = $end_date;
    }
    
    $apptSql .= " GROUP BY a.appointment_id";
    
    $apptStmt = $pdo->prepare($apptSql);
    $apptStmt->execute($params);
    $appts = $apptStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($appts as $appt) {
        $history[] = [
            'id' => 'appt_' . $appt['appointment_id'],
            'reference_id' => $appt['appointment_id'],
            'date' => $appt['appointment_date'],
            'time' => $appt['time_slot'],
            'doctor' => $appt['doctor_name'],
            'services' => $appt['services'],
            'amount' => $appt['amount'],
            'type' => 'Appointment Fee',
            'status' => 'Paid',
            'link' => 'appointment_fee_receipt.php?appointment_id=' . $appt['appointment_id']
        ];
    }

    // 2. Paid Invoices (Medical & Installments) with enhanced details
    $invSql = "
        SELECT i.invoice_id, i.reference_number, i.total_amount, i.discount_amount, 
               i.vat_exempt_amount, i.payment_method, i.paid_at, i.installment_id,
               a.appointment_date, a.time_slot,
               u_doc.full_name as appointment_doctor,
               s_app.service_name as app_service_name,
               
               -- Installment details
               s_inst.service_name as inst_service_name,
               u_inst.full_name as inst_doctor_name,
               
               GROUP_CONCAT(DISTINCT s.service_name SEPARATOR ', ') as service_names
        FROM tbl_invoice i
        
        -- Appointment links
        LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        LEFT JOIN tbl_user u_doc ON a.doctor_id = u_doc.user_id
        LEFT JOIN tbl_appointment_service aps ON a.appointment_id = aps.appointment_id
        LEFT JOIN tbl_service s ON aps.service_id = s.service_id
        LEFT JOIN tbl_service s_app ON aps.service_id = s_app.service_id /* duplicate for safety */
        
        -- Installment links
        LEFT JOIN tbl_installment inst ON i.installment_id = inst.installment_id
        LEFT JOIN tbl_service s_inst ON inst.service_id = s_inst.service_id
        LEFT JOIN tbl_appointment a_inst ON inst.appointment_id = a_inst.appointment_id -- if linked
        LEFT JOIN tbl_user u_inst ON a_inst.doctor_id = u_inst.user_id
        
        WHERE (
            -- Case A: Invoice Linked to Appointment owned by Patient
            EXISTS (SELECT 1 FROM tbl_appointment a2 WHERE a2.appointment_id = i.appointment_id AND a2.patient_id = ?)
            OR
            -- Case B: Invoice Linked to Installment owned by Patient
            EXISTS (SELECT 1 FROM tbl_installment ins2 WHERE ins2.installment_id = i.installment_id AND ins2.patient_id = ?)
        )
          AND i.payment_status = 'Paid'";
    
    $invParams = [$patient_id, $patient_id];
    
    if ($start_date && $end_date) {
        $invSql .= " AND DATE(i.paid_at) BETWEEN ? AND ?";
        $invParams[] = $start_date;
        $invParams[] = $end_date;
    }
    
    $invSql .= " GROUP BY i.invoice_id ORDER BY i.paid_at DESC LIMIT 50";
    
    $invStmt = $pdo->prepare($invSql);
    $invStmt->execute($invParams);
    $invoices = $invStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($invoices as $inv) {
        // True Installment (Monthly) has installment_id BUT NO appointment link
        // Down Payments have both installment_id AND appointment link -> Treat as Medical
        $isMonthlyInstallment = !empty($inv['installment_id']) && empty($inv['appointment_date']);
        
        $doctor = $isMonthlyInstallment ? ($inv['inst_doctor_name'] ?? 'Clinic') : ($inv['appointment_doctor'] ?? 'Clinic');
        
        $services = $isMonthlyInstallment ? 
                    ('Installment - ' . ($inv['inst_service_name'] ?? 'Service')) : 
                    ($inv['service_names'] ?: 'Medical Services');
        
        $history[] = [
            'id' => 'inv_' . $inv['invoice_id'],
            'reference_number' => $inv['reference_number'],
            'reference_id' => $inv['invoice_id'],
            'date' => date('Y-m-d', strtotime($inv['paid_at'])),
            'time' => date('H:i:s', strtotime($inv['paid_at'])),
            'paid_at' => $inv['paid_at'],
            'doctor' => $doctor,
            'doctor_name' => $doctor,
            'services' => $services,
            'service_names' => $services,
            'amount' => $inv['total_amount'],
            'total_amount' => $inv['total_amount'],
            'discount_amount' => $inv['discount_amount'] ?? 0,
            'vat_exempt_amount' => $inv['vat_exempt_amount'] ?? 0,
            'payment_method' => $inv['payment_method'],
            'type' => $isMonthlyInstallment ? 'Installment' : 'Medical Invoice',
            'status' => 'Paid',
            'link' => 'medical_invoice_receipt.php?invoice_id=' . $inv['invoice_id']
        ];
    }

    // Sort by date descending
    usort($history, function($a, $b) {
        $dateA = isset($a['paid_at']) ? $a['paid_at'] : ($a['date'] . ' ' . $a['time']);
        $dateB = isset($b['paid_at']) ? $b['paid_at'] : ($b['date'] . ' ' . $b['time']);
        return strtotime($dateB) - strtotime($dateA);
    });

    echo json_encode(['success' => true, 'history' => $history]);
}

