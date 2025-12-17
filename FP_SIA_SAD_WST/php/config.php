<?php
/**
 * =====================================================
 * DATABASE CONFIGURATION
 * Library Hub of Tambo, Lipa City
 * =====================================================
 */

// Start session with proper configuration
if (session_status() === PHP_SESSION_NONE) {
    // Session configuration for better cookie management
    ini_set('session.cookie_lifetime', 0); // Session ends when browser closes
    ini_set('session.gc_maxlifetime', 3600); // 1 hour
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    session_start();
    
    // Clear any error-related session data on new page load
    if (isset($_SESSION['_clear_on_next_load'])) {
        session_unset();
        session_destroy();
        session_start();
        unset($_SESSION['_clear_on_next_load']);
    }
}

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'library_reading_system');

// Create database connection
try {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    // Check connection
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    // Set charset to utf8
    $conn->set_charset("utf8");
    
} catch (Exception $e) {
    die("Database connection error: " . $e->getMessage());
}

// Timezone
date_default_timezone_set('Asia/Manila');

// Security Functions
function sanitize_input($data) {
    global $conn;
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $conn->real_escape_string($data);
}

function sanitize_array($array) {
    $sanitized = [];
    foreach ($array as $key => $value) {
        $sanitized[$key] = is_array($value) ? sanitize_array($value) : sanitize_input($value);
    }
    return $sanitized;
}

function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

// Check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

// Check user type
function check_user_type($allowed_types) {
    if (!is_logged_in()) {
        header("Location: login.php");
        exit();
    }
    
    if (!in_array($_SESSION['user_type'], $allowed_types)) {
        header("Location: unauthorized.php");
        exit();
    }
}

// Logout function
function logout() {
    session_unset();
    session_destroy();
    header("Location: login.php");
    exit();
}

// Generate random token for password reset
function generate_token() {
    return bin2hex(random_bytes(32));
}

// Send email (configure with your SMTP settings)
function send_email($to, $subject, $message) {
    // Basic mail function - configure with PHPMailer for production
    $headers = "From: Library Hub <noreply@libraryhub.com>\r\n";
    $headers .= "Reply-To: noreply@libraryhub.com\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    return mail($to, $subject, $message, $headers);
}

// =====================================================
// ENHANCED ERROR HANDLING & LOGGING
// =====================================================

// Error severity levels
define('ERROR_DEBUG', 0);
define('ERROR_INFO', 1);
define('ERROR_WARNING', 2);
define('ERROR_ERROR', 3);
define('ERROR_CRITICAL', 4);

// Development mode - set to false in production
define('DEV_MODE', true);

// Error log directory
define('LOG_DIR', __DIR__ . '/logs/');

// Initialize error handling
function init_error_handler() {
    // Create logs directory if it doesn't exist
    if (!file_exists(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }
    
    // Set custom error handler
    set_error_handler('custom_error_handler');
    
    // Set exception handler
    set_exception_handler('custom_exception_handler');
    
    // Register shutdown function to catch fatal errors
    register_shutdown_function('shutdown_error_handler');
}

// Custom error handler
function custom_error_handler($errno, $errstr, $errfile, $errline) {
    $error_types = [
        E_ERROR => 'Error',
        E_WARNING => 'Warning',
        E_PARSE => 'Parse Error',
        E_NOTICE => 'Notice',
        E_CORE_ERROR => 'Core Error',
        E_CORE_WARNING => 'Core Warning',
        E_COMPILE_ERROR => 'Compile Error',
        E_COMPILE_WARNING => 'Compile Warning',
        E_USER_ERROR => 'User Error',
        E_USER_WARNING => 'User Warning',
        E_USER_NOTICE => 'User Notice',
        E_STRICT => 'Strict',
        E_RECOVERABLE_ERROR => 'Recoverable Error',
        E_DEPRECATED => 'Deprecated',
        E_USER_DEPRECATED => 'User Deprecated'
    ];
    
    $type = isset($error_types[$errno]) ? $error_types[$errno] : 'Unknown';
    $message = "[$type] $errstr in $errfile on line $errline";
    
    log_error($message, ERROR_ERROR);
    
    // Don't execute PHP internal error handler
    return true;
}

// Custom exception handler
function custom_exception_handler($exception) {
    $message = "Uncaught Exception: " . $exception->getMessage() . 
               " in " . $exception->getFile() . 
               " on line " . $exception->getLine() . 
               "\nStack Trace:\n" . $exception->getTraceAsString();
    
    log_error($message, ERROR_CRITICAL);
    
    // Show user-friendly error page
    if (!DEV_MODE) {
        show_error_page('An unexpected error occurred. Please try again later.');
    } else {
        show_error_page($exception->getMessage(), $exception->getTraceAsString());
    }
}

// Shutdown handler for fatal errors
function shutdown_error_handler() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        $message = "Fatal Error: " . $error['message'] . 
                   " in " . $error['file'] . 
                   " on line " . $error['line'];
        log_error($message, ERROR_CRITICAL);
    }
}

// Enhanced error logging
function log_error($message, $severity = ERROR_ERROR) {
    $severity_labels = [
        ERROR_DEBUG => 'DEBUG',
        ERROR_INFO => 'INFO',
        ERROR_WARNING => 'WARNING',
        ERROR_ERROR => 'ERROR',
        ERROR_CRITICAL => 'CRITICAL'
    ];
    
    $label = isset($severity_labels[$severity]) ? $severity_labels[$severity] : 'UNKNOWN';
    $timestamp = date('Y-m-d H:i:s');
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'CLI';
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'Guest';
    $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'N/A';
    
    $log_message = "[$timestamp] [$label] [IP: $ip] [User: $user_id] [URI: $request_uri]\n$message\n" . str_repeat('-', 80) . "\n";
    
    // Daily log files
    $log_file = LOG_DIR . 'error_' . date('Y-m-d') . '.log';
    file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    
    // Also log critical errors to separate file
    if ($severity >= ERROR_CRITICAL) {
        $critical_log = LOG_DIR . 'critical_errors.log';
        file_put_contents($critical_log, $log_message, FILE_APPEND | LOCK_EX);
    }
}

// Log info messages
function log_info($message) {
    log_error($message, ERROR_INFO);
}

// Log warning messages
function log_warning($message) {
    log_error($message, ERROR_WARNING);
}

// Log debug messages (only in dev mode)
function log_debug($message) {
    if (DEV_MODE) {
        log_error($message, ERROR_DEBUG);
    }
}

// Show user-friendly error page
function show_error_page($message, $details = null) {
    // Clean output buffer
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    $show_details = DEV_MODE && $details !== null;
    
    echo '<!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Error - Library Hub</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            :root {
                --deped-blue: #1a4480;
                --deped-red: #c41230;
            }
            body {
                background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .error-container {
                background: white;
                border-radius: 15px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                padding: 3rem;
                max-width: 600px;
                text-align: center;
            }
            .error-icon {
                font-size: 5rem;
                color: var(--deped-red);
                margin-bottom: 1.5rem;
            }
            .error-title {
                color: var(--deped-blue);
                font-weight: 700;
                margin-bottom: 1rem;
            }
            .btn-primary {
                background: var(--deped-blue);
                border-color: var(--deped-blue);
            }
            .btn-primary:hover {
                background: #0d2240;
                border-color: #0d2240;
            }
            .error-details {
                background: #f8f9fa;
                border-radius: 8px;
                padding: 1rem;
                text-align: left;
                font-family: monospace;
                font-size: 0.85rem;
                max-height: 200px;
                overflow-y: auto;
                margin-top: 1rem;
            }
        </style>
    </head>
    <body>
        <div class="error-container">
            <div class="error-icon">⚠️</div>
            <h2 class="error-title">Oops! Something went wrong</h2>
            <p class="text-muted mb-4">' . htmlspecialchars($message) . '</p>
            ' . ($show_details ? '<div class="error-details"><pre>' . htmlspecialchars($details) . '</pre></div>' : '') . '
            <div class="mt-4">
                <a href="javascript:history.back()" class="btn btn-secondary me-2">Go Back</a>
                <a href="index.php" class="btn btn-primary">Home Page</a>
            </div>
        </div>
    </body>
    </html>';
    exit();
}

// Safe database query wrapper with error handling
function safe_query($conn, $sql, $params = [], $types = '') {
    try {
        $stmt = $conn->prepare($sql);
        
        if ($stmt === false) {
            log_error("SQL Prepare Error: " . $conn->error . " | Query: " . $sql, ERROR_ERROR);
            return ['success' => false, 'error' => 'Database error occurred'];
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        if (!$stmt->execute()) {
            log_error("SQL Execute Error: " . $stmt->error . " | Query: " . $sql, ERROR_ERROR);
            $stmt->close();
            return ['success' => false, 'error' => 'Database error occurred'];
        }
        
        $result = $stmt->get_result();
        $stmt->close();
        
        return ['success' => true, 'result' => $result];
        
    } catch (Exception $e) {
        log_error("Database Exception: " . $e->getMessage() . " | Query: " . $sql, ERROR_CRITICAL);
        return ['success' => false, 'error' => 'Database error occurred'];
    }
}

// Safe single row fetch
function safe_fetch_one($conn, $sql, $params = [], $types = '') {
    $query_result = safe_query($conn, $sql, $params, $types);
    
    if (!$query_result['success']) {
        return $query_result;
    }
    
    $row = $query_result['result']->fetch_assoc();
    return ['success' => true, 'data' => $row];
}

// Safe multiple rows fetch
function safe_fetch_all($conn, $sql, $params = [], $types = '') {
    $query_result = safe_query($conn, $sql, $params, $types);
    
    if (!$query_result['success']) {
        return $query_result;
    }
    
    $rows = $query_result['result']->fetch_all(MYSQLI_ASSOC);
    return ['success' => true, 'data' => $rows];
}

// Validate required fields
function validate_required($data, $required_fields) {
    $errors = [];
    
    foreach ($required_fields as $field => $label) {
        if (!isset($data[$field]) || trim($data[$field]) === '') {
            $errors[] = "$label is required";
        }
    }
    
    return empty($errors) ? ['valid' => true] : ['valid' => false, 'errors' => $errors];
}

// Format validation errors for display
function format_validation_errors($errors) {
    if (empty($errors)) return '';
    
    $html = '<div class="alert alert-danger"><ul class="mb-0">';
    foreach ($errors as $error) {
        $html .= '<li>' . htmlspecialchars($error) . '</li>';
    }
    $html .= '</ul></div>';
    
    return $html;
}

// Initialize error handler
init_error_handler();

// Success response with session clearing on error
function json_response($success, $message, $data = null) {
    // Clean any output buffer
    if (ob_get_level()) {
        ob_clean();
    }
    
    // If error, mark session for clearing on next load
    if (!$success) {
        $_SESSION['_clear_on_next_load'] = true;
    }
    
    // Ensure we're sending JSON
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
    
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if ($data !== null) {
        $response['data'] = $data;
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// =====================================================
// CSRF PROTECTION
// =====================================================
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
}

// =====================================================
// ENCRYPTION & DECRYPTION (AES-256-CBC)
// =====================================================
define('ENCRYPTION_KEY', 'your-32-character-secret-key-here-change-this!!'); // CHANGE IN PRODUCTION
define('ENCRYPTION_METHOD', 'AES-256-CBC');

function encrypt_data($data) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decrypt_data($encrypted_data) {
    list($encrypted, $iv) = explode('::', base64_decode($encrypted_data), 2);
    return openssl_decrypt($encrypted, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
}

// =====================================================
// SESSION SECURITY
// =====================================================
function secure_session_start() {
    if (session_status() === PHP_SESSION_NONE) {
        ini_set('session.cookie_httponly', 1);
        ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
        ini_set('session.use_strict_mode', 1);
        ini_set('session.cookie_samesite', 'Strict');
        session_start();
    }
    
    // Session hijacking protection
    if (!isset($_SESSION['user_ip'])) {
        $_SESSION['user_ip'] = $_SERVER['REMOTE_ADDR'];
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
    } else {
        if ($_SESSION['user_ip'] !== $_SERVER['REMOTE_ADDR'] || 
            $_SESSION['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
            session_unset();
            session_destroy();
            // Only redirect if headers not sent
            if (!headers_sent()) {
                header("Location: login.php?session_hijack=1");
                exit();
            }
        }
    }
    
    // Session regeneration for login - only if headers not sent
    if (isset($_SESSION['user_id']) && !isset($_SESSION['session_regenerated'])) {
        if (!headers_sent()) {
            session_regenerate_id(true);
            $_SESSION['session_regenerated'] = true;
        }
    }
}

// Replace the existing session_start() call
secure_session_start();

// =====================================================
// RATE LIMITING (Brute-Force Protection)
// =====================================================
function check_rate_limit($identifier, $max_attempts = 5, $time_window = 300) {
    global $conn;
    
    $sql = "SELECT COUNT(*) as attempts FROM system_logs 
            WHERE description LIKE CONCAT('%', ?, '%') 
            AND created_at > DATE_SUB(NOW(), INTERVAL ? SECOND)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("si", $identifier, $time_window);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return $row['attempts'] < $max_attempts;
}

function log_failed_attempt($identifier, $action) {
    global $conn;
    
    $ip = $_SERVER['REMOTE_ADDR'];
    $sql = "INSERT INTO system_logs (action, description, ip_address) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $action, $identifier, $ip);
    $stmt->execute();
    $stmt->close();
}

// =====================================================
// FILE UPLOAD SECURITY
// =====================================================
function secure_file_upload($file, $allowed_types = ['image/jpeg', 'image/png', 'application/pdf']) {
    $upload_dir = '../uploads/';
    
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Validate file
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['success' => false, 'message' => 'Invalid file upload'];
    }
    
    // Check upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Upload error: ' . $file['error']];
    }
    
    // File size limit (5MB)
    if ($file['size'] > 5242880) {
        return ['success' => false, 'message' => 'File size exceeds 5MB limit'];
    }
    
    // Check MIME type
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    
    if (!in_array($mime, $allowed_types)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    // Generate secure filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = bin2hex(random_bytes(16)) . '.' . $extension;
    $filepath = $upload_dir . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'message' => 'Failed to save file'];
    }
    
    return ['success' => true, 'filename' => $filename, 'filepath' => $filepath];
}

// =====================================================
// SECURE COOKIE MANAGEMENT
// =====================================================
function set_secure_cookie($name, $value, $days = 30) {
    $encrypted_value = encrypt_data($value);
    
    setcookie(
        $name,
        $encrypted_value,
        [
            'expires' => time() + ($days * 86400),
            'path' => '/',
            'domain' => $_SERVER['HTTP_HOST'],
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Strict'
        ]
    );
}

function get_secure_cookie($name) {
    if (isset($_COOKIE[$name])) {
        return decrypt_data($_COOKIE[$name]);
    }
    return null;
}

// =====================================================
// PASSWORD POLICY VALIDATION
// =====================================================
function validate_password($password) {
    $errors = [];
    
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters";
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Password must contain at least one uppercase letter";
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Password must contain at least one lowercase letter";
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Password must contain at least one number";
    }
    
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
        $errors[] = "Password must contain at least one special character";
    }
    
    return empty($errors) ? ['valid' => true] : ['valid' => false, 'errors' => $errors];
}

// =====================================================
// EMAIL VALIDATION
// =====================================================
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

// XSS PROTECTION
function escape_output($data) {
    return htmlspecialchars($data, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function clean_html($html) {
    return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
}
?>
