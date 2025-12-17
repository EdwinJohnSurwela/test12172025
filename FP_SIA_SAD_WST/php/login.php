<?php
require_once 'config.php';

// Determine where to redirect after login
$redirect_target = 'dashboard'; // default to dashboard
if (isset($_SESSION['scanned_book'])) {
    $redirect_target = 'quiz'; // if book scanned, go to quiz
}

// Check for explicit redirect parameter
if (isset($_GET['redirect'])) {
    $redirect_target = $_GET['redirect'] === 'quiz' ? 'quiz' : 'dashboard';
}

// If already logged in, redirect based on user type and target
if (is_logged_in()) {
    switch($_SESSION['user_type']) {
        case 'student':
            if ($redirect_target === 'quiz' && isset($_SESSION['scanned_book'])) {
                header("Location: quiz.php");
            } else {
                header("Location: student.php");
            }
            break;
        case 'teacher':
            header("Location: teacher.php");
            break;
        case 'librarian':
            header("Location: librarian.php");
            break;
        case 'admin':
            header("Location: admin.php");
            break;
    }
    exit();
}

$error = '';
$success = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF Protection
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $error = "Invalid security token. Please try again.";
    } else {
        $username = sanitize_input($_POST['username']);
        $password = $_POST['password'];
        
        // Rate limiting
        if (!check_rate_limit($username, 5, 300)) {
            $error = "Too many failed login attempts. Please try again in 5 minutes.";
            log_failed_attempt($username, 'login_rate_limit');
        } else if (empty($username) || empty($password)) {
            $error = "Please fill in all fields.";
        } else {
            // Query user from database
            $sql = "SELECT user_id, full_name, email, password_hash, user_type, status, student_id, grade_level 
                    FROM users 
                    WHERE (email = ? OR student_id = ?) 
                    LIMIT 1";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ss", $username, $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 1) {
                $user = $result->fetch_assoc();
                
                // Check if account is active
                if ($user['status'] != 'active') {
                    $error = "Your account has been suspended. Please contact the administrator.";
                    log_failed_attempt($username, 'login_suspended');
                } 
                // Verify password
                else if (verify_password($password, $user['password_hash'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $user['user_id'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['user_type'] = $user['user_type'];
                    $_SESSION['student_id'] = $user['student_id'];
                    $_SESSION['grade_level'] = $user['grade_level'];
                    
                    // Regenerate session ID on login
                    session_regenerate_id(true);
                    
                    // Log the login
                    $log_sql = "INSERT INTO system_logs (user_id, action, description, ip_address) 
                               VALUES (?, 'login', 'User logged in', ?)";
                    $log_stmt = $conn->prepare($log_sql);
                    $ip = $_SERVER['REMOTE_ADDR'];
                    $log_stmt->bind_param("is", $user['user_id'], $ip);
                    $log_stmt->execute();
                    
                    // Redirect based on user type
                    switch($user['user_type']) {
                        case 'student':
                            // Check if there's a scanned book waiting for quiz
                            if (isset($_SESSION['scanned_book'])) {
                                header("Location: quiz.php");
                            } else {
                                header("Location: student.php");
                            }
                            break;
                        case 'teacher':
                            header("Location: teacher.php");
                            break;
                        case 'librarian':
                            header("Location: librarian.php");
                            break;
                        case 'admin':
                            header("Location: admin.php");
                            break;
                    }
                    exit();
                } else {
                    $error = "Invalid username or password.";
                    log_failed_attempt($username, 'login_failed');
                }
            } else {
                $error = "Invalid username or password.";
                log_failed_attempt($username, 'login_not_found');
            }
            
            $stmt->close();
        }
    }
}

// Get scanned book info if coming from QR scan
$scanned_book = '';
$has_scanned_book = false;
if (isset($_SESSION['scanned_book'])) {
    $book = $_SESSION['scanned_book'];
    $scanned_book = "Selected Book: " . $book['title'] . " by " . $book['author'];
    $has_scanned_book = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Library Hub Tambo</title>
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <!-- Error Handler -->
    <script src="../js/error-handler.js"></script>
    <style>
        /* Add main gradient variable - DepEd Color Scheme */
        :root {
            /* DepEd Color Scheme */
            --deped-blue: #1a4480;
            --deped-blue-dark: #0d2240;
            --deped-red: #c41230;
            --deped-red-dark: #8b0a1e;
            --main-gradient: linear-gradient(135deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
            /* semi-transparent overlay variant (keeps portal/modal readable) */
            --main-gradient-overlay: linear-gradient(135deg, rgba(26,68,128,0.40) 0%, rgba(13,34,64,0.32) 45%, rgba(196,18,48,0.30) 100%);
            /* Vertical button gradient */
            --btn-gradient-vertical: linear-gradient(180deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
            --btn-green: #28a745;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            /* Apply new gradient */
            background: var(--main-gradient);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 500px;
            margin: 50px auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 16px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .btn {
            background: var(--btn-gradient-vertical);
            color: #ffffff;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700; /* make text bold */
            width: 100%;
            margin-bottom: 10px;
            transition: transform 0.3s, opacity 0.15s;
        }
        .btn:hover {
            transform: translateY(-2px);
            opacity: 0.98;
        }

        .btn-secondary {
            background: var(--btn-green);
            color: #ffffff;
            font-weight: 700;
            border: none;
        }
        .btn-secondary:hover {
            opacity: 0.92;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-info {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
        }

        .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .link:hover {
            text-decoration: underline;
        }

        .text-center {
            text-align: center;
            margin-top: 15px;
        }

        .divider {
            margin: 20px 0;
            text-align: center;
            color: #999;
        }

        /* Ensure header text is readable on gradient */
        .header,
        .header h1,
        .header p,
        .header small {
            color: #ffffff !important;
            font-weight: 700;
            text-shadow: 0 2px 8px rgba(0,0,0,0.25);
        }

        /* Login Method Tabs */
        .login-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .login-tab {
            flex: 1;
            padding: 12px;
            border: 2px solid #e1e1e1;
            background: #f8f9fa;
            border-radius: 10px;
            cursor: pointer;
            text-align: center;
            font-weight: 600;
            transition: all 0.3s ease;
            color: #666;
        }

        .login-tab:hover {
            border-color: #8e2ecc;
            color: #8e2ecc;
        }

        .login-tab.active {
            background: var(--main-gradient);
            border-color: transparent;
            color: white;
        }

        .login-tab i {
            display: block;
            font-size: 24px;
            margin-bottom: 5px;
        }

        .login-method {
            display: none;
        }

        .login-method.active {
            display: block;
        }

        /* QR Scanner Styles */
        .qr-scanner-box {
            background: #f8f9fa;
            border: 2px dashed #ddd;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }

        .qr-reader-container {
            width: 100%;
            max-width: 360px;
            aspect-ratio: 4 / 3;
            margin: 0 auto 15px;
            background: #000;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .qr-reader-container video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .scanner-status {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-weight: 600;
        }

        .scanner-status.ready {
            background: #d4edda;
            color: #155724;
        }

        .scanner-status.scanning {
            background: #fff3cd;
            color: #856404;
        }

        .scanner-status.success {
            background: #d4edda;
            color: #155724;
        }

        .scanner-status.error {
            background: #f8d7da;
            color: #721c24;
        }

        .scanner-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            margin: 5px;
            transition: all 0.3s ease;
        }

        .scanner-btn.primary {
            background: var(--main-gradient);
            color: white;
        }

        .scanner-btn.secondary {
            background: #6c757d;
            color: white;
        }

        .scanner-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .library-card-info {
            background: linear-gradient(135deg, #e8f4fd, #fff);
            border: 1px solid #bee5eb;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
            text-align: left;
        }

        .library-card-info h4 {
            margin: 0 0 10px;
            color: #0c5460;
            font-size: 14px;
        }

        .library-card-info p {
            margin: 5px 0;
            color: #666;
            font-size: 13px;
        }

        .library-card-info .icon {
            font-size: 40px;
            float: left;
            margin-right: 15px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>📚 Library Hub Reading System</h1>
            <p>Smart Reading Engagement & Comprehension Tracking</p>
            <p><small>Tambo, Lipa City</small></p>
        </div>

        <div class="card">
            <h2>🔑 Student Login</h2>
            
            <?php if ($scanned_book): ?>
            <div class="alert alert-info">
                <strong>📖 <?php echo $scanned_book; ?></strong>
                <p style="margin: 5px 0 0; font-size: 13px;">Login to take the quiz for this book.</p>
            </div>
            <?php else: ?>
            <div class="alert alert-info" style="background: #e8f4fd; border-color: #b8daff;">
                <strong>🎓 Student Dashboard</strong>
                <p style="margin: 5px 0 0; font-size: 13px;">Login to view your reading progress and achievements.</p>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="alert alert-danger">
                <strong>❌ Error:</strong> <?php echo $error; ?>
            </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
            <div class="alert alert-success">
                <strong>✅ Success:</strong> <?php echo $success; ?>
            </div>
            <?php endif; ?>

            <!-- Login Method Tabs -->
            <div class="login-tabs">
                <div class="login-tab active" onclick="switchLoginMethod('manual')" id="tab-manual">
                    <span style="font-size: 24px;">⌨️</span>
                    <div>Type Credentials</div>
                </div>
                <div class="login-tab" onclick="switchLoginMethod('qr')" id="tab-qr">
                    <span style="font-size: 24px;">📱</span>
                    <div>Scan Library Card</div>
                </div>
            </div>

            <!-- Manual Login Form -->
            <div id="method-manual" class="login-method active">
                <form method="POST" action="">
                    <?php echo csrf_field(); ?>
                    <div class="form-group">
                        <label>Email or Student ID:</label>
                        <input type="text" name="username" placeholder="Enter your email or student ID" required>
                    </div>
                    <div class="form-group">
                        <label>Password:</label>
                        <input type="password" name="password" placeholder="Enter your password" required>
                    </div>
                    <button type="submit" class="btn">
                        <?php echo $has_scanned_book ? '📝 Login & Start Quiz' : '🔑 Login to Dashboard'; ?>
                    </button>
                </form>
            </div>

            <!-- QR Code Scanner Login -->
            <div id="method-qr" class="login-method">
                <div class="qr-scanner-box">
                    <div class="qr-reader-container" id="qr-reader-login">
                        <div id="qr-video-container"></div>
                    </div>
                    <div class="scanner-status" id="loginScanStatus">
                        Click "Start Scanner" to scan your Library Card
                    </div>
                    <div>
                        <button class="scanner-btn primary" id="startLoginScanner" onclick="startLoginQRScanner()">
                            📷 Start Scanner
                        </button>
                        <button class="scanner-btn secondary" id="stopLoginScanner" onclick="stopLoginQRScanner()" style="display: none;">
                            ⏹️ Stop Scanner
                        </button>
                    </div>
                </div>

                <div class="library-card-info">
                    <span class="icon">🪪</span>
                    <h4>Don't have a Library Card?</h4>
                    <p>Ask the librarian to issue you a Library Card with your personal QR code.</p>
                    <p>Your card contains: <strong>Name, Grade Level, LRN/Student ID</strong></p>
                </div>
            </div>

            <div class="divider">OR</div>

            <a href="signup.php" class="btn btn-secondary" style="display: block; text-align: center; text-decoration: none;">
                Create Student Account
            </a>
            
            <div class="text-center">
                <a href="#" onclick="goToForgotPassword()" class="link">Forgot Password?</a>
            </div>

            <div class="divider">───</div>
            
            <div class="text-center">
                <a href="index.php" class="link">← Back to QR Scanner</a>
            </div>
        </div>
    </div>

    <script>
        // Login method switching
        function switchLoginMethod(method) {
            // Update tabs
            document.querySelectorAll('.login-tab').forEach(tab => tab.classList.remove('active'));
            document.getElementById('tab-' + method).classList.add('active');
            
            // Update content
            document.querySelectorAll('.login-method').forEach(m => m.classList.remove('active'));
            document.getElementById('method-' + method).classList.add('active');
            
            // Stop scanner when switching away from QR
            if (method !== 'qr') {
                stopLoginQRScanner();
            }
        }

        // Go to forgot password with prefilled identifier
        function goToForgotPassword() {
            const usernameInput = document.querySelector('input[name="username"]');
            const identifier = usernameInput ? usernameInput.value.trim() : '';
            const url = identifier ? 'forgot-password.php?identifier=' + encodeURIComponent(identifier) : 'forgot-password.php';
            window.location.href = url;
        }

        // QR Scanner variables
        let loginVideoStream = null;
        let loginVideoElement = null;
        let loginCanvasElement = null;
        let loginScanInterval = null;
        let isLoginScanning = false;
        let isLoginLaunching = false;

        const loginStatusDiv = document.getElementById('loginScanStatus');
        const startLoginBtn = document.getElementById('startLoginScanner');
        const stopLoginBtn = document.getElementById('stopLoginScanner');

        // Check Python API
        async function checkLoginPythonAPI() {
            try {
                const response = await fetch('python-qr-scan.php?action=health');
                const data = await response.json();
                return data.status === 'ok';
            } catch (e) {
                return false;
            }
        }

        // Launch Python scanner if needed
        async function ensureLoginScannerRunning() {
            const apiRunning = await checkLoginPythonAPI();
            if (apiRunning) return true;

            // Try to launch scanner
            loginStatusDiv.textContent = '🚀 Launching QR Scanner...';
            loginStatusDiv.className = 'scanner-status scanning';

            try {
                const response = await fetch('start-python-scanner.php?action=launch');
                const result = await response.json();
                
                if (result.already_running) return true;

                // Wait for API to be ready
                for (let i = 0; i < 30; i++) {
                    const ready = await checkLoginPythonAPI();
                    if (ready) return true;
                    loginStatusDiv.textContent = `⏳ Starting Scanner... (${i + 1}/30)`;
                    await new Promise(r => setTimeout(r, 1000));
                }
            } catch (e) {
                console.error('Failed to launch scanner:', e);
            }
            return false;
        }

        async function startLoginQRScanner() {
            if (isLoginLaunching || isLoginScanning) return;
            
            isLoginLaunching = true;
            startLoginBtn.disabled = true;
            startLoginBtn.textContent = '⏳ Starting...';
            
            // Ensure Python scanner is running
            const scannerReady = await ensureLoginScannerRunning();
            
            if (!scannerReady) {
                loginStatusDiv.textContent = '❌ Scanner failed to start. Run python/start_scanner.bat';
                loginStatusDiv.className = 'scanner-status error';
                isLoginLaunching = false;
                startLoginBtn.disabled = false;
                startLoginBtn.textContent = '📷 Start Scanner';
                
                Swal.fire({
                    icon: 'error',
                    title: 'Scanner Not Available',
                    html: '<p>Please run <code>python/start_scanner.bat</code> first.</p>',
                    confirmButtonText: 'OK'
                });
                return;
            }

            try {
                const container = document.getElementById('qr-video-container');
                container.innerHTML = '';
                
                loginVideoElement = document.createElement('video');
                loginVideoElement.setAttribute('playsinline', '');
                loginVideoElement.style.width = '100%';
                loginVideoElement.style.height = '100%';
                loginVideoElement.style.objectFit = 'cover';
                container.appendChild(loginVideoElement);

                loginCanvasElement = document.createElement('canvas');
                loginCanvasElement.style.display = 'none';

                const constraints = { video: { facingMode: 'environment' } };
                loginVideoStream = await navigator.mediaDevices.getUserMedia(constraints);
                loginVideoElement.srcObject = loginVideoStream;
                // Wait for metadata so videoWidth/videoHeight are available
                await new Promise(resolve => loginVideoElement.addEventListener('loadedmetadata', resolve));
                await loginVideoElement.play();

                // Ensure canvas matches the actual video feed dimensions
                loginCanvasElement.width = loginVideoElement.videoWidth || loginVideoElement.clientWidth || 640;
                loginCanvasElement.height = loginVideoElement.videoHeight || loginVideoElement.clientHeight || Math.round((loginCanvasElement.width * 3) / 4);

                isLoginScanning = true;
                startLoginBtn.style.display = 'none';
                stopLoginBtn.style.display = 'inline-block';
                loginStatusDiv.textContent = '📱 Scanning for Library Card QR...';
                loginStatusDiv.className = 'scanner-status scanning';

                loginScanInterval = setInterval(scanLoginFrame, 500);

            } catch (err) {
                console.error('Camera error:', err);
                loginStatusDiv.textContent = '❌ Camera access denied';
                loginStatusDiv.className = 'scanner-status error';
                
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Please allow camera permissions.',
                    confirmButtonText: 'OK'
                });
            }
            
            isLoginLaunching = false;
            startLoginBtn.disabled = false;
            startLoginBtn.textContent = '📷 Start Scanner';
        }

        async function scanLoginFrame() {
            if (!isLoginScanning || !loginVideoElement || !loginCanvasElement) return;

            try {
                const ctx = loginCanvasElement.getContext('2d');
                ctx.drawImage(loginVideoElement, 0, 0, loginCanvasElement.width, loginCanvasElement.height);
                const imageData = loginCanvasElement.toDataURL('image/jpeg', 0.8);

                const response = await fetch('python-qr-scan.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ image: imageData })
                });

                const result = await response.json();

                if (result.success && result.qr_code) {
                    // Check if it's a library card QR code
                    if (result.qr_code.startsWith('LIBCARD:')) {
                        await handleLibraryCardScan(result.qr_code);
                    } else {
                        loginStatusDiv.textContent = '❌ Not a Library Card QR code';
                        loginStatusDiv.className = 'scanner-status error';
                        setTimeout(() => {
                            loginStatusDiv.textContent = '📱 Scanning for Library Card QR...';
                            loginStatusDiv.className = 'scanner-status scanning';
                        }, 2000);
                    }
                }
            } catch (err) {
                // Silently ignore scan errors
            }
        }

        async function handleLibraryCardScan(qrCode) {
            // Stop scanning
            stopLoginQRScanner();
            
            loginStatusDiv.textContent = '⏳ Verifying Library Card...';
            loginStatusDiv.className = 'scanner-status scanning';

            try {
                const response = await fetch('student-qr-login.php?action=login', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ qr_code: qrCode })
                });

                const result = await response.json();

                if (result.success) {
                    loginStatusDiv.textContent = '✅ ' + result.message;
                    loginStatusDiv.className = 'scanner-status success';
                    
                    Swal.fire({
                        icon: 'success',
                        title: 'Welcome!',
                        html: `<p><strong>${result.user.name}</strong></p>
                               <p>Grade ${result.user.grade_level} | ID: ${result.user.student_id}</p>`,
                        timer: 2000,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = result.redirect;
                    });
                } else {
                    loginStatusDiv.textContent = '❌ ' + result.message;
                    loginStatusDiv.className = 'scanner-status error';
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Login Failed',
                        text: result.message,
                        confirmButtonText: 'Try Again'
                    }).then(() => {
                        loginStatusDiv.textContent = 'Click "Start Scanner" to try again';
                        loginStatusDiv.className = 'scanner-status';
                    });
                }
            } catch (err) {
                console.error('Login error:', err);
                loginStatusDiv.textContent = '❌ Login failed. Please try again.';
                loginStatusDiv.className = 'scanner-status error';
            }
        }

        function stopLoginQRScanner() {
            if (loginScanInterval) {
                clearInterval(loginScanInterval);
                loginScanInterval = null;
            }

            if (loginVideoStream) {
                loginVideoStream.getTracks().forEach(track => track.stop());
                loginVideoStream = null;
            }

            if (loginVideoElement) {
                loginVideoElement.srcObject = null;
            }

            isLoginScanning = false;
            startLoginBtn.style.display = 'inline-block';
            stopLoginBtn.style.display = 'none';
            loginStatusDiv.textContent = 'Scanner stopped';
            loginStatusDiv.className = 'scanner-status';
        }
    </script>
</body>
</html>
