<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Dentist role to access this page
requireRole('Dentist');

// Update current user's activity
updateUserActivity();

// Get logged-in user information
$doctor_id = $_SESSION['user_id'] ?? null;
$logged_in_user = [
    'full_name' => $_SESSION['full_name'] ?? 'Dr. User',
    'username' => $_SESSION['username'] ?? 'user'
];

// Helper function to calculate age
function calculateAge($dob) {
    if (!$dob) return null;
    $birth = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

// Helper function to get default profile photo
function getDefaultProfilePhoto($full_name) {
    $names = explode(' ', trim($full_name));
    $initials = '';
    if (count($names) >= 2) {
        $initials = strtoupper(substr($names[0], 0, 1) . substr($names[count($names) - 1], 0, 1));
    } else {
        $initials = strtoupper(substr($full_name, 0, 2));
    }
    $colors = [
        ['bg' => '#8a11d4', 'text' => '#ffffff'],
        ['bg' => '#6A1B9A', 'text' => '#ffffff'],
        ['bg' => '#FFD700', 'text' => '#151118'],
        ['bg' => '#10b981', 'text' => '#ffffff'],
    ];
    $colorIndex = abs(crc32($full_name)) % count($colors);
    $color = $colors[$colorIndex];
    return ['initials' => $initials, 'bg_color' => $color['bg'], 'text_color' => $color['text']];
}

// Get search, filter, and sort parameters
$search_query = sanitizeInput($_GET['search'] ?? '');
$status_filter = sanitizeInput($_GET['status'] ?? 'all');
$sort_by = sanitizeInput($_GET['sort'] ?? 'name');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 10;
$offset = ($page - 1) * $per_page;

    // Build query to get unique patients assigned to this doctor
    $where_conditions = [];
    $search_params = [];

if ($doctor_id) {
    // Add search filter
    if (!empty($search_query)) {
        $where_conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.phone_number LIKE ? OR p.patient_id = ?)";
        $search_param = '%' . $search_query . '%';
        $search_params[] = $search_param;
        $search_params[] = $search_param;
        $search_params[] = $search_param;
        $search_params[] = $search_param;
        $search_params[] = is_numeric($search_query) ? intval($search_query) : 0;
    }
    
    $where_clause = !empty($where_conditions) ? ' AND ' . implode(' AND ', $where_conditions) : '';
    
    // Add sorting
    $order_by = '';
    switch ($sort_by) {
        case 'name':
            $order_by = 'ORDER BY p.last_name ASC, p.first_name ASC';
            break;
        case 'last_visit':
            $order_by = 'ORDER BY last_visit_date DESC';
            break;
        case 'next_appt':
            $order_by = 'ORDER BY next_appt_date ASC';
            break;
        default:
            $order_by = 'ORDER BY p.last_name ASC, p.first_name ASC';
    }
    
    // Note: We'll calculate total after filtering since status determination is complex
    
    $current_date = date('Y-m-d');
    
    // Fetch patients with additional data (last visit, next appointment)
    // Build base parameters array (for subqueries and WHERE EXISTS clauses)
    $base_params = [
        $doctor_id,  // last_visit_date subquery
        $doctor_id,  // last_visit_service subquery 1
        $doctor_id,  // last_visit_service subquery 2
        $doctor_id,  // next_appt_datetime subquery doctor_id
        $current_date, // next_appt_datetime subquery date
        $doctor_id,  // next_appt_service subquery doctor_id
        $current_date, // next_appt_service subquery date
        $doctor_id,  // EXISTS appointment doctor_id
        $doctor_id   // EXISTS medical_record doctor_id
    ];
    
    // Add search params if any (these go after the base params)
    $patients_params = array_merge($base_params, $search_params);
    
    $patients_query = "
        SELECT DISTINCT
            p.patient_id,
            p.first_name,
            p.last_name,
            p.dob,
            p.gender,
            p.phone_number,
            p.email,
            p.registration_date,
            (
                SELECT MAX(mr.date_of_treatment)
                FROM tbl_medical_record mr
                WHERE mr.patient_id = p.patient_id AND mr.doctor_id = ?
            ) as last_visit_date,
            (
                SELECT GROUP_CONCAT(s2.service_name SEPARATOR ', ')
                FROM tbl_medical_record mr2
                INNER JOIN tbl_service s2 ON mr2.service_id = s2.service_id
                WHERE mr2.patient_id = p.patient_id 
                  AND mr2.doctor_id = ?
                  AND mr2.date_of_treatment = (
                      SELECT MAX(mr3.date_of_treatment)
                      FROM tbl_medical_record mr3
                      WHERE mr3.patient_id = p.patient_id AND mr3.doctor_id = ?
                  )
                GROUP BY mr2.appointment_id
                LIMIT 1
            ) as last_visit_service,
            (
                SELECT MIN(CONCAT(a.appointment_date, ' ', a.time_slot))
                FROM tbl_appointment a
                WHERE a.patient_id = p.patient_id 
                  AND a.doctor_id = ?
                  AND a.appointment_date >= ?
                  AND a.status IN ('Pending', 'Booked')
            ) as next_appt_datetime,
            (
                SELECT GROUP_CONCAT(s2.service_name SEPARATOR ', ')
                FROM tbl_appointment a2
                LEFT JOIN tbl_appointment_service tas2 ON a2.appointment_id = tas2.appointment_id
                LEFT JOIN tbl_service s2 ON tas2.service_id = s2.service_id
                WHERE a2.patient_id = p.patient_id 
                  AND a2.doctor_id = ?
                  AND a2.appointment_date >= ?
                  AND a2.status IN ('Pending', 'Booked')
                GROUP BY a2.appointment_id
                ORDER BY a2.appointment_date ASC, a2.time_slot ASC
                LIMIT 1
            ) as next_appt_service
        FROM tbl_patient p
        WHERE EXISTS (
            SELECT 1 FROM tbl_appointment a 
            WHERE a.patient_id = p.patient_id AND a.doctor_id = ?
        ) OR EXISTS (
            SELECT 1 FROM tbl_medical_record mr 
            WHERE mr.patient_id = p.patient_id AND mr.doctor_id = ?
        )
        " . $where_clause . " " . $order_by . "
        LIMIT " . intval($per_page) . " OFFSET " . intval($offset);
    
    try {
        $patients_stmt = $pdo->prepare($patients_query);
        $patients_stmt->execute($patients_params);
        $patients = $patients_stmt->fetchAll();
    } catch (PDOException $e) {
        error_log('Error fetching patients: ' . $e->getMessage());
        $patients = [];
    }
    
    // Process patients to add calculated fields and determine status
    $processed_patients = [];
    $current_date = date('Y-m-d');
    $current_datetime = date('Y-m-d H:i:s');
    
    foreach ($patients as $patient) {
        $age = calculateAge($patient['dob']);
        $initials = strtoupper(substr($patient['first_name'], 0, 1) . substr($patient['last_name'], 0, 1));
        
        // Format last visit
        $last_visit = null;
        $last_visit_service = null;
        if ($patient['last_visit_date']) {
            $last_visit_date = new DateTime($patient['last_visit_date']);
            $last_visit = $last_visit_date->format('M d, Y');
            $last_visit_service = $patient['last_visit_service'];
        }
        
        // Format next appointment
        $next_appt = null;
        $next_appt_service = null;
        $next_appt_status = null;
        if ($patient['next_appt_datetime']) {
            $next_appt_date = new DateTime($patient['next_appt_datetime']);
            $next_appt = [
                'date' => $next_appt_date->format('M d, Y'),
                'time' => $next_appt_date->format('g:i A'),
                'datetime' => $next_appt_date->format('Y-m-d')
            ];
            $next_appt_service = $patient['next_appt_service'];
            
            // Check if overdue
            if ($next_appt['datetime'] < $current_date) {
                $next_appt_status = 'overdue';
            } elseif ($next_appt['datetime'] == $current_date) {
                $next_appt_status = 'today';
            } else {
                $next_appt_status = 'upcoming';
            }
        }
        
        // Determine patient status
        $status = 'Inactive';
        $status_class = 'bg-gray-100 text-gray-600 border-gray-200';
        
        // Check for active appointments first (even without visits)
        if ($next_appt && $next_appt_status !== 'overdue') {
            $status = 'Active Treatment';
            $status_class = 'bg-green-100 text-green-800 border-green-200';
        }
        
        if ($patient['last_visit_date']) {
            $last_visit_days = (strtotime($current_date) - strtotime($patient['last_visit_date'])) / (60 * 60 * 24);
            
            // If no active appointment, determine status based on last visit
            if (!$next_appt || $next_appt_status === 'overdue') {
                if ($last_visit_days <= 90) {
                    // Recent visit within 3 months
                    $status = 'Active Treatment';
                    $status_class = 'bg-green-100 text-green-800 border-green-200';
                } elseif ($last_visit_days <= 180) {
                    // Visit within 6 months
                    $status = 'Maintenance';
                    $status_class = 'bg-gray-100 text-gray-800 border-gray-200';
                } elseif ($last_visit_days <= 365) {
                    // Visit within a year
                    $status = 'Maintenance';
                    $status_class = 'bg-gray-100 text-gray-800 border-gray-200';
                } else {
                    // No visit in over a year
                    $status = 'Inactive';
                    $status_class = 'bg-gray-100 text-gray-600 border-gray-200';
                }
            }
            
            // Check if new patient (registered within last 30 days and first visit)
            $reg_days = (strtotime($current_date) - strtotime($patient['registration_date'])) / (60 * 60 * 24);
            if ($reg_days <= 30) {
                // Check if this is their first visit
                try {
                    $visit_count_stmt = $pdo->prepare("SELECT COUNT(*) FROM tbl_medical_record WHERE patient_id = ? AND doctor_id = ?");
                    $visit_count_stmt->execute([$patient['patient_id'], $doctor_id]);
                    $visit_count = $visit_count_stmt->fetchColumn();
                    
                    if ($visit_count <= 1) {
                        $status = 'New Patient';
                        $status_class = 'bg-blue-100 text-blue-800 border-blue-200';
                    }
                } catch (PDOException $e) {
                    error_log('Error checking visit count: ' . $e->getMessage());
                }
            }
        } else {
            // No visits yet - check if they have appointments
            if ($next_appt && $next_appt_status !== 'overdue') {
                // Has appointment but no visits - still active
                $status = 'Active Treatment';
                $status_class = 'bg-green-100 text-green-800 border-green-200';
            } else {
                // No visits and no active appointments - new patient
                $status = 'New Patient';
                $status_class = 'bg-blue-100 text-blue-800 border-blue-200';
            }
        }
        
        $processed_patients[] = [
            'patient_id' => $patient['patient_id'],
            'first_name' => $patient['first_name'],
            'last_name' => $patient['last_name'],
            'full_name' => $patient['first_name'] . ' ' . $patient['last_name'],
            'dob' => $patient['dob'],
            'age' => $age,
            'gender' => $patient['gender'],
            'phone_number' => $patient['phone_number'],
            'email' => $patient['email'],
            'initials' => $initials,
            'last_visit' => $last_visit,
            'last_visit_service' => $last_visit_service,
            'next_appt' => $next_appt,
            'next_appt_service' => $next_appt_service,
            'next_appt_status' => $next_appt_status,
            'status' => $status,
            'status_class' => $status_class
        ];
    }
    
    // Apply status filter
    if ($status_filter !== 'all') {
        $status_map = [
            'Active Treatment' => 'active',
            'Maintenance' => 'maintenance',
            'Inactive' => 'archived',
            'New Patient' => 'active'
        ];
        $processed_patients = array_filter($processed_patients, function($patient) use ($status_filter, $status_map) {
            return isset($status_map[$patient['status']]) && $status_map[$patient['status']] === $status_filter;
        });
        $processed_patients = array_values($processed_patients); // Re-index array
    }
    
    // Re-sort if needed (since we filtered after fetching)
    if ($sort_by === 'last_visit') {
        usort($processed_patients, function($a, $b) {
            $a_date = $a['last_visit'] ? strtotime($a['last_visit']) : 0;
            $b_date = $b['last_visit'] ? strtotime($b['last_visit']) : 0;
            return $b_date - $a_date;
        });
    } elseif ($sort_by === 'next_appt') {
        usort($processed_patients, function($a, $b) {
            $a_date = $a['next_appt'] ? strtotime($a['next_appt']['datetime']) : PHP_INT_MAX;
            $b_date = $b['next_appt'] ? strtotime($b['next_appt']['datetime']) : PHP_INT_MAX;
            return $a_date - $b_date;
        });
    }
    
    // Get total count for display (before filtering)
    try {
        $count_query = "SELECT COUNT(DISTINCT p.patient_id) as total FROM tbl_patient p WHERE EXISTS (SELECT 1 FROM tbl_appointment a WHERE a.patient_id = p.patient_id AND a.doctor_id = ?) OR EXISTS (SELECT 1 FROM tbl_medical_record mr WHERE mr.patient_id = p.patient_id AND mr.doctor_id = ?)" . $where_clause;
        $count_params = [$doctor_id, $doctor_id];
        if (!empty($search_query)) {
            $count_params = array_merge($count_params, $search_params);
        }
        $count_stmt = $pdo->prepare($count_query);
        $count_stmt->execute($count_params);
        $total_patients = $count_stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log('Error counting patients: ' . $e->getMessage());
        $total_patients = 0;
    }
    
    // Update total count after filtering
    $total_patients_filtered = count($processed_patients);
    $total_pages = ceil($total_patients_filtered / $per_page);
    $processed_patients = array_slice($processed_patients, $offset, $per_page);
} else {
    $processed_patients = [];
    $total_patients = 0;
    $total_pages = 0;
}
?>
<!DOCTYPE html>
<html class="light" lang="en"><head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>My Patients - Vanessa Nicolas Dental Clinic</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#8a11d4", // Royal Purple
                        "secondary": "#FFD700", // Gold
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
<style>
        .clinical-scroll::-webkit-scrollbar {
            width: 8px;
        }
        .clinical-scroll::-webkit-scrollbar-track {
            background: #f1f1f1; 
            border-radius: 4px;
        }
        .clinical-scroll::-webkit-scrollbar-thumb {
            background: #d1d5db; 
            border-radius: 4px;
        }
        .clinical-scroll::-webkit-scrollbar-thumb:hover {
            background: #9ca3af; 
        }
    </style>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-[#151118] overflow-hidden">
<div class="flex h-screen w-full overflow-hidden">
<aside class="flex w-72 flex-col border-r border-[#e2dbe6] bg-white h-full hidden lg:flex flex-shrink-0 z-20 shadow-sm">
<div class="flex flex-col h-full justify-between p-6">
<div class="flex flex-col gap-8">
<div class="flex gap-3 items-center">
<div class="bg-center bg-no-repeat bg-cover rounded-xl size-12 shrink-0 shadow-md border-2 border-primary/10" data-alt="Logo showing a stylized tooth with a purple gradient background" style='background-image: url("https://lh3.googleusercontent.com/aida-public/AB6AXuDVLK7T7qRCxOBG-KaXUiV7VW5S5DR2zmQCVv6YRrNOrUS38YGhDstNQjB3IS0WM1pZK9Wo4EHloZ0lx0FQGmj-rZI4gSVFeJTXSjATIJ_tYqqzdo5pdNJ1pksXN6aRO3aeGfXmoEGKDAHJBMYm7CCJnmtPX1pi-uNNh_4RwjGbI6iAxHe6j1e3lNXsLFupAxH4fZU835iEQPEfoQiIO8RNnlF9NY6cD7wJbJ6d_j2M9IrAx20oMjkbfg8QwQadAORe5tubT-S5tKg");'></div>
<div class="flex flex-col">
<h1 class="text-[#151118] text-lg font-bold leading-tight">Vanessa Nicolas</h1>
<p class="text-[#7a6189] text-xs font-medium uppercase tracking-wider">Dental Clinic</p>
</div>
</div>
<nav class="flex flex-col gap-2">
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_dashboard.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">dashboard</span>
<p class="text-sm font-medium leading-normal">Dashboard</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 border-l-4 border-primary shadow-sm group transition-all" href="doctor_patients.php">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform">groups</span>
<p class="text-primary text-sm font-bold leading-normal">My Patients</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_records.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">folder_shared</span>
<p class="text-sm font-medium leading-normal">Medical Records</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_schedule.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">calendar_month</span>
<p class="text-sm font-medium leading-normal">My Schedule</p>
</a>
</nav>
</div>
<div class="flex flex-col gap-4">
<div class="p-4 bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl border border-primary/10">
<div class="flex items-center gap-3 mb-2">
<span class="material-symbols-outlined text-secondary text-2xl">workspace_premium</span>
<p class="text-xs font-bold text-primary uppercase">Staff Portal</p>
</div>
<p class="text-xs text-[#7a6189]">Logged in securely. Session active.</p>
</div>
<a href="../logout.php" class="flex items-center gap-3 px-4 py-2 text-[#7a6189] hover:text-red-500 transition-colors">
<span class="material-symbols-outlined">logout</span>
<span class="text-sm font-medium">Log Out</span>
</a>
</div>
</div>
</aside>
<main class="flex-1 flex flex-col h-full relative overflow-hidden">
<header class="h-16 flex items-center justify-between px-8 bg-white border-b border-[#f3f0f4] shrink-0 z-10">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-[#7a6189]">menu_open</span>
<span class="text-[#e2dbe6] mx-2">|</span>
<h2 class="text-[#151118] text-base font-bold">My Patients</h2>
</div>
<div class="flex items-center gap-6">
<button class="btn-notifications relative p-2 text-[#7a6189] hover:bg-[#f3f0f4] rounded-full transition-colors">
<span class="material-symbols-outlined">notifications</span>
<span class="absolute top-2 right-2 size-2 bg-red-500 rounded-full border border-white"></span>
</button>
<a href="doctor_current_user.php" class="flex items-center gap-3 pl-6 border-l border-[#f3f0f4] cursor-pointer hover:opacity-80 transition-opacity">
<div class="flex flex-col items-end">
<p class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($logged_in_user['full_name']); ?></p>
<p class="text-xs text-[#7a6189]">Dentist</p>
</div>
<?php
$current_profile = null;
if ($doctor_id) {
    try {
        $profile_stmt = $pdo->prepare("SELECT profile_picture FROM tbl_user WHERE user_id = ?");
        $profile_stmt->execute([$doctor_id]);
        $profile_result = $profile_stmt->fetch();
        $current_profile = $profile_result['profile_picture'] ?? null;
    } catch (PDOException $e) {
        error_log('Error fetching profile picture: ' . $e->getMessage());
    }
}
$current_name = $logged_in_user['full_name'];

if ($current_profile && file_exists('../' . $current_profile)) {
    echo '<div class="bg-center bg-no-repeat bg-cover rounded-full size-10 ring-2 ring-primary/20" style="background-image: url(\'../' . htmlspecialchars($current_profile) . '\');"></div>';
} else {
    $default_photo = getDefaultProfilePhoto($current_name);
    echo '<div class="rounded-full size-10 ring-2 ring-primary/20 flex items-center justify-center text-white font-bold" style="background-color: ' . $default_photo['bg_color'] . '; color: ' . $default_photo['text_color'] . ';">' . htmlspecialchars($default_photo['initials']) . '</div>';
}
?>
</a>
</div>
</header>
<div class="flex-1 overflow-y-auto bg-background-light p-6 lg:p-8">
<div class="max-w-[1600px] mx-auto flex flex-col gap-6 h-full">
<div class="flex flex-col lg:flex-row gap-6 justify-between items-end">
<div class="flex flex-col gap-1">
<h1 class="text-2xl md:text-3xl font-black text-[#151118] tracking-tight">Patient Management</h1>
<p class="text-[#7a6189] text-base font-medium flex items-center gap-2">
                            Total Assigned Patients: <span class="font-bold text-primary"><?php echo $total_patients; ?></span>
</p>
</div>
<div class="flex gap-3">
<button onclick="exportPatientList()" class="flex items-center gap-2 px-4 py-3 rounded-xl bg-white border border-[#e2dbe6] text-[#7a6189] font-bold text-sm hover:bg-[#f8f9fa] shadow-sm transition-all">
<span class="material-symbols-outlined text-lg">download</span>
                            Export List
                        </button>
</div>
</div>
<form method="GET" action="" class="bg-white p-4 rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col md:flex-row gap-4 items-center justify-between">
<div class="w-full md:w-[400px] relative">
<div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
<span class="material-symbols-outlined text-[#7a6189]">search</span>
</div>
<input name="search" value="<?php echo htmlspecialchars($search_query); ?>" class="block w-full pl-10 pr-4 py-2.5 bg-[#fcfcfd] border border-[#e2dbe6] rounded-xl text-sm placeholder-[#7a6189] focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all" placeholder="Search by name, ID, or phone number..." type="text"/>
</div>
<div class="flex items-center gap-3 w-full md:w-auto overflow-x-auto pb-2 md:pb-0">
<div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[#fcfcfd] border border-[#e2dbe6]">
<span class="material-symbols-outlined text-[#7a6189] text-lg">filter_list</span>
<select name="status" onchange="this.form.submit()" class="bg-transparent border-none p-0 text-sm font-medium text-[#151118] focus:ring-0 cursor-pointer">
<option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>Status: All</option>
<option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Active Treatment</option>
<option value="maintenance" <?php echo $status_filter === 'maintenance' ? 'selected' : ''; ?>>Maintenance</option>
<option value="archived" <?php echo $status_filter === 'archived' ? 'selected' : ''; ?>>Archived</option>
</select>
</div>
<div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-[#fcfcfd] border border-[#e2dbe6]">
<span class="material-symbols-outlined text-[#7a6189] text-lg">sort</span>
<select name="sort" onchange="this.form.submit()" class="bg-transparent border-none p-0 text-sm font-medium text-[#151118] focus:ring-0 cursor-pointer">
<option value="name" <?php echo $sort_by === 'name' ? 'selected' : ''; ?>>Sort by: Name (A-Z)</option>
<option value="last_visit" <?php echo $sort_by === 'last_visit' ? 'selected' : ''; ?>>Last Visit (Newest)</option>
<option value="next_appt" <?php echo $sort_by === 'next_appt' ? 'selected' : ''; ?>>Next Appt (Soonest)</option>
</select>
</div>
</div>
</form>
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col overflow-hidden flex-1 min-h-[500px]">
<div class="overflow-x-auto">
<table class="w-full text-left border-collapse">
<thead>
<tr class="bg-[#fcfcfd] border-b border-[#f3f0f4]">
<th class="px-6 py-4 text-xs font-bold text-[#7a6189] uppercase tracking-wider">Patient Name</th>
<th class="px-6 py-4 text-xs font-bold text-[#7a6189] uppercase tracking-wider">Patient ID</th>
<th class="px-6 py-4 text-xs font-bold text-[#7a6189] uppercase tracking-wider">Contact</th>
<th class="px-6 py-4 text-xs font-bold text-[#7a6189] uppercase tracking-wider">Last Visit</th>
<th class="px-6 py-4 text-xs font-bold text-[#7a6189] uppercase tracking-wider">Next Appointment</th>
<th class="px-6 py-4 text-xs font-bold text-[#7a6189] uppercase tracking-wider">Status</th>
<th class="px-6 py-4 text-xs font-bold text-[#7a6189] uppercase tracking-wider text-right">Actions</th>
</tr>
</thead>
<tbody class="divide-y divide-[#f3f0f4]">
<?php if (empty($processed_patients)): ?>
<tr>
<td colspan="7" class="px-6 py-12 text-center text-[#7a6189]">
<div class="flex flex-col items-center gap-3">
<span class="material-symbols-outlined text-4xl text-slate-300">groups</span>
<p class="font-medium">No patients found</p>
<?php if (!empty($search_query)): ?>
<p class="text-sm">Try adjusting your search criteria</p>
<?php endif; ?>
</div>
</td>
</tr>
<?php else: ?>
<?php foreach ($processed_patients as $patient): 
    $default_photo = getDefaultProfilePhoto($patient['full_name']);
    $patient_id_display = 'P-' . str_pad($patient['patient_id'], 5, '0', STR_PAD_LEFT);
    $phone_display = $patient['phone_number'] ? '+' . substr($patient['phone_number'], 0, 1) . ' (' . substr($patient['phone_number'], 1, 3) . ') ' . substr($patient['phone_number'], 4, 3) . '-' . substr($patient['phone_number'], 7) : 'N/A';
?>
<tr class="hover:bg-[#f8f9fa] transition-colors group cursor-pointer" onclick="window.location.href='doctor_patient_info.php?id=<?php echo $patient['patient_id']; ?>'">
<td class="px-6 py-4">
<div class="flex items-center gap-3">
<div class="size-10 rounded-full flex items-center justify-center font-bold text-sm ring-1 ring-[#e2dbe6]" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;"><?php echo htmlspecialchars($patient['initials']); ?></div>
<div>
<p class="text-sm font-bold text-[#151118] group-hover:text-primary transition-colors"><?php echo htmlspecialchars($patient['full_name']); ?></p>
<p class="text-xs text-[#7a6189]"><?php echo htmlspecialchars($patient['gender'] ?? 'N/A'); ?><?php echo $patient['age'] ? ', ' . $patient['age'] . ' yrs' : ''; ?></p>
</div>
</div>
</td>
<td class="px-6 py-4">
<span class="font-mono text-xs font-medium text-[#151118] bg-[#f3f0f4] px-2 py-1 rounded">#<?php echo $patient_id_display; ?></span>
</td>
<td class="px-6 py-4">
<div class="flex flex-col">
<p class="text-xs text-[#151118] font-medium"><?php echo htmlspecialchars($phone_display); ?></p>
<?php if ($patient['email']): ?>
<p class="text-[10px] text-[#7a6189]"><?php echo htmlspecialchars($patient['email']); ?></p>
<?php endif; ?>
</div>
</td>
<td class="px-6 py-4">
<?php if ($patient['last_visit']): ?>
<p class="text-sm text-[#151118]"><?php echo $patient['last_visit']; ?></p>
<?php if ($patient['last_visit_service']): ?>
<p class="text-[10px] text-[#7a6189]"><?php echo htmlspecialchars($patient['last_visit_service']); ?></p>
<?php endif; ?>
<?php else: ?>
<p class="text-sm text-[#7a6189] italic">No visits yet</p>
<?php endif; ?>
</td>
<td class="px-6 py-4">
<?php if ($patient['next_appt']): ?>
<div class="flex items-center gap-2">
<?php if ($patient['next_appt_status'] === 'overdue'): ?>
<span class="size-2 rounded-full bg-red-400"></span>
<p class="text-sm text-red-600 font-bold">Overdue</p>
<?php elseif ($patient['next_appt_status'] === 'today'): ?>
<span class="size-2 rounded-full bg-yellow-500"></span>
<p class="text-sm text-[#151118] font-medium"><?php echo $patient['next_appt']['date']; ?></p>
<?php else: ?>
<span class="size-2 rounded-full bg-green-500"></span>
<p class="text-sm text-[#151118] font-medium"><?php echo $patient['next_appt']['date']; ?></p>
<?php endif; ?>
</div>
<?php if ($patient['next_appt_service']): ?>
<p class="text-[10px] text-[#7a6189]"><?php echo $patient['next_appt']['time']; ?> - <?php echo htmlspecialchars($patient['next_appt_service']); ?></p>
<?php endif; ?>
<?php else: ?>
<p class="text-sm text-[#7a6189] italic">Not scheduled</p>
<?php endif; ?>
</td>
<td class="px-6 py-4">
<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium border <?php echo $patient['status_class']; ?>">
                                            <?php echo htmlspecialchars($patient['status']); ?>
                                        </span>
</td>
<td class="px-6 py-4 text-right">
<a href="doctor_patient_info.php?id=<?php echo $patient['patient_id']; ?>" onclick="event.stopPropagation();" class="text-primary hover:text-primary/80 hover:bg-primary/5 p-2 rounded-lg transition-colors inline-block" title="Open Medical Record">
<span class="material-symbols-outlined text-xl">folder_open</span>
</a>
</td>
</tr>
<?php endforeach; ?>
<?php endif; ?>
</tbody>
</table>
</div>
<?php if ($total_pages > 1): ?>
<div class="bg-white px-6 py-4 border-t border-[#f3f0f4] flex items-center justify-between">
<p class="text-xs text-[#7a6189]">
                            Showing <span class="font-bold text-[#151118]"><?php echo count($processed_patients) > 0 ? $offset + 1 : 0; ?></span> to <span class="font-bold text-[#151118]"><?php echo $offset + count($processed_patients); ?></span> of <span class="font-bold text-[#151118]"><?php echo $total_patients_filtered ?? $total_patients; ?></span> patients<?php if ($status_filter !== 'all'): ?> (filtered)<?php endif; ?>
                        </p>
<div class="flex items-center gap-2">
<a href="?<?php echo http_build_query(array_merge($_GET, ['page' => max(1, $page - 1)])); ?>" class="p-2 rounded-lg border border-[#e2dbe6] text-[#7a6189] hover:bg-[#f8f9fa] <?php echo $page <= 1 ? 'opacity-50 cursor-not-allowed pointer-events-none' : ''; ?>">
<span class="material-symbols-outlined text-sm">chevron_left</span>
</a>
<div class="flex items-center gap-1">
<?php
$start_page = max(1, $page - 2);
$end_page = min($total_pages, $page + 2);

for ($i = $start_page; $i <= $end_page; $i++):
    $query_params = array_merge($_GET, ['page' => $i]);
?>
<a href="?<?php echo http_build_query($query_params); ?>" class="size-8 rounded-lg <?php echo $i == $page ? 'bg-primary text-white' : 'text-[#7a6189] hover:bg-[#f8f9fa]'; ?> text-xs <?php echo $i == $page ? 'font-bold' : 'font-medium'; ?> flex items-center justify-center">
<?php echo $i; ?>
</a>
<?php endfor; ?>
<?php if ($end_page < $total_pages): ?>
<span class="text-[#7a6189] text-xs px-1">...</span>
<a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" class="size-8 rounded-lg text-[#7a6189] hover:bg-[#f8f9fa] text-xs font-medium flex items-center justify-center">
<?php echo $total_pages; ?>
</a>
<?php endif; ?>
</div>
<a href="?<?php echo http_build_query(array_merge($_GET, ['page' => min($total_pages, $page + 1)])); ?>" class="p-2 rounded-lg border border-[#e2dbe6] text-[#7a6189] hover:bg-[#f8f9fa] <?php echo $page >= $total_pages ? 'opacity-50 cursor-not-allowed pointer-events-none' : ''; ?>">
<span class="material-symbols-outlined text-sm">chevron_right</span>
</a>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
</main>
</div>

<script>
function exportPatientList() {
    // Build export URL with current filters
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = '?' + params.toString();
}
</script>


<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body></html>
