<?php
/**
 * Security Helper Functions
 * Centralized security functions for the application
 */

/**
 * Generate CSRF token
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email format
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (basic validation)
 */
function validatePhone($phone) {
    // Remove all non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $phone);
    // Check if it's between 10-15 digits
    return strlen($phone) >= 10 && strlen($phone) <= 15;
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

/**
 * Check if user has specific role
 */
function hasRole($requiredRole) {
    return isLoggedIn() && $_SESSION['role'] === $requiredRole;
}

/**
 * Require login - redirect if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ../index.php');
        exit;
    }
}

/**
 * Require specific role - redirect if doesn't have role
 */
function requireRole($requiredRole) {
    requireLogin();
    if (!hasRole($requiredRole)) {
        header('Location: ../index.php?error=unauthorized');
        exit;
    }
}

/**
 * Rate limiting for login attempts
 */
function checkLoginAttempts($username) {
    $max_attempts = 5;
    $lockout_time = 900; // 15 minutes
    
    $attempts_key = 'login_attempts_' . md5($username);
    $lockout_key = 'login_lockout_' . md5($username);
    
    // Check if account is locked out
    if (isset($_SESSION[$lockout_key])) {
        $lockout_until = $_SESSION[$lockout_key];
        if (time() < $lockout_until) {
            $remaining = ceil(($lockout_until - time()) / 60);
            return ['locked' => true, 'remaining_minutes' => $remaining];
        } else {
            // Lockout expired, clear it
            unset($_SESSION[$lockout_key]);
            unset($_SESSION[$attempts_key]);
        }
    }
    
    return ['locked' => false];
}

/**
 * Record failed login attempt
 */
function recordFailedLogin($username) {
    $max_attempts = 5;
    $lockout_time = 900; // 15 minutes
    
    $attempts_key = 'login_attempts_' . md5($username);
    $lockout_key = 'login_lockout_' . md5($username);
    
    if (!isset($_SESSION[$attempts_key])) {
        $_SESSION[$attempts_key] = 0;
    }
    
    $_SESSION[$attempts_key]++;
    
    if ($_SESSION[$attempts_key] >= $max_attempts) {
        $_SESSION[$lockout_key] = time() + $lockout_time;
        unset($_SESSION[$attempts_key]);
    }
}

/**
 * Clear login attempts on successful login
 */
function clearLoginAttempts($username) {
    $attempts_key = 'login_attempts_' . md5($username);
    $lockout_key = 'login_lockout_' . md5($username);
    unset($_SESSION[$attempts_key]);
    unset($_SESSION[$lockout_key]);
}

/**
 * Secure session configuration
 */
function configureSecureSession() {
    // Set secure session parameters
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', 0); // Set to 1 if using HTTPS
    ini_set('session.cookie_samesite', 'Strict');
    
    // Regenerate session ID periodically
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
    } else if (time() - $_SESSION['created'] > 1800) {
        // Regenerate session ID every 30 minutes
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
}

/**
 * Update user's last activity timestamp
 * Call this on every page load to track active users
 */
function updateUserActivity() {
    global $pdo;
    
    if (!isLoggedIn() || !isset($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        // Update last_active timestamp
        // First, check if column exists, if not we'll add it via migration
        $stmt = $pdo->prepare("UPDATE tbl_user SET last_active = NOW() WHERE user_id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return true;
    } catch (PDOException $e) {
        // Column might not exist yet, silently fail (will be added via migration)
        error_log('Activity update failed (column may not exist): ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if user is currently active (within last 5 minutes)
 */
function isUserActive($last_active_timestamp) {
    if (!$last_active_timestamp) {
        return false;
    }
    
    $last_active = strtotime($last_active_timestamp);
    $now = time();
    $inactive_threshold = 600; // 10 minutes in seconds (increased from 5 to be more accurate)
    
    return ($now - $last_active) <= $inactive_threshold;
}
?>

