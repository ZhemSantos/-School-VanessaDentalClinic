<?php
// admin/report_logic.php

function getReportsData($pdo, $start_date, $end_date, $report_date) {
    // Return array of data arrays
    $reports_data = [];
    
    // Normalize string dates
    $params_range = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    $params_day = [$report_date];

    try {
        // ==========================================
        // 1. FINANCIAL REPORTS
        // ==========================================
        
        // 1.1 Financial Transactions
        $sql_1_1 = "SELECT 
                        i.reference_number AS invoice_no,
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        GROUP_CONCAT(COALESCE(s.service_name, s_inst.service_name) SEPARATOR ', ') AS service,
                        u.full_name AS dentist,
                        i.payment_method,
                        i.total_amount AS amount_paid,
                        i.paid_at AS date_paid
                    FROM tbl_invoice i
                    LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
                    LEFT JOIN tbl_installment inst ON i.installment_id = inst.installment_id
                    LEFT JOIN tbl_patient p ON p.patient_id = COALESCE(a.patient_id, inst.patient_id)
                    LEFT JOIN tbl_user u ON a.doctor_id = u.user_id
                    LEFT JOIN tbl_appointment_service aps ON a.appointment_id = aps.appointment_id
                    LEFT JOIN tbl_service s ON aps.service_id = s.service_id
                    LEFT JOIN tbl_service s_inst ON inst.service_id = s_inst.service_id
                    WHERE i.payment_status = 'Paid'
                      AND i.paid_at BETWEEN ? AND ?
                    GROUP BY i.invoice_id
                    ORDER BY i.paid_at DESC";
        $stmt = $pdo->prepare($sql_1_1);
        $stmt->execute($params_range);
        $reports_data['financial_transactions'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 1.2 Daily Sales
        $sql_1_2 = "SELECT 
                        DATE(paid_at) AS sales_date,
                        COUNT(invoice_id) AS total_transactions,
                        SUM(total_amount) AS total_sales
                    FROM tbl_invoice
                    WHERE payment_status = 'Paid'
                      AND DATE(paid_at) = ?
                    GROUP BY DATE(paid_at)";
        $stmt = $pdo->prepare($sql_1_2);
        $stmt->execute($params_day);
        $reports_data['daily_sales'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 1.3 Breakdown
        $sql_1_3 = "SELECT 
                        payment_method,
                        COUNT(*) AS transaction_count,
                        SUM(total_amount) AS total_amount
                    FROM tbl_invoice
                    WHERE payment_status = 'Paid'
                      AND paid_at BETWEEN ? AND ?
                    GROUP BY payment_method";
        $stmt = $pdo->prepare($sql_1_3);
        $stmt->execute($params_range);
        $reports_data['payment_methods'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 1.4 AR
        $sql_1_4 = "SELECT 
                        reference_number,
                        total_amount,
                        payment_status,
                        created_at
                    FROM tbl_invoice
                    WHERE payment_status IN ('Pending', 'Failed')
                    ORDER BY created_at ASC";
        $stmt = $pdo->query($sql_1_4);
        $reports_data['accounts_receivable'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================
        // 2. CLINICAL & OPERATIONAL
        // ==========================================


        // 1.5 Discounts & Exemptions
        $sql_1_5 = "SELECT 
                        i.reference_number,
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        COALESCE(d.discount_type, 'Manual/Other') AS discount_type,
                        d.id_number,
                        d.id_issuer,
                        u.full_name AS verified_by,
                        i.discount_amount,
                        i.vat_exempt_amount,
                        COALESCE(d.date_verified, i.paid_at) AS date_verified
                    FROM tbl_invoice i
                    LEFT JOIN tbl_invoice_discount d ON i.invoice_id = d.invoice_id
                    LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
                    LEFT JOIN tbl_patient p ON a.patient_id = p.patient_id
                    LEFT JOIN tbl_user u ON d.verified_by = u.user_id
                    WHERE i.discount_amount > 0
                      AND i.paid_at BETWEEN ? AND ?
                    ORDER BY i.paid_at DESC";
        $stmt = $pdo->prepare($sql_1_5);
        $stmt->execute($params_range);
        $reports_data['discounts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2.1 Treatment Log
        $sql_2_1 = "SELECT 
                        DATE(m.date_of_treatment) AS treatment_date,
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        GROUP_CONCAT(COALESCE(s.service_name, s_legacy.service_name) SEPARATOR ', ') AS service_name,
                        u.full_name AS dentist,
                        m.tooth_numbers
                    FROM tbl_medical_record m
                    JOIN tbl_patient p ON m.patient_id = p.patient_id
                    JOIN tbl_appointment a ON m.appointment_id = a.appointment_id
                    LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                    LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                    LEFT JOIN tbl_service s_legacy ON m.service_id = s_legacy.service_id
                    JOIN tbl_user u ON m.doctor_id = u.user_id
                    WHERE DATE(m.date_of_treatment) = ?
                    GROUP BY m.record_id
                    ORDER BY m.date_of_treatment";
        $stmt = $pdo->prepare($sql_2_1);
        $stmt->execute($params_day);
        $reports_data['treatment_log'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2.2 Attendance
        $sql_2_2 = "SELECT 
                        appointment_date,
                        status,
                        COUNT(*) AS total
                    FROM tbl_appointment
                    WHERE appointment_date BETWEEN ? AND ?
                    GROUP BY appointment_date, status
                    ORDER BY appointment_date";
        $stmt = $pdo->prepare($sql_2_2);
        $stmt->execute([$start_date, $end_date]);
        $reports_data['attendance'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 2.3 Productivity
        $sql_2_3 = "SELECT 
                        u.full_name AS dentist,
                        COUNT(DISTINCT a.appointment_id) AS patients_seen,
                        SUM(i.total_amount) AS revenue_generated
                    FROM tbl_user u
                    JOIN tbl_appointment a ON u.user_id = a.doctor_id
                    LEFT JOIN tbl_invoice i ON a.appointment_id = i.appointment_id
                    WHERE u.role = 'Dentist'
                      AND a.status = 'Completed'
                      AND a.appointment_date BETWEEN ? AND ?
                    GROUP BY u.user_id";
        $stmt = $pdo->prepare($sql_2_3);
        $stmt->execute([$start_date, $end_date]);
        $reports_data['productivity'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================
        // 3. INSTALLMENT REPORTS
        // ==========================================

        // 3.1 Active
        $sql_3_1 = "SELECT 
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        s.service_name,
                        i.total_amount,
                        i.down_payment_paid,
                        i.balance_remaining,
                        i.monthly_amount,
                        i.months_paid,
                        i.months_total,
                        i.next_payment_date,
                        i.status
                    FROM tbl_installment i
                    JOIN tbl_patient p ON i.patient_id = p.patient_id
                    JOIN tbl_service s ON i.service_id = s.service_id
                    WHERE i.status = 'Active'
                    ORDER BY i.next_payment_date ASC";
        $stmt = $pdo->query($sql_3_1);
        $reports_data['active_installments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3.2 Due
        $sql_3_2 = "SELECT 
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        p.phone_number,
                        s.service_name,
                        i.monthly_amount,
                        i.next_payment_date
                    FROM tbl_installment i
                    JOIN tbl_patient p ON i.patient_id = p.patient_id
                    JOIN tbl_service s ON i.service_id = s.service_id
                    WHERE i.status = 'Active'
                      AND i.next_payment_date <= CURDATE()
                    ORDER BY i.next_payment_date";
        $stmt = $pdo->query($sql_3_2);
        $reports_data['installments_due'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 3.3 Completed
        $sql_3_3 = "SELECT 
                        CONCAT(p.first_name, ' ', p.last_name) AS patient_name,
                        s.service_name,
                        i.total_amount,
                        i.months_total,
                        i.created_at
                    FROM tbl_installment i
                    JOIN tbl_patient p ON i.patient_id = p.patient_id
                    JOIN tbl_service s ON i.service_id = s.service_id
                    WHERE i.status = 'Completed'";
        $stmt = $pdo->query($sql_3_3);
        $reports_data['completed_installments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // ==========================================
        // 4. MANAGEMENT
        // ==========================================

        // 4.1 Top Services
        $sql_4_1 = "SELECT 
                        s.service_name,
                        COUNT(aps.appointment_service_id) AS times_rendered,
                        SUM(aps.price_at_booking) AS total_revenue
                    FROM tbl_appointment_service aps
                    JOIN tbl_service s ON aps.service_id = s.service_id
                    JOIN tbl_appointment a ON aps.appointment_id = a.appointment_id
                    WHERE a.status = 'Completed'
                      AND a.appointment_date BETWEEN ? AND ?
                    GROUP BY s.service_id
                    ORDER BY total_revenue DESC";
        $stmt = $pdo->prepare($sql_4_1);
        $stmt->execute([$start_date, $end_date]);
        $reports_data['top_services'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 4.2 Feedback
        $sql_4_2 = "SELECT 
                        s.service_name,
                        AVG(f.rating) AS average_rating,
                        COUNT(f.feedback_id) AS total_reviews
                    FROM tbl_feedback f
                    JOIN tbl_invoice i ON f.invoice_id = i.invoice_id
                    JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
                    JOIN tbl_appointment_service aps ON a.appointment_id = aps.appointment_id
                    JOIN tbl_service s ON aps.service_id = s.service_id
                    GROUP BY s.service_id";
        $stmt = $pdo->query($sql_4_2);
        $reports_data['feedback'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        error_log("Report Query Error: " . $e->getMessage());
    }

    return $reports_data;
}
?>
