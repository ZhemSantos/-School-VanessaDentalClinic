<?php
session_start();
require_once '../db.php';
require_once '../includes/security.php';

// Configure secure session
configureSecureSession();

// Require receptionist role
requireRole('Receptionist');

header('Content-Type: application/json');

// Get search and filter parameters
$search_query = sanitizeInput($_GET['search'] ?? '');
$filter_type = sanitizeInput($_GET['filter_type'] ?? 'all');
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20; // More results per page for modal
$offset = ($page - 1) * $per_page;

// Build query
$where_conditions = [];
$params = [];


// Search filter
if (!empty($search_query)) {
    switch ($filter_type) {
        case 'name':
            $where_conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ?)";
            $search_param = '%' . $search_query . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            break;
        case 'phone':
            $where_conditions[] = "p.phone_number LIKE ?";
            $params[] = '%' . $search_query . '%';
            break;
        case 'email':
            $where_conditions[] = "p.email LIKE ?";
            $params[] = '%' . $search_query . '%';
            break;
        case 'id':
            $where_conditions[] = "p.patient_id = ?";
            $params[] = intval($search_query);
            break;
        default: // 'all'
            $where_conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name, ' ', p.last_name) LIKE ? OR p.phone_number LIKE ? OR p.email LIKE ? OR p.patient_id = ?)";
            $search_param = '%' . $search_query . '%';
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = $search_param;
            $params[] = is_numeric($search_query) ? intval($search_query) : 0;
            break;
    }
}

// Age Filter (Min Age)
$min_age = isset($_GET['min_age']) ? intval($_GET['min_age']) : null;
if ($min_age !== null) {
    $where_conditions[] = "p.age >= ?";
    $params[] = $min_age;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';


// Get total count
$count_sql = "SELECT COUNT(*) FROM tbl_patient p $where_clause";
try {
    $count_stmt = $pdo->prepare($count_sql);
    if (!empty($params)) {
        $count_stmt->execute($params);
    } else {
        $count_stmt->execute();
    }
    $total_patients = $count_stmt->fetchColumn();
} catch (PDOException $e) {
    error_log('Error counting patients: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
    exit;
}

// Get patients with pagination
try {
    $sql = "
        SELECT p.*
        FROM tbl_patient p
        $where_clause
        ORDER BY p.registration_date DESC
        LIMIT $per_page OFFSET $offset
    ";
    
    $stmt = $pdo->prepare($sql);
    if (!empty($params)) {
        $stmt->execute($params);
    } else {
        $stmt->execute();
    }
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_pages = ceil($total_patients / $per_page);
    
    echo json_encode([
        'success' => true,
        'patients' => $patients,
        'pagination' => [
            'current_page' => $page,
            'total_pages' => $total_pages,
            'total_patients' => $total_patients,
            'per_page' => $per_page
        ]
    ]);
} catch (PDOException $e) {
    error_log('Error fetching patients: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>

