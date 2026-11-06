<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

header('Content-Type: application/json');
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role']) || $_SESSION['role'] !== 'Receptionist') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    $search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
    $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
    $per_page = 20;
    $offset = ($page - 1) * $per_page;
    
    // ========================================
    // 1. MEDICAL INVOICES (Completed Procedures)
    // ========================================
    $medical_params = [];
    $medical_where = "WHERE i.invoice_id IS NULL";
    
    if (!empty($search_query)) {
        $medical_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR s.service_name LIKE ?)";
        $search_param = '%' . $search_query . '%';
        $medical_params = [$search_param, $search_param, $search_param, $search_param];
    }
    
    $medical_sql = "
        SELECT 
            mr.record_id,
            mr.patient_id,
            mr.appointment_id,
            mr.service_id,
            mr.date_of_treatment,
            p.first_name,
            p.last_name,
            p.discount_type,
            p.discount_id_number,
            p.discount_id_issuer,
            p.discount_id_image_path,
            p.discount_id_expiry,
            u.full_name as doctor_name,
            s.service_name,
            s.base_price,
            s.is_installment_available,
            s.installment_duration_months,
            s.installment_monthly_payment,
            s.installment_down_payment,
            (SELECT COUNT(*) FROM tbl_installment WHERE patient_id = mr.patient_id AND status = 'Active') as active_installment_count,
            a.appointment_fee_paid,
            COALESCE(MAX(adv_inv.amount_paid), 0) as advance_payment
        FROM tbl_medical_record mr
        INNER JOIN tbl_patient p ON mr.patient_id = p.patient_id
        INNER JOIN tbl_user u ON mr.doctor_id = u.user_id
        INNER JOIN tbl_service s ON mr.service_id = s.service_id
        LEFT JOIN tbl_invoice i ON mr.record_id = i.record_id
        LEFT JOIN tbl_appointment a ON mr.appointment_id = a.appointment_id
        LEFT JOIN tbl_invoice adv_inv ON mr.appointment_id = adv_inv.appointment_id AND adv_inv.payment_status != 'Cancelled'
        $medical_where
        GROUP BY mr.record_id
        ORDER BY mr.date_of_treatment DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    $medical_stmt = $pdo->prepare($medical_sql);
    $medical_stmt->execute($medical_params);
    $medical_records = $medical_stmt->fetchAll(PDO::FETCH_ASSOC);

    // ========================================
    // 2. APPOINTMENT FEES (Booked/Pending Appointments)
    // ========================================
    // Note: Appointment fee calculation is done directly in this file (no helper needed)
    
    $appt_params = [];
    $appt_where = "WHERE a.status IN ('Booked', 'Pending') AND (a.appointment_fee_paid IS NULL OR a.appointment_fee_paid = 0)";
    
    if (!empty($search_query)) {
        $appt_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR s.service_name LIKE ?)";
        $appt_params = [$search_param, $search_param, $search_param, $search_param];
    }
    
    $appt_sql = "
        SELECT 
            a.appointment_id,
            a.patient_id,
            a.appointment_date,
            a.time_slot,
            p.first_name,
            p.last_name,
            u.full_name as doctor_name,
            GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_names,
            SUM(s.estimated_duration_mins) as total_duration,
            SUM(tas.price_at_booking) as total_service_amount
        FROM tbl_appointment a
        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
        INNER JOIN tbl_user u ON a.doctor_id = u.user_id
        INNER JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        INNER JOIN tbl_service s ON tas.service_id = s.service_id
        $appt_where
        GROUP BY a.appointment_id
        ORDER BY a.appointment_date DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    $appt_stmt = $pdo->prepare($appt_sql);
    $appt_stmt->execute($appt_params);
    $appointments = $appt_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ========================================
    // 3. INSTALLMENT PAYMENTS (Due Payments)
    // ========================================
    $inst_params = [];
    $inst_where = "WHERE inst.status = 'Active' AND inst.balance_remaining > 0";
    
    if (!empty($search_query)) {
        $inst_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR s.service_name LIKE ?)";
        $inst_params = [$search_param, $search_param, $search_param, $search_param];
    }
    
    $inst_sql = "
        SELECT 
            inst.installment_id,
            inst.patient_id,
            inst.total_amount,
            inst.balance_remaining,
            inst.monthly_amount,
            inst.months_total,
            inst.months_paid,
            inst.next_payment_date,
            inst.penalty_accumulated,
            inst.penalty_paid,
            p.first_name,
            p.last_name,
            s.service_name
        FROM tbl_installment inst
        INNER JOIN tbl_patient p ON inst.patient_id = p.patient_id
        INNER JOIN tbl_service s ON inst.service_id = s.service_id
        $inst_where
        ORDER BY inst.next_payment_date ASC
        LIMIT $per_page OFFSET $offset
    ";
    
    $inst_stmt = $pdo->prepare($inst_sql);
    $inst_stmt->execute($inst_params);
    $installments = $inst_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // (Duplicate block removed)
    
    // ========================================
    // 4. PENDING INVOICES (Advance / Full Payments Pending Verification)
    // ========================================
    $pending_params = ['Pending'];
    $pending_where = "WHERE i.payment_status = ?";
    
    if (!empty($search_query)) {
        $pending_where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR i.reference_number LIKE ?)";
        $search_param = '%' . $search_query . '%';
        $pending_params = ['Pending', $search_param, $search_param, $search_param, $search_param];
    }
    
    $pending_sql = "
        SELECT 
            i.invoice_id,
            i.reference_number,
            i.total_amount,
            i.amount_paid,
            i.payment_status,
            i.created_at,
            i.paid_at,
            i.payment_reference,
            p.patient_id,
            p.first_name,
            p.last_name,
            p.discount_type,
            p.discount_id_number,
            p.discount_id_issuer,
            p.discount_id_image_path,
            p.discount_id_expiry,
            u.full_name as doctor_name,
            (SELECT GROUP_CONCAT(DISTINCT s.service_name SEPARATOR ', ') 
             FROM tbl_appointment_service tas 
             JOIN tbl_service s ON tas.service_id = s.service_id 
             WHERE tas.appointment_id = i.appointment_id) as service_names,
             i.appointment_id,
             i.record_id,
             a.appointment_date,
             a.time_slot,
             a.status as appointment_status,
             a.appointment_fee_paid
        FROM tbl_invoice i
        JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        JOIN tbl_patient p ON a.patient_id = p.patient_id
        LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
        $pending_where
        ORDER BY i.created_at DESC
    ";
    
    $pending_stmt = $pdo->prepare($pending_sql);
    $pending_stmt->execute($pending_params);
    $pending_invoices_raw = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pending_invoices = [];
    foreach ($pending_invoices_raw as $inv) {
        $date_obj = new DateTime($inv['appointment_date']);
        $pending_invoices[] = [
             'type' => 'invoice',
             'invoice_id' => $inv['invoice_id'],
             'reference_number' => $inv['reference_number'],
             'patient_name' => $inv['first_name'] . ' ' . $inv['last_name'],
             'amount' => floatval($inv['total_amount']),
             'amount_paid' => floatval($inv['amount_paid']),
             'doctor_name' => $inv['doctor_name'],
             'service_names' => $inv['service_names'],
             'date_formatted' => $date_obj->format('M d, Y'),
             'appointment_id' => $inv['appointment_id'],
             'record_id' => $inv['record_id'],
             'payment_reference' => $inv['payment_reference'],
             'appointment_status' => $inv['appointment_status'],
             'appointment_fee_paid' => floatval($inv['appointment_fee_paid'] ?? 0),
             'appointment_fee_paid' => floatval($inv['appointment_fee_paid'] ?? 0),
             'created_at' => $inv['created_at'],
             // Discount Info
             'discount_type' => $inv['discount_type'],
             'discount_id_number' => $inv['discount_id_number'],
             'discount_id_issuer' => $inv['discount_id_issuer'],
             'discount_id_image_path' => $inv['discount_id_image_path'],
             'discount_id_expiry' => $inv['discount_id_expiry']
        ];
    }

    // ========================================
    // FORMAT RESPONSES
    // ========================================
    
    // Format Medical Invoices
    $formatted_medical = [];
    foreach ($medical_records as $record) {
        $date_obj = new DateTime($record['date_of_treatment']);
        
        // Fetch Service Breakdown from tbl_appointment_service
        // This includes the primary service AND any additional services added by the doctor
        $breakdown = [];
        $total_service_amount = 0;
        
        if (!empty($record['appointment_id'])) {
            $svc_stmt = $pdo->prepare("
                SELECT s.service_name, tas.price_at_booking, s.service_id, s.is_installment_available
                FROM tbl_appointment_service tas
                INNER JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE tas.appointment_id = ?
            ");
            $svc_stmt->execute([$record['appointment_id']]);
            $services = $svc_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($services as $svc) {
                $price = floatval($svc['price_at_booking']);
                $breakdown[] = [
                    'name' => $svc['service_name'],
                    'price' => $price,
                    'service_id' => $svc['service_id'],
                    'is_installment_available' => (bool)$svc['is_installment_available']
                ];
                $total_service_amount += $price;
            }
        }
        
        // Fallback: If no appointment_service records found (legacy data?), use the record's service
        if (empty($breakdown)) {
            $price = floatval($record['base_price']);
            $breakdown[] = [
                'name' => $record['service_name'],
                'price' => $price,
                'service_id' => (int)$record['service_id']
            ];
            $total_service_amount = $price;
        }

        // Construct service name display
        $service_display_name = $record['service_name'];
        if (count($breakdown) > 1) {
            $service_display_name = $breakdown[0]['name'] . ' + ' . (count($breakdown) - 1) . ' more';
        }

        $formatted_medical[] = [
            'type' => 'medical',
            'record_id' => (int)$record['record_id'],
            'patient_id' => (int)$record['patient_id'],
            'patient_name' => $record['first_name'] . ' ' . $record['last_name'],
            'service_name' => $service_display_name,
            'doctor_name' => $record['doctor_name'],
            'amount' => $total_service_amount, // Uses the SUM of all services
            'services_breakdown' => $breakdown, // New field
            'date_formatted' => $date_obj->format('M d, Y'),
            'appointment_date' => $record['date_of_treatment'],
            'appointment_id' => $record['appointment_id'],
            'appointment_fee_paid' => floatval($record['appointment_fee_paid'] ?? 0),
            'service_id' => (int)$record['service_id'],
            'is_installment_available' => (bool)$record['is_installment_available'],
            'installment_duration_months' => (int)$record['installment_duration_months'],
            'installment_monthly_payment' => (float)$record['installment_monthly_payment'],
            'installment_down_payment' => (float)$record['installment_down_payment'],
            'has_active_installment' => ($record['active_installment_count'] > 0),
            'advance_payment' => floatval($record['advance_payment'] ?? 0),
            // Discount Info
             'discount_type' => $record['discount_type'],
             'discount_id_number' => $record['discount_id_number'],
             'discount_id_issuer' => $record['discount_id_issuer'],
             'discount_id_image_path' => $record['discount_id_image_path'],
             'discount_id_expiry' => $record['discount_id_expiry']
        ];
    }
    
    // Format Appointment Fees
    $formatted_appointments = [];
    
    // Fetch settings ONCE before the loop
    $base_fee = 200.0;
    $overtime_fee_per_interval = 50.0;
    $overtime_interval = 15;
    
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM tbl_settings WHERE setting_key IN ('appointment_fee', 'appointment_overtime_fee', 'appointment_overtime_interval_mins')");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['setting_key'] === 'appointment_fee' && is_numeric($row['setting_value'])) {
                $base_fee = floatval($row['setting_value']);
            } elseif ($row['setting_key'] === 'appointment_overtime_fee' && is_numeric($row['setting_value'])) {
                $overtime_fee_per_interval = floatval($row['setting_value']);
            } elseif ($row['setting_key'] === 'appointment_overtime_interval_mins' && is_numeric($row['setting_value'])) {
                $overtime_interval = intval($row['setting_value']);
            }
        }
    } catch (Exception $e) {
        error_log('Error fetching appointment settings: ' . $e->getMessage());
    }
    
    foreach ($appointments as $appt) {
        $date_obj = new DateTime($appt['appointment_date'] . ' ' . $appt['time_slot']);
        
        // Calculate appointment fee directly (no helper function)
        $total_duration = intval($appt['total_duration'] ?? 0);
        $appointment_fee = $base_fee;
        $overtime_minutes = 0;
        $overtime_fee_total = 0.0;
        
        if ($total_duration > 30 && $overtime_interval > 0) {
            $overtime_minutes = $total_duration - 30;
            $overtime_increments = ceil($overtime_minutes / $overtime_interval);
            $overtime_fee_total = floatval($overtime_increments * $overtime_fee_per_interval);
            $appointment_fee += $overtime_fee_total;
        }
        
        $formatted_appointments[] = [
            'type' => 'appointment',
            'appointment_id' => (int)$appt['appointment_id'],
            'patient_id' => (int)$appt['patient_id'],
            'patient_name' => $appt['first_name'] . ' ' . $appt['last_name'],
            'service_names' => $appt['service_names'],
            'doctor_name' => $appt['doctor_name'],
            'amount' => $appointment_fee,
            'total_duration' => $total_duration,
            'service_amount' => floatval($appt['total_service_amount'] ?? 0),
            'date_formatted' => $date_obj->format('M d, Y g:i A'),
            'appointment_date' => $appt['appointment_date'],
            'time_slot' => $appt['time_slot'],
            // BREAKDOWN DETAILS
            'base_fee' => $base_fee,
            'overtime_minutes' => $overtime_minutes,
            'overtime_fee' => $overtime_fee_total,
            'overtime_interval' => $overtime_interval,
            'overtime_fee_per_interval' => $overtime_fee_per_interval
        ];
    }
    
    // Format Installment Payments
    $formatted_installments = [];
    foreach ($installments as $inst) {
        $next_date_obj = new DateTime($inst['next_payment_date']);
        $penalty_due = max(0, ($inst['penalty_accumulated'] ?? 0) - ($inst['penalty_paid'] ?? 0));
        $amount_due = floatval($inst['monthly_amount']) + $penalty_due;
        
        $formatted_installments[] = [
            'type' => 'installment',
            'installment_id' => (int)$inst['installment_id'],
            'patient_id' => (int)$inst['patient_id'],
            'patient_name' => $inst['first_name'] . ' ' . $inst['last_name'],
            'service_name' => $inst['service_name'],
            'monthly_amount' => floatval($inst['monthly_amount']),
            'penalty_due' => $penalty_due,
            'amount_due' => $amount_due,
            'balance_remaining' => floatval($inst['balance_remaining']),
            'months_paid' => (int)$inst['months_paid'],
            'months_total' => (int)$inst['months_total'],
            'next_payment_date' => $inst['next_payment_date'],
            'date_formatted' => $next_date_obj->format('M d, Y'),
            'progress' => ($inst['months_paid'] + 1) . '/' . $inst['months_total']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'medical_invoices' => $formatted_medical,
        'appointment_fees' => $formatted_appointments,
        'installment_payments' => $formatted_installments,
        'pending_invoices' => $pending_invoices,
        'counts' => [
            'medical' => count($formatted_medical),
            'appointments' => count($formatted_appointments),
            'installments' => count($formatted_installments),
            'pending' => count($pending_invoices),
            'total' => count($formatted_medical) + count($formatted_appointments) + count($formatted_installments) + count($pending_invoices)
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('Error fetching unpaid transactions: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error fetching transactions']);
}
?>
