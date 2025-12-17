<?php
/**
 * Student QR Code Login Handler
 * Handles login via Library Card QR code scanning
 */

require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/**
 * QR Code Format for Library Cards:
 * LIBCARD:{student_id}:{hash}
 * 
 * The hash is generated using: md5(student_id . secret_key . user_id)
 * This prevents forging QR codes
 */

define('LIBRARY_CARD_SECRET', 'LibraryHub2024SecretKey'); // Change this to a secure key

/**
 * Generate a library card QR code data string
 */
function generateLibraryCardData($student_id, $user_id) {
    $hash = substr(md5($student_id . LIBRARY_CARD_SECRET . $user_id), 0, 12);
    return "LIBCARD:{$student_id}:{$hash}";
}

/**
 * Verify and parse library card QR code data
 */
function verifyLibraryCardData($qr_data) {
    // Check format
    if (!preg_match('/^LIBCARD:(.+):([a-f0-9]{12})$/', $qr_data, $matches)) {
        return ['valid' => false, 'error' => 'Invalid QR code format'];
    }
    
    $student_id = $matches[1];
    $provided_hash = $matches[2];
    
    return [
        'valid' => true,
        'student_id' => $student_id,
        'hash' => $provided_hash
    ];
}

$action = isset($_GET['action']) ? $_GET['action'] : 'login';

switch ($action) {
    case 'login':
        // Handle QR code login
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['qr_code']) || empty($input['qr_code'])) {
            echo json_encode(['success' => false, 'message' => 'No QR code provided']);
            exit;
        }
        
        $qr_data = trim($input['qr_code']);
        
        // Verify QR code format
        $verification = verifyLibraryCardData($qr_data);
        
        if (!$verification['valid']) {
            echo json_encode([
                'success' => false, 
                'message' => $verification['error'],
                'is_library_card' => false
            ]);
            exit;
        }
        
        $student_id = $verification['student_id'];
        
        // Look up student in database
        global $conn;
        $sql = "SELECT user_id, full_name, email, user_type, status, student_id, grade_level 
                FROM users 
                WHERE student_id = ? AND user_type = 'student'
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode([
                'success' => false, 
                'message' => 'Student not found. Please register first.',
                'is_library_card' => true
            ]);
            exit;
        }
        
        $user = $result->fetch_assoc();
        $stmt->close();
        
        // Verify the hash matches
        $expected_hash = substr(md5($student_id . LIBRARY_CARD_SECRET . $user['user_id']), 0, 12);
        if ($verification['hash'] !== $expected_hash) {
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid library card. Please get a new card from the librarian.',
                'is_library_card' => true
            ]);
            exit;
        }
        
        // Check if account is active
        if ($user['status'] !== 'active') {
            echo json_encode([
                'success' => false, 
                'message' => 'Your account has been suspended. Please contact the librarian.',
                'is_library_card' => true
            ]);
            exit;
        }
        
        // Set session variables (login the user)
        session_start();
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['email'] = $user['email'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['student_id'] = $user['student_id'];
        $_SESSION['grade_level'] = $user['grade_level'];
        $_SESSION['login_method'] = 'library_card';
        
        // Regenerate session ID
        session_regenerate_id(true);
        
        // Log the login
        $log_sql = "INSERT INTO system_logs (user_id, action, description, ip_address) 
                   VALUES (?, 'login', 'Student logged in via Library Card QR', ?)";
        $log_stmt = $conn->prepare($log_sql);
        $ip = $_SERVER['REMOTE_ADDR'];
        $log_stmt->bind_param("is", $user['user_id'], $ip);
        $log_stmt->execute();
        
        // Determine redirect
        $redirect = 'student.php';
        if (isset($_SESSION['scanned_book'])) {
            $redirect = 'quiz.php';
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Welcome, ' . $user['full_name'] . '!',
            'redirect' => $redirect,
            'user' => [
                'name' => $user['full_name'],
                'student_id' => $user['student_id'],
                'grade_level' => $user['grade_level']
            ]
        ]);
        break;
    
    case 'verify':
        // Just verify a QR code without logging in
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['qr_code']) || empty($input['qr_code'])) {
            echo json_encode(['success' => false, 'message' => 'No QR code provided']);
            exit;
        }
        
        $qr_data = trim($input['qr_code']);
        
        // Check if it's a library card QR code
        if (strpos($qr_data, 'LIBCARD:') === 0) {
            $verification = verifyLibraryCardData($qr_data);
            echo json_encode([
                'success' => true,
                'is_library_card' => true,
                'valid_format' => $verification['valid'],
                'student_id' => $verification['student_id'] ?? null
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'is_library_card' => false,
                'message' => 'This is not a Library Card QR code'
            ]);
        }
        break;
    
    case 'generate':
        // Generate QR code data for a student (admin/librarian only)
        session_start();
        
        // Check if user is admin or librarian
        if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['admin', 'librarian'])) {
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['student_id']) || empty($input['student_id'])) {
            echo json_encode(['success' => false, 'message' => 'Student ID required']);
            exit;
        }
        
        $student_id = sanitize_input($input['student_id']);
        
        // Get student info
        global $conn;
        $sql = "SELECT user_id, full_name, student_id, grade_level 
                FROM users 
                WHERE student_id = ? AND user_type = 'student'
                LIMIT 1";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            echo json_encode(['success' => false, 'message' => 'Student not found']);
            exit;
        }
        
        $student = $result->fetch_assoc();
        $stmt->close();
        
        // Generate QR code data
        $qr_data = generateLibraryCardData($student['student_id'], $student['user_id']);
        
        echo json_encode([
            'success' => true,
            'qr_data' => $qr_data,
            'student' => [
                'name' => $student['full_name'],
                'student_id' => $student['student_id'],
                'grade_level' => $student['grade_level']
            ]
        ]);
        break;
    
    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action']);
}
