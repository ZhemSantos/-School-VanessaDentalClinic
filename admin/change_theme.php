<?php
session_start();
require_once '../includes/security.php';

// Handle theme change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['theme'])) {
    $allowed_themes = ['default', 'purple', 'gold'];
    $new_theme = $_POST['theme'];
    
    if (in_array($new_theme, $allowed_themes)) {
        $_SESSION['theme'] = $new_theme;
        echo json_encode(['success' => true, 'theme' => $new_theme]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Invalid theme']);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid request']);
?>

