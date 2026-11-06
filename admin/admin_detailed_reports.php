<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once 'report_logic.php'; // Shared Logic

configureSecureSession();
requireRole('Admin');
updateUserActivity();

// --- FILTERS ---
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$report_date = $_GET['report_date'] ?? date('Y-m-d'); // For daily specific reports

// Fetch Data Using Logic
$reports_data = getReportsData($pdo, $start_date, $end_date, $report_date);

// Helper for Category Export Buttons
function categoryExportBtn($category, $start, $end, $date) {
    return '
    <div class="flex items-center gap-2">
        <a href="admin_export_category.php?category='.$category.'&start_date='.$start.'&end_date='.$end.'&report_date='.$date.'" 
           class="flex items-center gap-2 px-3 py-1.5 bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 rounded-lg text-xs font-bold text-green-700 dark:text-green-400 hover:bg-green-100 dark:hover:bg-green-900/50 transition-all"
           title="Download Zip of CSVs">
            <span class="material-symbols-outlined text-[16px]">folder_zip</span> CSV Zip
        </a>
        <button onclick="openExportModal(\''.$category.'\', \''.$start.'\', \''.$end.'\', \''.$date.'\')" 
           class="flex items-center gap-2 px-3 py-1.5 bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 rounded-lg text-xs font-bold text-red-700 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/50 transition-all"
           title="Select Reports to PDF">
            <span class="material-symbols-outlined text-[16px]">picture_as_pdf</span> PDF
        </button>
    </div>';
}

// Helper for Individual Export Button
function exportBtn($key, $start, $end, $date) {
    return '
    <a href="admin_export_detailed.php?report_key='.$key.'&start_date='.$start.'&end_date='.$end.'&report_date='.$date.'" 
       class="flex items-center gap-2 px-3 py-1.5 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg text-xs font-bold text-text-sub hover:text-primary hover:border-primary/30 transition-all shadow-sm"
       title="Download CSV">
        <span class="material-symbols-outlined text-[16px]">download</span> Export
    </a>';
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Detailed Reports</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script>
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4", "accent": "#FFD700",
                        "background-light": "#f7f6f8", "background-dark": "#1b1022",
                        "surface-light": "#ffffff", "surface-dark": "#2d1b36",
                        "text-main": "#151118", "text-sub": "#7a6189",
                    },
                    fontFamily: { "display": ["Manrope", "sans-serif"] },
                },
            },
        }
    </script>
    <style>
        /* Custom Scrollbar for inner report areas */
        .report-section { scroll-margin-top: 100px; }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark text-text-main dark:text-white font-display">

<!-- Header -->
<header class="fixed top-0 left-0 right-0 h-20 flex items-center justify-between px-8 bg-surface-light dark:bg-surface-dark border-b border-[#f3f0f4] dark:border-gray-800 z-50">
    <div class="flex items-center gap-4">
        <a href="admin_reports.php" class="p-2 rounded-xl hover:bg-gray-100 dark:hover:bg-gray-800 text-text-sub transition-colors">
            <span class="material-symbols-outlined">arrow_back</span>
        </a>
        <h1 class="text-xl font-bold">Detailed Analytics</h1>
    </div>

    <!-- Global Filter Form -->
    <form class="flex items-center gap-4 bg-gray-50 dark:bg-gray-800 px-4 py-2 rounded-xl border border-gray-100 dark:border-gray-700">
        <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-text-sub uppercase">Range:</span>
            <input type="date" name="start_date" value="<?php echo $start_date; ?>" class="bg-transparent border-none text-sm font-bold p-0 focus:ring-0">
            <span class="text-text-sub">-</span>
            <input type="date" name="end_date" value="<?php echo $end_date; ?>" class="bg-transparent border-none text-sm font-bold p-0 focus:ring-0">
        </div>
        <div class="w-px h-6 bg-gray-200 dark:bg-gray-700"></div>
        <div class="flex items-center gap-2">
            <span class="text-xs font-bold text-text-sub uppercase">Single Day:</span>
            <input type="date" name="report_date" value="<?php echo $report_date; ?>" class="bg-transparent border-none text-sm font-bold p-0 focus:ring-0">
        </div>
        <button type="submit" class="bg-primary text-white p-1.5 rounded-lg hover:bg-primary/90 transition-colors">
            <span class="material-symbols-outlined text-sm">refresh</span>
        </button>
    </form>
</header>

<div class="flex pt-20 h-screen overflow-hidden">
    <!-- Sidebar Navigation -->
    <aside class="w-72 bg-surface-light dark:bg-surface-dark border-r border-[#f3f0f4] dark:border-gray-800 overflow-y-auto p-6 hidden lg:block">
        <p class="text-xs font-bold text-text-sub uppercase tracking-wider mb-4">Report Categories</p>
        <nav class="flex flex-col gap-2">
            <a href="#financial" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-purple-50 dark:hover:bg-gray-800 text-text-main dark:text-gray-300 font-medium transition-colors">
                <span class="material-symbols-outlined text-primary">attach_money</span> Financials
            </a>
            <a href="#clinical" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-purple-50 dark:hover:bg-gray-800 text-text-main dark:text-gray-300 font-medium transition-colors">
                <span class="material-symbols-outlined text-blue-500">medical_services</span> Clinical & Ops
            </a>
            <a href="#installment" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-purple-50 dark:hover:bg-gray-800 text-text-main dark:text-gray-300 font-medium transition-colors">
                <span class="material-symbols-outlined text-amber-500">credit_score</span> Installments
            </a>
            <a href="#management" class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-purple-50 dark:hover:bg-gray-800 text-text-main dark:text-gray-300 font-medium transition-colors">
                <span class="material-symbols-outlined text-teal-500">monitoring</span> Management
            </a>
        </nav>
        
        <div class="mt-8 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-100 dark:border-blue-900/30">
            <div class="flex items-center gap-2 mb-2">
                <span class="material-symbols-outlined text-blue-600">info</span>
                <span class="text-sm font-bold text-blue-800 dark:text-blue-300">Note</span>
            </div>
            <p class="text-xs text-blue-800/80 dark:text-blue-300/80 leading-relaxed">
                Use the date pickers above to filter range-based reports. "Daily" reports use the specific "Single Day" selector.
            </p>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="flex-1 overflow-y-auto p-8 scroll-smooth">
        <div class="max-w-6xl mx-auto space-y-16 pb-20">

            <!-- 1. FINANCIAL REPORTS -->
            <section id="financial" class="report-section">
                <div class="flex items-center justify-between mb-6">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-purple-100 dark:bg-purple-900/30 rounded-lg"><span class="material-symbols-outlined text-primary">attach_money</span></div>
                        <h2 class="text-2xl font-bold">Financial Reports</h2>
                    </div>
                    <?php echo categoryExportBtn('financial', $start_date, $end_date, $report_date); ?>
                </div>

                <div class="grid grid-cols-1 gap-8">
                    <!-- 1.1 Transactions -->
                    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                        <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between items-center">
                            <div>
                                <h3 class="font-bold text-lg">1.1 Financial Transactions Report</h3>
                                <p class="text-xs text-text-sub">Cash tracking and audit support</p>
                            </div>
                            <?php echo exportBtn('financial_transactions', $start_date, $end_date, $report_date); ?>
                        </div>
                        <div class="overflow-x-auto max-h-[400px]">
                            <table class="w-full text-left text-sm whitespace-nowrap">
                                <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
                                    <tr>
                                        <th class="px-6 py-3 font-bold text-text-sub">Invoice #</th>
                                        <th class="px-6 py-3 font-bold text-text-sub">Patient</th>
                                        <th class="px-6 py-3 font-bold text-text-sub">Service</th>
                                        <th class="px-6 py-3 font-bold text-text-sub">Method</th>
                                        <th class="px-6 py-3 font-bold text-text-sub text-right">Amount</th>
                                        <th class="px-6 py-3 font-bold text-text-sub">Date Paid</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach($reports_data['financial_transactions'] as $row): ?>
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/20">
                                        <td class="px-6 py-3 font-mono text-xs"><?php echo htmlspecialchars($row['invoice_no']); ?></td>
                                        <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                        <td class="px-6 py-3 text-xs truncate max-w-[200px]" title="<?php echo htmlspecialchars($row['service']); ?>"><?php echo htmlspecialchars($row['service']); ?></td>
                                        <td class="px-6 py-3"><?php echo htmlspecialchars($row['payment_method']); ?></td>
                                        <td class="px-6 py-3 text-right font-bold text-primary">₱<?php echo number_format($row['amount_paid'], 2); ?></td>
                                        <td class="px-6 py-3 text-text-sub text-xs"><?php echo date('M d, Y', strtotime($row['date_paid'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- 1.2 Daily Sales -->
                        <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                             <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between">
                                <div>
                                    <h3 class="font-bold text-lg">1.2 Daily Sales</h3>
                                    <p class="text-xs text-text-sub">For Date: <?php echo date('M d, Y', strtotime($report_date)); ?></p>
                                </div>
                                <?php echo exportBtn('daily_sales', $start_date, $end_date, $report_date); ?>
                            </div>
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Date</th><th class="px-6 py-3 text-right">Count</th><th class="px-6 py-3 text-right">Total</th></tr></thead>
                                <tbody>
                                    <?php foreach($reports_data['daily_sales'] as $row): ?>
                                    <tr>
                                        <td class="px-6 py-3"><?php echo $row['sales_date']; ?></td>
                                        <td class="px-6 py-3 text-right"><?php echo $row['total_transactions']; ?></td>
                                        <td class="px-6 py-3 text-right font-bold">₱<?php echo number_format($row['total_sales'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; if(empty($reports_data['daily_sales'])): echo '<tr><td colspan="3" class="px-6 py-4 text-center text-text-sub">No sales for this day.</td></tr>'; endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- 1.3 Methods -->
                        <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                             <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between">
                                <h3 class="font-bold text-lg">1.3 Payment Methods</h3>
                                <?php echo exportBtn('payment_methods', $start_date, $end_date, $report_date); ?>
                            </div>
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Method</th><th class="px-6 py-3 text-right">Count</th><th class="px-6 py-3 text-right">Volume</th></tr></thead>
                                <tbody>
                                    <?php foreach($reports_data['payment_methods'] as $row): ?>
                                    <tr>
                                        <td class="px-6 py-3 font-bold"><?php echo $row['payment_method']; ?></td>
                                        <td class="px-6 py-3 text-right"><?php echo $row['transaction_count']; ?></td>
                                        <td class="px-6 py-3 text-right">₱<?php echo number_format($row['total_amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- 1.4 AR -->
                    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                        <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between">
                            <h3 class="font-bold text-lg text-red-500">1.4 Accounts Receivable (Unpaid)</h3>
                            <?php echo exportBtn('accounts_receivable', $start_date, $end_date, $report_date); ?>
                        </div>
                        <div class="max-h-[300px] overflow-y-auto">
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0"><tr><th class="px-6 py-3">Ref #</th><th class="px-6 py-3">Created</th><th class="px-6 py-3">Status</th><th class="px-6 py-3 text-right">Amount</th></tr></thead>
                                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <?php foreach($reports_data['accounts_receivable'] as $row): ?>
                                    <tr>
                                        <td class="px-6 py-3 font-mono text-xs"><?php echo $row['reference_number']; ?></td>
                                        <td class="px-6 py-3 text-text-sub text-xs"><?php echo date('M d', strtotime($row['created_at'])); ?></td>
                                        <td class="px-6 py-3"><span class="px-2 py-1 rounded bg-red-100 text-red-700 text-xs font-bold"><?php echo $row['payment_status']; ?></span></td>
                                        <td class="px-6 py-3 text-right font-bold text-red-600">₱<?php echo number_format($row['total_amount'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- 1.5 Discounts -->
                <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden mt-8">
                    <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between items-center">
                        <div>
                            <h3 class="font-bold text-lg text-purple-600">1.5 Discounts & Exemptions</h3>
                            <p class="text-xs text-text-sub">Senior & PWD Discount Tracking</p>
                        </div>
                        <?php echo exportBtn('discounts', $start_date, $end_date, $report_date); ?>
                    </div>
                    <div class="max-h-[300px] overflow-y-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-text-sub">Invoice #</th>
                                    <th class="px-6 py-3 font-bold text-text-sub">Patient</th>
                                    <th class="px-6 py-3 font-bold text-text-sub">Type</th>
                                    <th class="px-6 py-3 font-bold text-text-sub">ID Number</th>
                                    <th class="px-6 py-3 font-bold text-text-sub">Issuer</th>
                                    <th class="px-6 py-3 font-bold text-text-sub text-right">Discount</th>
                                    <th class="px-6 py-3 font-bold text-text-sub text-right">VAT Exempt</th>
                                    <th class="px-6 py-3 font-bold text-text-sub">Verified By</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($reports_data['discounts'] as $row): ?>
                                <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/20">
                                    <td class="px-6 py-3 font-mono text-xs"><?php echo htmlspecialchars($row['reference_number']); ?></td>
                                    <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td class="px-6 py-3"><span class="px-2 py-0.5 rounded text-xs font-bold bg-purple-100 dark:bg-purple-900 text-purple-700 dark:text-purple-300"><?php echo htmlspecialchars($row['discount_type']); ?></span></td>
                                    <td class="px-6 py-3"><?php echo htmlspecialchars($row['id_number']); ?></td>
                                    <td class="px-6 py-3 text-xs"><?php echo htmlspecialchars($row['id_issuer']); ?></td>
                                    <td class="px-6 py-3 text-right font-bold text-red-500">-₱<?php echo number_format($row['discount_amount'], 2); ?></td>
                                    <td class="px-6 py-3 text-right text-text-sub">₱<?php echo number_format($row['vat_exempt_amount'], 2); ?></td>
                                    <td class="px-6 py-3 text-xs"><?php echo htmlspecialchars($row['verified_by']); ?></td>
                                </tr>
                                <?php endforeach; if(empty($reports_data['discounts'])): echo '<tr><td colspan="8" class="px-6 py-4 text-center text-text-sub">No discounts applied in this period.</td></tr>'; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </section>


            <!-- 2. CLINICAL REPORTS -->
            <section id="clinical" class="report-section">
                <div class="flex items-center justify-between mb-6 pt-8 border-t border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-blue-100 dark:bg-blue-900/30 rounded-lg"><span class="material-symbols-outlined text-blue-600">medical_services</span></div>
                        <h2 class="text-2xl font-bold">Clinical & Operational</h2>
                    </div>
                    <?php echo categoryExportBtn('clinical', $start_date, $end_date, $report_date); ?>
                </div>
                
                 <div class="grid grid-cols-1 gap-8">
                     <!-- 2.1 Treatment Log -->
                    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                        <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-gray-50/50 dark:bg-gray-800/50 flex justify-between">
                            <div>
                                <h3 class="font-bold text-lg">2.1 Daily Treatment Log</h3>
                                <p class="text-xs text-text-sub">For Date: <?php echo date('M d, Y', strtotime($report_date)); ?></p>
                            </div>
                            <?php echo exportBtn('treatment_log', $start_date, $end_date, $report_date); ?>
                        </div>
                         <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Patient</th><th class="px-6 py-3">Service</th><th class="px-6 py-3">Doctor</th><th class="px-6 py-3">Teeth</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($reports_data['treatment_log'] as $row): ?>
                                <tr>
                                    <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td class="px-6 py-3"><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td class="px-6 py-3"><?php echo htmlspecialchars($row['dentist']); ?></td>
                                    <td class="px-6 py-3 font-mono text-xs"><?php echo htmlspecialchars($row['tooth_numbers'] ?? '-'); ?></td>
                                </tr>
                                <?php endforeach; if(empty($reports_data['treatment_log'])): echo '<tr><td colspan="4" class="px-6 py-4 text-center text-text-sub">No treatments logged for this day.</td></tr>'; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                        <!-- 2.2 Attendance -->
                        <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                            <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex justify-between">
                                <h3 class="font-bold text-lg">2.2 Appointment Attendance</h3>
                                <?php echo exportBtn('attendance', $start_date, $end_date, $report_date); ?>
                            </div>
                            <div class="max-h-[300px] overflow-y-auto">
                                <table class="w-full text-left text-sm">
                                    <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Date</th><th class="px-6 py-3">Status</th><th class="px-6 py-3 text-right">Count</th></tr></thead>
                                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                        <?php foreach($reports_data['attendance'] as $row): ?>
                                        <tr>
                                            <td class="px-6 py-3"><?php echo $row['appointment_date']; ?></td>
                                            <td class="px-6 py-3"><span class="px-2 py-0.5 rounded text-xs font-bold bg-gray-100 dark:bg-gray-700"><?php echo $row['status']; ?></span></td>
                                            <td class="px-6 py-3 text-right font-bold"><?php echo $row['total']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                         <!-- 2.3 Productivity -->
                        <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                            <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex justify-between">
                                <h3 class="font-bold text-lg">2.3 Dentist Productivity</h3>
                                <?php echo exportBtn('productivity', $start_date, $end_date, $report_date); ?>
                            </div>
                            <table class="w-full text-left text-sm">
                                <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Dentist</th><th class="px-6 py-3 text-center">Patients</th><th class="px-6 py-3 text-right">Revenue</th></tr></thead>
                                <tbody>
                                    <?php foreach($reports_data['productivity'] as $row): ?>
                                    <tr>
                                        <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['dentist']); ?></td>
                                        <td class="px-6 py-3 text-center"><?php echo $row['patients_seen']; ?></td>
                                        <td class="px-6 py-3 text-right">₱<?php echo number_format($row['revenue_generated'], 2); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>


            <!-- 3. INSTALLMENT REPORTS -->
            <section id="installment" class="report-section">
                <div class="flex items-center justify-between mb-6 pt-8 border-t border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-amber-100 dark:bg-amber-900/30 rounded-lg"><span class="material-symbols-outlined text-amber-600">credit_score</span></div>
                        <h2 class="text-2xl font-bold">Installment Reports</h2>
                    </div>
                    <?php echo categoryExportBtn('installment', $start_date, $end_date, $report_date); ?>
                </div>

                <!-- 3.1 Active Accounts -->
                <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden mb-8">
                    <div class="p-5 border-b border-gray-100 dark:border-gray-800 bg-amber-50/50 dark:bg-amber-900/10 flex justify-between">
                        <h3 class="font-bold text-lg">3.1 Active Installment Accounts</h3>
                        <?php echo exportBtn('active_installments', $start_date, $end_date, $report_date); ?>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-sm whitespace-nowrap">
                            <thead class="bg-gray-50 dark:bg-gray-800">
                                <tr>
                                    <th class="px-6 py-3 font-bold text-text-sub">Patient</th>
                                    <th class="px-6 py-3 font-bold text-text-sub">Service</th>
                                    <th class="px-6 py-3 font-bold text-text-sub text-right">Total</th>
                                    <th class="px-6 py-3 font-bold text-text-sub text-right">Balance</th>
                                    <th class="px-6 py-3 font-bold text-text-sub text-center">Progress</th>
                                    <th class="px-6 py-3 font-bold text-text-sub">Next Due</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($reports_data['active_installments'] as $row): ?>
                                <tr>
                                    <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td class="px-6 py-3"><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td class="px-6 py-3 text-right">₱<?php echo number_format($row['total_amount']); ?></td>
                                    <td class="px-6 py-3 text-right font-bold text-red-500">₱<?php echo number_format($row['balance_remaining']); ?></td>
                                    <td class="px-6 py-3 text-center"><?php echo $row['months_paid'] . '/' . $row['months_total']; ?> mos</td>
                                    <td class="px-6 py-3 text-amber-600 font-bold"><?php echo date('M d, Y', strtotime($row['next_payment_date'])); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <!-- 3.2 Due / Overdue -->
                    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-red-100 dark:border-red-900/30 overflow-hidden">
                        <div class="p-5 border-b border-red-100 dark:border-red-900/30 bg-red-50 dark:bg-red-900/10 flex justify-between">
                            <h3 class="font-bold text-lg text-red-600">3.2 Due Now / Overdue (Collection)</h3>
                            <?php echo exportBtn('installments_due', $start_date, $end_date, $report_date); ?>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Patient</th><th class="px-6 py-3">Phone</th><th class="px-6 py-3 text-right">Amount</th><th class="px-6 py-3">Due Date</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($reports_data['installments_due'] as $row): ?>
                                <tr>
                                    <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td class="px-6 py-3 font-mono text-xs"><?php echo htmlspecialchars($row['phone_number']); ?></td>
                                    <td class="px-6 py-3 text-right">₱<?php echo number_format($row['monthly_amount']); ?></td>
                                    <td class="px-6 py-3 font-bold text-red-600"><?php echo date('M d', strtotime($row['next_payment_date'])); ?></td>
                                </tr>
                                <?php endforeach; if(empty($reports_data['installments_due'])): echo '<tr><td colspan="4" class="px-6 py-4 text-center text-green-600 font-bold">No overdue payments.</td></tr>'; endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 3.3 Completed -->
                    <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                         <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex justify-between">
                            <h3 class="font-bold text-lg">3.3 Completed Plans</h3>
                            <?php echo exportBtn('completed_installments', $start_date, $end_date, $report_date); ?>
                        </div>
                         <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Patient</th><th class="px-6 py-3">Total Paid</th><th class="px-6 py-3">Duration</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($reports_data['completed_installments'] as $row): ?>
                                <tr>
                                    <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td class="px-6 py-3 font-bold text-green-600">₱<?php echo number_format($row['total_amount']); ?></td>
                                    <td class="px-6 py-3"><?php echo $row['months_total']; ?> mos</td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>


             <!-- 4. MANAGEMENT REPORTS -->
            <section id="management" class="report-section">
                <div class="flex items-center justify-between mb-6 pt-8 border-t border-gray-100 dark:border-gray-800">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-teal-100 dark:bg-teal-900/30 rounded-lg"><span class="material-symbols-outlined text-teal-600">monitoring</span></div>
                        <h2 class="text-2xl font-bold">Management & Quality</h2>
                    </div>
                    <?php echo categoryExportBtn('management', $start_date, $end_date, $report_date); ?>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- 4.1 Top Services -->
                     <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                        <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex justify-between">
                            <h3 class="font-bold text-lg">4.1 Top Services by Revenue</h3>
                            <?php echo exportBtn('top_services', $start_date, $end_date, $report_date); ?>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Service</th><th class="px-6 py-3 text-right">Count</th><th class="px-6 py-3 text-right">Revenue</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($reports_data['top_services'] as $row): ?>
                                <tr>
                                    <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td class="px-6 py-3 text-right"><?php echo $row['times_rendered']; ?></td>
                                    <td class="px-6 py-3 text-right font-bold text-primary">₱<?php echo number_format($row['total_revenue']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- 4.2 Feedback -->
                     <div class="bg-surface-light dark:bg-surface-dark rounded-2xl shadow-sm border border-gray-100 dark:border-gray-800 overflow-hidden">
                        <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex justify-between">
                            <h3 class="font-bold text-lg">4.2 Patient Feedback Summary</h3>
                            <?php echo exportBtn('feedback', $start_date, $end_date, $report_date); ?>
                        </div>
                        <table class="w-full text-left text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-800"><tr><th class="px-6 py-3">Service</th><th class="px-6 py-3 text-center">Avg Rating</th><th class="px-6 py-3 text-right">Reviews</th></tr></thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                <?php foreach($reports_data['feedback'] as $row): ?>
                                <tr>
                                    <td class="px-6 py-3 font-bold"><?php echo htmlspecialchars($row['service_name']); ?></td>
                                    <td class="px-6 py-3 text-center flex items-center justify-center gap-1">
                                        <span class="font-bold"><?php echo number_format($row['average_rating'], 1); ?></span>
                                        <span class="material-symbols-outlined text-xs text-accent text-[16px]">star</span>
                                    </td>
                                    <td class="px-6 py-3 text-right"><?php echo $row['total_reviews']; ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

        </div>
    </main>
</div>

<!-- Export Modal -->
<dialog id="exportModal" class="p-0 rounded-2xl shadow-2xl backdrop:bg-black/50 open:animate-fade-in w-[400px]">
    <form method="GET" action="admin_detailed_print.php" target="_blank" class="bg-surface-light dark:bg-surface-dark text-text-main dark:text-white">
        <!-- Header -->
        <div class="p-5 border-b border-gray-100 dark:border-gray-800 flex justify-between items-center">
            <h3 class="font-bold text-lg flex items-center gap-2">
                <span class="material-symbols-outlined text-red-500">picture_as_pdf</span> 
                Export to PDF
            </h3>
            <button type="button" onclick="closeExportModal()" class="text-text-sub hover:text-text-main">
                <span class="material-symbols-outlined">close</span>
            </button>
        </div>
        
        <!-- Content -->
        <div class="p-6">
            <p class="text-xs text-text-sub mb-4">Select the reports you want to include in the PDF.</p>
            
            <input type="hidden" name="category" id="modalCategory">
            <input type="hidden" name="start_date" id="modalStart">
            <input type="hidden" name="end_date" id="modalEnd">
            <input type="hidden" name="report_date" id="modalDate">

            <div id="modalCheckboxes" class="space-y-3">
                <!-- Checkboxes injected by JS -->
            </div>
        </div>

        <!-- Footer -->
        <div class="p-5 border-t border-gray-100 dark:border-gray-800 bg-gray-50 dark:bg-gray-800/50 flex justify-end gap-3">
            <button type="button" onclick="closeExportModal()" class="px-4 py-2 rounded-lg text-sm font-bold text-text-sub hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">Cancel</button>
            <button type="submit" onclick="closeExportModal()" class="px-4 py-2 rounded-lg text-sm font-bold text-white bg-red-600 hover:bg-red-700 transition-colors shadow-lg shadow-red-500/30 flex items-center gap-2">
                Generate PDF <span class="material-symbols-outlined text-sm">arrow_forward</span>
            </button>
        </div>
    </form>
</dialog>

<script>
    // Config Mapping (Must match server config)
    const reportConfig = {
        'financial': {
            'financial_transactions': 'Financial Transactions',
            'daily_sales': 'Daily Sales',
            'payment_methods': 'Payment Methods',
            'accounts_receivable': 'Accounts Receivable',
            'discounts': 'Discounts & Exemptions'
        },
        'clinical': {
            'treatment_log': 'Treatment Log',
            'attendance': 'Attendance Summary',
            'productivity': 'Dentist Productivity'
        },
        'installment': {
            'active_installments': 'Active Accounts',
            'installments_due': 'Due / Overdue',
            'completed_installments': 'Completed Plans'
        },
        'management': {
            'top_services': 'Top Services',
            'feedback': 'Patient Feedback'
        }
    };

    function openExportModal(category, start, end, date) {
        const modal = document.getElementById('exportModal');
        const container = document.getElementById('modalCheckboxes');
        
        // Set hidden inputs
        document.getElementById('modalCategory').value = category;
        document.getElementById('modalStart').value = start;
        document.getElementById('modalEnd').value = end;
        document.getElementById('modalDate').value = date;

        // Generate Checkboxes
        container.innerHTML = '';
        if (reportConfig[category]) {
            Object.entries(reportConfig[category]).forEach(([key, label]) => {
                const div = document.createElement('div');
                div.className = 'flex items-center gap-3 p-3 rounded-lg border border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer transition-colors';
                div.innerHTML = `
                    <input type="checkbox" name="reports[]" value="${key}" id="chk_${key}" checked 
                           class="w-5 h-5 text-red-600 rounded focus:ring-red-500 border-gray-300 dark:border-gray-600 dark:bg-gray-700 cursor-pointer">
                    <label for="chk_${key}" class="flex-1 text-sm font-bold cursor-pointer select-none">${label}</label>
                `;
                // Allow clicking row to toggle
                div.onclick = (e) => {
                    if(e.target.tagName !== 'INPUT') {
                        const chk = div.querySelector('input');
                        chk.checked = !chk.checked;
                    }
                };
                container.appendChild(div);
            });
        }

        modal.showModal();
    }

    function closeExportModal() {
        document.getElementById('exportModal').close();
    }
</script>

</body>
</html>
