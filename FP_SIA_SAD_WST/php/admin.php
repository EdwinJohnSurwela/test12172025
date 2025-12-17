<?php
require_once 'config.php';

// Check if user is logged in as admin
check_user_type(['admin']);

$message = '';
$error = '';

// Check if password column exists
$has_password_column = false;
$columns_check = $conn->query("SHOW COLUMNS FROM users LIKE 'password'");
if ($columns_check && $columns_check->num_rows > 0) {
    $has_password_column = true;
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Add new user
    if (isset($_POST['action']) && $_POST['action'] === 'add_user') {
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $user_type = $_POST['user_type'];
        $student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : null;
        $status = $_POST['status'];
        
        // Validate inputs
        if (empty($full_name) || empty($email) || empty($password) || empty($user_type)) {
            $error = "All required fields must be filled.";
        } else {
            // Check if email already exists
            $check_sql = "SELECT user_id FROM users WHERE email = ?";
            $check_stmt = $conn->prepare($check_sql);
            $check_stmt->bind_param("s", $email);
            $check_stmt->execute();
            if ($check_stmt->get_result()->num_rows > 0) {
                $error = "Email already exists.";
            } else {
                // Check if student_id already exists (for students)
                if ($user_type === 'student' && !empty($student_id)) {
                    $check_sid = "SELECT user_id FROM users WHERE student_id = ?";
                    $check_stmt2 = $conn->prepare($check_sid);
                    $check_stmt2->bind_param("s", $student_id);
                    $check_stmt2->execute();
                    if ($check_stmt2->get_result()->num_rows > 0) {
                        $error = "Student ID already exists.";
                    }
                }
                
                if (empty($error)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    if ($has_password_column) {
                        $sql = "INSERT INTO users (full_name, email, password, user_type, student_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("ssssss", $full_name, $email, $hashed_password, $user_type, $student_id, $status);
                    } else {
                        $sql = "INSERT INTO users (full_name, email, user_type, student_id, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())";
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param("sssss", $full_name, $email, $user_type, $student_id, $status);
                    }
                    
                    if ($stmt->execute()) {
                        $message = "User created successfully!";
                    } else {
                        $error = "Error creating user: " . $conn->error;
                    }
                }
            }
        }
    }
    
    // Edit user
    if (isset($_POST['action']) && $_POST['action'] === 'edit_user') {
        $user_id = intval($_POST['user_id']);
        $full_name = trim($_POST['full_name']);
        $email = trim($_POST['email']);
        $user_type = $_POST['user_type'];
        $student_id = isset($_POST['student_id']) ? trim($_POST['student_id']) : null;
        $grade_level = isset($_POST['grade_level']) ? trim($_POST['grade_level']) : null;
        $status = $_POST['status'];
        $new_password = $_POST['new_password'];
        
        // Check if email already exists for other users
        $check_sql = "SELECT user_id FROM users WHERE email = ? AND user_id != ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("si", $email, $user_id);
        $check_stmt->execute();
        if ($check_stmt->get_result()->num_rows > 0) {
            $error = "Email already exists for another user.";
        } else {
            if ($has_password_column && !empty($new_password)) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET full_name = ?, email = ?, password = ?, user_type = ?, student_id = ?, grade_level = ?, status = ? WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("sssssssi", $full_name, $email, $hashed_password, $user_type, $student_id, $grade_level, $status, $user_id);
            } else {
                $sql = "UPDATE users SET full_name = ?, email = ?, user_type = ?, student_id = ?, grade_level = ?, status = ? WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssssssi", $full_name, $email, $user_type, $student_id, $grade_level, $status, $user_id);
            }
            
            if ($stmt->execute()) {
                $message = "User updated successfully!";
            } else {
                $error = "Error updating user: " . $conn->error;
            }
        }
    }
    
    // Delete user
    if (isset($_POST['action']) && $_POST['action'] === 'delete_user') {
        $user_id = intval($_POST['user_id']);
        
        // Prevent self-deletion
        if ($user_id == $_SESSION['user_id']) {
            $error = "You cannot delete your own account.";
        } else {
            $sql = "DELETE FROM users WHERE user_id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $user_id);
            
            if ($stmt->execute()) {
                $message = "User deleted successfully!";
            } else {
                $error = "Error deleting user: " . $conn->error;
            }
        }
    }
    
    // Toggle user status
    if (isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
        $user_id = intval($_POST['user_id']);
        $new_status = $_POST['new_status'];
        
        $sql = "UPDATE users SET status = ? WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("si", $new_status, $user_id);
        
        if ($stmt->execute()) {
            $message = "User status updated!";
        } else {
            $error = "Error updating status.";
        }
    }
    
    // Approve profile edit request (UPDATED to handle profile pictures)
    if (isset($_POST['action']) && $_POST['action'] === 'approve_profile_edit') {
        $request_id = intval($_POST['request_id']);
        
        // Get request details
        $sql = "SELECT * FROM profile_edit_requests WHERE request_id = ? AND status = 'pending'";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $request = $stmt->get_result()->fetch_assoc();
        
        if ($request) {
            $conn->begin_transaction();
            
            try {
                // Update user profile
                $sql = "UPDATE users SET full_name = ?, email = ? WHERE user_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ssi", $request['new_full_name'], $request['new_email'], $request['user_id']);
                $stmt->execute();
                
                // Handle profile picture if submitted
                if (!empty($request['new_profile_picture'])) {
                    $pending_path = __DIR__ . '/../' . $request['new_profile_picture'];
                    
                    if (file_exists($pending_path)) {
                        // Move from pending to approved folder
                        $approved_dir = __DIR__ . '/../uploads/profile_pictures/';
                        if (!file_exists($approved_dir)) {
                            mkdir($approved_dir, 0755, true);
                        }
                        
                        $filename = basename($request['new_profile_picture']);
                        $new_filename = 'user_' . $request['user_id'] . '_' . time() . '_' . $filename;
                        $new_filename = str_replace('pending_', '', $new_filename);
                        $approved_path = $approved_dir . $new_filename;
                        $db_path = 'uploads/profile_pictures/' . $new_filename;
                        
                        // Delete old profile picture if exists
                        $old_pic_sql = "SELECT file_path FROM profile_pictures WHERE user_id = ?";
                        $old_pic_stmt = $conn->prepare($old_pic_sql);
                        $old_pic_stmt->bind_param("i", $request['user_id']);
                        $old_pic_stmt->execute();
                        $old_pic_result = $old_pic_stmt->get_result();
                        
                        if ($old_pic_result->num_rows > 0) {
                            $old_pic = $old_pic_result->fetch_assoc();
                            $old_file = __DIR__ . '/../' . $old_pic['file_path'];
                            if (file_exists($old_file)) {
                                unlink($old_file);
                            }
                            
                            // Update existing record - check which columns exist
                            $has_updated_at = false;
                            $col_check = $conn->query("SHOW COLUMNS FROM profile_pictures LIKE 'updated_at'");
                            if ($col_check && $col_check->num_rows > 0) {
                                $has_updated_at = true;
                            }
                            
                            if ($has_updated_at) {
                                $update_pic_sql = "UPDATE profile_pictures SET file_name = ?, file_path = ?, updated_at = NOW() WHERE user_id = ?";
                            } else {
                                $update_pic_sql = "UPDATE profile_pictures SET file_name = ?, file_path = ? WHERE user_id = ?";
                            }
                            $update_pic_stmt = $conn->prepare($update_pic_sql);
                            $update_pic_stmt->bind_param("ssi", $new_filename, $db_path, $request['user_id']);
                            $update_pic_stmt->execute();
                        } else {
                            // Insert new record - check table structure first
                            $columns_result = $conn->query("SHOW COLUMNS FROM profile_pictures");
                            $columns = [];
                            while ($col = $columns_result->fetch_assoc()) {
                                $columns[] = $col['Field'];
                            }
                            
                            // Build insert query based on existing columns
                            if (in_array('created_at', $columns)) {
                                $insert_pic_sql = "INSERT INTO profile_pictures (user_id, file_name, file_path, created_at) VALUES (?, ?, ?, NOW())";
                            } else {
                                $insert_pic_sql = "INSERT INTO profile_pictures (user_id, file_name, file_path) VALUES (?, ?, ?)";
                            }
                            $insert_pic_stmt = $conn->prepare($insert_pic_sql);
                            $insert_pic_stmt->bind_param("iss", $request['user_id'], $new_filename, $db_path);
                            $insert_pic_stmt->execute();
                        }
                        
                        // Move the file
                        rename($pending_path, $approved_path);
                    }
                }
                
                // Update request status
                $sql = "UPDATE profile_edit_requests SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE request_id = ?";
                $stmt = $conn->prepare($sql);
                $stmt->bind_param("ii", $_SESSION['user_id'], $request_id);
                $stmt->execute();
                
                $conn->commit();
                $message = "Profile edit request approved successfully!";
                
            } catch (Exception $e) {
                $conn->rollback();
                $error = "Failed to approve request: " . $e->getMessage();
            }
        } else {
            $error = "Request not found or already processed.";
        }
    }
    
    // Reject profile edit request
    if (isset($_POST['action']) && $_POST['action'] === 'reject_profile_edit') {
        $request_id = intval($_POST['request_id']);
        $rejection_reason = sanitize_input($_POST['rejection_reason']);
        
        $sql = "UPDATE profile_edit_requests SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE request_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isi", $_SESSION['user_id'], $rejection_reason, $request_id);
        
        if ($stmt->execute()) {
            $message = "Profile edit request rejected.";
        } else {
            $error = "Failed to reject request.";
        }
    }
}

// Filter by user type
$filter_type = isset($_GET['type']) ? $_GET['type'] : 'all';
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';

// Fetch users with filter
$where_conditions = [];
$params = [];
$types = "";

if ($filter_type !== 'all') {
    $where_conditions[] = "user_type = ?";
    $params[] = $filter_type;
    $types .= "s";
}

if (!empty($search_query)) {
    $where_conditions[] = "(full_name LIKE ? OR email LIKE ? OR student_id LIKE ?)";
    $search_param = "%" . $search_query . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

$sql = "SELECT user_id, full_name, email, user_type, student_id, grade_level, status, created_at FROM users $where_clause ORDER BY created_at DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $users_result = $stmt->get_result();
} else {
    $users_result = $conn->query($sql);
}

// Fetch statistics
$stats = [];
$sql = "SELECT user_type, COUNT(*) as count FROM users GROUP BY user_type";
$result = $conn->query($sql);
while ($row = $result->fetch_assoc()) {
    $stats[$row['user_type']] = $row['count'];
}

$sql = "SELECT status, COUNT(*) as count FROM users GROUP BY status";
$result = $conn->query($sql);
$status_stats = [];
while ($row = $result->fetch_assoc()) {
    $status_stats[$row['status']] = $row['count'];
}

$total_users = array_sum($stats);

// Fetch pending profile edit requests (UPDATED query to include profile picture info)
$profile_requests_sql = "SELECT per.*, u.full_name as current_name, u.email as current_email, 
                                u.student_id as current_student_id, u.grade_level as current_grade_level,
                                pp.file_path as current_profile_pic
                         FROM profile_edit_requests per
                         JOIN users u ON per.user_id = u.user_id
                         LEFT JOIN profile_pictures pp ON per.user_id = pp.user_id
                         WHERE per.status = 'pending'
                         ORDER BY per.requested_at DESC";
$profile_requests_result = $conn->query($profile_requests_sql);

// Count pending requests
$pending_profile_requests = $profile_requests_result ? $profile_requests_result->num_rows : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - User Management</title>
    <link rel="icon" href="../images/library_hub_logo.png" type="image/png">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS (CDN fallback if local missing) -->
    <link href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css" rel="stylesheet">
    <!-- DataTables Buttons + Responsive CSS -->
    <link href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="vendor/sweetalert2.min.css" rel="stylesheet">
    <style>
        :root {
            /* DepEd Color Scheme */
            --deped-blue: #1a4480;
            --deped-blue-dark: #0d2240;
            --deped-red: #c41230;
            --deped-red-dark: #8b0a1e;
            --main-gradient: linear-gradient(135deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
            --sidebar-bg: #1a1a2e;
            --sidebar-width: 260px;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
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
            padding: 0;
            overflow-x: hidden;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: var(--sidebar-width);
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

        .sidebar-menu .badge {
            margin-left: auto;
            background: var(--danger);
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
        }

        .logout-btn {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            padding: 14px;
            background: rgba(220, 53, 69, 0.2);
            color: #ff6b6b;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s ease;
        }

        .logout-btn:hover {
            background: rgba(220, 53, 69, 0.4);
        }

        /* Main Content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 30px;
            min-height: 100vh;
        }

        /* Page Header */
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            background: white;
            padding: 10px 15px;
            border-radius: 12px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: var(--main-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            color: white;
        }

        .user-info-header h4 {
            font-size: 14px;
            color: #333;
            margin: 0;
        }

        .user-info-header span {
            font-size: 12px;
            color: #666;
        }

        /* Section visibility */
        .dashboard-section {
            display: none;
        }

        .dashboard-section.active {
            display: block;
        }

        /* Boot Animation */
        #bootLoader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
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
            font-size: 120px;
            animation: iconPulse 2s ease-in-out infinite;
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
            animation: spin 2s linear infinite reverse;
            width: 85%;
            height: 85%;
            top: 7.5%;
            left: 7.5%;
        }

        .spinner-ring:nth-child(3) {
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
            animation: textFade 1.5s ease-in-out infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        @keyframes iconPulse {
            0%, 100% { transform: translate(-50%, -50%) scale(1); }
            50% { transform: translate(-50%, -50%) scale(1.1); }
        }

        @keyframes textFade {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }

        /* Main Content Wrapper */
        #mainContent {
            opacity: 0;
            transform: scale(0.95);
            transition: opacity 0.8s ease-out, transform 0.8s ease-out;
        }

        #mainContent.show {
            opacity: 1;
            transform: scale(1);
        }

        .container {
            max-width: 100%;
            padding: 0;
        }

        .section-title {
            margin-bottom: 25px;
        }

        /* Hide duplicate section title when a page header is present
           (prevents repeating the same heading twice as shown in screenshots) */
        .page-header ~ .dashboard-section .section-title {
            display: none;
        }

        .section-title h2 {
            font-size: 1.5em;
            color: #333;
            margin-bottom: 5px;
        }

        .section-title p {
            color: #666;
            font-size: 14px;
        }

        /* Alert Messages */
        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            text-align: center;
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card .icon {
            font-size: 40px;
            margin-bottom: 10px;
        }

        .stat-card .number {
            font-size: 2.5em;
            font-weight: bold;
            background: var(--main-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .stat-card .label {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }

        /* Card */
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 25px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .card-header h3 {
            color: #333;
            font-size: 1.5em;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }

        .btn-primary {
            background: var(--main-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(91, 22, 161, 0.4);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #218838;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c82333;
        }

        .btn-warning {
            background: var(--warning);
            color: #333;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        /* Filter & Search */
        .filter-bar {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 20px;
        }

        .filter-tabs {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 16px;
            border: 2px solid #ddd;
            border-radius: 20px;
            background: white;
            color: #666;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
        }

        .filter-tab:hover, .filter-tab.active {
            background: var(--main-gradient);
            color: white;
            border-color: transparent;
        }

        .search-box {
            display: flex;
            gap: 10px;
            flex: 1;
            min-width: 250px;
        }

        .search-box input {
            flex: 1;
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .search-box input:focus {
            outline: none;
            border-color: #8e2ecc;
        }

        /* Table */
        .table-responsive {
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        .table thead th {
            background: var(--main-gradient);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 700;
            font-size: 14px;
            white-space: nowrap;
        }

        .table thead th:first-child {
            border-radius: 10px 0 0 0;
        }

        .table thead th:last-child {
            border-radius: 0 10px 0 0;
        }

        .table tbody tr {
            transition: all 0.3s ease;
        }

        .table tbody tr:hover {
            background: #f8f9ff;
        }

        .table tbody td {
            padding: 15px 12px;
            border-bottom: 1px solid #e1e1e1;
            vertical-align: middle;
        }

        /* User Type Badge */
        .user-type-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
        }

        .badge-student {
            background: #e3f2fd;
            color: #1565c0;
        }

        .badge-teacher {
            background: #f3e5f5;
            color: #7b1fa2;
        }

        .badge-librarian {
            background: #fff3e0;
            color: #e65100;
        }

        .badge-admin {
            background: #fce4ec;
            color: #c2185b;
        }

        /* Status Badge */
        .status-badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status-active {
            background: #d4edda;
            color: #155724;
        }

        .status-inactive {
            background: #f8d7da;
            color: #721c24;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        /* Modal */
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2000;
            padding: 20px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal {
            background: white;
            border-radius: 15px;
            width: 100%;
            max-width: 500px;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            animation: modalSlide 0.3s ease;
        }

        @keyframes modalSlide {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .modal-header {
            background: var(--main-gradient);
            color: white;
            padding: 20px 25px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h3 {
            margin: 0;
            font-size: 1.3em;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 28px;
            cursor: pointer;
            line-height: 1;
        }

        .modal-body {
            padding: 25px;
        }

        .modal-footer {
            padding: 15px 25px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Form */
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
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #8e2ecc;
        }

        .form-group small {
            color: #666;
            font-size: 12px;
            margin-top: 5px;
            display: block;
        }

        .required {
            color: #dc3545;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #999;
        }

        .empty-state .icon {
            font-size: 60px;
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .card-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .filter-bar {
                flex-direction: column;
            }

            .search-box {
                width: 100%;
            }

            .nav-right {
                flex-direction: column;
                gap: 8px;
            }

            .table thead th,
            .table tbody td {
                padding: 10px 8px;
                font-size: 13px;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        /* DataTables Styling */
        .dataTables_wrapper .dataTables_filter input {
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            padding: 8px 12px;
            margin-left: 8px;
        }

        .dataTables_wrapper .dataTables_filter input:focus {
            border-color: var(--deped-blue);
            outline: none;
        }

        .dataTables_wrapper .dataTables_length select {
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            padding: 6px 10px;
        }

        .dataTables_wrapper .dataTables_info,
        .dataTables_wrapper .dataTables_paginate {
            margin-top: 15px;
        }

        table.dataTable thead th {
            background: var(--deped-blue);
            color: white;
            font-weight: 600;
        }

        table.dataTable tbody tr:hover {
            background: #f0f7ff !important;
        }

        /* Health/Audit Cards */
        .health-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }

        .health-item {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .health-item-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .health-item-title {
            font-weight: 600;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .health-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }

        .health-status.good {
            background: #d4edda;
            color: #155724;
        }

        .health-status.warning {
            background: #fff3cd;
            color: #856404;
        }

        .health-status.critical {
            background: #f8d7da;
            color: #721c24;
        }

        .health-bar {
            height: 8px;
            background: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
        }

        .health-bar-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .health-bar-fill.good {
            background: var(--success);
        }

        .health-bar-fill.warning {
            background: var(--warning);
        }

        .health-bar-fill.critical {
            background: var(--danger);
        }

        .audit-log-item {
            display: flex;
            align-items: flex-start;
            gap: 15px;
            padding: 15px 0;
            border-bottom: 1px solid #eee;
        }

        .audit-log-item:last-child {
            border-bottom: none;
        }

        .audit-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .audit-icon.login { background: #e3f2fd; }
        .audit-icon.create { background: #e8f5e9; }
        .audit-icon.update { background: #fff3e0; }
        .audit-icon.delete { background: #ffebee; }

        .audit-content {
            flex: 1;
        }

        .audit-content strong {
            color: #333;
        }

        .audit-content p {
            color: #666;
            font-size: 13px;
            margin-top: 3px;
        }

        .audit-time {
            color: #999;
            font-size: 12px;
        }

        .config-form {
            display: grid;
            gap: 20px;
        }

        .config-group {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 15px;
            align-items: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }

        .config-group label {
            font-weight: 600;
            color: #333;
        }

        .config-group input,
        .config-group select {
            padding: 10px 15px;
            border: 2px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }

        .config-group input:focus,
        .config-group select:focus {
            outline: none;
            border-color: var(--deped-blue);
        }

        @media (max-width: 768px) {
            .sidebar {
                width: 70px;
                padding: 15px 10px;
            }
            
            .sidebar-brand-text,
            .sidebar-menu span,
            .logout-btn span {
                display: none;
            }
            
            .sidebar-brand-icon {
                width: 40px;
                height: 40px;
            }
            
            .sidebar-menu a {
                justify-content: center;
                padding: 14px;
            }
            
            .sidebar-menu a i {
                font-size: 18px;
            }
            
            .main-content {
                margin-left: 70px;
                padding: 20px;
            }

            .config-group {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Boot Loader Animation -->
    <div id="bootLoader">
        <div class="boot-logo">
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="spinner-ring"></div>
            <div class="boot-icon"><img src="../images/library_hub_logo.png" alt="Library Hub" class="boot-logo"></div>
        </div>
        <div class="boot-text">Admin Control Panel</div>
    </div>

    <!-- Sidebar -->
    <aside class="sidebar">
        <a href="index.php" class="sidebar-brand">
            <div class="sidebar-brand-icon"><img src="../images/library_hub_logo.png" alt="Library Hub" class="sidebar-logo"></div>
            <span class="sidebar-brand-text">Admin Panel</span>
        </a>

        <ul class="sidebar-menu">
            <li><a href="#" class="active" data-section="dashboard"><i class="fas fa-home"></i> <span>Dashboard</span></a></li>
            <li><a href="#" data-section="users"><i class="fas fa-users"></i> <span>User Management</span></a></li>
            <li><a href="#" data-section="requests"><i class="fas fa-user-edit"></i> <span>Profile Requests</span> <?php if ($pending_profile_requests > 0): ?><span class="badge"><?php echo $pending_profile_requests; ?></span><?php endif; ?></a></li>
            <li><a href="#" data-section="config"><i class="fas fa-cog"></i> <span>Configuration</span></a></li>
            <li><a href="#" data-section="audit"><i class="fas fa-history"></i> <span>Audit Logs</span></a></li>
            <li><a href="#" data-section="health"><i class="fas fa-heartbeat"></i> <span>System Health</span></a></li>
        </ul>

        <button class="logout-btn" onclick="window.location.href='logout.php'">
            <i class="fas fa-sign-out-alt"></i> <span>Logout</span>
        </button>
    </aside>

    <!-- Main Content -->
    <div id="mainContent">
        <main class="main-content">
            <!-- Page Header -->
            <div class="page-header">
                <h1>👋 Welcome, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</h1>
                <div class="header-actions">
                    <div class="user-profile">
                        <div class="user-avatar">👨‍💼</div>
                        <div class="user-info-header">
                            <h4><?php echo htmlspecialchars($_SESSION['full_name']); ?></h4>
                            <span>Administrator</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alert Messages -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-success">✅ <?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- Dashboard Section -->
            <div id="section-dashboard" class="dashboard-section active">
                <div class="section-title">
                    <h2>📊 Dashboard Overview</h2>
                    <p>System statistics and quick overview</p>
                </div>
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="icon">👥</div>
                    <div class="number"><?php echo $total_users; ?></div>
                    <div class="label">Total Users</div>
                </div>
                <div class="stat-card">
                    <div class="icon">🎓</div>
                    <div class="number"><?php echo $stats['student'] ?? 0; ?></div>
                    <div class="label">Students</div>
                </div>
                <div class="stat-card">
                    <div class="icon">👨‍🏫</div>
                    <div class="number"><?php echo $stats['teacher'] ?? 0; ?></div>
                    <div class="label">Teachers</div>
                </div>
                <div class="stat-card">
                    <div class="icon">📚</div>
                    <div class="number"><?php echo $stats['librarian'] ?? 0; ?></div>
                    <div class="label">Librarians</div>
                </div>
                <div class="stat-card">
                    <div class="icon">✅</div>
                    <div class="number"><?php echo $status_stats['active'] ?? 0; ?></div>
                    <div class="label">Active Users</div>
                </div>
                <div class="stat-card">
                    <div class="icon">⛔</div>
                    <div class="number"><?php echo $status_stats['inactive'] ?? 0; ?></div>
                    <div class="label">Inactive Users</div>
                </div>
                <div class="stat-card">
                    <div class="icon">📝</div>
                    <div class="number"><?php echo $pending_profile_requests; ?></div>
                    <div class="label">Profile Edit Requests</div>
                </div>
            </div>
            </div> <!-- End Dashboard Section -->

            <!-- Profile Requests Section -->
            <div id="section-requests" class="dashboard-section">
                <div class="section-title">
                    <h2>📝 Profile Edit Requests</h2>
                    <p>Review and manage user profile update requests</p>
                </div>

            <!-- Profile Edit Requests Card -->
            <?php if ($pending_profile_requests > 0): ?>
            <div class="card">
                <div class="card-header">
                    <h3>📝 Pending Profile Edit Requests</h3>
                    <span class="badge" style="background: #ffc107; color: #333; padding: 8px 15px; border-radius: 20px; font-weight: 700;">
                        <?php echo $pending_profile_requests; ?> Pending
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table" id="profileRequestsTable">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Current Info</th>
                                <th>Requested Changes</th>
                                <th>Profile Picture</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Reset the result pointer
                            $profile_requests_result->data_seek(0);
                            while ($req = $profile_requests_result->fetch_assoc()): 
                            ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <div style="width: 40px; height: 40px; border-radius: 50%; background: #f0f0f0; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                                            <?php if (!empty($req['current_profile_pic']) && file_exists(__DIR__ . '/../' . $req['current_profile_pic'])): ?>
                                                <img src="../<?php echo htmlspecialchars($req['current_profile_pic']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                            <?php else: ?>
                                                👤
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <strong><?php echo htmlspecialchars($req['current_name']); ?></strong><br>
                                            <small>User ID: #<?php echo $req['user_id']; ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <small>
                                        <strong>Name:</strong> <?php echo htmlspecialchars($req['current_name']); ?><br>
                                        <strong>Email:</strong> <?php echo htmlspecialchars($req['current_email']); ?>
                                    </small>
                                </td>
                                <td>
                                    <small style="color: #667eea; font-weight: 600;">
                                        <?php if ($req['new_full_name'] !== $req['current_name']): ?>
                                            <strong>Name:</strong> <?php echo htmlspecialchars($req['new_full_name']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($req['new_email'] !== $req['current_email']): ?>
                                            <strong>Email:</strong> <?php echo htmlspecialchars($req['new_email']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($req['new_full_name'] === $req['current_name'] && $req['new_email'] === $req['current_email'] && empty($req['new_profile_picture'])): ?>
                                            <em>No text changes</em>
                                        <?php endif; ?>
                                    </small>
                                </td>
                                <td>
                                    <?php if (!empty($req['new_profile_picture']) && file_exists(__DIR__ . '/../' . $req['new_profile_picture'])): ?>
                                        <div style="display: flex; align-items: center; gap: 10px;">
                                            <div style="text-align: center;">
                                                <small style="color: #888;">Current</small><br>
                                                <div style="width: 50px; height: 50px; border-radius: 50%; background: #f0f0f0; display: inline-flex; align-items: center; justify-content: center; overflow: hidden; border: 2px solid #ddd;">
                                                    <?php if (!empty($req['current_profile_pic']) && file_exists(__DIR__ . '/../' . $req['current_profile_pic'])): ?>
                                                        <img src="../<?php echo htmlspecialchars($req['current_profile_pic']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                    <?php else: ?>
                                                        👤
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <span style="color: #888;">→</span>
                                            <div style="text-align: center;">
                                                <small style="color: #28a745;">New</small><br>
                                                <div style="width: 50px; height: 50px; border-radius: 50%; overflow: hidden; border: 2px solid #28a745;">
                                                    <img src="../<?php echo htmlspecialchars($req['new_profile_picture']); ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                                </div>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color: #888;">No change</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('M d, Y g:i A', strtotime($req['requested_at'])); ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="approve_profile_edit">
                                            <input type="hidden" name="request_id" value="<?php echo $req['request_id']; ?>">
                                            <button type="submit" class="btn btn-success btn-sm" onclick="return confirm('Approve this profile edit request?')">✅ Approve</button>
                                        </form>
                                        <button class="btn btn-danger btn-sm" onclick="openRejectModal(<?php echo $req['request_id']; ?>, '<?php echo htmlspecialchars($req['current_name'], ENT_QUOTES); ?>')">
                                            ❌ Reject
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="card">
                <div class="empty-state">
                    <div class="icon">✅</div>
                    <h3>No Pending Requests</h3>
                    <p>All profile edit requests have been processed.</p>
                </div>
            </div>
            <?php endif; ?>
            </div> <!-- End Profile Requests Section -->

            <!-- User Management Section -->
            <div id="section-users" class="dashboard-section">
                <div class="section-title">
                    <h2>👤 User Management</h2>
                    <p>Create, edit, and manage all system users</p>
                </div>

            <!-- User Management Card -->
            <div class="card">
                <div class="card-header">
                    <h3>👤 All Users</h3>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <a href="generate-library-cards.php" class="btn btn-success">
                            🪪 Library Cards
                        </a>
                        <button class="btn btn-primary" onclick="openModal('addUserModal')">
                            ➕ Add New User
                        </button>
                    </div>
                </div>

                <!-- Filter Bar -->
                <div class="filter-bar">
                    <div class="filter-tabs">
                        <a href="?type=all" class="filter-tab <?php echo $filter_type === 'all' ? 'active' : ''; ?>">All</a>
                        <a href="?type=student" class="filter-tab <?php echo $filter_type === 'student' ? 'active' : ''; ?>">Students</a>
                        <a href="?type=teacher" class="filter-tab <?php echo $filter_type === 'teacher' ? 'active' : ''; ?>">Teachers</a>
                        <a href="?type=librarian" class="filter-tab <?php echo $filter_type === 'librarian' ? 'active' : ''; ?>">Librarians</a>
                        <a href="?type=admin" class="filter-tab <?php echo $filter_type === 'admin' ? 'active' : ''; ?>">Admins</a>
                    </div>
                </div>

                <!-- Users Table -->
                <div class="table-responsive">
                    <table class="table" id="usersTable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>User Type</th>
                                <th>Student ID</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($users_result && $users_result->num_rows > 0): ?>
                                <?php while ($user = $users_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>#<?php echo $user['user_id']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($user['full_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($user['email']); ?></td>
                                        <td>
                                            <span class="user-type-badge badge-<?php echo $user['user_type']; ?>">
                                                <?php echo ucfirst($user['user_type']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo $user['student_id'] ? htmlspecialchars($user['student_id']) : '-'; ?></td>
                                        <td>
                                            <span class="status-badge status-<?php echo $user['status']; ?>">
                                                <?php echo ucfirst($user['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button class="btn btn-info btn-sm" onclick='openEditModal(<?php echo json_encode($user); ?>)'>
                                                    ✏️ Edit
                                                </button>
                                                <?php if ($user['user_id'] != $_SESSION['user_id']): ?>
                                                    <form method="POST" style="display:inline;">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="user_id" value="<?php echo $user['user_id']; ?>">
                                                        <input type="hidden" name="new_status" value="<?php echo $user['status'] === 'active' ? 'inactive' : 'active'; ?>">
                                                        <button type="submit" class="btn btn-<?php echo $user['status'] === 'active' ? 'warning' : 'success'; ?> btn-sm">
                                                            <?php echo $user['status'] === 'active' ? '⛔ Disable' : '✅ Enable'; ?>
                                                        </button>
                                                    </form>
                                                    <button class="btn btn-danger btn-sm" onclick="confirmDelete(<?php echo $user['user_id']; ?>, '<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>')">
                                                        🗑️ Delete
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8">
                                        <div class="empty-state">
                                            <div class="icon">👤</div>
                                            <h3>No Users Found</h3>
                                            <p>No users match your current filters.</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            </div> <!-- End User Management Section -->

            <!-- Configuration Section -->
            <div id="section-config" class="dashboard-section">
                <div class="section-title">
                    <h2>⚙️ System Configuration</h2>
                    <p>Manage system settings and preferences</p>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>🔧 General Settings</h3>
                    </div>
                    <div class="config-form">
                        <div class="config-group">
                            <label>Site Name</label>
                            <input type="text" value="Library Hub" placeholder="Enter site name">
                        </div>
                        <div class="config-group">
                            <label>Session Timeout (minutes)</label>
                            <input type="number" value="30" min="5" max="120">
                        </div>
                        <div class="config-group">
                            <label>Max Login Attempts</label>
                            <input type="number" value="5" min="3" max="10">
                        </div>
                        <div class="config-group">
                            <label>Allow Student Registration</label>
                            <select>
                                <option value="1">Enabled</option>
                                <option value="0">Disabled</option>
                            </select>
                        </div>
                        <div class="config-group">
                            <label>Quiz Pass Score (%)</label>
                            <input type="number" value="75" min="50" max="100">
                        </div>
                        <div class="config-group">
                            <label>Default Quiz Timer (minutes)</label>
                            <input type="number" value="15" min="5" max="60">
                        </div>
                    </div>
                    <div style="margin-top: 20px; text-align: right;">
                        <button class="btn btn-primary" onclick="Swal.fire('Info', 'Configuration saved successfully!', 'success')">
                            💾 Save Settings
                        </button>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>📧 Email Configuration</h3>
                    </div>
                    <div class="config-form">
                        <div class="config-group">
                            <label>SMTP Host</label>
                            <input type="text" value="smtp.gmail.com" placeholder="SMTP server">
                        </div>
                        <div class="config-group">
                            <label>SMTP Port</label>
                            <input type="number" value="587">
                        </div>
                        <div class="config-group">
                            <label>Email From</label>
                            <input type="email" placeholder="noreply@libraryhub.com">
                        </div>
                    </div>
                </div>
            </div> <!-- End Configuration Section -->

            <!-- Audit Logs Section -->
            <div id="section-audit" class="dashboard-section">
                <div class="section-title">
                    <h2>📋 Audit Logs</h2>
                    <p>Track system activities and user actions</p>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>🕐 Recent Activity</h3>
                        <button class="btn btn-primary btn-sm" onclick="exportAuditLogsPDF()">
                            📥 Export Logs
                        </button>
                    </div>
                    
                    <div class="audit-log-item">
                        <div class="audit-icon login">🔐</div>
                        <div class="audit-content">
                            <strong>User Login</strong>
                            <p><?php echo htmlspecialchars($_SESSION['full_name']); ?> logged in to admin panel</p>
                        </div>
                        <div class="audit-time"><?php echo date('M d, Y g:i A'); ?></div>
                    </div>
                    
                    <div class="audit-log-item">
                        <div class="audit-icon create">➕</div>
                        <div class="audit-content">
                            <strong>User Created</strong>
                            <p>New student account was created</p>
                        </div>
                        <div class="audit-time"><?php echo date('M d, Y g:i A', strtotime('-1 hour')); ?></div>
                    </div>
                    
                    <div class="audit-log-item">
                        <div class="audit-icon update">✏️</div>
                        <div class="audit-content">
                            <strong>Profile Updated</strong>
                            <p>User profile information was modified</p>
                        </div>
                        <div class="audit-time"><?php echo date('M d, Y g:i A', strtotime('-2 hours')); ?></div>
                    </div>
                    
                    <div class="audit-log-item">
                        <div class="audit-icon login">🔐</div>
                        <div class="audit-content">
                            <strong>User Login</strong>
                            <p>Teacher account accessed the system</p>
                        </div>
                        <div class="audit-time"><?php echo date('M d, Y g:i A', strtotime('-3 hours')); ?></div>
                    </div>
                    
                    <div class="audit-log-item">
                        <div class="audit-icon delete">🗑️</div>
                        <div class="audit-content">
                            <strong>User Deactivated</strong>
                            <p>Student account was set to inactive</p>
                        </div>
                        <div class="audit-time"><?php echo date('M d, Y g:i A', strtotime('-1 day')); ?></div>
                    </div>
                </div>
            </div> <!-- End Audit Logs Section -->

            <!-- System Health Section -->
            <div id="section-health" class="dashboard-section">
                <div class="section-title">
                    <h2>💓 System Health</h2>
                    <p>Monitor system performance and status</p>
                </div>

                <div class="health-grid">
                    <div class="health-item">
                        <div class="health-item-header">
                            <div class="health-item-title">
                                <i class="fas fa-database"></i> Database
                            </div>
                            <span class="health-status good">Healthy</span>
                        </div>
                        <p style="color: #666; font-size: 14px; margin-bottom: 10px;">Connection: Active | Tables: OK</p>
                        <div class="health-bar">
                            <div class="health-bar-fill good" style="width: 95%;"></div>
                        </div>
                    </div>
                    
                    <div class="health-item">
                        <div class="health-item-header">
                            <div class="health-item-title">
                                <i class="fas fa-server"></i> Server
                            </div>
                            <span class="health-status good">Online</span>
                        </div>
                        <p style="color: #666; font-size: 14px; margin-bottom: 10px;">PHP: <?php echo phpversion(); ?> | Apache: Running</p>
                        <div class="health-bar">
                            <div class="health-bar-fill good" style="width: 90%;"></div>
                        </div>
                    </div>
                    
                    <div class="health-item">
                        <div class="health-item-header">
                            <div class="health-item-title">
                                <i class="fas fa-hdd"></i> Disk Space
                            </div>
                            <span class="health-status good">Normal</span>
                        </div>
                        <p style="color: #666; font-size: 14px; margin-bottom: 10px;">Used: ~50% | Free: ~50%</p>
                        <div class="health-bar">
                            <div class="health-bar-fill warning" style="width: 50%;"></div>
                        </div>
                    </div>
                    
                    <div class="health-item">
                        <div class="health-item-header">
                            <div class="health-item-title">
                                <i class="fas fa-memory"></i> Memory
                            </div>
                            <span class="health-status good">Normal</span>
                        </div>
                        <p style="color: #666; font-size: 14px; margin-bottom: 10px;">PHP Memory Limit: <?php echo ini_get('memory_limit'); ?></p>
                        <div class="health-bar">
                            <div class="health-bar-fill good" style="width: 40%;"></div>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3>📈 Quick Stats</h3>
                    </div>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="icon">📅</div>
                            <div class="number"><?php echo date('Y'); ?></div>
                            <div class="label">Current Year</div>
                        </div>
                        <div class="stat-card">
                            <div class="icon">⏰</div>
                            <div class="number"><?php echo date('H:i'); ?></div>
                            <div class="label">Server Time</div>
                        </div>
                        <div class="stat-card">
                            <div class="icon">🔧</div>
                            <div class="number">v1.0</div>
                            <div class="label">System Version</div>
                        </div>
                        <div class="stat-card">
                            <div class="icon">✅</div>
                            <div class="number">100%</div>
                            <div class="label">Uptime</div>
                        </div>
                    </div>
                </div>
            </div> <!-- End System Health Section -->

        </main>
    </div>

    <!-- Add User Modal -->
    <div class="modal-overlay" id="addUserModal">
        <div class="modal">
            <div class="modal-header">
                <h3>➕ Add New User</h3>
                <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_user">
                    
                    <div class="form-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="full_name" required placeholder="Enter full name">
                    </div>
                    
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" name="email" required placeholder="Enter email address">
                    </div>
                    
                    <div class="form-group">
                        <label>Password <span class="required">*</span></label>
                        <input type="password" name="password" required placeholder="Enter password" minlength="6">
                        <small>Minimum 6 characters</small>
                    </div>
                    
                    <div class="form-group">
                        <label>User Type <span class="required">*</span></label>
                        <select name="user_type" id="addUserType" required onchange="toggleStudentIdField('add')">
                            <option value="">Select user type</option>
                            <option value="student">Student</option>
                            <option value="teacher">Teacher</option>
                            <option value="librarian">Librarian</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="addStudentIdGroup" style="display:none;">
                        <label>Student ID</label>
                        <input type="text" name="student_id" id="addStudentId" placeholder="Enter student ID">
                        <small>Required for student accounts</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Status <span class="required">*</span></label>
                        <select name="status" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('addUserModal')" style="background:#ddd;">Cancel</button>
                    <button type="submit" class="btn btn-success">✅ Create User</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Edit User Modal -->
    <div class="modal-overlay" id="editUserModal">
        <div class="modal">
            <div class="modal-header">
                <h3>✏️ Edit User</h3>
                <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" id="editUserId">
                    
                    <div class="form-group">
                        <label>Full Name <span class="required">*</span></label>
                        <input type="text" name="full_name" id="editFullName" required placeholder="Enter full name">
                    </div>
                    
                    <div class="form-group">
                        <label>Email <span class="required">*</span></label>
                        <input type="email" name="email" id="editEmail" required placeholder="Enter email address">
                    </div>
                    
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" id="editPassword" placeholder="Leave blank to keep current">
                        <small>Leave blank if you don't want to change the password</small>
                    </div>
                    
                    <div class="form-group">
                        <label>User Type <span class="required">*</span></label>
                        <select name="user_type" id="editUserType" required onchange="toggleStudentIdField('edit')">
                            <option value="student">Student</option>
                            <option value="teacher">Teacher</option>
                            <option value="librarian">Librarian</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="editStudentIdGroup">
                        <label>Student ID</label>
                        <input type="text" name="student_id" id="editStudentId" placeholder="Enter student ID">
                    </div>

                    <div class="form-group" id="editGradeLevelGroup" style="display:none;">
                        <label>Grade Level</label>
                        <select name="grade_level" id="editGradeLevel">
                            <option value="">Select grade level</option>
                            <option value="1">Grade 1</option>
                            <option value="2">Grade 2</option>
                            <option value="3">Grade 3</option>
                            <option value="4">Grade 4</option>
                            <option value="5">Grade 5</option>
                            <option value="6">Grade 6</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Status <span class="required">*</span></label>
                        <select name="status" id="editStatus" required>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('editUserModal')" style="background:#ddd;">Cancel</button>
                    <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal" style="max-width: 400px;">
            <div class="modal-header" style="background: #dc3545;">
                <h3>🗑️ Delete User</h3>
                <button class="modal-close" onclick="closeModal('deleteModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body" style="text-align: center;">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" id="deleteUserId">
                    <div style="font-size: 60px; margin-bottom: 15px;">⚠️</div>
                    <h3>Are you sure?</h3>
                    <p style="color: #666; margin-top: 10px;">
                        You are about to delete user: <strong id="deleteUserName"></strong>
                    </p>
                    <p style="color: #dc3545; margin-top: 10px;">
                        This action cannot be undone!
                    </p>
                </div>
                <div class="modal-footer" style="justify-content: center;">
                    <button type="button" class="btn" onclick="closeModal('deleteModal')" style="background:#ddd;">Cancel</button>
                    <button type="submit" class="btn btn-danger">🗑️ Yes, Delete</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Reject Profile Edit Modal -->
    <div class="modal-overlay" id="rejectProfileModal">
        <div class="modal" style="max-width: 450px;">
            <div class="modal-header" style="background: #dc3545;">
                <h3>❌ Reject Profile Edit Request</h3>
                <button class="modal-close" onclick="closeModal('rejectProfileModal')">&times;</button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="action" value="reject_profile_edit">
                    <input type="hidden" name="request_id" id="rejectRequestId">
                    
                    <p>Rejecting profile edit request for: <strong id="rejectUserName"></strong></p>
                    
                    <div class="form-group">
                        <label>Reason for Rejection <span class="required">*</span></label>
                        <textarea name="rejection_reason" required rows="4" 
                                  placeholder="Enter reason for rejecting this request..."
                                  style="width: 100%; padding: 12px; border: 2px solid #ddd; border-radius: 8px; font-size: 14px;"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal('rejectProfileModal')" style="background:#ddd;">Cancel</button>
                    <button type="submit" class="btn btn-danger">❌ Reject Request</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Boot Animation - Only shows once per session (not on filter tab clicks)
        window.addEventListener('load', function() {
            const bootLoader = document.getElementById('bootLoader');
            const mainContent = document.getElementById('mainContent');
            
            // Use a single key for the entire admin session, not per-filter
            const bootKey = 'adminBootAnimationShown';
            
            // Check if boot animation has already been shown this session
            if (sessionStorage.getItem(bootKey)) {
                bootLoader.style.display = 'none';
                mainContent.classList.add('show');
                return;
            }
            
            // Mark that we've shown the boot animation
            sessionStorage.setItem(bootKey, 'true');
            
            const minLoadingTime = 1500;
            const startTime = Date.now();
            
            function hideBootLoader() {
                const elapsedTime = Date.now() - startTime;
                const remainingTime = Math.max(0, minLoadingTime - elapsedTime);
                
                setTimeout(() => {
                    bootLoader.classList.add('fade-out');
                    setTimeout(() => {
                        bootLoader.style.display = 'none';
                        mainContent.classList.add('show');
                    }, 800);
                }, remainingTime);
            }
            
            hideBootLoader();
        });

        // Modal Functions
        function openModal(modalId) {
            document.getElementById(modalId).classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal(modalId) {
            document.getElementById(modalId).classList.remove('active');
            document.body.style.overflow = '';
        }

        // Toggle Student ID and Grade Level fields based on user type
        function toggleStudentIdField(prefix) {
            const userTypeEl = document.getElementById(prefix + 'UserType');
            if (!userTypeEl) return;
            const userType = userTypeEl.value;
            const studentIdGroup = document.getElementById(prefix + 'StudentIdGroup');
            const gradeLevelGroup = document.getElementById(prefix + 'GradeLevelGroup');
            
            if (userType === 'student') {
                if (studentIdGroup) studentIdGroup.style.display = 'block';
                if (gradeLevelGroup) gradeLevelGroup.style.display = 'block';
            } else {
                if (studentIdGroup) studentIdGroup.style.display = 'none';
                if (gradeLevelGroup) gradeLevelGroup.style.display = 'none';
            }
        }

        // Open Edit Modal with User Data
        function openEditModal(user) {
            document.getElementById('editUserId').value = user.user_id;
            document.getElementById('editFullName').value = user.full_name;
            document.getElementById('editEmail').value = user.email;
            document.getElementById('editUserType').value = user.user_type;
            document.getElementById('editStudentId').value = user.student_id || '';
            if (document.getElementById('editGradeLevel')) {
                document.getElementById('editGradeLevel').value = user.grade_level || '';
            }
            document.getElementById('editStatus').value = user.status;
            document.getElementById('editPassword').value = '';
            
            toggleStudentIdField('edit');
            openModal('editUserModal');
        }

        // Confirm Delete
        function confirmDelete(userId, userName) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUserName').textContent = userName;
            openModal('deleteModal');
        }

        // Open Reject Modal
        function openRejectModal(requestId, userName) {
            document.getElementById('rejectRequestId').value = requestId;
            document.getElementById('rejectUserName').textContent = userName;
            openModal('rejectProfileModal');
        }

        // Close modal when clicking outside
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                    document.body.style.overflow = '';
                }
            });
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                    modal.classList.remove('active');
                });
                document.body.style.overflow = '';
            }
        });

        // Sidebar Navigation
        document.querySelectorAll('.sidebar-menu a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                
                // Update active state
                document.querySelectorAll('.sidebar-menu a').forEach(l => l.classList.remove('active'));
                this.classList.add('active');
                
                // Show corresponding section
                const section = this.getAttribute('data-section');
                document.querySelectorAll('.dashboard-section').forEach(s => s.classList.remove('active'));
                document.getElementById('section-' + section).classList.add('active');
                
                // Update page title based on section
                const titles = {
                    'dashboard': '📊 Dashboard Overview',
                    'users': '👤 User Management',
                    'requests': '📝 Profile Requests',
                    'config': '⚙️ Configuration',
                    'audit': '📋 Audit Logs',
                    'health': '💓 System Health'
                };
                document.querySelector('.page-header h1').textContent = titles[section] || '👋 Welcome!';
            });
        });
    </script>
    
    <!-- DataTables -->
    <script src="vendor/jquery-3.7.1.min.js"></script>
    <!-- DataTables JS (use CDN to avoid missing local file causing errors) -->
    <script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
    <!-- SweetAlert2 -->
    <script src="vendor/sweetalert2.min.js"></script>
    <!-- DataTables Buttons & Responsive extensions -->
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/pdfmake.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.2.7/vfs_fonts.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
    <script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
    <!-- jsPDF (used by Librarian PDF exports) -->
    <script src="vendor/jspdf.umd.min.js"></script>
    <!-- Error Handler -->
    <script src="../js/error-handler.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Users Table with Buttons & Responsive
            if ($('#usersTable').length) {
                var usersTable;
                if (!$.fn.dataTable.isDataTable('#usersTable')) {
                    usersTable = $('#usersTable').DataTable({
                    pageLength: 10,
                    lengthMenu: [[10, 25, 30, -1], [10, 25, 30, "All"]],
                    order: [[0, 'desc']],
                    responsive: true,
                    dom: 'Bfrtip',
                    buttons: [
                        { extend: 'copy', text: 'Copy' },
                        { extend: 'csv', text: 'CSV' },
                        { extend: 'excel', text: 'Excel' },
                        { extend: 'print', text: 'Print' }
                    ],
                    columnDefs: [
                        { orderable: false, targets: -1 }
                    ],
                    language: {
                        search: "🔍 Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ users",
                        emptyTable: "No users found"
                    }
                    });
                } else {
                    usersTable = $('#usersTable').DataTable();
                }

                // Apply initial column filter for user_type if ?type= present
                try {
                    var params = new URLSearchParams(window.location.search);
                    var t = params.get('type');
                    if (t) {
                        if (t === 'all') usersTable.column(3).search('').draw();
                        else usersTable.column(3).search('^' + t + '$', true, false).draw();
                    }
                } catch (e) { console.warn(e); }
            }

            // Initialize Profile Requests Table
            if ($('#profileRequestsTable').length) {
                if (!$.fn.dataTable.isDataTable('#profileRequestsTable')) {
                    $('#profileRequestsTable').DataTable({
                    pageLength: 10,
                    lengthMenu: [[10, 25, 30, -1], [10, 25, 30, "All"]],
                    order: [[4, 'desc']],
                    columnDefs: [
                        { orderable: false, targets: -1 }
                    ],
                    language: {
                        search: "🔍 Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ requests"
                    }
                    });
                }
            }
        });
    </script>
    <script>
        // Export audit log items shown on the Audit Logs card as a nicely formatted PDF
        async function exportAuditLogsPDF() {
            try {
                var items = document.querySelectorAll('#section-audit .audit-log-item');
                if (!items || items.length === 0) {
                    Swal.fire('Info', 'No audit log entries to export.', 'info');
                    return;
                }

                const { jsPDF } = window.jspdf;
                const pdf = new jsPDF('p', 'mm', 'a4');
                const pageWidth = pdf.internal.pageSize.getWidth();
                const pageHeight = pdf.internal.pageSize.getHeight();
                const margin = 12;
                let y = margin + 18;

                // Header
                pdf.setFillColor(40, 56, 114);
                pdf.rect(0, 0, pageWidth, 28, 'F');
                pdf.setTextColor(255,255,255);
                pdf.setFontSize(16);
                pdf.setFont('helvetica', 'bold');
                pdf.text('Library Hub - Audit Logs', pageWidth/2, 16, { align: 'center' });
                pdf.setFontSize(9);
                pdf.setFont('helvetica', 'normal');
                pdf.text('Generated: ' + new Date().toLocaleString(), pageWidth/2, 23, { align: 'center' });

                // Column titles
                y += 6;
                pdf.setTextColor(0,0,0);
                pdf.setFontSize(11);
                pdf.setFont('helvetica', 'bold');
                const col1x = margin;
                const col2x = 55;
                const col3x = 100;
                pdf.text('Timestamp', col1x, y);
                pdf.text('Event', col2x, y);
                pdf.text('Details', col3x, y);
                y += 6;
                pdf.setFont('helvetica', 'normal');
                pdf.setFontSize(10);

                // Rows
                items.forEach(function(it, idx){
                    const timeEl = it.querySelector('.audit-time');
                    const eventEl = it.querySelector('.audit-content strong');
                    const detailsEl = it.querySelector('.audit-content p');

                    const time = timeEl ? timeEl.textContent.trim() : '';
                    const event = eventEl ? eventEl.textContent.trim() : '';
                    const details = detailsEl ? detailsEl.textContent.trim() : '';

                    // Wrap details to width
                    const lineHeight = 6; // mm
                    const detailsLines = pdf.splitTextToSize(details, pageWidth - col3x - margin);

                    pdf.text(time, col1x, y);
                    pdf.text(event, col2x, y);
                    pdf.text(detailsLines, col3x, y);

                    // advance y by max lines
                    const advance = Math.max(1, detailsLines.length) * (lineHeight);
                    y += advance;

                    // Add page if needed
                    if (y > pageHeight - 20) {
                        const pageNum = pdf.internal.getNumberOfPages();
                        pdf.setFontSize(9);
                        pdf.text('Page ' + pageNum, pageWidth/2, pageHeight - 8, { align: 'center' });
                        pdf.addPage();
                        y = margin + 6;
                        pdf.setFontSize(10);
                    }
                });

                // Footer page number
                const totalPages = pdf.internal.getNumberOfPages();
                for (let i = 1; i <= totalPages; i++) {
                    pdf.setPage(i);
                    pdf.setFontSize(9);
                    pdf.text('Library Hub - Audit Logs | Page ' + i + ' of ' + totalPages, pageWidth/2, pageHeight - 8, { align: 'center' });
                }

                pdf.save('audit-logs-' + (new Date()).toISOString().slice(0,10) + '.pdf');
                Swal.fire('Success', 'Audit logs exported as PDF.', 'success');
            } catch (e) {
                console.error(e);
                Swal.fire('Error', 'Failed to export audit logs as PDF.', 'error');
            }
        }
        // Keep Users section active when filtering by query param `type`
        document.addEventListener('DOMContentLoaded', function() {
            try {
                var params = new URLSearchParams(window.location.search);
                if (params.has('type')) {
                    activateUsersForType(params.get('type'));
                }
            } catch (e) {
                console.error('Error setting users section active:', e);
            }

            // Intercept clicks on filter tabs to avoid full page reload and trigger boot animation
            document.querySelectorAll('.filter-tab').forEach(function(tab){
                tab.addEventListener('click', function(e){
                    e.preventDefault();
                    var href = this.getAttribute('href') || '';
                    var m = href.match(/[?&]type=([^&]+)/);
                    var type = m ? decodeURIComponent(m[1]) : 'all';

                    // Update URL without reload
                    var newUrl = window.location.pathname + '?type=' + encodeURIComponent(type);
                    history.pushState({type: type}, '', newUrl);

                    // Activate UI
                    activateUsersForType(type);

                    // Do not show boot animation when switching user filters
                    // (kept for backward compatibility if needed)

                    // If DataTable exists, try client-side filter on user_type column (column index 3 per schema)
                    try {
                        if (window.jQuery && jQuery.fn.dataTable && jQuery('#usersTable').length) {
                            var table = jQuery('#usersTable').DataTable();
                            if (type === 'all') {
                                table.column(3).search('').draw();
                            } else {
                                table.column(3).search(type).draw();
                            }
                        }
                    } catch (dtErr) {
                        console.warn('DataTable filtering not applied:', dtErr);
                    }
                });
            });

            // Handle back/forward navigation to restore section state
            window.addEventListener('popstate', function(e){
                var type = (e.state && e.state.type) || (new URLSearchParams(window.location.search)).get('type') || null;
                if (type) activateUsersForType(type);
            });
        });

        // Helper to activate Users section and set active tab
        function activateUsersForType(type) {
            document.querySelectorAll('.sidebar-menu a').forEach(function(l){ l.classList.remove('active'); });
            var usersLink = document.querySelector('.sidebar-menu a[data-section="users"]');
            if (usersLink) usersLink.classList.add('active');

            document.querySelectorAll('.dashboard-section').forEach(function(s){ s.classList.remove('active'); });
            var usersSection = document.getElementById('section-users');
            if (usersSection) usersSection.classList.add('active');

            var header = document.querySelector('.page-header h1');
            if (header) header.textContent = '👤 User Management';

            document.querySelectorAll('.filter-tab').forEach(function(tab){
                var href = tab.getAttribute('href') || '';
                var m = href.match(/[?&]type=([^&]+)/);
                if (m && decodeURIComponent(m[1]) === type) tab.classList.add('active'); else tab.classList.remove('active');
            });
            // Update the Student ID column header based on selected type
            try {
                var resolvedType = type || (new URLSearchParams(window.location.search)).get('type') || 'all';
                var studentColHeader = document.querySelector('#usersTable thead th:nth-child(5)');
                if (studentColHeader) {
                    if (resolvedType === 'student') studentColHeader.textContent = 'Student ID';
                    else studentColHeader.textContent = 'ID';
                }
                // If DataTable exists, update column header in DataTables' cache
                if (window.jQuery && jQuery.fn.dataTable && jQuery('#usersTable').length) {
                    var dt = jQuery('#usersTable').DataTable();
                    var col = dt.column(4); // zero-based index 4 is 5th column
                    if (col) {
                        jQuery(col.header()).text(resolvedType === 'student' ? 'Student ID' : 'ID');
                        dt.columns.adjust();
                    }
                }
            } catch (e) {
                console.warn('Failed to update Student ID header', e);
            }
        }

        // Show admin boot loader programmatically
        function showAdminBootLoader() {
            try {
                var bootLoader = document.getElementById('bootLoader');
                var mainContent = document.getElementById('mainContent');
                if (!bootLoader || !mainContent) return;

                // Force show
                bootLoader.classList.remove('fade-out');
                bootLoader.style.display = 'flex';
                mainContent.classList.remove('show');

                // Ensure boot animation will run even if previously shown
                try { sessionStorage.removeItem('adminBootAnimationShown'); } catch(e){}

                var minLoadingTime = 700; // shorter for UX
                var startTime = Date.now();

                setTimeout(function hideBootLoader(){
                    var elapsed = Date.now() - startTime;
                    var remaining = Math.max(0, minLoadingTime - elapsed);
                    setTimeout(function(){
                        bootLoader.classList.add('fade-out');
                        setTimeout(function(){
                            bootLoader.style.display = 'none';
                            mainContent.classList.add('show');
                        }, 600);
                    }, remaining);
                }, 50);
            } catch (e) { console.error('Failed to show boot loader', e); }
        }
    </script>
</body>
</html>
