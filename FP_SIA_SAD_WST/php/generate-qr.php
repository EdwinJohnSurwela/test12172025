<?php
// generate-qr.php
// Receives JSON {qr_data, student_id} and runs the Python QR generator to create a PNG
// Returns JSON { success: true, url: '../qr_codes/...png' } on success

session_start();
require_once 'config.php';
header('Content-Type: application/json');

// Only allow admins
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Forbidden']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!$data || empty($data['qr_data']) || empty($data['student_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
    exit;
}

$qr_data = $data['qr_data'];
$student_id = preg_replace('/[^A-Za-z0-9-_]/', '', $data['student_id']);
if ($student_id === '') $student_id = 'unknown';

$filename = 'qr_' . $student_id . '_' . time() . '.png';
$webPath = '../qr_codes/' . $filename; // relative to this PHP file for browser
$fullPath = __DIR__ . '/../qr_codes/' . $filename; // absolute filesystem path

// Ensure directory exists
$dir = dirname($fullPath);
if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true)) {
        echo json_encode(['success' => false, 'error' => 'Failed to create qr_codes directory']);
        exit;
    }
}

// Try several python executables to maximize compatibility on Windows
$pythonCandidates = ['python', 'python3', 'py'];
$script = __DIR__ . '/../python/generate_qr.py';
$generated = false;
$lastOutput = null;

foreach ($pythonCandidates as $py) {
    $cmd = escapeshellcmd($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($qr_data) . ' ' . escapeshellarg($fullPath) . ' 2>&1';
    exec($cmd, $output, $ret);
    $lastOutput = implode("\n", $output);
    if ($ret === 0 && file_exists($fullPath)) {
        $generated = true;
        break;
    }
    // reset output for next candidate
    $output = [];
}

if ($generated) {
    echo json_encode(['success' => true, 'url' => $webPath]);
    exit;
} else {
    error_log('QR generation failed: ' . $lastOutput);
    echo json_encode(['success' => false, 'error' => 'QR generation failed', 'details' => $lastOutput]);
    exit;
}
