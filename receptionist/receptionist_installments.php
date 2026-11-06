<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role
requireRole('Receptionist');

// Update activity
updateUserActivity();

// Get Current User Info (Template Standard)
$current_user_details = null;
if (isset($_SESSION['user_id'])) {
    try {
        $user_details_stmt = $pdo->prepare("SELECT user_id, username, full_name, role, email, contact_number, status, profile_picture FROM tbl_user WHERE user_id = ? LIMIT 1");
        $user_details_stmt->execute([$_SESSION['user_id']]);
        $current_user_details = $user_details_stmt->fetch();
    } catch (PDOException $e) {
        error_log('Error fetching user details: ' . $e->getMessage());
    }
}
$current_user = $current_user_details ?? $_SESSION['user'] ?? null;
$current_name = $current_user['full_name'] ?? 'Receptionist';
$current_role = $current_user['role'] ?? 'Receptionist';
$current_profile = $current_user['profile_picture'] ?? null;

// Helper for Profile Photo
function getDefaultProfilePhoto($full_name) {
    $names = explode(' ', trim($full_name));
    $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1] ?? $names[0], 0, 1));
    $colors = [['bg' => '#FFD700', 'text' => '#6A1B9A'], ['bg' => '#6A1B9A', 'text' => '#ffffff'], ['bg' => '#8a11d4', 'text' => '#ffffff']];
    $color = $colors[abs(crc32($full_name)) % count($colors)];
    return ['initials' => $initials, 'bg_color' => $color['bg'], 'text_color' => $color['text']];
}
$default_photo = getDefaultProfilePhoto($current_name);
$current_page = 'receptionist_payment.php'; // Keep active tab on sidebar

// --- Installment Logic ---

// Penalty Multipliers
$category_multipliers = [
    'General Dentistry' => 1.0,
    'Pediatric Dentistry' => 1.0,
    'Endodontics' => 1.2,
    'Prosthodontics' => 1.2,
    'Orthodontics' => 1.3,
    'Implantology' => 1.5,
    'Oral Surgery' => 1.5
];

// Handle Payment Processing
$action_message = '';
$action_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_installment_payment'])) {
    $installment_id = intval($_POST['installment_id']);
    $payment_amount = floatval($_POST['payment_amount']);
    $payment_method = sanitizeInput($_POST['payment_method']);
    
    if ($installment_id > 0 && $payment_amount > 0) {
        try {
            $pdo->beginTransaction();
            
            // 1. Fetch Installment Details
            $stmt = $pdo->prepare("SELECT * FROM tbl_installment WHERE installment_id = ? FOR UPDATE");
            $stmt->execute([$installment_id]);
            $inst = $stmt->fetch();
            
            if ($inst) {
                // Check for overpayment (Payment > Balance + Unpaid Penalties)
                $penalty_due = max(0, ($inst['penalty_accumulated'] ?? 0) - ($inst['penalty_paid'] ?? 0));
                $total_outstanding = $inst['balance_remaining'] + $penalty_due;
                
                // Allow a tiny margin for float comparison error
                if ($payment_amount > ($total_outstanding + 0.01)) {
                    $pdo->rollBack();
                    $action_message = 'Error: Payment amount (₱' . number_format($payment_amount, 2) . ') exceeds the total outstanding balance (₱' . number_format($total_outstanding, 2) . ').';
                    $action_type = 'error';
                } else {
                    // Create Invoice record for this payment (Linked to Installment)
                    // FIX: Use PHP date() for timezone and include amount_paid
                    $ref = 'INS-' . date('YmdHis') . '-' . $installment_id;
                    $inv_stmt = $pdo->prepare("INSERT INTO tbl_invoice (reference_number, total_amount, amount_paid, payment_method, payment_status, paid_at, installment_id) VALUES (?, ?, ?, ?, 'Paid', ?, ?)");
                    $inv_stmt->execute([$ref, $payment_amount, $payment_amount, $payment_method, date('Y-m-d H:i:s'), $installment_id]);
                    
                    // Update Installment Record with penalty allocation
                    $penalty_paid_now = 0;
                    $principal_paid = 0;
                    $remaining_payment = $payment_amount;
                    
                    // Pay off existing penalties first
                    if ($penalty_due > 0) {
                        if ($remaining_payment >= $penalty_due) {
                            $penalty_paid_now = $penalty_due; // Full penalty covered
                            $remaining_payment -= $penalty_due;
                        } else {
                            $penalty_paid_now = $remaining_payment; // Partial penalty
                            $remaining_payment = 0;
                        }
                    }
                    
                    // Remaining goes to principal/balance
                    if ($remaining_payment > 0) {
                        $principal_paid = $remaining_payment;
                    }
                    
                    // Calculate new values
                    $new_balance = max(0, $inst['balance_remaining'] - $principal_paid);
                    $new_months_paid = $inst['months_paid'] + 1;
                    $new_penalty_paid = ($inst['penalty_paid'] ?? 0) + $penalty_paid_now;
                    
                    // Calculate next payment date (add 1 month)
                    $current_next_date = new DateTime($inst['next_payment_date']);
                    $current_next_date->modify('+1 month');
                    $new_next_payment_date = $current_next_date->format('Y-m-d');
                    
                    // Check if completed (balance paid AND all penalties paid)
                    $new_status = ($new_balance <= 0.01 && $new_penalty_paid >= ($inst['penalty_accumulated'] ?? 0)) ? 'Completed' : 'Active';
                    
                    $update_stmt = $pdo->prepare("UPDATE tbl_installment SET 
                        balance_remaining = ?, 
                        months_paid = ?,
                        penalty_paid = ?,
                        next_payment_date = ?,
                        status = ? 
                        WHERE installment_id = ?");
                    $update_stmt->execute([$new_balance, $new_months_paid, $new_penalty_paid, $new_next_payment_date, $new_status, $installment_id]);
                    
                    $pdo->commit();
                    
                    // Build detailed success message
                    $msg_parts = [];
                    if ($penalty_paid_now > 0) {
                        $msg_parts[] = 'Penalty: ₱' . number_format($penalty_paid_now, 2);
                    }
                    if ($principal_paid > 0) {
                        $msg_parts[] = 'Principal: ₱' . number_format($principal_paid, 2);
                    }
                    $breakdown = !empty($msg_parts) ? ' (' . implode(', ', $msg_parts) . ')' : '';
                    
                    $action_message = 'Payment of ₱' . number_format($payment_amount, 2) . ' processed successfully!' . $breakdown;
                    $action_type = 'success';
                }
            } else {
                $pdo->rollBack();
                $action_message = 'Installment plan not found.';
                $action_type = 'error';
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $action_message = 'Error processing payment: ' . $e->getMessage();
            $action_type = 'error';
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $inst_id = intval($_POST['installment_id'] ?? 0);
    $new_status = sanitizeInput($_POST['new_status'] ?? '');
    
    $allowed_statuses = ['Active', 'Pending', 'Completed', 'Defaulted', 'Cancelled'];
    
    if ($inst_id > 0 && in_array($new_status, $allowed_statuses)) {
        try {
            $stmt = $pdo->prepare("UPDATE tbl_installment SET status = ? WHERE installment_id = ?");
            $stmt->execute([$new_status, $inst_id]);
            $action_message = 'Installment status updated successfully!';
            $action_type = 'success';
        } catch (PDOException $e) {
            error_log('Error updating installment status: ' . $e->getMessage());
            $action_message = 'Failed to update status.';
            $action_type = 'error';
        }
    } else {
        $action_message = 'Invalid input provided.';
        $action_type = 'error';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_installment'])) {
    $inst_id = intval($_POST['installment_id'] ?? 0);
    
    if ($inst_id > 0) {
        try {
            // Check if any payments have been made? Maybe allow delete anyway for Receptionist.
            $stmt = $pdo->prepare("DELETE FROM tbl_installment WHERE installment_id = ?");
            $stmt->execute([$inst_id]);
            $action_message = 'Installment plan deleted successfully!';
            $action_type = 'success';
        } catch (PDOException $e) {
            error_log('Error deleting installment: ' . $e->getMessage());
            $action_message = 'Failed to delete installment. Likely linked to existing invoices.';
            $action_type = 'error';
        }
    }
}

// Calculate Penalties (Lazy / Helper)
// Note: This needs to run before stats calculations to be accurate on overdue checks
// However, since we are doing stats via SQL, we must rely on date comparisons there.
// Simple Overdue Check: Active AND next_payment_date < CURDATE()

// Fetch Global Statistics (Independent of Filters)
try {
    // Active Plans
    $stats_active = $pdo->query("SELECT COUNT(*) FROM tbl_installment WHERE status = 'Active'")->fetchColumn();
    
    // Overdue Plans (Active and Past Due)
    $stats_overdue = $pdo->query("SELECT COUNT(*) FROM tbl_installment WHERE status = 'Active' AND next_payment_date < CURDATE()")->fetchColumn();
    
    // New Installments (Last 30 Days)
    $stats_new = $pdo->query("SELECT COUNT(*) FROM tbl_installment WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();
    
} catch (PDOException $e) {
    error_log("Error fetching stats: " . $e->getMessage());
    $stats_active = 0;
    $stats_overdue = 0;
    $stats_new = 0;
}

// Fetch Installment Plans
$search = $_GET['search'] ?? '';
$filter_status = $_GET['status'] ?? 'Active';

$query = "
    SELECT i.*, 
           p.first_name, p.last_name, p.patient_id, p.phone_number,
           s.service_name, s.category
    FROM tbl_installment i
    JOIN tbl_patient p ON i.patient_id = p.patient_id
    JOIN tbl_service s ON i.service_id = s.service_id
    WHERE 1=1
";
$params = [];

if ($filter_status !== 'All') {
    $query .= " AND i.status = ?";
    $params[] = $filter_status;
}

if ($search) {
    $query .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR i.installment_id = ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = is_numeric($search) ? $search : -1;
}

$query .= " ORDER BY i.next_payment_date ASC";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$installments = $stmt->fetchAll();

// Calculate Penalties Dynamically (Display Only - or update DB if cron not running)
// Ideally this should be a background job, but we can do lazy update on view.
foreach ($installments as &$inst) {
    // Check if overdue
    if ($inst['status'] == 'Active' && strtotime($inst['next_payment_date']) < time()) {
        // Calculate days overdue
        $due_date = new DateTime($inst['next_payment_date']);
        $now = new DateTime();
        $diff = $now->diff($due_date)->days;
        $grace_period = $inst['grace_period_days'] ?? 5;
        
        if ($diff > $grace_period) {
            // Calculate Penalty: Outstanding * 3% * Multiplier
            // Multiplier
            $cat = $inst['category'] ?? 'General Dentistry';
            $mult = $category_multipliers[$cat] ?? 1.0;
            
            // Check if penalty already applied for this month? 
            // Simplified: Just show estimated current penalty if not paid
            $inst['is_overdue'] = true;
            $inst['current_penalty_est'] = $inst['balance_remaining'] * 0.03 * $mult;
        }
    }
}
unset($inst);

$current_date_time = date('F j, Y g:i A');
?>
<!DOCTYPE html>
<html lang="en" class="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installment Plans - Vanessa Nicolas Dental</title>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" />
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        "royal-purple": "#6A1B9A",
                        "clinic-gold": "#FFD700",
                        "background-light": "#f7f6f8"
                    },
                    fontFamily: { "display": ["Manrope", "sans-serif"] }
                }
            }
        }
    </script>
</head>
<body class="bg-background-light font-display text-[#151118] h-screen overflow-hidden flex">

    <!-- Sidebar -->
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

    <!-- Main Content -->
    <main class="flex-1 flex flex-col h-full relative">
        <!-- Header -->
        <header class="w-full px-8 py-6 flex items-start justify-between bg-white/50 backdrop-blur-md sticky top-0 z-10 border-b border-gray-100">
            <div class="flex flex-col gap-1">
                <div class="flex items-center gap-4">
                    <h2 class="text-3xl font-black text-[#151118] tracking-tight">Installment Plans</h2>
                     <!-- Tabs -->
                    <div class="flex bg-gray-100 p-1 rounded-xl ml-4">
                        <a href="receptionist_payment.php" class="px-4 py-1.5 rounded-lg text-slate-600 font-bold text-xs hover:bg-white hover:shadow-sm transition-all">Transactions</a>
                        <a href="#" class="px-4 py-1.5 rounded-lg bg-white text-royal-purple shadow-sm font-bold text-xs ring-1 ring-black/5">Installments</a>
                    </div>
                </div>
                <p class="text-slate-500 font-medium flex items-center gap-2">
                    <span class="material-symbols-outlined text-lg">schedule</span>
                    <?php echo $current_date_time; ?>
                </p>
            </div>
            <div class="flex items-center gap-4">
                <button class="btn-notifications h-12 w-12 flex items-center justify-center bg-white border border-gray-200 rounded-xl hover:bg-gray-50 text-slate-600 relative">
                    <span class="absolute top-3 right-3 w-2 h-2 bg-red-500 rounded-full"></span>
                    <span class="material-symbols-outlined">notifications</span>
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-y-auto p-8">
            <!-- Stats Row -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
                <!-- Active Plans Card -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                    <div>
                        <p class="text-slate-500 text-sm font-bold">Active Plans</p>
                        <h3 class="text-3xl font-black text-royal-purple"><?php echo $stats_active; ?></h3>
                    </div>
                    <div class="h-12 w-12 rounded-full bg-purple-50 flex items-center justify-center text-royal-purple">
                        <span class="material-symbols-outlined">credit_score</span>
                    </div>
                </div>
                 <!-- Overdue Card -->
                 <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                    <div>
                        <p class="text-slate-500 text-sm font-bold">Overdue / Warning</p>
                        <h3 class="text-3xl font-black text-orange-500">
                            <?php echo $stats_overdue; ?>
                        </h3>
                    </div>
                    <div class="h-12 w-12 rounded-full bg-orange-50 flex items-center justify-center text-orange-500">
                        <span class="material-symbols-outlined">warning</span>
                    </div>
                </div>
                <!-- New Installments Card -->
                <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-100 flex items-center justify-between">
                    <div>
                        <p class="text-slate-500 text-sm font-bold">New Installments</p>
                        <h3 class="text-3xl font-black text-blue-600">
                             <?php echo $stats_new; ?>
                        </h3>
                         <p class="text-xs text-slate-400 mt-1">Last 30 days</p>
                    </div>
                    <div class="h-12 w-12 rounded-full bg-blue-50 flex items-center justify-center text-blue-600">
                        <span class="material-symbols-outlined">new_releases</span>
                    </div>
                </div>
            </div>

            <?php if ($action_message): ?>
            <div class="bg-green-50 text-green-800 p-4 rounded-xl mb-6 flex items-center gap-2 border border-green-200">
                <span class="material-symbols-outlined">check_circle</span>
                <?php echo htmlspecialchars($action_message); ?>
            </div>
            <?php endif; ?>

            <!-- Filter Bar -->
            <div class="flex gap-4 mb-6">
                <form class="flex-1 flex gap-3">
                    <div class="relative flex-1">
                        <span class="material-symbols-outlined absolute left-3 top-3 text-slate-400">search</span>
                        <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search patient..." class="w-full pl-10 px-4 py-3 rounded-xl border border-gray-200 focus:outline-none focus:border-royal-purple">
                    </div>
                    <select name="status" class="px-4 py-3 rounded-xl border border-gray-200 focus:outline-none" onchange="this.form.submit()">
                        <option value="Active" <?php echo $filter_status == 'Active' ? 'selected' : ''; ?>>Active</option>
                        <option value="Pending" <?php echo $filter_status == 'Pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="Completed" <?php echo $filter_status == 'Completed' ? 'selected' : ''; ?>>Completed</option>
                        <option value="Defaulted" <?php echo $filter_status == 'Defaulted' ? 'selected' : ''; ?>>Defaulted</option>
                        <option value="Cancelled" <?php echo $filter_status == 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                        <option value="All" <?php echo $filter_status == 'All' ? 'selected' : ''; ?>>All</option>
                    </select>
                </form>
            </div>

            <!-- Table -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <table class="w-full text-left">
                    <thead class="bg-gray-50 border-b border-gray-100 text-xs font-bold text-slate-500 uppercase">
                        <tr>
                            <th class="px-6 py-4">ID</th>
                            <th class="px-6 py-4">Patient</th>
                            <th class="px-6 py-4">Service</th>
                            <th class="px-6 py-4">Balance / Total</th>
                            <th class="px-6 py-4">Monthly</th>
                            <th class="px-6 py-4">Next Due</th>
                            <th class="px-6 py-4 text-center">Status</th>
                            <th class="px-6 py-4 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-sm">
                        <?php foreach($installments as $row): 
                            $progress = ($row['total_amount'] - $row['balance_remaining']) / $row['total_amount'] * 100;
                            $is_overdue = isset($row['is_overdue']);
                        ?>
                        <tr class="hover:bg-purple-50/30 transition-colors">
                            <td class="px-6 py-4 font-mono text-slate-400">#<?php echo $row['installment_id']; ?></td>
                            <td class="px-6 py-4 font-bold text-royal-purple">
                                <?php echo htmlspecialchars($row['first_name'] . ' ' . $row['last_name']); ?>
                                <div class="text-xs font-normal text-slate-400"><?php echo $row['phone_number']; ?></div>
                            </td>
                            <td class="px-6 py-4">
                                <?php echo htmlspecialchars($row['service_name']); ?>
                                <div class="text-[10px] bg-gray-100 text-slate-500 px-2 py-0.5 rounded-full inline-block mt-1">
                                    <?php echo htmlspecialchars($row['category']); ?>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="font-bold">₱<?php echo number_format($row['balance_remaining'], 2); ?></div>
                                <div class="text-xs text-slate-400">of ₱<?php echo number_format($row['total_amount'], 2); ?></div>
                                <!-- Progress Bar -->
                                <div class="w-24 h-1.5 bg-gray-100 rounded-full mt-1 overflow-hidden">
                                    <div class="h-full bg-clinic-gold rounded-full" style="width: <?php echo $progress; ?>%"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 font-medium">₱<?php echo number_format($row['monthly_amount'], 2); ?></td>
                            <td class="px-6 py-4">
                                <?php if ($row['status'] == 'Active'): ?>
                                    <div class="<?php echo $is_overdue ? 'text-red-500 font-bold' : 'text-slate-600'; ?>">
                                        <?php echo date('M d, Y', strtotime($row['next_payment_date'])); ?>
                                    </div>
                                    <?php if ($is_overdue): ?>
                                        <div class="text-xs text-red-400 mt-1">
                                            +₱<?php echo number_format($row['current_penalty_est'], 2); ?> Penalty
                                        </div>
                                    <?php endif; ?>
                                <?php elseif ($row['status'] == 'Pending'): ?>
                                    <span class="text-slate-400 text-xs italic">Awaiting Downpayment</span>
                                <?php else: ?>
                                    <span class="text-green-500">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <?php 
                                    $status_colors = [
                                        'Active' => 'bg-blue-50 text-blue-600',
                                        'Completed' => 'bg-green-50 text-green-600',
                                        'Pending' => 'bg-yellow-50 text-yellow-600',
                                        'Defaulted' => 'bg-red-50 text-red-600',
                                        'Cancelled' => 'bg-gray-50 text-gray-500'
                                    ];
                                    $badge_color = $status_colors[$row['status']] ?? 'bg-gray-50 text-gray-600';
                                ?>
                                <span class="px-3 py-1 rounded-full text-xs font-bold <?php echo $badge_color; ?>">
                                    <?php echo $row['status']; ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <?php if ($row['status'] == 'Active'): ?>
                                    <button onclick='openPayModal(<?php echo json_encode($row); ?>)' 
                                        class="bg-royal-purple hover:bg-purple-800 text-white px-3 py-1.5 rounded-lg text-xs font-bold shadow-md transition-all">
                                        Pay
                                    </button>
                                    <?php endif; ?>
                                    
                                    <button onclick="openEditStatusModal(<?php echo $row['installment_id']; ?>, '<?php echo $row['status']; ?>')" 
                                        class="p-1.5 text-slate-400 hover:text-royal-purple hover:bg-purple-50 rounded-full transition-colors" title="Edit Status">
                                        <span class="material-symbols-outlined text-[20px]">edit</span>
                                    </button>
                                    
                                    <button onclick="confirmDelete(<?php echo $row['installment_id']; ?>)" 
                                        class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-full transition-colors" title="Delete">
                                        <span class="material-symbols-outlined text-[20px]">delete</span>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
    
    <!-- Payment Modal -->
    <div id="paymentModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <form method="POST" class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 animate-fade-in-up">
            <h3 class="text-xl font-bold text-royal-purple mb-4">Process Payment</h3>
            <input type="hidden" name="installment_id" id="modal_inst_id">
            <input type="hidden" name="process_installment_payment" value="1">
            
            <div class="bg-purple-50 p-4 rounded-xl mb-4 border border-purple-100">
                <p class="text-xs text-slate-500 uppercase font-bold mb-1">Monthly Due</p>
                <div class="text-2xl font-black text-royal-purple" id="modal_monthly_disp">₱0.00</div>
                <div id="modal_penalty_alert" class="hidden mt-2 text-xs text-red-500 font-bold border-t border-red-100 pt-1">
                    Includes estimated penalty
                </div>
            </div>
            
            <div class="mb-4">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Payment Amount</label>
                <div class="relative">
                    <span class="absolute left-3 top-3 text-slate-400 font-bold">₱</span>
                    <input type="number" step="0.01" name="payment_amount" id="modal_amount_input" class="w-full pl-8 pr-4 py-3 rounded-xl border border-gray-200 focus:border-royal-purple font-bold" required>
                </div>
            </div>
            
            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">Payment Method</label>
                <select name="payment_method" class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-royal-purple bg-white">
                    <option value="Cash">Cash</option>
                    <option value="GCash">GCash</option>
                    <option value="Card">Card</option>
                </select>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="document.getElementById('paymentModal').classList.add('hidden')" class="flex-1 py-3 text-slate-600 font-bold hover:bg-gray-50 rounded-xl">Cancel</button>
                <button type="submit" class="flex-1 py-3 bg-clinic-gold hover:bg-yellow-400 text-royal-purple font-bold rounded-xl shadow-lg">Confirm</button>
            </div>
        </form>
    </div>

    <script>
        function openPayModal(data) {
            document.getElementById('modal_inst_id').value = data.installment_id;
            
            // Calculate suggested amount (Monthly + Penalty if exists)
            let amount = parseFloat(data.monthly_amount);
            let penalty = 0;
            
            // Check if penalty columns exist and have values
            if (data.penalty_accumulated && data.penalty_paid) {
                penalty = Math.max(0, parseFloat(data.penalty_accumulated) - parseFloat(data.penalty_paid));
            }
            
            let total = amount + penalty;
            
            document.getElementById('modal_monthly_disp').textContent = '₱' + total.toFixed(2);
            document.getElementById('modal_amount_input').value = total.toFixed(2);
            
            // Show penalty note if there are penalties
            if (penalty > 0) {
                console.log('Penalty due: ₱' + penalty.toFixed(2));
            }
            
            document.getElementById('paymentModal').classList.remove('hidden');
        }

        function openEditStatusModal(id, currentStatus) {
            document.getElementById('edit_inst_id').value = id;
            document.getElementById('edit_status_select').value = currentStatus;
            document.getElementById('editStatusModal').classList.remove('hidden');
        }

        function confirmDelete(id) {
            document.getElementById('delete_inst_id').value = id;
            document.getElementById('deleteConfirmModal').classList.remove('hidden');
        }
    </script>

    <!-- Edit Status Modal -->
    <div id="editStatusModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <form method="POST" class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 animate-fade-in-up">
            <h3 class="text-xl font-bold text-royal-purple mb-4">Edit Status</h3>
            <input type="hidden" name="installment_id" id="edit_inst_id">
            <input type="hidden" name="update_status" value="1">
            
            <p class="text-sm text-slate-500 mb-4">Manually update the status of this installment plan.</p>
            
            <div class="mb-6">
                <label class="block text-xs font-bold text-slate-500 uppercase mb-2">New Status</label>
                <select name="new_status" id="edit_status_select" class="w-full px-4 py-3 rounded-xl border border-gray-200 focus:border-royal-purple bg-white">
                    <option value="Active">Active</option>
                    <option value="Pending">Pending</option>
                    <option value="Completed">Completed</option>
                    <option value="Defaulted">Defaulted</option>
                    <option value="Cancelled">Cancelled</option>
                </select>
            </div>
            
            <div class="flex gap-3">
                <button type="button" onclick="document.getElementById('editStatusModal').classList.add('hidden')" class="flex-1 py-3 text-slate-600 font-bold hover:bg-gray-50 rounded-xl">Cancel</button>
                <button type="submit" class="flex-1 py-3 bg-royal-purple hover:bg-purple-800 text-white font-bold rounded-xl shadow-lg">Update</button>
            </div>
        </form>
    </div>

    <!-- Delete Confirmation Modal -->
    <div id="deleteConfirmModal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <form method="POST" class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6 animate-fade-in-up">
            <div class="flex flex-col items-center text-center mb-6">
                 <div class="h-16 w-16 rounded-full bg-yellow-50 flex items-center justify-center text-clinic-gold mb-4">
                    <span class="material-symbols-outlined text-3xl">warning</span>
                 </div>
                 <h3 class="text-xl font-bold text-royal-purple">Delete Installment?</h3>
                 <p class="text-sm text-slate-500 mt-2">You won't be able to revert this! Ensure no payments are linked.</p>
            </div>
            
            <input type="hidden" name="installment_id" id="delete_inst_id">
            <input type="hidden" name="delete_installment" value="1">
            
            <div class="flex gap-3">
                <button type="button" onclick="document.getElementById('deleteConfirmModal').classList.add('hidden')" class="flex-1 py-3 text-slate-600 font-bold hover:bg-gray-50 rounded-xl">Cancel</button>
                <button type="submit" class="flex-1 py-3 bg-royal-purple hover:bg-purple-800 text-white font-bold rounded-xl shadow-lg">Delete</button>
            </div>
        </form>
    </div>
</body>
</html>
