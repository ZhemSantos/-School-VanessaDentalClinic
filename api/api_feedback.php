<?php
session_start();
require_once '../db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['patient_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? '';
$patient_id = $_SESSION['patient_id'];

try {
    switch ($action) {
        case 'eligible':
            getEligibleInvoices($pdo, $patient_id);
            break;
        case 'submit':
            submitFeedback($pdo, $patient_id);
            break;
        case 'history':
            getFeedbackHistory($pdo, $patient_id);
            break;
        default:
             echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

function getEligibleInvoices($pdo, $patient_id) {
    // Find Paired Invoices (linked to appointments of this patient)
    // That do NOT have an entry in tbl_feedback
    $stmt = $pdo->prepare("
        SELECT i.invoice_id, i.reference_number, i.total_amount, i.paid_at, 
               s.service_name, a.appointment_date
        FROM tbl_invoice i
        JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
        LEFT JOIN tbl_service s ON tas.service_id = s.service_id
        LEFT JOIN tbl_feedback f ON i.invoice_id = f.invoice_id
        WHERE a.patient_id = ? 
          AND i.payment_status = 'Paid'
          AND f.feedback_id IS NULL
        GROUP BY i.invoice_id
        ORDER BY i.paid_at DESC
    ");
    $stmt->execute([$patient_id]);
    echo json_encode(['success' => true, 'invoices' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function submitFeedback($pdo, $patient_id) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('Invalid method');
    $input = json_decode(file_get_contents('php://input'), true);
    
    $invoice_id = $input['invoice_id'] ?? 0;
    $booking = intval($input['booking_rating'] ?? 0);
    $procedure = intval($input['procedure_rating'] ?? 0);
    $payment = intval($input['payment_rating'] ?? 0);
    $comments = trim($input['comments'] ?? '');
    
    if ($booking < 1 || $booking > 5 || $procedure < 1 || $procedure > 5 || $payment < 1 || $payment > 5) {
        throw new Exception('Invalid ratings (must be 1-5)');
    }
    
    // Verify invoice ownership and validate it's a paid medical invoice
    $chk = $pdo->prepare("
        SELECT i.payment_status, i.record_id, COUNT(*) as count
        FROM tbl_invoice i 
        JOIN tbl_appointment a ON i.appointment_id = a.appointment_id 
        WHERE i.invoice_id = ? AND a.patient_id = ?
    ");
    $chk->execute([$invoice_id, $patient_id]);
    $inv = $chk->fetch();
    
    if (!$inv || $inv['count'] == 0) {
        throw new Exception('Invoice not found or access denied');
    }
    
    // BUSINESS RULE: Feedback can only be submitted for medical procedures (invoices with record_id)
    if (!$inv['record_id']) {
        throw new Exception('Feedback can only be submitted for medical procedures, not appointment fees');
    }
    
    // BUSINESS RULE: Feedback can only be submitted for paid invoices
    if ($inv['payment_status'] !== 'Paid') {
        throw new Exception('Feedback can only be submitted for paid invoices. Please complete payment first.');
    }
    
    // Calculate Overall Average
    $overall = ($booking + $procedure + $payment) / 3.0;
    
    $stmt = $pdo->prepare("INSERT INTO tbl_feedback (invoice_id, rating, booking_rating, procedure_rating, payment_rating, overall_rating, comments, date_submitted) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
    // We store floor(overall) in 'rating' legacy column, or just the overall rounded? Let's assume legacy 'rating' is overall rounded.
    $stmt->execute([$invoice_id, round($overall), $booking, $procedure, $payment, $overall, $comments]);
    
    echo json_encode(['success' => true, 'message' => 'Feedback submitted. Thank you!']);
}

function getFeedbackHistory($pdo, $patient_id) {
    $stmt = $pdo->prepare("
        SELECT f.*, i.reference_number, DATE_FORMAT(f.date_submitted, '%M %d, %Y') as date_fmt
        FROM tbl_feedback f
        JOIN tbl_invoice i ON f.invoice_id = i.invoice_id
        JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
        WHERE a.patient_id = ?
        ORDER BY f.date_submitted DESC
    ");
    $stmt->execute([$patient_id]);
    echo json_encode(['success' => true, 'feedback' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}
?>
