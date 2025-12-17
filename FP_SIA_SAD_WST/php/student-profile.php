<?php
session_start();
require_once 'config.php';

// Check if student is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'] ?? 'Student';
$student_id = $_SESSION['student_id'] ?? 'N/A';
$grade_level = $_SESSION['grade_level'] ?? 'N/A';
$email = $_SESSION['email'] ?? '';

$message = '';
$error = '';

// Handle profile edit submission (including profile picture)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] === 'request_profile_edit') {
        $new_full_name = sanitize_input($_POST['full_name']);
        $new_email = sanitize_input($_POST['email']);
        $new_profile_pic_path = null;
        
        // Handle profile picture upload
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
            $upload_dir = __DIR__ . '/../uploads/pending_profile_pictures/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            $file = $_FILES['profile_picture'];
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowed_types)) {
                $error = 'Only JPG, PNG, and GIF images are allowed.';
            } elseif ($file['size'] > $max_size) {
                $error = 'File size must be less than 5MB.';
            } else {
                $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
                $new_filename = 'pending_user_' . $user_id . '_' . time() . '.' . $extension;
                $upload_path = $upload_dir . $new_filename;
                $new_profile_pic_path = 'uploads/pending_profile_pictures/' . $new_filename;
                
                if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $error = 'Failed to upload profile picture.';
                    $new_profile_pic_path = null;
                }
            }
        }
        
        if (empty($error)) {
            // Check if there's already a pending request
            $check_sql = "SELECT request_id FROM profile_edit_requests WHERE user_id = ? AND status = 'pending'";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("i", $user_id);
            $check_stmt->execute();
            $existing = $check_stmt->get_result();
            
            if ($existing->num_rows > 0) {
                // Update existing request
                $sql = "UPDATE profile_edit_requests SET 
                        new_full_name = ?, 
                        new_email = ?, 
                        new_profile_picture = COALESCE(?, new_profile_picture),
                        requested_at = NOW() 
                        WHERE user_id = ? AND status = 'pending'";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssi", $new_full_name, $new_email, $new_profile_pic_path, $user_id);
            } else {
                // Insert new request
                $sql = "INSERT INTO profile_edit_requests (user_id, new_full_name, new_email, new_student_id, new_grade_level, new_profile_picture, status, requested_at) 
                        VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("isssss", $user_id, $new_full_name, $new_email, $student_id, $grade_level, $new_profile_pic_path);
            }
            
            if ($stmt->execute()) {
                $message = "Profile edit request submitted successfully! Waiting for admin approval.";
            } else {
                $error = "Failed to submit request. Please try again.";
            }
            $stmt->close();
        }
    }
}

// Check for pending profile edit request
$has_pending_request = false;
$pending_request = null;
$sql = "SELECT * FROM profile_edit_requests WHERE user_id = ? AND status = 'pending' ORDER BY requested_at DESC LIMIT 1";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $has_pending_request = true;
        $pending_request = $result->fetch_assoc();
    }
    $stmt->close();
}

// Get current approved profile picture
$profile_pic = null;
$pic_sql = "SELECT file_path FROM profile_pictures WHERE user_id = ?";
$pic_stmt = $conn->prepare($pic_sql);
if ($pic_stmt) {
    $pic_stmt->bind_param("i", $user_id);
    $pic_stmt->execute();
    $pic_result = $pic_stmt->get_result();
    if ($pic_result->num_rows > 0) {
        $profile_pic = $pic_result->fetch_assoc()['file_path'];
    }
    $pic_stmt->close();
}

// Get recent request history
$request_history = [];
$history_sql = "SELECT * FROM profile_edit_requests WHERE user_id = ? ORDER BY requested_at DESC LIMIT 5";
$history_stmt = $conn->prepare($history_sql);
if ($history_stmt) {
    $history_stmt->bind_param("i", $user_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();
    while ($row = $history_result->fetch_assoc()) {
        $request_history[] = $row;
    }
    $history_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - Library Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --main-gradient: linear-gradient(135deg, #5b16a1 0%, #8e2ecc 45%, #ff7a3d 100%);
            --sidebar-bg: #1a1a2e;
            --card-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fb;
            min-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100vh;
            background: var(--sidebar-bg);
            padding: 20px;
            z-index: 1000;
            transition: all 0.3s ease;
            overflow-y: auto;
        }

        .sidebar-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 15px 10px;
            margin-bottom: 30px;
            text-decoration: none;
        }

        .sidebar-brand-icon {
            width: 56px;
            height: 56px;
            background: var(--main-gradient);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .sidebar-brand-icon img.sidebar-logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
            display: block;
            margin: 0 auto;
            transition: transform 180ms ease;
        }

        .sidebar-brand-icon {
            transition: transform 180ms ease, box-shadow 180ms ease;
        }

        .sidebar-brand-icon:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.20);
        }

        .sidebar-brand-icon:hover img.sidebar-logo {
            transform: scale(1.06);
        }

        .sidebar-brand-text {
            color: white;
            font-weight: 700;
            font-size: 1.2em;
        }

        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin-bottom: 80px;
        }

        .sidebar-menu li {
            margin-bottom: 5px;
        }

        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            color: #a0a0b0;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s ease;
            font-weight: 500;
        }

        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: rgba(255,255,255,0.1);
            color: white;
        }

        .sidebar-menu a.active {
            background: var(--main-gradient);
        }

        .sidebar-menu a i {
            width: 20px;
            text-align: center;
        }

        /* Main Content */
        .main-content {
            margin-left: 260px;
            padding: 30px;
            min-height: 100vh;
        }

        /* Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-header h1 {
            font-size: 1.8em;
            font-weight: 700;
            color: #333;
        }

        /* Logout button */
        .logout-btn {
            position: fixed;
            bottom: 20px;
            left: 20px;
            width: 220px;
            padding: 14px;
            background: rgba(255,255,255,0.1);
            color: #ff6b6b;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .logout-btn:hover {
            background: #ff6b6b;
            color: white;
        }

        /* Profile Page Specific Styles */
        .profile-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .profile-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .profile-picture-section {
            text-align: center;
            margin-bottom: 40px;
        }
        
        .profile-picture-preview {
            width: 200px;
            height: 200px;
            border-radius: 50%;
            margin: 0 auto 20px;
            border: 5px solid #e0e0e0;
            overflow: hidden;
            background: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }
        
        .profile-picture-preview img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .profile-picture-preview .placeholder {
            font-size: 80px;
            color: #ccc;
        }

        .pending-badge {
            position: absolute;
            bottom: 10px;
            right: 10px;
            background: #ffc107;
            color: #333;
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 700;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-top: 30px;
        }
        
        .info-item {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 10px;
        }
        
        .info-label {
            font-size: 12px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .info-value {
            font-size: 18px;
            font-weight: 700;
            color: #333;
        }

        .btn-primary {
            background: var(--main-gradient);
            border: none;
            padding: 12px 30px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(142, 46, 204, 0.4);
            color: white;
        }

        .alert {
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
        }

        /* Edit Form Styles */
        .edit-form {
            background: linear-gradient(135deg, #f8f9ff, #fff);
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            padding: 30px;
            margin-top: 30px;
        }

        .edit-form h3 {
            color: #333;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
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

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #8e2ecc;
        }

        .form-group input:disabled {
            background: #f5f5f5;
            cursor: not-allowed;
        }

        .file-upload-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .file-upload-btn {
            background: var(--main-gradient);
            color: white;
            padding: 12px 25px;
            border-radius: 10px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }

        .file-upload-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(142, 46, 204, 0.4);
        }

        .file-upload-wrapper input[type="file"] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            width: 180px;
            height: 45px;
            cursor: pointer;
        }

        .file-name {
            color: #666;
            font-size: 14px;
        }

        .preview-container {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 15px;
        }

        .preview-image {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid #e0e0e0;
        }

        .pending-alert {
            background: linear-gradient(135deg, #fff3cd, #fff);
            border: 2px solid #ffc107;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            align-items: flex-start;
            gap: 15px;
        }

        .pending-alert .icon {
            font-size: 32px;
        }

        .pending-alert .content h4 {
            margin: 0 0 8px;
            color: #856404;
        }

        .pending-alert .content p {
            margin: 0;
            color: #856404;
            font-size: 14px;
        }

        .pending-changes {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-top: 15px;
        }

        .pending-changes h5 {
            font-size: 14px;
            color: #666;
            margin-bottom: 10px;
        }

        .change-item {
            display: flex;
            gap: 10px;
            margin-bottom: 8px;
            font-size: 13px;
        }

        .change-item .label {
            font-weight: 600;
            color: #333;
            min-width: 100px;
        }

        .change-item .value {
            color: #667eea;
        }

        /* Request History */
        .history-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.08);
        }

        .history-card h3 {
            margin-bottom: 20px;
            color: #333;
        }

        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
        }

        .history-item .info {
            flex: 1;
        }

        .history-item .date {
            font-size: 12px;
            color: #888;
        }

        .status-badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .form-actions {
            display: flex;
            gap: 15px;
            margin-top: 25px;
            justify-content: flex-end;
        }

        .btn-secondary {
            background: #6c757d;
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 10px;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-secondary:hover {
            background: #5a6268;
            color: white;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-content {
                margin-left: 0;
            }
            .info-grid {
                grid-template-columns: 1fr;
            }
            .logout-btn {
                position: relative;
                width: 100%;
                margin-top: 20px;
            }
            .form-actions {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <aside class="sidebar">
        <a href="index.php" class="sidebar-brand">
            <div class="sidebar-brand-icon"><img src="../images/library_hub_logo.png" alt="Library Hub" class="sidebar-logo"></div>
            <span class="sidebar-brand-text">Library Hub</span>
        </a>

        <ul class="sidebar-menu">
            <li><a href="student.php"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="student-profile.php" class="active"><i class="fas fa-user"></i> My Profile</a></li>
            <li><a href="student.php" data-action="scan-book"><i class="fas fa-qrcode"></i> Scan Book</a></li>
        </ul>

        <button class="logout-btn" onclick="logout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <div class="page-header">
            <h1>👤 My Profile</h1>
            <a href="student.php" class="btn-secondary">← Back to Dashboard</a>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($message); ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
        </div>
        <?php endif; ?>

        <!-- Pending Request Alert -->
        <?php if ($has_pending_request): ?>
        <div class="pending-alert">
            <div class="icon">⏳</div>
            <div class="content">
                <h4>Profile Edit Request Pending</h4>
                <p>Your profile update request is waiting for admin approval. You'll be notified once it's processed.</p>
                <small>Submitted on: <?php echo date('M d, Y g:i A', strtotime($pending_request['requested_at'])); ?></small>
                
                <div class="pending-changes">
                    <h5>Requested Changes:</h5>
                    <?php if ($pending_request['new_full_name'] !== $student_name): ?>
                    <div class="change-item">
                        <span class="label">Name:</span>
                        <span class="value"><?php echo htmlspecialchars($pending_request['new_full_name']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($pending_request['new_email'] !== $email): ?>
                    <div class="change-item">
                        <span class="label">Email:</span>
                        <span class="value"><?php echo htmlspecialchars($pending_request['new_email']); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($pending_request['new_profile_picture'])): ?>
                    <div class="change-item">
                        <span class="label">Profile Picture:</span>
                        <span class="value">New picture uploaded</span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="profile-container">
            <!-- Current Profile Card -->
            <div class="profile-card">
                <h3 style="margin-bottom: 25px; color: #333;">📋 Current Profile Information</h3>
                
                <div class="profile-picture-section">
                    <div class="profile-picture-preview">
                        <?php if ($profile_pic && file_exists(__DIR__ . '/../' . $profile_pic)): ?>
                            <img src="../<?php echo htmlspecialchars($profile_pic); ?>" alt="Profile Picture">
                        <?php else: ?>
                            <div class="placeholder">👤</div>
                        <?php endif; ?>
                        <?php if ($has_pending_request && !empty($pending_request['new_profile_picture'])): ?>
                            <span class="pending-badge">⏳ Pending</span>
                        <?php endif; ?>
                    </div>
                    <h2 style="margin: 0; color: #333;"><?php echo htmlspecialchars($student_name); ?></h2>
                    <p style="color: #666; margin-top: 5px;">Student ID: <?php echo htmlspecialchars($student_id); ?></p>
                </div>

                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Full Name</div>
                        <div class="info-value"><?php echo htmlspecialchars($student_name); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Student ID</div>
                        <div class="info-value"><?php echo htmlspecialchars($student_id); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Email</div>
                        <div class="info-value"><?php echo htmlspecialchars($email ?: 'Not set'); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Grade Level</div>
                        <div class="info-value">Grade <?php echo htmlspecialchars($grade_level); ?></div>
                    </div>
                </div>

                <!-- Edit Profile Form -->
                <div class="edit-form">
                    <h3>✏️ Request Profile Changes</h3>
                    <p style="color: #666; margin-bottom: 20px; font-size: 14px;">
                        <i class="fas fa-info-circle"></i> All changes require admin approval before they take effect.
                    </p>
                    
                    <form method="POST" enctype="multipart/form-data" id="profileEditForm">
                        <input type="hidden" name="action" value="request_profile_edit">
                        
                        <div class="form-group">
                            <label><i class="fas fa-image"></i> Profile Picture</label>
                            <div class="file-upload-wrapper">
                                <button type="button" class="file-upload-btn">
                                    <i class="fas fa-camera"></i> Choose New Photo
                                </button>
                                <input type="file" name="profile_picture" id="profilePictureInput" accept="image/*" onchange="previewImage(this)">
                                <span class="file-name" id="fileName">No file chosen</span>
                            </div>
                            <small style="color: #888; margin-top: 8px; display: block;">Maximum size: 5MB (JPG, PNG, GIF)</small>
                            
                            <div class="preview-container" id="previewContainer" style="display: none;">
                                <img src="" alt="Preview" class="preview-image" id="imagePreview">
                                <span style="color: #28a745; font-weight: 600;"><i class="fas fa-check"></i> New photo selected</span>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Full Name</label>
                            <input type="text" name="full_name" value="<?php echo htmlspecialchars($student_name); ?>" 
                                   required <?php echo $has_pending_request ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-envelope"></i> Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" 
                                   required <?php echo $has_pending_request ? 'disabled' : ''; ?>>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-id-card"></i> Student ID</label>
                            <input type="text" value="<?php echo htmlspecialchars($student_id); ?>" disabled>
                            <small style="color: #888;">Student ID cannot be changed</small>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-graduation-cap"></i> Grade Level</label>
                            <input type="text" value="Grade <?php echo htmlspecialchars($grade_level); ?>" disabled>
                            <small style="color: #888;">Contact admin to change grade level</small>
                        </div>
                        
                        <div class="form-actions">
                            <a href="student.php" class="btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary" <?php echo $has_pending_request ? 'disabled' : ''; ?>>
                                <i class="fas fa-paper-plane"></i> Submit for Approval
                            </button>
                        </div>
                        
                        <?php if ($has_pending_request): ?>
                        <p style="text-align: center; color: #856404; margin-top: 15px; font-size: 14px;">
                            <i class="fas fa-exclamation-triangle"></i> You have a pending request. Please wait for admin approval before submitting a new one.
                        </p>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Request History -->
            <?php if (!empty($request_history)): ?>
            <div class="history-card">
                <h3><i class="fas fa-history"></i> Recent Request History</h3>
                <?php foreach ($request_history as $history): ?>
                <div class="history-item">
                    <div class="info">
                        <strong>Profile Update Request</strong>
                        <div class="date"><?php echo date('M d, Y g:i A', strtotime($history['requested_at'])); ?></div>
                    </div>
                    <span class="status-badge status-<?php echo $history['status']; ?>">
                        <?php 
                        if ($history['status'] === 'pending') echo '⏳ Pending';
                        elseif ($history['status'] === 'approved') echo '✅ Approved';
                        else echo '❌ Rejected';
                        ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function previewImage(input) {
            const fileName = document.getElementById('fileName');
            const previewContainer = document.getElementById('previewContainer');
            const imagePreview = document.getElementById('imagePreview');
            
            if (input.files && input.files[0]) {
                fileName.textContent = input.files[0].name;
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    imagePreview.src = e.target.result;
                    previewContainer.style.display = 'flex';
                };
                reader.readAsDataURL(input.files[0]);
            } else {
                fileName.textContent = 'No file chosen';
                previewContainer.style.display = 'none';
            }
        }

        function logout() {
            Swal.fire({
                title: 'Logout?',
                text: 'Are you sure you want to logout?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, logout',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#dc3545'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'logout.php';
                }
            });
        }

        // Form validation
        document.getElementById('profileEditForm').addEventListener('submit', function(e) {
            const fileInput = document.getElementById('profilePictureInput');
            const fullName = document.querySelector('input[name="full_name"]').value;
            const email = document.querySelector('input[name="email"]').value;
            
            // Check if any changes were made
            const originalName = '<?php echo addslashes($student_name); ?>';
            const originalEmail = '<?php echo addslashes($email); ?>';
            
            if (fullName === originalName && email === originalEmail && !fileInput.files[0]) {
                e.preventDefault();
                Swal.fire({
                    icon: 'info',
                    title: 'No Changes',
                    text: 'Please make at least one change before submitting.',
                });
                return false;
            }
            
            // Show loading
            Swal.fire({
                title: 'Submitting Request...',
                text: 'Please wait while your request is being processed.',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
        });

        // Handle scan book link
        document.querySelectorAll('.sidebar-menu a[data-action="scan-book"]').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                sessionStorage.setItem('openScanner', 'true');
                window.location.href = 'student.php';
            });
        });
    </script>
</body>
</html>
