<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require admin role
requireRole('Admin');

// --- REPORT LOGIC (Same as admin_reports.php) ---

// 1. FINANCIAL FILTERS
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$doctor_id  = $_GET['doctor_id'] ?? 'all';

// Fetch Doctor Name for Display
$doctor_name = "All Doctors";
if ($doctor_id !== 'all') {
    try {
        $stmt = $pdo->prepare("SELECT full_name FROM tbl_user WHERE user_id = ?");
        $stmt->execute([$doctor_id]);
        $doctor_name = $stmt->fetchColumn() ?: "Unknown Doctor";
    } catch(Exception $e) {}
}

$date_clause = " BETWEEN ? AND ? ";
$params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59']; 

// --- FETCH DATA ---
$total_revenue = 0;
$invoices_issued = 0;
$new_patients_period = 0;
$appointments_period = 0;
$transactions = [];
$attendance_data = ['Completed'=>0, 'No-Show'=>0, 'Cancelled'=>0, 'Walk-in'=>0];
$top_services = [];

try {
    // REVENUE (Income Only) & INVOICES
    $inv_sql = "SELECT SUM(i.total_amount) as rev FROM tbl_invoice i 
                LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id 
                WHERE i.created_at $date_clause AND i.payment_status = 'Paid'";
    $inv_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    if ($doctor_id !== 'all') { $inv_sql .= " AND a.doctor_id = ?"; $inv_params[] = $doctor_id; }
    $stmt = $pdo->prepare($inv_sql);
    $stmt->execute($inv_params);
    $total_revenue = $stmt->fetchColumn() ?: 0;
    
    // Invoices Issued
    $inv_count_sql = "SELECT COUNT(i.invoice_id) FROM tbl_invoice i 
                      LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id 
                      WHERE i.created_at $date_clause";
    $stmt = $pdo->prepare($inv_count_sql);
    $stmt->execute($inv_params);
    $invoices_issued = $stmt->fetchColumn();

    // New Patients (Period)
    if ($doctor_id === 'all') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_patient WHERE registration_date BETWEEN ? AND ?");
        $stmt->execute([$start_date . ' 00:00:00', $end_date . ' 23:59:59']);
        $new_patients_period = $stmt->fetchColumn();
    } else { $new_patients_period = "-"; }

    // Appointments (Period)
    $apt_sql = "SELECT COUNT(*) FROM tbl_appointment a WHERE a.appointment_date BETWEEN ? AND ?";
    $apt_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') { $apt_sql .= " AND a.doctor_id = ?"; $apt_params[] = $doctor_id; }
    $stmt = $pdo->prepare($apt_sql);
    $stmt->execute($apt_params);
    $appointments_period = $stmt->fetchColumn();

    // Attendance
    $att_sql = "SELECT status, COUNT(*) FROM tbl_appointment a WHERE a.appointment_date BETWEEN ? AND ? ";
    $att_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') { $att_sql .= " AND a.doctor_id = ?"; $att_params[] = $doctor_id; }
    $att_sql .= " GROUP BY status";
    $stmt = $pdo->prepare($att_sql);
    $stmt->execute($att_params);
    while($row = $stmt->fetch(PDO::FETCH_NUM)) {
        if(isset($attendance_data[$row[0]])) $attendance_data[$row[0]] = $row[1];
    }
    // Walk-ins
    $walk_sql = "SELECT COUNT(*) FROM tbl_appointment a WHERE a.appointment_type = 'Walk-in' AND a.appointment_date BETWEEN ? AND ?";
    $walk_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') { $walk_sql .= " AND a.doctor_id = ?"; $walk_params[] = $doctor_id; }
    $stmt = $pdo->prepare($walk_sql);
    $stmt->execute($walk_params);
    $attendance_data['Walk-in'] = $stmt->fetchColumn();

    // Top Services
    $srv_sql = "SELECT s.service_name, COUNT(tas.service_id) as usage_count 
                FROM tbl_appointment_service tas
                JOIN tbl_appointment a ON tas.appointment_id = a.appointment_id
                JOIN tbl_service s ON tas.service_id = s.service_id
                WHERE a.appointment_date BETWEEN ? AND ? AND a.status = 'Completed' ";
    $srv_params = [$start_date, $end_date];
    if ($doctor_id !== 'all') { $srv_sql .= " AND a.doctor_id = ?"; $srv_params[] = $doctor_id; }
    $srv_sql .= " GROUP BY s.service_name ORDER BY usage_count DESC LIMIT 5";
    $stmt = $pdo->prepare($srv_sql);
    $stmt->execute($srv_params);
    $top_services = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recent Transactions
    $trx_sql = "SELECT i.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name
                FROM tbl_invoice i
                LEFT JOIN tbl_patient p ON i.patient_id = p.patient_id
                LEFT JOIN tbl_appointment a ON i.appointment_id = a.appointment_id
                WHERE i.created_at BETWEEN ? AND ? ";
    $trx_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    if ($doctor_id !== 'all') { $trx_sql .= " AND a.doctor_id = ?"; $trx_params[] = $doctor_id; }
    $trx_sql .= " ORDER BY i.created_at DESC LIMIT 20"; // Show more for print
    $stmt = $pdo->prepare($trx_sql);
    $stmt->execute($trx_params);
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) { }

// --- DEMOGRAPHICS (ALL TIME) ---
$gender_labels = []; $gender_counts = [];
$age_groups = [0, 0, 0];
$total_patients = 0;

try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM tbl_patient");
    $total_patients = $stmt->fetchColumn();

    $stmt = $pdo->query("SELECT gender, COUNT(*) FROM tbl_patient GROUP BY gender");
    while($row = $stmt->fetch(PDO::FETCH_NUM)) {
        $gender_labels[] = $row[0] ?: 'Unknown';
        $gender_counts[] = $row[1];
    }

    $stmt = $pdo->query("SELECT TIMESTAMPDIFF(YEAR, dob, CURDATE()) as age FROM tbl_patient");
    $ages = $stmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ages as $age) {
        if ($age >= 18 && $age <= 30) $age_groups[0]++;
        elseif ($age >= 31 && $age <= 50) $age_groups[1]++;
        elseif ($age >= 51) $age_groups[2]++;
    }
} catch (Exception $e) { }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Print View</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        /* Print optimizations */
        @media print {
            .no-print { display: none !important; }
            body { background: white; -webkit-print-color-adjust: exact; }
            .page-break { page-break-before: always; }
        }
    </style>
</head>
<body class="bg-gray-100 p-8 min-h-screen">

    <!-- Action Bar -->
    <div class="no-print fixed top-4 right-4 z-50 flex gap-2">
        <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded shadow font-bold hover:bg-blue-700">Print Report</button>
        <button onclick="downloadPDF()" class="bg-red-600 text-white px-4 py-2 rounded shadow font-bold hover:bg-red-700">Download PDF</button>
    </div>

    <!-- Report Container -->
    <div id="report-content" class="max-w-[800px] mx-auto bg-white p-12 shadow-lg min-h-[1100px]">
        
        <!-- Header -->
        <div class="border-b border-gray-200 pb-8 mb-8 flex justify-between items-end">
            <div>
                <h1 class="text-3xl font-bold text-gray-900 mb-2">Admin Report</h1>
                <p class="text-gray-500">Generated on <?php echo date('F j, Y'); ?></p>
            </div>
            <div class="text-right">
                <p class="text-sm font-bold text-gray-900"><?php echo htmlspecialchars($doctor_name); ?></p>
                <p class="text-sm text-gray-500"><?php echo $start_date; ?> to <?php echo $end_date; ?></p>
            </div>
        </div>

        <!-- SECTION 1: FINANCIALS & OPERATIONS -->
        <h2 class="text-xl font-bold text-gray-800 border-l-4 border-purple-600 pl-3 mb-6">Financial & Operational Overview</h2>

        <!-- KPI Grid -->
        <div class="grid grid-cols-4 gap-4 mb-8">
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-bold">Total Revenue</p>
                <p class="text-xl font-bold text-gray-900">$<?php echo number_format($total_revenue, 2); ?></p>
            </div>
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-bold">Invoices</p>
                <p class="text-xl font-bold text-gray-900"><?php echo $invoices_issued; ?></p>
            </div>
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-bold">New Patients</p>
                <p class="text-xl font-bold text-gray-900"><?php echo $new_patients_period; ?></p>
            </div>
            <div class="p-4 bg-gray-50 rounded-lg">
                <p class="text-xs text-gray-500 uppercase font-bold">Appointments</p>
                <p class="text-xl font-bold text-gray-900"><?php echo $appointments_period; ?></p>
            </div>
        </div>

        <!-- Attendance & Services -->
        <div class="grid grid-cols-2 gap-8 mb-8">
            <!-- Attendance -->
            <div>
                <h3 class="font-bold text-gray-900 mb-3">Appointment Attendance</h3>
                <table class="w-full text-sm">
                    <?php 
                    $statuses = ['Completed', 'No-Show', 'Cancelled', 'Walk-in'];
                    $total_att = array_sum($attendance_data) ?: 1;
                    foreach($statuses as $k):
                        $v = $attendance_data[$k] ?? 0;
                        $pct = round(($v/$total_att)*100);
                    ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-600"><?php echo $k; ?></td>
                        <td class="py-2 text-right font-bold"><?php echo $v; ?> (<?php echo $pct; ?>%)</td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
             <!-- Top Services -->
             <div>
                <h3 class="font-bold text-gray-900 mb-3">Top Services</h3>
                <table class="w-full text-sm">
                    <?php foreach($top_services as $s): ?>
                    <tr class="border-b border-gray-100">
                        <td class="py-2 text-gray-600 truncate max-w-[150px]"><?php echo htmlspecialchars($s['service_name']); ?></td>
                        <td class="py-2 text-right font-bold"><?php echo $s['usage_count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($top_services)): ?>
                        <tr><td class="py-2 text-gray-500">No completed services.</td></tr>
                    <?php endif; ?>
                </table>
            </div>
        </div>

        <!-- Transactions Table (Compact) -->
        <div class="mb-10">
            <h3 class="font-bold text-gray-900 mb-3">Recent Transactions (Top 20)</h3>
            <table class="w-full text-xs text-left">
                <thead class="bg-gray-100 text-gray-600 uppercase">
                    <tr>
                        <th class="px-3 py-2">Date</th>
                        <th class="px-3 py-2">Patient</th>
                        <th class="px-3 py-2 text-right">Amount</th>
                        <th class="px-3 py-2 text-right">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach($transactions as $t): ?>
                    <tr>
                        <td class="px-3 py-2 text-gray-600"><?php echo date('M d, Y', strtotime($t['created_at'])); ?></td>
                        <td class="px-3 py-2 font-medium text-gray-900"><?php echo htmlspecialchars($t['patient_name']); ?></td>
                        <td class="px-3 py-2 text-right font-bold">$<?php echo number_format($t['total_amount']); ?></td>
                        <td class="px-3 py-2 text-right"><?php echo htmlspecialchars($t['payment_status']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Page Break for Demographics -->
        <div class="page-break"></div>

        <!-- SECTION 2: DEMOGRAPHICS -->
        <div class="mt-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800 border-l-4 border-yellow-500 pl-3">Patient Demographics</h2>
                <span class="bg-gray-100 text-gray-600 text-xs px-2 py-1 rounded font-bold uppercase">All Time Data</span>
            </div>

            <div class="grid grid-cols-2 gap-8">
                <!-- Gender Stats -->
                <div class="p-6 bg-gray-50 rounded-xl">
                    <h3 class="font-bold text-gray-900 mb-4 text-center">Gender Distribution</h3>
                    <div class="space-y-3">
                         <?php 
                         $total_gen = array_sum($gender_counts) ?: 1;
                         foreach($gender_labels as $idx => $lbl): 
                            $cnt = $gender_counts[$idx];
                            $pct = round(($cnt/$total_gen)*100);
                            
                            // Color Mapping
                            $color = '#D2B48C'; // Default Beige
                            if(stripos($lbl, 'Female') !== false) $color = '#FFD700';
                            elseif(stripos($lbl, 'Male') !== false) $color = '#8a11d4';
                         ?>
                         <div class="flex justify-between items-center text-sm">
                             <span class="text-gray-600"><?php echo $lbl; ?></span>
                             <div class="flex items-center gap-2">
                                <span class="font-bold"><?php echo $cnt; ?></span>
                                <span class="text-xs text-gray-400">(<?php echo $pct; ?>%)</span>
                             </div>
                         </div>
                         <div class="h-1.5 w-full bg-gray-200 rounded-full overflow-hidden">
                             <div class="h-full" style="background-color: <?php echo $color; ?>; width: <?php echo $pct; ?>%"></div>
                         </div>
                         <?php endforeach; ?>
                    </div>
                </div>

                <!-- Age Stats -->
                <div class="p-6 bg-gray-50 rounded-xl">
                    <h3 class="font-bold text-gray-900 mb-4 text-center">Age Distribution</h3>
                    <div class="space-y-4">
                        <?php 
                        $age_labels = ['18-30 Years', '31-50 Years', '51+ Years'];
                        $total_ages = array_sum($age_groups) ?: 1;
                        foreach($age_groups as $idx => $cnt):
                            $pct = round(($cnt/$total_ages)*100);
                        ?>
                        <div>
                             <div class="flex justify-between text-sm mb-1">
                                 <span class="text-gray-600"><?php echo $age_labels[$idx]; ?></span>
                                 <span class="font-bold"><?php echo $cnt; ?></span>
                             </div>
                             <div class="h-2 w-full bg-gray-200 rounded-full overflow-hidden">
                                 <div class="h-full bg-yellow-400" style="width: <?php echo $pct; ?>%"></div>
                             </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="mt-8 text-center">
                 <p class="text-gray-500 text-sm">Total Registered Patients</p>
                 <p class="text-4xl font-bold text-gray-900"><?php echo number_format($total_patients); ?></p>
            </div>
        </div>

    </div>

    <script>
        function downloadPDF() {
            const element = document.getElementById('report-content');
            const opt = {
                margin:       [10, 10, 10, 10], // top, left, bottom, right
                filename:     'Admin_Report_<?php echo $end_date; ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2, useCORS: true },
                jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
            };
            html2pdf().set(opt).from(element).save();
        }
    </script>
</body>
</html>
