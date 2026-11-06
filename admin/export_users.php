<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require admin role to access this page
requireRole('Admin');

// Get export format
$format = $_GET['format'] ?? 'excel';
$format = in_array($format, ['pdf', 'excel', 'word']) ? $format : 'excel';

// Get filter parameters (same as admin_user.php)
$role_filter = $_GET['role'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';
$search_query = $_GET['search'] ?? '';

// Build query (same logic as admin_user.php)
$where_conditions = [];
$params = [];

if ($role_filter !== 'all') {
    $where_conditions[] = "role = ?";
    $params[] = $role_filter;
}

if ($status_filter !== 'all') {
    $where_conditions[] = "status = ?";
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = "(full_name LIKE ? OR username LIKE ? OR email LIKE ? OR user_id LIKE ?)";
    $search_param = '%' . $search_query . '%';
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_query;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get all users (no pagination for export)
$query = "SELECT user_id, username, full_name, role, email, contact_number, status, created_at, last_active FROM tbl_user $where_clause ORDER BY user_id DESC";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

// Helper function to format last active
function formatLastActive($timestamp) {
    if (empty($timestamp)) {
        return 'Never';
    }
    $time_ago = strtotime($timestamp);
    $current_time = time();
    $diff = $current_time - $time_ago;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $minutes = round($diff / 60);
        return $minutes . ' min' . ($minutes > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = round($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 2592000) {
        $days = round($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    } else {
        return date('M j, Y', $time_ago);
    }
}

// Helper function to determine status
function getDisplayStatus($status, $last_active) {
    if ($status !== 'Active') {
        return 'Inactive';
    }
    
    if (empty($last_active)) {
        return 'Away';
    }
    
    $time_ago = strtotime($last_active);
    $current_time = time();
    $diff = $current_time - $time_ago;
    
    if ($diff <= 300) { // 5 minutes
        return 'Online';
    } else {
        return 'Away';
    }
}

// Generate filename
$filename = 'users_export_' . date('Y-m-d_His') . '.' . ($format === 'excel' ? 'xls' : ($format === 'word' ? 'doc' : 'pdf'));

if ($format === 'excel') {
    // Excel format (CSV/Excel XML)
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // Excel XML header
    echo '<?xml version="1.0"?>' . "\n";
    echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
    echo ' xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
    echo ' xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
    echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
    echo ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    echo '<Worksheet ss:Name="Users">' . "\n";
    echo '<Table>' . "\n";
    
    // Header row
    echo '<Row>' . "\n";
    echo '<Cell><Data ss:Type="String">Employee ID</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Full Name</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Username</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Role</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Email</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Contact Number</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Status</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Last Active</Data></Cell>' . "\n";
    echo '<Cell><Data ss:Type="String">Created At</Data></Cell>' . "\n";
    echo '</Row>' . "\n";
    
    // Data rows
    foreach ($users as $user) {
        $emp_id = 'EMP-' . str_pad($user['user_id'], 3, '0', STR_PAD_LEFT);
        $display_status = getDisplayStatus($user['status'], $user['last_active']);
        
        echo '<Row>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($emp_id) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($user['full_name']) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($user['username']) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($user['role']) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($user['email'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($user['contact_number'] ?? 'N/A') . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($display_status) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars(formatLastActive($user['last_active'])) . '</Data></Cell>' . "\n";
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars(date('M d, Y', strtotime($user['created_at']))) . '</Data></Cell>' . "\n";
        echo '</Row>' . "\n";
    }
    
    echo '</Table>' . "\n";
    echo '</Worksheet>' . "\n";
    echo '</Workbook>' . "\n";
    
} elseif ($format === 'word') {
    // Word format (RTF)
    header('Content-Type: application/msword');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    // RTF header
    echo "{\\rtf1\\ansi\\deff0\n";
    echo "{\\fonttbl{\\f0 Times New Roman;}}\n";
    echo "{\\colortbl;\\red0\\green0\\blue0;}\n";
    
    // Title
    echo "\\fs32\\b User List - Vanessa Nicolas Dental Clinic\\b0\\fs24\\par\\par\n";
    echo "Generated: " . date('F j, Y g:i A') . "\\par\\par\n";
    
    // Table header
    echo "\\trowd\\trgaph108\\trleft-108\n";
    echo "\\cellx1000\\cellx2000\\cellx3000\\cellx4000\\cellx5000\\cellx6000\\cellx7000\\cellx8000\\cellx9000\n";
    echo "\\intbl\\b Employee ID\\b0\\cell\n";
    echo "\\intbl\\b Full Name\\b0\\cell\n";
    echo "\\intbl\\b Username\\b0\\cell\n";
    echo "\\intbl\\b Role\\b0\\cell\n";
    echo "\\intbl\\b Email\\b0\\cell\n";
    echo "\\intbl\\b Contact\\b0\\cell\n";
    echo "\\intbl\\b Status\\b0\\cell\n";
    echo "\\intbl\\b Last Active\\b0\\cell\n";
    echo "\\intbl\\b Created\\b0\\cell\n";
    echo "\\row\n";
    
    // Data rows
    foreach ($users as $user) {
        $emp_id = 'EMP-' . str_pad($user['user_id'], 3, '0', STR_PAD_LEFT);
        $display_status = getDisplayStatus($user['status'], $user['last_active']);
        
        echo "\\trowd\\trgaph108\\trleft-108\n";
        echo "\\cellx1000\\cellx2000\\cellx3000\\cellx4000\\cellx5000\\cellx6000\\cellx7000\\cellx8000\\cellx9000\n";
        echo "\\intbl " . htmlspecialchars($emp_id) . "\\cell\n";
        echo "\\intbl " . htmlspecialchars($user['full_name']) . "\\cell\n";
        echo "\\intbl " . htmlspecialchars($user['username']) . "\\cell\n";
        echo "\\intbl " . htmlspecialchars($user['role']) . "\\cell\n";
        echo "\\intbl " . htmlspecialchars($user['email'] ?? 'N/A') . "\\cell\n";
        echo "\\intbl " . htmlspecialchars($user['contact_number'] ?? 'N/A') . "\\cell\n";
        echo "\\intbl " . htmlspecialchars($display_status) . "\\cell\n";
        echo "\\intbl " . htmlspecialchars(formatLastActive($user['last_active'])) . "\\cell\n";
        echo "\\intbl " . htmlspecialchars(date('M d, Y', strtotime($user['created_at']))) . "\\cell\n";
        echo "\\row\n";
    }
    
    echo "}\n";
    
} else {
    // PDF format (HTML to PDF using browser print)
    header('Content-Type: text/html; charset=utf-8');
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>User List - Vanessa Nicolas Dental Clinic</title>
        <style>
            @media print {
                @page {
                    margin: 1cm;
                    size: A4 landscape;
                }
                body {
                    margin: 0;
                    padding: 20px;
                    font-family: Arial, sans-serif;
                    font-size: 10px;
                }
            }
            body {
                font-family: Arial, sans-serif;
                font-size: 12px;
                margin: 20px;
            }
            h1 {
                color: #8a11d4;
                margin-bottom: 5px;
            }
            .meta {
                color: #666;
                margin-bottom: 20px;
                font-size: 10px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            th {
                background-color: #8a11d4;
                color: white;
                padding: 8px;
                text-align: left;
                font-size: 10px;
                border: 1px solid #ddd;
            }
            td {
                padding: 6px;
                border: 1px solid #ddd;
                font-size: 9px;
            }
            tr:nth-child(even) {
                background-color: #f9f9f9;
            }
            .footer {
                margin-top: 20px;
                text-align: center;
                font-size: 9px;
                color: #666;
            }
        </style>
        <style>
            .download-pdf-btn {
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 12px 24px;
                background: #8a11d4;
                color: white;
                border: none;
                border-radius: 8px;
                cursor: pointer;
                font-weight: bold;
                z-index: 10000;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            .download-pdf-btn:hover {
                background: #6a0da8;
            }
            @media print {
                .download-pdf-btn {
                    display: none;
                }
            }
        </style>
        <script>
            window.onload = function() {
                // Add download button
                const downloadBtn = document.createElement('button');
                downloadBtn.textContent = 'Download as PDF';
                downloadBtn.className = 'download-pdf-btn';
                downloadBtn.onclick = function() {
                    window.print();
                };
                document.body.appendChild(downloadBtn);
            };
        </script>
    </head>
    <body>
        <h1>User List - Vanessa Nicolas Dental Clinic</h1>
        <div class="meta">
            Generated: <?php echo date('F j, Y g:i A'); ?><br>
            Total Users: <?php echo count($users); ?>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Employee ID</th>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Role</th>
                    <th>Email</th>
                    <th>Contact Number</th>
                    <th>Status</th>
                    <th>Last Active</th>
                    <th>Created At</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): 
                    $emp_id = 'EMP-' . str_pad($user['user_id'], 3, '0', STR_PAD_LEFT);
                    $display_status = getDisplayStatus($user['status'], $user['last_active']);
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($emp_id); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['role']); ?></td>
                    <td><?php echo htmlspecialchars($user['email'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($user['contact_number'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars($display_status); ?></td>
                    <td><?php echo htmlspecialchars(formatLastActive($user['last_active'])); ?></td>
                    <td><?php echo htmlspecialchars(date('M d, Y', strtotime($user['created_at']))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <div class="footer">
            Vanessa Nicolas Dental Clinic - User Management System
        </div>
    </body>
    </html>
    <?php
    exit;
}
?>

