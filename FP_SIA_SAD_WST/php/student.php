<?php
session_start();
require_once 'config.php';

// Check if student is logged in using correct session key
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'student') {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$student_name = $_SESSION['full_name'] ?? 'Student';
$student_id = $_SESSION['student_id'] ?? 'N/A';
$grade_level = $_SESSION['grade_level'] ?? 'N/A';

// Pagination settings for quiz history
$quizzes_per_page = 10;
$current_page = isset($_GET['quiz_page']) ? max(1, intval($_GET['quiz_page'])) : 1;
$offset = ($current_page - 1) * $quizzes_per_page;

// Fetch student statistics
$stats = [
    'books_completed' => 0,
    'total_time' => 0,
    'quizzes_taken' => 0,
    'avg_score' => 0,
    'rewards_earned' => 0
];

// Get books completed (distinct books with quiz attempts)
$sql = "SELECT COUNT(DISTINCT book_id) as count FROM quiz_attempts WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stats['books_completed'] = $row['count'];
    }
    $stmt->close();
}

// Get total reading/quiz time - check which column exists
$time_column = 'time_taken';
$columns_check = $conn->query("SHOW COLUMNS FROM quiz_attempts LIKE 'time_taken_seconds'");
if ($columns_check && $columns_check->num_rows > 0) {
    $time_column = 'time_taken_seconds';
}

$sql = "SELECT SUM($time_column) as total FROM quiz_attempts WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stats['total_time'] = $row['total'] ?? 0;
    }
    $stmt->close();
}

// Get quiz statistics
$sql = "SELECT COUNT(*) as count, AVG(score_percentage) as avg_score FROM quiz_attempts WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $stats['quizzes_taken'] = $row['count'];
        $stats['avg_score'] = round($row['avg_score'] ?? 0, 1);
    }
    $stmt->close();
}

// Get rewards earned - check if table exists first
$rewards_table_exists = false;
$table_check = $conn->query("SHOW TABLES LIKE 'user_rewards'");
if ($table_check && $table_check->num_rows > 0) {
    $rewards_table_exists = true;
    $sql = "SELECT COUNT(*) as count FROM user_rewards WHERE user_id = ?";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $stats['rewards_earned'] = $row['count'];
        }
        $stmt->close();
    }
}

// Get recent quiz attempts with book info
$recent_quizzes = [];
$sql = "SELECT qa.*, b.title, b.author 
        FROM quiz_attempts qa 
        JOIN books b ON qa.book_id = b.book_id 
        WHERE qa.user_id = ? 
        ORDER BY qa.attempt_date DESC 
        LIMIT 5";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recent_quizzes[] = $row;
    }
    $stmt->close();
}

// Get total quiz count for pagination
$total_quizzes = 0;
$sql = "SELECT COUNT(*) as total FROM quiz_attempts WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $total_quizzes = $row['total'];
    }
    $stmt->close();
}
$total_pages = ceil($total_quizzes / $quizzes_per_page);

// Get paginated quiz history for the table (UPDATED with pagination)
$all_quizzes = [];
$sql = "SELECT qa.*, b.title, b.author, b.genre
        FROM quiz_attempts qa 
        JOIN books b ON qa.book_id = b.book_id 
        WHERE qa.user_id = ? 
        ORDER BY qa.attempt_date DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param("iii", $user_id, $quizzes_per_page, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $all_quizzes[] = $row;
    }
    $stmt->close();
}

// Get earned rewards - check column structure first
$earned_rewards = [];
if ($rewards_table_exists) {
    // Check if earned_at column exists in user_rewards
    $has_earned_at = false;
    $col_check = $conn->query("SHOW COLUMNS FROM user_rewards LIKE 'earned_at'");
    if ($col_check && $col_check->num_rows > 0) {
        $has_earned_at = true;
    }
    
    // Also check for created_at as alternative
    $date_column = null;
    if ($has_earned_at) {
        $date_column = 'ur.earned_at';
    } else {
        $col_check2 = $conn->query("SHOW COLUMNS FROM user_rewards LIKE 'created_at'");
        if ($col_check2 && $col_check2->num_rows > 0) {
            $date_column = 'ur.created_at';
        }
    }
    
    if ($date_column) {
        $sql = "SELECT r.*, $date_column as earned_at 
                FROM user_rewards ur 
                JOIN rewards r ON ur.reward_id = r.reward_id 
                WHERE ur.user_id = ? 
                ORDER BY $date_column DESC";
    } else {
        $sql = "SELECT r.*, NULL as earned_at 
                FROM user_rewards ur 
                JOIN rewards r ON ur.reward_id = r.reward_id 
                WHERE ur.user_id = ?";
    }
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $earned_rewards[] = $row;
        }
        $stmt->close();
    }
}

// Get available rewards (not yet earned)
$available_rewards = [];
$rewards_def_exists = false;
$table_check2 = $conn->query("SHOW TABLES LIKE 'rewards'");
if ($table_check2 && $table_check2->num_rows > 0) {
    $rewards_def_exists = true;
    
    if ($rewards_table_exists) {
        $sql = "SELECT r.* 
                FROM rewards r 
                WHERE r.is_active = TRUE 
                AND r.reward_id NOT IN (SELECT reward_id FROM user_rewards WHERE user_id = ?)
                ORDER BY r.books_required ASC";
    } else {
        $sql = "SELECT r.* FROM rewards r WHERE r.is_active = TRUE ORDER BY r.books_required ASC";
    }
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $available_rewards[] = $row;
        }
        $stmt->close();
    }
}

// Get reading leaderboard (top 10)
$leaderboard = [];
$sql = "SELECT u.full_name, u.student_id, 
               COUNT(DISTINCT qa.book_id) as books_count,
               AVG(qa.score_percentage) as avg_score
        FROM users u
        JOIN quiz_attempts qa ON u.user_id = qa.user_id
        WHERE u.user_type = 'student' AND u.status = 'active'
        GROUP BY u.user_id
        ORDER BY books_count DESC, avg_score DESC
        LIMIT 10";
$result = $conn->query($sql);
if ($result) {
    $rank = 1;
    while ($row = $result->fetch_assoc()) {
        $row['rank'] = $rank++;
        $row['is_current_user'] = ($row['student_id'] === $student_id);
        $leaderboard[] = $row;
    }
}

// Format time helper - handle the correct column
function formatTime($seconds) {
    $seconds = (int)$seconds;
    if ($seconds < 60) {
        return $seconds . ' sec';
    } elseif ($seconds < 3600) {
        $mins = floor($seconds / 60);
        $secs = $seconds % 60;
        return $mins . 'm ' . $secs . 's';
    } else {
        $hours = floor($seconds / 3600);
        $mins = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $mins . 'm';
    }
}

// Check if there's a scanned book pending
$pending_book = isset($_SESSION['scanned_book']) ? $_SESSION['scanned_book'] : null;

// Store time_column for use in HTML
$time_col = $time_column;

// Handle profile edit submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'request_profile_edit') {
    $new_full_name = sanitize_input($_POST['full_name']);
    $new_email = sanitize_input($_POST['email']);
    $new_student_id = sanitize_input($_POST['student_id']);
    $new_grade_level = sanitize_input($_POST['grade_level']);
    
    // Store the edit request in database
    $sql = "INSERT INTO profile_edit_requests (user_id, new_full_name, new_email, new_student_id, new_grade_level, status, requested_at) 
            VALUES (?, ?, ?, ?, ?, 'pending', NOW())";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("issss", $user_id, $new_full_name, $new_email, $new_student_id, $new_grade_level);
        if ($stmt->execute()) {
            $_SESSION['profile_edit_message'] = "Profile edit request submitted! Waiting for admin approval.";
        } else {
            $_SESSION['profile_edit_error'] = "Failed to submit request. Please try again.";
        }
        $stmt->close();
    }
    header('Location: student.php');
    exit;
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

// Get profile picture
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

// Also update session with latest user data
$user_data_sql = "SELECT full_name, email FROM users WHERE user_id = ?";
$user_data_stmt = $conn->prepare($user_data_sql);
if ($user_data_stmt) {
    $user_data_stmt->bind_param("i", $user_id);
    $user_data_stmt->execute();
    $user_data_result = $user_data_stmt->get_result();
    if ($user_data_result->num_rows > 0) {
        $user_data = $user_data_result->fetch_assoc();
        $_SESSION['full_name'] = $user_data['full_name'];
        $_SESSION['email'] = $user_data['email'];
        $student_name = $user_data['full_name'];
    }
    $user_data_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Library Hub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <!-- SweetAlert2 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/html5-qrcode"></script>
    <script src="../js/script.js"></script>
    <!-- Error Handler will be loaded at bottom -->
    <style>
        :root {
            /* DepEd Color Scheme */
            --deped-blue: #1a4480;
            --deped-blue-dark: #0d2240;
            --deped-red: #c41230;
            --deped-red-dark: #8b0a1e;
            --main-gradient: linear-gradient(135deg, var(--deped-blue) 0%, var(--deped-blue-dark) 100%);
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

        .header-actions {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .scan-book-btn {
            padding: 12px 24px;
            background: var(--main-gradient);
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .scan-book-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(142, 46, 204, 0.4);
            color: white;
        }

        .user-profile {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 15px;
            background: white;
            border-radius: 12px;
            box-shadow: var(--card-shadow);
        }

        .user-avatar {
            width: 45px;
            height: 45px;
            background: var(--main-gradient);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            color: white;
            overflow: hidden;
        }
        
        .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-info h4 {
            margin: 0;
            font-size: 14px;
            color: #333;
        }

        .user-info span {
            font-size: 12px;
            color: #888;
        }

        /* Pending Book Alert */
        .pending-book-alert {
            background: linear-gradient(135deg, #fff9e6, #fff);
            border: 2px solid #ffc107;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .pending-book-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .pending-book-icon {
            width: 60px;
            height: 80px;
            background: var(--main-gradient);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }

        .pending-book-details h3 {
            margin: 0 0 5px;
            color: #333;
            font-size: 1.1em;
        }

        .pending-book-details p {
            margin: 0;
            color: #666;
            font-size: 14px;
        }

        .take-quiz-btn {
            padding: 14px 28px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 10px;
            font-weight: 700;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .take-quiz-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            color: white;
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 15px;
            box-shadow: var(--card-shadow);
            transition: all 0.3s ease;
            text-align: center;
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.15);
        }

        .stat-icon {
            width: 55px;
            height: 55px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            margin: 0 auto 15px;
        }

        .stat-icon.books { background: linear-gradient(135deg, #667eea, #764ba2); }
        .stat-icon.time { background: linear-gradient(135deg, #11998e, #38ef7d); }
        .stat-icon.quiz { background: linear-gradient(135deg, #eb3349, #f45c43); }
        .stat-icon.score { background: linear-gradient(135deg, #f7971e, #ffd200); }
        .stat-icon.rewards { background: linear-gradient(135deg, #ee0979, #ff6a00); }

        .stat-value {
            font-size: 2em;
            font-weight: 800;
            color: #333;
            margin-bottom: 5px;
        }

        .stat-label {
            color: #888;
            font-size: 14px;
        }

        /* Tabs */
        .dashboard-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .tab-btn {
            padding: 12px 24px;
            background: white;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            color: #666;
        }

        .tab-btn:hover {
            border-color: #8e2ecc;
            color: #8e2ecc;
        }

        .tab-btn.active {
            background: var(--main-gradient);
            border-color: transparent;
            color: white;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        /* Cards */
        .card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: var(--card-shadow);
            border: none;
            margin-bottom: 25px;
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding: 0;
            background: none;
            border: none;
        }

        .card-header h3 {
            font-size: 1.2em;
            font-weight: 700;
            color: #333;
            margin: 0;
        }

        /* Quiz History Table */
        .quiz-table {
            width: 100%;
            border-collapse: collapse;
        }

        .quiz-table th {
            padding: 15px;
            text-align: left;
            background: #f8f9fa;
            color: #666;
            font-weight: 600;
            font-size: 13px;
            border-bottom: 2px solid #e0e0e0;
        }

        .quiz-table td {
            padding: 15px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }

        .quiz-table tr:hover {
            background: #f8f9ff;
        }

        .book-cell {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .book-thumb {
            width: 40px;
            height: 55px;
            background: var(--main-gradient);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }

        .book-info h5 {
            margin: 0 0 3px;
            font-size: 14px;
            color: #333;
        }

        .book-info span {
            font-size: 12px;
            color: #888;
        }

        .score-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 700;
            font-size: 13px;
        }

        .score-badge.excellent { background: #d4edda; color: #155724; }
        .score-badge.good { background: #fff3cd; color: #856404; }
        .score-badge.needs-work { background: #f8d7da; color: #721c24; }

        .status-badge {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
        }

        .status-badge.normal { background: #e8f5e9; color: #2e7d32; }
        .status-badge.flagged { background: #ffebee; color: #c62828; }

        /* Rewards Grid */
        .rewards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 20px;
        }

        .reward-card {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }

        .reward-card.earned {
            background: linear-gradient(135deg, #fff9e6, #fff);
            border-color: #ffc107;
        }

        .reward-card.locked {
            opacity: 0.7;
        }

        .reward-icon {
            width: 70px;
            height: 70px;
            margin: 0 auto 15px;
            background: var(--main-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }

        .reward-card.locked .reward-icon {
            background: #ccc;
        }

        .reward-name {
            font-weight: 700;
            color: #333;
            margin-bottom: 5px;
        }

        .reward-desc {
            font-size: 13px;
            color: #666;
            margin-bottom: 10px;
        }

        .reward-requirement {
            font-size: 12px;
            color: #8e2ecc;
            font-weight: 600;
        }

        .earned-date {
            font-size: 11px;
            color: #28a745;
            margin-top: 8px;
        }

        /* Leaderboard */
        .leaderboard-list {
            list-style: none;
            padding: 0;
        }

        .leaderboard-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
            margin-bottom: 10px;
            transition: all 0.3s ease;
        }

        .leaderboard-item:hover {
            background: #f0f4ff;
        }

        .leaderboard-item.current-user {
            background: linear-gradient(135deg, #f0f4ff, #fff);
            border: 2px solid #667eea;
        }

        .rank-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 16px;
        }

        .rank-badge.gold { background: #ffd700; color: #333; }
        .rank-badge.silver { background: #c0c0c0; color: #333; }
        .rank-badge.bronze { background: #cd7f32; color: #fff; }
        .rank-badge.default { background: #e0e0e0; color: #666; }

        .leaderboard-info {
            flex: 1;
        }

        .leaderboard-info h5 {
            margin: 0 0 3px;
            font-size: 14px;
            color: #333;
        }

        .leaderboard-info span {
            font-size: 12px;
            color: #888;
        }

        .leaderboard-stats {
            text-align: right;
        }

        .leaderboard-stats .books {
            font-weight: 700;
            color: #333;
        }

        .leaderboard-stats .score {
            font-size: 12px;
            color: #28a745;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: #888;
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.3;
        }

        .empty-state h4 {
            color: #666;
            margin-bottom: 10px;
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

        /* QR Scanner Modal */
        .qr-scanner-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.9);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }

        .qr-scanner-modal.active {
            display: flex;
        }

        .qr-scanner-content {
            background: white;
            border-radius: 20px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            position: relative;
        }

        .qr-scanner-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .qr-scanner-header h3 {
            margin: 0;
            color: #333;
            font-size: 1.4em;
        }

        .close-scanner {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #999;
            transition: color 0.3s;
        }

        .close-scanner:hover {
            color: #333;
        }

        .qr-reader-container {
            width: 100%;
            max-width: 400px;
            height: 300px;
            border: 4px solid #e0e0e0;
            border-radius: 15px;
            margin: 20px auto;
            overflow: hidden;
            background: #000;
            position: relative;
        }

        .qr-reader-container.scanning {
            border-color: #667eea;
        }

        .qr-reader-container.success {
            border-color: #28a745;
        }

        .qr-reader-container.error {
            border-color: #dc3545;
        }

        #qr-reader-student {
            width: 100%;
            height: 100%;
        }

        #qr-reader-student video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .scanner-status {
            text-align: center;
            padding: 10px;
            font-weight: 600;
            color: #666;
            min-height: 40px;
        }

        .scanner-status.scanning {
            color: #667eea;
        }

        .scanner-status.success {
            color: #28a745;
        }

        .scanner-status.error {
            color: #dc3545;
        }

        .scanner-controls {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
        }

        .scanner-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .scanner-btn.primary {
            background: var(--main-gradient);
            color: white;
        }

        .scanner-btn.primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(142, 46, 204, 0.4);
        }

        .scanner-btn.secondary {
            background: #6c757d;
            color: white;
        }

        .scanner-btn.secondary:hover {
            background: #5a6268;
        }

        /* Profile Edit Specific Styles */
        .profile-pending-alert {
            background: linear-gradient(135deg, #fff3cd, #fff);
            border: 2px solid #ffc107;
            border-radius: 15px;
            padding: 15px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .profile-pending-alert .icon {
            font-size: 32px;
        }

        .profile-pending-alert .content {
            flex: 1;
        }

        .profile-pending-alert h4 {
            margin: 0 0 5px;
            color: #856404;
        }

        .profile-pending-alert p {
            margin: 0;
            color: #856404;
            font-size: 14px;
        }

        /* Pagination Styles */
        .pagination-container {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 15px;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .pagination {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
            justify-content: center;
        }

        .pagination-btn {
            min-width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            background: white;
            color: #666;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s ease;
            padding: 0 12px;
        }

        .pagination-btn:hover {
            border-color: #8e2ecc;
            color: #8e2ecc;
            transform: translateY(-2px);
        }

        .pagination-btn.active {
            background: var(--main-gradient);
            border-color: transparent;
            color: white;
        }

        .pagination-btn.active:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(142, 46, 204, 0.4);
        }

        .pagination-ellipsis {
            color: #999;
            padding: 0 8px;
            font-weight: 600;
        }

        .pagination-info {
            color: #888;
            font-size: 14px;
        }

        .table-responsive {
            overflow-x: auto;
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
            <li><a href="student.php" class="active"><i class="fas fa-home"></i> Dashboard</a></li>
            <li><a href="student-profile.php"><i class="fas fa-user"></i> My Profile</a></li>
            <li><a href="#" data-action="scan-book"><i class="fas fa-qrcode"></i> Scan Book</a></li>
        </ul>

        <button class="logout-btn" onclick="logout()">
            <i class="fas fa-sign-out-alt"></i> Logout
        </button>
    </aside>

    <!-- Main Content -->
    <main class="main-content">
        <!-- Header -->
        <div class="page-header">
            <h1>Welcome back, <?php echo htmlspecialchars($student_name); ?>! 👋</h1>
            <div class="header-actions">
                <button class="scan-book-btn" onclick="openQRScanner()">
                    <i class="fas fa-qrcode"></i> Scan Book
                </button>
                <div class="user-profile">
                    <div class="user-avatar">
                        <?php if ($profile_pic && file_exists(__DIR__ . '/../' . $profile_pic)): ?>
                            <img src="../<?php echo htmlspecialchars($profile_pic); ?>" alt="Profile">
                        <?php else: ?>
                            🎓
                        <?php endif; ?>
                    </div>
                    <div class="user-info">
                        <h4><?php echo htmlspecialchars($student_name); ?></h4>
                        <span>ID: <?php echo htmlspecialchars($student_id); ?> | Grade <?php echo htmlspecialchars($grade_level); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Profile Edit Alert -->
        <?php if ($has_pending_request): ?>
        <div class="profile-pending-alert">
            <div class="icon">⏳</div>
            <div class="content">
                <h4>Profile Edit Request Pending</h4>
                <p>Your profile update request is waiting for admin approval. You'll be notified once it's processed.</p>
                <small>Requested on: <?php echo date('M d, Y g:i A', strtotime($pending_request['requested_at'])); ?></small>
            </div>
        </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['profile_edit_message'])): ?>
        <div class="pending-book-alert" style="border-color: #28a745; background: linear-gradient(135deg, #d4edda, #fff);">
            <div class="pending-book-info">
                <div class="pending-book-icon" style="background: #28a745;">✅</div>
                <div class="pending-book-details">
                    <h3>Success!</h3>
                    <p><?php echo htmlspecialchars($_SESSION['profile_edit_message']); ?></p>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['profile_edit_message']); endif; ?>

        <?php if (isset($_SESSION['profile_edit_error'])): ?>
        <div class="pending-book-alert" style="border-color: #dc3545; background: linear-gradient(135deg, #f8d7da, #fff);">
            <div class="pending-book-info">
                <div class="pending-book-icon" style="background: #dc3545;">❌</div>
                <div class="pending-book-details">
                    <h3>Error</h3>
                    <p><?php echo htmlspecialchars($_SESSION['profile_edit_error']); ?></p>
                </div>
            </div>
        </div>
        <?php unset($_SESSION['profile_edit_error']); endif; ?>

        <!-- Pending Book Alert -->
        <?php if ($pending_book): ?>
        <div class="pending-book-alert">
            <div class="pending-book-info">
                <div class="pending-book-icon">📖</div>
                <div class="pending-book-details">
                    <h3><?php echo htmlspecialchars($pending_book['title']); ?></h3>
                    <p>by <?php echo htmlspecialchars($pending_book['author']); ?> • Ready for quiz</p>
                </div>
            </div>
            <a href="quiz.php" class="take-quiz-btn">📝 Take Quiz Now</a>
        </div>
        <?php endif; ?>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon books">📚</div>
                <div class="stat-value"><?php echo $stats['books_completed']; ?></div>
                <div class="stat-label">Books Completed</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon time">⏱️</div>
                <div class="stat-value"><?php echo formatTime($stats['total_time']); ?></div>
                <div class="stat-label">Total Quiz Time</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon quiz">📝</div>
                <div class="stat-value"><?php echo $stats['quizzes_taken']; ?></div>
                <div class="stat-label">Quizzes Taken</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon score">⭐</div>
                <div class="stat-value"><?php echo $stats['avg_score']; ?>%</div>
                <div class="stat-label">Average Score</div>
            </div>
            <div class="stat-card">
                <div class="stat-icon rewards">🏆</div>
                <div class="stat-value"><?php echo $stats['rewards_earned']; ?></div>
                <div class="stat-label">Rewards Earned</div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="dashboard-tabs">
            <button class="tab-btn active" onclick="switchTab('quizzes')">📝 Quiz History/Books Completed Progress</button>
            <button class="tab-btn" onclick="switchTab('achievements')">🏆 Achievements</button>
            <button class="tab-btn" onclick="switchTab('leaderboard')">🥇 Leaderboard</button>
        </div>

        <!-- Tab: Quiz History -->
        <div id="tab-quizzes" class="tab-content active">
            <div class="card">
                <div class="card-header">
                    <h3>📝 All Quiz Results/Books Completed Progress</h3>
                    <?php if ($total_quizzes > 0): ?>
                    <span style="color: #666; font-size: 14px;">
                        Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $quizzes_per_page, $total_quizzes); ?> of <?php echo $total_quizzes; ?> results
                    </span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($all_quizzes)): ?>
                    <div class="table-responsive">
                        <table class="quiz-table" id="quizHistoryTable">
                            <thead>
                                <tr>
                                    <th>Book</th>
                                    <th>Questions</th>
                                    <th>Score</th>
                                    <th>Time</th>
                                    <th>Status</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_quizzes as $quiz): ?>
                                    <?php 
                                        $score = $quiz['score_percentage'];
                                        $scoreClass = $score >= 80 ? 'excellent' : ($score >= 60 ? 'good' : 'needs-work');
                                        $isFlagged = isset($quiz['status']) && $quiz['status'] === 'flagged_tab_switch';
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="book-cell">
                                                <div class="book-thumb">📖</div>
                                                <div class="book-info">
                                                    <h5><?php echo htmlspecialchars($quiz['title']); ?></h5>
                                                    <span><?php echo htmlspecialchars($quiz['author']); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php echo $quiz['correct_answers']; ?>/<?php echo $quiz['total_questions']; ?></td>
                                        <td><span class="score-badge <?php echo $scoreClass; ?>"><?php echo $score; ?>%</span></td>
                                        <td><?php echo formatTime($quiz['time_taken'] ?? 0); ?></td>
                                        <td>
                                            <?php if ($isFlagged): ?>
                                                <span class="status-badge flagged">⚠️ Tab Switch</span>
                                            <?php else: ?>
                                                <span class="status-badge normal">✅ Normal</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo date('M d, Y g:i A', strtotime($quiz['attempt_date'])); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                    <div class="pagination-container">
                        <div class="pagination">
                            <?php if ($current_page > 1): ?>
                                <a href="?quiz_page=1" class="pagination-btn" title="First Page">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                                <a href="?quiz_page=<?php echo $current_page - 1; ?>" class="pagination-btn" title="Previous">
                                    <i class="fas fa-angle-left"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            // Calculate range of pages to show
                            $start_page = max(1, $current_page - 2);
                            $end_page = min($total_pages, $current_page + 2);
                            
                            // Always show at least 5 pages if available
                            if ($end_page - $start_page < 4) {
                                if ($start_page == 1) {
                                    $end_page = min($total_pages, 5);
                                } elseif ($end_page == $total_pages) {
                                    $start_page = max(1, $total_pages - 4);
                                }
                            }

                            if ($start_page > 1): ?>
                                <span class="pagination-ellipsis">...</span>
                            <?php endif;

                            for ($i = $start_page; $i <= $end_page; $i++): ?>
                                <a href="?quiz_page=<?php echo $i; ?>" 
                                   class="pagination-btn <?php echo $i === $current_page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor;

                            if ($end_page < $total_pages): ?>
                                <span class="pagination-ellipsis">...</span>
                            <?php endif; ?>

                            <?php if ($current_page < $total_pages): ?>
                                <a href="?quiz_page=<?php echo $current_page + 1; ?>" class="pagination-btn" title="Next">
                                    <i class="fas fa-angle-right"></i>
                                </a>
                                <a href="?quiz_page=<?php echo $total_pages; ?>" class="pagination-btn" title="Last Page">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <div class="pagination-info">
                            Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h4>No quizzes taken yet</h4>
                        <p>Complete a quiz to see your results here.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tab: Achievements -->
        <div id="tab-achievements" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>🏆 Your Achievements</h3>
                </div>
                <?php if (!empty($earned_rewards)): ?>
                    <div class="rewards-grid">
                        <?php foreach ($earned_rewards as $reward): ?>
                            <div class="reward-card earned">
                                <div class="reward-icon">🏆</div>
                                <div class="reward-name"><?php echo htmlspecialchars($reward['reward_name']); ?></div>
                                <div class="reward-desc"><?php echo htmlspecialchars($reward['reward_description'] ?? ''); ?></div>
                                <?php if (!empty($reward['earned_at'])): ?>
                                <div class="earned-date">✅ Earned on <?php echo date('M d, Y', strtotime($reward['earned_at'])); ?></div>
                                <?php else: ?>
                                <div class="earned-date">✅ Earned</div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-trophy"></i>
                        <h4>No achievements yet</h4>
                        <p>Complete books and quizzes to earn rewards!</p>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($available_rewards)): ?>
            <div class="card">
                <div class="card-header">
                    <h3>🔒 Available Rewards</h3>
                </div>
                <div class="rewards-grid">
                    <?php foreach ($available_rewards as $reward): ?>
                        <div class="reward-card locked">
                            <div class="reward-icon">🔒</div>
                            <div class="reward-name"><?php echo htmlspecialchars($reward['reward_name']); ?></div>
                            <div class="reward-desc"><?php echo htmlspecialchars($reward['reward_description']); ?></div>
                            <div class="reward-requirement">Complete <?php echo $reward['books_required']; ?> books to unlock</div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Tab: Leaderboard -->
        <div id="tab-leaderboard" class="tab-content">
            <div class="card">
                <div class="card-header">
                    <h3>🥇 Top Readers This Month</h3>
                </div>
                <?php if (!empty($leaderboard)): ?>
                    <ul class="leaderboard-list">
                        <?php foreach ($leaderboard as $reader): ?>
                            <?php 
                                $rankClass = $reader['rank'] === 1 ? 'gold' : ($reader['rank'] === 2 ? 'silver' : ($reader['rank'] === 3 ? 'bronze' : 'default'));
                            ?>
                            <li class="leaderboard-item <?php echo $reader['is_current_user'] ? 'current-user' : ''; ?>">
                                <div class="rank-badge <?php echo $rankClass; ?>"><?php echo $reader['rank']; ?></div>
                                <div class="leaderboard-info">
                                    <h5>
                                        <?php echo htmlspecialchars($reader['full_name']); ?>
                                        <?php if ($reader['is_current_user']): ?>
                                            <span style="color: #8e2ecc; font-size: 12px;">(You)</span>
                                        <?php endif; ?>
                                    </h5>
                                    <span>ID: <?php echo htmlspecialchars($reader['student_id']); ?></span>
                                </div>
                                <div class="leaderboard-stats">
                                    <div class="books"><?php echo $reader['books_count']; ?> books</div>
                                    <div class="score">Avg: <?php echo round($reader['avg_score'], 1); ?>%</div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-medal"></i>
                        <h4>No rankings yet</h4>
                        <p>Be the first to complete a book and appear on the leaderboard!</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- QR Scanner Modal -->
    <div id="qrScannerModal" class="qr-scanner-modal">
        <div class="qr-scanner-content">
            <div class="qr-scanner-header">
                <h3>📱 Scan Book QR Code</h3>
                <button class="close-scanner" onclick="closeQRScanner()">&times;</button>
            </div>
            
            <p style="text-align: center; color: #666; margin-bottom: 15px;">
                Point your camera at the QR code on the book
            </p>

            <div id="qrReaderContainer" class="qr-reader-container">
                <div id="qr-reader-student"></div>
            </div>

            <div id="scannerStatus" class="scanner-status">
                Click "Start Scanner" to begin
            </div>

            <div class="scanner-controls">
                <button id="startScannerBtn" class="scanner-btn primary" onclick="startQRScanner()">
                    📷 Start Scanner
                </button>
                <button id="stopScannerBtn" class="scanner-btn secondary" onclick="stopQRScanner()" style="display: none;">
                    ⏹️ Stop Scanner
                </button>
            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div id="editProfileModal" class="qr-scanner-modal">
        <div class="qr-scanner-content">
            <div class="qr-scanner-header">
                <h3>👤 Edit Profile</h3>
                <button class="close-scanner" onclick="closeEditProfile()">&times;</button>
            </div>
            
            <?php if ($has_pending_request): ?>
            <div style="background: #fff3cd; padding: 15px; border-radius: 10px; margin-bottom: 15px;">
                <p style="margin: 0; color: #856404; font-weight: 600;">
                    ⚠️ You have a pending profile edit request. Please wait for admin approval before submitting a new one.
                </p>
            </div>
            <?php endif; ?>

            <form method="POST" onsubmit="return <?php echo $has_pending_request ? 'false' : 'true'; ?>;">
                <input type="hidden" name="action" value="request_profile_edit">
                
                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Full Name</label>
                    <input type="text" name="full_name" value="<?php echo htmlspecialchars($student_name); ?>" 
                           required <?php echo $has_pending_request ? 'disabled' : ''; ?>
                           style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Email</label>
                    <input type="email" name="email" value="<?php echo htmlspecialchars($_SESSION['email'] ?? ''); ?>" 
                           required <?php echo $has_pending_request ? 'disabled' : ''; ?>
                           style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Student ID</label>
                    <input type="text" name="student_id" value="<?php echo htmlspecialchars($student_id); ?>" 
                           required <?php echo $has_pending_request ? 'disabled' : ''; ?>
                           style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                </div>

                <div class="form-group" style="margin-bottom: 15px;">
                    <label style="display: block; margin-bottom: 8px; font-weight: 600; color: #333;">Grade Level</label>
                    <select name="grade_level" required <?php echo $has_pending_request ? 'disabled' : ''; ?>
                            style="width: 100%; padding: 12px; border: 2px solid #e0e0e0; border-radius: 8px; font-size: 14px;">
                        <?php for ($i = 7; $i <= 12; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $grade_level == $i ? 'selected' : ''; ?>>Grade <?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </div>

                <p style="color: #666; font-size: 13px; margin: 15px 0;">
                    ℹ️ Changes will be submitted to admin for approval
                </p>

                <div class="scanner-controls">
                    <button type="button" class="scanner-btn secondary" onclick="closeEditProfile()">Cancel</button>
                    <button type="submit" class="scanner-btn primary" <?php echo $has_pending_request ? 'disabled' : ''; ?>>
                        📤 Submit for Approval
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let videoStream = null;
        let videoElement = null;
        let canvasElement = null;
        let scanInterval = null;
        let isScanning = false;

        async function checkPythonAPI() {
            try {
                const response = await fetch('python-qr-scan.php?action=health');
                const data = await response.json();
                return data.status === 'ok';
            } catch (e) {
                return false;
            }
        }

        function openQRScanner() {
            document.getElementById('qrScannerModal').classList.add('active');
        }

        function closeQRScanner() {
            stopQRScanner();
            document.getElementById('qrScannerModal').classList.remove('active');
        }

        async function startQRScanner() {
            try {
                const statusDiv = document.getElementById('scannerStatus');
                const readerContainer = document.getElementById('qrReaderContainer');
                const startBtn = document.getElementById('startScannerBtn');
                const stopBtn = document.getElementById('stopScannerBtn');

                // Check Python API
                const apiAvailable = await checkPythonAPI();
                if (!apiAvailable) {
                    statusDiv.textContent = '❌ Python Scanner offline';
                    statusDiv.className = 'scanner-status error';
                    Swal.fire({
                        icon: 'error',
                        title: 'Scanner Not Available',
                        html: '<p>Run <code>python/start_scanner.bat</code> first.</p>',
                        confirmButtonText: 'OK'
                    });
                    return;
                }

                // Stop existing
                await stopQRScanner();

                // Create video element
                const qrReader = document.getElementById('qr-reader-student');
                qrReader.innerHTML = '';
                
                videoElement = document.createElement('video');
                videoElement.setAttribute('playsinline', '');
                videoElement.style.width = '100%';
                videoElement.style.height = '100%';
                videoElement.style.objectFit = 'cover';
                qrReader.appendChild(videoElement);

                canvasElement = document.createElement('canvas');
                canvasElement.style.display = 'none';

                // Get camera
                videoStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' }
                });
                videoElement.srcObject = videoStream;
                await videoElement.play();

                canvasElement.width = videoElement.videoWidth || 640;
                canvasElement.height = videoElement.videoHeight || 480;

                isScanning = true;
                startBtn.style.display = 'none';
                stopBtn.style.display = 'inline-block';
                statusDiv.textContent = '🐍 Python Scanner Active';
                statusDiv.className = 'scanner-status scanning';
                readerContainer.classList.add('scanning');

                // Start scanning
                scanInterval = setInterval(scanFrameStudent, 500);

            } catch (err) {
                console.error('Camera error:', err);
                document.getElementById('scannerStatus').textContent = '❌ Camera access denied';
                document.getElementById('scannerStatus').className = 'scanner-status error';
                document.getElementById('qrReaderContainer').classList.add('error');
                
                Swal.fire({
                    icon: 'error',
                    title: 'Camera Error',
                    text: 'Unable to access camera.',
                    confirmButtonText: 'OK'
                });
            }
        }

        async function scanFrameStudent() {
            if (!isScanning || !videoElement || !canvasElement) return;

            try {
                const ctx = canvasElement.getContext('2d');
                ctx.drawImage(videoElement, 0, 0, canvasElement.width, canvasElement.height);
                const imageData = canvasElement.toDataURL('image/jpeg', 0.8);

                const response = await fetch('python-qr-scan.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ image: imageData })
                });

                const result = await response.json();

                if (result.success && result.qr_code) {
                    isScanning = false;
                    onScanSuccess(result.qr_code);
                }
            } catch (err) {
                // Ignore
            }
        }

        async function stopQRScanner() {
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

            isScanning = false;
            document.getElementById('startScannerBtn').style.display = 'inline-block';
            document.getElementById('stopScannerBtn').style.display = 'none';
            document.getElementById('scannerStatus').textContent = 'Scanner stopped';
            document.getElementById('scannerStatus').className = 'scanner-status';
            document.getElementById('qrReaderContainer').className = 'qr-reader-container';
        }

        function onScanSuccess(decodedText) {
            const statusDiv = document.getElementById('scannerStatus');
            const readerContainer = document.getElementById('qrReaderContainer');
            
            statusDiv.textContent = '✅ QR Code detected! Verifying...';
            statusDiv.className = 'scanner-status success';
            readerContainer.classList.remove('scanning');
            readerContainer.classList.add('success');

            stopQRScanner();

            fetch(`get-book-by-qr.php?qr_code=${encodeURIComponent(decodedText)}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.book) {
                        return fetch('save-scanned-book.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(data.book)
                        });
                    } else {
                        throw new Error(data.message || 'Book not found');
                    }
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        closeQRScanner();
                        Swal.fire({
                            icon: 'success',
                            title: 'Book Found!',
                            text: 'Redirecting to quiz...',
                            timer: 1500,
                            showConfirmButton: false
                        }).then(() => {
                            window.location.href = 'quiz.php';
                        });
                    } else {
                        throw new Error(data.message || 'Failed to save book');
                    }
                })
                .catch(error => {
                    statusDiv.textContent = '❌ Book not found!';
                    statusDiv.className = 'scanner-status error';
                    readerContainer.classList.remove('success');
                    readerContainer.classList.add('error');
                    
                    Swal.fire({
                        icon: 'error',
                        title: 'Book Not Found',
                        html: `<p><strong>QR:</strong> ${decodedText}</p><p>${error.message}</p>`,
                        confirmButtonText: '🔁 Try Again'
                    }).then(() => {
                        statusDiv.textContent = 'Click "Start Scanner"';
                        statusDiv.className = 'scanner-status';
                        readerContainer.className = 'qr-reader-container';
                        document.getElementById('startScannerBtn').style.display = 'inline-block';
                    });
                });
        }

        // Close modal on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && document.getElementById('qrScannerModal').classList.contains('active')) {
                closeQRScanner();
            }
        });

        // Close modal on outside click
        document.getElementById('qrScannerModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeQRScanner();
            }
        });

        function switchTab(tabName) {
            document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
            if (event && event.target) {
                event.target.classList.add('active');
            }
            
            document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
            const targetTab = document.getElementById('tab-' + tabName);
            if (targetTab) {
                targetTab.classList.add('active');
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

        function openEditProfile() {
            document.getElementById('editProfileModal').classList.add('active');
        }

        function closeEditProfile() {
            document.getElementById('editProfileModal').classList.remove('active');
        }

        // Enhanced sidebar navigation - FIXED VERSION
        document.addEventListener('DOMContentLoaded', function() {
            // Check if we should open the scanner (coming from profile page)
            if (sessionStorage.getItem('openScanner') === 'true') {
                sessionStorage.removeItem('openScanner');
                setTimeout(() => {
                    openQRScanner();
                }, 300);
            }

            // Check if there's a quiz_page parameter - keep quiz tab active
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.has('quiz_page')) {
                // Ensure quiz history tab is active
                document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
                document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
                
                const quizTabBtn = document.querySelector('.tab-btn');
                const quizTabContent = document.getElementById('tab-quizzes');
                if (quizTabBtn) quizTabBtn.classList.add('active');
                if (quizTabContent) quizTabContent.classList.add('active');
            }

            document.querySelectorAll('.sidebar-menu a').forEach(link => {
                link.addEventListener('click', function(e) {
                    const action = this.getAttribute('data-action');
                    
                    // Only handle special actions (like scan-book)
                    if (action === 'scan-book') {
                        e.preventDefault();
                        
                        // Remove active from all links
                        document.querySelectorAll('.sidebar-menu a').forEach(a => a.classList.remove('active'));
                        
                        // Add active to clicked link
                        this.classList.add('active');
                        
                        // Open QR scanner
                        openQRScanner();
                    }
                    // For regular links (Dashboard, My Profile), let them navigate normally
                    // Don't prevent default - browser will handle the navigation
                });
            });
        });
    </script>
    
    <!-- DataTables -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <!-- Error Handler -->
    <script src="../js/error-handler.js"></script>
    <script>
        $(document).ready(function() {
            // Initialize Quiz History Table
            if ($('#quizHistoryTable').length) {
                $('#quizHistoryTable').DataTable({
                    pageLength: 10,
                    lengthMenu: [[10, 25, 30, -1], [10, 25, 30, "All"]],
                    order: [[5, 'desc']],
                    language: {
                        search: "🔍 Search:",
                        lengthMenu: "Show _MENU_ entries",
                        info: "Showing _START_ to _END_ of _TOTAL_ quiz attempts",
                        emptyTable: "No quiz history yet"
                    }
                });
            }
        });
    </script>
</body>
</html>
