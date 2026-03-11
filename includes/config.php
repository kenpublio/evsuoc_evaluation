<?php
// ============================================
// EVSU EVALUATION SYSTEM - CONFIGURATION FILE
// ============================================

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// DATABASE CONFIGURATION
// ============================================
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'evsu_evaluation');

// Create database connection
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to UTF-8
$conn->set_charset("utf8mb4");

// ============================================
// APPLICATION CONFIGURATION
// ============================================
define('APP_NAME', 'EVSU Evaluation System');
define('APP_VERSION', '1.0.0');
define('APP_ENVIRONMENT', 'development'); // development or production

// Base URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$base_url = $protocol . $host . str_replace(basename($script_name), '', $script_name);
define('BASE_URL', $base_url);

// ============================================
// SECURITY CONFIGURATION
// ============================================
define('MAX_LOGIN_ATTEMPTS', 5);        // Max failed login attempts
define('LOCKOUT_TIME', 900);              // Lockout time in seconds (15 minutes)
define('SESSION_TIMEOUT', 3600);          // Session timeout in seconds (1 hour)
define('PASSWORD_MIN_LENGTH', 6);         // Minimum password length
define('BCRYPT_COST', 12);                 // Bcrypt cost factor

// ============================================
// DATE AND TIME CONFIGURATION
// ============================================
define('TIMEZONE', 'Asia/Manila');
date_default_timezone_set(TIMEZONE);

// ============================================
// FILE UPLOAD CONFIGURATION
// ============================================
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_EXTENSIONS', ['jpg', 'jpeg', 'png', 'gif', 'pdf']);
define('UPLOAD_PATH', __DIR__ . '/uploads/');

// ============================================
// ERROR REPORTING
// ============================================
if (APP_ENVIRONMENT === 'development') {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
    ini_set('display_startup_errors', 0);
}

// ============================================
// SESSION SECURITY
// ============================================

// Regenerate session ID periodically to prevent fixation
if (!isset($_SESSION['CREATED'])) {
    $_SESSION['CREATED'] = time();
} else if (time() - $_SESSION['CREATED'] > 1800) { // 30 minutes
    session_regenerate_id(true);
    $_SESSION['CREATED'] = time();
}

// Check session timeout
if (isset($_SESSION['LAST_ACTIVITY']) && (time() - $_SESSION['LAST_ACTIVITY'] > SESSION_TIMEOUT)) {
    // Session expired
    session_unset();
    session_destroy();
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['error'] = "Your session has expired. Please login again.";
    header('Location: ' . BASE_URL . 'login.php');
    exit();
}
$_SESSION['LAST_ACTIVITY'] = time();

// ============================================
// HELPER FUNCTIONS
// ============================================

/**
 * Get database connection
 * @return mysqli
 */
function getDB() {
    global $conn;
    return $conn;
}

/**
 * Check if user is logged in
 * @return bool
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

/**
 * Check if user has specific role
 * @param string $role
 * @return bool
 */
function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] === $role;
}

/**
 * Require login to access page
 */
function requireLogin() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . BASE_URL . 'login.php');
        exit();
    }
}

/**
 * Require specific role to access page
 * @param string $role
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        header('HTTP/1.0 403 Forbidden');
        die('Access denied. You do not have permission to access this page.');
    }
}

/**
 * Log user activity
 * @param int $user_id
 * @param string $action
 * @return bool
 */
function logActivity($user_id, $action) {
    $conn = getDB();
    $ip = $_SERVER['REMOTE_ADDR'];
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    $stmt = $conn->prepare("INSERT INTO user_logs (user_id, action, ip_address, user_agent) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("isss", $user_id, $action, $ip, $user_agent);
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

/**
 * Get current user's full name
 * @return string|null
 */
function getCurrentUserName() {
    return $_SESSION['fullname'] ?? null;
}

/**
 * Get current user's role
 * @return string|null
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}

/**
 * Get current user's ID
 * @return int|null
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Redirect to a URL with optional message
 * @param string $url
 * @param string $message
 * @param string $type
 */
function redirect($url, $message = null, $type = 'info') {
    if ($message) {
        $_SESSION[$type] = $message;
    }
    header('Location: ' . BASE_URL . $url);
    exit();
}

/**
 * Display flash messages
 */
function displayFlashMessages() {
    $types = ['success', 'error', 'warning', 'info'];
    foreach ($types as $type) {
        if (isset($_SESSION[$type])) {
            echo '<div class="alert alert-' . $type . '">' . htmlspecialchars($_SESSION[$type]) . '</div>';
            unset($_SESSION[$type]);
        }
    }
}

/**
 * Sanitize input data
 * @param string $data
 * @return string
 */
function sanitize($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Generate CSRF token
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Check if user has already evaluated an office today
 * @param int $user_id
 * @param int $office_id
 * @return bool
 */
function hasUserEvaluated($user_id, $office_id) {
    $conn = getDB();
    $today = date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT id FROM responses 
        WHERE user_id = ? AND office_id = ? AND DATE(submitted_at) = ?
    ");
    $stmt->bind_param("iis", $user_id, $office_id, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    $exists = $result->num_rows > 0;
    $stmt->close();
    
    return $exists;
}

/**
 * Get user by ID
 * @param int $user_id
 * @return array|null
 */
function getUserById($user_id) {
    $conn = getDB();
    $stmt = $conn->prepare("SELECT id, username, fullname, email, role, student_id, is_active, created_at, last_login FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    return $user;
}

/**
 * Get office by ID
 * @param int $office_id
 * @return array|null
 */
function getOfficeById($office_id) {
    $conn = getDB();
    $stmt = $conn->prepare("SELECT * FROM offices WHERE id = ?");
    $stmt->bind_param("i", $office_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $office = $result->fetch_assoc();
    $stmt->close();
    return $office;
}

/**
 * Get all active offices
 * @return mysqli_result
 */
function getActiveOffices() {
    $conn = getDB();
    return $conn->query("SELECT * FROM offices WHERE status = 'active' ORDER BY name");
}

/**
 * Get questions for an office
 * @param int $office_id
 * @return mysqli_result
 */
function getOfficeQuestions($office_id) {
    $conn = getDB();
    $stmt = $conn->prepare("
        SELECT * FROM survey_questions 
        WHERE office_id = ? AND is_active = 1 
        ORDER BY display_order, id
    ");
    $stmt->bind_param("i", $office_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();
    return $result;
}

/**
 * Format date
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDate($date, $format = 'F j, Y, g:i a') {
    return date($format, strtotime($date));
}

/**
 * Truncate text
 * @param string $text
 * @param int $length
 * @param string $suffix
 * @return string
 */
function truncateText($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

// Create upload directory if it doesn't exist
if (!file_exists(UPLOAD_PATH)) {
    mkdir(UPLOAD_PATH, 0755, true);
}

// ============================================
// AUTO-LOGOUT FOR INACTIVE USERS
// ============================================
if (isLoggedIn() && isset($_SESSION['user_id'])) {
    // You can add additional checks here
}
?>