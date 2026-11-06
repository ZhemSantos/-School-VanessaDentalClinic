<?php
// admin/admin_detailed_print.php
session_start();
require_once '../db.php';
require_once '../includes/security.php';
require_once 'report_logic.php';

configureSecureSession();
requireRole('Admin');

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date   = $_GET['end_date'] ?? date('Y-m-t');
$report_date = $_GET['report_date'] ?? date('Y-m-d');
$category   = $_GET['category'] ?? '';

if (empty($category)) die("No category specified.");

// Map & Titles
$category_config = [
    'financial' => [
        'title' => 'Financial Reports',
        'keys' => [
            'financial_transactions' => '1.1 Financial Transactions', 
            'daily_sales' => '1.2 Daily Sales', 
            'payment_methods' => '1.3 Payment Methods', 
            'accounts_receivable' => '1.4 Accounts Receivable',
            'discounts' => '1.5 Discounts & Exemptions'
        ]
    ],
    'clinical' => [
        'title' => 'Clinical & Operational Reports',
        'keys' => [
            'treatment_log' => '2.1 Daily Treatment Log', 
            'attendance' => '2.2 Attendance Summary', 
            'productivity' => '2.3 Dentist Productivity'
        ]
    ],
    'installment' => [
        'title' => 'Installment Plan Reports',
        'keys' => [
            'active_installments' => '3.1 Active Accounts', 
            'installments_due' => '3.2 Collection Due / Overdue', 
            'completed_installments' => '3.3 Completed Plans'
        ]
    ],
    'management' => [
        'title' => 'Management Reports',
        'keys' => [
            'top_services' => '4.1 Top Services', 
            'feedback' => '4.2 Patient Feedback'
        ]
    ]
];

if (!isset($category_config[$category])) die("Invalid category.");

$config = $category_config[$category];

// Filter by selected reports if specified
$selected_reports = $_GET['reports'] ?? [];
if (!empty($selected_reports) && is_array($selected_reports)) {
    $config['keys'] = array_filter($config['keys'], function($k) use ($selected_reports) {
        return in_array($k, $selected_reports);
    }, ARRAY_FILTER_USE_KEY);
}

$data = getReportsData($pdo, $start_date, $end_date, $report_date);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo $config['title']; ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { font-family: sans-serif; font-size: 12px; color: #333; margin: 20px; }
        #report-content { max-width: 100%; }
        h1 { text-align: center; margin-bottom: 5px; }
        .meta { text-align: center; margin-bottom: 20px; font-size: 10px; color: #666; }
        h2 { margin-top: 30px; border-bottom: 2px solid #ddd; padding-bottom: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 6px; text-align: left; }
        th { background-color: #f5f5f5; font-weight: bold; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        
        .controls {
            position: sticky;
            top: 0;
            background: white;
            padding: 10px;
            border-bottom: 1px solid #ddd;
            text-align: right;
            margin-bottom: 20px;
            z-index: 1000;
        }
        .btn {
            padding: 8px 16px;
            cursor: pointer;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-weight: bold;
            margin-left: 10px;
        }
        .btn-print { background: #f0f0f0; color: #333; }
        .btn-dl { background: #8a11d4; color: white; border-color: #7c0fc0; }
        
        @media print {
            .controls { display: none; }
            body { margin: 0; }
            h2 { page-break-after: avoid; }
            table { page-break-inside: auto; }
            tr { page-break-inside: avoid; page-break-after: auto; }
        }
    </style>
</head>
<body>
    <div class="controls">
        <button onclick="window.print()" class="btn btn-print">🖨️ Print</button>
        <button onclick="downloadPDF()" class="btn btn-dl">⬇️ Download PDF</button>
    </div>

    <div id="report-content">
        <h1><?php echo $config['title']; ?></h1>
        <div class="meta">
            Start: <?php echo $start_date; ?> | End: <?php echo $end_date; ?> | Single Day Ref: <?php echo $report_date; ?> | Generated: <?php echo date('Y-m-d H:i'); ?>
        </div>

        <?php foreach ($config['keys'] as $key => $label): 
            $rows = $data[$key] ?? [];
            if (empty($rows)) {
                echo "<h2>$label</h2><p>No data available.</p>";
                continue;
            }
            $headers = array_keys($rows[0]);
        ?>
        
        <h2><?php echo $label; ?></h2>
        <table>
            <thead>
                <tr>
                    <?php foreach($headers as $h): ?>
                        <th><?php echo ucwords(str_replace('_', ' ', $h)); ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach($rows as $row): ?>
                <tr>
                    <?php foreach($row as $cell): ?>
                        <td><?php echo htmlspecialchars($cell); ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php endforeach; ?>
    </div>

    <script>
        function downloadPDF() {
            const element = document.getElementById('report-content');
            const opt = {
                margin:       [0.5, 0.5, 0.5, 0.5], // Top, Left, Bottom, Right (inches)
                filename:     '<?php echo $category; ?>_report_<?php echo date('Ymd'); ?>.pdf',
                image:        { type: 'jpeg', quality: 0.98 },
                html2canvas:  { scale: 2 },
                jsPDF:        { unit: 'in', format: 'letter', orientation: 'portrait' }
            };
            
            // Show loading state
            const btn = document.querySelector('.btn-dl');
            const originalText = btn.innerText;
            btn.innerText = 'Generating...';
            btn.disabled = true;

            html2pdf().set(opt).from(element).save().then(() => {
                btn.innerText = originalText;
                btn.disabled = false;
            });
        }
    </script>
</body>
</html>
