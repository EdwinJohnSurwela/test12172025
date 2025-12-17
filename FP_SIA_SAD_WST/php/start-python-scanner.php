<?php
/**
 * PHP Script to Launch Python QR Scanner and OBS
 * Handles starting start_scanner.bat and obs_esp32cam_setup.py
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
define('BAT_FILE_PATH', realpath(__DIR__ . '/../python/start_scanner_tray.bat'));
define('OBS_SETUP_SCRIPT', realpath(__DIR__ . '/../python/obs_esp32cam_setup.py'));
define('OBS_PASSWORD', 'Library_Hub_Tambo_2025');
define('LOCK_FILE', sys_get_temp_dir() . '/library_hub_scanner.lock');
define('OBS_LOCK_FILE', sys_get_temp_dir() . '/library_hub_obs.lock');

/**
 * Check if Python API is running
 */
function isPythonApiRunning() {
    $socket = @fsockopen(PYTHON_API_HOST, PYTHON_API_PORT, $errno, $errstr, 1);
    if ($socket) {
        fclose($socket);
        return true;
    }
    return false;
}

/**
 * Check if a scanner process was recently launched (within last 30 seconds)
 */
function isScannerLaunching() {
    if (file_exists(LOCK_FILE)) {
        $lockTime = filemtime(LOCK_FILE);
        $elapsed = time() - $lockTime;
        // If lock file is older than 30 seconds, consider it stale
        if ($elapsed > 30) {
            @unlink(LOCK_FILE);
            return false;
        }
        return true;
    }
    return false;
}

/**
 * Create lock file to prevent multiple launches
 */
function createLockFile() {
    file_put_contents(LOCK_FILE, date('Y-m-d H:i:s'));
}

/**
 * Remove lock file
 */
function removeLockFile() {
    @unlink(LOCK_FILE);
}

/**
 * Check if OBS is running
 */
function isOBSRunning() {
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $output = [];
        exec('tasklist /FI "IMAGENAME eq obs64.exe" 2>NUL', $output);
        foreach ($output as $line) {
            if (stripos($line, 'obs64.exe') !== false) {
                return true;
            }
        }
    }
    return false;
}

/**
 * Launch OBS and configure for ESP32-CAM
 */
function launchOBS($esp32Ip = '192.168.1.100', $obsPort = 4455) {
    // Check if OBS setup script exists
    if (!OBS_SETUP_SCRIPT || !file_exists(OBS_SETUP_SCRIPT)) {
        return ['success' => false, 'message' => 'OBS setup script not found'];
    }
    
    // Check if OBS lock file exists (already launching)
    if (file_exists(OBS_LOCK_FILE)) {
        $lockTime = filemtime(OBS_LOCK_FILE);
        if (time() - $lockTime < 30) {
            return ['success' => false, 'message' => 'OBS is currently starting...'];
        }
        @unlink(OBS_LOCK_FILE);
    }
    
    // Create OBS lock file
    file_put_contents(OBS_LOCK_FILE, date('Y-m-d H:i:s'));
    
    // Start OBS if not running
    if (!isOBSRunning()) {
        // Try common OBS paths
        $obsPaths = [
            'C:\\Program Files\\obs-studio\\bin\\64bit\\obs64.exe',
            'C:\\Program Files (x86)\\obs-studio\\bin\\64bit\\obs64.exe'
        ];
        
        foreach ($obsPaths as $obsPath) {
            if (file_exists($obsPath)) {
                // Must start OBS from its own directory to find locale files
                $obsDir = dirname($obsPath);
                $command = 'cd /d "' . $obsDir . '" && start "" "obs64.exe" --minimize-to-tray';
                pclose(popen($command, 'r'));
                sleep(5); // Wait for OBS to start and initialize
                break;
            }
        }
    } else {
        // OBS already running, just wait a moment for WebSocket
        sleep(1);
    }
    
    // Run OBS setup script in background
    $scriptDir = dirname(OBS_SETUP_SCRIPT);
    $scriptFile = basename(OBS_SETUP_SCRIPT);
    
    // Run with --no-monitor flag to exit after setup (don't block)
    // Include password if defined
    $passwordArg = defined('OBS_PASSWORD') && OBS_PASSWORD ? ' --obs-password ' . OBS_PASSWORD : '';
    $command = 'cd /d "' . $scriptDir . '" && start /MIN "" pythonw "' . $scriptFile . '" --esp32-ip ' . $esp32Ip . ' --obs-port ' . $obsPort . $passwordArg . ' --no-monitor';
    pclose(popen($command, 'r'));
    
    return ['success' => true, 'message' => 'OBS setup initiated'];
}

/**
 * Check if a Python/Flask process is running on port 5000
 */
function isPythonProcessRunning() {
    // First check if API is responding
    if (isPythonApiRunning()) {
        return true;
    }
    
    // On Windows, check if python.exe is running with port 5000
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $output = [];
        exec('netstat -ano | findstr :5000 | findstr LISTENING', $output);
        return !empty($output);
    }
    
    return false;
}

$action = isset($_GET['action']) ? $_GET['action'] : 'status';

switch ($action) {
    case 'status':
        // Check current status
        $apiRunning = isPythonApiRunning();
        $launching = isScannerLaunching();
        $processRunning = isPythonProcessRunning();
        
        echo json_encode([
            'success' => true,
            'api_running' => $apiRunning,
            'launching' => $launching,
            'process_running' => $processRunning,
            'can_launch' => !$apiRunning && !$launching && !$processRunning
        ]);
        break;
    
    case 'launch':
        // Check if already running
        if (isPythonApiRunning()) {
            echo json_encode([
                'success' => true,
                'message' => 'Scanner is already running',
                'already_running' => true
            ]);
            exit;
        }
        
        // Check if currently launching
        if (isScannerLaunching()) {
            echo json_encode([
                'success' => false,
                'message' => 'Scanner is currently starting up. Please wait...',
                'launching' => true
            ]);
            exit;
        }
        
        // Check if process is running but API not responding yet
        if (isPythonProcessRunning()) {
            echo json_encode([
                'success' => false,
                'message' => 'Scanner process is starting. Please wait...',
                'starting' => true
            ]);
            exit;
        }
        
        // Validate bat file exists
        if (!BAT_FILE_PATH || !file_exists(BAT_FILE_PATH)) {
            echo json_encode([
                'success' => false,
                'message' => 'Scanner batch file not found',
                'path' => BAT_FILE_PATH
            ]);
            exit;
        }
        
        // Create lock file
        createLockFile();
        
        // Launch the batch file
        // Use 'start' command to run minimized in system tray
        $batDir = dirname(BAT_FILE_PATH);
        $batFile = basename(BAT_FILE_PATH);
        
        // Change to the batch file directory and run it minimized
        // The /MIN flag starts the window minimized, then the batch file launches pythonw which runs hidden
        $command = 'cd /d "' . $batDir . '" && start /MIN "" cmd /c "' . $batFile . '"';
        
        // Execute in background on Windows
        pclose(popen($command, 'r'));
        
        // Also launch OBS for ESP32-CAM integration
        $obsResult = launchOBS();
        
        echo json_encode([
            'success' => true,
            'message' => 'Scanner is starting...',
            'launched' => true,
            'obs' => $obsResult
        ]);
        break;
    
    case 'wait':
        // Wait for API to be ready (polling endpoint)
        $maxWait = 15; // Maximum seconds to report waiting
        $apiRunning = isPythonApiRunning();
        
        if ($apiRunning) {
            removeLockFile();
            echo json_encode([
                'success' => true,
                'ready' => true,
                'message' => 'Scanner is ready'
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'ready' => false,
                'message' => 'Scanner is still starting...'
            ]);
        }
        break;
    
    case 'clear-lock':
        // Force clear the lock file (for debugging/reset)
        removeLockFile();
        echo json_encode([
            'success' => true,
            'message' => 'Lock cleared'
        ]);
        break;
    
    default:
        echo json_encode([
            'success' => false,
            'message' => 'Unknown action'
        ]);
}
