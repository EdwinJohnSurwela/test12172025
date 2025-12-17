<?php
require_once 'config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['forgot_password'])) {
    $email = sanitize_input($_POST['email']);

    if (empty($email)) {
        $error = "Please enter your registered email.";
    } else {
        $sql = "SELECT user_id, full_name, email FROM users WHERE email = ? LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();

            $token = bin2hex(random_bytes(32));
            $expires = date("Y-m-d H:i:s", strtotime("+1 hour"));

            $insert = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) 
                                      VALUES (?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE token=?, expires_at=?");
            $insert->bind_param("sssss", $email, $token, $expires, $token, $expires);
            $insert->execute();

            $reset_link = "http://localhost/FP_SIA_SAD_WST/php/reset-password.php?token=" . $token;
            $success = "A password reset link has been sent to your email: 
                        <strong>{$email}</strong><br>
                        <small>(For testing: <a href='$reset_link' target='_blank'>Click here to reset now</a>)</small>";
        } else {
            $error = "No account found with that email.";
        }
        $stmt->close();
    }
}

// Check if accessing via non-localhost IP
$isLocalhost = ($_SERVER['HTTP_HOST'] === 'localhost' || 
                $_SERVER['HTTP_HOST'] === '127.0.0.1' ||
                strpos($_SERVER['HTTP_HOST'], 'localhost:') === 0);
$showNetworkWarning = !$isLocalhost && $_SERVER['REQUEST_SCHEME'] !== 'https';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Scanner - Library Hub Tambo</title>

<?php
// Locate favicon in common locations and set a web-friendly URL (relative to this file's URL).
$favicon_url = null;
$candidates = [
    // prefer images/library_hub_logo.png as requested
    __DIR__ . '/../images/library_hub_logo.png'    => '../images/library_hub_logo.png',
    __DIR__ . '/../assets/library_hub_favicon.png' => '../assets/library_hub_favicon.png',
    __DIR__ . '/../assets/favicon-32x32.png'      => '../assets/favicon-32x32.png',
    __DIR__ . '/assets/library_hub_favicon.png'  => 'assets/library_hub_favicon.png',
    __DIR__ . '/assets/favicon-32x32.png'        => 'assets/favicon-32x32.png',
];

foreach ($candidates as $fs => $url) {
    if (file_exists($fs)) {
        $favicon_url = $url;
        break;
    }
}

if (!$favicon_url) {
    // Fallback: simple inline SVG (guaranteed to render as favicon)
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">
      <defs>
        <linearGradient id="g" x1="0" x2="1" y1="0" y2="1">
          <stop offset="0" stop-color="#5b16a1"/>
          <stop offset="0.6" stop-color="#8e2ecc"/>
          <stop offset="1" stop-color="#ff7a3d"/>
        </linearGradient>
      </defs>
      <rect width="64" height="64" rx="10" fill="url(#g)"/>
      <rect x="10" y="12" width="44" height="40" rx="4" fill="#fff"/>
      <rect x="14" y="18" width="36" height="4" rx="2" fill="#8e2ecc"/>
      <rect x="14" y="26" width="36" height="4" rx="2" fill="#8e2ecc"/>
      <rect x="14" y="34" width="28" height="4" rx="2" fill="#8e2ecc"/>
    </svg>';
    $favicon_url = 'data:image/svg+xml;base64,' . base64_encode($svg);
}

// Output favicon link tags (browser will use first supported)
?>
<link rel="icon" href="<?php echo htmlspecialchars($favicon_url, ENT_QUOTES); ?>" sizes="any">
<link rel="shortcut icon" href="<?php echo htmlspecialchars($favicon_url, ENT_QUOTES); ?>">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($favicon_url, ENT_QUOTES); ?>">
<meta name="theme-color" content="#5b16a1">

    <!-- Remove the html5-qrcode CDN and local fallback scripts, replace with: -->
    <script>
        // Python QR Scanner - no external library needed
        console.log('Using Python QR Scanner API');
    </script>
    
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- SweetAlert2 - keep existing -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11" onerror="loadLocalSweetAlert()"></script>
    <script>
        function loadLocalSweetAlert() {
            console.log('SweetAlert2 CDN failed, alerts will use native dialogs');
            window.Swal = {
                fire: function(options) {
                    var message = options.title || '';
                    if (options.html) message += '\n' + options.html.replace(/<[^>]*>/g, '');
                    if (options.text) message += '\n' + options.text;
                    alert(message);
                    return Promise.resolve({ isConfirmed: true });
                }
            };
        }
    </script>
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
            /* semi-transparent overlay variant for portal/modal overlays */
            --main-gradient-overlay: linear-gradient(135deg, rgba(26,68,128,0.40) 0%, rgba(13,34,64,0.32) 45%, rgba(196,18,48,0.30) 100%);
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
            padding: 0;
            overflow-x: hidden;
        }

        /* Scroll Progress Bar */
        .scroll-progress-bar {
            position: fixed;
            top: 0;
            left: 0;
            width: 0%;
            height: 4px;
            background: linear-gradient(90deg, #ff7a3d 0%, #8e2ecc 50%, #5b16a1 100%);
            z-index: 10001;
            transition: width 0.1s ease;
            box-shadow: 0 2px 10px rgba(255, 122, 61, 0.5);
        }

        /* Navigation Bar with Pixel Art Brick Texture */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            width: 100%;
            z-index: 1000;
            /* Match Login Modal brown (saddle-brown) */
            background: rgba(139, 69, 19, 0.95);
            box-shadow: 
                inset 0 2px 0 rgba(255, 255, 255, 0.12),
                inset 0 -2px 0 rgba(0, 0, 0, 0.15),
                0 12px 40px rgba(0, 0, 0, 0.45);
            transform: translateZ(0);
            will-change: box-shadow, transform;
            padding: 12px 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            transition: all 0.3s ease;
            border-bottom: 3px solid rgba(101, 67, 33, 1);
        }

        .navbar.scrolled {
            padding: 10px 20px;
            box-shadow: 
                inset 0 2px 0 rgba(255, 255, 255, 0.08),
                inset 0 -2px 0 rgba(0, 0, 0, 0.35),
                0 18px 60px rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(6px);
            /* Slightly darker when scrolled to match hover tone from login modal */
            background: rgba(101, 67, 33, 1);
        }

        /* Align navbar content with page container - use grid so center column aligns with .container center */
        .navbar > .container {
            display: grid;
            grid-template-columns: auto 1fr auto;
            align-items: center;
            gap: 12px;
            padding: 0 20px; /* keep same horizontal spacing as original navbar */
        }

        .nav-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: #fff;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            margin-left: 0; /* align with container edge */
            grid-column: 1;
            justify-self: start;
        }

        /* Ensure navbar buttons and links are uppercase */
        .nav-btn,
        .nav-links a {
            text-transform: uppercase;
            letter-spacing: 0.6px;
        }


        .nav-logo {
            width: 68px; /* slightly smaller */
            height: 68px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.30s cubic-bezier(.68,-0.55,.27,1.55);
            box-shadow: 0 8px 26px rgba(224, 168, 0, 0.22), inset 0 2px 8px rgba(255, 235, 170, 0.08);
            /* Circular gradient using DepEd gold */
            background: linear-gradient(135deg, var(--deped-gold) 0%, #e0a800 100%);
            padding: 3px;
            overflow: visible;
            margin-right: 12px;
        }

        .nav-logo img {
            width: 120%;
            height: 120%;
            object-fit: cover;
            border-radius: 50%;
            display: block;
            background: transparent;
            transform: scale(1.06) translateY(-1%);
            filter: drop-shadow(0 6px 14px rgba(0,0,0,0.42));
        }

        .nav-logo:hover {
            transform: rotate(360deg) scale(1.1);
            box-shadow: 0 6px 25px rgba(0, 0, 0, 0.5);
        }

        .nav-brand-text {
            font-weight: 800;
            font-size: 1.15em;
            color: #fff;
            text-shadow: 2px 2px 0 #654321, 3px 3px 6px rgba(0, 0, 0, 0.5);
            background: hsla(28, 40%, 54%, 1.00); /* lighter mocha color */
            padding: 6px 10px;
            border-radius: 10px;
            display: inline-block;
            box-shadow: 0 6px 18px rgba(13,34,64,0.28);
            backdrop-filter: blur(4px);
        }

        .nav-links {
            display: flex;
            align-items: center;
            gap: 8px;
            grid-column: 2;
            justify-self: center; /* center the button group in the middle column */
            transform: translateX(-60px); /* shift left to align with hero text */
        }

        .nav-login-wrapper {
            grid-column: 3;
            justify-self: end;
        }

        .nav-btn {
            padding: 10px 18px;
            border: 2px solid rgba(255, 255, 255, 0.18);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.25s ease;
            background: #B8875B; /* lighter mocha color */
            color: #fff;
            text-decoration: none;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.6);
            box-shadow: 0 6px 18px rgba(13,34,64,0.18), inset 0 1px 0 rgba(255,255,255,0.04);
            position: relative;
        }

        .nav-btn:hover {
            background: rgba(160, 82, 45, 0.8);
            border-color: rgba(255, 255, 255, 0.5);
            transform: translateY(-2px);
            box-shadow: 
                inset 0 1px 0 rgba(255, 255, 255, 0.3),
                inset 0 -1px 0 rgba(0, 0, 0, 0.3),
                0 4px 12px rgba(0, 0, 0, 0.3);
        }

        .nav-btn.active {
            background: linear-gradient(135deg, #5b16a1 0%, #8e2ecc 100%);
            color: white;
            border-color: rgba(255, 255, 255, 0.6);
            box-shadow: 
                inset 0 1px 0 rgba(255, 255, 255, 0.3),
                0 4px 15px rgba(91, 22, 161, 0.5);
        }

        .nav-btn.active:hover {
            transform: translateY(-2px);
            box-shadow: 
                inset 0 1px 0 rgba(255, 255, 255, 0.3),
                0 6px 20px rgba(91, 22, 161, 0.6);
        }

        .nav-hamburger {
            display: none;
            flex-direction: column;
            gap: 5px;
            cursor: pointer;
            padding: 5px;
        }

        .nav-hamburger span {
            width: 25px;
            height: 3px;
            background: #fff;
            border-radius: 2px;
            transition: all 0.3s ease;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        }

        /* Mobile only elements - hidden on desktop */
        .mobile-only {
            display: none;
        }

        /* Dropdown styles */
        .nav-dropdown {
            position: relative;
        }

        .dropdown-content {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            background: rgba(139, 69, 19, 0.95);
            min-width: 200px;
            box-shadow: 0 8px 16px rgba(0,0,0,0.3);
            border-radius: 8px;
            margin-top: 8px;
            z-index: 1001;
            border: 2px solid rgba(255, 255, 255, 0.3);
        }

        .nav-dropdown:hover .dropdown-content,
        .nav-dropdown.active .dropdown-content {
            display: block;
        }

        .dropdown-content a,
        .dropdown-content button {
            color: #fff;
            padding: 12px 16px;
            text-decoration: none;
            display: block;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.5);
            background: none;
            border: none;
            width: 100%;
            text-align: left;
            cursor: pointer;
        }

        .dropdown-content a:first-child,
        .dropdown-content button:first-child {
            border-radius: 6px 6px 0 0;
        }

        .dropdown-content a:last-child,
        .dropdown-content button:last-child {
            border-radius: 0 0 6px 6px;
        }

        .dropdown-content a:hover,
        .dropdown-content button:hover {
            background: rgba(160, 82, 45, 0.8);
            transform: translateX(5px);
        }

        .nav-btn.dropdown-toggle::after {
            content: ' ▼';
            font-size: 10px;
            margin-left: 5px;
        }

        .nav-dropdown.active .nav-btn.dropdown-toggle::after {
            content: ' ▲';
        }

        @media (max-width: 900px) {
            body {
                /* Removed padding-top: 60px; */
            }

            .nav-links {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: rgba(139, 69, 19, 0.95);
                flex-direction: column;
                padding: 15px;
                box-shadow: 0 10px 30px rgba(0,0,0,0.4);
                display: none;
                gap: 5px;
                border-bottom: 3px solid #654321;
            }

            .nav-links.show {
                display: flex;
            }

            .nav-btn {
                width: 100%;
                text-align: center;
            }

            .nav-login-btn {
                order: -1;
                margin-left: 0 !important;
                margin-bottom: 10px;
            }

            .nav-login-wrapper {
                display: none;
            }

            .mobile-only {
                display: block !important;
            }

            .nav-links.show .nav-login-btn {
                display: block;
                width: 100%;
            }

            .nav-hamburger {
                display: flex;
            }
        }

        /* Boot Animation Styles */
        #bootLoader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            /* Apply new gradient */
            background: var(--main-gradient);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            opacity: 1;
            transition: opacity 0.8s ease-out;
        }

        #bootLoader.fade-out {
            opacity: 0;
            pointer-events: none;
        }

        .boot-logo {
            position: relative;
            width: 200px;
            height: 200px;
            margin-bottom: 30px;
        }

        .boot-icon {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 100px;
            height: 100px;
            animation: iconPulse 2s ease-in-out infinite;
        }

        .boot-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .spinner-ring {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: 6px solid transparent;
            border-top-color: rgba(255, 255, 255, 0.8);
            border-right-color: rgba(255, 255, 255, 0.6);
            border-radius: 50%;
            animation: spin 1.5s linear infinite;
        }

        .spinner-ring:nth-child(2) {
            border-top-color: rgba(255, 255, 255, 0.6);
            border-right-color: rgba(255, 255, 255, 0.4);
            animation: spin 2s linear infinite reverse;
            width: 85%;
            height: 85%;
            top: 7.5%;
            left: 7.5%;
        }

        .spinner-ring:nth-child(3) {
            border-top-color: rgba(255, 255, 255, 0.4);
            border-right-color: rgba(255, 255, 255, 0.2);
            animation: spin 2.5s linear infinite;
            width: 70%;
            height: 70%;
            top: 15%;
            left: 15%;
        }

        .boot-text {
            color: white;
            font-size: 28px;
            font-weight: bold;
            text-align: center;
            animation: textFade 1.5s ease-in-out infinite;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.3);
        }

        .boot-subtitle {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            margin-top: 10px;
            text-align: center;
            animation: textFade 1.5s ease-in-out infinite 0.3s;
        }

        .loading-dots {
            display: inline-block;
            width: 40px;
            text-align: left;
        }

        .loading-dots::after {
            content: '';
            animation: dots 1.5s steps(4, end) infinite;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
                filter: blur(0px);
            }
            50% {
                filter: blur(2px);
            }
            100% {
                transform: rotate(360deg);
                filter: blur(0px);
            }
        }

        @keyframes iconPulse {
            0%, 100% {
                transform: translate(-50%, -50%) scale(1);
                filter: drop-shadow(0 0 20px rgba(255, 255, 255, 0.5));
            }
            50% {
                transform: translate(-50%, -50%) scale(1.1);
                filter: drop-shadow(0 0 30px rgba(255, 255, 255, 0.8));
            }
        }

        @keyframes textFade {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.7;
            }
        }

        @keyframes dots {
            0%, 20% { content: ''; }
            40% { content: '.'; }
            60% { content: '..'; }
            80%, 100% { content: '...'; }
        }

        /* Main Content - Initially Hidden */
        #mainContent {
            opacity: 0;
            transform: scale(0.95);
            transition: opacity 0.8s ease-out, transform 0.8s ease-out;
        }

        #mainContent.show {
            opacity: 1;
            transform: scale(1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            /* Apply new gradient */
            background: var(--main-gradient);
            min-height: 100vh;
            padding: 0;
            overflow-x: hidden;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 15px; /* Add minimal side padding for mobile */
        }

        /* Parallax Hero Section */
        .parallax-hero {
            position: relative;
            height: 100vh;
            min-height: 600px;
            background-image: url('../images/Library_Hub_bg.jpg');
            background-attachment: fixed;
            background-position: center;
            background-repeat: no-repeat;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }

        .parallax-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            text-align: center;
            color: white;
            padding: 20px;
            animation: fadeInUp 1s ease-out;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .hero-content h1 {
            font-size: 4em;
            font-weight: 900;
            margin-bottom: 20px;
            text-shadow: 3px 3px 6px rgba(0, 0, 0, 0.7);
            letter-spacing: 2px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .hero-content .subtitle {
            font-size: 2em;
            font-weight: 300;
            margin-bottom: 10px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.7);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .hero-content .location {
            font-size: 1.5em;
            font-weight: 400;
            opacity: 0.9;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.7);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Mobile parallax fix */
        @media (max-width: 768px) {
            .parallax-hero {
                background-attachment: scroll;
                min-height: 500px;
            }

            .hero-content h1 {
                font-size: 2.5em;
            }

            .hero-content .subtitle {
                font-size: 1.5em;
            }

            .hero-content .location {
                font-size: 1.2em;
            }
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

        .header p {
            font-size: 1.2em;
            opacity: 0.9;
        }

        .card {
            background: white;
            border-radius: 15px;
            padding: 20px; /* Reduced from 30px */
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }

        .qr-scanner {
            text-align: center;
            padding: 30px; /* Reduced from 40px */
            background: #f8f9fa;
            border-radius: 10px;
        }

        .qr-placeholder {
            width: 100%;
            max-width: 400px;
            height: 300px;
            border: 4px solid #ffffff;
            border-radius: 20px;
            margin: 20px auto;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
            background: #000;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.2);
            transition: all 0.3s ease;
        }

        .qr-placeholder.qr-detected {
            border-color: #28a745;
            box-shadow: 0 4px 20px rgba(40, 167, 69, 0.4);
        }

        .qr-placeholder.qr-error {
            border-color: #dc3545;
            box-shadow: 0 4px 20px rgba(220, 53, 69, 0.4);
        }

        #qr-reader {
            width: 100%;
            height: 100%;
        }

        #qr-reader video {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        #scanStatus {
            position: absolute;
            top: 10px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.8);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            z-index: 10;
            transition: all 0.3s ease;
        }

        #scanStatus.analyzing {
            background: rgba(255, 193, 7, 0.9);
            color: #333;
        }

        #scanStatus.success {
            background: rgba(40, 167, 69, 0.9);
            color: white;
        }

        #scanStatus.error {
            background: rgba(220, 53, 69, 0.9);
            color: white;
        }

        .camera-controls {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
            align-items: center;
        }

        .btn {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 700;
            transition: transform 0.3s;
            text-shadow: 0 0 10px rgba(255, 255, 255, 0.8), 0 0 20px rgba(255, 255, 255, 0.6);
            box-shadow: 0 0 15px rgba(102, 126, 234, 0.6), 0 0 30px rgba(118, 75, 162, 0.4);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 0 20px rgba(102, 126, 234, 0.8), 0 0 40px rgba(118, 75, 162, 0.6);
        }

        /* Primary action button matching the dark DepEd-blue pill (used for staff login / reset) */
        .primary-btn {
            background: linear-gradient(135deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
            color: #ffffff;
            padding: 12px 30px;
            border: none;
            border-radius: 28px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 800;
            letter-spacing: 0.2px;
            transition: transform 0.22s ease, box-shadow 0.22s ease;
            box-shadow: 0 8px 22px rgba(13,34,64,0.45), inset 0 1px 0 rgba(255,255,255,0.06);
            text-shadow: 0 1px 0 rgba(0,0,0,0.35);
            display: inline-block;
        }

        .primary-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 30px rgba(13,34,64,0.55), inset 0 1px 0 rgba(255,255,255,0.08);
        }

        .btn-secondary {
            background: #6c757d;
        }

        #cameraSelector {
            padding: 12px 20px;
            font-size: 16px;
            max-width: 300px;
            width: 100%;
            border: 2px solid #667eea;
            border-radius: 8px;
            background: white;
            color: #333;
            cursor: pointer;
            font-weight: 600;
        }

        #cameraSelector:focus {
            outline: none;
            border-color: #764ba2;
        }

        .link {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .link:hover {
            text-decoration: underline;
        }

        /* Portal Animation Overlay */
        #portalOverlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 99999;
            animation: portalFadeIn 0.5s ease-out;
        }

        /* Portal GIF styling */
        .portal-gif {
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%) scale(0.98);
            z-index: 100001;
            max-width: 60%;
            max-height: 60%;
            object-fit: contain;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.35s ease, transform 0.35s ease, visibility 0s linear 0.35s;
        }

        .portal-gif-visible {
            opacity: 1 !important;
            visibility: visible !important;
            transform: translate(-50%, -50%) scale(1) !important;
            transition-delay: 0s;
        }

        @keyframes gifFadeIn {
            from { opacity: 0; transform: scale(0.98); }
            to { opacity: 1; transform: scale(1); }
        }

        .portal-gif-caption {
            position: absolute;
            left: 50%;
            transform: translateX(-50%);
            z-index: 100002;
            color: #ffffff;
            font-weight: 700;
            text-shadow: 2px 2px 6px rgba(0,0,0,0.7);
            margin-top: 12px;
            bottom: 12%;
            font-size: 20px;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.25s ease;
        }

        .portal-gif-caption-visible { opacity: 1 !important; }
        @keyframes portalFadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .portal-circle {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            background: var(--main-gradient);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            animation: portalPulse 0.5s ease-in-out infinite alternate, portalGrow 2s ease-out forwards;
            box-shadow: 0 0 60px rgba(102, 126, 234, 0.8), 0 0 120px rgba(118, 75, 162, 0.6);
        }

        @keyframes portalPulse {
            from {
                box-shadow: 0 0 60px rgba(102, 126, 234, 0.8), 0 0 120px rgba(118, 75, 162, 0.6);
            }
            to {
                box-shadow: 0 0 80px rgba(102, 126, 234, 1), 0 0 160px rgba(118, 75, 162, 0.8);
            }
        }

        @keyframes portalGrow {
            0% {
                transform: scale(0.5);
                opacity: 0;
            }
            50% {
                transform: scale(1);
                opacity: 1;
            }
            100% {
                transform: scale(50);
                opacity: 0;
            }
        }

        .portal-icon {
            font-size: 80px;
            animation: portalIconSpin 1s linear infinite;
        }

        @keyframes portalIconSpin {
            from { transform: rotateY(0deg); }
            to { transform: rotateY(360deg); }
        }

        .portal-text {
            color: white;
            font-size: 16px;
            font-weight: 700;
            margin-top: 10px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.5);
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: absolute;
            /* Ensure modal sits above portal / animated overlays */
            z-index: 300000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100vh;
            background: transparent;
            pointer-events: none;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal.hide-anim {
            opacity: 0;
        }

        .modal-content {
            background: white;
            border-radius: 15px;
            padding: 20px;
            max-width: 450px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            animation: modalPopIn 0.4s cubic-bezier(0.68, -0.55, 0.27, 1.55);
            max-height: 90vh;
            overflow-y: auto;
            position: relative;
            pointer-events: auto;
        }

        /* Circular gradient shadow at bottom of modal */
        .modal-content::after {
            content: '';
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 40px;
            background: radial-gradient(ellipse at center, rgba(0, 0, 0, 0.3) 0%, rgba(0, 0, 0, 0.15) 40%, transparent 70%);
            border-radius: 50%;
            z-index: -1;
            pointer-events: none;
        }

        .modal.hide-anim .modal-content {
            animation: modalPopOut 0.4s ease forwards;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes modalPopIn {
            from {
                transform: scale(0.7) translateY(-50px);
                opacity: 0;
            }
            to {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
        }

        @keyframes modalPopOut {
            from {
                transform: scale(1) translateY(0);
                opacity: 1;
            }
            to {
                transform: scale(0.7) translateY(-50px);
                opacity: 0;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eee;
        }

        .modal-header h2 {
            margin: 0;
            color: #333;
            font-size: 1.4em;
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: color 0.3s;
        }

        .close-modal:hover {
            color: #333;
        }

        .modal .form-group {
            margin-bottom: 20px;
        }

        .modal .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .modal .form-group input {
            width: 100%;
            padding: 12px;
            border: 2px solid #e1e1e1;
            border-radius: 8px;
            font-size: 16px;
            transition: border-color 0.3s;
        }

        .modal .form-group input:focus {
            outline: none;
            border-color: #667eea;
        }

        .modal-footer {
            margin-top: 20px;
            text-align: center;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .modal-footer a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }

        .modal-footer a:hover {
            text-decoration: underline;
        }

        .modal .alert {
            padding: 12px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .modal .alert-success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
        }

        .modal .alert-danger {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
        }

        /* Dashboard Cards Grid - Now single column for Featured Books */
        .section-container {
            padding: 30px 0;
            margin: 20px 0;
        }

        @media (max-width: 900px) {
            .section-container {
                padding: 20px 0;
            }
        }

        /* Student Dashboard Card - Now in Modal */
        .student-dashboard-card {
            background: white;
            border-radius: 15px;
            padding: 20px; /* Reduced from 25px */
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            display: flex;
            flex-direction: column;
        }

        .student-dashboard-card h2 {
            margin-bottom: 15px;
            color: #333;
        }

        .student-features {
            display: flex;
            flex-direction: column;
            gap: 12px;
            flex: 1;
        }

        .student-feature {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            transition: all 0.3s ease;
        }

        .student-feature:hover {
            background: #f0f4ff;
            transform: translateX(5px);
        }

        .student-feature-icon {
            width: 45px;
            height: 45px;
            border-radius: 10px;
            background: var(--main-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .student-feature-text h4 {
            margin: 0 0 4px;
            color: #333;
            font-size: 15px;
        }

        .student-feature-text p {
            margin: 0;
            color: #666;
            font-size: 13px;
        }

        .student-login-btn {
            margin-top: 20px;
            padding: 15px 30px;
            background: var(--main-gradient);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
            text-decoration: none;
            display: block;
        }

        .student-login-btn:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
        }

        /* Featured Books Card */
        .featured-books-card {
            background: linear-gradient(180deg, rgba(255,255,255,0.98), rgba(250,250,250,0.98));
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
            max-width: 900px;
            margin: 0 auto;
        }

        /* Wrapper provides the rounded outer border for the table */
        .featured-books-table-wrapper {
            width: 100%;
            margin-top: 15px;
            border: 1px solid var(--deped-blue);
            border-radius: 12px;
            overflow: hidden; /* clip inner table so corners appear rounded */
            box-shadow: 0 6px 18px rgba(13,34,64,0.06);
        }

        .featured-books-table {
            width: 100%;
            /* Use collapsed borders so internal cell borders align */
            border-collapse: collapse;
            border-spacing: 0;
        }

        .featured-books-table thead th {
            background: var(--main-gradient);
            color: white;
            padding: 14px 12px;
            font-weight: 700;
            font-size: 14px;
            border: 1px solid var(--deped-blue);
        }

        /* Keep first column left-aligned (Book & Author), center the other headers */
        .featured-books-table thead th:first-child {
            text-align: left;
            padding-left: 18px;
        }

        .featured-books-table thead th:nth-child(2),
        .featured-books-table thead th:nth-child(3),
        .featured-books-table thead th:nth-child(4) {
            text-align: center;
        }

        .featured-books-table thead th:first-child {
            border-radius: 10px 0 0 0;
        }

        .featured-books-table thead th:last-child {
            border-radius: 0 10px 0 0;
            text-align: center;
        }

        .featured-books-table tbody tr {
            transition: all 0.3s ease;
        }

        .featured-books-table tbody tr:hover {
            background: #f8f9ff;
        }

        .featured-books-table tbody td {
            padding: 15px 12px;
            vertical-align: middle;
            border: 1px solid var(--deped-blue);
        }

        /* Center the contents for Genre, Rating and Description columns */
        .featured-books-table tbody td:nth-child(2),
        .featured-books-table tbody td:nth-child(3),
        .featured-books-table tbody td:nth-child(4) {
            text-align: center;
        }

        .featured-books-table tbody tr:last-child td {
            border-bottom: none;
        }

        .featured-books-table tbody tr:last-child td:first-child {
            border-radius: 0 0 0 10px;
        }

        .featured-books-table tbody tr:last-child td:last-child {
            border-radius: 0 0 10px 0;
        }

        .book-table-cover {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .book-mini-cover {
            width: 60px;
            height: 85px;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transition: all 0.4s ease;
            cursor: pointer;
        }

        .book-mini-cover:hover {
            transform: perspective(500px) rotateY(-15deg) scale(1.05);
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }

        .book-mini-cover.cover1 {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .book-mini-cover.cover2 {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }

        .book-mini-cover.cover3 {
            background: linear-gradient(135deg, #eb3349 0%, #f45c43 100%);
        }

        .book-table-info h4 {
            margin: 0 0 4px;
            color: #333;
            font-weight: 700;
        }

        .book-table-info p {
            margin: 0;
            color: #666;
            font-size: 13px;
        }

        .book-genre-tag {
            display: inline-block;
            padding: 5px 12px;
            background: #f0f4ff;
            color: #667eea;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .book-rating {
            color: #ffc107;
            font-size: 14px;
        }

        .book-view-btn {
            padding: 8px 16px;
            background: var(--main-gradient);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .book-view-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }

        @media (max-width: 768px) {
            .featured-books-table {
                display: block;
                overflow-x: auto;
            }

            .featured-books-table thead {
                display: none;
            }

            .featured-books-table tbody tr {
                display: block;
                margin-bottom: 15px;
                background: #f8f9fa;
                border-radius: 10px;
                padding: 15px;
            }

            .featured-books-table tbody td {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 8px 0;
                border-bottom: 1px solid #eee;
            }

            .featured-books-table tbody td::before {
                content: attr(data-label);
                font-weight: 600;
                color: #666;
                margin-right: 10px;
            }

            .featured-books-table tbody td:last-child {
                justify-content: center;
                border-bottom: none;
            }
        }

        /* Staff Access cards */
        .staff-access {
            margin-top: 24px;
            text-align: center;
            color: #fff;
        }

        .staff-access h3 {
            margin-bottom: 12px;
            font-weight: 800;
            color: #fff;
        }

        .staff-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 18px;
            align-items: stretch;
            margin-top: 12px;
        }

        .staff-card {
            background: rgba(255,255,255,0.08);
            padding: 18px;
            border-radius: 12px;
            min-height: 110px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            color: #fff;
            font-weight: 800;
            cursor: pointer;
            transition: transform 0.18s, box-shadow 0.18s, background 0.18s;
            box-shadow: 0 6px 18px rgba(0,0,0,0.12);
        }

        .staff-card:hover {
            transform: translateY(-6px);
            background: linear-gradient(135deg, rgba(255,255,255,0.12), rgba(255,255,255,0.06));
            box-shadow: 0 12px 28px rgba(0,0,0,0.18);
        }

        .staff-card small {
            font-weight: 600;
            opacity: 0.95;
            font-size: 13px;
        }

        /* Login Button in Navbar */
        .nav-login-wrapper {
            margin-left: 15px;
            margin-right: 15px;
        }

        .nav-login-btn {
            /* match .nav-btn contrast background for visual consistency */
            background: #B8875B !important;
            border: 2px solid rgba(255, 255, 255, 0.18) !important;
            color: #fff !important;
            font-weight: 700 !important;
            border-radius: 10px !important;
            padding: 10px 18px !important;
        }

        .nav-login-btn:hover {
            background: rgba(0,0,0,0.55) !important;
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(13,34,64,0.22) !important;
        }

        /* Main Login Modal Styles */
        .login-modal-content {
            max-width: 450px;
            padding: 0;
            border-radius: 16px;
            overflow: hidden;
        }

        .login-modal-content .modal-header {
            padding: 20px 25px;
            border-bottom: 1px solid #eee;
            background: #fff;
        }

        .login-modal-content .modal-header h2 {
            font-size: 1.5em;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        .login-modal-body {
            padding: 30px;
            background: #fff;
        }

        .login-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .student-login-option {
            background: rgba(139, 69, 19, 0.9);
            color: white;
            margin: 0 auto 15px;
            width: 140px;
            height: 140px;
            border-radius: 16px;
        }

        .student-login-option:hover {
            background: rgba(101, 67, 33, 1);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(139, 69, 19, 0.4);
        }

        .login-option-icon {
            width: 96px;
            height: 96px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 72px;
            margin-bottom: 10px;
        }

        .login-option-icon img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            filter: none;
            transition: transform 160ms ease;
        }

        .login-option span {
            font-size: 16px;
            font-weight: 700;
        }

        .login-divider {
            display: flex;
            align-items: center;
            text-align: center;
            margin: 20px 0;
            color: #999;
        }

        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            border-bottom: 1px solid #ddd;
        }

        .login-divider span {
            padding: 0 15px;
            font-weight: 600;
            color: #666;
        }

        .staff-access-title {
            text-align: center;
            font-size: 1.1em;
            font-weight: 800;
            color: #333;
            margin-bottom: 20px;
        }

        .staff-login-options {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .staff-option {
            background: rgba(139, 69, 19, 0.9);
            color: white;
            width: 100px;
            height: 100px;
            border-radius: 12px;
            padding: 15px;
        }

        .staff-option:hover {
            background: rgba(101, 67, 33, 1);
            transform: translateY(-3px);
            box-shadow: 0 6px 20px rgba(139, 69, 19, 0.4);
        }

        .staff-option .login-option-icon {
            width: 50px;
            height: 50px;
            font-size: 35px;
            margin-bottom: 8px;
        }

        .staff-option span {
            font-size: 13px;
        }

        @media (max-width: 500px) {
            .staff-login-options {
                gap: 12px;
            }
            .staff-option {
                width: 85px;
                height: 85px;
            }
            .student-login-option {
                width: 120px;
                height: 120px;
            }
        }

        /* Footer */
        footer.site-footer {
            background: linear-gradient(135deg, #c41230 0%, #8b0a1e 100%);
            padding: 0;
            color: #fff;
            margin-top: 60px;
        }

        .footer-content {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 40px;
            padding: 50px 40px 30px;
            max-width: 1200px;
            margin: 0 auto;
        }

        .footer-brand {
            text-align: left;
        }

        .footer-logo-link {
            display: inline-block;
            transition: all 0.3s ease;
        }

        .footer-logo-link:hover {
            transform: scale(1.1) rotate(5deg);
            filter: drop-shadow(0 4px 15px rgba(255, 255, 255, 0.4));
        }

        .footer-logo {
            width: 60px;
            height: 60px;
            margin-bottom: 15px;
            cursor: pointer;
        }

        .footer-brand h3 {
            font-size: 1.5em;
            font-weight: 800;
            margin-bottom: 5px;
        }

        .footer-brand p {
            opacity: 0.8;
            font-size: 14px;
        }

        .footer-links {
            display: flex;
            gap: 60px;
            flex-wrap: wrap;
        }

        .footer-column h4 {
            font-size: 14px;
            font-weight: 800;
            text-transform: uppercase;
            margin-bottom: 15px;
            letter-spacing: 1px;
        }

        .footer-column p,
        .footer-column a {
            font-size: 14px;
            opacity: 0.85;
            margin-bottom: 8px;
            display: block;
            color: #fff;
            text-decoration: none;
            transition: all 0.3s;
        }

        .footer-column a:hover {
            opacity: 1;
            transform: translateX(3px);
        }

        .footer-bottom {
            background: rgba(0, 0, 0, 0.2);
            text-align: center;
            padding: 20px;
            font-size: 13px;
            letter-spacing: 0.5px;
        }

        .footer-bottom p {
            margin: 0;
            opacity: 0.9;
        }

        @media (max-width: 768px) {
            .footer-content {
                flex-direction: column;
                text-align: center;
                padding: 40px 20px 25px;
            }
            .footer-brand {
                text-align: center;
            }
            .footer-links {
                justify-content: center;
                gap: 30px;
            }
        }

        /* Section Container - Uniform spacing */
        .section-container {
            padding: 40px 0;
        }

        /* Student Features Preview in Modal */
        .student-features-preview {
            display: flex;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .student-features-preview .feature-item {
            background: rgba(139, 69, 19, 0.1);
            padding: 8px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: #654321;
        }

        .student-features-preview .feature-item span {
            margin-right: 5px;
        }

        /* small responsive adjustments */
        @media (max-width: 640px) {
            .featured-books { gap: 12px; }
            .book-card { width: 45%; height: 230px; }
        }

        /* About Section */
        .about-section {
            padding: 60px 20px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
        }

        .about-section h2 {
            text-align: center;
            font-size: 3em;
            font-weight: 900;
            color: #fff;
            margin-bottom: 50px;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.5);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .about-cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 30px;
            max-width: 1200px;
            margin: 0 auto;
            align-items: stretch;
        }

        .about-card {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.18);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            min-height: 300px; /* reduce large empty space for short content */
        }

        .about-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 15px 40px rgba(0, 0, 0, 0.4);
        }

        .about-card h3 {
            font-size: 1.8em;
            font-weight: 700;
            color: var(--deped-blue);
            margin-bottom: 20px;
            text-align: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            flex-shrink: 0;
        }

        .about-card-content {
            flex: 1;
            display: flex;
            align-items: center; /* vertically center short content to reduce bottom gap */
            justify-content: center;
        }

        /* Keep the goals list top-aligned so its long content flows naturally */
        #goals .about-card-content {
            align-items: flex-start;
            justify-content: flex-start;
        }

        .about-card p {
            font-size: 1.05em; /* slightly larger to fill more vertical space */
            line-height: 1.7;
            color: #333;
            text-align: justify;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
        }

        /* Larger paragraphs specifically for Vision & Mission to reduce empty space */
        /* Mission stays at 1.40em; Vision slightly larger but not too big */
        #mission .about-card-content p {
            font-size: 1.40em;
            line-height: 1.9;
        }

        #vision .about-card-content p {
            font-size: 1.50em; /* modest increase from previous size */
            line-height: 2.0;
        }

        .about-card ul {
            list-style: none;
            padding: 0;
            margin: 0;
            width: 100%;
        }

        .about-card ul li {
            padding: 10px 0;
            padding-left: 28px;
            position: relative;
            color: #333;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.5;
        }

        .about-card ul li::before {
            content: '✓';
            position: absolute;
            left: 0;
            color: var(--deped-blue);
            font-weight: bold;
        }

        @media (max-width: 900px) {
            .about-cards {
                grid-template-columns: 1fr;
            }
        }
    /* Emphasized button text styles: thick white glowing text for primary actions and view buttons */
    .primary-btn, .book-view-btn {
        color: #ffffff !important;
        font-weight: 900 !important;
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
        text-shadow:
            0 1px 0 rgba(0,0,0,0.6),
            0 4px 12px rgba(26,68,128,0.45),
            0 8px 30px rgba(142,46,204,0.18);
        letter-spacing: 0.3px;
    }
    .book-view-btn {
        text-transform: none;
        font-weight: 800 !important;
        box-shadow: 0 6px 18px rgba(102,126,234,0.25);
        color: #fff !important;
    }

    </style>
    <script src="../js/script.js"></script>
</head>
<body>
    <?php if ($showNetworkWarning): ?>
    <div id="networkWarning" style="background: #fff3cd; border-bottom: 2px solid #ffc107; padding: 15px; text-align: center;">
        <strong>⚠️ Network Access Notice:</strong> 
        You are accessing via <code><?php echo htmlspecialchars($_SERVER['HTTP_HOST']); ?></code>. 
        QR scanning may not work. 
        <a href="http://localhost/FP_SIA_SAD_WST/php/index.php" style="color: #856404; font-weight: bold;">Click here to use localhost</a> 
        or <a href="javascript:void(0)" onclick="window.CameraHelper.showCameraInstructions()" style="color: #856404; font-weight: bold;">see other options</a>.
        <button onclick="this.parentElement.style.display='none'" style="margin-left: 15px; padding: 5px 15px; cursor: pointer;">✕ Dismiss</button>
    </div>
    <?php endif; ?>
    
    <!-- Scroll Progress Bar -->
    <div class="scroll-progress-bar" id="scrollProgressBar"></div>

    <!-- Navigation Bar -->
    <nav class="navbar" id="navbar">
        <div class="container">
            <a href="#" class="nav-brand" onclick="scrollToTop(event)">
                <div class="nav-logo">
                    <img src="../images/library_hub_logo_navbar.png" alt="Library Hub Logo" onerror="this.parentElement.innerHTML='📚'">
                </div>
                <span class="nav-brand-text">LIBRARY HUB</span>
            </a>
            <div class="nav-hamburger" onclick="toggleNavMenu()">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <div class="nav-links" id="navLinks">
                <button class="nav-btn" onclick="scrollToSection('about-section')">ABOUT</button>
                <button class="nav-btn" onclick="scrollToSection('qrScanner')">QR SCANNER</button>
                <button class="nav-btn" onclick="scrollToSection('featuredBooks')">FEATURED BOOKS</button>
                <button class="nav-btn nav-login-btn mobile-only" onclick="openLoginModal()">LOG IN</button>
            </div>
            <div class="nav-login-wrapper">
                <button class="nav-btn nav-login-btn" onclick="openLoginModal()">LOG IN</button>
            </div>
        </div>
    </nav>

    <!-- Boot Loader Animation -->
    <div id="bootLoader">
        <div class="boot-logo">
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="boot-icon">
                <img src="../images/library_hub_logo.png" alt="Library Hub Logo">
            </div>
        </div>
        <div class="boot-text">
            Library Hub
        </div>
        <div class="boot-subtitle">
            Loading<span class="loading-dots"></span>
        </div>
    </div>

    <!-- Main Content -->
    <div id="mainContent">
        <!-- Parallax Hero Section -->
        <div class="parallax-hero">
            <div class="hero-content">
                <img src="../images/welcome_white_shadow.png" alt="Welcome to The Library Hub of Tambo, Lipa City" style="max-width: 90%; height: auto;">
            </div>
        </div>
        <script>
        // Parallax effect for hero background
        (function() {
            var hero = document.querySelector('.parallax-hero');
            if (!hero) return;
            window.addEventListener('scroll', function() {
                var scrolled = window.pageYOffset || document.documentElement.scrollTop;
                // Move background slower than scroll (parallax)
                hero.style.backgroundPosition = 'center ' + Math.round(scrolled * 0.4) + 'px';
            });
        })();
        </script>

        <!-- About Section -->
        <div class="about-section" id="about-section">
            <h2>ABOUT</h2>
            <div class="about-cards">
                <div class="about-card" id="vision">
                    <h3>Vision</h3>
                    <div class="about-card-content">
                        <p>A functional Library Hub in every schools division is a reservoir of reading materials envisioned to develop among pupils and students the love for and habit of reading.</p>
                    </div>
                </div>
                <div class="about-card" id="mission">
                    <h3>Mission</h3>
                    <div class="about-card-content">
                        <p>The Library Hub, equipped with adequate and varied quality supplementary reading materials for public elementary and secondary schools, shall be established in all schools divisions nationwide.</p>
                    </div>
                </div>
                <div class="about-card" id="goals">
                    <h3>Goals & Objectives</h3>
                    <div class="about-card-content">
                        <ul>
                            <li>Provide greater access to reading materials to all public school pupils and students</li>
                            <li>Supply quality and appropriate books to public schools nationwide</li>
                            <li>Develop the love for books and habit of reading</li>
                            <li>Make every Filipino child a book lover</li>
                            <li>Support the development of reading and comprehension skills</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="container" style="margin-top: 40px;">
            <!-- QR Scanner Section -->
            <div class="card" id="qrScanner">
                
                <div class="qr-scanner">
                    <h3>QR CODE SCANNER</h3>
                    <p>Point your camera at the QR code on the book</p>

                    <div class="qr-placeholder" id="qrPlaceholder">
                        <div id="qr-reader"></div>
                        <div id="scanStatus">Click "Start Scanner" to begin</div>
                    </div>

                    <div class="camera-controls">
                        <button class="btn book-view-btn" id="startButton">START SCANNER</button>
                        <button class="btn btn-secondary" id="stopButton" style="display: none;">⏹️ STOP SCANNER</button>
                        <select id="cameraSelector" style="display: none;">
                            <option value="">SELECT CAMERA</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Featured Books Section -->
            <div class="section-container" id="featuredBooks">
                <!-- Featured Books Card -->
                <div class="featured-books-card">
                    <h2 style="text-align:center; margin-bottom: 5px;">FEATURED BOOKS</h2>
                    <p style="text-align:center; color:#666; margin-bottom: 15px;">Discover popular reads this month</p>
                    
                    <div class="featured-books-table-wrapper">
                    <table class="featured-books-table">
                        <thead>
                            <tr>
                                <th>Book & Author</th>
                                <th>Genre</th>
                                <th>Rating</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr data-id="1" data-title="The Little Prince" data-synopsis="A whimsical tale about a young prince who travels from planet to planet, learning about life, love and human nature. Written by Antoine de Saint-Exupéry, it remains one of the most translated books in the world.">
                                <td data-label="Book">
                                    <div class="book-table-cover">
                                        <div class="book-mini-cover cover1">🌟</div>
                                        <div class="book-table-info">
                                            <h4>The Little Prince</h4>
                                            <p>by Antoine de Saint-Exupéry</p>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Genre"><span class="book-genre-tag">Fantasy</span></td>
                                <td data-label="Rating"><span class="book-rating">⭐⭐⭐⭐⭐</span></td>
                                <td data-label="Action"><button class="book-view-btn" onclick="showBookSynopsis(this)">View</button></td>
                            </tr>
                            <tr data-id="2" data-title="Around the World" data-synopsis="An adventurous journey across continents that inspires curiosity and cultural appreciation — perfect for young explorers. Follow the protagonist as they discover new lands, meet diverse people, and learn valuable life lessons.">
                                <td data-label="Book">
                                    <div class="book-table-cover">
                                        <div class="book-mini-cover cover2">🌍</div>
                                        <div class="book-table-info">
                                            <h4>Around the World</h4>
                                            <p>by Jules Verne</p>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Genre"><span class="book-genre-tag">Adventure</span></td>
                                <td data-label="Rating"><span class="book-rating">⭐⭐⭐⭐</span></td>
                                <td data-label="Action"><button class="book-view-btn" onclick="showBookSynopsis(this)">View</button></td>
                            </tr>
                            <tr data-id="3" data-title="Tales of Tomorrow" data-synopsis="A modern collection of short stories exploring technology, hope and the gentle lessons for young readers about tomorrow. Each tale presents a unique perspective on how we can shape a better future through kindness and innovation.">
                                <td data-label="Book">
                                    <div class="book-table-cover">
                                        <div class="book-mini-cover cover3">📖</div>
                                        <div class="book-table-info">
                                            <h4>Tales of Tomorrow</h4>
                                            <p>by Various Authors</p>
                                        </div>
                                    </div>
                                </td>
                                <td data-label="Genre"><span class="book-genre-tag">Sci-Fi</span></td>
                                <td data-label="Rating"><span class="book-rating">⭐⭐⭐⭐⭐</span></td>
                                <td data-label="Action"><button class="book-view-btn" onclick="showBookSynopsis(this)">View</button></td>
                            </tr>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>

        </div>

        <!-- Main Login Modal (Student + Staff) -->
        <div id="mainLoginModal" class="modal">
            <div class="modal-content login-modal-content">
                <div class="modal-header">
                    <h2>Log In</h2>
                    <button class="close-modal" onclick="closeModal('mainLoginModal')">&times;</button>
                </div>
                <div class="login-modal-body">
                    <!-- Student Login Option -->
                    <div class="login-option student-login-option" onclick="window.location.href='login.php?redirect=student'" role="button" tabindex="0">
                        <div class="login-option-icon">
                            <img src="../images/student_icon.png" alt="Student" onerror="this.parentElement.innerHTML='🎓'">
                        </div>
                        <span>Student</span>
                    </div>
                    
                    <!-- Student Features Preview -->
                    <div class="student-features-preview">
                        <div class="feature-item"><span>📊</span> Track Progress</div>
                        <div class="feature-item"><span>🏆</span> Achievements</div>
                        <div class="feature-item"><span>📝</span> Quiz Scores</div>
                        <div class="feature-item"><span>🥇</span> Leaderboard</div>
                    </div>
                    
                    <!-- Divider -->
                    <div class="login-divider">
                        <span>OR</span>
                    </div>
                    
                    <!-- Staff Access Section -->
                    <h3 class="staff-access-title">STAFF ACCESS</h3>
                    <div class="staff-login-options">
                        <div class="login-option staff-option" onclick="openStaffLogin('admin')" role="button" tabindex="0">
                            <div class="login-option-icon">
                                <img src="../images/admin_icon.png" alt="Admin" onerror="this.parentElement.innerHTML='👨‍💼'">
                            </div>
                            <span>Admin</span>
                        </div>
                        <div class="login-option staff-option" onclick="openStaffLogin('librarian')" role="button" tabindex="0">
                            <div class="login-option-icon">
                                <img src="../images/librarian_icon.png" alt="Librarian" onerror="this.parentElement.innerHTML='📚'">
                            </div>
                            <span>Librarian</span>
                        </div>
                        <div class="login-option staff-option" onclick="openStaffLogin('teacher')" role="button" tabindex="0">
                            <div class="login-option-icon">
                                <img src="../images/teacher_icon.png" alt="Teacher" onerror="this.parentElement.innerHTML='👩‍🏫'">
                            </div>
                            <span>Teacher</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Staff Login Modal -->
        <div id="staffLoginModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 id="modalTitle">Staff Login</h2>
                    <button class="close-modal" onclick="closeModal('staffLoginModal')">&times;</button>
                </div>
                <div id="modalMessage"></div>
                <form id="staffLoginForm" onsubmit="handleStaffLogin(event)">
                    <input type="hidden" id="staffType" name="staff_type">
                    <div class="form-group">
                        <label>Email:</label>
                        <input type="email" name="email" id="staffEmail" placeholder="Enter your email" required>
                    </div>
                    <div class="form-group">
                        <label>Password:</label>
                        <input type="password" name="password" id="staffPassword" placeholder="Enter your password" required>
                    </div>
                    <button type="submit" class="btn primary-btn" style="width: 100%;">Login</button>
                </form>
                <div class="modal-footer">
                    <a href="#" class="forgot-password-link">Forgot Password?</a>
                </div>
            </div>
        </div>

        <!-- Forgot Password Modal -->
        <div id="forgotPasswordModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>🔒 Forgot Password</h2>
                    <button class="close-modal" onclick="closeForgotPasswordModal()">&times;</button>
                </div>
                <div id="forgotPasswordMessage"></div>
                <form id="forgotPasswordForm" onsubmit="handleForgotPassword(event)">
                    <input type="hidden" id="forgotStaffType" name="staff_type">
                    <h6 style="text-align: center; margin-bottom: 20px; color: #666;">Reset Your Password</h6>
                    <div class="form-group">
                        <label>Email Address:</label>
                        <input type="email" name="email" id="forgotPasswordEmail" placeholder="Enter your registered email" required>
                    </div>
                    <button type="submit" class="btn primary-btn" style="width: 100%;">Send Reset Link</button>
                </form>
                <div class="modal-footer">
                    <a href="#" class="text-link" onclick="backToLogin(event)">← Back to Login</a>
                </div>
            </div>
        </div>

        <!-- Book Not Found Modal (added) -->
        <div id="bookNotFoundModal" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h2>❌ ERROR: Book Not Found!</h2>
                    <button class="close-modal" onclick="hideModal('bookNotFoundModal')">&times;</button>
                </div>
                <div id="bookNotFoundMessage" style="color:#333; max-height:50vh; overflow:auto; line-height:1.4;">
                    <!-- Filled dynamically -->
                </div>
                <div class="modal-footer">
                    <button class="btn" onclick="retryScan()">🔁 Retry Scan</button>
                    <button class="btn btn-secondary" onclick="hideModal('bookNotFoundModal')">Close</button>
                </div>
            </div>
        </div>

        <!-- Bootstrap 5 (if not already included) -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

        <script>
            // Boot Animation Script - Only shows once per session
            window.addEventListener('load', function() {
                const bootLoader = document.getElementById('bootLoader');
                const mainContent = document.getElementById('mainContent');
                
                // Check if boot animation has already been shown this session
                if (sessionStorage.getItem('bootAnimationShown')) {
                    bootLoader.style.display = 'none';
                    mainContent.classList.add('show');
                    return;
                }
                
                // Mark that we've shown the boot animation
                sessionStorage.setItem('bootAnimationShown', 'true');
                
                // Minimum loading time for smooth animation (2 seconds)
                const minLoadingTime = 2000;
                const startTime = Date.now();
                
                function hideBootLoader() {
                    const elapsedTime = Date.now() - startTime;
                    const remainingTime = Math.max(0, minLoadingTime - elapsedTime);
                    
                    setTimeout(() => {
                        // Fade out boot loader
                        bootLoader.classList.add('fade-out');
                        
                        // Show main content
                        setTimeout(() => {
                            bootLoader.style.display = 'none';
                            mainContent.classList.add('show');
                        }, 800); // Wait for fade-out animation
                    }, remainingTime);
                }
                
                // Start hiding boot loader
                hideBootLoader();
            });

            // Prevent accidental navigation during boot
            let bootComplete = false;
            setTimeout(() => {
                bootComplete = true;
            }, 3000);

            // Open main login modal
            function openLoginModal() {
                showModal('mainLoginModal');
            }

            function openStaffLogin(type) {
                // Close main login modal first if open
                hideModal('mainLoginModal');
                
                const modal = document.getElementById('staffLoginModal');
                const title = document.getElementById('modalTitle');
                const staffType = document.getElementById('staffType');

                const titles = {
                    'admin': '👨‍💼 Admin Login',
                    'teacher': '👩‍🏫 Teacher Login',
                    'librarian': '📚 Librarian Login'
                };

                title.textContent = titles[type] || 'Staff Login';
                staffType.value = type;
                
                document.getElementById('staffLoginForm').reset();
                document.getElementById('staffEmail').value = '';
                document.getElementById('staffPassword').value = '';
                document.getElementById('modalMessage').innerHTML = '';
                
                // Use showModal instead of directly adding class
                showModal('staffLoginModal');
            }

            function openForgotPasswordModal() {
                const staffType = document.getElementById('staffType').value;
                
                closeModal('staffLoginModal');
                
                setTimeout(() => {
                    const forgotModal = document.getElementById('forgotPasswordModal');
                    // Reset form first, then set staffType (reset clears all fields including hidden ones)
                    document.getElementById('forgotPasswordForm').reset();
                    document.getElementById('forgotPasswordMessage').innerHTML = '';
                    document.getElementById('forgotStaffType').value = staffType;
                    document.getElementById('forgotPasswordEmail').value = '';
                    showModal('forgotPasswordModal');
                }, 300);
            }

            function backToLogin(event) {
                event.preventDefault();
                const staffType = document.getElementById('forgotStaffType').value;
                
                closeForgotPasswordModal();
                
                setTimeout(() => {
                    openStaffLogin(staffType);
                }, 300);
            }

            function closeForgotPasswordModal() {
                hideModal('forgotPasswordModal');
                document.getElementById('forgotPasswordEmail').value = '';
                document.getElementById('forgotPasswordForm').reset();
                document.getElementById('forgotPasswordMessage').innerHTML = '';
            }

            function closeModal(modalId) {
                hideModal(modalId);
            }

            window.onclick = function(event) {
                const mainLoginModal = document.getElementById('mainLoginModal');
                const staffModal = document.getElementById('staffLoginModal');
                const forgotModal = document.getElementById('forgotPasswordModal');
                if (event.target == mainLoginModal) {
                    hideModal('mainLoginModal');
                }
                if (event.target == staffModal) {
                    hideModal('staffLoginModal');
                }
                if (event.target == forgotModal) {
                    hideModal('forgotPasswordModal');
                }
            }

            async function handleStaffLogin(event) {
                event.preventDefault();

                const form = event.target;
                const formData = new FormData(form);

                try {
                    const response = await fetch('staff-login-handler.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        showModalMessage('modalMessage', 'Login successful! Redirecting...', 'success');
                        setTimeout(() => {
                            window.location.href = data.redirect;
                        }, 1000);
                    } else {
                        showModalMessage('modalMessage', data.message || 'Login failed. Please try again.', 'danger');
                    }
                } catch (error) {
                    console.error('Login error:', error);
                    showModalMessage('modalMessage', 'Login failed. Please try again.', 'danger');
                }
            }

            async function handleForgotPassword(event) {
                event.preventDefault();

                const form = event.target;
                const formData = new FormData(form);

                try {
                    const response = await fetch('forgot-password-handler.php', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        const messageHTML = `
                            <div class="alert alert-success">
                                A password reset link has been sent to your email. Please 
                                <a href="${data.reset_link}" target="_blank" style="text-decoration: underline; font-weight: 600;">click here to reset now</a>.
                            </div>
                        `;
                        document.getElementById('forgotPasswordMessage').innerHTML = messageHTML;
                        document.getElementById('forgotPasswordEmail').value = '';
                        form.reset();
                    } else {
                        showModalMessage('forgotPasswordMessage', data.message || 'Failed to send reset link. Please try again.', 'danger');
                    }
                } catch (error) {
                    console.error('Forgot password error:', error);
                    showModalMessage('forgotPasswordMessage', 'Failed to send reset link. Please try again.', 'danger');
                }
            }

            function showModalMessage(elementId, message, type) {
                const messageDiv = document.getElementById(elementId);
                messageDiv.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
            }

            // Auto-open login modal if redirected from password reset
            document.addEventListener('DOMContentLoaded', () => {
                const urlParams = new URLSearchParams(window.location.search);
                const loginModal = urlParams.get('login_modal');
                
                if (loginModal && ['admin', 'teacher', 'librarian'].includes(loginModal)) {
                    openStaffLogin(loginModal);
                }

                document.querySelectorAll('.forgot-password-link').forEach(link => {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        openForgotPasswordModal();
                    });
                });
            });

            const statusDiv = document.getElementById('scanStatus');
            const qrPlaceholder = document.getElementById('qrPlaceholder');
            const startButton = document.getElementById('startButton');
            const stopButton = document.getElementById('stopButton');
            const cameraSelector = document.getElementById('cameraSelector');

            let videoStream = null;
            let videoElement = null;
            let canvasElement = null;
            let scanInterval = null;
            let isProcessing = false;
            let isPythonAvailable = false;
            let isLaunchingScanner = false;

            // Check Python API on load
            async function checkPythonAPI() {
                try {
                    const response = await fetch('python-qr-scan.php?action=health');
                    const data = await response.json();
                    isPythonAvailable = data.status === 'ok';
                    console.log('Python API:', isPythonAvailable ? 'Online' : 'Offline');
                    return isPythonAvailable;
                } catch (e) {
                    console.log('Python API: Offline');
                    isPythonAvailable = false;
                    return false;
                }
            }

            // Check scanner status (if already running or launching)
            async function checkScannerStatus() {
                try {
                    const response = await fetch('start-python-scanner.php?action=status');
                    return await response.json();
                } catch (e) {
                    console.error('Error checking scanner status:', e);
                    return { success: false, api_running: false, launching: false };
                }
            }

            // Launch the Python scanner
            async function launchPythonScanner() {
                try {
                    const response = await fetch('start-python-scanner.php?action=launch');
                    return await response.json();
                } catch (e) {
                    console.error('Error launching scanner:', e);
                    return { success: false, message: 'Failed to launch scanner' };
                }
            }

            // Wait for Python API to be ready
            async function waitForPythonAPI(maxAttempts = 30, intervalMs = 1000) {
                for (let i = 0; i < maxAttempts; i++) {
                    const isReady = await checkPythonAPI();
                    if (isReady) {
                        return true;
                    }
                    // Update status message
                    statusDiv.textContent = `⏳ Starting Python Scanner... (${i + 1}/${maxAttempts})`;
                    await new Promise(resolve => setTimeout(resolve, intervalMs));
                }
                return false;
            }

            // Main function to ensure scanner is running
            async function ensureScannerRunning() {
                // First check if API is already running
                const apiRunning = await checkPythonAPI();
                if (apiRunning) {
                    console.log('Python API already running');
                    return true;
                }

                // Check scanner status
                const status = await checkScannerStatus();
                
                // If already launching, wait for it
                if (status.launching || status.process_running) {
                    statusDiv.textContent = '⏳ Scanner is starting, please wait...';
                    statusDiv.className = 'analyzing';
                    return await waitForPythonAPI(30, 1000);
                }

                // Launch the scanner
                statusDiv.textContent = '🚀 Launching Python Scanner...';
                statusDiv.className = 'analyzing';
                
                const launchResult = await launchPythonScanner();
                
                if (launchResult.already_running) {
                    return true;
                }
                
                if (launchResult.launching) {
                    statusDiv.textContent = '⏳ Scanner is already starting, please wait...';
                    return await waitForPythonAPI(30, 1000);
                }
                
                if (!launchResult.success && !launchResult.launched) {
                    console.error('Failed to launch scanner:', launchResult.message);
                    return false;
                }

                // Wait for the API to become available
                statusDiv.textContent = '⏳ Waiting for Python Scanner to start...';
                return await waitForPythonAPI(30, 1000);
            }

            async function loadAvailableCameras() {
                try {
                    const devices = await navigator.mediaDevices.enumerateDevices();
                    const videoDevices = devices.filter(d => d.kind === 'videoinput');
                    
                    if (videoDevices.length > 0) {
                        cameraSelector.innerHTML = '<option value="">Select Camera</option>';
                        videoDevices.forEach((device, index) => {
                            const option = document.createElement('option');
                            option.value = device.deviceId;
                            option.text = device.label || `Camera ${index + 1}`;
                            cameraSelector.appendChild(option);
                        });
                        if (videoDevices.length > 1) {
                            cameraSelector.style.display = 'inline-block';
                        }
                    }
                } catch (err) {
                    console.error('Error loading cameras:', err);
                }
            }

            async function startScanner(cameraId = null) {
                try {
                    // Prevent multiple simultaneous launch attempts
                    if (isLaunchingScanner) {
                        console.log('Scanner is already being launched...');
                        return;
                    }
                    
                    isLaunchingScanner = true;
                    startButton.disabled = true;
                    startButton.textContent = '⏳ STARTING...';
                    
                    // Try to ensure Python scanner is running (launches if needed)
                    statusDiv.textContent = '🔍 Checking Python Scanner...';
                    statusDiv.className = 'analyzing';
                    
                    const scannerReady = await ensureScannerRunning();
                    
                    if (!scannerReady) {
                        statusDiv.textContent = '❌ Python Scanner failed to start';
                        statusDiv.className = 'error';
                        Swal.fire({
                            icon: 'error',
                            title: 'Scanner Failed to Start',
                            html: `<p>The Python QR Scanner could not be started automatically.</p>
                                   <p>Please try:</p>
                                   <ol style="text-align: left;">
                                       <li>Manually run <code>python/start_scanner_tray.bat</code></li>
                                       <li>Look for the scanner icon in your system tray (near the clock)</li>
                                       <li>Right-click the tray icon and select "Start Scanner"</li>
                                       <li>Click "Start Scanner" again in the browser</li>
                                   </ol>`,
                            confirmButtonText: 'OK'
                        });
                        isLaunchingScanner = false;
                        startButton.disabled = false;
                        startButton.textContent = 'START SCANNER';
                        return;
                    }

                    // Stop existing stream
                    await stopScanner();

                    // Create video element if needed
                    const qrReader = document.getElementById('qr-reader');
                    qrReader.innerHTML = '';
                    
                    videoElement = document.createElement('video');
                    videoElement.setAttribute('playsinline', '');
                    videoElement.style.width = '100%';
                    videoElement.style.height = '100%';
                    videoElement.style.objectFit = 'cover';
                    qrReader.appendChild(videoElement);

                    // Create hidden canvas for frame capture
                    canvasElement = document.createElement('canvas');
                    canvasElement.style.display = 'none';

                    // Get camera stream
                    const constraints = {
                        video: cameraId 
                            ? { deviceId: { exact: cameraId } }
                            : { facingMode: 'environment' }
                    };

                    videoStream = await navigator.mediaDevices.getUserMedia(constraints);
                    videoElement.srcObject = videoStream;
                    await videoElement.play();

                    // Set canvas size
                    canvasElement.width = videoElement.videoWidth || 640;
                    canvasElement.height = videoElement.videoHeight || 480;

                    // Update UI
                    startButton.style.display = 'none';
                    stopButton.style.display = 'inline-block';
                    statusDiv.textContent = '🐍 Python Scanner Active';
                    statusDiv.className = '';
                    qrPlaceholder.className = 'qr-placeholder';
                    isProcessing = false;
                    isLaunchingScanner = false;
                    startButton.disabled = false;
                    startButton.textContent = 'START SCANNER';

                    // Start scanning loop
                    scanInterval = setInterval(scanFrame, 500);

                } catch (err) {
                    console.error('Camera error:', err);
                    statusDiv.textContent = '❌ Camera access denied';
                    statusDiv.className = 'error';
                    qrPlaceholder.className = 'qr-placeholder qr-error';
                    isLaunchingScanner = false;
                    startButton.disabled = false;
                    startButton.textContent = 'START SCANNER';
                    Swal.fire({
                        icon: 'error',
                        title: 'Camera Error',
                        text: 'Unable to access camera. Please allow camera permissions.',
                        confirmButtonText: 'OK'
                    });
                }
            }

            async function scanFrame() {
                if (isProcessing || !videoElement || !canvasElement) return;

                try {
                    // Capture frame
                    const ctx = canvasElement.getContext('2d');
                    ctx.drawImage(videoElement, 0, 0, canvasElement.width, canvasElement.height);
                    const imageData = canvasElement.toDataURL('image/jpeg', 0.8);

                    // Send to Python API
                    const response = await fetch('python-qr-scan.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ image: imageData })
                    });

                    const result = await response.json();

                    if (result.success && result.qr_code) {
                        isProcessing = true;
                        onScanSuccess(result.qr_code);
                    }
                } catch (err) {
                    // Silently ignore scan errors
                }
            }

            async function stopScanner() {
                if (scanInterval) {
                    clearInterval(scanInterval);
                    scanInterval = null;
                }

                if (videoStream) {
                    videoStream.getTracks().forEach(track => track.stop());
                    videoStream = null;
                }

                if (videoElement) {
                    videoElement.srcObject = null;
                }

                startButton.style.display = 'inline-block';
                startButton.disabled = false;
                startButton.textContent = 'START SCANNER';
                stopButton.style.display = 'none';
                statusDiv.textContent = 'Scanner stopped';
                statusDiv.className = '';
                qrPlaceholder.className = 'qr-placeholder';
                isProcessing = false;
                isLaunchingScanner = false;
            }

            startButton.addEventListener('click', async () => {
                await loadAvailableCameras();
                await startScanner();
            });

            stopButton.addEventListener('click', async () => {
                await stopScanner();
            });

            cameraSelector.addEventListener('change', async (e) => {
                if (e.target.value) {
                    await startScanner(e.target.value);
                }
            });

            function escapeHtml(unsafe) {
                return String(unsafe || '')
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }

            function resetScannerUI() {
                statusDiv.textContent = '🐍 Python Scanner Active';
                statusDiv.className = '';
                qrPlaceholder.className = 'qr-placeholder';
                isProcessing = false;
            }

            function onScanSuccess(qrCode) {
                console.log('[Scanner] QR Code Detected:', qrCode);

                // Stop scanning
                if (scanInterval) {
                    clearInterval(scanInterval);
                    scanInterval = null;
                }

                statusDiv.textContent = '⏳ Verifying book...';
                statusDiv.className = 'analyzing';
                qrPlaceholder.className = 'qr-placeholder qr-detected';

                // Fetch book from database
                fetch(`get-book-by-qr.php?qr_code=${encodeURIComponent(qrCode)}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.book) {
                            statusDiv.textContent = '✅ Book recognized! Redirecting...';
                            statusDiv.className = 'success';

                            return fetch('save-scanned-book.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(data.book)
                            });
                        } else {
                            throw new Error(data.message || 'Book not found in database');
                        }
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showPortalAndRedirect('login.php');
                        } else {
                            throw new Error(data.message || 'Failed to save book');
                        }
                    })
                    .catch(error => {
                        console.error('[Scanner] Error:', error);
                        statusDiv.textContent = '❌ Book not found!';
                        statusDiv.className = 'error';
                        qrPlaceholder.className = 'qr-placeholder qr-error';

                        Swal.fire({
                            title: '❌ Book Not Found',
                            html: `
                                <p><strong>Scanned QR Code:</strong> ${escapeHtml(qrCode)}</p>
                                <p><strong>Details:</strong><br><small>${escapeHtml(error.message)}</small></p>
                                <hr>
                                <p>Please ensure:</p>
                                <ul style="text-align:left; margin-left:18px;">
                                    <li>The book exists in the system</li>
                                    <li>The QR code matches exactly</li>
                                    <li>The book is marked as available</li>
                                </ul>
                            `,
                            icon: 'error',
                            showCancelButton: true,
                            confirmButtonText: '🔁 Retry Scan',
                            cancelButtonText: 'Close',
                            allowOutsideClick: false
                        }).then((result) => {
                            resetScannerUI();
                            if (result.isConfirmed) {
                                scanInterval = setInterval(scanFrame, 500);
                            }
                        });
                    });
            }

            // Portal Animation helpers and Function
            // Include the original portal-circle as its own animation option so it can be
            // treated the same as GIFs in the rotation (it will be shown only when selected).
            const portalAnimations = [
                { type: 'circle' },
                { type: 'gif', src: '../images/entering_quiz_portal.gif' },
                { type: 'gif', src: '../images/entering_quiz_2_portal.gif' }
            ];

            let _portalQueue = [];
            function shufflePortalQueue() {
                // Shuffle a fresh copy so each item appears once before repeating
                _portalQueue = portalAnimations.slice().sort(() => Math.random() - 0.5);
            }

            function getNextPortalAnimation() {
                if (!_portalQueue || _portalQueue.length === 0) shufflePortalQueue();
                return _portalQueue.shift();
            }

            function showPortalAndRedirect(url) {
                const portal = document.getElementById('portalOverlay');
                if (!portal) {
                    setTimeout(() => window.location.href = url, 2200);
                    return;
                }

                const portalCircle = portal.querySelector('.portal-circle');
                let gif = portal.querySelector('.portal-gif');
                if (!gif) {
                    gif = document.createElement('img');
                    gif.className = 'portal-gif';
                    portal.appendChild(gif);
                }

                // Pick next animation from the shuffled queue
                const anim = getNextPortalAnimation();

                // Reset visibility
                portal.style.display = 'flex';

                const gifCaption = portal.querySelector('.portal-gif-caption');

                if (anim.type === 'gif') {
                    // Hide the portal-circle while the GIF plays
                    if (portalCircle) portalCircle.style.display = 'none';

                    // Hide GIF until it's fully loaded to avoid layout flicker
                    try { gif.onload = null; } catch (e) {}
                    gif.style.display = 'none';
                    gif.classList.remove('portal-gif-visible');
                    gif.style.visibility = 'hidden';

                    gif.onload = function() {
                        // show GIF using visible class for smooth fade/scale
                        gif.style.display = 'block';
                        // small timeout to allow display to apply before transition
                        setTimeout(() => gif.classList.add('portal-gif-visible'), 20);
                    };

                    // Start loading the GIF
                    gif.src = anim.src;
                    // show caption for GIF
                    if (gifCaption) {
                        gifCaption.textContent = 'Entering Quiz Portal';
                        gifCaption.style.display = 'block';
                        // small timeout to allow display before fade
                        setTimeout(() => gifCaption.classList.add('portal-gif-caption-visible'), 20);
                    }
                } else {
                    // Show only the portal-circle; hide GIF and stop it
                    try { gif.onload = null; } catch (e) {}
                    gif.classList.remove('portal-gif-visible');
                    gif.style.display = 'none';
                    gif.style.visibility = 'hidden';
                    gif.src = '';
                    if (portalCircle) portalCircle.style.display = 'flex';
                    if (gifCaption) {
                        gifCaption.classList.remove('portal-gif-caption-visible');
                        gifCaption.style.display = 'none';
                    }
                }

                // Redirect after animation length (2.2s)
                setTimeout(() => {
                    window.location.href = url;
                }, 2200);
            }

            // Handlers - allow scrolling
            const _modalWheelHandler = function(e) {
                // Allow scrolling - don't prevent default
            };
            const _modalMouseDownHandler = function(e) {
                // Allow middle-button - don't prevent default
            };

            function disablePageScrollAndAutoscroll() {
                // Don't lock page scroll - allow free scrolling with modal open
            }

            function enablePageScrollAndAutoscroll() {
                // Nothing to restore since we don't lock scrolling
            }

            // Track if any modal is open
            let isModalOpen = false;

            // Utility to show modal with animation
            function showModal(modalId) {
                const modal = document.getElementById(modalId);
                if (!modal) return;
                modal.classList.remove('hide-anim');
                modal.classList.add('active');
                isModalOpen = true;
                updateModalPosition();

                // Focus first focusable element for accessibility
                setTimeout(() => {
                    const focusable = modal.querySelector('input, select, textarea, button:not(.close-modal)');
                    if (focusable) focusable.focus();
                }, 100);
            }

            // Utility to hide modal with animation
            function hideModal(modalId) {
                const modal = document.getElementById(modalId);
                if (!modal) return;
                modal.classList.add('hide-anim');

                setTimeout(() => {
                    modal.classList.remove('active');
                    modal.classList.remove('hide-anim');
                    // Check if any modal is still open
                    const anyModalOpen = document.querySelectorAll('.modal.active').length > 0;
                    isModalOpen = anyModalOpen;
                }, 400); // match modalPopOut duration
            }

            // Make modal follow scroll - positions modal at current viewport
            function updateModalPosition() {
                const modals = document.querySelectorAll('.modal.active');
                const scrollY = window.scrollY || window.pageYOffset;
                const viewportHeight = window.innerHeight;
                modals.forEach(modal => {
                    modal.style.top = scrollY + 'px';
                    modal.style.height = viewportHeight + 'px';
                });
            }

            // Get maximum scroll position (footer bottom)
            function getMaxScrollPosition() {
                const footer = document.querySelector('footer.site-footer');
                if (footer) {
                    // Allow scrolling until the footer is visible at the bottom
                    return footer.offsetTop + footer.offsetHeight - window.innerHeight;
                }
                return document.documentElement.scrollHeight - window.innerHeight;
            }

            // Enforce scroll boundary when modal is open
            function enforceScrollBoundary() {
                if (!isModalOpen) return;
                
                const maxScroll = getMaxScrollPosition();
                const currentScroll = window.scrollY || window.pageYOffset;
                
                // Prevent scrolling beyond footer
                if (currentScroll > maxScroll) {
                    window.scrollTo({
                        top: maxScroll,
                        behavior: 'auto'
                    });
                }
                
                // Prevent scrolling above the page (negative scroll)
                if (currentScroll < 0) {
                    window.scrollTo({
                        top: 0,
                        behavior: 'auto'
                    });
                }
                
                // Update modal position after boundary check
                updateModalPosition();
            }
            
            // Update modal position on scroll and resize
            window.addEventListener('scroll', function() {
                enforceScrollBoundary();
                updateModalPosition();
            });
            window.addEventListener('resize', updateModalPosition);

            // Navigation functionality
            function scrollToTop(event) {
                event.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function scrollToSection(sectionId) {
                // Close the About dropdown if open
                const aboutDropdown = document.getElementById('aboutDropdown');
                if (aboutDropdown) {
                    aboutDropdown.classList.remove('active');
                }
                
                // Close mobile menu if open
                document.getElementById('navLinks').classList.remove('show');
                
                const section = document.getElementById(sectionId);
                if (section) {
                    const navHeight = document.getElementById('navbar').offsetHeight;
                    
                    // For vision, mission, goals - scroll to the about-section container first
                    // then the specific card will be visible
                    if (['vision', 'mission', 'goals'].includes(sectionId)) {
                        const aboutSection = document.querySelector('.about-section');
                        if (aboutSection) {
                            const sectionTop = aboutSection.offsetTop - navHeight - 20;
                            window.scrollTo({ top: sectionTop, behavior: 'smooth' });
                            
                            // Add a highlight effect to the specific card
                            setTimeout(() => {
                                // Remove any existing highlights
                                document.querySelectorAll('.about-card').forEach(card => {
                                    card.style.transform = '';
                                    card.style.boxShadow = '';
                                });
                                
                                // Highlight the target card
                                section.style.transform = 'translateY(-10px) scale(1.02)';
                                section.style.boxShadow = '0 20px 50px rgba(91, 22, 161, 0.4)';
                                
                                // Remove highlight after 2 seconds
                                setTimeout(() => {
                                    section.style.transform = '';
                                    section.style.boxShadow = '';
                                }, 2000);
                            }, 500);
                        }
                    } else {
                        // For other sections, scroll directly to them
                        const sectionTop = section.offsetTop - navHeight - 20;
                        window.scrollTo({ top: sectionTop, behavior: 'smooth' });
                    }
                }
            }

            function toggleNavMenu() {
                document.getElementById('navLinks').classList.toggle('show');
            }

            // About Dropdown Toggle
            function toggleAboutDropdown(event) {
                event.stopPropagation();
                const dropdown = document.getElementById('aboutDropdown');
                dropdown.classList.toggle('active');
            }

            // Close dropdown when clicking outside
            document.addEventListener('click', function(event) {
                const dropdown = document.getElementById('aboutDropdown');
                if (dropdown && !dropdown.contains(event.target)) {
                    dropdown.classList.remove('active');
                }
            });

            // Scroll Progress Bar
            window.addEventListener('scroll', function() {
                const navbar = document.getElementById('navbar');
                const scrollProgressBar = document.getElementById('scrollProgressBar');
                
                // Calculate scroll percentage
                const windowHeight = window.innerHeight;
                const documentHeight = document.documentElement.scrollHeight;
                const scrollTop = window.scrollY || document.documentElement.scrollTop;
                const scrollPercentage = (scrollTop / (documentHeight - windowHeight)) * 100;
                
                // Update progress bar
                scrollProgressBar.style.width = scrollPercentage + '%';
                
                // Navbar scroll effect
                if (window.scrollY > 50) {
                    navbar.classList.add('scrolled');
                } else {
                    navbar.classList.remove('scrolled');
                }
            });

            // Book synopsis for table
            function showBookSynopsis(btn) {
                const row = btn.closest('tr');
                const title = row.getAttribute('data-title') || 'Untitled';
                const synopsis = row.getAttribute('data-synopsis') || 'No synopsis available.';

                Swal.fire({
                    title: `📖 ${escapeHtml(title)}`,
                    html: `<div style="text-align:left; line-height:1.6; font-size:15px; color:#444;">${escapeHtml(synopsis)}</div>`,
                    showCloseButton: true,
                    confirmButtonText: 'Close',
                    width: 520,
                    allowOutsideClick: true
                });
            }

            // Featured Books: click handler to show synopsis using SweetAlert2
            (function initFeaturedBooks() {
                const bookCards = document.querySelectorAll('.book[data-title]');
                bookCards.forEach(card => {
                    const title = card.getAttribute('data-title') || 'Untitled';
                    const synopsis = card.getAttribute('data-synopsis') || 'No synopsis available.';

                    function openSynopsis(e) {
                        // Prevent if clicking directly on btn (btn will handle it)
                        if (e && e.target.classList.contains('book-btn')) return;
                        
                        Swal.fire({
                            title: `📖 ${escapeHtml(title)}`,
                            html: `<div style="text-align:left; line-height:1.6; font-size:15px; color:#444;">${escapeHtml(synopsis)}</div>`,
                            showCloseButton: true,
                            showCancelButton: false,
                            confirmButtonText: 'Close',
                            width: 520,
                            allowOutsideClick: true,
                            customClass: {
                                confirmButton: 'btn'
                            }
                        });
                    }

                    // Click on book card
                    card.addEventListener('click', openSynopsis);
                    
                    // Click on View Synopsis button
                    const btn = card.querySelector('.book-btn');
                    if (btn) {
                        btn.addEventListener('click', (e) => {
                            e.stopPropagation();
                            openSynopsis();
                        });
                    }

                    // Keyboard accessibility
                    card.addEventListener('keydown', (e) => {
                        if (e.key === 'Enter' || e.key === ' ') {
                            e.preventDefault();
                            openSynopsis();
                        }
                    });
                });
            })();
        </script>

    </div>
    <!-- End of #mainContent -->

    <!-- Portal Animation Overlay (outside mainContent) -->
    <div id="portalOverlay">
        <div class="portal-circle">
            <span class="portal-icon">📖</span>
            <div class="portal-text">Entering Quiz Portal...</div>
        </div>
        <!-- GIF caption (shown when GIF is active) -->
        <div class="portal-gif-caption" style="display:none;">Entering Quiz Portal</div>
    </div>

    <!-- Footer -->
    <footer class="site-footer">
        <div class="footer-content">
            <div class="footer-brand">
                <a href="#" onclick="scrollToTop(event)" class="footer-logo-link">
                    <img src="../images/library_hub_logo.png" alt="Library Hub" class="footer-logo" onerror="this.style.display='none'">
                </a>
                <h3>Library Hub</h3>
                <p>Tambo, Lipa City</p>
            </div>
            <div class="footer-links">
                <div class="footer-column">
                    <h4>Hours</h4>
                    <p>Mon - Fri</p>
                    <p>7:00 AM - 4:00 PM</p>
                </div>
                <div class="footer-column">
                    <h4>Location</h4>
                    <p>Library Hub</p>
                    <p>Tambo, Lipa City</p>
                </div>
                <div class="footer-column">
                    <h4>Quick Links</h4>
                    <a href="#" onclick="scrollToSection('about-section'); return false;">About</a>
                    <a href="#" onclick="scrollToSection('qrScanner'); return false;">QR Scanner</a>
                    <a href="#" onclick="scrollToSection('featuredBooks'); return false;">Featured Books</a>
                    <a href="#" onclick="openLoginModal(); return false;">Login</a>
                </div>
            </div>
        </div>
        <div class="footer-bottom">
            <p>© 2025 Library Hub Tambo. All Rights Reserved.</p>
        </div>
    </footer>

</body>
</html>
