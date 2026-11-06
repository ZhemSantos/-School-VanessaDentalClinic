<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role to access this page
requireRole('Receptionist');

// Update current user's activity
updateUserActivity();

// Database Migration: Ensure payment_reference column exists
try {
    $col_check = $pdo->query("SHOW COLUMNS FROM tbl_invoice LIKE 'payment_reference'");
    if ($col_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbl_invoice ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payment_method");
    }
} catch (Exception $e) {
    // Ignore if already exists or other error, strictly for dev convenience
}

// Database Migration: Ensure appointment fee tracking columns exist
try {
    $col_check = $pdo->query("SHOW COLUMNS FROM tbl_appointment LIKE 'appointment_fee_paid'");
    if ($col_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbl_appointment 
                   ADD COLUMN appointment_fee_paid DECIMAL(10,2) DEFAULT 0.00,
                   ADD COLUMN appointment_fee_invoice_id INT NULL,
                   ADD INDEX idx_appointment_fee_invoice (appointment_fee_invoice_id)");
    }
} catch (Exception $e) {
    // Ignore migration errors
}

// Database Migration: Ensure invoice discount columns exist
try {
    // 1. Create discount table if not exists (with all columns)
    $pdo->exec("CREATE TABLE IF NOT EXISTS tbl_invoice_discount (
        discount_id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NULL,
        discount_type ENUM('None', 'PWD', 'Senior') NOT NULL DEFAULT 'None',
        id_number VARCHAR(100) NULL,
        id_issuer VARCHAR(100) NULL,
        id_image_path VARCHAR(255) NULL,
        verified_by INT NULL,
        date_verified TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (verified_by) REFERENCES tbl_user(user_id) ON DELETE SET NULL,
        FOREIGN KEY (invoice_id) REFERENCES tbl_invoice(invoice_id) ON DELETE CASCADE
    )");

    // 2. Add id_image_path if missing (for existing tables)
    $col_check = $pdo->query("SHOW COLUMNS FROM tbl_invoice_discount LIKE 'id_image_path'");
    if ($col_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbl_invoice_discount ADD COLUMN id_image_path VARCHAR(255) NULL AFTER id_issuer");
    }

    // 3. Add discount columns to tbl_invoice
    $col_check_inv = $pdo->query("SHOW COLUMNS FROM tbl_invoice LIKE 'discount_amount'");
    if ($col_check_inv->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbl_invoice ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0.00");
    }
    $col_check_vat = $pdo->query("SHOW COLUMNS FROM tbl_invoice LIKE 'vat_exempt_amount'");
    if ($col_check_vat->rowCount() == 0) {
        $pdo->exec("ALTER TABLE tbl_invoice ADD COLUMN vat_exempt_amount DECIMAL(10,2) DEFAULT 0.00");
    }
    
    // User confirmed amount_paid exists, no migration needed.
    // Ensure we handle NULL values in logic though.

} catch (Exception $e) {
    error_log("Migration Error: " . $e->getMessage());
}

// Fetch current user's full details from database
// ... (omitting unchanged lines until we get to the query) ...

// JUMPING TO THE UPDATE QUERY LOGIC (Line 389 approx in context, but using search for replace)
// We need to apply the fix to the SQL string directly.

/* 
   Since I cannot skip lines in replace_file_content easily without context, 
   I will perform TWO replacements. 
   First: Revert the migration code block at the top.
*/

// Fetch current user's full details from database
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $user_details_stmt = $pdo->prepare("SELECT user_id, username, full_name, role, email, contact_number, blood_type, status, profile_picture, created_at FROM tbl_user WHERE user_id = ? LIMIT 1");
        $user_details_stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $user_details_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching user details: ' . $e->getMessage());
    }
}

// Helper function to get default profile photo
function getDefaultProfilePhoto($full_name, $size = 40) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    
    $colors = [
        ['bg' => '#FFD700', 'text' => '#6A1B9A'],
        ['bg' => '#6A1B9A', 'text' => '#ffffff'],
        ['bg' => '#8a11d4', 'text' => '#ffffff'],
        ['bg' => '#10b981', 'text' => '#ffffff'],
        ['bg' => '#3b82f6', 'text' => '#ffffff'],
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    
    return [
        'initials' => $initials,
        'bg_color' => $color['bg'],
        'text_color' => $color['text']
    ];
}

$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Receptionist';
$current_role = $current_user['role'] ?? 'Receptionist';
$current_profile = $current_user['profile_picture'] ?? null;
$default_photo = getDefaultProfilePhoto($current_name);

// Get current page for active navigation state
$current_page = basename($_SERVER['PHP_SELF']);

// Handle payment processing
$action_message = '';
$action_type = '';

// Check for success message from redirect
if (isset($_GET['status']) && $_GET['status'] === 'success' && isset($_GET['message'])) {
    $action_message = sanitizeInput($_GET['message']);
    $action_type = 'success';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['process_payment'])) {
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $invoice_id = intval($_POST['invoice_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $payment_reference = sanitizeInput($_POST['payment_reference'] ?? '');
        
        if ($invoice_id > 0 && in_array($payment_method, ['Cash', 'GCash', 'Card'])) {
            try {
                $current_datetime = date('Y-m-d H:i:s');
                $payment_amount = floatval($_POST['payment_amount'] ?? 0);
                
                // Fetch info
                $curr_stmt = $pdo->prepare("SELECT installment_id, appointment_id, payment_method, payment_reference, amount_paid, total_amount FROM tbl_invoice WHERE invoice_id = ?");
                $curr_stmt->execute([$invoice_id]);
                $inv_info = $curr_stmt->fetch(); 
                
                // Append Method Logic
                $new_method = $payment_method;
                $new_reference = $payment_reference;
                
                if ($inv_info) {
                    if (!empty($inv_info['payment_method']) && strpos($inv_info['payment_method'], $payment_method) === false) {
                        $new_method = $inv_info['payment_method'] . ', ' . $payment_method;
                    } elseif (!empty($inv_info['payment_method']) && !empty($payment_method)) {
                         $new_method = $inv_info['payment_method']; 
                    }
                    
                    if (!empty($inv_info['payment_reference']) && strpos($inv_info['payment_reference'], $payment_reference) === false) {
                        $new_reference = $inv_info['payment_reference'] . ', ' . $payment_reference;
                    } elseif (!empty($inv_info['payment_reference'])) {
                         $new_reference = $inv_info['payment_reference'];
                    }
                }

                // Installment Logic
                if ($inv_info) {
                    $today_date = date('Y-m-d'); // Use PHP date for timezone
                    
                    if (!empty($inv_info['installment_id'])) {
                        $inst_id = $inv_info['installment_id'];
                        $inst_update = $pdo->prepare("UPDATE tbl_installment SET status = 'Active', start_date = ? WHERE installment_id = ? AND status = 'Pending'");
                        $inst_update->execute([$today_date, $inst_id]);
                    } elseif (!empty($inv_info['appointment_id'])) {
                        $appt_id = $inv_info['appointment_id'];
                        $inst_update = $pdo->prepare("UPDATE tbl_installment SET status = 'Active', start_date = ? WHERE appointment_id = ? AND status = 'Pending'");
                        $inst_update->execute([$today_date, $appt_id]);
                    }
                }

                $update_stmt = $pdo->prepare("UPDATE tbl_invoice SET payment_status = 'Paid', payment_method = ?, payment_reference = ?, paid_at = ?, amount_paid = amount_paid + ? WHERE invoice_id = ?");
                $update_stmt->execute([$new_method, $new_reference, $current_datetime, $payment_amount, $invoice_id]);
                $action_message = 'Payment processed successfully!';
                $action_type = 'success';
            } catch (PDOException $e) {
                error_log('Error processing payment: ' . $e->getMessage());
                $action_message = 'Failed to process payment.';
                $action_type = 'error';
            }
        }
    } elseif (!empty($_POST['create_invoice_from_transaction'])) {
        $record_id = (int)($_POST['record_id'] ?? 0);
        $appointment_id = (int)($_POST['appointment_id_val'] ?? 0);
        $service_id_post = (int)($_POST['service_id'] ?? 0);
        $payment_amount = (float)($_POST['payment_amount'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $payment_reference = sanitizeInput($_POST['payment_reference'] ?? '');
        $payment_scheme = sanitizeInput($_POST['payment_scheme'] ?? 'full');
        $down_payment_amount = floatval($_POST['down_payment_amount'] ?? 0);
        
        $discount_type = sanitizeInput($_POST['discount_type'] ?? 'None');
        $discount_id_number = sanitizeInput($_POST['discount_id_number'] ?? '');
        $discount_id_issuer = sanitizeInput($_POST['discount_id_issuer'] ?? '');
        $discount_date_verified = sanitizeInput($_POST['discount_date_verified'] ?? '');
        if (empty($discount_date_verified)) {
            $discount_date_verified = date('Y-m-d H:i:s');
        }
        
        // Handle File Upload
        $discount_image_path = null;
        if ($discount_type !== 'None' && isset($_FILES['discount_id_image']) && $_FILES['discount_id_image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/discounts/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $fileName = uniqid('disc_') . '_' . basename($_FILES['discount_id_image']['name']);
            $targetPath = $uploadDir . $fileName;
            if (move_uploaded_file($_FILES['discount_id_image']['tmp_name'], $targetPath)) {
                $discount_image_path = 'uploads/discounts/' . $fileName;
            }
        }
        
        // Allow if either record_id OR appointment_id is present
        if (($record_id > 0 || $appointment_id > 0) && in_array($payment_method, ['Cash', 'GCash'])) {
            try {
                $record = null;
                $total_bill_amount = 0;
                $patient_id_val = 0;

                // Case 1: Payment for Medical Record (Completed Procedure)
                if ($record_id > 0) {
                    // Check existence
                    $check_stmt = $pdo->prepare("SELECT invoice_id FROM tbl_invoice WHERE record_id = ?");
                    $check_stmt->execute([$record_id]);
                    if ($check_stmt->fetch()) {
                        $action_message = 'Invoice already exists for this record.';
                        $action_type = 'error';
                        // Break early?
                        $appointment_id = 0; // Prevent falling through
                    } else {
                        // Fetch details
                        $record_stmt = $pdo->prepare("
                            SELECT mr.*, 
                                   p.patient_id, p.first_name, p.last_name, p.email,
                                   COALESCE((SELECT SUM(price_at_booking) FROM tbl_appointment_service WHERE appointment_id = mr.appointment_id), 0) as total_service_price
                            FROM tbl_medical_record mr
                            INNER JOIN tbl_patient p ON mr.patient_id = p.patient_id
                            WHERE mr.record_id = ?
                        ");
                        $record_stmt->execute([$record_id]);
                        $record = $record_stmt->fetch();
                        if ($record) {
                            $total_bill_amount = $record['total_service_price'];
                            $patient_id_val = $record['patient_id'];
                        }
                    }
                } 
                // Case 2: Payment for Appointment (Pending/Pre-payment)
                elseif ($appointment_id > 0) {
                     // Check invoice linked to appointment directly? 
                     // Typically invoices link to record_id. If pre-paying, we might need to link to appointment_id.
                     // Schema check: tbl_invoice has appointment_id column? 
                     // I will assume YES because I need it. If not, I can only link via installment.
                     
                     $appt_stmt = $pdo->prepare("
                        SELECT a.*, p.patient_id, p.first_name, p.last_name, p.email,
                               COALESCE((SELECT SUM(price_at_booking) FROM tbl_appointment_service WHERE appointment_id = a.appointment_id), 0) as total_service_price
                        FROM tbl_appointment a
                        INNER JOIN tbl_patient p ON a.patient_id = p.patient_id
                        WHERE a.appointment_id = ?
                     ");
                     $appt_stmt->execute([$appointment_id]);
                     $appt_data = $appt_stmt->fetch();
                     
                     if ($appt_data) {
                         // Build a pseudo-record array to reuse logic
                         $record = $appt_data; // contains patient info and price
                         $total_bill_amount = $appt_data['total_service_price'];
                         $patient_id_val = $appt_data['patient_id'];
                         // record_id stays 0
                     }
                }

                if ($record && ($total_bill_amount > 0 || $down_payment_amount > 0)) {
                    $current_datetime = date('Y-m-d H:i:s');
                    $invoice_amount = $total_bill_amount;
                    $notes = '';
                    
                    // Check if appointment fee was already paid and deduct it
                    $appointment_fee_deduction = 0;
                    if ($record_id > 0 && isset($record['appointment_id']) && $record['appointment_id'] > 0) {
                        // This is a medical record invoice - check if appointment fee was paid
                        $appt_fee_stmt = $pdo->prepare("SELECT appointment_fee_paid FROM tbl_appointment WHERE appointment_id = ?");
                        $appt_fee_stmt->execute([$record['appointment_id']]);
                        $appointment_fee_deduction = floatval($appt_fee_stmt->fetchColumn() ?? 0);
                        
                        if ($appointment_fee_deduction > 0) {
                            $notes .= "Appointment fee of ₱" . number_format($appointment_fee_deduction, 2) . " deducted. ";
                        }
                    }
                    
                    // Apply Discount Logic (re-calculate server side)
                    $discount_amount = 0;
                    $vat_exempt_amount = 0;
                    $final_total = $total_bill_amount;
                    
                    if (($discount_type === 'Senior' || $discount_type === 'PWD') && $total_bill_amount > 0) {
                        $vat_exempt_amount = $total_bill_amount / 1.12; 
                        $discount_amount = $vat_exempt_amount * 0.20;
                        $final_total = $vat_exempt_amount - $discount_amount;
                        // Determine if we use the discounted total or original for invoice amount
                        // Usually Invoice Amount is Payable.
                        $invoice_amount = $final_total;
                    }
                    
                    // Deduct appointment fee from final invoice amount
                    if ($appointment_fee_deduction > 0) {
                        $invoice_amount = max(0, $invoice_amount - $appointment_fee_deduction);
                    }

                    // INSTALLMENT LOGIC
                    if ($payment_scheme === 'installment') {
                        if ($down_payment_amount < 0) {
                            throw new Exception("Invalid down payment amount.");
                        }
                        
                        $invoice_amount = $down_payment_amount; // Current invoice is just DP
                        
                        // Calculate terms
                        // Use $final_total instead of $total_bill_amount so discounts are respected
                        $balance = $final_total - $down_payment_amount;
                        
                        $duration_months = intval($_POST['installment_months'] ?? 12);
                        if ($duration_months <= 0) $duration_months = 12;
                        
                        $interest_rate = 0.05; // 5%
                        $monthly_payment = 0;
                        if ($duration_months > 0) {
                            $total_with_interest = $balance * (1 + $interest_rate);
                            $monthly_payment = $total_with_interest / $duration_months;
                        }
                        
                        // Create Installment Record
                        // FIX: Use PHP date() for timezone consistency
                        $start_date = date('Y-m-d');
                        $next_date = date('Y-m-d', strtotime('+1 month'));
                        
                        $inst_stmt = $pdo->prepare("
                            INSERT INTO tbl_installment 
                            (patient_id, service_id, total_amount, down_payment_paid, balance_remaining, monthly_amount, months_total, start_date, next_payment_date, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active')
                        ");
                        
                        // Use actual service ID
                        $service_id_to_use = $service_id_post > 0 ? $service_id_post : ($record['service_id'] ?? 0);
                        
                        $inst_stmt->execute([
                            $patient_id_val,
                            $service_id_to_use,
                            $final_total,           // Use discounted total
                            $down_payment_amount,   // down_payment_paid
                            $balance,               // balance_remaining
                            $monthly_payment,       // monthly_amount
                            $duration_months,       // months_total
                            $start_date,
                            $next_date
                        ]);
                        $installment_id = $pdo->lastInsertId();
                    }
                    
                    // UNIFIED INVOICE: Check/Upsert Logic
                    $full_ref_id = ($record_id > 0) ? $record_id : $appointment_id;
                    $reference_number = 'INV-' . date('Ymd') . '-' . str_pad($full_ref_id, 4, '0', STR_PAD_LEFT);
                    $appt_id_final = $record['appointment_id'] ?? $appointment_id;

                    // 1. Check if an invoice already exists for this appointment (Advance Payment)
                    $check_existing = $pdo->prepare("SELECT invoice_id, payment_method, payment_reference, amount_paid FROM tbl_invoice WHERE appointment_id = ?");
                    $check_existing->execute([$appt_id_final]);
                    $existing_inv = $check_existing->fetch();

                    if ($existing_inv) {
                        // UPDATE EXISTING (Merge Advance + Final)
                        $invoice_id = $existing_inv['invoice_id'];
                        
                        // Append method/reference
                        $final_method = $payment_method;
                        if (!empty($existing_inv['payment_method']) && strpos($existing_inv['payment_method'], $payment_method) === false) {
                            $final_method = $existing_inv['payment_method'] . ', ' . $payment_method;
                        }
                        
                        $final_ref = $payment_reference;
                        if (!empty($existing_inv['payment_reference']) && strpos($existing_inv['payment_reference'], $payment_reference) === false) {
                            $final_ref = $existing_inv['payment_reference'] . ', ' . $payment_reference;
                        }

                        // Amount Logic: Store GROSS ($final_total) in total_amount
                        // Update amount_paid by adding the NEW payment amount
                        $pay_amt_clean = floatval($_POST['payment_amount'] ?? 0);
                        
                        $update_sql = "UPDATE tbl_invoice SET 
                            record_id = ?, 
                            total_amount = ?, 
                            payment_status = 'Paid', 
                            paid_at = ?,
                            payment_method = ?,
                            payment_reference = ?,
                            discount_amount = ?, 
                            vat_exempt_amount = ?,
                            amount_paid = COALESCE(amount_paid, 0) + ? 
                            WHERE invoice_id = ?";
                            
                        $upd_stmt = $pdo->prepare($update_sql);
                        $upd_stmt->execute([
                            $record_id > 0 ? $record_id : null,
                            $final_total, // Use GROSS
                            $current_datetime,
                            $final_method,
                            $final_ref,
                            $discount_amount,
                            $vat_exempt_amount,
                            $pay_amt_clean,
                            $invoice_id
                        ]);
                        
                        // Link Installment if needed
                        if (isset($installment_id)) {
                             $pdo->prepare("UPDATE tbl_invoice SET installment_id = ? WHERE invoice_id = ?")->execute([$installment_id, $invoice_id]);
                        }
                        
                    } else {
                        // INSERT NEW (Standard Logic)
                        // Use $final_total (Gross) instead of fee-deducted $invoice_amount
                        $pay_amt_clean = floatval($_POST['payment_amount'] ?? 0);
                        
                        // FIX: For Installments, the Invoice Total should match the Down Payment (amount paid now).
                        // use $down_payment_amount explicitly if available, otherwise fallback to pay_amt_clean
                        $invoice_total_to_record = isset($installment_id) ? $down_payment_amount : $final_total;
                        
                        // FIX: Explicitly set created_at to use PHP timezone
                        $cols = "record_id, reference_number, total_amount, payment_method, payment_reference, payment_status, paid_at, created_at, appointment_id, discount_amount, vat_exempt_amount, amount_paid";
                        $vals = "?, ?, ?, ?, ?, 'Paid', ?, ?, ?, ?, ?, ?";
                        $params = [$record_id > 0 ? $record_id : null, $reference_number, $invoice_total_to_record, $payment_method, $payment_reference, $current_datetime, $current_datetime, $appt_id_final, $discount_amount, $vat_exempt_amount, $pay_amt_clean];
                        
                        if (isset($installment_id)) {
                            $cols .= ", installment_id";
                            $vals .= ", ?";
                            $params[] = $installment_id;
                        }
    
                        $insert_stmt = $pdo->prepare("INSERT INTO tbl_invoice ($cols) VALUES ($vals)");
                        $insert_stmt->execute($params);
                        $invoice_id = $pdo->lastInsertId();
                    }
                    
                    // UPDATE APPOINTMENT STATUS if this is an appointment payment (and not a medical record payment)
                    if ($record_id == 0 && $appointment_id > 0) {
                        // Mark appointment as Booked and link fee payment
                        // We use the full invoice amount as the fee paid amount for tracking
                        $appt_update = $pdo->prepare("UPDATE tbl_appointment SET appointment_fee_paid = ?, appointment_fee_invoice_id = ?, status = 'Booked' WHERE appointment_id = ?");
                        $appt_update->execute([$invoice_amount, $invoice_id, $appointment_id]);

                        // Auto-cancel conflicting appointments
                        $getAppt = $pdo->prepare("SELECT doctor_id, appointment_date, time_slot FROM tbl_appointment WHERE appointment_id = ?");
                        $getAppt->execute([$appointment_id]);
                        $apptInfo = $getAppt->fetch();
                        
                        if ($apptInfo) {
                            $cancelConflicts = $pdo->prepare("
                                UPDATE tbl_appointment 
                                SET status = 'Cancelled' 
                                WHERE doctor_id = ? 
                                  AND appointment_date = ? 
                                  AND time_slot = ? 
                                  AND appointment_id != ? 
                                  AND status = 'Pending'
                            ");
                            $cancelConflicts->execute([$apptInfo['doctor_id'], $apptInfo['appointment_date'], $apptInfo['time_slot'], $appointment_id]);
                        }
                    }
                    
                    // Insert Discount Details
                    if ($discount_type !== 'None') {
                        $disc_stmt = $pdo->prepare("INSERT INTO tbl_invoice_discount (invoice_id, discount_type, id_number, id_issuer, verified_by, date_verified, id_image_path) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        $disc_stmt->execute([$invoice_id, $discount_type, $discount_id_number, $discount_id_issuer, $_SESSION['user_id'], $discount_date_verified, $discount_image_path]);
                        
                        // SAVE DISCOUNT TO PATIENT RECORD FOR FUTURE AUTO-APPLICATION
                        // Only update if patient doesn't already have this discount type
                        $checkDiscount = $pdo->prepare("SELECT discount_type FROM tbl_patient WHERE patient_id = ?");
                        $checkDiscount->execute([$patient_id_val]);
                        $currentDiscount = $checkDiscount->fetchColumn();
                        
                        // Update patient discount if:
                        // 1. Patient has no discount (None), OR
                        // 2. New discount type is different from current
                        if ($currentDiscount === 'None' || $currentDiscount === null || $currentDiscount !== $discount_type) {
                            // Calculate expiry for PWD (3 years from verification date)
                            $expiry_date = null;
                            if ($discount_type === 'PWD') {
                                $expiry_date = date('Y-m-d', strtotime('+3 years', strtotime($discount_date_verified)));
                            }
                            
                            $updatePatient = $pdo->prepare("
                                UPDATE tbl_patient 
                                SET discount_type = ?,
                                    discount_id_number = ?,
                                    discount_id_issuer = ?,
                                    discount_id_image_path = ?,
                                    discount_id_expiry = ?,
                                    discount_verified_by = ?,
                                    discount_verified_at = ?
                                WHERE patient_id = ?
                            ");
                            $updatePatient->execute([
                                $discount_type,
                                $discount_id_number,
                                $discount_id_issuer,
                                $discount_image_path,
                                $expiry_date,
                                $_SESSION['user_id'],
                                $discount_date_verified,
                                $patient_id_val
                            ]);
                        }
                    }
                    
                    // Send Email
                    require_once '../includes/email_notifications.php';
                    try {
                        sendPaymentConfirmation(
                            $invoice_id,
                            $patient_id_val,
                            $invoice_amount,
                            $payment_method,
                            $reference_number
                        );
                    } catch (Exception $e) {}
                    
                    $action_message = 'Invoice created and payment processed successfully!';
                    $action_type = 'success';
                    header("Location: receptionist_payment.php?status=success&message=" . urlencode($action_message));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Error creating invoice: ' . $e->getMessage());
                $action_message = 'Database Error: ' . $e->getMessage();
                $action_type = 'error';
                // Don't redirect on error, stay on page to show error
            } catch (Exception $e) {
                error_log('Error creating invoice: ' . $e->getMessage());
                $action_message = 'Error: ' . $e->getMessage();
                $action_type = 'error';
            }
        } else {
            $action_message = 'Invalid payment data. Please check all required fields.';
            $action_type = 'error';
        }
    } elseif (!empty($_POST['process_appointment_fee'])) {
        // NEW: Process appointment fee payment with time-based calculation
        require_once '../includes/appointment_fee_helper.php';
        
        $appointment_id = intval($_POST['appointment_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $payment_reference = sanitizeInput($_POST['payment_reference'] ?? '');
        
        if ($appointment_id > 0 && in_array($payment_method, ['Cash', 'GCash', 'Card'])) {
            try {
                $pdo->beginTransaction();
                
                // Calculate appointment fee based on total duration
                $fee_info = calculateAppointmentFeeForAppointment($appointment_id);
                $appointment_fee = $fee_info['fee'];
                
                if ($appointment_fee > 0) {
                    $current_datetime = date('Y-m-d H:i:s');
                    $reference_number = 'APPT-FEE-' . date('Ymd') . '-' . str_pad($appointment_id, 4, '0', STR_PAD_LEFT);
                    
                    // Check if already paid
                    $chk_stmt = $pdo->prepare("SELECT appointment_fee_invoice_id FROM tbl_appointment WHERE appointment_id = ?");
                    $chk_stmt->execute([$appointment_id]);
                    $existing_inv = $chk_stmt->fetchColumn();
                    
                    if ($existing_inv) {
                         $pdo->rollBack();
                         $action_message = 'This appointment fee has already been paid.';
                         $action_type = 'warning';
                         header("Location: receptionist_process_payment.php?status=success&message=" . urlencode($action_message));
                         exit;
                    }
                    
                    // Check for duplicate reference (just in case)
                    $dup_stmt = $pdo->prepare("SELECT invoice_id FROM tbl_invoice WHERE reference_number = ?");
                    $dup_stmt->execute([$reference_number]);
                    $dup_invoice_id = $dup_stmt->fetchColumn();

                    if ($dup_invoice_id) {
                         // HEAL: Link exists in invoice but not appointment (likely due to previous error)
                         // Update appointment to link to this invoice
                         $heal = $pdo->prepare("UPDATE tbl_appointment SET appointment_fee_paid = ?, appointment_fee_invoice_id = ?, status = 'Booked' WHERE appointment_id = ?");
                         $heal->execute([$appointment_fee, $dup_invoice_id, $appointment_id]);
                         
                         // Auto-cancel conflicting appointments
                         $getAppt = $pdo->prepare("SELECT doctor_id, appointment_date, time_slot FROM tbl_appointment WHERE appointment_id = ?");
                         $getAppt->execute([$appointment_id]);
                         $apptInfo = $getAppt->fetch();
                         
                         if ($apptInfo) {
                             $cancelConflicts = $pdo->prepare("
                                 UPDATE tbl_appointment 
                                 SET status = 'Cancelled' 
                                 WHERE doctor_id = ? 
                                   AND appointment_date = ? 
                                   AND time_slot = ? 
                                   AND appointment_id != ? 
                                   AND status = 'Pending'
                             ");
                             $cancelConflicts->execute([$apptInfo['doctor_id'], $apptInfo['appointment_date'], $apptInfo['time_slot'], $appointment_id]);
                         }
                         
                         $pdo->commit(); // Commit the heal
                         
                         $action_message = 'Payment already exists. Appointment status updated.';
                         $action_type = 'success';
                         header("Location: receptionist_process_payment.php?status=success&message=" . urlencode($action_message));
                         exit;
                    }
                    
                    // Create invoice for appointment fee
                    $inv_stmt = $pdo->prepare("INSERT INTO tbl_invoice (appointment_id, reference_number, total_amount, payment_method, payment_reference, payment_status, paid_at) VALUES (?, ?, ?, ?, ?, 'Paid', ?)");
                    $inv_stmt->execute([$appointment_id, $reference_number, $appointment_fee, $payment_method, $payment_reference, $current_datetime]);
                    $invoice_id = $pdo->lastInsertId();
                    
                    // Update appointment to track fee payment
                    $appt_update = $pdo->prepare("UPDATE tbl_appointment SET appointment_fee_paid = ?, appointment_fee_invoice_id = ?, status = 'Booked' WHERE appointment_id = ?");
                    $appt_update->execute([$appointment_fee, $invoice_id, $appointment_id]);
                    
                    // Auto-cancel conflicting appointments
                    $getAppt = $pdo->prepare("SELECT doctor_id, appointment_date, time_slot FROM tbl_appointment WHERE appointment_id = ?");
                    $getAppt->execute([$appointment_id]);
                    $apptInfo = $getAppt->fetch();
                    
                    if ($apptInfo) {
                        $cancelConflicts = $pdo->prepare("
                            UPDATE tbl_appointment 
                            SET status = 'Cancelled' 
                            WHERE doctor_id = ? 
                              AND appointment_date = ? 
                              AND time_slot = ? 
                              AND appointment_id != ? 
                              AND status = 'Pending'
                        ");
                        $cancelConflicts->execute([$apptInfo['doctor_id'], $apptInfo['appointment_date'], $apptInfo['time_slot'], $appointment_id]);
                    }
                    
                    $pdo->commit();
                    
                    $action_message = 'Appointment fee of ₱' . number_format($appointment_fee, 2) . ' processed successfully! (' . $fee_info['duration'] . ' minutes)';
                    $action_type = 'success';
                    header("Location: receptionist_process_payment.php?status=success&message=" . urlencode($action_message));
                    exit;
                } else {
                    $pdo->rollBack();
                    $action_message = 'Unable to calculate appointment fee.';
                    $action_type = 'error';
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Error processing appointment fee: ' . $e->getMessage());
                $action_message = 'Database Error: ' . $e->getMessage();
                $action_type = 'error';
            }
        } else {
            $action_message = 'Invalid appointment payment data.';
            $action_type = 'error';
        }
    } elseif (!empty($_POST['process_installment_payment'])) {
        // NEW: Process installment monthly payment
        $installment_id = intval($_POST['installment_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $payment_reference = sanitizeInput($_POST['payment_reference'] ?? '');
        $payment_amount = floatval($_POST['payment_amount'] ?? 0);
        
        if ($installment_id > 0 && $payment_amount > 0 && in_array($payment_method, ['Cash', 'GCash', 'Card'])) {
            try {
                $pdo->beginTransaction();
                
                // Fetch installment details
                $inst_stmt = $pdo->prepare("SELECT * FROM tbl_installment WHERE installment_id = ? FOR UPDATE");
                $inst_stmt->execute([$installment_id]);
                $inst = $inst_stmt->fetch();
                
                if ($inst) {
                    // Calculate penalty and total outstanding
                    $penalty_due = max(0, ($inst['penalty_accumulated'] ?? 0) - ($inst['penalty_paid'] ?? 0));
                    $total_outstanding = $inst['balance_remaining'] + $penalty_due;
                    
                    if ($payment_amount > ($total_outstanding + 0.01)) {
                        $pdo->rollBack();
                        $action_message = 'Error: Payment amount exceeds total outstanding balance.';
                        $action_type = 'error';
                    } else {
                        // Create invoice for this payment
                        $current_datetime = date('Y-m-d H:i:s');
                        $reference_number = 'INST-' . date('YmdHis') . '-' . $installment_id;
                        
                        $inv_stmt = $pdo->prepare("INSERT INTO tbl_invoice (installment_id, reference_number, total_amount, payment_method, payment_reference, payment_status, paid_at) VALUES (?, ?, ?, ?, ?, 'Paid', ?)");
                        $inv_stmt->execute([$installment_id, $reference_number, $payment_amount, $payment_method, $payment_reference, $current_datetime]);
                        
                        // Update installment record
                        $penalty_paid_now = 0;
                        $principal_paid = 0;
                        $remaining_payment = $payment_amount;
                        
                        // Pay penalties first
                        if ($penalty_due > 0) {
                            if ($remaining_payment >= $penalty_due) {
                                $penalty_paid_now = $penalty_due;
                                $remaining_payment -= $penalty_due;
                            } else {
                                $penalty_paid_now = $remaining_payment;
                                $remaining_payment = 0;
                            }
                        }
                        
                        // Remaining goes to principal
                        if ($remaining_payment > 0) {
                            $principal_paid = $remaining_payment;
                        }
                        
                        $new_balance = max(0, $inst['balance_remaining'] - $principal_paid);
                        $new_months_paid = $inst['months_paid'] + 1;
                        $new_penalty_paid = ($inst['penalty_paid'] ?? 0) + $penalty_paid_now;
                        
                        // Calculate next payment date
                        $current_next_date = new DateTime($inst['next_payment_date']);
                        $current_next_date->modify('+1 month');
                        $new_next_payment_date = $current_next_date->format('Y-m-d');
                        
                        // Check if completed
                        $new_status = ($new_balance <= 0.01 && $new_penalty_paid >= ($inst['penalty_accumulated'] ?? 0)) ? 'Completed' : 'Active';
                        
                        $update_stmt = $pdo->prepare("UPDATE tbl_installment SET balance_remaining = ?, months_paid = ?, penalty_paid = ?, next_payment_date = ?, status = ? WHERE installment_id = ?");
                        $update_stmt->execute([$new_balance, $new_months_paid, $new_penalty_paid, $new_next_payment_date, $new_status, $installment_id]);
                        
                        $pdo->commit();
                        
                        $action_message = 'Installment payment of ₱' . number_format($payment_amount, 2) . ' processed successfully!';
                        $action_type = 'success';
                        header("Location: receptionist_process_payment.php?status=success&message=" . urlencode($action_message));
                        exit;
                    }
                } else {
                    $pdo->rollBack();
                    $action_message = 'Installment plan not found.';
                    $action_type = 'error';
                }
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Error processing installment payment: ' . $e->getMessage());
                $action_message = 'Database Error: ' . $e->getMessage();
                $action_type = 'error';
            }
        } else {
            $action_message = 'Invalid installment payment data.';
            $action_type = 'error';
        }
    } elseif (!empty($_POST['create_new_payment'])) {
        // Process new payment (immediate payment)
        $patient_id = intval($_POST['selected_patient_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $billable_items_json = $_POST['billable_items_json'] ?? '[]';
        
        if ($patient_id > 0 && in_array($payment_method, ['Cash', 'GCash', 'Card']) && !empty($billable_items_json)) {
            try {
                $billable_items = json_decode($billable_items_json, true);
                if (!is_array($billable_items) || empty($billable_items)) {
                    $action_message = 'Please add at least one billable item.';
                    $action_type = 'error';
                } else {
                    // Get current user (receptionist) as the doctor for medical record
                    $doctor_id = $_SESSION['user_id'] ?? null;
                    if (!$doctor_id) {
                        // Fallback to first available doctor
                        $doctor_stmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE role = 'Dentist' AND status = 'Active' LIMIT 1");
                        $doctor_stmt->execute();
                        $doctor = $doctor_stmt->fetch();
                        $doctor_id = $doctor['user_id'] ?? 1;
                    }
                    
                    // Calculate total amount
                    $total_amount = 0;
                    foreach ($billable_items as $item) {
                        $total_amount += floatval($item['price']);
                    }
                    
                    // Create medical record for the first service (or primary service)
                    // Use PHP date to ensure timezone consistency
                    $current_datetime = date('Y-m-d H:i:s');
                    $first_service = $billable_items[0];
                    $insert_record_stmt = $pdo->prepare("
                        INSERT INTO tbl_medical_record (patient_id, doctor_id, service_id, diagnosis, date_of_treatment)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $diagnosis = 'Payment for: ' . $first_service['name'];
                    $insert_record_stmt->execute([$patient_id, $doctor_id, $first_service['serviceId'], $diagnosis, $current_datetime]);
                    $record_id = $pdo->lastInsertId();
                    
                    // Create invoice
                    $reference_number = 'INV-' . date('YmdHis') . '-' . str_pad($record_id, 4, '0', STR_PAD_LEFT);
                    $insert_invoice_stmt = $pdo->prepare("
                        INSERT INTO tbl_invoice (record_id, reference_number, total_amount, payment_method, payment_status, paid_at)
                        VALUES (?, ?, ?, ?, 'Paid', ?)
                    ");
                    $insert_invoice_stmt->execute([$record_id, $reference_number, $total_amount, $payment_method, $current_datetime]);
                    
                    // Send payment confirmation email
                    require_once '../includes/email_notifications.php';
                    try {
                        sendPaymentConfirmation(
                            $pdo->lastInsertId(),
                            $patient_id,
                            $total_amount,
                            $payment_method,
                            $reference_number
                        );
                    } catch (Exception $e) {
                        error_log("Error sending payment confirmation email: " . $e->getMessage());
                        // Don't fail the payment if email fails
                    }
                    
                    $action_message = 'Payment processed successfully!';
                    $action_type = 'success';
                    
                    // Redirect to refresh the page and show the new invoice
                    header("Location: receptionist_payment.php?status=success&message=" . urlencode($action_message));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Error creating new payment: ' . $e->getMessage());
                $action_message = 'Failed to process payment.';
                $action_type = 'error';
            }
        } else {
            $action_message = 'Please fill in all required fields.';
            $action_type = 'error';
        }
    } elseif (!empty($_POST['save_draft'])) {
        // Save as draft (pending payment)
        $patient_id = intval($_POST['selected_patient_id'] ?? 0);
        $payment_method = sanitizeInput($_POST['payment_method'] ?? '');
        $billable_items_json = $_POST['billable_items_json'] ?? '[]';
        
        if ($patient_id > 0 && in_array($payment_method, ['Cash', 'GCash', 'Card']) && !empty($billable_items_json)) {
            try {
                $billable_items = json_decode($billable_items_json, true);
                if (!is_array($billable_items) || empty($billable_items)) {
                    $action_message = 'Please add at least one billable item.';
                    $action_type = 'error';
                } else {
                    // Get current user (receptionist) as the doctor for medical record
                    $doctor_id = $_SESSION['user_id'] ?? null;
                    if (!$doctor_id) {
                        // Fallback to first available doctor
                        $doctor_stmt = $pdo->prepare("SELECT user_id FROM tbl_user WHERE role = 'Dentist' AND status = 'Active' LIMIT 1");
                        $doctor_stmt->execute();
                        $doctor = $doctor_stmt->fetch();
                        $doctor_id = $doctor['user_id'] ?? 1;
                    }
                    
                    // Calculate total amount
                    $total_amount = 0;
                    foreach ($billable_items as $item) {
                        $total_amount += floatval($item['price']);
                    }
                    
                    // Create medical record for the first service (or primary service)
                    // Use PHP date to ensure timezone consistency
                    $current_datetime = date('Y-m-d H:i:s');
                    $first_service = $billable_items[0];
                    $insert_record_stmt = $pdo->prepare("
                        INSERT INTO tbl_medical_record (patient_id, doctor_id, service_id, diagnosis, date_of_treatment)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    $diagnosis = 'Payment for: ' . $first_service['name'];
                    $insert_record_stmt->execute([$patient_id, $doctor_id, $first_service['serviceId'], $diagnosis, $current_datetime]);
                    $record_id = $pdo->lastInsertId();
                    
                    // Create invoice with Pending status
                    $reference_number = 'INV-' . date('YmdHis') . '-' . str_pad($record_id, 4, '0', STR_PAD_LEFT);
                    $insert_invoice_stmt = $pdo->prepare("
                        INSERT INTO tbl_invoice (record_id, reference_number, total_amount, payment_method, payment_status)
                        VALUES (?, ?, ?, ?, 'Pending')
                    ");
                    $insert_invoice_stmt->execute([$record_id, $reference_number, $total_amount, $payment_method]);
                    
                    $action_message = 'Invoice saved as draft (Pending) successfully!';
                    $action_type = 'success';
                    
                    // Redirect to refresh the page and show the new invoice
                    header("Location: receptionist_payment.php?status=success&message=" . urlencode($action_message));
                    exit;
                }
            } catch (PDOException $e) {
                error_log('Error saving draft: ' . $e->getMessage());
                $action_message = 'Failed to save draft.';
                $action_type = 'error';
            }
        } else {
            $action_message = 'Please fill in all required fields.';
            $action_type = 'error';
        }
    }
}

// Fetches are handled via API for the side panel. 
// We only need basic page rendering logic here since the list is dynamic.
$current_date_time = date('l, M d') . ' • ' . date('g:i A');
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Receptionist Payments - Vanessa Nicolas Dental</title>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
      tailwind.config = {
        darkMode: "class",
        theme: {
          extend: {
            colors: {
              "primary": "#8a11d4",
              "royal-purple": "#6A1B9A",
              "clinic-gold": "#FFD700",
              "background-light": "#f7f6f8",
              "background-dark": "#1b1022",
            },
            fontFamily: {
              "display": ["Manrope", "sans-serif"]
            },
            borderRadius: {"DEFAULT": "0.5rem", "lg": "1rem", "xl": "1.5rem", "full": "9999px"},
          },
        },
      }
    </script>
<style>.scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }.custom-radio:checked + div {
            border-color: #6A1B9A;
            background-color: #f3e8f9;
            color: #6A1B9A;
        }
        .custom-radio:checked + div .check-icon {
            display: block;
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-[#151118] font-display antialiased overflow-hidden">
<div class="flex h-screen w-full">
<aside class="w-72 bg-royal-purple text-white flex flex-col flex-shrink-0 h-full shadow-2xl z-20 relative">
<div class="p-8 pb-4">
<div class="flex flex-col gap-1">
<h1 class="text-xl font-bold leading-tight tracking-wide">Vanessa Nicolas</h1>
<p class="text-white/70 text-sm font-medium">Dental Clinic System</p>
</div>
</div>
<div class="px-8 py-4 mb-2">
<a href="receptionist_current_profile.php" class="flex items-center gap-3 bg-white/10 p-3 rounded-xl border border-white/10 backdrop-blur-sm hover:bg-white/20 transition-all cursor-pointer group">
<?php if ($current_profile && file_exists('../' . $current_profile)): ?>
<div class="w-10 h-10 rounded-full bg-cover bg-center flex-shrink-0 border-2 border-transparent group-hover:border-white/50 transition-all" style="background-image: url('../<?php echo htmlspecialchars($current_profile); ?>');"></div>
<?php else: ?>
<div class="w-10 h-10 rounded-full flex items-center justify-center font-bold text-lg flex-shrink-0 border-2 border-transparent group-hover:border-white/50 transition-all" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;">
<?php echo htmlspecialchars($default_photo['initials']); ?>
</div>
<?php endif; ?>
<div class="flex flex-col overflow-hidden">
<span class="text-sm font-bold truncate group-hover:text-white transition-colors"><?php echo htmlspecialchars($current_name); ?></span>
<span class="text-xs text-white/70 truncate group-hover:text-white/90 transition-colors"><?php echo htmlspecialchars($current_role); ?></span>
</div>
</a>
</div>
<nav class="flex-1 px-4 gap-2 flex flex-col overflow-y-auto py-4">
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_dashboard.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_dashboard.php">
<span class="material-symbols-outlined">dashboard</span>
<span class="<?php echo ($current_page === 'receptionist_dashboard.php') ? 'font-bold' : 'font-medium'; ?>">Dashboard</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_registration.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_registration.php">
<span class="material-symbols-outlined">person_add</span>
<span class="<?php echo ($current_page === 'receptionist_registration.php') ? 'font-bold' : 'font-medium'; ?>">Patient Registration</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="patient_records.php">
<span class="material-symbols-outlined">folder_shared</span>
<span class="<?php echo ($current_page === 'patient_records.php' || $current_page === 'patient_information.php') ? 'font-bold' : 'font-medium'; ?>">Patient Records</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_booking.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_booking.php">
<span class="material-symbols-outlined">calendar_month</span>
<span class="<?php echo ($current_page === 'receptionist_booking.php') ? 'font-bold' : 'font-medium'; ?>">Bookings</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_queue.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_queue.php">
<span class="material-symbols-outlined">monitor_heart</span>
<span class="<?php echo ($current_page === 'receptionist_queue.php') ? 'font-bold' : 'font-medium'; ?>">Queue Monitor</span>
</a>
<a class="flex items-center gap-4 px-4 py-3 rounded-xl <?php echo ($current_page === 'receptionist_payment.php') ? 'bg-white text-royal-purple shadow-lg' : 'hover:bg-white/10 text-white/90 hover:text-white'; ?> transition-all group" href="receptionist_payment.php">
<span class="material-symbols-outlined">payments</span>
<span class="<?php echo ($current_page === 'receptionist_payment.php') ? 'font-bold' : 'font-medium'; ?>">Payments</span>
</a>
</nav>
<div class="p-6">
<div class="text-xs text-white/40 text-center font-medium">v2.4.0 • Reception Portal</div>
<a href="../logout.php" class="flex items-center justify-center gap-2 mt-4 w-full py-2 rounded-xl text-white/70 hover:text-white hover:bg-white/10 transition-colors text-sm font-medium">
<span class="material-symbols-outlined text-[18px]">logout</span>
Logout
</a>
</div>
</aside>
<main class="flex-1 flex flex-col h-full overflow-hidden bg-background-light relative">
<header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
<div class="flex flex-col gap-1">
    <div class="flex items-center gap-4">
        <h2 class="text-3xl font-black text-[#151118] tracking-tight">Payments &amp; Invoicing</h2>
        <!-- Tabs -->
        <div class="flex bg-gray-100 p-1 rounded-xl ml-4">
            <a href="#" class="px-4 py-1.5 rounded-lg bg-white text-royal-purple shadow-sm font-bold text-xs ring-1 ring-black/5">Transactions</a>
            <a href="receptionist_installments.php" class="px-4 py-1.5 rounded-lg text-slate-600 font-bold text-xs hover:bg-white hover:shadow-sm transition-all">Installments</a>
        </div>
    </div>
    <p class="text-slate-500 font-medium flex items-center gap-2">
        <span class="material-symbols-outlined text-lg">schedule</span>
        <?php echo $current_date_time; ?>
    </p>
</div>
    <div class="flex items-center gap-4">
        <button onclick="toggleQRModal()" class="h-10 w-10 flex items-center justify-center bg-white border border-gray-200 hover:bg-gray-50 text-slate-600 rounded-xl transition-colors mr-2" title="View GCash QR">
            <span class="material-symbols-outlined text-sm">qr_code_scanner</span>
        </button>
        <a href="receptionist_payment.php" class="h-10 px-4 bg-white border border-gray-200 hover:bg-gray-50 text-slate-600 rounded-xl font-bold flex items-center gap-2 transition-all">
            <span class="material-symbols-outlined text-sm">arrow_back</span>
            <span>History</span>
        </a>
    </div>
</header>
<div class="flex-1 overflow-hidden p-8">
    <?php if ($action_message): ?>
    <div class="max-w-[1600px] mx-auto mb-6">
        <div class="<?php echo $action_type === 'success' ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'; ?> border rounded-xl p-4 flex items-start gap-3">
            <span class="material-symbols-outlined"><?php echo $action_type === 'success' ? 'check_circle' : 'error'; ?></span>
            <div class="flex-1">
                <p class="font-bold text-sm"><?php echo $action_type === 'success' ? 'Success!' : 'Error'; ?></p>
                <p class="text-sm mt-1"><?php echo htmlspecialchars($action_message); ?></p>
            </div>
            <button onclick="this.parentElement.parentElement.remove()" class="text-current opacity-50 hover:opacity-100">
                <span class="material-symbols-outlined text-sm">close</span>
            </button>
        </div>
    </div>
    <?php endif; ?>
    <div class="max-w-[1600px] mx-auto flex h-full gap-6">
        
        <!-- Left Panel: Transaction Selection -->
        <div class="w-1/3 bg-white rounded-2xl shadow-sm border border-gray-100 flex flex-col overflow-hidden h-full">
            <div class="p-6 border-b border-gray-100 bg-gray-50/50">
                <h3 class="font-bold text-lg text-royal-purple mb-4">Select Transaction</h3>
                <div class="flex gap-2">
                    <div class="relative flex-1">
                        <span class="absolute left-3 top-2.5 text-slate-400 material-symbols-outlined text-sm">search</span>
                        <input type="text" id="transactionSearch" onkeypress="if(event.key === 'Enter') searchTransactions()" class="w-full pl-9 pr-4 py-2 text-sm border border-gray-200 rounded-lg focus:outline-none focus:border-royal-purple focus:ring-1 focus:ring-royal-purple" placeholder="Search patient or service...">
                    </div>
                    <button onclick="searchTransactions()" class="px-4 py-2 bg-royal-purple hover:bg-purple-800 text-white rounded-lg font-bold text-sm transition-colors shadow-sm flex items-center gap-2">
                        <span>Search</span>
                    </button>
                </div>
            </div>
            
            <!-- List Container -->
            <div class="flex-1 overflow-y-auto" id="transactionModalList">
                <div class="text-center py-12 text-slate-400">
                    <span class="material-symbols-outlined text-4xl mb-2 block animate-spin">hourglass_empty</span>
                    <p class="text-sm font-medium">Loading transactions...</p>
                </div>
            </div>
            
            <!-- Pagination -->
            <div class="p-4 border-t border-gray-100 bg-gray-50 flex justify-between items-center" id="transactionModalPagination">
                <span class="text-xs text-slate-500 font-medium" id="transactionPageInfo">Page 1</span>
                <div class="flex gap-2">
                    <button id="prevTransactionPageBtn" onclick="currentTransactionPage--; loadTransactionPage()" class="p-1.5 rounded hover:bg-gray-200 text-slate-500 disabled:opacity-50" disabled>
                        <span class="material-symbols-outlined text-sm">chevron_left</span>
                    </button>
                    <button id="nextTransactionPageBtn" onclick="currentTransactionPage++; loadTransactionPage()" class="p-1.5 rounded hover:bg-gray-200 text-slate-500 disabled:opacity-50" disabled>
                        <span class="material-symbols-outlined text-sm">chevron_right</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Right Panel: Payment Form -->
        <div class="flex-1 bg-white rounded-2xl shadow-lg border border-gray-100 flex flex-col h-full relative overflow-hidden">
            <div class="bg-royal-purple p-6 text-white relative overflow-hidden flex-shrink-0">
                <div class="absolute -right-6 -top-6 w-32 h-32 bg-clinic-gold rounded-full opacity-10 blur-2xl"></div>
                <h3 class="font-bold text-lg relative z-10 flex items-center gap-2">
                    <span class="material-symbols-outlined">point_of_sale</span>
                    Process Payment
                </h3>
            </div>
            
            <div class="p-8 flex-1 overflow-y-auto">
                <div id="selectedTransactionDisplay" class="hidden h-full flex flex-col">
                    <!-- Transaction Summary -->
                    <div class="flex items-start gap-4 p-5 bg-purple-50 rounded-xl border border-purple-100 mb-6">
                         <div class="w-12 h-12 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-lg flex-shrink-0" id="selectedTransactionInitials">--</div>
                         <div class="flex-1">
                             <p class="text-lg font-bold text-royal-purple" id="selectedTransactionPatient">--</p>
                             <p class="text-sm text-slate-500" id="selectedTransactionService">--</p>
                             <p class="text-xs text-slate-500 mt-1" id="selectedTransactionDate">--</p>
                         </div>
                         <div class="text-right">
                             <p class="text-2xl font-black text-royal-purple" id="selectedTransactionAmount">--</p>
                             <p class="text-xs text-slate-400 font-medium mt-1">Amount Due</p>
                         </div>
                    </div>
                    
                    <!-- Appointment Fee Breakdown (hidden by default, shown for appointment fees) -->
                    <div id="appointmentFeeBreakdown" class="hidden mb-6 bg-blue-50 p-4 rounded-xl border border-blue-100">
                        <h4 class="text-xs font-bold text-blue-800 uppercase mb-3 flex items-center gap-2">
                            <span class="material-symbols-outlined text-sm">receipt</span>
                            Appointment Fee Breakdown
                        </h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between items-center">
                                <span class="text-slate-600">Base Fee (first 30 min):</span>
                                <span class="font-bold text-[#151118]" id="breakdownBaseFee">₱0.00</span>
                            </div>
                            <div id="breakdownOvertimeSection" class="hidden">
                                <div class="flex justify-between items-center">
                                    <span class="text-slate-600" id="breakdownOvertimeLabel">Overtime:</span>
                                    <span class="font-bold text-[#151118]" id="breakdownOvertimeFee">₱0.00</span>
                                </div>
                            </div>
                            <div class="border-t border-blue-200 pt-2 mt-2">
                                <div class="flex justify-between items-center">
                                    <span class="font-bold text-slate-700">Total Appointment Fee:</span>
                                    <span class="font-black text-lg text-royal-purple" id="breakdownTotal">₱0.00</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Form -->
                    <form method="POST" action="" id="transactionPaymentForm" class="flex-1 flex flex-col" enctype="multipart/form-data">
                        <input type="hidden" name="invoice_id" id="selectedInvoiceId">
                        <input type="hidden" name="process_payment" id="processPaymentTrigger" value="0">
                        <input type="hidden" name="record_id" id="selectedRecordId">
                        <input type="hidden" name="appointment_id" id="selectedAppointmentId">
                        <input type="hidden" name="service_id" id="selectedServiceId">
                        <input type="hidden" name="create_invoice_from_transaction" id="transactionActionTrigger" value="1">
                        <input type="hidden" name="discount_type" id="discount_type" value="None">
                        <input type="hidden" name="discount_id_number" id="discount_id_number" value="">
                        <input type="hidden" name="discount_id_issuer" id="discount_id_issuer" value="">
                        <input type="hidden" name="discount_date_verified" id="discount_date_verified" value="">
                        <input type="file" name="discount_id_image" id="discount_id_image" class="hidden" accept="image/*" onchange="updateFileName()">
                        <input type="hidden" id="original_service_price" value="0">
                        <input type="hidden" id="original_is_installment" value="0">
                        <input type="hidden" id="installment_months" name="installment_months" value="12">
                        <input type="hidden" id="installment_monthly_db" value="0">
                        
                        <div id="singlePaymentFormContainer" class="flex flex-col gap-6 flex-1 pb-20 overflow-y-auto px-1">
                            <!-- Payment Scheme -->
                            <div>
                                <label class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-3 block">Payment Scheme</label>
                                <div class="grid grid-cols-2 gap-4">
                                    <label class="relative flex items-center justify-center p-4 rounded-xl border-2 border-gray-100 cursor-pointer hover:bg-gray-50 transition-all has-[:checked]:border-royal-purple has-[:checked]:bg-purple-50 group">
                                        <input type="radio" name="payment_scheme" value="full" class="peer hidden" checked onchange="toggleTransactionPaymentScheme()">
                                        <div class="flex flex-col items-center gap-1">
                                             <span class="font-bold text-slate-700 peer-checked:text-royal-purple text-base">Full Payment</span>
                                             <span class="text-xs text-slate-400">Pay total upfront</span>
                                        </div>
                                        <div class="absolute top-3 right-3 w-5 h-5 rounded-full border border-gray-300 peer-checked:bg-royal-purple peer-checked:border-royal-purple flex items-center justify-center text-white transition-all">
                                            <span class="material-symbols-outlined text-xs">check</span>
                                        </div>
                                    </label>
                                    <label class="relative flex items-center justify-center p-4 rounded-xl border-2 border-gray-100 cursor-pointer hover:bg-gray-50 transition-all has-[:checked]:border-royal-purple has-[:checked]:bg-purple-50 group">
                                        <input type="radio" name="payment_scheme" value="installment" class="peer hidden" onchange="toggleTransactionPaymentScheme()">
                                        <div class="flex flex-col items-center gap-1">
                                             <span class="font-bold text-slate-700 peer-checked:text-royal-purple text-base">Installment</span>
                                             <span class="text-xs text-slate-400">Downpayment + Monthly</span>
                                        </div>
                                        <div class="absolute top-3 right-3 w-5 h-5 rounded-full border border-gray-300 peer-checked:bg-royal-purple peer-checked:border-royal-purple flex items-center justify-center text-white transition-all">
                                            <span class="material-symbols-outlined text-xs">check</span>
                                        </div>
                                    </label>
                                </div>
                            </div>
                            
                            <!-- Installment Payment Config (Removed) -->
                            <!-- <div id="installmentConfigContainer" class="hidden mb-4"> ... </div> -->
                            
                            <!-- Installment Details -->
                            <div id="installmentDetailsDisplay" class="hidden bg-blue-50 p-4 rounded-xl border border-blue-100">
                                <h4 class="text-xs font-bold text-blue-800 uppercase mb-2">Installment Plan</h4>
                                <div id="installmentPlanBreakdown" class="mb-2 pb-2 border-b border-blue-200 text-sm hidden space-y-1"></div>
                                <div class="grid grid-cols-2 gap-y-1 gap-x-4 text-sm">
                                    <span class="text-slate-600">Duration:</span>
                                    <span class="font-bold text-[#151118]" id="displayDuration">--</span>
                                    
                                    <span class="text-slate-600">Monthly Payment:</span>
                                    <span class="font-bold text-[#151118]" id="displayMonthly">--</span>
                                    
                                    <span class="text-slate-600" id="labelMonthsLeft">Months Left:</span>
                                    <span class="font-bold text-[#151118]" id="displayMonthsLeft">--</span>
                                    
                                    <span class="text-slate-600">Balance:</span>
                                    <span class="font-bold text-[#151118]" id="displayBalance">--</span>
                                </div>
                                <div class="flex justify-between items-center text-sm mt-3 border-t border-blue-200 pt-2" id="containerRequiredAmount">
                                    <span class="text-slate-600 font-bold">Required Amount:</span>
                                    <span class="font-bold text-royal-purple" id="displayRequiredAmount">--</span>
                                </div>
                            </div>
                            
                            <hr class="border-gray-100">
                            
                            <!-- Payment Details -->
                            <div>
                                <input type="hidden" name="payment_amount" id="transactionPaymentInput">
                                
                                <!-- Payment Input for Installments (Visible) -->
                                <div id="installmentPaymentInputContainer" class="hidden mb-6">
                                     <label class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 block">Payment Amount</label>
                                     <div class="relative">
                                        <span class="absolute left-3 top-3 text-slate-400 font-bold">₱</span>
                                        <input type="number" step="0.01" id="visibleInstallmentInput" class="w-full pl-8 pr-4 py-3 bg-white border border-gray-200 rounded-xl font-bold text-lg text-[#151118] focus:outline-none focus:border-royal-purple focus:ring-1 focus:ring-royal-purple" placeholder="0.00" oninput="document.getElementById('transactionPaymentInput').value = this.value; document.querySelector('#totalServiceAmountDisplay span').textContent = '₱' + parseFloat(this.value || 0).toLocaleString(undefined, {minimumFractionDigits: 2})">
                                     </div>
                                </div>
                                
                                <div id="transactionDpContainer" class="hidden mb-6">
                                     <label class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 block">Down Payment</label>
                                     <div class="relative">
                                        <span class="absolute left-3 top-3 text-slate-400 font-bold">₱</span>
                                        <input type="number" step="0.01" name="down_payment_amount" id="transactionDpInput" class="w-full pl-8 pr-4 py-3 bg-white border border-gray-200 rounded-xl font-bold text-lg text-[#151118] focus:outline-none focus:border-royal-purple focus:ring-1 focus:ring-royal-purple" placeholder="Min. 20%">
                                     </div>
                                </div>
                            </div>
                            
                            <!-- Discount Section -->
                            <!-- Service Breakdown Section -->
                            <div id="serviceBreakdownContainer" class="hidden mb-4 bg-white rounded-xl border border-gray-200 p-4 shadow-sm">
                                <h4 class="text-[10px] font-bold text-slate-400 uppercase tracking-wide mb-3 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-sm">receipt_long</span> Service Breakdown
                                </h4>
                                <div id="serviceBreakdownList" class="flex flex-col gap-2"></div>
                                <div class="border-t border-dashed border-gray-200 mt-3 pt-2 flex justify-between items-center">
                                    <span class="text-xs font-bold text-slate-600">Subtotal</span>
                                    <span class="text-xs font-bold text-slate-800" id="breakdownSubtotal">₱0.00</span>
                                </div>
                            </div>

                            <!-- Discount Section -->
                            <div id="discountSectionContainer" class="bg-gray-50 rounded-xl border border-gray-100 p-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-10 rounded-full bg-blue-100 text-blue-600 flex items-center justify-center">
                                            <span class="material-symbols-outlined">percent</span>
                                        </div>
                                        <div>
                                            <p class="font-bold text-sm text-[#151118]" id="discountLabel">Discount</p>
                                            <p class="text-xs text-slate-500" id="discountSubLabel">Apply Senior/PWD discount</p>
                                        </div>
                                    </div>
                                    <div class="flex gap-2">
                                        <button type="button" id="btnRemoveDiscount" onclick="removeDiscount()" class="hidden px-3 py-2 bg-red-50 text-red-600 border border-red-100 font-bold text-xs rounded-lg hover:bg-red-100 transition-all">
                                            Remove
                                        </button>
                                        <button type="button" id="btnDiscount" onclick="openDiscountModal()" class="px-4 py-2 bg-white border border-gray-200 text-slate-600 font-bold text-xs rounded-lg hover:bg-gray-50 hover:text-royal-purple transition-all shadow-sm">
                                            Apply Discount
                                        </button>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Payment Method -->
                            <div class="flex flex-col gap-2 mb-6">
                                <label class="text-xs font-bold text-slate-500 uppercase tracking-wide">Payment Method</label>
                                <div class="grid grid-cols-2 gap-2">
                                    <label class="cursor-pointer">
                                        <input class="custom-radio hidden" name="payment_method" value="Cash" type="radio" checked onchange="togglePaymentReference()"/>
                                        <div class="border border-gray-200 rounded-xl p-2 flex flex-col items-center justify-center gap-1 hover:border-royal-purple/50 transition-all h-20 text-slate-500">
                                            <span class="material-symbols-outlined">payments</span>
                                            <span class="text-[10px] font-bold">Cash</span>
                                            <span class="check-icon hidden material-symbols-outlined text-[10px] absolute top-1 right-1">check_circle</span>
                                        </div>
                                    </label>
                                    <label class="cursor-pointer">
                                        <input class="custom-radio hidden" name="payment_method" value="GCash" type="radio" onchange="togglePaymentReference()"/>
                                        <div class="border border-gray-200 rounded-xl p-2 flex flex-col items-center justify-center gap-1 hover:border-royal-purple/50 transition-all h-20 text-slate-500">
                                            <span class="material-symbols-outlined">phone_android</span>
                                            <span class="text-[10px] font-bold">GCash</span>
                                            <span class="check-icon hidden material-symbols-outlined text-[10px] absolute top-1 right-1">check_circle</span>
                                        </div>
                                    </label>
                                </div>
                                <label class="cursor-pointer hidden">
                                    <input class="custom-radio hidden" name="payment_method" value="Cash" type="radio" onchange="togglePaymentReference()"/>
                                </label>
                            </div>

                            <!-- Payment Reference Input (GCash) -->
                            <div id="paymentReferenceContainer" class="hidden mb-6">
                                <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100 mb-4 text-center">
                                    <p class="text-[10px] font-bold text-blue-800 uppercase tracking-wider mb-2">Scan QR to Pay</p>
                                    <div class="bg-white p-2 rounded-lg shadow-sm w-fit mx-auto mb-2">
                                        <img src="../images/Gcash.jpg" class="w-32 h-32 object-contain">
                                    </div>
                                    <p class="font-black text-lg text-slate-800 tracking-wide">0962 878 6207</p>
                                </div>

                                <label class="text-xs font-bold text-slate-500 uppercase tracking-wide mb-2 block">Reference Number <span class="text-red-500">*</span></label>
                                <input type="text" name="payment_reference" id="paymentReferenceInput" class="w-full px-4 py-3 bg-gray-50 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-royal-purple transition-colors" placeholder="Enter GCash Reference No.">
                            </div>

                            <!-- Total Display -->
                            <div class="flex items-end justify-between mt-auto pt-6 border-t border-gray-100">
                                <div class="flex flex-col">
                                    <span class="text-sm font-medium text-slate-500">Total Amount</span>
                                    <div class="flex items-center" id="totalServiceAmountDisplay">
                                        <span class="text-3xl font-black text-royal-purple">₱0.00</span>
                                    </div>
                                </div>
                                <button type="submit" id="btnProcessPayment" class="h-14 px-8 bg-clinic-gold hover:bg-yellow-400 text-royal-purple rounded-xl font-black text-lg shadow-lg shadow-yellow-400/20 transform active:scale-95 transition-all flex items-center gap-2">
                                    <span class="material-symbols-outlined">payments</span>
                                    <span id="processBtnText">Process Payment</span>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
                
                <!-- Empty State -->
                <div id="emptyTransactionState" class="h-full flex flex-col items-center justify-center text-center p-8 text-slate-400">
                     <div class="w-24 h-24 bg-gray-50 rounded-full flex items-center justify-center mb-4">
                         <span class="material-symbols-outlined text-5xl opacity-40">receipt_long</span>
                     </div>
                     <h3 class="text-xl font-bold text-slate-600 mb-2">No Transaction Selected</h3>
                     <p class="max-w-xs text-sm">Select an unpaid transaction from the list on the left to view details and process payment.</p>
                </div>
            </div>
        </div>
    </div>

    </div>

</div>


</div>
</div>
</main>
</div>

<!-- Transaction Selection Modal -->


<!-- Custom Popup Modal -->

<!-- Discount Modal -->
<div id="discountModal" class="hidden fixed inset-0 z-[70] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full overflow-hidden transform transition-all scale-95 opacity-0" id="discountModalContent">
        <div class="p-6 border-b border-gray-100 flex justify-between items-center">
            <h3 class="font-bold text-royal-purple text-lg">Apply Discount</h3>
            <button onclick="closeDiscountModal()" class="text-slate-400 hover:text-red-500">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <div>
                <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Discount Type</label>
                <select id="modal_discount_type" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple outline-none">
                    <option value="None">None</option>
                    <option value="Senior">Senior Citizen (20% + VAT Ex)</option>
                    <option value="PWD">PWD (20% + VAT Ex)</option>
                </select>
            </div>
            <div id="discount_fields" class="hidden space-y-4">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">ID Number <span class="text-red-500">*</span></label>
                        <input type="text" id="modal_id_number" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple outline-none" placeholder="ID No.">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Issuer <span class="text-red-500">*</span></label>
                        <input type="text" id="modal_id_issuer" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple outline-none" placeholder="e.g. OSCA">
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase mb-1">Date Verified</label>
                    <input type="date" id="modal_date_verified" class="w-full bg-gray-50 border border-gray-200 rounded-xl px-4 py-3 text-sm focus:border-royal-purple outline-none" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div>
                     <label class="block text-xs font-bold text-slate-500 uppercase mb-1">ID Picture (Optional)</label>
                     <div class="flex items-center gap-3">
                         <button onclick="triggerFileUpload()" class="px-4 py-2 bg-gray-100 hover:bg-gray-200 text-slate-600 rounded-lg text-xs font-bold border border-gray-300 transition-colors flex items-center gap-2">
                             <span class="material-symbols-outlined text-sm">upload_file</span>
                             Upload Image
                         </button>
                         <span id="file_name_display" class="text-xs text-slate-400 italic">No file chosen</span>
                     </div>
                </div>
            </div>
        </div>
        <div class="p-6 border-t border-gray-100 bg-gray-50 flex justify-end gap-3">
            <button onclick="closeDiscountModal()" class="px-4 py-2 text-slate-500 font-bold hover:bg-gray-200 rounded-xl transition-colors">Cancel</button>
            <button onclick="applyDiscount()" class="px-6 py-2 bg-royal-purple text-white font-bold rounded-xl shadow-lg shadow-purple-200 hover:bg-purple-800 transition-colors">Apply Discount</button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize things safely
    const discountSelect = document.getElementById('modal_discount_type');
    if (discountSelect) {
        discountSelect.addEventListener('change', toggleDiscountFields);
    }
});

function openDiscountModal() {
    const modal = document.getElementById('discountModal');
    const content = document.getElementById('discountModalContent');
    const currentType = document.getElementById('discount_type').value;
    
    const modalType = document.getElementById('modal_discount_type');
    if(modalType) modalType.value = currentType;

    if(currentType !== 'None') {
        const savedId = document.getElementById('discount_id_number').value;
        const savedIssuer = document.getElementById('discount_id_issuer').value;
        
        const modalId = document.getElementById('modal_id_number');
        const modalIssuer = document.getElementById('modal_id_issuer');
        
        if(modalId) modalId.value = savedId;
        if(modalIssuer) modalIssuer.value = savedIssuer;
        
        // Date
        const currentSavedDate = document.getElementById('discount_date_verified').value;
        const modalDate = document.getElementById('modal_date_verified');
        if(currentSavedDate && modalDate) {
             modalDate.value = currentSavedDate;
        }
        
    } else {
        const modalId = document.getElementById('modal_id_number');
        const modalIssuer = document.getElementById('modal_id_issuer');
        if(modalId) modalId.value = '';
        if(modalIssuer) modalIssuer.value = '';
    }
    
    toggleDiscountFields();
    
    if(modal) {
        modal.classList.remove('hidden');
        if(content) {
            setTimeout(function() {
                content.classList.remove('scale-95', 'opacity-0');
                content.classList.add('scale-100', 'opacity-100');
            }, 10);
        }
    }
}

function closeDiscountModal() {
    const modal = document.getElementById('discountModal');
    const content = document.getElementById('discountModalContent');
    if(content) {
        content.classList.remove('scale-100', 'opacity-100');
        content.classList.add('scale-95', 'opacity-0');
    }
    if(modal) {
        setTimeout(function() {
            modal.classList.add('hidden');
        }, 200);
    }
}

function toggleDiscountFields() {
    const typeEl = document.getElementById('modal_discount_type');
    const fields = document.getElementById('discount_fields');
    
    if (!typeEl || !fields) return;
    
    if (typeEl.value !== 'None') {
        fields.classList.remove('hidden');
        
        // Auto-fill Issuer for convenience
        const issuerEl = document.getElementById('modal_id_issuer');
        if (issuerEl && !issuerEl.value) { // Only auto-fill if empty
            if (typeEl.value === 'Senior') {
                issuerEl.value = 'OSCA';
            } else if (typeEl.value === 'PWD') {
                issuerEl.value = 'NCDA';
            }
        }
    } else {
        fields.classList.add('hidden');
    }
}

function applyDiscount() {
    const typeEl = document.getElementById('modal_discount_type');
    const idNumEl = document.getElementById('modal_id_number');
    const issuerEl = document.getElementById('modal_id_issuer');
    const dateVerifiedEl = document.getElementById('modal_date_verified');
    
    if (!typeEl) return;
    const type = typeEl.value;
    
    let idNum = '';
    let issuer = '';
    let dateVerified = '';
    
    if (type !== 'None') {
        if(idNumEl) idNum = idNumEl.value;
        if(issuerEl) issuer = issuerEl.value;
        if(dateVerifiedEl) dateVerified = dateVerifiedEl.value;
        
        if (!idNum || !issuer) {
            alert('Please enter ID Number and Issuer.');
            return;
        }
    }
    
    // Update hidden form fields
    const targetType = document.getElementById('discount_type');
    const targetId = document.getElementById('discount_id_number');
    const targetIssuer = document.getElementById('discount_id_issuer');
    const targetDate = document.getElementById('discount_date_verified');
    
    if(targetType) targetType.value = type;
    if(targetId) targetId.value = (type === 'None' ? '' : idNum);
    if(targetIssuer) targetIssuer.value = (type === 'None' ? '' : issuer);
    if(targetDate) targetDate.value = (type === 'None' ? '' : dateVerified);
    
    // Update UI Labels and Buttons
    const discountLabel = document.getElementById('discountLabel');
    const discountSubLabel = document.getElementById('discountSubLabel');
    const btnRemove = document.getElementById('btnRemoveDiscount');
    const btnApply = document.getElementById('btnDiscount');
    
    if (type !== 'None') {
        if (discountLabel) discountLabel.textContent = type + ' Discount';
        if (discountSubLabel) discountSubLabel.textContent = '20% + VAT Exemption Applied';
        if (btnRemove) btnRemove.classList.remove('hidden');
        if (btnApply) {
            btnApply.textContent = 'Edit Discount';
            btnApply.classList.replace('bg-white', 'bg-purple-50');
            btnApply.classList.add('text-royal-purple');
        }
    } else {
        if (discountLabel) discountLabel.textContent = 'Discount';
        if (discountSubLabel) discountSubLabel.textContent = 'Apply Senior/PWD discount';
        if (btnRemove) btnRemove.classList.add('hidden');
        if (btnApply) {
            btnApply.textContent = 'Apply Discount';
            btnApply.classList.replace('bg-purple-50', 'bg-white');
            btnApply.classList.remove('text-royal-purple');
        }
    }
    
    closeDiscountModal();
    if (typeof updateTransactionPayment === 'function') {
        updateTransactionPayment();
    }
}

function removeDiscount() {
    // Reset hidden fields
    const targetType = document.getElementById('discount_type');
    const targetId = document.getElementById('discount_id_number');
    const targetIssuer = document.getElementById('discount_id_issuer');
    const targetDate = document.getElementById('discount_date_verified');
    
    if(targetType) targetType.value = 'None';
    if(targetId) targetId.value = '';
    if(targetIssuer) targetIssuer.value = '';
    if(targetDate) targetDate.value = '';
    
    // Reset Modal Fields
    const typeEl = document.getElementById('modal_discount_type');
    const idNumEl = document.getElementById('modal_id_number');
    const issuerEl = document.getElementById('modal_id_issuer');
    
    if(typeEl) typeEl.value = 'None';
    if(idNumEl) idNumEl.value = '';
    if(issuerEl) issuerEl.value = '';
    
    // Hide fields in modal
    const fields = document.getElementById('discount_fields');
    if(fields) fields.classList.add('hidden');
    
    // Update UI
    const discountLabel = document.getElementById('discountLabel');
    const discountSubLabel = document.getElementById('discountSubLabel');
    const btnRemove = document.getElementById('btnRemoveDiscount');
    const btnApply = document.getElementById('btnDiscount');
    
    // Reset file input
    const fileInput = document.getElementById('discount_id_image');
    if (fileInput) fileInput.value = '';
    
    const fileNameDisplay = document.getElementById('file_name_display');
    if (fileNameDisplay) {
        fileNameDisplay.textContent = 'No file chosen';
        fileNameDisplay.classList.add('text-slate-400', 'italic');
        fileNameDisplay.classList.remove('text-royal-purple', 'font-medium');
    }
    
    if (discountLabel) discountLabel.textContent = 'Discount';
    if (discountSubLabel) discountSubLabel.textContent = 'Apply Senior/PWD discount';
    if (btnRemove) btnRemove.classList.add('hidden');
    if (btnApply) {
        btnApply.textContent = 'Apply Discount';
        btnApply.classList.replace('bg-purple-50', 'bg-white');
        btnApply.classList.remove('text-royal-purple');
    }
    
    if (typeof updateTransactionPayment === 'function') {
        updateTransactionPayment();
    }
}

function triggerFileUpload() {
    const fileInput = document.getElementById('discount_id_image');
    if (fileInput) {
        fileInput.click();
    }
}

function updateFileName() {
    const fileInput = document.getElementById('discount_id_image');
    const fileNameDisplay = document.getElementById('file_name_display');
    
    if (fileInput && fileInput.files && fileInput.files.length > 0) {
        if (fileNameDisplay) {
            fileNameDisplay.textContent = fileInput.files[0].name;
            fileNameDisplay.classList.remove('text-slate-400', 'italic');
            fileNameDisplay.classList.add('text-royal-purple', 'font-medium');
        }
    } else {
        if (fileNameDisplay) {
            fileNameDisplay.textContent = 'No file chosen';
            fileNameDisplay.classList.add('text-slate-400', 'italic');
            fileNameDisplay.classList.remove('text-royal-purple', 'font-medium');
        }
    }
}
</script>
<div id="customPopup" class="hidden fixed inset-0 z-[60] flex items-center justify-center bg-black/60 backdrop-blur-sm">
    <div class="bg-white rounded-2xl p-6 max-w-md w-[90%] shadow-2xl transform transition-all duration-300 scale-95 opacity-0" id="popupContent">
        <div class="flex items-center gap-4 mb-4">
            <div id="popupIcon" class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0">
                <span class="material-symbols-outlined text-2xl"></span>
            </div>
            <h3 id="popupTitle" class="text-lg font-bold text-[#151118] flex-1"></h3>
        </div>
        <p id="popupMessage" class="text-slate-600 mb-6 whitespace-pre-line"></p>
        <div id="popupButtons" class="flex gap-3 justify-end"></div>
    </div>
</div>

<script>
// Custom Popup System
(function() {
    const popup = document.getElementById('customPopup');
    const popupContent = document.getElementById('popupContent');
    const popupIcon = document.getElementById('popupIcon');
    const popupTitle = document.getElementById('popupTitle');
    const popupMessage = document.getElementById('popupMessage');
    const popupButtons = document.getElementById('popupButtons');
    
    let currentResolve = null;
    
    function showPopup(type, title, message) {
        return new Promise((resolve) => {
            currentResolve = resolve;
            
            // Set icon and colors based on type (using purple and gold theme)
            const iconMap = {
                'alert': { icon: 'info', color: 'bg-purple-100 text-royal-purple' },
                'confirm': { icon: 'help', color: 'bg-yellow-100 text-yellow-700' },
                'success': { icon: 'check_circle', color: 'bg-green-100 text-green-700' },
                'error': { icon: 'error', color: 'bg-red-100 text-red-700' }
            };
            
            const config = iconMap[type] || iconMap['alert'];
            popupIcon.className = `w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0 ${config.color}`;
            popupIcon.querySelector('span').textContent = config.icon;
            
            popupTitle.textContent = title;
            popupMessage.textContent = message;
            
            // Create buttons
            popupButtons.innerHTML = '';
            if (type === 'confirm') {
                const cancelBtn = document.createElement('button');
                cancelBtn.className = 'px-4 py-2 rounded-xl bg-gray-100 text-slate-600 hover:bg-gray-200 font-bold text-sm transition-all';
                cancelBtn.textContent = 'Cancel';
                cancelBtn.onclick = () => closePopup(false);
                
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-royal-purple text-white hover:bg-purple-800 font-bold text-sm transition-all shadow-md';
                okBtn.textContent = 'Confirm';
                okBtn.onclick = () => closePopup(true);
                
                popupButtons.appendChild(cancelBtn);
                popupButtons.appendChild(okBtn);
            } else {
                const okBtn = document.createElement('button');
                okBtn.className = 'px-4 py-2 rounded-xl bg-royal-purple text-white hover:bg-purple-800 font-bold text-sm transition-all shadow-md';
                okBtn.textContent = 'OK';
                okBtn.onclick = () => closePopup(true);
                popupButtons.appendChild(okBtn);
            }
            
            // Show popup with animation
            popup.classList.remove('hidden');
            setTimeout(() => {
                popupContent.classList.remove('scale-95', 'opacity-0');
                popupContent.classList.add('scale-100', 'opacity-100');
            }, 10);
        });
    }
    
    function closePopup(result) {
        popupContent.classList.remove('scale-100', 'opacity-100');
        popupContent.classList.add('scale-95', 'opacity-0');
        setTimeout(() => {
            popup.classList.add('hidden');
            if (currentResolve) {
                currentResolve(result);
                currentResolve = null;
            }
        }, 300);
    }
    
    popup.addEventListener('click', function(e) {
        if (e.target === popup) {
            closePopup(false);
        }
    });
    
    // Export functions
    window.showAlert = (message, title = 'Alert') => showPopup('alert', title, message);
    window.showConfirm = (message, title = 'Confirm') => showPopup('confirm', title, message);
    window.showSuccess = (message, title = 'Success') => showPopup('success', title, message);
    window.showError = (message, title = 'Error') => showPopup('error', title, message);
})();

</script>

<script>
// Clear selected patient
function clearSelectedPatient() {
    document.getElementById('selectedPatientDisplay').classList.add('hidden');
    document.getElementById('selectedPatientInitials').textContent = '--';
    document.getElementById('selectedPatientName').textContent = '--';
    document.getElementById('selectedPatientInfo').textContent = '--';
}

// Billable items management
let billableItems = [];
let itemCounter = 0;

function updateTotals() {
    // This function is no longer needed as we removed the manual payment form
    // Keeping it to prevent errors from old code that might call it
    const subtotalEl = document.getElementById('subtotal');
    const taxEl = document.getElementById('tax');
    const totalDueEl = document.getElementById('totalDue');
    
    if (subtotalEl && taxEl && totalDueEl) {
        let subtotal = 0;
        if (typeof billableItems !== 'undefined' && billableItems) {
            billableItems.forEach(item => {
                subtotal += parseFloat(item.price);
            });
        }
        const tax = 0; // Tax rate can be configured
        const total = subtotal + tax;
        
        subtotalEl.textContent = '$' + subtotal.toFixed(2);
        taxEl.textContent = '$' + tax.toFixed(2);
        totalDueEl.textContent = '$' + total.toFixed(2);
    }
}

function renderBillableItems() {
    const container = document.getElementById('billableItems');
    container.innerHTML = '';
    
    billableItems.forEach((item, index) => {
        const itemDiv = document.createElement('div');
        itemDiv.className = 'flex justify-between items-center text-sm p-2 hover:bg-gray-50 rounded-lg group';
        itemDiv.innerHTML = `
            <div class="flex flex-col">
                <span class="font-medium text-[#151118]">${item.name}</span>
                <span class="text-xs text-slate-400">Qty: ${item.quantity}</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="font-bold">$${parseFloat(item.price).toFixed(2)}</span>
                <button onclick="removeBillableItem(${index})" class="text-slate-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition-opacity">
                    <span class="material-symbols-outlined text-sm">delete</span>
                </button>
            </div>
        `;
        container.appendChild(itemDiv);
    });
    
    // Only update totals if elements exist
    const totalDueEl = document.getElementById('totalDue');
    if (totalDueEl) {
        updateTotals();
    }
}

function addBillableItem() {
    const select = document.getElementById('serviceSelect');
    const selectedOption = select.options[select.selectedIndex];
    
    if (!selectedOption.value) return;
    
    const serviceId = selectedOption.value;
    const serviceName = selectedOption.text.split(' ($')[0];
    const price = selectedOption.getAttribute('data-price');
    
    // Check if item already exists
    const existingIndex = billableItems.findIndex(item => item.serviceId === serviceId);
    if (existingIndex !== -1) {
        billableItems[existingIndex].quantity++;
        billableItems[existingIndex].price = (parseFloat(billableItems[existingIndex].price) / (billableItems[existingIndex].quantity - 1) * billableItems[existingIndex].quantity).toFixed(2);
    } else {
        billableItems.push({
            id: itemCounter++,
            serviceId: serviceId,
            name: serviceName,
            price: price,
            quantity: 1
        });
    }
    
    renderBillableItems();
    select.value = '';
}

function removeBillableItem(index) {
    billableItems.splice(index, 1);
    renderBillableItems();
}

// Add event listener for service selection
document.getElementById('serviceSelect')?.addEventListener('change', function() {
    if (this.value) {
        addBillableItem();
    }
});

// Initialize totals (only if elements exist - they were removed in the new design)
if (document.getElementById('subtotal') && document.getElementById('totalDue')) {
    updateTotals();
}

// Transaction Selection Modal functionality
// Auto-select patient logic (restored safely)
<?php if (isset($js_patient_data) && $js_patient_data): ?>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof selectPatient === 'function') {
        selectPatient(
            <?php echo json_encode($js_patient_data['id'] ?? 0); ?>,
            <?php echo json_encode($js_patient_data['firstName'] ?? ''); ?>,
            <?php echo json_encode($js_patient_data['lastName'] ?? ''); ?>,
            <?php echo json_encode($js_patient_data['displayId'] ?? ''); ?>,
            <?php echo json_encode($js_patient_data['phone'] ?? ''); ?>
        );
    }
});
<?php endif; ?>

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Transaction Selection Modal functionality
let currentTransactionPage = 1;
let totalTransactionPages = 1;
let currentTransactionSort = 'desc'; // 'asc' or 'desc'

const selectedTransactionDisplay = document.getElementById('selectedTransactionDisplay');
const selectedRecordId = document.getElementById('selectedRecordId');
const selectedTransactionInitials = document.getElementById('selectedTransactionInitials');
const selectedTransactionPatient = document.getElementById('selectedTransactionPatient');
const selectedTransactionService = document.getElementById('selectedTransactionService');
const selectedTransactionDate = document.getElementById('selectedTransactionDate');
const selectedTransactionAmount = document.getElementById('selectedTransactionAmount');
const transactionPaymentForm = document.getElementById('transactionPaymentForm');

window.setTransactionSort = function(sortOrder) {
    currentTransactionSort = sortOrder;
    currentTransactionPage = 1; // Reset to first page when sorting changes
    
    // Update button styles
    const descBtn = document.getElementById('sortDescBtn');
    const ascBtn = document.getElementById('sortAscBtn');
    
    if (sortOrder === 'desc') {
        descBtn.classList.remove('bg-gray-100', 'text-slate-600');
        descBtn.classList.add('bg-royal-purple', 'text-white');
        ascBtn.classList.remove('bg-royal-purple', 'text-white');
        ascBtn.classList.add('bg-gray-100', 'text-slate-600');
    } else {
        ascBtn.classList.remove('bg-gray-100', 'text-slate-600');
        ascBtn.classList.add('bg-royal-purple', 'text-white');
        descBtn.classList.remove('bg-royal-purple', 'text-white');
        descBtn.classList.add('bg-gray-100', 'text-slate-600');
    }
    
    // Reload transactions with new sort order
    loadTransactionPage();
}

window.searchTransactions = function() {
    currentTransactionPage = 1; // Reset to page 1 when searching
    loadTransactionPage();
}

function loadTransactionPage() {
    const searchInput = document.getElementById('transactionSearch');
    const transactionList = document.getElementById('transactionModalList');
    const pagination = document.getElementById('transactionModalPagination');
    
    const searchQuery = searchInput.value.trim();
    
    // Show loading
    transactionList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block animate-spin">hourglass_empty</span><p class="text-sm font-medium">Loading transactions...</p></div>';
    
    const url = `../api/search_unpaid_transactions.php?search=${encodeURIComponent(searchQuery)}&page=${currentTransactionPage}`;
    
    console.log('Fetching transactions from:', url);

    fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok: ' + response.statusText);
            }
            return response.text().then(text => {
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Server returned invalid JSON:', text);
                    throw new Error('Invalid JSON response');
                }
            });
        })
        .then(data => {
            console.log('Transaction data received:', data);
            if (data.success) {
                displayTransactionResults(data);
            } else {
                transactionList.innerHTML = '<div class="text-center py-12 text-slate-400"><span class="material-symbols-outlined text-4xl mb-2 block">receipt_long</span><p class="text-sm font-medium">No unpaid transactions found</p></div>';
            }
        })
        .catch(err => {
            console.error('Fetch error:', err);
            transactionList.innerHTML = `<div class="p-6 text-center text-red-500">Error loading transactions: ${err.message}</div>`;
        });
};

// Utility function to escape HTML and prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

let currentPageTransactions = {
    medical: [],
    appointments: [],
    installments: [],
    pending: []
};

function displayTransactionResults(data) {
    const listContainer = document.getElementById('transactionModalList');
    
    // Store for safe access
    currentPageTransactions.medical = data.medical_invoices || [];
    currentPageTransactions.appointments = data.appointment_fees || [];
    currentPageTransactions.installments = data.installment_payments || [];
    
    const totalCount = data.counts.total || 0;

    if (totalCount === 0) {
        listContainer.innerHTML = `
            <div class="flex flex-col items-center justify-center h-64 text-slate-400">
                <span class="material-symbols-outlined text-4xl mb-2 opacity-50">search_off</span>
                <p class="text-sm">No unpaid transactions found</p>
            </div>
        `;
        return;
    }
    
    let html = '';
    
    // ========================================
    // SECTION 0: PENDING INVOICES (Advance / Verification)
    // ========================================
    if (currentPageTransactions.pending && currentPageTransactions.pending.length > 0) {
        html += `
            <div class="px-4 py-3 bg-yellow-50 border-b border-yellow-100 sticky top-0 z-10">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-yellow-700 text-lg">pending_actions</span>
                    <h3 class="font-bold text-sm text-yellow-700">Pending Verification</h3>
                    <span class="ml-auto text-xs font-bold text-yellow-800 bg-yellow-200 px-2 py-0.5 rounded-full">${currentPageTransactions.pending.length}</span>
                </div>
            </div>
        `;
        
        currentPageTransactions.pending.forEach((inv, idx) => {
            let advanceBadge = '';
            if (parseFloat(inv.amount_paid) > 0) {
                advanceBadge = `<span class="text-[10px] font-bold text-green-700 bg-green-100 px-1.5 py-0.5 rounded ml-2">Paid: ₱${parseFloat(inv.amount_paid).toLocaleString()}</span>`;
            }
            
            html += `
                <div class="p-4 hover:bg-yellow-50 cursor-pointer border-b border-gray-50 transition-colors group relative" 
                     onclick="selectPendingInvoice(${idx})">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-yellow-100 group-hover:bg-yellow-600 group-hover:text-white text-yellow-700 flex items-center justify-center font-bold text-xs transition-colors flex-shrink-0">
                            DOC
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-1">
                                <h4 class="font-bold text-slate-700 group-hover:text-yellow-700 truncate pr-2">${escapeHtml(inv.patient_name)}</h4>
                                <span class="text-xs font-bold text-yellow-700 bg-yellow-100 px-2 py-0.5 rounded-full">₱${parseFloat(inv.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                            </div>
                            <div class="mb-1">${advanceBadge}</div>
                            <p class="text-xs text-slate-500 truncate mb-0.5">${escapeHtml(inv.service_names || 'Review Invoice')}</p>
                            <div class="flex items-center gap-2 text-[10px] text-slate-400">
                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">receipt</span> ${inv.reference_number}</span>
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-slate-300 group-hover:text-yellow-600 transition-colors">chevron_right</span>
                    </div>
                </div>
            `;
        });
    }
    if (currentPageTransactions.medical.length > 0) {
        html += `
            <div class="px-4 py-3 bg-purple-50 border-b border-purple-100 sticky top-0 z-10">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-royal-purple text-lg">receipt_long</span>
                    <h3 class="font-bold text-sm text-royal-purple">Medical Invoices</h3>
                    <span class="ml-auto text-xs font-bold text-royal-purple bg-purple-200 px-2 py-0.5 rounded-full">${currentPageTransactions.medical.length} ${currentPageTransactions.medical.length === 1 ? 'patient' : 'patients'}</span>
                </div>
            </div>
        `;
        
        currentPageTransactions.medical.forEach((record, idx) => {
            html += `
                <div class="p-4 hover:bg-purple-50 cursor-pointer border-b border-gray-50 transition-colors group" 
                     onclick="selectMedicalTransaction(${idx})">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-purple-100 group-hover:bg-royal-purple group-hover:text-white text-royal-purple flex items-center justify-center font-bold text-xs transition-colors flex-shrink-0">
                            ${(() => { const names = (record.patient_name || '').split(' '); return names.length > 1 ? (names[0].charAt(0) + names[names.length - 1].charAt(0)).toUpperCase() : (names[0] || '').substring(0, 2).toUpperCase(); })()}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-1">
                                <h4 class="font-bold text-slate-700 group-hover:text-royal-purple truncate pr-2">${escapeHtml(record.patient_name)}</h4>
                                <span class="text-xs font-bold text-royal-purple bg-purple-100 px-2 py-0.5 rounded-full">₱${parseFloat(record.amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                            </div>
                            <p class="text-xs text-slate-500 truncate mb-0.5">${escapeHtml(record.service_name)}</p>
                            <div class="flex items-center gap-2 text-[10px] text-slate-400">
                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">calendar_today</span> ${new Date(record.appointment_date).toLocaleDateString('en-US', {month: 'short', day: 'numeric', year: 'numeric'})}</span>
                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">person</span> Dr. ${escapeHtml(record.doctor_name)}</span>
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-slate-300 group-hover:text-royal-purple transition-colors">chevron_right</span>
                    </div>
                </div>
            `;
        });
    }
    
    // ========================================
    // SECTION 2: Appointment Fees
    // ========================================
    if (currentPageTransactions.appointments.length > 0) {
        html += `
            <div class="px-4 py-3 bg-blue-50 border-b border-blue-100 sticky top-0 z-10">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-blue-600 text-lg">event</span>
                    <h3 class="font-bold text-sm text-blue-600">Appointment Fees</h3>
                    <span class="ml-auto text-xs font-bold text-blue-600 bg-blue-200 px-2 py-0.5 rounded-full">${currentPageTransactions.appointments.length}</span>
                </div>
            </div>
        `;
        
        currentPageTransactions.appointments.forEach((t, index) => {
            const initials = (t.patient_name.split(' ')[0].charAt(0) + t.patient_name.split(' ').pop().charAt(0)).toUpperCase();
            html += `
                <div class="p-4 hover:bg-blue-50 cursor-pointer border-b border-gray-50 transition-colors group" 
                     onclick="selectAppointmentTransaction(${index})">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-blue-100 group-hover:bg-blue-600 group-hover:text-white text-blue-600 flex items-center justify-center font-bold text-xs transition-colors flex-shrink-0">
                            ${initials}x
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-1">
                                <h4 class="font-bold text-slate-700 group-hover:text-blue-600 truncate pr-2">${escapeHtml(t.patient_name)}</h4>
                                <span class="text-xs font-bold text-blue-600 bg-blue-100 px-2 py-0.5 rounded-full">₱${parseFloat(t.amount).toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                            </div>
                            <p class="text-xs text-slate-500 truncate mb-0.5">${escapeHtml(t.service_names)}</p>
                            <div class="flex items-center gap-2 text-[10px] text-slate-400">
                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">schedule</span> ${t.date_formatted}</span>
                                <span>•</span>
                                <span>Dr. ${escapeHtml(t.doctor_name)}</span>
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-slate-300 group-hover:text-blue-600 transition-colors">chevron_right</span>
                    </div>
                </div>
            `;
        });
    }
    
    // ========================================
    // SECTION 3: Installment Payments
    // ========================================
    if (currentPageTransactions.installments.length > 0) {
        html += `
            <div class="px-4 py-3 bg-yellow-50 border-b border-yellow-100 sticky top-0 z-10">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-clinic-gold text-lg">credit_card</span>
                    <h3 class="font-bold text-sm text-clinic-gold">Installment Payments</h3>
                    <span class="ml-auto text-xs font-bold text-clinic-gold bg-yellow-200 px-2 py-0.5 rounded-full">${currentPageTransactions.installments.length}</span>
                </div>
            </div>
        `;
        
        currentPageTransactions.installments.forEach((t, index) => {
            const initials = (t.patient_name.split(' ')[0].charAt(0) + t.patient_name.split(' ').pop().charAt(0)).toUpperCase();
            const hasPenalty = t.penalty_due > 0;
            html += `
                <div class="p-4 hover:bg-yellow-50 cursor-pointer border-b border-gray-50 transition-colors group" 
                     onclick="selectInstallmentTransaction(${index})">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-full bg-yellow-100 group-hover:bg-clinic-gold group-hover:text-royal-purple text-clinic-gold flex items-center justify-center font-bold text-xs transition-colors flex-shrink-0">
                            ${initials}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex justify-between items-start mb-1">
                                <h4 class="font-bold text-slate-700 group-hover:text-clinic-gold truncate pr-2">${escapeHtml(t.patient_name)}</h4>
                                <span class="text-xs font-bold text-clinic-gold bg-yellow-100 px-2 py-0.5 rounded-full">₱${parseFloat(t.amount_due).toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                            </div>
                            <p class="text-xs text-slate-500 truncate mb-0.5">${escapeHtml(t.service_name)} ${hasPenalty ? '<span class="text-red-500 font-bold">+ Penalty</span>' : ''}</p>
                            <div class="flex items-center gap-2 text-[10px] text-slate-400">
                                <span class="flex items-center gap-1"><span class="material-symbols-outlined text-[10px]">calendar_today</span> Due: ${t.date_formatted}</span>
                                <span>•</span>
                                <span class="font-bold text-clinic-gold">${t.progress}</span>
                            </div>
                        </div>
                        <span class="material-symbols-outlined text-slate-300 group-hover:text-clinic-gold transition-colors">chevron_right</span>
                    </div>
                </div>
            `;
        });
    }
    
    listContainer.innerHTML = html;
}


// ========================================
// SELECTION HANDLERS FOR THREE PAYMENT TYPES
// ========================================

// function selectMedicalTransaction is below... adding selectPendingInvoice here
window.selectPendingInvoice = function(index) {
    const inv = currentPageTransactions.pending[index];
    if (!inv) return;
    
    // Reset triggers to "Process Payment" mode
    document.getElementById('transactionActionTrigger').value = '0'; // Disable Create
    document.getElementById('transactionActionTrigger').name = 'ignore_create'; // Rename to avoid POST
    document.getElementById('processPaymentTrigger').value = '1'; // Enable Process
    document.getElementById('processPaymentTrigger').name = 'process_payment'; // Ensure proper name
    document.getElementById('selectedInvoiceId').value = inv.invoice_id; // Set ID
    
    // Reset others
    document.getElementById('selectedRecordId').value = '';
    
    // UI Updates
    document.getElementById('emptyTransactionState').classList.add('hidden');
    document.getElementById('selectedTransactionDisplay').classList.remove('hidden');
    
    // Populate Header
    const initials = (inv.patient_name.split(' ')[0].charAt(0) + inv.patient_name.split(' ').pop().charAt(0)).toUpperCase();
    document.getElementById('selectedTransactionInitials').textContent = initials;
    document.getElementById('selectedTransactionPatient').textContent = inv.patient_name;
    document.getElementById('selectedTransactionService').textContent = (inv.service_names || 'Invoice Payment') + ' (Pending Verification)';
    document.getElementById('selectedTransactionDate').textContent = 'Ref: ' + inv.reference_number;
    document.getElementById('selectedTransactionAmount').textContent = '₱' + parseFloat(inv.amount).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Handle Advance Payment Display in Breakdown
    const breakdownContainer = document.getElementById('serviceBreakdownContainer');
    const breakdownList = document.getElementById('serviceBreakdownList');
    const subtotalEl = document.getElementById('breakdownSubtotal');
    
    breakdownContainer.classList.remove('hidden');
    
    let breakdownHtml = `
        <div class="flex justify-between items-center text-xs text-slate-600 mb-2">
            <span>Total Bill</span>
            <span class="font-bold">₱${parseFloat(inv.amount).toLocaleString()}</span>
        </div>
    `;
    
    const amountPaid = parseFloat(inv.amount_paid || 0);
    const feePaid = parseFloat(inv.appointment_fee_paid || 0);
    
    if (feePaid > 0) {
        breakdownHtml += `
            <div class="flex justify-between items-center text-xs text-blue-600 mb-2 font-medium">
                <span>Less: Appointment Fee</span>
                <span>-₱${feePaid.toLocaleString()}</span>
            </div>
        `;
    }
    
    if (amountPaid > 0) {
        breakdownHtml += `
            <div class="flex justify-between items-center text-xs text-green-600 mb-2 font-medium">
                <span>Less: Advance Payment</span>
                <span>-₱${amountPaid.toLocaleString()}</span>
            </div>
        `;
    }
    
    let remaining = parseFloat(inv.amount) - amountPaid - feePaid;
    if (remaining < 0) remaining = 0; // Should not trigger unless overpaid
    
    breakdownList.innerHTML = breakdownHtml;
    subtotalEl.textContent = 'Net Due: ₱' + remaining.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Update Total Display
    document.querySelector('#totalServiceAmountDisplay span').textContent = '₱' + remaining.toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Constraint Check: Can only process if appointment is Completed
    const processBtn = document.getElementById('btnProcessPayment');
    const processBtnText = document.getElementById('processBtnText');
    const warningContainer = document.getElementById('installmentDetailsDisplay'); // Reuse or create new
    
    // Clear previous warning styling if any
    warningContainer.classList.add('hidden');
    warningContainer.className = "hidden bg-blue-50 p-4 rounded-xl border border-blue-100"; // Reset classes
    
    if (inv.appointment_status !== 'Completed') {
        processBtn.disabled = true;
        processBtn.classList.add('opacity-50', 'cursor-not-allowed', 'bg-gray-200', 'text-slate-400');
        processBtn.classList.remove('bg-clinic-gold', 'text-royal-purple', 'hover:bg-yellow-400', 'shadow-yellow-400/20');
        processBtnText.textContent = "Procedure Pending";
        
        // Show Warning
        warningContainer.classList.remove('hidden');
        warningContainer.className = "mb-4 bg-amber-50 p-4 rounded-xl border border-amber-200 text-amber-800 text-sm font-bold flex items-center gap-2";
        warningContainer.innerHTML = `
            <span class="material-symbols-outlined">warning</span>
            <span>Unfinished Procedure: Doctor must complete the appointment first.</span>
        `;
    } else {
        processBtn.disabled = false;
        processBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'bg-gray-200', 'text-slate-400');
        processBtn.classList.add('bg-clinic-gold', 'text-royal-purple', 'hover:bg-yellow-400', 'shadow-yellow-400/20');
        
        if (remaining <= 0.01) {
            processBtnText.textContent = "Confirm Full Payment";
        } else {
            processBtnText.textContent = "Process Balance Payment";
        }
    }
    
    // Hide Payment Scheme (force full verification/completion)
    // Or allow partial? For now user said "receptionist will need to complete the payment". 
    // Assuming this means verifying and marking Paid.
    // If balance > 0, they collect cash/gcash.
    document.querySelector('input[name="payment_scheme"][value="full"]').click(); // Reset to full
    // Maybe hide scheme options?
    // For simplicity, we just pre-fill amount
    document.getElementById('transactionPaymentInput').value = remaining;
    
    // Auto-fill GCash ref if exists
    if (inv.payment_reference) {
        document.querySelector('input[name="payment_method"][value="GCash"]').click();
        document.getElementById('paymentReferenceInput').value = inv.payment_reference;
    }
};

window.selectMedicalTransaction = function(index) {
    // Reset Button State
    const processBtn = document.getElementById('btnProcessPayment');
    const processBtnText = document.getElementById('processBtnText');
    processBtn.disabled = false;
    processBtn.classList.remove('opacity-50', 'cursor-not-allowed', 'bg-gray-200', 'text-slate-400');
    processBtn.classList.add('bg-clinic-gold', 'text-royal-purple', 'hover:bg-yellow-400', 'shadow-yellow-400/20');
    processBtnText.textContent = "Process Payment";
    document.getElementById('installmentDetailsDisplay').classList.add('hidden'); // Hide warnings
    
    // Restore triggers to "Create Invoice" mode
    document.getElementById('transactionActionTrigger').value = '1';
    document.getElementById('transactionActionTrigger').name = 'create_invoice_from_transaction';
    document.getElementById('processPaymentTrigger').value = '0'; 
    document.getElementById('processPaymentTrigger').name = 'ignore_process';
    document.getElementById('selectedInvoiceId').value = '';
    
    const t = currentPageTransactions.medical[index];
    if (!t) return;
    
    // Show old payment form, hide checklist
    const singlePaymentForm = document.getElementById('singlePaymentFormContainer');
    if (singlePaymentForm) singlePaymentForm.style.display = '';
    
    // Remove checklist if it exists
    const existingChecklist = document.getElementById('batchItemsList');
    if (existingChecklist) existingChecklist.remove();
    

    // Ensure amount is valid
    const amount = parseFloat(t.amount || 0);

    // STORE BREAKDOWN IN GLOBAL OR ELEMENT DATA
    document.getElementById('transactionPaymentForm').dataset.servicesBreakdown = JSON.stringify(t.services_breakdown || []);

    // Map to existing selectTransaction function
    selectTransaction(
        t.record_id || 0,
        t.patient_name,
        t.service_name,
        t.date_formatted || new Date(t.date_of_treatment).toLocaleDateString(),
        amount,
        t.doctor_name,
        t.appointment_id || '',
        amount, // Original Price
        t.is_installment_available,
        t.installment_duration_months,
        t.installment_monthly_payment,
        t.service_id,
        t.appointment_fee_paid || 0, // Fee Paid
        t.installment_down_payment || 0, // New Argument: Down Payment
        t.has_active_installment || false, // Has active installment?
        t.advance_payment || 0 // New: Advance Payment
    );

    // MANUALLY FIX INSTALLMENT DISPLAY
    if (t.is_installment_available) {
        // Fix Monthly Payment formatting (force 2 decimals)
        const monthly = parseFloat(t.installment_monthly_payment);
        const displayMonthly = document.getElementById('displayMonthly');
        if (displayMonthly && !isNaN(monthly)) {
            displayMonthly.textContent = '₱' + monthly.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}) + '/mo';
        }

        // Hide "Months Left" and "Required Amount" for NEW proposals (irrelevant)
        const labelMonthsLeft = document.getElementById('labelMonthsLeft');
        const displayMonthsLeft = document.getElementById('displayMonthsLeft');
        const containerRequiredAmount = document.getElementById('containerRequiredAmount');
        
        if (labelMonthsLeft) labelMonthsLeft.style.display = 'none';
        if (displayMonthsLeft) displayMonthsLeft.style.display = 'none';
        if (containerRequiredAmount) containerRequiredAmount.style.display = 'none';

        // Populate and Show "Balance"
        const displayBalance = document.getElementById('displayBalance');
        if (displayBalance) {
            displayBalance.style.display = ''; // Ensure visible
            displayBalance.textContent = '₱' + amount.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
        }
    }
    
    // Set form action for Medical Invoice
    const actionTrigger = document.getElementById('transactionActionTrigger');
    if(actionTrigger) actionTrigger.name = 'create_invoice_from_transaction';
    
    // UI RESET: Hide Appointment Fee Breakdown
    const breakdownSection = document.getElementById('appointmentFeeBreakdown');
    if(breakdownSection) breakdownSection.classList.add('hidden');
    
    // UI RESET: Show Discount Section
    const discountContainer = document.getElementById('discountSectionContainer');
    if(discountContainer) discountContainer.style.display = 'block';
    
    // UI RESET: Show Payment Scheme Section
    const paymentSchemeSection = document.querySelector('label:has([name="payment_scheme"])').closest('div').parentElement;
    if(paymentSchemeSection) paymentSchemeSection.style.display = 'block';
    
    // ---------------------------------------------------------
    // INSTALLMENT ELIGIBILITY CHECK (Strict Rule)
    // ---------------------------------------------------------
    const installmentRadio = document.querySelector('input[name="payment_scheme"][value="installment"]');
    const services = t.services_breakdown || [];
    let isInstallmentEligible = true;
    let ineligibilityReason = '';

    // Check if ANY service is ineligible
    for (const svc of services) {
        if (!svc.is_installment_available) { // API returns boolean true/false
            isInstallmentEligible = false;
            ineligibilityReason = `Unavailable for ${svc.name}`;
            break;
        }
    }

    if (installmentRadio) {
        const parentLabel = installmentRadio.parentElement; // The label element
        const parentDiv = parentLabel.closest('div'); // The grid container (or direct parent)
        
        // Find or create warning text container
        let warningEl = parentLabel.querySelector('.installment-warning');
        if (!warningEl) {
             warningEl = document.createElement('div');
             warningEl.className = 'installment-warning absolute -top-2 right-2 bg-red-100 text-red-600 text-[9px] font-bold px-1.5 py-0.5 rounded shadow-sm hidden';
             parentLabel.classList.add('relative');
             parentLabel.appendChild(warningEl);
        }

        if (isInstallmentEligible) {
            installmentRadio.disabled = false;
            parentLabel.classList.remove('opacity-50', 'cursor-not-allowed', 'bg-gray-50');
            warningEl.classList.add('hidden');
        } else {
            installmentRadio.disabled = true;
            installmentRadio.checked = false; // Uncheck if disabled
            parentLabel.classList.add('opacity-50', 'cursor-not-allowed', 'bg-gray-50');
            
            warningEl.textContent = ineligibilityReason;
            warningEl.classList.remove('hidden');
            
            // Force switch to Full Payment
            const fullRadio = document.querySelector('input[name="payment_scheme"][value="full"]');
            if (fullRadio) {
                fullRadio.checked = true;
                fullRadio.dispatchEvent(new Event('change')); // Trigger UI update
            }
        }
    }
    
    // Initial Update
    // AUTO-FILL DISCOUNT IF PATIENT HAS VERIFIED PROFILE
    // Reset first
    const targetType = document.getElementById('discount_type');
    if(targetType) targetType.value = 'None';
    const btnRemove = document.getElementById('btnRemoveDiscount');
    if(btnRemove) btnRemove.classList.add('hidden');
    const btnApply = document.getElementById('btnDiscount');
    if (btnApply) {
        btnApply.textContent = 'Apply Discount';
        btnApply.classList.replace('bg-purple-50', 'bg-white');
        btnApply.classList.remove('text-royal-purple');
    }
    const discountLabel = document.getElementById('discountLabel');
    if (discountLabel) discountLabel.textContent = 'Discount';
    const discountSubLabel = document.getElementById('discountSubLabel');
    if (discountSubLabel) discountSubLabel.textContent = 'Apply Senior/PWD discount';

    if (t.discount_type && (t.discount_type === 'PWD' || t.discount_type === 'Senior')) {
        // Set Hidden Fields
        const targetId = document.getElementById('discount_id_number');
        const targetIssuer = document.getElementById('discount_id_issuer');
        const targetDate = document.getElementById('discount_date_verified');
        
        if(targetType) targetType.value = t.discount_type;
        if(targetId) targetId.value = t.discount_id_number || '';
        if(targetIssuer) targetIssuer.value = t.discount_id_issuer || '';
        if(targetDate) targetDate.value = t.discount_id_expiry || '';
        
        // Update UI Labels and Buttons
        if (discountLabel) discountLabel.textContent = t.discount_type + ' Discount';
        if (discountSubLabel) discountSubLabel.textContent = '20% + VAT Exemption Applied';
        if (btnRemove) btnRemove.classList.remove('hidden');
        if (btnApply) {
            btnApply.textContent = 'Verified ' + t.discount_type;
            btnApply.classList.replace('bg-white', 'bg-purple-50');
            btnApply.classList.add('text-royal-purple');
        }
    }

    updateTransactionPayment();
};

window.selectAppointmentTransaction = function(index) {
    const t = currentPageTransactions.appointments[index];
    if (!t) return;
    
    // Clear breakdown data
    document.getElementById('transactionPaymentForm').dataset.servicesBreakdown = '[]';
    
    // Show appointment fee payment form
    selectedTransactionDisplay.classList.remove('hidden');
    
    // Set form action for Appointment Fee
    const actionTrigger = document.getElementById('transactionActionTrigger');
    if(actionTrigger) actionTrigger.name = 'process_appointment_fee';
    
    // HIDE EMPTY STATE
    const emptyState = document.getElementById('emptyTransactionState');
    if (emptyState) emptyState.classList.add('hidden');
    
    // Populate display
    const initials = (t.patient_name.split(' ')[0].charAt(0) + t.patient_name.split(' ').pop().charAt(0)).toUpperCase();
    selectedTransactionInitials.textContent = initials;
    selectedTransactionPatient.textContent = t.patient_name;
    selectedTransactionService.textContent = t.service_names;
    selectedTransactionDate.textContent = t.date_formatted;
    selectedTransactionAmount.textContent = '₱' + parseFloat(t.amount).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Set hidden form fields for appointment payment
    document.getElementById('selectedAppointmentId').value = t.appointment_id;
    document.getElementById('selectedRecordId').value = 0; // No record for appointments
    
    // Set payment amount
    // Set payment amount
    document.getElementById('transactionPaymentInput').value = t.amount;
    
    // SHOW AND POPULATE APPOINTMENT FEE BREAKDOWN
    const breakdownSection = document.getElementById('appointmentFeeBreakdown');
    if (breakdownSection) {
        breakdownSection.classList.remove('hidden');
        
        // Populate breakdown details
        document.getElementById('breakdownBaseFee').textContent = '₱' + parseFloat(t.base_fee || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('breakdownTotal').textContent = '₱' + parseFloat(t.amount).toLocaleString(undefined, {minimumFractionDigits: 2});
        
        // Show/hide overtime section
        const overtimeSection = document.getElementById('breakdownOvertimeSection');
        if (t.overtime_minutes > 0 && t.overtime_fee > 0) {
            overtimeSection.classList.remove('hidden');
            
            // Calculate overtime increments for display
            const overtimeIncrements = Math.ceil(t.overtime_minutes / t.overtime_interval);
            const overtimeLabel = `Overtime (${t.overtime_minutes} min = ${overtimeIncrements} × ${t.overtime_interval} min):`;
            
            document.getElementById('breakdownOvertimeLabel').textContent = overtimeLabel;
            document.getElementById('breakdownOvertimeFee').textContent = '₱' + parseFloat(t.overtime_fee).toLocaleString(undefined, {minimumFractionDigits: 2});
        } else {
            overtimeSection.classList.add('hidden');
        }
    }
    
    // UI RESET: Hide Installment Payment Input
    document.getElementById('installmentPaymentInputContainer').classList.add('hidden');
    
    // HIDE ENTIRE PAYMENT SCHEME SECTION (installment not available for appointment fees)
    const paymentSchemeSection = document.querySelector('label:has([name="payment_scheme"])').closest('div').parentElement;
    if (paymentSchemeSection) paymentSchemeSection.style.display = 'none';
    
    // Hide installment options for appointment fees (not applicable)
    document.getElementById('transactionDpContainer').classList.add('hidden');
    document.getElementById('installmentDetailsDisplay').classList.add('hidden');
    
    // UI RESET: Hide Installment Payment Input
    document.getElementById('installmentPaymentInputContainer').classList.add('hidden');
    
    // Disable installment option
    const installmentRadio = document.querySelector('input[name="payment_scheme"][value="installment"]');
    if (installmentRadio) {
        installmentRadio.disabled = true;
        installmentRadio.parentElement.classList.add('opacity-50', 'cursor-not-allowed');
    }
    
    // Select full payment
    document.querySelector('input[name="payment_scheme"][value="full"]').checked = true;
    
    // HIDE DISCOUNT SECTION (only available for medical invoices)
    const discountContainer = document.getElementById('discountSectionContainer');
    if (discountContainer) discountContainer.style.display = 'none';
    
    // HIDE SERVICE BREAKDOWN
    const breakdownContainer = document.getElementById('serviceBreakdownContainer');
    if (breakdownContainer) breakdownContainer.classList.add('hidden');
    
    // UPDATE TOTAL DISPLAY with appointment fee amount
    const totalDisplay = document.getElementById('totalServiceAmountDisplay');
    if (totalDisplay) {
        totalDisplay.querySelector('span').textContent = '₱' + parseFloat(t.amount).toLocaleString(undefined, {minimumFractionDigits: 2});
    }
};

window.selectInstallmentTransaction = function(index) {
    const t = currentPageTransactions.installments[index];
    if (!t) return;
    
    // Clear breakdown data
    document.getElementById('transactionPaymentForm').dataset.servicesBreakdown = '[]';

    // RESTORE "Months Left" and "Required Amount" (might be hidden by selectMedicalTransaction)
    const labelMonthsLeft = document.getElementById('labelMonthsLeft');
    const displayMonthsLeft = document.getElementById('displayMonthsLeft');
    const containerRequiredAmount = document.getElementById('containerRequiredAmount');
    
    if (labelMonthsLeft) labelMonthsLeft.style.display = '';
    if (displayMonthsLeft) displayMonthsLeft.style.display = '';
    if (containerRequiredAmount) containerRequiredAmount.style.display = '';
    
    // Show installment payment form
    selectedTransactionDisplay.classList.remove('hidden');
    document.getElementById('emptyTransactionState').classList.add('hidden'); // HIDE EMPTY STATE
    
    // Set form action for Installment Payment
    const actionTrigger = document.getElementById('transactionActionTrigger');
    if(actionTrigger) actionTrigger.name = 'process_installment_payment';
    
    // UI RESET: Hide Appointment Fee Breakdown
    const breakdownSection = document.getElementById('appointmentFeeBreakdown');
    if(breakdownSection) breakdownSection.classList.add('hidden');
    
    // UI RESET: Hide Discount Section (Installments don't have discounts at payment time)
    const discountContainer = document.getElementById('discountSectionContainer');
    if(discountContainer) discountContainer.style.display = 'none';
    
    // HIDE SERVICE BREAKDOWN
    const breakdownContainer = document.getElementById('serviceBreakdownContainer');
    if (breakdownContainer) breakdownContainer.classList.add('hidden');
    
    // Populate display
    const initials = (t.patient_name.split(' ')[0].charAt(0) + t.patient_name.split(' ').pop().charAt(0)).toUpperCase();
    selectedTransactionInitials.textContent = initials;
    selectedTransactionPatient.textContent = t.patient_name;
    selectedTransactionService.textContent = t.service_name + ' - ' + t.progress;
    selectedTransactionDate.textContent = 'Due: ' + t.date_formatted;
    selectedTransactionAmount.textContent = '₱' + parseFloat(t.amount_due).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Set hidden form fields for installment payment
    // We need to add a hidden field for installment_id
    let installmentIdField = document.getElementById('selectedInstallmentId');
    if (!installmentIdField) {
        installmentIdField = document.createElement('input');
        installmentIdField.type = 'hidden';
        installmentIdField.name = 'installment_id';
        installmentIdField.id = 'selectedInstallmentId';
        transactionPaymentForm.appendChild(installmentIdField);
    }
    installmentIdField.value = t.installment_id;
    
    document.getElementById('selectedRecordId').value = 0; // No record for installments
    document.getElementById('selectedAppointmentId').value = 0; // No appointment for installments
    
    // POPULATE VISIBLE PAYMENT INPUT
    const visibleInput = document.getElementById('visibleInstallmentInput');
    visibleInput.value = t.amount_due;
    document.getElementById('transactionPaymentInput').value = t.amount_due; // Sync hidden
    document.getElementById('installmentPaymentInputContainer').classList.remove('hidden');
    
    
    // Hide installment creation options (this is a payment, not creation)
    document.getElementById('transactionDpContainer').classList.add('hidden');
    document.getElementById('installmentDetailsDisplay').classList.remove('hidden'); // Ensure visible
    
    // Populate Detailed Installment Info
    const monthsLeft = (t.months_total || 0) - (t.months_paid || 0);
    document.getElementById('displayDuration').textContent = (t.months_total || 0) + ' months';
    document.getElementById('displayMonthly').textContent = '₱' + parseFloat(t.monthly_amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('displayMonthsLeft').textContent = monthsLeft;
    document.getElementById('displayBalance').textContent = '₱' + parseFloat(t.balance_remaining || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
    document.getElementById('displayRequiredAmount').textContent = '₱' + parseFloat(t.amount_due || 0).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Fix: Set installment_months hidden input for updateTransactionPayment to work correctly
    document.getElementById('installment_months').value = t.months_total || 12;
    // Hide config container
    const configContainer = document.getElementById('installmentConfigContainer');
    if (configContainer) configContainer.classList.add('hidden');
    
    
    // Disable installment option (this is already an installment)
    const installmentRadio = document.querySelector('input[name="payment_scheme"][value="installment"]');
    if (installmentRadio) {
        installmentRadio.disabled = true;
        installmentRadio.parentElement.classList.add('opacity-50', 'cursor-not-allowed');
    }
    
    // Select full payment
    document.querySelector('input[name="payment_scheme"][value="full"]').checked = true;
    
    // Show penalty warning if applicable
    if (t.penalty_due > 0) {
        console.log('Penalty due: ₱' + t.penalty_due.toFixed(2));
    }
    
    // UPDATE TOTAL DISPLAY with installment due amount
    const totalDisplay = document.getElementById('totalServiceAmountDisplay');
    if (totalDisplay) {
        totalDisplay.querySelector('span').textContent = '₱' + parseFloat(t.amount_due).toLocaleString(undefined, {minimumFractionDigits: 2});
        
        // Ensure discount button is hidden (redundant but safe)
        const btnParam = document.getElementById('btnDiscount');
        if (btnParam) btnParam.textContent = 'No Discount'; // Or just leave hidden
    }
};

window.updateTransactionPayment = function() {
    const originalPrice = parseFloat(document.getElementById('original_service_price').value || 0);
    const discountType = document.getElementById('discount_type').value;
    const scheme = document.querySelector('input[name="payment_scheme"]:checked').value;
    const dpInput = document.getElementById('transactionDpInput');
    const feePaid = parseFloat(document.getElementById('appointment_fee_paid_input')?.value || 0);
    const advancePaid = parseFloat(document.getElementById('advance_payment_input')?.value || 0);
    
    // Retrieve Breakdown Data
    let breakdown = [];
    try {
        breakdown = JSON.parse(document.getElementById('transactionPaymentForm').dataset.servicesBreakdown || '[]'); // Correct ID
    } catch(e) {}

    // 1. Calculate Discount
    let discountedTotal = 0;
    let discountAmount = 0;
    let breakdownHtml = `<div class="flex flex-col gap-1 w-full bg-slate-50 p-3 rounded-lg border border-slate-100 mb-2">`;
    
    // If we have a breakdown, calculate per service
    if (breakdown.length > 0) {
         breakdownHtml += `<p class="text-[10px] uppercase font-bold text-slate-400 mb-1">Service Breakdown</p>`;
         breakdown.forEach(svc => {
             const price = parseFloat(svc.price);
             let itemDiscount = 0;
             let itemNet = price;
             
             if (discountType === 'Senior' || discountType === 'PWD') {
                 const vatExempt = price / 1.12;
                 const discountVal = vatExempt * 0.20;
                 itemDiscount = price - (vatExempt - discountVal); // Diff between Original and Net
                 itemNet = vatExempt - discountVal;
             }
             
             discountedTotal += itemNet;
             discountAmount += itemDiscount;
             
             breakdownHtml += `
                <div class="flex justify-between items-center text-xs text-slate-600 mb-1">
                    <span>${svc.name}</span>
                    <span>₱${price.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                </div>
             `;
         });
    } else {
        // Fallback for no breakdown (single service logic) - same as before
        if ((discountType === 'Senior' || discountType === 'PWD') && originalPrice > 0) {
             const vatExempt = originalPrice / 1.12;
             const discountVal = vatExempt * 0.20;
             discountedTotal = vatExempt - discountVal;
             discountAmount = originalPrice - discountedTotal;
        } else {
            discountedTotal = originalPrice;
        }
    }
    
    // 2. Deduct Appointment Fee AND Advance Payment from the TOTAL net
    let finalAmount = Math.max(0, discountedTotal - feePaid - advancePaid);
    
    // 3. Handle Display Logic
    const displayEl = document.getElementById('totalServiceAmountDisplay');
    const btnParam = document.getElementById('btnDiscount');
    const installmentBreakdownEl = document.getElementById('installmentPlanBreakdown');
    
    let amountToPay = finalAmount;
    
    // Reset Breakdown visibility
    if (installmentBreakdownEl) installmentBreakdownEl.classList.add('hidden');
    
    if (scheme === 'installment') {
        const months = parseInt(document.getElementById('installment_months').value || 1);
        
        // Show breakdown in the Blue Box if Fee > 0
        if (feePaid > 0 && installmentBreakdownEl) {
            installmentBreakdownEl.classList.remove('hidden');
            installmentBreakdownEl.innerHTML = `
                <div class="flex justify-between items-center text-slate-500">
                    <span>Total Service Value:</span>
                    <span>₱${discountedTotal.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                </div>
                <div class="flex justify-between items-center text-blue-600 font-medium">
                    <span>Less: Appointment Fee:</span>
                    <span>-₱${feePaid.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                </div>
            `;
            // Note: Balance is calculated from finalAmount (which is discountedTotal - feePaid)
        }
        
        // Recalculate Min Downpayment based on Final Amount
        // If DB has a specific down payment set, use it. Otherwise default to 20%
        const dbDownPayment = parseFloat(document.getElementById('installment_down_payment_db')?.value || 0);
        
        let minDp = 0;
        if (dbDownPayment > 0) {
            minDp = dbDownPayment;
        } else {
            minDp = finalAmount * 0.20;
        }
        
        dpInput.min = minDp.toFixed(2);
        
        let currentDp = parseFloat(dpInput.value || 0);
        
        // Amount to Pay NOW is Down Payment
        amountToPay = currentDp;
        
        // Calculate Monthly
        const remaining = finalAmount - currentDp; // Fee already deducted from finalAmount
        const monthly = remaining / months;
        
        // Update Installment Details Box
        document.getElementById('displayMonthly').textContent = '₱' + Math.max(0, monthly).toLocaleString(undefined, {minimumFractionDigits: 2}) + '/mo';
        document.getElementById('displayBalance').textContent = '₱' + Math.max(0, remaining).toLocaleString(undefined, {minimumFractionDigits: 2});
        document.getElementById('displayRequiredAmount').textContent = '₱' + Math.max(0, finalAmount).toLocaleString(undefined, {minimumFractionDigits: 2}); // Total Installment Value
    } else {
        // Full Payment
        amountToPay = finalAmount;
    }
    
    // 4. Update UI with Breakdown
    const breakdownContainer = document.getElementById('serviceBreakdownContainer');
    const breakdownList = document.getElementById('serviceBreakdownList');
    const breakdownSubtotal = document.getElementById('breakdownSubtotal');
    
    // Only show if we have data AND not in installment (unless we want to support breakdown there too, but user said no)
    // The previous toggle scheme hides it, but this function re-runs and unhided it. Adding check here fixes it.
    if (breakdown.length > 0 && scheme !== 'installment') {
        breakdownContainer.classList.remove('hidden');
        let listHtml = '';
        
        breakdown.forEach(svc => {
             const price = parseFloat(svc.price);
             let itemHtml = `<span class="text-slate-800 font-bold">₱${price.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>`;
             
             // Calculate per-item discount for display if applicable
             if ((discountType === 'Senior' || discountType === 'PWD') && price > 0) {
                 const vatExempt = price / 1.12;
                 const discountVal = vatExempt * 0.20;
                 const netItem = vatExempt - discountVal;
                 
                 itemHtml = `
                    <div class="flex flex-col items-end">
                        <span class="text-[10px] text-slate-400 line-through">₱${price.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                        <span class="text-green-600 font-bold">₱${netItem.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                    </div>
                 `;
             }

             listHtml += `
                <div class="flex justify-between items-center text-xs">
                    <span class="text-slate-600 font-medium">${svc.name}</span>
                    ${itemHtml}
                </div>
             `;
        });

        // Subtotal (Gross)
        listHtml += `
            <div class="flex justify-between items-center text-xs mt-2 pt-2 border-t border-slate-200 font-bold text-slate-700">
                <span>Subtotal</span>
                <span>₱${originalPrice.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
            </div>
        `;

        // Add Appointment Fee Deduction if applicable
        if (feePaid > 0) {
             listHtml += `
                <div class="flex justify-between items-center text-xs mt-1 text-blue-600">
                    <span class="font-medium">Less: Appointment Fee</span>
                    <span>-₱${feePaid.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                </div>
             `;
        }

        // Add Advance Payment Deduction if applicable
        if (advancePaid > 0) {
             listHtml += `
                <div class="flex justify-between items-center text-xs mt-1 text-green-600">
                    <span class="font-medium">Less: Advance Payment</span>
                    <span>-₱${advancePaid.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
                </div>
             `;
        }
        
        // Net Due
        let netDue = Math.max(0, originalPrice - feePaid - advancePaid);
        if (discountAmount > 0) netDue = Math.max(0, discountedTotal - feePaid - advancePaid);

        listHtml += `
            <div class="flex justify-between items-center text-sm mt-2 pt-2 border-t border-dashed border-slate-300 font-black text-royal-purple">
                <span>Net Due</span>
                <span>₱${netDue.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>
            </div>
        `;
        
        breakdownList.innerHTML = listHtml;
        breakdownSubtotal.textContent = ''; // Clear external subtotal to avoid confusion
        
    } else {
        // Fallback for single service or appointment fee (hide breakdown box)
        breakdownContainer.classList.add('hidden');
    }
    
    // UPDATE FINAL TOTAL DISPLAY (Clean, just the amount)
    
    let summaryHtml = `<span class="text-3xl font-black text-royal-purple">₱${amountToPay.toLocaleString(undefined, {minimumFractionDigits: 2})}</span>`;
    
    // Optional: Show "Discount Applied" label near total if discount > 0
    if (discountAmount > 0) {
        summaryHtml += `<div class="text-xs text-green-600 font-bold mt-1 text-right">Saved: ₱${discountAmount.toLocaleString(undefined, {minimumFractionDigits: 2})}</div>`;
    }
    
    displayEl.innerHTML = summaryHtml;
    
    // Update Hidden Payment Input to match the Calculated Net Amount
    const paymentInput = document.getElementById('transactionPaymentInput');
    if (paymentInput) {
        paymentInput.value = amountToPay;
        // Also update visible input if it exists (for manual overrides enabled cases)
        const visibleInput = document.getElementById('transactionVisibleInput'); // If exists
        if (visibleInput) visibleInput.value = amountToPay;
    }

    // Update Button Text
    if (discountAmount > 0) {
        btnParam.textContent = 'Edit Discount';
    } else {
        btnParam.textContent = 'Apply Discount';
    }
};

window.toggleTransactionPaymentScheme = function() {
    const scheme = document.querySelector('input[name="payment_scheme"]:checked').value;
    const dpContainer = document.getElementById('transactionDpContainer');
    const dpInput = document.getElementById('transactionDpInput');
    const detailsDisplay = document.getElementById('installmentDetailsDisplay');
    
    if (scheme === 'installment') {
        dpContainer.classList.remove('hidden');
        if (dpInput) dpInput.setAttribute('required', 'required');
        
        // Show Installment Details Box
        if (detailsDisplay) {
            detailsDisplay.classList.remove('hidden');
            const months = document.getElementById('installment_months').value || 12;
            document.getElementById('displayDuration').textContent = months + ' months';
            
            // Show config
            const configContainer = document.getElementById('installmentConfigContainer');
            if (configContainer) {
                configContainer.classList.remove('hidden');
                // Sync select
                const durSelect = document.getElementById('installmentDurationSelect');
                if (durSelect) durSelect.value = months;
            }
        }
        
        // Retrieve total amount to set min/max/default
        const totalText = document.getElementById('selectedTransactionAmount').textContent;
        const totalVal = parseFloat(totalText.replace(/[^0-9.-]+/g,""));
        if (!isNaN(totalVal)) {
             // Recalculate Min Downpayment based on Final Amount
             // If DB has a specific down payment set, use it. Otherwise default to 20%
             const dbDownPayment = parseFloat(document.getElementById('installment_down_payment_db')?.value || 0);
             let minDpVal = 0;
             if (dbDownPayment > 0) {
                 minDpVal = dbDownPayment;
             } else {
                 minDpVal = totalVal * 0.20;
             }
             
             const minDp = minDpVal.toFixed(2);
             if (dpInput) {
                 dpInput.min = minDp;
                 dpInput.placeholder = minDp + " (Min)";
                 
                 // If input is empty, set it to min
                 if (dpInput.value === '') {
                     dpInput.value = minDp;
                     // Trigger input event to update totals
                     dpInput.dispatchEvent(new Event('input'));
                 }
             }
        }
    } else {
        dpContainer.classList.add('hidden');
        if (dpInput) {
            dpInput.removeAttribute('required');
            dpInput.value = '';
        }
        
        const configContainer = document.getElementById('installmentConfigContainer');
        if (configContainer) configContainer.classList.add('hidden');
        if (detailsDisplay) detailsDisplay.classList.add('hidden');
    }
    
    // UI RESET: Toggle Service Breakdown based on Scheme
    const breakdownContainer = document.getElementById('serviceBreakdownContainer');
    if (breakdownContainer) {
        if (scheme === 'installment') {
            breakdownContainer.classList.add('hidden');
        } else {
            // Restore visibility ONLY if there is breakdown data
            const breakdownData = document.getElementById('transactionPaymentForm').dataset.servicesBreakdown;
            if (breakdownData && breakdownData !== '[]' && breakdownData !== '') {
                breakdownContainer.classList.remove('hidden');
            }
        }
    }
    
    // Re-run totals update
    updateTransactionPayment();
}

// Add Listener to DP Input to recalculate monthly
document.getElementById('transactionDpInput')?.addEventListener('input', updateTransactionPayment);



// File Upload Triggers
window.triggerFileUpload = function() {
    document.getElementById('discount_id_image').click();
};

window.updateFileName = function() {
    const fileInput = document.getElementById('discount_id_image');
    const fileNameDisplay = document.getElementById('file_name_display');
    if (fileInput.files.length > 0) {
        fileNameDisplay.textContent = fileInput.files[0].name;
    } else {
        fileNameDisplay.textContent = 'No file chosen';
    }
};

window.clearSelectedTransaction = function() {
    selectedTransactionDisplay.classList.add('hidden');
    transactionPaymentForm.classList.add('hidden');
    document.getElementById('installmentPaymentInputContainer').classList.add('hidden');
    selectedRecordId.value = '';
    selectedTransactionInitials.textContent = '--';
    selectedTransactionPatient.textContent = '--';
    selectedTransactionService.textContent = '--';
    selectedTransactionDate.textContent = '--';
    selectedTransactionAmount.textContent = '--';
}

window.loadPreviousTransactionPage = function() {
    if (currentTransactionPage > 1) {
        currentTransactionPage--;
        loadTransactionPage();
    }
};

window.loadNextTransactionPage = function() {
    if (currentTransactionPage < totalTransactionPages) {
        currentTransactionPage++;
        loadTransactionPage();
    }
};

// Close modal when clicking outside


// Patient search functionality (only if patientSearchInput exists - removed in new design)
let patientSearchTimeout;
const patientSearchInput = document.getElementById('patientSearchInput');
const selectedPatientDisplay = document.getElementById('selectedPatientDisplay');
const selectedPatientId = document.getElementById('selectedPatientId');
const selectedPatientInitials = document.getElementById('selectedPatientInitials');
const selectedPatientName = document.getElementById('selectedPatientName');
const selectedPatientInfo = document.getElementById('selectedPatientInfo');

// Create dropdown for search results (only if patientSearchInput exists)
let searchDropdown = null;
if (patientSearchInput && patientSearchInput.parentElement) {
    searchDropdown = document.createElement('div');
    searchDropdown.id = 'patientSearchDropdown';
    searchDropdown.className = 'absolute z-50 w-full mt-1 bg-white border border-gray-200 rounded-xl shadow-lg max-h-60 overflow-y-auto hidden';
    patientSearchInput.parentElement.style.position = 'relative';
    patientSearchInput.parentElement.appendChild(searchDropdown);
}

// Only add event listener if patientSearchInput exists
if (patientSearchInput && searchDropdown) {
    patientSearchInput.addEventListener('input', function(e) {
        const query = this.value.trim();
        
        clearTimeout(patientSearchTimeout);
        
        if (query.length < 2) {
            searchDropdown.classList.add('hidden');
            return;
        }
        
        patientSearchTimeout = setTimeout(() => {
            // Fetch patients via AJAX
            fetch(`../api/search_patients.php?q=${encodeURIComponent(query)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.patients) {
                        displayPatientResults(data.patients);
                    } else {
                        searchDropdown.innerHTML = '<div class="p-3 text-sm text-slate-500">No patients found</div>';
                        searchDropdown.classList.remove('hidden');
                    }
                })
                .catch(error => {
                    console.error('Error searching patients:', error);
                    // Fallback: try direct search
                    if (typeof searchPatientsDirect === 'function') {
                        searchPatientsDirect(query);
                    }
                });
        }, 300);
    });
}

function displayPatientResults(patients) {
    if (!searchDropdown) return; // Element doesn't exist, skip
    
    if (patients.length === 0) {
        searchDropdown.innerHTML = '<div class="p-3 text-sm text-slate-500">No patients found</div>';
        searchDropdown.classList.remove('hidden');
        return;
    }
    
    searchDropdown.innerHTML = patients.map(patient => {
        const initials = (patient.first_name.charAt(0) + patient.last_name.charAt(0)).toUpperCase();
        const patientId = 'P-' + String(patient.patient_id).padStart(4, '0');
        return `
            <div class="p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-100 last:border-b-0" onclick="selectPatient(${patient.patient_id}, '${patient.first_name}', '${patient.last_name}', '${patientId}', '${patient.phone_number || ''}')">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-royal-purple text-white flex items-center justify-center font-bold text-xs">${initials}</div>
                    <div class="flex-1">
                        <p class="font-bold text-sm text-[#151118]">${patient.first_name} ${patient.last_name}</p>
                        <p class="text-xs text-slate-400">${patientId}${patient.phone_number ? ' • ' + patient.phone_number : ''}</p>
                    </div>
                </div>
            </div>
        `;
    }).join('');
    
    searchDropdown.classList.remove('hidden');
}

function selectPatient(patientId, firstName, lastName, patientIdDisplay, phoneNumber) {
    const initials = (firstName.charAt(0) + lastName.charAt(0)).toUpperCase();
    
    if (selectedPatientId) selectedPatientId.value = patientId;
    if (selectedPatientInitials) selectedPatientInitials.textContent = initials;
    if (selectedPatientName) selectedPatientName.textContent = firstName + ' ' + lastName;
    if (selectedPatientInfo) selectedPatientInfo.textContent = patientIdDisplay + (phoneNumber ? ' • ' + phoneNumber : '');
    
    if (selectedPatientDisplay) selectedPatientDisplay.classList.remove('hidden');
    if (patientSearchInput) patientSearchInput.value = firstName + ' ' + lastName;
    if (searchDropdown) searchDropdown.classList.add('hidden');
}

// Make selectPatient globally available
window.selectPatient = selectPatient;

// Filter dropdown functionality
function toggleFilterDropdown() {
    const dropdown = document.getElementById('filterDropdown');
    if (dropdown) {
        dropdown.classList.toggle('hidden');
    }
}

// Close filter dropdown when clicking outside
document.addEventListener('click', function(e) {
    const filterButton = document.querySelector('[onclick="toggleFilterDropdown()"]');
    const filterDropdown = document.getElementById('filterDropdown');
    if (filterButton && filterDropdown && !filterButton.contains(e.target) && !filterDropdown.contains(e.target)) {
        filterDropdown.classList.add('hidden');
    }
});

// Export to CSV functionality
function exportToCSV() {
    const searchQuery = <?php echo json_encode($search_query ?? ''); ?>;
    const statusFilter = <?php echo json_encode($status_filter ?? ''); ?>;
    const dateFilter = <?php echo json_encode($date_filter ?? ''); ?>;
    const dateFrom = <?php echo json_encode($date_from ?? ''); ?>;
    const dateTo = <?php echo json_encode($date_to ?? ''); ?>;
    
    // Build export URL with current filters
    let exportUrl = 'export_invoices.php?';
    const params = [];
    if (searchQuery) params.push('search=' + encodeURIComponent(searchQuery));
    if (statusFilter !== 'all') params.push('status=' + encodeURIComponent(statusFilter));
    if (dateFilter !== 'all') {
        params.push('date_filter=' + encodeURIComponent(dateFilter));
        if (dateFilter === 'range' && dateFrom && dateTo) {
            params.push('date_from=' + encodeURIComponent(dateFrom));
            params.push('date_to=' + encodeURIComponent(dateTo));
        }
    }
    exportUrl += params.join('&');
    
    // Open export in new window/tab
    window.location.href = exportUrl;
}

// Export to PDF functionality
function exportToPDF() {
    const searchQuery = <?php echo json_encode($search_query ?? ''); ?>;
    const statusFilter = <?php echo json_encode($status_filter ?? ''); ?>;
    const dateFilter = <?php echo json_encode($date_filter ?? ''); ?>;
    const dateFrom = <?php echo json_encode($date_from ?? ''); ?>;
    const dateTo = <?php echo json_encode($date_to ?? ''); ?>;
    
    // Build export URL with current filters
    let exportUrl = 'export_invoices_pdf.php?';
    const params = [];
    if (searchQuery) params.push('search=' + encodeURIComponent(searchQuery));
    if (statusFilter !== 'all') params.push('status=' + encodeURIComponent(statusFilter));
    if (dateFilter !== 'all') {
        params.push('date_filter=' + encodeURIComponent(dateFilter));
        if (dateFilter === 'range' && dateFrom && dateTo) {
            params.push('date_from=' + encodeURIComponent(dateFrom));
            params.push('date_to=' + encodeURIComponent(dateTo));
        }
    }
    exportUrl += params.join('&');
    
    // Open export in new window/tab
    window.open(exportUrl, '_blank');
}

// Date filter dropdown functionality
function toggleDateFilterDropdown() {
    const dropdown = document.getElementById('dateFilterDropdown');
    if (dropdown) {
        dropdown.classList.toggle('hidden');
    }
}

// Close date filter dropdown when clicking outside
document.addEventListener('click', function(e) {
    const dateFilterButton = document.querySelector('[onclick="toggleDateFilterDropdown()"]');
    const dateFilterDropdown = document.getElementById('dateFilterDropdown');
    if (dateFilterButton && dateFilterDropdown && !dateFilterButton.contains(e.target) && !dateFilterDropdown.contains(e.target)) {
        dateFilterDropdown.classList.add('hidden');
    }
});

// Close dropdown when clicking outside (only if elements exist)
if (patientSearchInput && searchDropdown) {
    document.addEventListener('click', function(e) {
        if (!patientSearchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
            searchDropdown.classList.add('hidden');
        }
    });
}

// Fallback direct search function (if API doesn't exist)
function searchPatientsDirect(query) {
    // This would need to be implemented with a server-side endpoint
    // For now, we'll just hide the dropdown
    if (searchDropdown) {
        searchDropdown.classList.add('hidden');
    }
}
// JS for Transaction Payment Scheme
// Duplicate function removed
// See window.toggleTransactionPaymentScheme above

// Toggle Payment Reference Input (GCash)
window.togglePaymentReference = function() {
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
    const container = document.getElementById('paymentReferenceContainer');
    const input = document.getElementById('paymentReferenceInput');
    
    if (paymentMethod === 'GCash') {
        container.classList.remove('hidden');
        input.setAttribute('required', 'required');
    } else {
        container.classList.add('hidden');
        input.removeAttribute('required');
        input.value = '';
    }
}

// IMPORTANT: Update selectTransaction to use the new layout
// IMPORTANT: Update selectTransaction to use the new layout
window.selectTransaction = function(recordId, patientName, serviceName, dateFormatted, amount, doctorName, appointmentId = '', originalPrice = 0, isInstallmentAvailable = 0, durationMonths = 0, durationMonthly = 0, serviceId = 0, appointmentFeePaid = 0, installmentDownPayment = 0, hasActiveInstallment = false, advancePaid = 0) {
    const initials = (patientName.split(' ')[0].charAt(0) + patientName.split(' ').pop().charAt(0)).toUpperCase();
    
    // Populate Hidden Inputs
    document.getElementById('selectedRecordId').value = recordId || '';
    document.getElementById('selectedAppointmentId').value = appointmentId || '';
    document.getElementById('selectedServiceId').value = serviceId || 0;
    
    // Store Original Values for Logic
    document.getElementById('original_service_price').value = originalPrice || amount; // Use original service price if available
    document.getElementById('original_is_installment').value = isInstallmentAvailable;
    // Fix: Default to 12 if 0
    document.getElementById('installment_months').value = durationMonths || 12;
    document.getElementById('installment_monthly_db').value = durationMonthly;
    
    // Store Appointment Fee Paid
    let feeInput = document.getElementById('appointment_fee_paid_input');
    if (!feeInput) {
        feeInput = document.createElement('input');
        feeInput.type = 'hidden';
        feeInput.id = 'appointment_fee_paid_input';
        document.getElementById('transactionPaymentForm').appendChild(feeInput);
    }
    feeInput.value = appointmentFeePaid || 0;

    // Store Advance Payment
    let advInput = document.getElementById('advance_payment_input');
    if (!advInput) {
        advInput = document.createElement('input');
        advInput.type = 'hidden';
        advInput.id = 'advance_payment_input';
        document.getElementById('transactionPaymentForm').appendChild(advInput);
    }
    advInput.value = advancePaid || 0;

    // Store Down Payment from DB
    let dpInputDb = document.getElementById('installment_down_payment_db');
    if (!dpInputDb) {
        dpInputDb = document.createElement('input');
        dpInputDb.type = 'hidden';
        dpInputDb.id = 'installment_down_payment_db';
        document.getElementById('transactionPaymentForm').appendChild(dpInputDb);
    }
    dpInputDb.value = installmentDownPayment || 0;

    // Populate Display
    document.getElementById('selectedTransactionInitials').textContent = initials;
    document.getElementById('selectedTransactionPatient').textContent = patientName;
    document.getElementById('selectedTransactionService').textContent = serviceName + ' • ' + dateFormatted;
    document.getElementById('selectedTransactionDate').textContent = 'Dr. ' + doctorName;
    document.getElementById('selectedTransactionAmount').textContent = '₱' + parseFloat(amount).toLocaleString(undefined, {minimumFractionDigits: 2});
    
    // Initial UI Update (will be overridden by updateTransactionPayment)
    // document.getElementById('totalServiceAmountDisplay').innerHTML = ... (removed redundant update)

    // Toggle Visibility
    document.getElementById('emptyTransactionState').classList.add('hidden');
    document.getElementById('selectedTransactionDisplay').classList.remove('hidden');
    
    // Hide config by default (will be shown if installment selected and toggle called)
    const configContainer = document.getElementById('installmentConfigContainer');
    if (configContainer) configContainer.classList.add('hidden');
    
    // Reset inputs
    document.getElementById('transactionPaymentInput').value = amount;
    
    // Reset Payment Scheme to Full
    const fullRadio = document.querySelector('input[name="payment_scheme"][value="full"]');
    fullRadio.checked = true;
    
    // Installment Constraints
    const installmentRadio = document.querySelector('input[name="payment_scheme"][value="installment"]');
    const isInstallmentBool = (isInstallmentAvailable == 1);
    
    // Logic: Disable if Not Available OR if Active Installment Exists
    const parent = installmentRadio.parentElement;
    const labelSpan = parent.querySelector('span') || parent;
    
    // Reset Label Text first (remove appended warning)
    // Assuming the structure is <label> <input> <div> <span>Text</span> </div> </label>
    // We might need to select the text container specifically.
    // For now, let's just use title attribute and opacity.
    
    if (hasActiveInstallment) {
        installmentRadio.disabled = true;
        parent.classList.add('opacity-50', 'cursor-not-allowed');
        parent.title = "Patient already has an ACTIVE installment plan (Limit: 1 per patient).";
        // Optional: Visual cue
        // alert("Note: This patient already has an active installment plan for this service."); 
    } else if (!isInstallmentBool) {
        installmentRadio.disabled = true;
        parent.classList.add('opacity-50', 'cursor-not-allowed');
        parent.title = "Not available for this service";
    } else {
        installmentRadio.disabled = false;
        parent.classList.remove('opacity-50', 'cursor-not-allowed');
        parent.title = "";
    }
    
    toggleTransactionPaymentScheme(); // Refresh UI logic
};

// Initialize Transaction List
document.addEventListener('DOMContentLoaded', function() {
    loadTransactionPage();
    
    // Add form submission debugging
    const paymentForm = document.getElementById('transactionPaymentForm');
    if (paymentForm) {
        paymentForm.addEventListener('submit', function(e) {
             // Basic validation before submit
             const scheme = document.querySelector('input[name="payment_scheme"]:checked')?.value;
             // Installment check
             if (scheme === 'installment') {
                 const dpInput = document.getElementById('transactionDpInput');
                 const dpValue = dpInput?.value;
                 if (!dpValue || parseFloat(dpValue) <= 0) {
                     e.preventDefault();
                     window.showAlert('Please enter a valid down payment amount (minimum 20% of total)', 'Validation Error');
                     return false;
                 }
             }
             
             // GCash check
             const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
             if (paymentMethod === 'GCash') {
                 const refInput = document.getElementById('paymentReferenceInput');
                 const refValue = refInput?.value;
                 if (!refValue || refValue.trim() === '') {
                     e.preventDefault();
                     window.showAlert('Please enter a GCash reference number', 'Validation Error');
                     return false;
                 }
             }

             // Validation passed - allow submit
             return true;
        });
    }
});



</script>
<!-- QR Code Modal -->
<div id="qrModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="toggleQRModal()"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white w-full max-w-sm rounded-2xl shadow-2xl overflow-hidden p-6 transform transition-all scale-100">
        <div class="text-center">
            <h3 class="text-xl font-bold text-slate-800 mb-2">Scan to Pay</h3>
            <div class="bg-blue-50/50 p-4 rounded-xl border border-blue-100 mb-4">
                <div class="bg-white p-2 rounded-lg shadow-sm w-fit mx-auto mb-2">
                    <img src="../images/Gcash.jpg" class="w-48 h-48 object-contain">
                </div>
                <p class="font-black text-xl text-slate-800 tracking-wide">0962 878 6207</p>
                <p class="text-xs text-slate-500 font-medium mt-1">GCash Payment</p>
            </div>
            <button onclick="toggleQRModal()" class="w-full py-3 bg-gray-100 hover:bg-gray-200 text-slate-700 font-bold rounded-xl transition-colors">
                Close
            </button>
    </div>
</div>

<script>
function toggleQRModal() {
    const modal = document.getElementById('qrModal');
    if (modal) {
        modal.classList.toggle('hidden');
    }
}
</script>
<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>