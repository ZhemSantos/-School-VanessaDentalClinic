<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require Dentist role
requireRole('Dentist');

// Set CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$doctor_id = $_SESSION['user_id'] ?? null;

// Helper function
function calculateAge($dob) {
    if (!$dob) return null;
    $birth = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birth)->y;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);
$patient_id = isset($input['patient_id']) ? intval($input['patient_id']) : null;
$message = trim($input['message'] ?? '');
$conversation_history = isset($input['history']) ? $input['history'] : [];

if (!$doctor_id || !$patient_id || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

// Verify patient is assigned to this doctor
try {
    $check_stmt = $pdo->prepare("
        SELECT COUNT(*) as count FROM tbl_appointment 
        WHERE patient_id = ? AND doctor_id = ?
        UNION ALL
        SELECT COUNT(*) as count FROM tbl_medical_record 
        WHERE patient_id = ? AND doctor_id = ?
    ");
    $check_stmt->execute([$patient_id, $doctor_id, $patient_id, $doctor_id]);
    $results = $check_stmt->fetchAll();
    $total = ($results[0]['count'] ?? 0) + ($results[1]['count'] ?? 0);
    
    if ($total == 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Patient not assigned to this doctor']);
        exit;
    }
} catch (PDOException $e) {
    error_log('Error checking patient assignment: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
}

// Fetch patient information and medical records for context
try {
    // Get patient info
    $patient_stmt = $pdo->prepare("
        SELECT p.*, CONCAT(p.first_name, ' ', p.last_name) as full_name
        FROM tbl_patient p
        WHERE p.patient_id = ?
    ");
    $patient_stmt->execute([$patient_id]);
    $patient = $patient_stmt->fetch();
    
    // Get all medical records with service names
    $records_stmt = $pdo->prepare("
        SELECT mr.*, s.service_name,
               DATE(mr.date_of_treatment) as treatment_date,
               TIME(mr.date_of_treatment) as treatment_time
        FROM tbl_medical_record mr
        INNER JOIN tbl_service s ON mr.service_id = s.service_id
        WHERE mr.patient_id = ? AND mr.doctor_id = ?
        ORDER BY mr.date_of_treatment DESC
    ");
    $records_stmt->execute([$patient_id, $doctor_id]);
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
    $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=' . $api_key;
    
    // Build conversation history with system context
    $system_prompt = "You are a helpful AI assistant for a dental clinic. You have access to the following patient's medical records. Use this information to help answer questions, summarize diagnoses and treatments, and provide insights. Be concise and professional.\n\n" . $context . "\n\n";
    
    // Build contents array for Gemini API
    $contents = [];
    
    // Add system context and conversation history as user messages
    $full_conversation = $system_prompt;
    
    // Add conversation history if available
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
    
    // Add current message
    $full_conversation .= "\n\nUser: " . $message;
    
    // Prepare request payload - Gemini API expects a simple structure
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
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    // SSL verification - set to false for local development if needed
    // In production, these should be true for security
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Set to true in production
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false); // Set to 2 in production
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    // Check for curl errors
    if ($response === false || !empty($curl_error)) {
        error_log('Gemini API curl error: ' . $curl_error);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Connection error: ' . ($curl_error ?: 'Unable to connect to AI service. Please check your internet connection.'),
            'error' => $curl_error
        ]);
        exit;
    }
    
    // Check HTTP status code
    if ($http_code !== 200) {
        $error_details = '';
        $response_array = json_decode($response, true);
        if (isset($response_array['error']['message'])) {
            $error_details = $response_array['error']['message'];
        } elseif (isset($response_array['error'])) {
            $error_details = is_string($response_array['error']) ? $response_array['error'] : json_encode($response_array['error']);
        }
        
        error_log('Gemini API error: HTTP ' . $http_code . ' - ' . $response);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'AI service error (HTTP ' . $http_code . '): ' . ($error_details ?: 'Please try again.'),
            'error' => $error_details ?: $response
        ]);
        exit;
    }
    
    $response_data = json_decode($response, true);
    
    // Check if response is valid JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('Gemini API JSON decode error: ' . json_last_error_msg() . ' - Response: ' . substr($response, 0, 500));
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid response from AI service. Please try again.',
            'error' => 'JSON decode error'
        ]);
        exit;
    }
    
    // Check if response has expected structure
    if (!isset($response_data['candidates']) || !isset($response_data['candidates'][0])) {
        error_log('Gemini API unexpected response structure: ' . json_encode($response_data));
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Unexpected response from AI service. Please try again.',
            'error' => 'Invalid response structure'
        ]);
        exit;
    }
    
    if (!isset($response_data['candidates'][0]['content']['parts'][0]['text'])) {
        error_log('Gemini API unexpected response: ' . json_encode($response_data));
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'AI service returned an empty response. Please try again.',
            'error' => 'No text in response'
        ]);
        exit;
    }
    
    $ai_response = $response_data['candidates'][0]['content']['parts'][0]['text'];
    
    echo json_encode([
        'success' => true,
        'response' => $ai_response
    ]);
    
} catch (PDOException $e) {
    error_log('Error fetching patient data: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
} catch (Exception $e) {
    error_log('Error with AI service: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'AI service error: ' . $e->getMessage()]);
}

