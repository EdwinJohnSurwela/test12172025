<?php
// filepath: d:\XAMMP\htdocs\FP_SIA_SAD_WST\php\python-qr-scan.php
/**
 * PHP Proxy for Python QR Scanner API
 * Supports OBS Virtual Camera integration
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('PYTHON_API_HOST', '127.0.0.1');
define('PYTHON_API_PORT', 5000);
define('PYTHON_API_URL', 'http://' . PYTHON_API_HOST . ':' . PYTHON_API_PORT);
define('TIMEOUT_SECONDS', 10);

function checkPythonApi() {
    $socket = @fsockopen(PYTHON_API_HOST, PYTHON_API_PORT, $errno, $errstr, 1);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

function callPythonApi($endpoint, $method = 'GET', $data = null) {
    $url = PYTHON_API_URL . $endpoint;
    
    $options = [
        'http' => [
            'method' => $method,
            'timeout' => TIMEOUT_SECONDS,
            'ignore_errors' => true
        ]
    ];
    
    if ($method === 'POST' && $data !== null) {
        $jsonData = json_encode($data);
        $options['http']['header'] = "Content-Type: application/json\r\nContent-Length: " . strlen($jsonData);
        $options['http']['content'] = $jsonData;
    }
    
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return null;
    }
    
    return json_decode($response, true);
}

$action = isset($_GET['action']) ? $_GET['action'] : 'scan';

switch ($action) {
    case 'health':
        if (!checkPythonApi()) {
            echo json_encode([
                'status' => 'offline',
                'message' => 'Python QR Scanner is not running'
            ]);
            exit;
        }
        $result = callPythonApi('/health');
        echo json_encode($result ?: ['status' => 'error']);
        break;
    
    case 'cameras':
        // List available cameras (including OBS Virtual Camera)
        if (!checkPythonApi()) {
            echo json_encode(['success' => false, 'error' => 'Python API offline']);
            exit;
        }
        $result = callPythonApi('/camera/list');
        echo json_encode($result ?: ['success' => false, 'cameras' => []]);
        break;
    
    case 'camera-start':
        // Start camera capture
        if (!checkPythonApi()) {
            echo json_encode(['success' => false, 'error' => 'Python API offline']);
            exit;
        }
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $result = callPythonApi('/camera/start', 'POST', $input);
        echo json_encode($result ?: ['success' => false]);
        break;
    
    case 'camera-stop':
        // Stop camera capture
        if (!checkPythonApi()) {
            echo json_encode(['success' => false, 'error' => 'Python API offline']);
            exit;
        }
        $result = callPythonApi('/camera/stop', 'POST', []);
        echo json_encode($result ?: ['success' => false]);
        break;
    
    case 'camera-frame':
        // Get current camera frame
        if (!checkPythonApi()) {
            echo json_encode(['success' => false, 'error' => 'Python API offline']);
            exit;
        }
        $result = callPythonApi('/camera/frame');
        echo json_encode($result ?: ['success' => false]);
        break;
    
    case 'camera-scan':
        // Scan QR from camera
        if (!checkPythonApi()) {
            echo json_encode(['success' => false, 'error' => 'Python API offline']);
            exit;
        }
        $result = callPythonApi('/camera/scan');
        echo json_encode($result ?: ['success' => false]);
        break;
    
    case 'codes':
        if (!checkPythonApi()) {
            echo json_encode(['success' => false, 'error' => 'Python API offline']);
            exit;
        }
        $result = callPythonApi('/codes');
        echo json_encode($result ?: ['success' => false]);
        break;
    
    case 'scan':
    default:
        if (!checkPythonApi()) {
            echo json_encode([
                'success' => false,
                'error' => 'Python QR Scanner is not running',
                'offline' => true
            ]);
            exit;
        }
        
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || !isset($data['image'])) {
            echo json_encode(['success' => false, 'error' => 'No image provided']);
            exit;
        }
        
        $result = callPythonApi('/scan', 'POST', ['image' => $data['image']]);
        echo json_encode($result ?: ['success' => false, 'error' => 'Scan failed']);
        break;
}