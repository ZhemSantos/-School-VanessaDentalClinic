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

// Helper functions
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

function calculateAge($dob) {
    if (!$dob) return null;
    $birth = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

// Get search query
$search_query = sanitizeInput($_GET['search'] ?? '');
$selected_patient_id = isset($_GET['patient_id']) ? intval($_GET['patient_id']) : null;
$active_tab = sanitizeInput($_GET['tab'] ?? 'overview');

// Fetch patients assigned to this doctor
$patients = [];
$total_patients = 0;

if ($doctor_id) {
    try {
        $where_clause = '';
        $params = [$doctor_id, $doctor_id];
        
        if (!empty($search_query)) {
            $where_clause = " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.phone_number LIKE ? OR p.patient_id = ?)";
            $search_param = '%' . $search_query . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = is_numeric($search_query) ? intval($search_query) : 0;
        }
        
        // Get unique patients assigned to this doctor
        $patients_query = "
            SELECT DISTINCT p.*, 
                   CONCAT(p.first_name, ' ', p.last_name) as full_name
            FROM tbl_patient p
            WHERE (
                EXISTS (
                    SELECT 1 FROM tbl_appointment a 
                    WHERE a.patient_id = p.patient_id AND a.doctor_id = ?
                )
                OR EXISTS (
                    SELECT 1 FROM tbl_medical_record mr 
                    WHERE mr.patient_id = p.patient_id AND mr.doctor_id = ?
                )
            )
            $where_clause
            ORDER BY p.last_name ASC, p.first_name ASC
        ";
        
        $patients_stmt = $pdo->prepare($patients_query);
        $patients_stmt->execute($params);
        $patients = $patients_stmt->fetchAll();
        $total_patients = count($patients);
    } catch (PDOException $e) {
        error_log('Error fetching patients: ' . $e->getMessage());
    }
}

// Fetch selected patient details
$selected_patient = null;
$patient_appointments = [];
$patient_medical_records = [];
$patient_files = [];
$patient_treatment_plans = [];
$current_medical_record = null;

if ($selected_patient_id && $doctor_id) {
    try {
        // Verify patient is assigned to this doctor
        $verify_stmt = $pdo->prepare("
            SELECT COUNT(*) as count FROM tbl_appointment 
            WHERE patient_id = ? AND doctor_id = ?
            UNION ALL
            SELECT COUNT(*) as count FROM tbl_medical_record 
            WHERE patient_id = ? AND doctor_id = ?
        ");
        $verify_stmt->execute([$selected_patient_id, $doctor_id, $selected_patient_id, $doctor_id]);
        $results = $verify_stmt->fetchAll();
        $total = ($results[0]['count'] ?? 0) + ($results[1]['count'] ?? 0);
        
        if ($total > 0) {
            // Get patient details
            $patient_stmt = $pdo->prepare("SELECT * FROM tbl_patient WHERE patient_id = ?");
            $patient_stmt->execute([$selected_patient_id]);
            $selected_patient = $patient_stmt->fetch();
            
            if ($selected_patient) {
                // Get appointments with this doctor
                $appt_stmt = $pdo->prepare("
                    SELECT a.*, GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name, u.full_name as doctor_name
                    FROM tbl_appointment a
                    INNER JOIN tbl_user u ON a.doctor_id = u.user_id
                    LEFT JOIN tbl_appointment_service tas ON a.appointment_id = tas.appointment_id
                    LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                    WHERE a.patient_id = ? AND a.doctor_id = ?
                    GROUP BY a.appointment_id
                    ORDER BY a.appointment_date DESC, a.time_slot DESC
                ");
                $appt_stmt->execute([$selected_patient_id, $doctor_id]);
                $patient_appointments = $appt_stmt->fetchAll();
                
                // Get medical records with appointment matching info
                $mr_stmt = $pdo->prepare("
                    SELECT mr.*, 
                           GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name, 
                           u.full_name as doctor_name,
                           TIME(mr.date_of_treatment) as treatment_time,
                           DATE(mr.date_of_treatment) as treatment_date
                    FROM tbl_medical_record mr
                    INNER JOIN tbl_user u ON mr.doctor_id = u.user_id
                    LEFT JOIN tbl_appointment_service tas ON mr.appointment_id = tas.appointment_id
                    LEFT JOIN tbl_service s ON tas.service_id = s.service_id
                    WHERE mr.patient_id = ? AND mr.doctor_id = ?
                    GROUP BY mr.record_id
                    ORDER BY mr.date_of_treatment DESC
                ");
                $mr_stmt->execute([$selected_patient_id, $doctor_id]);
                $patient_medical_records = $mr_stmt->fetchAll();
                
                // Get patient files
                $files_stmt = $pdo->prepare("
                    SELECT * FROM tbl_patient_file
                    WHERE patient_id = ? AND doctor_id = ?
                    ORDER BY uploaded_at DESC
                ");
                $files_stmt->execute([$selected_patient_id, $doctor_id]);
                $patient_files = $files_stmt->fetchAll();
                
                // Get treatment plans
                $plans_stmt = $pdo->prepare("
                    SELECT * FROM tbl_treatment_plan
                    WHERE patient_id = ? AND doctor_id = ?
                    ORDER BY created_at DESC
                ");
                $plans_stmt->execute([$selected_patient_id, $doctor_id]);
                $patient_treatment_plans = $plans_stmt->fetchAll();
            }
        }
    } catch (PDOException $e) {
        error_log('Error fetching patient details: ' . $e->getMessage());
    }
}

// Calculate overview statistics for selected patient
$overview_stats = [
    'total_appointments' => 0,
    'completed_appointments' => 0,
    'upcoming_appointments' => 0,
    'total_medical_records' => 0,
    'latest_record_date' => null,
    'total_files' => 0,
    'total_treatment_plans' => 0,
    'active_treatment_plans' => 0,
    'latest_diagnosis' => null
];

if ($selected_patient_id && $doctor_id) {
    try {
        // Appointment statistics
        $appt_stats_stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'Completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN appointment_date >= CURDATE() AND status IN ('Pending', 'Booked') THEN 1 ELSE 0 END) as upcoming
            FROM tbl_appointment
            WHERE patient_id = ? AND doctor_id = ?
        ");
        $appt_stats_stmt->execute([$selected_patient_id, $doctor_id]);
        $appt_stats = $appt_stats_stmt->fetch();
        $overview_stats['total_appointments'] = $appt_stats['total'] ?? 0;
        $overview_stats['completed_appointments'] = $appt_stats['completed'] ?? 0;
        $overview_stats['upcoming_appointments'] = $appt_stats['upcoming'] ?? 0;
        
        // Medical records statistics
        $overview_stats['total_medical_records'] = count($patient_medical_records);
        if (!empty($patient_medical_records)) {
            $latest_record = $patient_medical_records[0];
            $overview_stats['latest_record_date'] = date('M d, Y', strtotime($latest_record['treatment_date']));
            $overview_stats['latest_diagnosis'] = $latest_record['diagnosis'] ?? null;
        }
        
        // Files statistics
        $overview_stats['total_files'] = count($patient_files);
        
        // Treatment plans statistics
        $overview_stats['total_treatment_plans'] = count($patient_treatment_plans);
        $overview_stats['active_treatment_plans'] = count(array_filter($patient_treatment_plans, function($plan) {
            return $plan['status'] === 'Active';
        }));
    } catch (PDOException $e) {
        error_log('Error calculating overview statistics: ' . $e->getMessage());
    }
}

// Handle form submissions
$success_message = '';
$error_message = '';

// Handle clinical notes save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_clinical_notes']) && $selected_patient_id && $doctor_id) {
    $appointment_id = intval($_POST['appointment_id'] ?? 0);
    $diagnosis = trim($_POST['diagnosis'] ?? '');
    $treatment_notes = trim($_POST['treatment_notes'] ?? '');
    $tooth_numbers = trim($_POST['tooth_numbers'] ?? '');
    
    if (!empty($diagnosis) && $appointment_id > 0) {
        try {
            // Get appointment details
            $appt_stmt = $pdo->prepare("
                SELECT a.*, 
                       (SELECT service_id FROM tbl_appointment_service WHERE appointment_id = a.appointment_id LIMIT 1) as service_id 
                FROM tbl_appointment a
                WHERE a.appointment_id = ? AND a.doctor_id = ? AND a.patient_id = ?
            ");
            $appt_stmt->execute([$appointment_id, $doctor_id, $selected_patient_id]);
            $appt = $appt_stmt->fetch();
            
            if ($appt) {
                // Check if medical record exists using robust appointment_id link
                $check_stmt = $pdo->prepare("
                    SELECT record_id FROM tbl_medical_record
                    WHERE appointment_id = ?
                ");
                $check_stmt->execute([$appointment_id]);
                $existing = $check_stmt->fetch();
                
                if ($existing) {
                    // Update existing record
                    $update_stmt = $pdo->prepare("
                        UPDATE tbl_medical_record
                        SET diagnosis = ?, treatment_notes = ?, tooth_numbers = ?
                        WHERE record_id = ?
                    ");
                    $update_stmt->execute([$diagnosis, $treatment_notes, $tooth_numbers, $existing['record_id']]);
                } else {
                    // Create new record
                    // Use the first service_id found as a placeholder for the legacy column
                    $service_id_placeholder = $appt['service_id'] ?: 1; // Fallback to 1 if null, though unlikely in valid data
                    
                    $insert_stmt = $pdo->prepare("
                        INSERT INTO tbl_medical_record (patient_id, doctor_id, service_id, appointment_id, date_of_treatment, diagnosis, treatment_notes, tooth_numbers)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert_stmt->execute([
                        $selected_patient_id,
                        $doctor_id,
                        $service_id_placeholder,
                        $appointment_id,
                        date('Y-m-d H:i:s'),
                        $diagnosis,
                        $treatment_notes,
                        $tooth_numbers
                    ]);
                }
                
                $success_message = 'Clinical notes saved successfully!';
                header("Location: doctor_records.php?patient_id=$selected_patient_id&tab=clinical&saved=1");
                exit;
            }
        } catch (PDOException $e) {
            error_log('Error saving clinical notes: ' . $e->getMessage());
            $error_message = 'Failed to save clinical notes.';
        }
    } else {
        $error_message = 'Please provide a diagnosis.';
    }
}

// Handle treatment plan save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_treatment_plan']) && $selected_patient_id && $doctor_id) {
    $plan_name = trim($_POST['plan_name'] ?? '');
    $description = trim($_POST['plan_description'] ?? '');
    $status = sanitizeInput($_POST['plan_status'] ?? 'Draft');
    $plan_id = isset($_POST['plan_id']) ? intval($_POST['plan_id']) : null;
    
    if (!empty($plan_name)) {
        try {
            if ($plan_id) {
                // Update existing plan
                $update_stmt = $pdo->prepare("
                    UPDATE tbl_treatment_plan
                    SET plan_name = ?, description = ?, status = ?
                    WHERE plan_id = ? AND patient_id = ? AND doctor_id = ?
                ");
                $update_stmt->execute([$plan_name, $description, $status, $plan_id, $selected_patient_id, $doctor_id]);
            } else {
                // Create new plan
                $insert_stmt = $pdo->prepare("
                    INSERT INTO tbl_treatment_plan (patient_id, doctor_id, plan_name, description, status)
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insert_stmt->execute([$selected_patient_id, $doctor_id, $plan_name, $description, $status]);
            }
            
            $success_message = 'Treatment plan saved successfully!';
            header("Location: doctor_records.php?patient_id=$selected_patient_id&tab=treatment&saved=1");
            exit;
        } catch (PDOException $e) {
            error_log('Error saving treatment plan: ' . $e->getMessage());
            $error_message = 'Failed to save treatment plan.';
        }
    } else {
        $error_message = 'Please provide a plan name.';
    }
}

// Handle AI chat request (AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ai_chat_request']) && $selected_patient_id && $doctor_id) {
    header('Content-Type: application/json');
    
    $message = trim($_POST['message'] ?? '');
    $conversation_history = isset($_POST['history']) ? json_decode($_POST['history'], true) : [];
    
    if (empty($message)) {
        echo json_encode(['success' => false, 'message' => 'Message is required']);
        exit;
    }
    
    try {
        // Get patient info
        $patient_stmt = $pdo->prepare("
            SELECT p.*, CONCAT(p.first_name, ' ', p.last_name) as full_name
            FROM tbl_patient p
            WHERE p.patient_id = ?
        ");
        $patient_stmt->execute([$selected_patient_id]);
        $patient = $patient_stmt->fetch();
        
        // Get all medical records
        $records_stmt = $pdo->prepare("
            SELECT mr.*, 
                   GROUP_CONCAT(s.service_name SEPARATOR ', ') as service_name,
                   DATE(mr.date_of_treatment) as treatment_date,
                   TIME(mr.date_of_treatment) as treatment_time
            FROM tbl_medical_record mr
            LEFT JOIN tbl_appointment_service tas ON mr.appointment_id = tas.appointment_id
            LEFT JOIN tbl_service s ON tas.service_id = s.service_id
            WHERE mr.patient_id = ? AND mr.doctor_id = ?
            GROUP BY mr.record_id
            ORDER BY mr.date_of_treatment DESC
        ");
        $records_stmt->execute([$selected_patient_id, $doctor_id]);
        $medical_records = $records_stmt->fetchAll();
        
        // Build context for AI
        $context = "Patient Information:\n";
        $context .= "- Name: " . ($patient['full_name'] ?? 'N/A') . "\n";
        $context .= "- Age: " . (calculateAge($patient['dob'] ?? null) ?? 'N/A') . " years\n";
        $context .= "- Gender: " . ($patient['gender'] ?? 'N/A') . "\n";
        if ($patient['medical_history']) {
            $context .= "- Medical History: " . $patient['medical_history'] . "\n";
        }
        $context .= "\nMedical Records Summary:\n";
        
        if (empty($medical_records)) {
            $context .= "No medical records found for this patient.\n";
        } else {
            foreach ($medical_records as $record) {
                $date_formatted = date('M d, Y', strtotime($record['treatment_date']));
                $time_formatted = date('g:i A', strtotime($record['treatment_time']));
                $context .= "\n--- Record from " . $date_formatted . " at " . $time_formatted . " ---\n";
                $context .= "Service: " . $record['service_name'] . "\n";
                if ($record['diagnosis']) {
                    $context .= "Diagnosis: " . $record['diagnosis'] . "\n";
                }
                if ($record['treatment_notes']) {
                    $context .= "Treatment Notes: " . $record['treatment_notes'] . "\n";
                }
                if ($record['tooth_numbers']) {
                    $context .= "Tooth Numbers: " . $record['tooth_numbers'] . "\n";
                }
            }
        }
        
        // Prepare Gemini API request
        $api_key = 'AIzaSyAg88b_kxJXkyzmIxWuY7gVne5Rn8XX8os';
        // Use gemini-2.5-flash model
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . $api_key;
        
        $system_prompt = "You are a helpful AI assistant for a dental clinic. You have access to the following patient's medical records. Use this information to help answer questions, summarize diagnoses and treatments, and provide insights. Be concise and professional.\n\n" . $context . "\n\n";
        
        $full_conversation = $system_prompt;
        
        if (!empty($conversation_history) && is_array($conversation_history)) {
            foreach ($conversation_history as $hist) {
                if (isset($hist['role']) && isset($hist['content'])) {
                    if ($hist['role'] === 'user') {
                        $full_conversation .= "\n\nUser: " . $hist['content'];
                    } else {
                        $full_conversation .= "\n\nAssistant: " . $hist['content'];
                    }
                }
            }
        }
        
        $full_conversation .= "\n\nUser: " . $message;
        
        $payload = [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $full_conversation]
                    ]
                ]
            ]
        ];
        
        // Make API request
        $ch = curl_init($api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false || !empty($curl_error)) {
            echo json_encode([
                'success' => false,
                'message' => 'Connection error: ' . ($curl_error ?: 'Unable to connect to AI service.')
            ]);
            exit;
        }
        
        if ($http_code !== 200) {
            $response_array = json_decode($response, true);
            $error_details = $response_array['error']['message'] ?? 'AI service error';
            $error_code = $response_array['error']['code'] ?? $http_code;
            
            // Log full error for debugging
            error_log('Gemini API Error - HTTP Code: ' . $http_code . ', Response: ' . $response);
            
            // If model not found, try alternative models
            if (strpos($error_details, 'not found') !== false || strpos($error_details, 'not supported') !== false) {
                // Try gemini-1.5-flash as fallback
                $fallback_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $api_key;
                $ch2 = curl_init($fallback_url);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_POST, true);
                curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($payload));
                curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 30);
                
                $response2 = curl_exec($ch2);
                $http_code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                
                if ($http_code2 === 200) {
                    $response_data = json_decode($response2, true);
                    if (isset($response_data['candidates'][0]['content']['parts'][0]['text'])) {
                        $ai_response = $response_data['candidates'][0]['content']['parts'][0]['text'];
                        echo json_encode([
                            'success' => true,
                            'response' => $ai_response
                        ]);
                        exit;
                    }
                }
            }
            
            echo json_encode([
                'success' => false,
                'message' => 'AI service error (HTTP ' . $http_code . '): ' . $error_details,
                'error_code' => $error_code,
                'full_error' => $response_array['error'] ?? null
            ]);
            exit;
        }
        
        $response_data = json_decode($response, true);
        
        if (!isset($response_data['candidates'][0]['content']['parts'][0]['text'])) {
            echo json_encode([
                'success' => false,
                'message' => 'AI service returned an empty response.'
            ]);
            exit;
        }
        
        $ai_response = $response_data['candidates'][0]['content']['parts'][0]['text'];
        
        echo json_encode([
            'success' => true,
            'response' => $ai_response
        ]);
        exit;
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Error: ' . $e->getMessage()
        ]);
        exit;
    }
}

// Check for success messages
if (isset($_GET['saved']) && $_GET['saved'] == '1') {
    $success_message = 'Record saved successfully!';
}

?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
<meta charset="utf-8"/>
<meta content="width=device-width, initial-scale=1.0" name="viewport"/>
<title>Medical Records - Vanessa Nicolas Dental Clinic</title>
<link href="https://fonts.googleapis.com" rel="preconnect"/>
<link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@200..800&display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
<script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
<script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        "primary": "#6A1B9A",
                        "secondary": "#FFD700",
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
[class*="record-icon-"] {
    transition: transform 0.3s ease;
}
</style>
</head>
<body class="bg-background-light dark:bg-background-dark font-display text-[#151118] overflow-hidden">
<div class="flex h-screen w-full overflow-hidden">
<!-- Sidebar -->
<aside class="flex w-72 flex-col border-r border-[#e2dbe6] bg-white h-full hidden lg:flex flex-shrink-0 z-20 shadow-sm">
<div class="flex flex-col h-full justify-between p-6">
<div class="flex flex-col gap-8">
<div class="flex gap-3 items-center">
<div class="bg-center bg-no-repeat bg-cover rounded-xl size-12 shrink-0 shadow-md border-2 border-primary/10" style='background-image: url("https://lh3.googleusercontent.com/aida-public/AB6AXuDVLK7T7qRCxOBG-KaXUiV7VW5S5DR2zmQCVv6YRrNOrUS38YGhDstNQjB3IS0WM1pZK9Wo4EHloZ0lx0FQGmj-rZI4gSVFeJTXSjATIJ_tYqqzdo5pdNJ1pksXN6aRO3aeGfXmoEGKDAHJBMYm7CCJnmtPX1pi-uNNh_4RwjGbI6iAxHe6j1e3lNXsLFupAxH4fZU835iEQPEfoQiIO8RNnlF9NY6cD7wJbJ6d_j2M9IrAx20oMjkbfg8QwQadAORe5tubT-S5tKg");'></div>
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
<a class="flex items-center gap-3 px-4 py-3 rounded-xl hover:bg-[#f8f9fa] group transition-all text-[#7a6189] hover:text-[#151118]" href="doctor_patients.php">
<span class="material-symbols-outlined group-hover:scale-110 transition-transform">groups</span>
<p class="text-sm font-medium leading-normal">My Patients</p>
</a>
<a class="flex items-center gap-3 px-4 py-3 rounded-xl bg-primary/10 border-l-4 border-primary shadow-sm group transition-all" href="doctor_records.php">
<span class="material-symbols-outlined text-primary group-hover:scale-110 transition-transform">folder_shared</span>
<p class="text-primary text-sm font-bold leading-normal">Medical Records</p>
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
<h2 class="text-[#151118] text-base font-bold">Electronic Medical Records (EMR)</h2>
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
<div class="max-w-[1600px] mx-auto flex flex-col gap-6">
<div class="flex flex-col lg:flex-row gap-6 justify-between items-end">
<div class="flex flex-col gap-1">
<h1 class="text-2xl md:text-3xl font-black text-[#151118] tracking-tight">Patient Records Database</h1>
<p class="text-[#7a6189] text-base font-medium flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-sm">folder_open</span>
Managing <span class="font-bold text-primary"><?php echo $total_patients; ?></span> active patient files.
</p>
</div>
<form method="GET" action="" class="w-full lg:w-[480px]">
<div class="relative group">
<div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
<span class="material-symbols-outlined text-[#7a6189] group-focus-within:text-primary transition-colors">search</span>
</div>
<input name="search" value="<?php echo htmlspecialchars($search_query); ?>" class="block w-full pl-12 pr-4 py-3 bg-white border border-[#e2dbe6] rounded-xl text-sm placeholder-[#7a6189] focus:outline-none focus:ring-2 focus:ring-primary/20 focus:border-primary transition-all shadow-sm" placeholder="Search by patient name, ID, or phone..." type="text"/>
<?php if ($selected_patient_id): ?>
<input type="hidden" name="patient_id" value="<?php echo $selected_patient_id; ?>"/>
<input type="hidden" name="tab" value="<?php echo htmlspecialchars($active_tab); ?>"/>
<?php endif; ?>
</div>
</form>
</div>

<?php if ($success_message): ?>
<div class="bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl flex items-center gap-2">
<span class="material-symbols-outlined">check_circle</span>
<span><?php echo htmlspecialchars($success_message); ?></span>
</div>
<?php endif; ?>

<?php if ($error_message): ?>
<div class="bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl flex items-center gap-2">
<span class="material-symbols-outlined">error</span>
<span><?php echo htmlspecialchars($error_message); ?></span>
</div>
<?php endif; ?>

<div class="grid grid-cols-12 gap-6 h-[calc(100vh-280px)] min-h-[600px]">
<!-- Patient Directory -->
<div id="patientDirectory" class="col-span-12 xl:col-span-4 flex flex-col gap-6 h-full transition-all duration-300">
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col h-full overflow-hidden relative">
<div class="absolute top-0 left-0 w-1.5 h-full bg-primary"></div>
<!-- Full View Header -->
<div id="directoryHeader" class="p-5 border-b border-[#f3f0f4] flex justify-between items-center bg-white sticky top-0 z-10 relative">
<h3 class="font-bold text-lg text-[#151118] flex items-center gap-2">
<span class="material-symbols-outlined text-primary">recent_patient</span>
Patient Directory
</h3>
<div class="flex gap-2">
<button onclick="togglePatientDirectory()" class="p-1 hover:bg-[#f3f0f4] rounded text-[#7a6189]" title="Minimize">
<span class="material-symbols-outlined text-lg" id="minimizeIcon">chevron_left</span>
</button>
</div>
</div>
<!-- Minimized View Header (Restore Button) -->
<div id="directoryHeaderMinimized" class="hidden p-3 border-b border-[#f3f0f4] flex justify-center items-center bg-white sticky top-0 z-10 relative">
<button onclick="togglePatientDirectory()" class="p-2 hover:bg-[#f3f0f4] rounded-full text-primary transition-all hover:scale-110" title="Restore Directory">
<span class="material-symbols-outlined text-xl">unfold_more</span>
</button>
</div>
<!-- Full View Content -->
<div id="directoryContentFull" class="flex-1 overflow-y-auto p-3 space-y-2 clinical-scroll">
<?php if (empty($patients)): ?>
<div class="p-6 text-center text-[#7a6189]">
<span class="material-symbols-outlined text-4xl text-slate-300 mb-2">groups</span>
<p class="font-medium">No patients found</p>
<?php if (!empty($search_query)): ?>
<p class="text-sm mt-1">Try adjusting your search</p>
<?php endif; ?>
</div>
<?php else: ?>
<?php foreach ($patients as $patient): 
    $default_photo = getDefaultProfilePhoto($patient['full_name']);
    $patient_id_display = 'P-' . str_pad($patient['patient_id'], 5, '0', STR_PAD_LEFT);
    $is_selected = $selected_patient_id == $patient['patient_id'];
?>
<div class="p-3 rounded-xl <?php echo $is_selected ? 'bg-primary/5 border border-primary/20 shadow-sm' : 'hover:bg-[#f8f9fa] border border-transparent hover:border-[#f3f0f4]'; ?> cursor-pointer relative overflow-hidden group transition-all" onclick="window.location.href='?patient_id=<?php echo $patient['patient_id']; ?>&tab=<?php echo $active_tab; ?>'">
<?php if ($is_selected): ?>
<div class="absolute left-0 top-0 bottom-0 w-1 bg-primary"></div>
<?php endif; ?>
<div class="flex items-center gap-3">
<div class="size-10 rounded-full flex items-center justify-center font-bold text-sm ring-2 ring-primary/20" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;"><?php echo htmlspecialchars($default_photo['initials']); ?></div>
<div class="flex-1 min-w-0">
<div class="flex justify-between items-start">
<h4 class="font-bold text-[#151118] truncate"><?php echo htmlspecialchars($patient['full_name']); ?></h4>
</div>
<p class="text-xs text-[#7a6189]">ID: #<?php echo $patient_id_display; ?></p>
</div>
<div class="flex flex-col items-end">
<span class="material-symbols-outlined text-primary text-lg">chevron_right</span>
</div>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
<!-- Minimized View Content (Profile Pictures Only) -->
<div id="directoryContentMinimized" class="hidden flex-1 overflow-y-auto p-3 clinical-scroll">
<div class="flex flex-col items-center gap-3">
<?php if (empty($patients)): ?>
<div class="flex flex-col items-center justify-center py-8">
<span class="material-symbols-outlined text-3xl text-slate-300 mb-2">groups</span>
</div>
<?php else: ?>
<?php foreach ($patients as $patient): 
    $default_photo = getDefaultProfilePhoto($patient['full_name']);
    $patient_id_display = 'P-' . str_pad($patient['patient_id'], 5, '0', STR_PAD_LEFT);
    $is_selected = $selected_patient_id == $patient['patient_id'];
?>
<div class="relative group/tooltip">
<div class="size-12 rounded-full flex items-center justify-center font-bold text-sm cursor-pointer transition-all hover:scale-110 <?php echo $is_selected ? 'ring-4 ring-primary shadow-lg' : 'ring-2 ring-primary/20 hover:ring-primary/40'; ?>" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;" onclick="window.location.href='?patient_id=<?php echo $patient['patient_id']; ?>&tab=<?php echo $active_tab; ?>'" title="<?php echo htmlspecialchars($patient['full_name']); ?>">
<?php echo htmlspecialchars($default_photo['initials']); ?>
</div>
<!-- Tooltip -->
<div class="absolute left-full ml-3 top-1/2 -translate-y-1/2 bg-[#151118] text-white text-xs font-medium px-3 py-1.5 rounded-lg whitespace-nowrap opacity-0 invisible group-hover/tooltip:opacity-100 group-hover/tooltip:visible transition-all duration-200 pointer-events-none z-50 shadow-lg">
<?php echo htmlspecialchars($patient['full_name']); ?>
<div class="absolute right-full top-1/2 -translate-y-1/2 border-4 border-transparent border-r-[#151118]"></div>
</div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>
</div>
</div>
</div>

<!-- Main Content Area -->
<div id="mainContentArea" class="col-span-12 xl:col-span-8 flex flex-col gap-4 h-full transition-all duration-300">
<?php if (!$selected_patient): ?>
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col items-center justify-center h-full p-12">
<span class="material-symbols-outlined text-6xl text-slate-300 mb-4">folder_open</span>
<h3 class="text-xl font-bold text-[#151118] mb-2">Select a Patient</h3>
<p class="text-[#7a6189] text-center">Choose a patient from the directory to view their medical records</p>
</div>
<?php else: 
    $patient_age = calculateAge($selected_patient['dob']);
    $patient_id_display = 'P-' . str_pad($selected_patient['patient_id'], 5, '0', STR_PAD_LEFT);
    $default_photo = getDefaultProfilePhoto($selected_patient['first_name'] . ' ' . $selected_patient['last_name']);
    $dob_formatted = $selected_patient['dob'] ? date('M d, Y', strtotime($selected_patient['dob'])) : 'N/A';
    $phone_display = $selected_patient['phone_number'] ? '+' . substr($selected_patient['phone_number'], 0, 1) . ' (' . substr($selected_patient['phone_number'], 1, 3) . ') ' . substr($selected_patient['phone_number'], 4, 3) . '-' . substr($selected_patient['phone_number'], 7) : 'N/A';
?>
<!-- Patient Header -->
<div class="bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col relative overflow-hidden shrink-0">
<div class="absolute top-0 left-0 w-1.5 h-full bg-primary"></div>
<div class="p-5 flex flex-wrap justify-between items-center gap-4">
<div class="flex items-center gap-4">
<div class="size-12 rounded-full flex items-center justify-center font-bold text-lg ring-2 ring-[#e2dbe6]" style="background-color: <?php echo $default_photo['bg_color']; ?>; color: <?php echo $default_photo['text_color']; ?>;"><?php echo htmlspecialchars($default_photo['initials']); ?></div>
<div>
<h2 class="text-xl font-black text-[#151118] flex items-center gap-2">
<?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']); ?>
<span class="text-sm font-normal text-[#7a6189]">(<?php echo htmlspecialchars($selected_patient['gender'] ?? 'N/A'); ?>, <?php echo $patient_age ?? 'N/A'; ?>)</span>
</h2>
<div class="flex items-center gap-3 mt-1">
<p class="text-xs text-[#7a6189]">ID: <span class="font-mono text-[#151118]">#<?php echo $patient_id_display; ?></span></p>
<span class="w-1 h-1 rounded-full bg-[#e2dbe6]"></span>
<p class="text-xs text-[#7a6189]">DOB: <span class="text-[#151118]"><?php echo $dob_formatted; ?></span></p>
<span class="w-1 h-1 rounded-full bg-[#e2dbe6]"></span>
<p class="text-xs text-[#7a6189]">Phone: <span class="text-[#151118]"><?php echo htmlspecialchars($phone_display); ?></span></p>
</div>
</div>
</div>
<div class="flex items-center gap-3">
<?php if ($selected_patient['medical_history']): ?>
<div class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-red-50 border border-red-100">
<span class="material-symbols-outlined text-red-500 text-lg">warning</span>
<span class="text-xs font-bold text-red-600">Allergies/History</span>
</div>
<?php endif; ?>
</div>
</div>
<!-- Tabs -->
<div class="px-5 flex gap-6 border-t border-[#f3f0f4] bg-[#fcfcfd]">
<button onclick="switchTab('overview')" class="py-3 text-sm font-medium transition-colors <?php echo $active_tab === 'overview' ? 'font-bold text-primary border-b-2 border-primary' : 'text-[#7a6189] hover:text-[#151118]'; ?>">Overview</button>
<button onclick="switchTab('clinical')" class="py-3 text-sm font-medium transition-colors <?php echo $active_tab === 'clinical' ? 'font-bold text-primary border-b-2 border-primary' : 'text-[#7a6189] hover:text-[#151118]'; ?>">Clinical Notes</button>
<button onclick="switchTab('history')" class="py-3 text-sm font-medium transition-colors <?php echo $active_tab === 'history' ? 'font-bold text-primary border-b-2 border-primary' : 'text-[#7a6189] hover:text-[#151118]'; ?>">Medical History</button>
<button onclick="switchTab('files')" class="py-3 text-sm font-medium transition-colors <?php echo $active_tab === 'files' ? 'font-bold text-primary border-b-2 border-primary' : 'text-[#7a6189] hover:text-[#151118]'; ?>">Files & X-Rays</button>
<button onclick="switchTab('treatment')" class="py-3 text-sm font-medium transition-colors <?php echo $active_tab === 'treatment' ? 'font-bold text-primary border-b-2 border-primary' : 'text-[#7a6189] hover:text-[#151118]'; ?>">Treatment Plan</button>
</div>
</div>

<!-- Tab Content -->
<div class="flex-1 bg-white rounded-2xl border border-[#e2dbe6] shadow-sm flex flex-col overflow-hidden min-h-0">
<?php if ($active_tab === 'overview'): ?>
<!-- Overview Tab -->
<div class="flex-1 flex flex-col min-h-0 overflow-hidden">
<div class="flex flex-1 min-h-0">
<!-- Left Sidebar - Overview Summary -->
<div class="w-80 border-r border-[#f3f0f4] flex flex-col bg-[#fcfcfd] overflow-y-auto clinical-scroll">
<div class="p-6 space-y-6">
<!-- AI Assistant Button -->
<div class="bg-gradient-to-br from-primary/10 to-primary/5 rounded-xl p-4 border border-primary/20">
<button onclick="toggleAIChat()" class="w-full bg-primary hover:bg-primary/90 text-white px-4 py-3 rounded-lg text-sm font-bold flex items-center justify-center gap-2 shadow-lg shadow-primary/20 transition-all hover:scale-[1.02]">
<span class="material-symbols-outlined text-lg">smart_toy</span>
AI Medical Assistant
</button>
<p class="text-xs text-[#7a6189] mt-2 text-center">Get AI-powered insights about this patient</p>
</div>

<!-- Statistics Overview -->
<div class="space-y-4">
<h3 class="text-sm font-bold text-[#151118] flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">insights</span>
Quick Statistics
</h3>

<!-- Appointments Card -->
<div class="bg-white rounded-xl p-4 border border-[#e2dbe6] hover:shadow-sm transition-shadow">
<div class="flex items-center justify-between mb-3">
<h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide">Appointments</h4>
<span class="material-symbols-outlined text-primary text-lg">event</span>
</div>
<div class="space-y-2">
<div class="flex justify-between items-center">
<span class="text-xs text-[#7a6189]">Total</span>
<span class="text-lg font-bold text-[#151118]"><?php echo $overview_stats['total_appointments']; ?></span>
</div>
<div class="flex justify-between items-center">
<span class="text-xs text-[#7a6189]">Completed</span>
<span class="text-sm font-semibold text-green-600"><?php echo $overview_stats['completed_appointments']; ?></span>
</div>
<div class="flex justify-between items-center">
<span class="text-xs text-[#7a6189]">Upcoming</span>
<span class="text-sm font-semibold text-primary"><?php echo $overview_stats['upcoming_appointments']; ?></span>
</div>
</div>
</div>

<!-- Medical Records Card -->
<div class="bg-white rounded-xl p-4 border border-[#e2dbe6] hover:shadow-sm transition-shadow">
<div class="flex items-center justify-between mb-3">
<h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide">Medical Records</h4>
<span class="material-symbols-outlined text-primary text-lg">folder_shared</span>
</div>
<div class="space-y-2">
<div class="flex justify-between items-center">
<span class="text-xs text-[#7a6189]">Total Records</span>
<span class="text-lg font-bold text-[#151118]"><?php echo $overview_stats['total_medical_records']; ?></span>
</div>
<?php if ($overview_stats['latest_record_date']): ?>
<div class="flex flex-col gap-1">
<span class="text-xs text-[#7a6189]">Latest Record</span>
<span class="text-xs font-semibold text-[#151118]"><?php echo $overview_stats['latest_record_date']; ?></span>
</div>
<?php endif; ?>
</div>
</div>

<!-- Files Card -->
<div class="bg-white rounded-xl p-4 border border-[#e2dbe6] hover:shadow-sm transition-shadow">
<div class="flex items-center justify-between mb-3">
<h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide">Files & X-Rays</h4>
<span class="material-symbols-outlined text-primary text-lg">folder</span>
</div>
<div class="flex justify-between items-center">
<span class="text-xs text-[#7a6189]">Total Files</span>
<span class="text-lg font-bold text-[#151118]"><?php echo $overview_stats['total_files']; ?></span>
</div>
</div>

<!-- Treatment Plans Card -->
<div class="bg-white rounded-xl p-4 border border-[#e2dbe6] hover:shadow-sm transition-shadow">
<div class="flex items-center justify-between mb-3">
<h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide">Treatment Plans</h4>
<span class="material-symbols-outlined text-primary text-lg">assignment</span>
</div>
<div class="space-y-2">
<div class="flex justify-between items-center">
<span class="text-xs text-[#7a6189]">Total Plans</span>
<span class="text-lg font-bold text-[#151118]"><?php echo $overview_stats['total_treatment_plans']; ?></span>
</div>
<div class="flex justify-between items-center">
<span class="text-xs text-[#7a6189]">Active</span>
<span class="text-sm font-semibold text-green-600"><?php echo $overview_stats['active_treatment_plans']; ?></span>
</div>
</div>
</div>
</div>

<!-- Quick Actions -->
<div class="pt-4 border-t border-[#e2dbe6]">
<h3 class="text-sm font-bold text-[#151118] mb-3 flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">flash_on</span>
Quick Actions
</h3>
<div class="space-y-2">
<button onclick="switchTab('clinical')" class="w-full text-left px-3 py-2 rounded-lg hover:bg-primary/5 border border-transparent hover:border-primary/20 transition-all flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">edit_note</span>
<span class="text-xs font-medium text-[#151118]">View Clinical Notes</span>
</button>
<button onclick="switchTab('history')" class="w-full text-left px-3 py-2 rounded-lg hover:bg-primary/5 border border-transparent hover:border-primary/20 transition-all flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">history_edu</span>
<span class="text-xs font-medium text-[#151118]">View Medical History</span>
</button>
<button onclick="switchTab('files')" class="w-full text-left px-3 py-2 rounded-lg hover:bg-primary/5 border border-transparent hover:border-primary/20 transition-all flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">folder</span>
<span class="text-xs font-medium text-[#151118]">View Files</span>
</button>
<button onclick="switchTab('treatment'); openTreatmentPlanModal();" class="w-full text-left px-3 py-2 rounded-lg hover:bg-primary/5 border border-transparent hover:border-primary/20 transition-all flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">add</span>
<span class="text-xs font-medium text-[#151118]">Create Treatment Plan</span>
</button>
</div>
</div>

<!-- Latest Diagnosis Preview -->
<?php if ($overview_stats['latest_diagnosis']): ?>
<div class="pt-4 border-t border-[#e2dbe6]">
<h3 class="text-sm font-bold text-[#151118] mb-3 flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">medical_information</span>
Latest Diagnosis
</h3>
<div class="bg-white rounded-lg p-3 border border-[#e2dbe6]">
<p class="text-xs text-[#151118] line-clamp-3"><?php echo htmlspecialchars(substr($overview_stats['latest_diagnosis'], 0, 150)) . (strlen($overview_stats['latest_diagnosis']) > 150 ? '...' : ''); ?></p>
<button onclick="switchTab('clinical')" class="text-xs text-primary font-medium mt-2 hover:underline">View Full Details →</button>
</div>
</div>
<?php endif; ?>

<!-- Recent Appointments Preview -->
<?php if (!empty($patient_appointments)): ?>
<div class="pt-4 border-t border-[#e2dbe6]">
<h3 class="text-sm font-bold text-[#151118] mb-3 flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">schedule</span>
Recent Appointments
</h3>
<div class="space-y-2">
<?php 
$recent_appts = array_slice($patient_appointments, 0, 3);
foreach ($recent_appts as $appt): 
    $appt_date = new DateTime($appt['appointment_date']);
    $status_colors = [
        'Completed' => 'bg-green-100 text-green-700',
        'In-Procedure' => 'bg-blue-100 text-blue-700',
        'Booked' => 'bg-yellow-100 text-yellow-700',
        'Pending' => 'bg-gray-100 text-gray-700',
        'Cancelled' => 'bg-red-100 text-red-700',
    ];
    $status_class = $status_colors[$appt['status']] ?? 'bg-gray-100 text-gray-700';
?>
<div class="bg-white rounded-lg p-3 border border-[#e2dbe6] hover:shadow-sm transition-shadow cursor-pointer" onclick="switchTab('history')">
<div class="flex items-center justify-between mb-1">
<span class="text-xs font-bold text-[#151118]"><?php echo htmlspecialchars($appt['service_name']); ?></span>
<span class="px-2 py-0.5 rounded text-[9px] font-bold <?php echo $status_class; ?>"><?php echo strtoupper($appt['status']); ?></span>
</div>
<p class="text-[10px] text-[#7a6189]"><?php echo $appt_date->format('M d, Y'); ?> at <?php echo date('g:i A', strtotime($appt['time_slot'])); ?></p>
</div>
<?php endforeach; ?>
<?php if (count($patient_appointments) > 3): ?>
<button onclick="switchTab('history')" class="w-full text-xs text-primary font-medium hover:underline mt-1">View All (<?php echo count($patient_appointments); ?>) →</button>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
</div>
</div>

<!-- Right Content Area - Summary Details -->
<div class="flex-1 overflow-y-auto clinical-scroll p-6">
<div class="max-w-4xl mx-auto space-y-6">
<div class="flex items-center justify-between mb-6">
<h2 class="text-xl font-bold text-[#151118] flex items-center gap-2">
<span class="material-symbols-outlined text-primary">dashboard</span>
Patient Overview
</h2>
</div>

<!-- Patient Information Card -->
<div class="bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl p-6 border border-primary/20">
<h3 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
<span class="material-symbols-outlined text-primary">person</span>
Patient Information
</h3>
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
<div>
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Full Name</p>
<p class="text-sm font-semibold text-[#151118]"><?php echo htmlspecialchars($selected_patient['first_name'] . ' ' . $selected_patient['last_name']); ?></p>
</div>
<div>
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Age</p>
<p class="text-sm font-semibold text-[#151118]"><?php echo $patient_age ?? 'N/A'; ?> years old</p>
</div>
<div>
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Gender</p>
<p class="text-sm font-semibold text-[#151118]"><?php echo htmlspecialchars($selected_patient['gender'] ?? 'N/A'); ?></p>
</div>
<div>
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Date of Birth</p>
<p class="text-sm font-semibold text-[#151118]"><?php echo $dob_formatted; ?></p>
</div>
<div>
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Phone Number</p>
<p class="text-sm font-semibold text-[#151118]"><?php echo htmlspecialchars($selected_patient['phone_number'] ?? 'N/A'); ?></p>
</div>
<div>
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Email</p>
<p class="text-sm font-semibold text-[#151118]"><?php echo htmlspecialchars($selected_patient['email'] ?? 'N/A'); ?></p>
</div>
<div>
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Blood Type</p>
<p class="text-sm font-semibold text-[#151118]"><?php echo htmlspecialchars($selected_patient['blood_type'] ?? 'Unknown'); ?></p>
</div>
<?php if ($selected_patient['medical_history']): ?>
<div class="md:col-span-2">
<p class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Medical History / Allergies</p>
<p class="text-sm text-[#151118] bg-red-50 border border-red-100 rounded-lg p-3"><?php echo nl2br(htmlspecialchars($selected_patient['medical_history'])); ?></p>
</div>
<?php endif; ?>
</div>
</div>

<!-- Activity Summary -->
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
<!-- Recent Medical Records -->
<div class="bg-white rounded-xl p-6 border border-[#e2dbe6]">
<h3 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
<span class="material-symbols-outlined text-primary">history_edu</span>
Recent Medical Records
</h3>
<?php if (empty($patient_medical_records)): ?>
<div class="text-center py-8">
<span class="material-symbols-outlined text-4xl text-slate-300 mb-2">note_add</span>
<p class="text-sm text-[#7a6189]">No medical records yet</p>
</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach (array_slice($patient_medical_records, 0, 3) as $record): 
    $record_date = date('M d, Y', strtotime($record['treatment_date']));
?>
<div class="border border-[#e2dbe6] rounded-lg p-3 hover:bg-[#f8f9fa] transition-colors">
<h4 class="text-sm font-bold text-[#151118] mb-1"><?php echo htmlspecialchars($record['service_name']); ?></h4>
<p class="text-xs text-[#7a6189] mb-2"><?php echo $record_date; ?></p>
<?php if ($record['diagnosis']): ?>
<p class="text-xs text-[#151118] line-clamp-2"><?php echo htmlspecialchars(substr($record['diagnosis'], 0, 100)) . (strlen($record['diagnosis']) > 100 ? '...' : ''); ?></p>
<?php endif; ?>
</div>
<?php endforeach; ?>
<button onclick="switchTab('clinical')" class="w-full text-xs text-primary font-medium hover:underline mt-2">View All Records →</button>
</div>
<?php endif; ?>
</div>

<!-- Recent Files -->
<div class="bg-white rounded-xl p-6 border border-[#e2dbe6]">
<h3 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
<span class="material-symbols-outlined text-primary">folder</span>
Recent Files
</h3>
<?php if (empty($patient_files)): ?>
<div class="text-center py-8">
<span class="material-symbols-outlined text-4xl text-slate-300 mb-2">folder_open</span>
<p class="text-sm text-[#7a6189]">No files uploaded yet</p>
</div>
<?php else: ?>
<div class="space-y-2">
<?php foreach (array_slice($patient_files, 0, 4) as $file): 
    $upload_date = date('M d, Y', strtotime($file['uploaded_at']));
    $file_ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
    $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']);
?>
<div class="flex items-center gap-3 border border-[#e2dbe6] rounded-lg p-2 hover:bg-[#f8f9fa] transition-colors">
<?php if ($is_image): ?>
<span class="material-symbols-outlined text-primary">image</span>
<?php else: ?>
<span class="material-symbols-outlined text-primary">description</span>
<?php endif; ?>
<div class="flex-1 min-w-0">
<p class="text-xs font-bold text-[#151118] truncate"><?php echo htmlspecialchars($file['file_name']); ?></p>
<p class="text-[10px] text-[#7a6189]"><?php echo $upload_date; ?></p>
</div>
</div>
<?php endforeach; ?>
<button onclick="switchTab('files')" class="w-full text-xs text-primary font-medium hover:underline mt-2">View All Files →</button>
</div>
<?php endif; ?>
</div>
</div>

<!-- Treatment Plans Summary -->
<?php if (!empty($patient_treatment_plans)): ?>
<div class="bg-white rounded-xl p-6 border border-[#e2dbe6]">
<h3 class="text-lg font-bold text-[#151118] mb-4 flex items-center gap-2">
<span class="material-symbols-outlined text-primary">assignment</span>
Active Treatment Plans
</h3>
<div class="space-y-3">
<?php 
$active_plans = array_filter($patient_treatment_plans, function($plan) {
    return $plan['status'] === 'Active';
});
if (empty($active_plans)) {
    $active_plans = array_slice($patient_treatment_plans, 0, 2);
}
foreach ($active_plans as $plan): 
    $status_colors = [
        'Draft' => 'bg-gray-100 text-gray-700',
        'Active' => 'bg-green-100 text-green-700',
        'Completed' => 'bg-blue-100 text-blue-700',
        'Cancelled' => 'bg-red-100 text-red-700'
    ];
    $status_class = $status_colors[$plan['status']] ?? 'bg-gray-100 text-gray-700';
?>
<div class="border border-[#e2dbe6] rounded-lg p-3 hover:bg-[#f8f9fa] transition-colors">
<div class="flex items-center justify-between mb-2">
<h4 class="text-sm font-bold text-[#151118]"><?php echo htmlspecialchars($plan['plan_name']); ?></h4>
<span class="px-2 py-0.5 rounded text-[9px] font-bold <?php echo $status_class; ?>"><?php echo strtoupper($plan['status']); ?></span>
</div>
<?php if ($plan['description']): ?>
<p class="text-xs text-[#7a6189] line-clamp-2"><?php echo htmlspecialchars(substr($plan['description'], 0, 150)); ?></p>
<?php endif; ?>
</div>
<?php endforeach; ?>
<button onclick="switchTab('treatment')" class="w-full text-xs text-primary font-medium hover:underline mt-2">View All Plans →</button>
</div>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>

<?php elseif ($active_tab === 'clinical'): ?>
<!-- Clinical Notes Tab -->
<div class="px-5 py-3 border-b border-[#f3f0f4] flex justify-between items-center bg-white">
<div class="flex items-center gap-2">
<span class="material-symbols-outlined text-[#7a6189]">edit_note</span>
<h3 class="font-bold text-[#151118] text-sm">Clinical Notes Summary</h3>
</div>
</div>
<div class="flex-1 flex flex-col min-h-0">
<div class="flex-1 p-6 overflow-y-auto clinical-scroll">
<?php if (empty($patient_medical_records)): ?>
<div class="flex items-center justify-center h-full">
<div class="text-center">
<span class="material-symbols-outlined text-6xl text-slate-300 mb-4">note_add</span>
<p class="text-[#7a6189] font-medium">No medical records found for this patient</p>
<p class="text-xs text-[#7a6189] mt-2">Medical records will appear here after appointments are completed</p>
</div>
</div>
<?php else: ?>
<div class="space-y-3">
<?php foreach ($patient_medical_records as $index => $record): 
    $record_date = date('M d, Y', strtotime($record['treatment_date']));
    $record_time = date('g:i A', strtotime($record['treatment_time']));
?>
<div class="border border-[#e2dbe6] rounded-xl overflow-hidden">
<button onclick="toggleRecord(<?php echo $record['record_id']; ?>)" class="w-full p-4 flex items-center justify-between hover:bg-[#f8f9fa] transition-colors text-left">
<div class="flex items-center gap-4 flex-1">
<div class="flex flex-col items-center justify-center w-12 h-10 rounded-lg bg-primary/5 border border-primary/20 shrink-0">
<span class="text-[9px] font-bold text-primary uppercase leading-none"><?php echo date('M', strtotime($record['treatment_date'])); ?></span>
<span class="text-sm font-bold text-[#151118] leading-none mt-0.5"><?php echo date('d', strtotime($record['treatment_date'])); ?></span>
</div>
<div class="flex flex-col flex-1 min-w-0">
<h4 class="font-bold text-[#151118] text-sm"><?php echo htmlspecialchars($record['service_name']); ?></h4>
<div class="flex items-center gap-2 mt-1">
<p class="text-xs text-[#7a6189]"><?php echo $record_date; ?> at <?php echo $record_time; ?></p>
</div>
<?php if ($record['diagnosis']): ?>
<p class="text-xs text-[#7a6189] mt-1 truncate"><strong>Diagnosis:</strong> <?php echo htmlspecialchars(substr($record['diagnosis'], 0, 80)) . (strlen($record['diagnosis']) > 80 ? '...' : ''); ?></p>
<?php endif; ?>
</div>
</div>
<span class="material-symbols-outlined text-[#7a6189] transition-transform record-icon-<?php echo $record['record_id']; ?>">expand_more</span>
</button>
<div id="record-<?php echo $record['record_id']; ?>" class="hidden border-t border-[#e2dbe6] bg-[#f8f9fa]">
<div class="p-4 space-y-4">
<div>
<h5 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-2">Diagnosis</h5>
<p class="text-sm text-[#151118] bg-white p-3 rounded-lg border border-[#e2dbe6]"><?php echo nl2br(htmlspecialchars($record['diagnosis'] ?? 'N/A')); ?></p>
</div>
<?php if ($record['treatment_notes']): ?>
<div>
<h5 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-2">Treatment Notes</h5>
<p class="text-sm text-[#151118] bg-white p-3 rounded-lg border border-[#e2dbe6] whitespace-pre-wrap"><?php echo htmlspecialchars($record['treatment_notes']); ?></p>
</div>
<?php endif; ?>
<?php if ($record['tooth_numbers']): ?>
<div>
<h5 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-2">Tooth Numbers</h5>
<p class="text-sm text-[#151118] bg-white p-3 rounded-lg border border-[#e2dbe6]"><?php echo htmlspecialchars($record['tooth_numbers']); ?></p>
</div>
<?php endif; ?>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
</div>

<?php elseif ($active_tab === 'history'): ?>
<!-- Medical History Tab -->
<div class="px-5 py-3 border-b border-[#f3f0f4] bg-white flex justify-between items-center">
<h3 class="font-bold text-[#151118] text-sm flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">history_edu</span>
Medical History
</h3>
</div>
<div class="flex-1 overflow-y-auto p-4 clinical-scroll">
<?php if (empty($patient_medical_records) && empty($patient_appointments)): ?>
<div class="flex items-center justify-center h-full">
<div class="text-center">
<span class="material-symbols-outlined text-6xl text-slate-300 mb-4">history</span>
<p class="text-[#7a6189] font-medium">No medical history available</p>
</div>
</div>
<?php else: ?>
<div class="space-y-3">
<?php 
// Track which medical records have been matched to avoid duplicates
$matched_record_ids = [];

foreach ($patient_appointments as $appt): 
    $appt_date = new DateTime($appt['appointment_date']);
    $appt_time = date('g:i A', strtotime($appt['time_slot']));
    $appt_time_compare = $appt['time_slot']; // For time comparison
    
    // Find medical record for this appointment - match by service, date, AND time
    $appt_record = null;
    $best_match = null;
    $smallest_time_diff = null;
    
    foreach ($patient_medical_records as $record) {
        // Skip if this record was already matched to another appointment
        if (in_array($record['record_id'], $matched_record_ids)) {
            continue;
        }
        
        $record_date = isset($record['treatment_date']) ? $record['treatment_date'] : date('Y-m-d', strtotime($record['date_of_treatment']));
        $record_time = isset($record['treatment_time']) ? $record['treatment_time'] : date('H:i:s', strtotime($record['date_of_treatment']));
        
        // Check if service and date match
        if ($record['service_id'] == $appt['service_id'] && $record_date == $appt['appointment_date']) {
            // Calculate time difference to find the closest match
            $time_diff = abs(strtotime($record_time) - strtotime($appt_time_compare));
            
            // If times are very close (within 2 hours), consider it a match
            if ($time_diff <= 7200) { // 2 hours in seconds
                if ($best_match === null || $time_diff < $smallest_time_diff) {
                    $best_match = $record;
                    $smallest_time_diff = $time_diff;
                }
            }
        }
    }
    
    // If we found a match, mark it as used
    if ($best_match !== null) {
        $appt_record = $best_match;
        $matched_record_ids[] = $best_match['record_id'];
    }
    
    $status_colors = [
        'Completed' => 'bg-green-100 text-green-700',
        'In-Procedure' => 'bg-blue-100 text-blue-700',
        'Booked' => 'bg-yellow-100 text-yellow-700',
        'Pending' => 'bg-gray-100 text-gray-700',
        'Cancelled' => 'bg-red-100 text-red-700',
        'No-Show' => 'bg-orange-100 text-orange-700'
    ];
    $status_class = $status_colors[$appt['status']] ?? 'bg-gray-100 text-gray-700';
?>
<div class="p-4 rounded-xl border border-[#e2dbe6] hover:bg-[#f8f9fa] transition-colors cursor-pointer" onclick="viewAppointmentDetailsModal(<?php echo $appt['appointment_id']; ?>)">
<div class="flex items-center justify-between">
<div class="flex items-center gap-4">
<div class="flex flex-col items-center justify-center w-12 h-10 rounded-lg bg-primary/5 border border-primary/20 shrink-0">
<span class="text-[9px] font-bold text-primary uppercase leading-none"><?php echo $appt_date->format('M'); ?></span>
<span class="text-sm font-bold text-[#151118] leading-none mt-0.5"><?php echo $appt_date->format('d'); ?></span>
</div>
<div class="flex flex-col">
<h4 class="font-bold text-[#151118] text-sm"><?php echo htmlspecialchars($appt['service_name']); ?></h4>
<div class="flex items-center gap-2 mt-1">
<p class="text-xs text-[#7a6189]"><?php echo $appt_time; ?></p>
<?php if ($appt_record): ?>
<span class="text-[10px] font-mono bg-primary/10 text-primary px-1.5 py-0.5 rounded">Has Notes</span>
<?php endif; ?>
</div>
<?php if ($appt_record && $appt_record['diagnosis']): ?>
<p class="text-xs text-[#7a6189] mt-1 truncate"><strong>Diagnosis:</strong> <?php echo htmlspecialchars(substr($appt_record['diagnosis'], 0, 100)) . (strlen($appt_record['diagnosis']) > 100 ? '...' : ''); ?></p>
<?php endif; ?>
</div>
</div>
<div class="flex items-center gap-3">
<span class="px-2 py-1 rounded text-[10px] font-bold <?php echo $status_class; ?>"><?php echo strtoupper($appt['status']); ?></span>
<span class="material-symbols-outlined text-primary">visibility</span>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<?php elseif ($active_tab === 'files'): ?>
<!-- Files & X-Rays Tab -->
<div class="px-5 py-3 border-b border-[#f3f0f4] bg-white flex justify-between items-center">
<h3 class="font-bold text-[#151118] text-sm flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">folder</span>
Files & X-Rays
</h3>
<button onclick="document.getElementById('fileUploadModal').classList.remove('hidden')" class="bg-primary hover:bg-primary/90 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2">
<span class="material-symbols-outlined text-lg">upload</span>
Upload File
</button>
</div>
<div class="flex-1 overflow-y-auto p-4 clinical-scroll">
<?php if (empty($patient_files)): ?>
<div class="flex items-center justify-center h-full">
<div class="text-center">
<span class="material-symbols-outlined text-6xl text-slate-300 mb-4">folder_open</span>
<p class="text-[#7a6189] font-medium mb-4">No files uploaded yet</p>
<button onclick="document.getElementById('fileUploadModal').classList.remove('hidden')" class="bg-primary hover:bg-primary/90 text-white px-6 py-2 rounded-xl text-sm font-bold">
Upload First File
</button>
</div>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
<?php foreach ($patient_files as $file): 
    $file_ext = strtolower(pathinfo($file['file_name'], PATHINFO_EXTENSION));
    $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png', 'gif']);
    $upload_date = date('M d, Y', strtotime($file['uploaded_at']));
?>
<div class="border border-[#e2dbe6] rounded-xl p-4 hover:bg-[#f8f9fa] transition-colors">
<?php if ($is_image): ?>
<div class="aspect-video bg-[#f3f0f4] rounded-lg mb-3 overflow-hidden">
<img src="../<?php echo htmlspecialchars($file['file_path']); ?>" alt="<?php echo htmlspecialchars($file['file_name']); ?>" class="w-full h-full object-cover"/>
</div>
<?php else: ?>
<div class="aspect-video bg-primary/5 rounded-lg mb-3 flex items-center justify-center">
<span class="material-symbols-outlined text-4xl text-primary">description</span>
</div>
<?php endif; ?>
<h4 class="font-bold text-sm text-[#151118] mb-1 truncate"><?php echo htmlspecialchars($file['file_name']); ?></h4>
<?php if ($file['description']): ?>
<p class="text-xs text-[#7a6189] mb-2"><?php echo htmlspecialchars($file['description']); ?></p>
<?php endif; ?>
<div class="flex items-center justify-between">
<span class="text-xs text-[#7a6189]"><?php echo $upload_date; ?></span>
<div class="flex gap-2">
<a href="../<?php echo htmlspecialchars($file['file_path']); ?>" target="_blank" class="p-1.5 hover:bg-primary/10 rounded text-primary" title="View">
<span class="material-symbols-outlined text-lg">open_in_new</span>
</a>
<button onclick="deleteFile(<?php echo $file['file_id']; ?>)" class="p-1.5 hover:bg-red-50 rounded text-red-500" title="Delete">
<span class="material-symbols-outlined text-lg">delete</span>
</button>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>

<?php elseif ($active_tab === 'treatment'): ?>
<!-- Treatment Plan Tab -->
<div class="px-5 py-3 border-b border-[#f3f0f4] bg-white flex justify-between items-center">
<h3 class="font-bold text-[#151118] text-sm flex items-center gap-2">
<span class="material-symbols-outlined text-primary text-base">assignment</span>
Treatment Plans
</h3>
<button onclick="openTreatmentPlanModal()" class="bg-primary hover:bg-primary/90 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2">
<span class="material-symbols-outlined text-lg">add</span>
New Plan
</button>
</div>
<div class="flex-1 overflow-y-auto p-4 clinical-scroll">
<?php if (empty($patient_treatment_plans)): ?>
<div class="flex items-center justify-center h-full">
<div class="text-center">
<span class="material-symbols-outlined text-6xl text-slate-300 mb-4">assignment</span>
<p class="text-[#7a6189] font-medium mb-4">No treatment plans created yet</p>
<button onclick="openTreatmentPlanModal()" class="bg-primary hover:bg-primary/90 text-white px-6 py-2 rounded-xl text-sm font-bold">
Create First Plan
</button>
</div>
</div>
<?php else: ?>
<div class="space-y-4">
<?php foreach ($patient_treatment_plans as $plan): 
    $status_colors = [
        'Draft' => 'bg-gray-100 text-gray-700',
        'Active' => 'bg-green-100 text-green-700',
        'Completed' => 'bg-blue-100 text-blue-700',
        'Cancelled' => 'bg-red-100 text-red-700'
    ];
    $status_class = $status_colors[$plan['status']] ?? 'bg-gray-100 text-gray-700';
    $created_date = date('M d, Y', strtotime($plan['created_at']));
?>
<div class="p-4 rounded-xl border border-[#e2dbe6] hover:bg-[#f8f9fa] transition-colors">
<div class="flex items-start justify-between mb-2">
<h4 class="font-bold text-[#151118]"><?php echo htmlspecialchars($plan['plan_name']); ?></h4>
<span class="px-2 py-1 rounded text-[10px] font-bold <?php echo $status_class; ?>"><?php echo strtoupper($plan['status']); ?></span>
</div>
<?php if ($plan['description']): ?>
<p class="text-sm text-[#7a6189] mb-2"><?php echo nl2br(htmlspecialchars($plan['description'])); ?></p>
<?php endif; ?>
<div class="flex items-center justify-between mt-3">
<span class="text-xs text-[#7a6189]">Created: <?php echo $created_date; ?></span>
<button onclick="editTreatmentPlan(<?php echo $plan['plan_id']; ?>)" class="text-primary hover:text-primary/80 text-sm font-medium">
Edit
</button>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php endif; ?>
</div>
</div>
</div>
</div>
</main>
</div>

<!-- AI Chat Modal -->
<div id="aiChatModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full h-[85vh] flex flex-col overflow-hidden">
<!-- Fixed Header -->
<div class="px-6 py-4 border-b border-[#e2dbe6] bg-white flex justify-between items-center shrink-0">
<div class="flex items-center gap-3">
<div class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center">
<span class="material-symbols-outlined text-primary">smart_toy</span>
</div>
<div>
<h3 class="text-lg font-bold text-[#151118]">AI Medical Assistant</h3>
<p class="text-xs text-[#7a6189]">Always here to help</p>
</div>
</div>
<button onclick="toggleAIChat()" class="w-8 h-8 flex items-center justify-center rounded-full hover:bg-[#f3f0f4] transition-colors text-[#7a6189] hover:text-[#151118]">
<span class="material-symbols-outlined text-xl">close</span>
</button>
</div>

<!-- Scrollable Messages Area -->
<div id="aiChatMessages" class="flex-1 overflow-y-auto px-4 py-6 space-y-4 clinical-scroll bg-[#fafafa] min-h-0">
<div class="flex items-start gap-3">
<div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0 mt-1">
<span class="material-symbols-outlined text-primary text-base">smart_toy</span>
</div>
<div class="flex-1 max-w-[85%]">
<div class="bg-white rounded-2xl rounded-tl-sm p-4 shadow-sm border border-[#e2dbe6]">
<p class="text-sm text-[#151118] leading-relaxed">Hello! I'm your AI medical assistant. I can help you summarize diagnoses and treatment notes, identify patterns, and answer questions about this patient's medical history. How can I assist you today?</p>
</div>
</div>
</div>
</div>

<!-- Fixed Input Bar at Bottom -->
<div class="bg-white border-t border-[#e2dbe6] shrink-0">
<!-- Quick Action Buttons (Above Input) -->
<div class="px-4 pt-3 pb-2 flex flex-wrap gap-2 overflow-x-auto">
<button onclick="askAI('Summarize all diagnoses')" class="text-xs px-3 py-1.5 bg-primary/10 text-primary rounded-full hover:bg-primary/20 transition-colors whitespace-nowrap">Summarize Diagnoses</button>
<button onclick="askAI('Summarize all treatment notes')" class="text-xs px-3 py-1.5 bg-primary/10 text-primary rounded-full hover:bg-primary/20 transition-colors whitespace-nowrap">Summarize Treatments</button>
<button onclick="askAI('What are the main patterns or trends in this patient\'s medical history?')" class="text-xs px-3 py-1.5 bg-primary/10 text-primary rounded-full hover:bg-primary/20 transition-colors whitespace-nowrap">Identify Patterns</button>
<button onclick="askAI('What are the key concerns or recommendations for this patient?')" class="text-xs px-3 py-1.5 bg-primary/10 text-primary rounded-full hover:bg-primary/20 transition-colors whitespace-nowrap">Key Concerns</button>
</div>
<!-- Input Form -->
<form id="aiChatForm" class="px-4 pb-4 pt-2 flex gap-2 items-end">
<input type="hidden" id="aiPatientId" value="<?php echo $selected_patient_id; ?>"/>
<div class="flex-1 flex items-end gap-2 bg-[#f3f0f4] rounded-2xl px-4 py-2 border border-transparent focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/20 transition-all">
<input type="text" id="aiMessageInput" placeholder="Type your message..." class="flex-1 bg-transparent border-none outline-none text-sm text-[#151118] placeholder:text-[#7a6189] resize-none" autocomplete="off" rows="1"/>
</div>
<button type="submit" id="aiSendBtn" class="w-10 h-10 bg-primary hover:bg-primary/90 text-white rounded-full flex items-center justify-center transition-all shadow-lg shadow-primary/20 hover:scale-105 shrink-0">
<span class="material-symbols-outlined text-xl">send</span>
</button>
</form>
<!-- Clear Chat Button (Optional, bottom right) -->
<div class="px-4 pb-2 flex justify-end">
<button onclick="clearAIConversation()" class="text-xs px-3 py-1 text-[#7a6189] hover:text-[#151118] transition-colors flex items-center gap-1">
<span class="material-symbols-outlined text-sm">refresh</span>
Clear Chat
</button>
</div>
</div>
</div>
</div>

<!-- File Upload Modal -->
<div id="fileUploadModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6">
<div class="flex justify-between items-center mb-4">
<h3 class="text-xl font-bold text-[#151118]">Upload File</h3>
<button onclick="document.getElementById('fileUploadModal').classList.add('hidden')" class="text-[#7a6189] hover:text-[#151118]">
<span class="material-symbols-outlined">close</span>
</button>
</div>
<form id="fileUploadForm" enctype="multipart/form-data" class="space-y-4">
<input type="hidden" name="patient_id" value="<?php echo $selected_patient_id; ?>"/>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">File</label>
<input type="file" name="file" required accept="image/*,application/pdf" class="w-full p-2 border border-[#e2dbe6] rounded-xl"/>
</div>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">File Type</label>
<select name="file_type" class="w-full p-2 border border-[#e2dbe6] rounded-xl">
<option value="xray">X-Ray</option>
<option value="document">Document</option>
<option value="photo">Photo</option>
<option value="other">Other</option>
</select>
</div>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">Description (Optional)</label>
<textarea name="description" rows="3" class="w-full p-2 border border-[#e2dbe6] rounded-xl resize-none"></textarea>
</div>
<div class="flex gap-3 justify-end">
<button type="button" onclick="document.getElementById('fileUploadModal').classList.add('hidden')" class="px-4 py-2 border border-[#e2dbe6] rounded-xl text-sm font-medium hover:bg-[#f8f9fa]">Cancel</button>
<button type="submit" class="px-4 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary/90">Upload</button>
</div>
</form>
</div>
</div>

<!-- Treatment Plan Modal -->
<div id="treatmentPlanModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-6">
<div class="flex justify-between items-center mb-4">
<h3 class="text-xl font-bold text-[#151118]" id="treatmentPlanModalTitle">New Treatment Plan</h3>
<button onclick="closeTreatmentPlanModal()" class="text-[#7a6189] hover:text-[#151118]">
<span class="material-symbols-outlined">close</span>
</button>
</div>
<form id="treatmentPlanForm" method="POST" class="space-y-4">
<input type="hidden" name="save_treatment_plan" value="1"/>
<input type="hidden" name="plan_id" id="plan_id" value=""/>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">Plan Name *</label>
<input type="text" name="plan_name" id="plan_name" required class="w-full p-2 border border-[#e2dbe6] rounded-xl"/>
</div>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">Description</label>
<textarea name="plan_description" id="plan_description" rows="5" class="w-full p-2 border border-[#e2dbe6] rounded-xl resize-none"></textarea>
</div>
<div>
<label class="block text-sm font-bold text-[#151118] mb-2">Status</label>
<select name="plan_status" id="plan_status" class="w-full p-2 border border-[#e2dbe6] rounded-xl">
<option value="Draft">Draft</option>
<option value="Active">Active</option>
<option value="Completed">Completed</option>
<option value="Cancelled">Cancelled</option>
</select>
</div>
<div class="flex gap-3 justify-end">
<button type="button" onclick="closeTreatmentPlanModal()" class="px-4 py-2 border border-[#e2dbe6] rounded-xl text-sm font-medium hover:bg-[#f8f9fa]">Cancel</button>
<button type="submit" class="px-4 py-2 bg-primary text-white rounded-xl text-sm font-bold hover:bg-primary/90">Save Plan</button>
</div>
</form>
</div>
</div>

<!-- Appointment Details Modal -->
<div id="appointmentDetailsModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-xl max-w-3xl w-full max-h-[90vh] flex flex-col overflow-hidden">
<!-- Modal Header -->
<div class="px-6 py-5 border-b border-[#e2dbe6] bg-gradient-to-r from-primary/5 to-primary/10 flex justify-between items-center shrink-0">
<div class="flex items-center gap-3">
<div class="w-12 h-12 rounded-xl bg-primary/10 flex items-center justify-center">
<span class="material-symbols-outlined text-primary text-2xl">event</span>
</div>
<div>
<h3 class="text-xl font-bold text-[#151118]">Appointment Details</h3>
<p class="text-xs text-[#7a6189] mt-0.5">View full appointment and medical record information</p>
</div>
</div>
<button onclick="closeAppointmentDetailsModal()" class="w-10 h-10 flex items-center justify-center rounded-full hover:bg-[#f3f0f4] transition-colors text-[#7a6189] hover:text-[#151118]">
<span class="material-symbols-outlined text-xl">close</span>
</button>
</div>
<!-- Modal Content -->
<div id="appointmentDetailsContent" class="flex-1 overflow-y-auto p-6 space-y-6 clinical-scroll">
<!-- Content will be loaded via AJAX -->
</div>
</div>
</div>

<script>
// Check localStorage for minimization state
let directoryMinimized = localStorage.getItem('patientDirectoryMinimized') === 'true';

// Function to update directory state
function updateDirectoryState(minimized) {
    const directory = document.getElementById('patientDirectory');
    const icon = document.getElementById('minimizeIcon');
    const headerFull = document.getElementById('directoryHeader');
    const headerMinimized = document.getElementById('directoryHeaderMinimized');
    const contentFull = document.getElementById('directoryContentFull');
    const contentMinimized = document.getElementById('directoryContentMinimized');
    const mainContent = document.getElementById('mainContentArea');
    
    if (!directory || !mainContent) return;
    
    if (minimized) {
        // Minimize to profile pictures only
        directory.classList.remove('xl:col-span-4');
        directory.classList.add('xl:col-span-1');
        mainContent.classList.remove('xl:col-span-8');
        mainContent.classList.add('xl:col-span-11');
        if (icon) icon.textContent = 'chevron_right';
        if (headerFull) headerFull.classList.add('hidden');
        if (headerMinimized) headerMinimized.classList.remove('hidden');
        if (contentFull) contentFull.classList.add('hidden');
        if (contentMinimized) contentMinimized.classList.remove('hidden');
    } else {
        // Restore to full view
        directory.classList.remove('xl:col-span-1');
        directory.classList.add('xl:col-span-4');
        mainContent.classList.remove('xl:col-span-11');
        mainContent.classList.add('xl:col-span-8');
        if (icon) icon.textContent = 'chevron_left';
        if (headerFull) headerFull.classList.remove('hidden');
        if (headerMinimized) headerMinimized.classList.add('hidden');
        if (contentFull) contentFull.classList.remove('hidden');
        if (contentMinimized) contentMinimized.classList.add('hidden');
    }
}

function togglePatientDirectory() {
    directoryMinimized = !directoryMinimized;
    localStorage.setItem('patientDirectoryMinimized', directoryMinimized);
    updateDirectoryState(directoryMinimized);
}

// Restore state on page load
document.addEventListener('DOMContentLoaded', function() {
    if (directoryMinimized) {
        updateDirectoryState(true);
    }
});

function switchTab(tab) {
    const patientId = <?php echo $selected_patient_id ? $selected_patient_id : 'null'; ?>;
    if (patientId) {
        window.location.href = `?patient_id=${patientId}&tab=${tab}`;
    }
}

function viewAppointmentDetails(appointmentId) {
    // Load appointment details via AJAX
    fetch(`../api/get_appointment_details.php?appointment_id=${appointmentId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                const content = document.getElementById('appointmentDetailsContent');
                const appt = data.appointment;
                const record = data.record;
                
                // Format status badge
                const statusColors = {
                    'Pending': 'bg-yellow-100 text-yellow-700',
                    'Booked': 'bg-green-100 text-green-700',
                    'In-Procedure': 'bg-blue-100 text-blue-700',
                    'Completed': 'bg-green-100 text-green-700',
                    'Cancelled': 'bg-red-100 text-red-700',
                    'No-Show': 'bg-gray-100 text-gray-700'
                };
                const statusClass = statusColors[appt.status] || 'bg-gray-100 text-gray-700';
                
                content.innerHTML = `
                    <div class="space-y-6">
                        <!-- Appointment Information Card -->
                        <div class="bg-gradient-to-br from-primary/5 to-primary/10 rounded-xl p-6 border border-primary/20">
                            <h3 class="text-lg font-bold text-[#151118] mb-5 flex items-center gap-2">
                                <span class="material-symbols-outlined text-primary text-xl">event</span>
                                Appointment Information
                            </h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div class="bg-white/60 rounded-lg p-3 border border-primary/10">
                                    <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Service</h4>
                                    <p class="text-[#151118] font-semibold text-sm">${escapeHtml(appt.service_name || 'N/A')}</p>
                                </div>
                                <div class="bg-white/60 rounded-lg p-3 border border-primary/10">
                                    <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Date</h4>
                                    <p class="text-[#151118] font-semibold text-sm">${escapeHtml(appt.appointment_date_formatted || 'N/A')}</p>
                                </div>
                                <div class="bg-white/60 rounded-lg p-3 border border-primary/10">
                                    <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Time</h4>
                                    <p class="text-[#151118] font-semibold text-sm">${escapeHtml(appt.time_slot_formatted || 'N/A')}</p>
                                </div>
                                <div class="bg-white/60 rounded-lg p-3 border border-primary/10">
                                    <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Status</h4>
                                    <span class="inline-flex items-center px-3 py-1 rounded-lg text-xs font-bold ${statusClass}">${escapeHtml(appt.status || 'N/A')}</span>
                                </div>
                                ${appt.appointment_type ? `
                                <div class="bg-white/60 rounded-lg p-3 border border-primary/10">
                                    <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Type</h4>
                                    <p class="text-[#151118] font-semibold text-sm">${escapeHtml(appt.appointment_type)}</p>
                                </div>
                                ` : ''}
                                ${appt.doctor_name ? `
                                <div class="bg-white/60 rounded-lg p-3 border border-primary/10">
                                    <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Doctor</h4>
                                    <p class="text-[#151118] font-semibold text-sm">${escapeHtml(appt.doctor_name)}</p>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                        
                        <!-- Medical Record Information -->
                        ${record ? `
                            <div class="bg-white rounded-xl p-6 border border-[#e2dbe6]">
                                <h3 class="text-lg font-bold text-[#151118] mb-5 flex items-center gap-2">
                                    <span class="material-symbols-outlined text-primary text-xl">medical_services</span>
                                    Medical Record
                                </h3>
                                <div class="space-y-4">
                                    ${record.treatment_date_formatted ? `
                                    <div class="bg-[#f8f9fa] rounded-lg p-3 border border-[#e2dbe6]">
                                        <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Treatment Date & Time</h4>
                                        <p class="text-[#151118] font-semibold text-sm">${escapeHtml(record.treatment_date_formatted || 'N/A')} at ${escapeHtml(record.treatment_time_formatted || 'N/A')}</p>
                                    </div>
                                    ` : ''}
                                    ${record.diagnosis ? `
                                    <div>
                                        <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-2">Diagnosis</h4>
                                        <div class="bg-[#f8f9fa] rounded-lg p-4 border border-[#e2dbe6]">
                                            <p class="text-sm text-[#151118] leading-relaxed whitespace-pre-wrap">${escapeHtml(record.diagnosis)}</p>
                                        </div>
                                    </div>
                                    ` : ''}
                                    ${record.treatment_notes ? `
                                        <div>
                                            <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-2">Treatment Notes</h4>
                                            <div class="bg-[#f8f9fa] rounded-lg p-4 border border-[#e2dbe6] max-h-60 overflow-y-auto clinical-scroll">
                                                <p class="text-sm text-[#151118] leading-relaxed whitespace-pre-wrap">${escapeHtml(record.treatment_notes)}</p>
                                            </div>
                                        </div>
                                    ` : ''}
                                    ${record.tooth_numbers ? `
                                        <div class="bg-[#f8f9fa] rounded-lg p-3 border border-[#e2dbe6]">
                                            <h4 class="text-xs font-bold text-[#7a6189] uppercase tracking-wide mb-1">Tooth Numbers</h4>
                                            <p class="text-[#151118] font-semibold text-sm">${escapeHtml(record.tooth_numbers)}</p>
                                        </div>
                                    ` : ''}
                                </div>
                            </div>
                        ` : `
                            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-5">
                                <div class="flex items-center gap-3">
                                    <span class="material-symbols-outlined text-yellow-600 text-xl">info</span>
                                    <div>
                                        <p class="text-yellow-800 font-semibold text-sm">No Medical Record</p>
                                        <p class="text-yellow-700 text-xs mt-0.5">No medical record found for this appointment.</p>
                                    </div>
                                </div>
                            </div>
                        `}
                    </div>
                `;
                document.getElementById('appointmentDetailsModal').classList.remove('hidden');
            } else {
                alert(data.message || 'Failed to load appointment details.');
            }
        })
        .catch(error => {
            console.error('Error loading appointment details:', error);
            alert('Failed to load appointment details. Please try again.');
        });
}

function deleteFile(fileId) {
    if (!confirm('Are you sure you want to delete this file?')) return;
    
    const formData = new FormData();
    formData.append('file_id', fileId);
    
    fetch('../api/delete_patient_file.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert(data.message || 'Failed to delete file.');
        }
    })
    .catch(error => {
        console.error('Error deleting file:', error);
        alert('Failed to delete file.');
    });
}

document.getElementById('fileUploadForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    fetch('../api/upload_patient_file.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            document.getElementById('fileUploadModal').classList.add('hidden');
            location.reload();
        } else {
            alert(data.message || 'Failed to upload file.');
        }
    })
    .catch(error => {
        console.error('Error uploading file:', error);
        alert('Failed to upload file.');
    });
});

function openTreatmentPlanModal(planId = null) {
    const modal = document.getElementById('treatmentPlanModal');
    const form = document.getElementById('treatmentPlanForm');
    const title = document.getElementById('treatmentPlanModalTitle');
    
    if (planId) {
        // Load plan data (you'll need to implement this)
        title.textContent = 'Edit Treatment Plan';
        document.getElementById('plan_id').value = planId;
    } else {
        title.textContent = 'New Treatment Plan';
        form.reset();
        document.getElementById('plan_id').value = '';
    }
    
    modal.classList.remove('hidden');
}

function closeTreatmentPlanModal() {
    document.getElementById('treatmentPlanModal').classList.add('hidden');
    document.getElementById('treatmentPlanForm').reset();
}

function editTreatmentPlan(planId) {
    // Load plan data and open modal
    // For now, just open the modal - you can implement AJAX loading if needed
    openTreatmentPlanModal(planId);
}

function toggleRecord(recordId) {
    const recordContent = document.getElementById('record-' + recordId);
    const icon = document.querySelector('.record-icon-' + recordId);
    
    if (recordContent.classList.contains('hidden')) {
        recordContent.classList.remove('hidden');
        if (icon) icon.style.transform = 'rotate(180deg)';
    } else {
        recordContent.classList.add('hidden');
        if (icon) icon.style.transform = 'rotate(0deg)';
    }
}

function closeAppointmentDetailsModal() {
    document.getElementById('appointmentDetailsModal').classList.add('hidden');
}

function viewAppointmentDetailsModal(appointmentId) {
    // Use the existing viewAppointmentDetails function which loads via AJAX
    viewAppointmentDetails(appointmentId);
}

function askAI(question) {
    document.getElementById('aiMessageInput').value = question;
    document.getElementById('aiChatForm').dispatchEvent(new Event('submit'));
}

function clearAIConversation() {
    if (confirm('Are you sure you want to clear the conversation history?')) {
        conversationHistory = [];
        const chatMessages = document.getElementById('aiChatMessages');
        // Keep only the welcome message
        chatMessages.innerHTML = `
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0 mt-1">
                    <span class="material-symbols-outlined text-primary text-base">smart_toy</span>
                </div>
                <div class="flex-1 max-w-[85%]">
                    <div class="bg-white rounded-2xl rounded-tl-sm p-4 shadow-sm border border-[#e2dbe6]">
                        <p class="text-sm text-[#151118] leading-relaxed">Hello! I'm your AI medical assistant. I can help you summarize diagnoses and treatment notes, identify patterns, and answer questions about this patient's medical history. How can I assist you today?</p>
                    </div>
                </div>
            </div>
        `;
        chatMessages.scrollTop = 0;
    }
}

// Handle Enter key in message input
document.addEventListener('DOMContentLoaded', function() {
    const messageInput = document.getElementById('aiMessageInput');
    if (messageInput) {
        messageInput.addEventListener('keypress', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                document.getElementById('aiChatForm').dispatchEvent(new Event('submit'));
            }
        });
    }
});

// AI Chat functionality - track conversation history
let conversationHistory = [];

const aiChatForm = document.getElementById('aiChatForm');
if (aiChatForm) {
    aiChatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const messageInput = document.getElementById('aiMessageInput');
        const patientIdEl = document.getElementById('aiPatientId');
        const chatMessages = document.getElementById('aiChatMessages');
        const sendBtn = document.getElementById('aiSendBtn');
        
        if (!messageInput || !patientIdEl || !chatMessages || !sendBtn) return;
        
        const message = messageInput.value.trim();
        const patientId = patientIdEl.value;
        
        if (!message || !patientId) return;
    
    // Add user message to chat
    const userMessageDiv = document.createElement('div');
    userMessageDiv.className = 'flex items-start gap-3 justify-end';
    userMessageDiv.innerHTML = `
        <div class="flex-1 max-w-[85%] flex justify-end">
            <div class="bg-primary text-white rounded-2xl rounded-tr-sm p-4 shadow-sm">
                <p class="text-sm leading-relaxed">${escapeHtml(message)}</p>
            </div>
        </div>
        <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center shrink-0 mt-1">
            <span class="material-symbols-outlined text-white text-base">person</span>
        </div>
    `;
    chatMessages.appendChild(userMessageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // Add to conversation history
    conversationHistory.push({ role: 'user', content: message });
    
    // Clear input and disable send button
    messageInput.value = '';
    sendBtn.disabled = true;
    sendBtn.innerHTML = '<span class="material-symbols-outlined text-xl animate-spin">sync</span>';
    
    // Add loading message
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'aiLoading';
    loadingDiv.className = 'flex items-start gap-3';
    loadingDiv.innerHTML = `
        <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0 mt-1">
            <span class="material-symbols-outlined text-primary text-base">smart_toy</span>
        </div>
        <div class="flex-1 max-w-[85%]">
            <div class="bg-white rounded-2xl rounded-tl-sm p-4 shadow-sm border border-[#e2dbe6]">
                <p class="text-sm text-[#151118] leading-relaxed">Thinking...</p>
            </div>
        </div>
    `;
    chatMessages.appendChild(loadingDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    
    // Send request using form POST to same page
    const formData = new FormData();
    formData.append('ai_chat_request', '1');
    formData.append('message', message);
    formData.append('history', JSON.stringify(conversationHistory.slice(0, -1)));
    
    console.log('Sending AI request:', { patient_id: patientId, message_length: message.length });
    
    fetch(window.location.href, {
        method: 'POST',
        body: formData
    })
    .then(response => {
        // Check if response is ok
        if (!response.ok) {
            // Handle 403 Forbidden specifically
            if (response.status === 403) {
                throw new Error('Access forbidden. The API endpoint may be blocked by the hosting provider. Please contact your administrator.');
            }
            return response.json().then(data => {
                throw new Error(data.message || `HTTP ${response.status}: ${response.statusText}`);
            }).catch(() => {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            });
        }
        return response.json();
    })
    .then(data => {
        // Remove loading message
        const loading = document.getElementById('aiLoading');
        if (loading) loading.remove();
        
        if (data.success) {
            // Add AI response to conversation history
            conversationHistory.push({ role: 'assistant', content: data.response });
            
            // Add AI response with formatted markdown
            const aiMessageDiv = document.createElement('div');
            aiMessageDiv.className = 'flex items-start gap-3';
            aiMessageDiv.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center shrink-0 mt-1">
                    <span class="material-symbols-outlined text-primary text-base">smart_toy</span>
                </div>
                <div class="flex-1 max-w-[85%]">
                    <div class="bg-white rounded-2xl rounded-tl-sm p-4 shadow-sm border border-[#e2dbe6]">
                        <div class="text-sm text-[#151118] leading-relaxed prose prose-sm max-w-none">${formatMarkdown(data.response)}</div>
                    </div>
                </div>
            `;
            chatMessages.appendChild(aiMessageDiv);
        } else {
            // Add error message
            const errorDiv = document.createElement('div');
            errorDiv.className = 'flex items-start gap-3';
            errorDiv.innerHTML = `
                <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center shrink-0 mt-1">
                    <span class="material-symbols-outlined text-red-600 text-base">error</span>
                </div>
                <div class="flex-1 max-w-[85%]">
                    <div class="bg-red-50 rounded-2xl rounded-tl-sm p-4 shadow-sm border border-red-200">
                        <p class="text-sm text-red-800 leading-relaxed">${escapeHtml(data.message || data.error || 'Failed to get AI response. Please try again.')}</p>
                    </div>
                </div>
            `;
            chatMessages.appendChild(errorDiv);
        }
        
        chatMessages.scrollTop = chatMessages.scrollHeight;
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<span class="material-symbols-outlined text-xl">send</span>';
        messageInput.focus();
    })
    .catch(error => {
        console.error('Error:', error);
        const loading = document.getElementById('aiLoading');
        if (loading) loading.remove();
        
        let errorMessage = 'Network error. Please check your connection and try again.';
        if (error.message) {
            errorMessage = error.message;
        }
        
        const errorDiv = document.createElement('div');
        errorDiv.className = 'flex items-start gap-3';
        errorDiv.innerHTML = `
            <div class="w-8 h-8 rounded-full bg-red-100 flex items-center justify-center shrink-0 mt-1">
                <span class="material-symbols-outlined text-red-600 text-base">error</span>
            </div>
            <div class="flex-1 max-w-[85%]">
                <div class="bg-red-50 rounded-2xl rounded-tl-sm p-4 shadow-sm border border-red-200">
                    <p class="text-sm text-red-800 leading-relaxed">${escapeHtml(errorMessage)}</p>
                    <p class="text-xs text-red-600 mt-2">If this persists, please check your internet connection or contact support.</p>
                </div>
            </div>
        `;
        chatMessages.appendChild(errorDiv);
        chatMessages.scrollTop = chatMessages.scrollHeight;
        
        sendBtn.disabled = false;
        sendBtn.innerHTML = '<span class="material-symbols-outlined text-xl">send</span>';
    });
    });
}

// Reset conversation when modal is closed
function toggleAIChat() {
    const modal = document.getElementById('aiChatModal');
    if (!modal) return;
    
    if (modal.classList.contains('hidden')) {
        modal.classList.remove('hidden');
        const messageInput = document.getElementById('aiMessageInput');
        if (messageInput) messageInput.focus();
    } else {
        modal.classList.add('hidden');
        // Optionally reset conversation history when closing
        // conversationHistory = [];
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatMarkdown(text) {
    if (!text) return '';
    
    // First escape HTML to prevent XSS
    let html = escapeHtml(text);
    
    // Split into lines for better processing
    let lines = html.split('\n');
    let result = [];
    let inList = false;
    let listItems = [];
    
    for (let i = 0; i < lines.length; i++) {
        let line = lines[i].trim();
        
        // Check if it's a bullet point (starts with *, -, or •)
        if (/^[\*\-\•]\s+/.test(line)) {
            if (!inList) {
                inList = true;
                listItems = [];
            }
            // Remove the bullet and add as list item
            let content = line.replace(/^[\*\-\•]\s+/, '');
            // Process bold text in list items
            content = content.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            listItems.push('<li class="ml-4">' + content + '</li>');
        }
        // Check if it's a numbered list
        else if (/^\d+\.\s+/.test(line)) {
            if (!inList) {
                inList = true;
                listItems = [];
            }
            let content = line.replace(/^\d+\.\s+/, '');
            content = content.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            listItems.push('<li class="ml-4">' + content + '</li>');
        }
        // Regular line
        else {
            // Close any open list
            if (inList) {
                result.push('<ul class="list-disc list-inside my-2 space-y-1 ml-4">' + listItems.join('') + '</ul>');
                inList = false;
                listItems = [];
            }
            
            if (line) {
                // Convert bold **text** to <strong>text</strong>
                line = line.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
                // Convert italic *text* to <em>text</em> (but not if it's part of **)
                line = line.replace(/(?<!\*)\*([^*\n]+?)\*(?!\*)/g, '<em>$1</em>');
                result.push('<p class="mb-2">' + line + '</p>');
            } else {
                result.push('<br>');
            }
        }
    }
    
    // Close any remaining list
    if (inList) {
        result.push('<ul class="list-disc list-inside my-2 space-y-1 ml-4">' + listItems.join('') + '</ul>');
    }
    
    return result.join('');
}
</script>

<script src="../js/notifications.js?v=<?php echo time(); ?>"></script>
</body>
</html>
